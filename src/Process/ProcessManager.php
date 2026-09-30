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

    private ?bool $daemonizeOverride = null;

    private float $startedAt = 0.0;

    private float $stopStartedAt = 0.0;

    /** @var null|callable(list<array<string, mixed>>, float): void */
    private $onStarted = null;

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

    /**
     * Force daemon mode on/off for this start (CLI `start -d`); null = config process.daemonize.
     */
    public function setDaemonize(?bool $daemonize): void
    {
        $this->daemonizeOverride = $daemonize;
    }

    public function daemonize(): bool
    {
        return $this->daemonizeOverride ?? !empty($this->masterOptions['daemonize']);
    }

    /** Merged config repository (config/*.php), for banners / probes. Pre-fork safe. */
    public function config(): Repository
    {
        return $this->config;
    }

    /**
     * Called once in the master after every child was spawned and the listening ones accept
     * connections (or failed / 5s passed). Rows: name, index, type, app, pid, listen, workers, state
     * (listening | running | starting | exited), plus the elapsed seconds since start().
     * In daemon mode it runs after daemonizing (stdout = process.log_file).
     *
     * @param callable(list<array<string, mixed>>, float): void $callback
     */
    public function onStarted(callable $callback): void
    {
        $this->onStarted = $callback;
    }

    /**
     * Run the master in the foreground (or daemonized) until stopped. Returns the exit code.
     * Log lines go through ProcessLog (plain unless the caller enabled colours for a TTY).
     * Daemon mode redirects stdin to /dev/null and stdout/stderr (master + children) to process.log_file.
     *
     * @throws \RuntimeException when already running or nothing is enabled (before forking)
     */
    public function start(): int
    {
        if ($this->isAlreadyRunning()) {
            throw new \RuntimeException(sprintf('Already running (pid %d). Use stop/reload/status.', $this->readPid() ?? 0));
        }
        if ($this->enabledEntries() === []) {
            throw new \RuntimeException('No enabled processes to start (check config/process.php, .env and --only).');
        }

        $this->ensureRuntimeDir();
        $this->running = true;
        $this->stopping = false;
        $this->startedAt = microtime(true);

        if ($this->daemonize()) {
            ProcessLog::setAnsi(false);
            $this->daemonizeToLogFile();
        }

        ProcessLog::setTag('master');
        ProcessLog::setTagWidth($this->longestTag());
        $this->setTitle('master');
        $this->spawnAll();
        $this->writePidFile((int) getmypid());
        $this->installSignals();

        $rows = $this->waitReady();
        $notReady = array_values(array_filter($rows, static fn (array $r): bool => $r['state'] !== 'listening' && $r['state'] !== 'running'));
        if ($this->onStarted !== null && !$this->stopping) {
            ($this->onStarted)($rows, microtime(true) - $this->startedAt);
        }
        ProcessLog::info(sprintf(
            'ProcessManager started pid=%d children=%d ready in %s',
            getmypid(),
            count($this->children),
            ProcessLog::duration(microtime(true) - $this->startedAt),
        ));
        if ($notReady !== [] && !$this->stopping) {
            ProcessLog::warn('not ready: ' . implode(', ', array_map(
                static fn (array $r): string => sprintf('%s#%d %s', $r['name'], $r['index'], $r['state']),
                $notReady,
            )));
        }

        $this->supervise();

        return 0;
    }

    /**
     * Stop the running master (SIGTERM, wait up to $timeout, then SIGKILL).
     *
     * $progress(string $event, array $context): events not_running | stopping | stopped | timeout.
     * Returns 0 when stopped / not running, 1 when it had to SIGKILL.
     *
     * @param null|callable(string, array<string, mixed>): void $progress
     */
    public function stop(?callable $progress = null, float $timeout = 30.0): int
    {
        $progress ??= static function (string $event, array $context): void {
        };
        $pid = $this->readPid();
        if ($pid === null || !$this->pidAlive($pid)) {
            $this->unlinkPidFile();
            $progress('not_running', []);

            return 0;
        }

        $progress('stopping', ['pid' => $pid]);
        posix_kill($pid, SIGTERM);

        $started = microtime(true);
        $deadline = $started + $timeout;
        while (microtime(true) < $deadline) {
            if (!$this->pidAlive($pid)) {
                $this->unlinkPidFile();
                $progress('stopped', ['pid' => $pid, 'seconds' => round(microtime(true) - $started, 2)]);

                return 0;
            }
            usleep(100_000);
        }

        $progress('timeout', ['pid' => $pid]);
        posix_kill($pid, SIGKILL);
        $this->unlinkPidFile();

        return 1;
    }

    /**
     * Graceful reload (SIGUSR1 to the master → children reload). Returns the master pid,
     * or null when not running.
     */
    public function reload(): ?int
    {
        $pid = $this->masterPid();
        if ($pid === null) {
            return null;
        }
        posix_kill($pid, SIGUSR1);

        return $pid;
    }

    /** Alive master pid from the pid file, or null. */
    public function masterPid(): ?int
    {
        $pid = $this->readPid();

        return $pid !== null && $this->pidAlive($pid) ? $pid : null;
    }

    public function pidFile(): string
    {
        return $this->pidFilePath();
    }

    public function logFile(): string
    {
        $rel = (string) ($this->masterOptions['log_file'] ?? 'runtime/loong-swoole.log');
        if ($rel !== '' && ($rel[0] === '/' || (strlen($rel) > 2 && $rel[1] === ':'))) {
            return $rel;
        }

        return $this->basePath . '/' . ltrim($rel, '/\\');
    }

    /**
     * Status snapshot for rendering (no output here).
     *
     * @return array{
     *   running: bool, master_pid: ?int, pid_file: string, log_file: string, daemonize: bool,
     *   processes: list<array{name: string, type: string, enabled: bool, selected: bool, app: string,
     *     count: int, listen: ?string, workers: ?int, pids: list<int>, state: string}>
     * }
     */
    public function statusReport(): array
    {
        $masterPid = $this->masterPid();
        if ($masterPid === null) {
            $this->unlinkPidFile();
        }
        $childPids = $masterPid !== null ? $this->childPidsByName($masterPid) : [];

        $rows = [];
        /** @var mixed $processes */
        $processes = $this->masterOptions['processes'] ?? [];
        foreach (is_array($processes) ? $processes : [] as $name => $cfg) {
            if (!is_string($name) || !is_array($cfg)) {
                continue;
            }
            $enabled = $cfg['enabled'] ?? true;
            if (is_string($enabled)) {
                $enabled = filter_var($enabled, FILTER_VALIDATE_BOOLEAN);
            }
            $enabled = (bool) $enabled;
            $selected = $this->only === null || $this->matchesOnly(strtolower($name));
            $listen = isset($cfg['port']) ? ((string) ($cfg['host'] ?? '0.0.0.0')) . ':' . (int) $cfg['port'] : null;
            $workers = isset($cfg['settings']['worker_num']) ? (int) $cfg['settings']['worker_num'] : null;
            $pids = $childPids[$name] ?? [];
            $state = match (true) {
                $pids !== [] => 'running',
                !$enabled => 'disabled',
                $masterPid === null => 'stopped',
                default => 'not running',
            };
            $rows[] = [
                'name' => $name,
                'type' => (string) ($cfg['type'] ?? $name),
                'enabled' => $enabled,
                'selected' => $selected,
                'app' => isset($cfg['_app']) ? (string) $cfg['_app'] : (isset($cfg['app']) ? (string) $cfg['app'] : '-'),
                'count' => max(1, (int) ($cfg['count'] ?? 1)),
                'listen' => $listen,
                'workers' => $workers,
                'pids' => $pids,
                'state' => $state,
            ];
        }

        return [
            'running' => $masterPid !== null,
            'master_pid' => $masterPid,
            'pid_file' => $this->pidFilePath(),
            'log_file' => $this->logFile(),
            'daemonize' => $this->daemonize(),
            'processes' => $rows,
        ];
    }

    /**
     * Direct children of the master, keyed by process name (from the child title
     * "loong-swoole: <name>[#i]" set in runChild()). Linux /proc only; empty elsewhere.
     *
     * @return array<string, list<int>>
     */
    private function childPidsByName(int $masterPid): array
    {
        $out = [];
        foreach (glob('/proc/[0-9]*/stat') ?: [] as $statFile) {
            $stat = @file_get_contents($statFile);
            if (!is_string($stat) || ($rp = strrpos($stat, ')')) === false) {
                continue;
            }
            $fields = explode(' ', substr($stat, $rp + 2));
            if ((int) ($fields[1] ?? 0) !== $masterPid) {
                continue;
            }
            $pid = (int) basename(dirname($statFile));
            $cmd = trim(str_replace("\0", ' ', (string) @file_get_contents('/proc/' . $pid . '/cmdline')));
            if (preg_match('/' . preg_quote(self::TITLE_PREFIX, '/') . ': ([^\s#]+)/', $cmd, $m) === 1) {
                $out[$m[1]][] = $pid;
            }
        }
        foreach ($out as &$pids) {
            sort($pids);
        }

        return $out;
    }

    /**
     * Poll until every child with a port accepts TCP connections and every other child
     * survived its first 300ms (or died / $timeout passed / a stop signal arrived).
     *
     * @return list<array{name: string, index: int, type: string, app: string, pid: int, listen: ?string, workers: ?int, state: string}>
     */
    private function waitReady(float $timeout = 5.0): array
    {
        $deadline = microtime(true) + $timeout;
        $states = [];
        do {
            if (function_exists('pcntl_signal_dispatch')) {
                pcntl_signal_dispatch();
            }
            if ($this->stopping) {
                break;
            }
            $pending = false;
            foreach ($this->children as $key => $child) {
                if (isset($states[$key])) {
                    continue;
                }
                if (!$this->childAlive($child['pid'])) {
                    $states[$key] = 'exited';
                    continue;
                }
                $port = (int) ($child['config']['port'] ?? 0);
                if ($port > 0) {
                    if ($this->portAccepting((string) ($child['config']['host'] ?? '0.0.0.0'), $port)) {
                        $states[$key] = 'listening';
                    } else {
                        $pending = true;
                    }
                    continue;
                }
                if (microtime(true) - $child['started_at'] >= 0.3) {
                    $states[$key] = 'running';
                } else {
                    $pending = true;
                }
            }
            if (!$pending) {
                break;
            }
            usleep(50_000);
        } while (microtime(true) < $deadline);

        $rows = [];
        foreach ($this->children as $key => $child) {
            $cfg = $child['config'];
            $rows[] = [
                'name' => $child['name'],
                'index' => $child['index'],
                'type' => $child['type'],
                'app' => isset($cfg['_app']) ? (string) $cfg['_app'] : (isset($cfg['app']) ? (string) $cfg['app'] : '-'),
                'pid' => $child['pid'],
                'listen' => isset($cfg['port']) ? ((string) ($cfg['host'] ?? '0.0.0.0')) . ':' . (int) $cfg['port'] : null,
                'workers' => isset($cfg['settings']['worker_num']) ? (int) $cfg['settings']['worker_num'] : null,
                'state' => $states[$key] ?? 'starting',
            ];
        }

        return $rows;
    }

    private function portAccepting(string $host, int $port): bool
    {
        $host = match ($host) {
            '', '0.0.0.0' => '127.0.0.1',
            '::', '[::]' => '[::1]',
            default => str_contains($host, ':') && $host[0] !== '[' ? '[' . $host . ']' : $host,
        };
        $fp = @stream_socket_client('tcp://' . $host . ':' . $port, $errno, $errstr, 0.2);
        if (!is_resource($fp)) {
            return false;
        }
        fclose($fp);

        return true;
    }

    /** Alive and not a zombie (children are only reaped in supervise()). */
    private function childAlive(int $pid): bool
    {
        if (!$this->pidAlive($pid)) {
            return false;
        }
        $stat = @file_get_contents('/proc/' . $pid . '/stat');
        if (!is_string($stat) || ($rp = strrpos($stat, ')')) === false) {
            return true;
        }

        return ($stat[$rp + 2] ?? '') !== 'Z';
    }

    /** Width of the longest log tag ("master", "<name>#<index>", "+/wN" for rpc pool workers). */
    private function longestTag(): int
    {
        $width = strlen('master');
        foreach ($this->enabledEntries() as $name => $cfg) {
            $count = max(1, (int) ($cfg['count'] ?? 1));
            $tag = $name . '#' . ($count - 1);
            $workers = (int) ($cfg['settings']['worker_num'] ?? 1);
            if ((string) ($cfg['type'] ?? $name) === 'rpc' && $workers > 1) {
                $tag .= '/w' . ($workers - 1);
            }
            $width = max($width, strlen($tag));
        }

        return $width;
    }

    /**
     * Daemonize with stdin → /dev/null and stdout/stderr → process.log_file (append), so every
     * echo / ProcessLog line / PHP warning / Swoole log line of the master and its children lands there.
     */
    private function daemonizeToLogFile(): void
    {
        $file = $this->logFile();
        $dir = dirname($file);
        if (!is_dir($dir)) {
            @mkdir($dir, 0775, true);
        }
        $log = @fopen($file, 'ab');
        $null = @fopen('/dev/null', 'rb');
        if (is_resource($log) && is_resource($null)) {
            Process::daemon(true, true, [$null, $log, $log]);

            return;
        }
        ProcessLog::warn('cannot open log file ' . $file . ' — daemon output discarded');
        Process::daemon(true, false);
    }

    private function spawnAll(): void
    {
        $entries = $this->enabledEntries();

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
                ProcessLog::error(sprintf(
                    'child %s#%d fatal: %s: %s (%s:%d)',
                    $name,
                    $index,
                    $e::class,
                    $e->getMessage(),
                    $e->getFile(),
                    $e->getLine(),
                ));
                exit(1);
            }
        }, false, 0, false);

        $pid = $process->start();
        if ($pid <= 0) {
            ProcessLog::error(sprintf('failed to fork process %s#%d', $name, $index));

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

        ProcessLog::info(sprintf('spawned %s#%d type=%s pid=%d', $name, $index, $type, $pid));
    }

    /**
     * @param array<string, mixed> $cfg
     */
    private function runChild(string $name, int $index, array $cfg, string $type): void
    {
        $this->setTitle($name . ($index > 0 ? '#' . $index : ''));
        ProcessLog::setTag($name . '#' . $index);

        // Children must not run the master's handlers (respawned children fork after installSignals()).
        // SIGINT is ignored: on Ctrl+C the terminal signals the whole process group, but only the
        // master reacts and stops children with SIGTERM (graceful, code=0 instead of signal=2).
        if (function_exists('pcntl_signal')) {
            pcntl_signal(SIGTERM, SIG_DFL);
            pcntl_signal(SIGUSR1, SIG_DFL);
            pcntl_signal(SIGINT, SIG_IGN);
        }

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
                            ProcessLog::warn(sprintf('stop timeout: SIGKILL %s#%d pid=%d', $child['name'], $child['index'], $child['pid']));
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
        $now = microtime(true);
        ProcessLog::info(sprintf(
            'ProcessManager exited%s uptime=%s',
            $this->stopStartedAt > 0 ? ' shutdown=' . ProcessLog::duration($now - $this->stopStartedAt) : '',
            ProcessLog::duration($now - $this->startedAt),
        ));
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

        $now = microtime(true);
        $line = sprintf(
            'child exit %s#%d pid=%d code=%d signal=%d uptime=%s',
            $child['name'],
            $child['index'],
            $pid,
            $code,
            $signal,
            ProcessLog::duration($child['started_at'] > 0 ? $now - $child['started_at'] : 0.0),
        );

        if ($this->stopping) {
            $line .= ' stopped in ' . ProcessLog::duration($now - $this->stopStartedAt);
            $code === 0 && $signal === 0 ? ProcessLog::info($line) : ProcessLog::warn($line);

            return;
        }

        // Backoff based on restart count.
        $restarts = (int) $child['restarts'] + 1;
        $backoffMs = min(10_000, 200 * (2 ** min($restarts, 5)));
        ProcessLog::warn(sprintf('%s — restarting in %s (restart #%d)', $line, ProcessLog::duration($backoffMs / 1000), $restarts));
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
        ProcessLog::info(sprintf('reload: restarting %d children (SIGUSR1)', count($this->children)));
        foreach ($this->children as $child) {
            if ($child['pid'] > 0 && $this->pidAlive($child['pid'])) {
                // Prefer graceful: SIGUSR1 for Swoole servers, SIGTERM for others then respawn via wait.
                posix_kill($child['pid'], SIGUSR1);
            }
        }
    }

    private function installSignals(): void
    {
        $handleStop = function (int $signo = SIGTERM) {
            if ($this->stopping) {
                return;
            }
            $this->stopping = true;
            $this->stopStartedAt = microtime(true);
            ProcessLog::info(sprintf(
                'shutting down (%s): stopping %d children with SIGTERM',
                $signo === SIGINT ? 'SIGINT' : ($signo === SIGTERM ? 'SIGTERM' : 'signal ' . $signo),
                count($this->children),
            ));
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
        ProcessLog::warn('pcntl_signal unavailable; run with php -d disable_functions= for stop/reload signals.');
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
