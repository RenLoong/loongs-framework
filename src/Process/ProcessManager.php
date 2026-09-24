<?php

declare(strict_types=1);

namespace Loongs\Process;

use Loongs\App\AppProcessDiscovery;
use Loongs\Config\Repository;
use Loongs\Process\Role\CrontabProcess;
use Loongs\Process\Role\CustomProcess;
use Loongs\Process\Role\HttpProcess;
use Loongs\Process\Role\QueueProcess;
use Loongs\Process\Role\RpcProcess;
use Loongs\Process\Role\WebsocketProcess;
use Loongs\Support\BasePath;
use Loongs\Support\Env;
use Swoole\Process;
use Throwable;

/**
 * Master supervisor: forks one Swoole\Process per enabled role×count,
 * restarts crashed children, forwards stop/reload signals.
 *
 * Must NOT boot Application / coroutine / event-loop state before forking.
 */
final class ProcessManager
{
    private const TITLE_PREFIX = 'loong-swoole';

    private string $basePath;

    /** @var list<string>|null */
    private ?array $only;

    private Repository $config;

    /** @var array<string, mixed> */
    private array $masterOptions = [];

    /** @var array<string, array{name:string,index:int,type:string,config:array<string,mixed>,process:?Process,pid:int,restarts:int,started_at:float}> */
    private array $children = [];

    private bool $running = false;

    private bool $stopping = false;

    private bool $reloading = false;

    private float $stopDeadline = 0.0;

    /** @param list<string>|null $only */
    public function __construct(string $basePath, ?array $only = null)
    {
        $this->basePath = rtrim($basePath, '/\\');
        BasePath::set($this->basePath);
        $this->only = $only === null || $only === [] ? null : array_values(array_unique(array_map(
            static fn (string $n): string => strtolower(trim($n)),
            $only,
        )));

        Env::load($this->basePath . '/.env');
        $this->config = new Repository($this->basePath . '/config');

        /** @var array<string, mixed> $process */
        $process = $this->config->get('process', []);
        $this->masterOptions = is_array($process) ? $process : [];

        $globalProcesses = $this->masterOptions['processes'] ?? [];
        if (!is_array($globalProcesses)) {
            $globalProcesses = [];
        }
        /** @var array<string, array<string, mixed>> $globalProcesses */
        $discovery = new AppProcessDiscovery($this->basePath . '/apps');
        $appProcesses = $discovery->discover();
        $this->masterOptions['processes'] = AppProcessDiscovery::merge($globalProcesses, $appProcesses);
    }

    public function start(): void
    {
        if ($this->isAlreadyRunning()) {
            fwrite(STDERR, sprintf(
                "Already running (pid %d). Use stop/reload/status.\n",
                $this->readPid() ?? 0,
            ));
            exit(1);
        }

        $this->ensureRuntimeDir();
        $this->running = true;
        $this->stopping = false;

        if (!empty($this->masterOptions['daemonize'])) {
            Process::daemon(true, true);
        }

        $this->setTitle('master');
        $this->spawnAll();
        $this->writePidFile((int) getmypid());

        echo sprintf(
            "[%s] ProcessManager started pid=%d children=%d\n",
            date('Y-m-d H:i:s'),
            getmypid(),
            count($this->children),
        );

        $this->installSignals();
        $this->supervise();
    }

    public function stop(): int
    {
        $pid = $this->readPid();
        if ($pid === null || !$this->pidAlive($pid)) {
            $this->unlinkPidFile();
            echo "Not running.\n";

            return 0;
        }

        echo sprintf("Stopping master pid=%d ...\n", $pid);
        posix_kill($pid, SIGTERM);

        $deadline = microtime(true) + 30.0;
        while (microtime(true) < $deadline) {
            if (!$this->pidAlive($pid)) {
                $this->unlinkPidFile();
                echo "Stopped.\n";

                return 0;
            }
            usleep(100_000);
        }

        fwrite(STDERR, "Stop timed out; sending SIGKILL\n");
        posix_kill($pid, SIGKILL);
        $this->unlinkPidFile();

        return 1;
    }

    public function reload(): int
    {
        $pid = $this->readPid();
        if ($pid === null || !$this->pidAlive($pid)) {
            echo "Not running.\n";

            return 1;
        }

        echo sprintf("Reloading master pid=%d (SIGUSR1)\n", $pid);
        posix_kill($pid, SIGUSR1);

        return 0;
    }

    public function status(): int
    {
        $pid = $this->readPid();
        $running = $pid !== null && $this->pidAlive($pid);
        if (!$running) {
            echo "status: stopped\n";
            $this->unlinkPidFile();
            $this->printProcessList();

            return 1;
        }

        echo sprintf("status: running master_pid=%d pid_file=%s\n", $pid, $this->pidFilePath());
        $this->printProcessList();

        return 0;
    }

    private function printProcessList(): void
    {
        /** @var mixed $processes */
        $processes = $this->masterOptions['processes'] ?? [];
        if (!is_array($processes) || $processes === []) {
            echo "processes: (none)\n";

            return;
        }

        echo "processes:\n";
        foreach ($processes as $name => $cfg) {
            if (!is_string($name) || !is_array($cfg)) {
                continue;
            }
            $enabled = $cfg['enabled'] ?? true;
            if (is_string($enabled)) {
                $enabled = filter_var($enabled, FILTER_VALIDATE_BOOLEAN);
            }
            $type = (string) ($cfg['type'] ?? $name);
            $app = isset($cfg['_app']) ? (string) $cfg['_app'] : (isset($cfg['app']) ? (string) $cfg['app'] : '-');
            echo sprintf(
                "  - %-24s type=%-10s enabled=%s app=%s\n",
                $name,
                $type,
                $enabled ? 'true' : 'false',
                $app,
            );
        }
    }

    private function spawnAll(): void
    {
        $entries = $this->enabledEntries();
        if ($entries === []) {
            fwrite(STDERR, "No enabled processes to start.\n");
            exit(1);
        }

        foreach ($entries as $name => $cfg) {
            $count = max(1, (int) ($cfg['count'] ?? 1));
            for ($i = 0; $i < $count; $i++) {
                $this->spawnChild($name, $i, $cfg);
            }
        }
    }

    /**
     * @param array<string, mixed> $cfg
     */
    private function spawnChild(string $name, int $index, array $cfg): void
    {
        $type = (string) ($cfg['type'] ?? $name);
        $key = $this->childKey($name, $index);

        $manager = $this;
        $process = new Process(static function (Process $worker) use ($manager, $name, $index, $cfg, $type): void {
            // Child: no inherited master signal handlers needed beyond defaults.
            try {
                $manager->runChild($name, $index, $cfg, $type);
            } catch (Throwable $e) {
                fwrite(STDERR, sprintf(
                    "[%s] child %s#%d fatal: %s\n",
                    date('Y-m-d H:i:s'),
                    $name,
                    $index,
                    $e->getMessage(),
                ));
                exit(1);
            }
        }, false, 0, false);

        $pid = $process->start();
        if ($pid <= 0) {
            fwrite(STDERR, sprintf("Failed to fork process %s#%d\n", $name, $index));

            return;
        }

        $prev = $this->children[$key]['restarts'] ?? 0;
        $this->children[$key] = [
            'name' => $name,
            'index' => $index,
            'type' => $type,
            'config' => $cfg,
            'process' => $process,
            'pid' => $pid,
            'restarts' => $prev,
            'started_at' => microtime(true),
        ];

        echo sprintf(
            "[%s] spawned %s#%d type=%s pid=%d\n",
            date('Y-m-d H:i:s'),
            $name,
            $index,
            $type,
            $pid,
        );
    }

    /**
     * @param array<string, mixed> $cfg
     */
    private function runChild(string $name, int $index, array $cfg, string $type): void
    {
        $this->setTitle($name . ($index > 0 ? '#' . $index : ''));

        $processType = ProcessType::tryFromConfig($type);
        if ($processType === null) {
            throw new \InvalidArgumentException("Unknown process type [{$type}] for [{$name}].");
        }

        $role = $this->resolveRole($processType, $cfg);
        $role->handle($name, $cfg);
    }

    /**
     * @param array<string, mixed> $cfg
     */
    private function resolveRole(ProcessType $type, array $cfg): ProcessInterface
    {
        return match ($type) {
            ProcessType::Http => new HttpProcess($this->basePath),
            ProcessType::Rpc => new RpcProcess($this->basePath),
            ProcessType::Websocket => new WebsocketProcess($this->basePath),
            ProcessType::Queue => new QueueProcess($this->basePath),
            ProcessType::Crontab => new CrontabProcess($this->basePath),
            ProcessType::Custom => new CustomProcess($this->basePath),
        };
    }

    private function supervise(): void
    {
        while ($this->running) {
            if (function_exists('pcntl_signal_dispatch')) {
                pcntl_signal_dispatch();
            }

            if ($this->reloading) {
                $this->reloading = false;
                $this->reloadChildren();
            }

            while (true) {
                $ret = Process::wait(false);
                if (!is_array($ret) || !isset($ret['pid'])) {
                    break;
                }
                $this->onChildExit((int) $ret['pid'], (int) ($ret['code'] ?? 0), (int) ($ret['signal'] ?? 0));
            }

            if ($this->stopping) {
                if ($this->children === []) {
                    break;
                }
                if ($this->stopDeadline > 0 && microtime(true) >= $this->stopDeadline) {
                    foreach ($this->children as $k => $child) {
                        if ($child['pid'] > 0 && $this->pidAlive($child['pid'])) {
                            posix_kill($child['pid'], SIGKILL);
                        }
                        unset($this->children[$k]);
                    }
                    break;
                }
            }

            usleep(100_000);
        }

        $this->running = false;
        $this->unlinkPidFile();
        echo sprintf("[%s] ProcessManager exited\n", date('Y-m-d H:i:s'));
    }

    private function onChildExit(int $pid, int $code, int $signal): void
    {
        $key = null;
        foreach ($this->children as $k => $child) {
            if ($child['pid'] === $pid) {
                $key = $k;
                break;
            }
        }

        if ($key === null) {
            return;
        }

        $child = $this->children[$key];
        unset($this->children[$key]);

        echo sprintf(
            "[%s] child exit %s#%d pid=%d code=%d signal=%d\n",
            date('Y-m-d H:i:s'),
            $child['name'],
            $child['index'],
            $pid,
            $code,
            $signal,
        );

        if ($this->stopping) {
            return;
        }

        // Backoff based on restart count.
        $restarts = (int) $child['restarts'] + 1;
        $backoffMs = min(10_000, 200 * (2 ** min($restarts, 5)));
        usleep($backoffMs * 1000);

        $cfg = $child['config'];
        $cfg['_restarts'] = $restarts;
        $this->children[$key] = [
            'name' => $child['name'],
            'index' => $child['index'],
            'type' => $child['type'],
            'config' => $cfg,
            'process' => null,
            'pid' => 0,
            'restarts' => $restarts,
            'started_at' => 0.0,
        ];
        $this->spawnChild($child['name'], $child['index'], $cfg);
    }

    private function reloadChildren(): void
    {
        echo sprintf("[%s] reload: restarting children\n", date('Y-m-d H:i:s'));
        foreach ($this->children as $child) {
            if ($child['pid'] > 0 && $this->pidAlive($child['pid'])) {
                // Prefer graceful: SIGUSR1 for Swoole servers, SIGTERM for others then respawn via wait.
                posix_kill($child['pid'], SIGUSR1);
            }
        }
    }

    private function installSignals(): void
    {
        $handleStop = function () {
            if ($this->stopping) {
                return;
            }
            $this->stopping = true;
            echo sprintf("[%s] shutting down...\n", date('Y-m-d H:i:s'));
            foreach ($this->children as $child) {
                if ($child['pid'] > 0 && $this->pidAlive($child['pid'])) {
                    posix_kill($child['pid'], SIGTERM);
                }
            }
            $this->stopDeadline = microtime(true) + 15.0;
        };

        if (function_exists('pcntl_async_signals')) {
            pcntl_async_signals(true);
        }

        if (function_exists('pcntl_signal')) {
            pcntl_signal(SIGTERM, $handleStop);
            pcntl_signal(SIGINT, $handleStop);
            pcntl_signal(SIGUSR1, function () {
                $this->reloading = true;
            });
            return;
        }

        // Fallback when pcntl_signal is disabled: Swoole signal + brief event wait is not used
        // in the poll loop; document running with `php -d disable_functions= start`.
        fwrite(STDERR, "Warning: pcntl_signal unavailable; run with php -d disable_functions= for stop/reload signals.\n");
        Process::signal(SIGTERM, $handleStop);
        Process::signal(SIGINT, $handleStop);
        Process::signal(SIGUSR1, function (): void {
            $this->reloading = true;
        });
    }

    /**
     * @return array<string, array<string, mixed>>
     */
    private function enabledEntries(): array
    {
        /** @var mixed $processes */
        $processes = $this->masterOptions['processes'] ?? [];
        if (!is_array($processes)) {
            return [];
        }

        $out = [];
        foreach ($processes as $name => $cfg) {
            if (!is_string($name) || !is_array($cfg)) {
                continue;
            }
            if ($this->only !== null && !$this->matchesOnly(strtolower($name))) {
                continue;
            }
            $enabled = $cfg['enabled'] ?? true;
            if (is_string($enabled)) {
                $enabled = filter_var($enabled, FILTER_VALIDATE_BOOLEAN);
            }
            if (!$enabled) {
                continue;
            }
            /** @var array<string, mixed> $cfg */
            $out[$name] = $cfg;
        }

        return $out;
    }

    /**
     * --only=http,user.stats,user.*  (exact names or app wildcard)
     */
    private function matchesOnly(string $name): bool
    {
        if ($this->only === null) {
            return true;
        }

        foreach ($this->only as $pattern) {
            if ($pattern === $name) {
                return true;
            }
            if (str_ends_with($pattern, '.*')) {
                $prefix = substr($pattern, 0, -1); // "user." from "user.*"
                if ($prefix !== '' && str_starts_with($name, $prefix)) {
                    return true;
                }
            }
        }

        return false;
    }

    private function childKey(string $name, int $index): string
    {
        return $name . '#' . $index;
    }

    private function setTitle(string $suffix): void
    {
        $title = self::TITLE_PREFIX . ': ' . $suffix;
        if (function_exists('cli_set_process_title')) {
            @cli_set_process_title($title);
        }
        if (function_exists('swoole_set_process_name')) {
            @swoole_set_process_name($title);
        }
    }

    private function pidFilePath(): string
    {
        $rel = (string) ($this->masterOptions['pid_file'] ?? 'runtime/loong-swoole.pid');
        if ($rel[0] === '/' || (strlen($rel) > 2 && $rel[1] === ':')) {
            return $rel;
        }

        return $this->basePath . '/' . ltrim($rel, '/\\');
    }

    private function writePidFile(int $pid): void
    {
        $path = $this->pidFilePath();
        $dir = dirname($path);
        if (!is_dir($dir)) {
            mkdir($dir, 0775, true);
        }
        file_put_contents($path, (string) $pid . "\n");
    }

    private function unlinkPidFile(): void
    {
        $path = $this->pidFilePath();
        if (is_file($path)) {
            @unlink($path);
        }
    }

    private function readPid(): ?int
    {
        $path = $this->pidFilePath();
        if (!is_file($path)) {
            return null;
        }
        $raw = trim((string) file_get_contents($path));
        if ($raw === '' || !ctype_digit($raw)) {
            return null;
        }

        return (int) $raw;
    }

    private function isAlreadyRunning(): bool
    {
        $pid = $this->readPid();

        return $pid !== null && $this->pidAlive($pid);
    }

    private function pidAlive(int $pid): bool
    {
        if ($pid <= 0) {
            return false;
        }
        if (!function_exists('posix_kill')) {
            return file_exists('/proc/' . $pid);
        }

        return @posix_kill($pid, 0);
    }

    private function ensureRuntimeDir(): void
    {
        $dir = $this->basePath . '/runtime';
        if (!is_dir($dir)) {
            mkdir($dir, 0775, true);
        }
    }
}
