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

    /** Graceful stop budget for children before the master SIGKILLs the whole tree. */
    private const STOP_GRACE = 15.0;

    /** Watchdog: how long an orphaned child (master gone) gets after SIGTERM before SIGKILL. */
    private const ORPHAN_GRACE = 15.0;

    /** Master self-heal interval (pid file / lock file deleted or replaced while running). */
    private const HEAL_INTERVAL = 1.0;

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

    /**
     * Instance lock (flock LOCK_EX on <pid file>.lock), held by the master for its lifetime.
     * Children inherit the descriptor (same open file description), so the lock stays held while
     * any process of this instance is alive — that is how orphans are detected. Only the master
     * ever calls flock(LOCK_UN) (PHP's fclose / process exit do not unlock).
     *
     * @var resource|null
     */
    private $lockHandle = null;

    private bool $forced = false;

    private float $nextHealAt = 0.0;

    /** Self-heal could not re-lock a replaced lock file (someone else holds it); logged once. */
    private bool $healConflictLogged = false;

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
        if ($this->enabledEntries() === []) {
            throw new \RuntimeException('No enabled processes to start (check config/process.php, .env and --only).');
        }

        $this->ensureRuntimeDir();
        // A live master of this instance wins, even when its pid / lock file was deleted.
        $running = $this->masterPid();
        if ($running !== null) {
            throw new \RuntimeException(sprintf('Already running (pid %d). Use stop/reload/status.', $running));
        }
        // Lock before any fork / daemonize: two concurrent `start`s → exactly one wins.
        $this->acquireLock();
        try {
            // Re-check under the lock: if the lock file was deleted / replaced, the running instance
            // holds the old (unlinked) inode and a fresh lock proves nothing.
            $running = $this->masterPid();
            if ($running !== null) {
                throw new \RuntimeException(sprintf('Already running (pid %d). Use stop/reload/status.', $running));
            }
            $orphans = $this->describe($this->topLevel($this->titledHolders()));
            if ($orphans !== []) {
                throw new \RuntimeException($this->orphanMessage($orphans));
            }
        } catch (\RuntimeException $e) {
            $this->releaseLock();
            throw $e;
        }
        $this->writePidFile((int) getmypid());
        try {
            $this->assertPortsFree();
        } catch (\RuntimeException $e) {
            $this->unlinkOwnPidFile();
            $this->releaseLock();
            throw $e;
        }

        $this->running = true;
        $this->stopping = false;
        $this->forced = false;
        $this->startedAt = microtime(true);

        if ($this->daemonize()) {
            ProcessLog::setAnsi(false);
            // The daemon inherits the locked descriptor; the CLI parent exits without unlocking.
            $this->daemonizeToLogFile();
            $this->writePidFile((int) getmypid());
        }

        ProcessLog::setTag('master');
        ProcessLog::setTagWidth($this->longestTag());
        $this->setTitle('master');
        $this->spawnAll();
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

        return $this->forced ? 1 : 0;
    }

    /**
     * Stop this instance.
     *
     * Master alive: SIGTERM, wait until the master AND every process holding the instance lock are
     * gone (ports free), else after $timeout SIGKILL the master plus all lock holders.
     * Master gone but orphans alive (kill -9 / crash): SIGTERM the orphaned children, wait up to
     * $orphanTimeout, then SIGKILL every remaining lock holder.
     * A stale pid file (pid reused by an unrelated process) is never signalled.
     *
     * $progress(string $event, array $context): not_running | stopping | stopped | timeout
     *   | orphans | orphans_stopped | orphans_killed.
     * Returns 0 when stopped gracefully / not running, 1 when it had to SIGKILL.
     *
     * @param null|callable(string, array<string, mixed>): void $progress
     */
    public function stop(?callable $progress = null, float $timeout = 30.0, float $orphanTimeout = 10.0): int
    {
        $progress ??= static function (string $event, array $context): void {
        };
        $pid = $this->masterPid();
        if ($pid === null) {
            $holders = $this->instancePids();
            if ($holders === []) {
                $this->unlinkStalePidFile();
                $progress('not_running', []);

                return 0;
            }

            return $this->stopOrphans($holders, $progress, $orphanTimeout);
        }

        $progress('stopping', ['pid' => $pid]);
        posix_kill($pid, SIGTERM);

        $started = microtime(true);
        $deadline = $started + $timeout;
        while (microtime(true) < $deadline) {
            if (!$this->pidAlive($pid) && !$this->instanceBusy()) {
                $this->unlinkStalePidFile();
                $progress('stopped', ['pid' => $pid, 'seconds' => round(microtime(true) - $started, 2)]);

                return 0;
            }
            usleep(100_000);
        }

        $pids = array_values(array_unique(array_merge([$pid], $this->lockHolders() ?? [])));
        foreach ($pids as $p) {
            @posix_kill($p, SIGKILL);
        }
        $this->waitUnlocked(2.0);
        $progress('timeout', ['pid' => $pid, 'killed' => $pids]);
        $this->unlinkStalePidFile();

        return 1;
    }

    /**
     * @param list<int> $holders
     * @param callable(string, array<string, mixed>): void $progress
     */
    private function stopOrphans(array $holders, callable $progress, float $timeout): int
    {
        $top = $this->topLevel($holders);
        $progress('orphans', ['pids' => $top, 'count' => count($holders), 'processes' => $this->describe($top)]);
        foreach ($top as $p) {
            @posix_kill($p, SIGTERM);
        }
        $started = microtime(true);
        if ($this->waitUnlocked($timeout)) {
            $this->unlinkStalePidFile();
            $progress('orphans_stopped', ['count' => count($holders), 'seconds' => round(microtime(true) - $started, 2)]);

            return 0;
        }
        $left = $this->instancePids();
        foreach ($left as $p) {
            @posix_kill($p, SIGKILL);
        }
        $this->waitUnlocked(2.0);
        $this->unlinkStalePidFile();
        $progress('orphans_killed', ['pids' => $left, 'seconds' => round(microtime(true) - $started, 2)]);

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

    /**
     * Pid of the live master of this instance, or null. "Running" means the instance lock is held
     * AND the pid file names a live process that holds it — so a stale pid file whose pid was reused
     * by an unrelated process does not count (PID reuse), and neither do orphans without a master.
     */
    public function masterPid(): ?int
    {
        $pid = $this->readPid();
        $holders = $this->lockHolders();
        if ($holders === null) {
            // No /proc (non-Linux): pid file + lock only.
            return $pid !== null && $this->pidAlive($pid) && $this->lockHeld() ? $pid : null;
        }
        if ($pid !== null && in_array($pid, $holders, true) && ($this->lockHeld() || $this->isMasterProcess($pid))) {
            return $pid;
        }
        // Fallback when the pid file is missing / wrong or the lock file was deleted / replaced:
        // the process titled "loong-swoole: master" that has THIS instance's lock path open (the
        // current file or the unlinked inode, "<path> (deleted)"). The lock path is per instance
        // (derived from the pid file), so other instances on the host never match.
        foreach ($holders as $h) {
            if ($this->isMasterProcess($h)) {
                return $h;
            }
        }

        return null;
    }

    /** Lock file guarding this instance: <pid file without .pid>.lock (never unlinked). */
    public function lockFile(): string
    {
        $pid = $this->pidFilePath();

        return (str_ends_with($pid, '.pid') ? substr($pid, 0, -4) : $pid) . '.lock';
    }

    /**
     * Processes of this instance that are alive although the master is gone (top level only).
     *
     * @return list<array{pid: int, ppid: int, title: string}>
     */
    public function orphans(): array
    {
        if ($this->masterPid() !== null) {
            return [];
        }

        return $this->describe($this->topLevel($this->instancePids()));
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
        $orphans = $masterPid === null ? $this->orphans() : [];
        if ($masterPid === null && $orphans === []) {
            $this->unlinkStalePidFile();
        }
        $childPids = [];
        if ($masterPid !== null) {
            $childPids = $this->childPidsByName($masterPid);
        } else {
            foreach ($orphans as $o) {
                if (preg_match('/^' . preg_quote(self::TITLE_PREFIX, '/') . ': ([^\s#]+)/', $o['title'], $mm) === 1 && $mm[1] !== 'watchdog') {
                    $childPids[$mm[1]][] = $o['pid'];
                }
            }
        }

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
                $pids !== [] => $masterPid !== null ? 'running' : 'orphaned',
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
            'orphans' => $orphans,
            'notes' => $masterPid !== null ? $this->healthNotes($masterPid) : [],
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
        // Forked before the role binds any socket, so the watchdog never holds ports.
        $this->startWatchdog($name . '#' . $index);

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
                    foreach ($this->children as $child) {
                        if ($child['pid'] > 0 && $this->pidAlive($child['pid'])) {
                            ProcessLog::warn(sprintf('stop timeout (%ds): SIGKILL %s#%d pid=%d', (int) self::STOP_GRACE, $child['name'], $child['index'], $child['pid']));
                        }
                    }
                    $this->killTree();
                    $this->reapChildren(1.0);
                    break;
                }
            }

            $this->selfHeal();
            usleep(100_000);
        }

        $this->running = false;
        if ($this->forced) {
            $this->reapChildren(1.0);
        }
        // Watchdogs / grandchildren still holding the lock: give them a moment, then SIGKILL.
        if (!$this->waitUnlocked(2.0, true)) {
            $left = $this->lockHolders() ?? [];
            ProcessLog::warn(sprintf('SIGKILL %d leftover process(es): %s', count($left), implode(',', $left)));
            $this->killTree();
            $this->waitUnlocked(1.0, true);
        }
        $this->unlinkOwnPidFile();
        $this->releaseLock();
        $now = microtime(true);
        ProcessLog::info(sprintf(
            'ProcessManager exited%s%s uptime=%s',
            $this->forced ? ' (forced)' : '',
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
                $this->forceStop($signo);

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
            $this->stopDeadline = microtime(true) + self::STOP_GRACE;
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
        // in the poll loop; document running with `php -d disable_functions= loongs`.
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

    /**
     * Take the instance lock (LOCK_EX|LOCK_NB). Refuses when a master runs, or when orphans of a
     * dead master still hold it. Retries ~1s so a status probe (LOCK_SH) or the last watchdog of a
     * just-finished shutdown does not cause a spurious refusal.
     */
    private function acquireLock(): void
    {
        $file = $this->lockFile();
        $dir = dirname($file);
        if (!is_dir($dir)) {
            @mkdir($dir, 0775, true);
        }
        $fh = @fopen($file, 'c');
        if (!is_resource($fh)) {
            throw new \RuntimeException("Cannot open lock file {$file}.");
        }
        $deadline = microtime(true) + 1.0;
        while (!flock($fh, LOCK_EX | LOCK_NB)) {
            $master = $this->masterPid();
            if ($master !== null) {
                fclose($fh);
                throw new \RuntimeException(sprintf('Already running (pid %d). Use stop/reload/status.', $master));
            }
            if (microtime(true) >= $deadline) {
                fclose($fh);
                $orphans = $this->describe($this->topLevel($this->lockHolders() ?? []));
                foreach ($orphans as $o) {
                    if (str_ends_with($o['title'], ': master')) {
                        throw new \RuntimeException(sprintf('Already running (pid %d). Use stop/reload/status.', $o['pid']));
                    }
                }
                if ($orphans !== []) {
                    throw new \RuntimeException($this->orphanMessage($orphans));
                }
                throw new \RuntimeException("Another start is in progress (lock {$file} is held). Retry, or check ./loongs status.");
            }
            usleep(50_000);
        }
        $this->lockHandle = $fh;
    }

    /**
     * Remove the pid file only when provably nobody runs this instance: we are the master, or we can
     * take the lock ourselves (a starter writes the pid file only while holding it). Avoids a racing
     * `status` / losing `start` deleting the pid file a new master just wrote.
     */
    private function unlinkStalePidFile(): void
    {
        if (is_resource($this->lockHandle)) {
            $this->unlinkOwnPidFile();

            return;
        }
        // Never while this instance still has processes (lock file may have been deleted under them).
        if ($this->masterPid() !== null || $this->titledHolders() !== []) {
            return;
        }
        $file = $this->lockFile();
        if (!is_file($file)) {
            $this->unlinkPidFile();

            return;
        }
        $fh = @fopen($file, 'c');
        if (!is_resource($fh)) {
            return;
        }
        if (flock($fh, LOCK_EX | LOCK_NB)) {
            $this->unlinkPidFile();
            flock($fh, LOCK_UN);
        }
        fclose($fh);
    }

    /** Canonical lock path as shown by /proc/<pid>/fd links (works when the file itself is gone). */
    private function lockTarget(): string
    {
        $file = $this->lockFile();
        $dir = realpath(dirname($file)) ?: dirname($file);

        return $dir . '/' . basename($file);
    }

    private function isMasterProcess(int $pid): bool
    {
        return $this->processTitle($pid) === self::TITLE_PREFIX . ': master';
    }

    /**
     * Lock holders titled "loong-swoole: …" — this instance's processes, excluding transient CLI
     * probes (status/start) that merely opened the file.
     *
     * @return list<int>
     */
    private function titledHolders(): array
    {
        return array_values(array_filter(
            $this->lockHolders() ?? [],
            fn (int $p): bool => str_starts_with($this->processTitle($p), self::TITLE_PREFIX . ':'),
        ));
    }

    /**
     * Processes of this instance: every lock holder while the lock is held, else only the titled
     * holders of the (possibly unlinked) lock path.
     *
     * @return list<int>
     */
    private function instancePids(): array
    {
        return $this->lockHeld() ? ($this->lockHolders() ?? []) : $this->titledHolders();
    }

    /** True while any process of this instance is alive (lock held, or titled holders of an unlinked lock). */
    private function instanceBusy(): bool
    {
        return $this->lockHeld() || $this->titledHolders() !== [];
    }

    /** @param list<array{pid: int, ppid: int, title: string}> $orphans */
    private function orphanMessage(array $orphans): string
    {
        return sprintf(
            'The master is gone but %d process(es) of the previous run are still alive (%s) and may hold the ports. Run ./loongs stop to clean them up.',
            count($orphans),
            implode(', ', array_map(static fn (array $o): string => sprintf('pid %d %s', $o['pid'], $o['title']), $orphans)),
        );
    }

    /** Unlink the pid file only when it names this process (never a file another start wrote). */
    private function unlinkOwnPidFile(): void
    {
        if ($this->readPid() === (int) getmypid()) {
            $this->unlinkPidFile();
        }
    }

    /**
     * Master only, from the supervise loop (every HEAL_INTERVAL): restore a deleted / overwritten
     * pid file, and re-create + re-lock a deleted / replaced lock file. Processes forked earlier keep
     * the old (unlinked) inode; lockHolders() matches "<path> (deleted)" so status/stop/start still
     * see them. If someone else already holds a replacement lock, log an ERROR (once) and keep running.
     */
    private function selfHeal(): void
    {
        $now = microtime(true);
        if ($this->stopping || $now < $this->nextHealAt) {
            return;
        }
        $this->nextHealAt = $now + self::HEAL_INTERVAL;
        $me = (int) getmypid();

        $pidFile = $this->pidFilePath();
        clearstatcache(true, $pidFile);
        $current = $this->readPid();
        if ($current !== $me) {
            $was = is_file($pidFile) ? sprintf('overwritten (%s)', $current === null ? 'invalid content' : 'pid ' . $current) : 'missing';
            $this->writePidFile($me);
            ProcessLog::warn(sprintf('pid file %s was %s: rewritten with pid %d', $pidFile, $was, $me));
        }

        $lockFile = $this->lockFile();
        clearstatcache(true, $lockFile);
        $st = @stat($lockFile);
        $fst = is_resource($this->lockHandle) ? @fstat($this->lockHandle) : false;
        if ($st !== false && $fst !== false && $st['ino'] === $fst['ino'] && $st['dev'] === $fst['dev']) {
            $this->healConflictLogged = false;

            return;
        }
        $fh = @fopen($lockFile, 'c');
        if (!is_resource($fh)) {
            if (!$this->healConflictLogged) {
                ProcessLog::error(sprintf('lock file %s is %s and cannot be re-created; still running', $lockFile, $st === false ? 'missing' : 'replaced'));
                $this->healConflictLogged = true;
            }

            return;
        }
        if (!flock($fh, LOCK_EX | LOCK_NB)) {
            fclose($fh);
            if (!$this->healConflictLogged) {
                $others = array_values(array_diff($this->lockHolders() ?? [], $this->descendants($me)));
                ProcessLog::error(sprintf(
                    'lock file %s was replaced and is locked by another process (%s); still running — check ./loongs status',
                    $lockFile,
                    $others === [] ? '?' : implode(', ', array_map(static fn (array $o): string => sprintf('pid %d %s', $o['pid'], $o['title']), $this->describe($others))),
                ));
                $this->healConflictLogged = true;
            }

            return;
        }
        $old = $this->lockHandle;
        $this->lockHandle = $fh;
        // Children keep the old descriptor (shared open file description), so the old inode stays
        // locked while they live; closing our copy changes nothing for them.
        if (is_resource($old)) {
            fclose($old);
        }
        $this->healConflictLogged = false;
        ProcessLog::warn(sprintf(
            'lock file %s was %s: re-created and locked (children keep the unlinked inode; status/stop still find them)',
            $lockFile,
            $st === false ? 'missing' : 'replaced',
        ));
    }

    /**
     * Status notes for a running master whose pid / lock file is gone or not its own (self-healing).
     *
     * @return list<string>
     */
    private function healthNotes(int $masterPid): array
    {
        $notes = [];
        $pid = $this->readPid();
        if ($pid !== $masterPid) {
            $notes[] = $pid === null
                ? sprintf('pid file missing or invalid — the master rewrites it within ~%ds', (int) ceil(self::HEAL_INTERVAL))
                : sprintf('pid file names pid %d, not the master — the master rewrites it within ~%ds', $pid, (int) ceil(self::HEAL_INTERVAL));
        }
        $target = $this->lockTarget();
        clearstatcache(true, $target);
        if (!is_file($target)) {
            $notes[] = sprintf('lock file missing — the master re-creates it within ~%ds', (int) ceil(self::HEAL_INTERVAL));
        } else {
            $holdsCurrent = false;
            foreach (@scandir('/proc/' . $masterPid . '/fd') ?: [] as $fd) {
                if ($fd !== '.' && $fd !== '..' && @readlink('/proc/' . $masterPid . '/fd/' . $fd) === $target) {
                    $holdsCurrent = true;
                    break;
                }
            }
            if (!$holdsCurrent && is_dir('/proc/' . $masterPid . '/fd')) {
                $notes[] = sprintf('lock file was replaced — the master re-locks it within ~%ds', (int) ceil(self::HEAL_INTERVAL));
            }
        }

        return $notes;
    }

    private function releaseLock(): void
    {
        if (is_resource($this->lockHandle)) {
            flock($this->lockHandle, LOCK_UN);
            fclose($this->lockHandle);
        }
        $this->lockHandle = null;
    }

    /** True when some process holds the instance lock (probe with LOCK_SH|LOCK_NB, released at once). */
    private function lockHeld(): bool
    {
        $file = $this->lockFile();
        if (!is_file($file)) {
            return false;
        }
        $fh = @fopen($file, 'r');
        if (!is_resource($fh)) {
            return false;
        }
        $free = flock($fh, LOCK_SH | LOCK_NB);
        if ($free) {
            flock($fh, LOCK_UN);
        }
        fclose($fh);

        return !$free;
    }

    /** Wait until nobody (or, with $exceptSelf, nobody but this process) holds the lock. */
    private function waitUnlocked(float $timeout, bool $exceptSelf = false): bool
    {
        $deadline = microtime(true) + $timeout;
        do {
            $free = $exceptSelf ? ($this->lockHolders() ?? []) === [] : !$this->instanceBusy();
            if ($free) {
                return true;
            }
            usleep(50_000);
        } while (microtime(true) < $deadline);

        return false;
    }

    /**
     * Pids (other than this one) with an open descriptor on the lock file, via /proc/<pid>/fd.
     * Null when /proc is unavailable (non-Linux).
     *
     * @return list<int>|null
     */
    private function lockHolders(): ?array
    {
        if (!is_dir('/proc/self/fd')) {
            return null;
        }
        $target = $this->lockTarget();
        $deleted = $target . ' (deleted)';

        // "<path> (deleted)": the lock file was unlinked while processes of this instance still hold it.
        return $this->pidsWithFd(static fn (string $link): bool => $link === $target || $link === $deleted);
    }

    /**
     * @param callable(string): bool $match
     * @return list<int>
     */
    private function pidsWithFd(callable $match): array
    {
        $self = (int) getmypid();
        $out = [];
        foreach (glob('/proc/[0-9]*', GLOB_ONLYDIR) ?: [] as $dir) {
            $pid = (int) basename($dir);
            if ($pid === $self) {
                continue;
            }
            foreach (@scandir($dir . '/fd') ?: [] as $fd) {
                if ($fd === '.' || $fd === '..') {
                    continue;
                }
                $link = @readlink($dir . '/fd/' . $fd);
                if (is_string($link) && $match($link)) {
                    $out[] = $pid;
                    break;
                }
            }
        }
        sort($out);

        return $out;
    }

    /**
     * @param list<int> $pids
     * @return list<int> pids whose parent is not in $pids (the tree roots)
     */
    private function topLevel(array $pids): array
    {
        return array_values(array_filter($pids, fn (int $p): bool => !in_array($this->parentPid($p), $pids, true)));
    }

    /**
     * @param list<int> $pids
     * @return list<array{pid: int, ppid: int, title: string}>
     */
    private function describe(array $pids): array
    {
        $out = [];
        foreach ($pids as $pid) {
            $title = $this->processTitle($pid);
            if (strlen($title) > 60) {
                $title = substr($title, 0, 57) . '...';
            }
            $out[] = ['pid' => $pid, 'ppid' => (int) ($this->parentPid($pid) ?? 0), 'title' => $title];
        }

        return $out;
    }

    private function parentPid(int $pid): ?int
    {
        $stat = @file_get_contents('/proc/' . $pid . '/stat');
        if (!is_string($stat) || ($rp = strrpos($stat, ')')) === false) {
            return null;
        }
        $fields = explode(' ', substr($stat, $rp + 2));

        return isset($fields[1]) ? (int) $fields[1] : null;
    }

    private function processTitle(int $pid): string
    {
        $cmd = trim(str_replace("\0", ' ', (string) @file_get_contents('/proc/' . $pid . '/cmdline')));

        return $cmd !== '' ? $cmd : '?';
    }

    /**
     * Every descendant of $pid (via /proc ppid links), deepest last.
     *
     * @return list<int>
     */
    private function descendants(int $pid): array
    {
        $parents = [];
        foreach (glob('/proc/[0-9]*', GLOB_ONLYDIR) ?: [] as $dir) {
            $p = (int) basename($dir);
            $pp = $this->parentPid($p);
            if ($pp !== null) {
                $parents[$pp][] = $p;
            }
        }
        $out = [];
        $queue = [$pid];
        while ($queue !== []) {
            $cur = array_shift($queue);
            foreach ($parents[$cur] ?? [] as $c) {
                $out[] = $c;
                $queue[] = $c;
            }
        }

        return $out;
    }

    /** SIGKILL every child and every other process holding the instance lock (grandchildren, watchdogs). */
    private function killTree(): void
    {
        $pids = $this->lockHolders() ?? [];
        foreach ($this->children as $child) {
            if ($child['pid'] > 0) {
                $pids[] = $child['pid'];
            }
        }
        foreach (array_unique($pids) as $p) {
            @posix_kill($p, SIGKILL);
        }
    }

    /** Reap exited children (logging "child exit" lines) until none are left or $timeout passes. */
    private function reapChildren(float $timeout): void
    {
        $deadline = microtime(true) + $timeout;
        while ($this->children !== [] && microtime(true) < $deadline) {
            $ret = Process::wait(false);
            if (is_array($ret) && isset($ret['pid'])) {
                $this->onChildExit((int) $ret['pid'], (int) ($ret['code'] ?? 0), (int) ($ret['signal'] ?? 0));
                continue;
            }
            usleep(20_000);
        }
        $this->children = [];
    }

    /** Second SIGINT/SIGTERM while shutting down: SIGKILL the whole tree now. */
    private function forceStop(int $signo): void
    {
        if ($this->forced) {
            return;
        }
        $this->forced = true;
        $pids = $this->lockHolders() ?? [];
        foreach ($this->children as $child) {
            if ($child['pid'] > 0) {
                $pids[] = $child['pid'];
            }
        }
        $pids = array_values(array_unique($pids));
        sort($pids);
        ProcessLog::warn(sprintf(
            '%s again during shutdown (after %s): force exit — SIGKILL %d process(es): %s',
            $signo === SIGINT ? 'SIGINT' : ($signo === SIGTERM ? 'SIGTERM' : 'signal ' . $signo),
            ProcessLog::duration(microtime(true) - $this->stopStartedAt),
            count($pids),
            implode(',', $pids),
        ));
        foreach ($pids as $p) {
            @posix_kill($p, SIGKILL);
        }
        $this->running = false;
    }

    /**
     * Refuse to start when a configured port is already bound (orphans, another instance, anything).
     * Probes with a plain bind (no SO_REUSEPORT), so it also catches an RPC server that would
     * otherwise let a second SO_REUSEPORT listener co-bind the port.
     */
    private function assertPortsFree(): void
    {
        foreach ($this->enabledEntries() as $name => $cfg) {
            $port = (int) ($cfg['port'] ?? 0);
            if ($port <= 0) {
                continue;
            }
            $host = (string) ($cfg['host'] ?? '0.0.0.0');
            $error = $this->bindProbe($host, $port);
            if ($error === null) {
                continue;
            }
            $owners = $this->describe($this->portOwners($port));
            $ours = array_filter($owners, static fn (array $o): bool => str_starts_with($o['title'], self::TITLE_PREFIX . ':'));
            throw new \RuntimeException(sprintf(
                'Port %s:%d for [%s] is already in use%s (%s).%s',
                $host,
                $port,
                $name,
                $owners === [] ? '' : ' by ' . implode(', ', array_map(static fn (array $o): string => sprintf('pid %d %s', $o['pid'], $o['title']), array_slice($owners, 0, 5))),
                $error,
                $ours !== []
                    ? ' Leftover loong-swoole processes: run ./loongs stop (or stop the instance that owns them).'
                    : ' Free the port or change it in .env / config/process.php.',
            ));
        }
    }

    private function bindProbe(string $host, int $port): ?string
    {
        $h = $host === '' ? '0.0.0.0' : $host;
        if (str_contains($h, ':') && $h[0] !== '[') {
            $h = '[' . $h . ']';
        }
        $ctx = stream_context_create(['socket' => ['so_reuseport' => false, 'backlog' => 1]]);
        $server = @stream_socket_server('tcp://' . $h . ':' . $port, $errno, $errstr, STREAM_SERVER_BIND | STREAM_SERVER_LISTEN, $ctx);
        if ($server === false) {
            return $errstr !== '' ? $errstr : 'errno ' . $errno;
        }
        fclose($server);

        return null;
    }

    /**
     * Top-level pids listening on TCP $port (/proc/net/tcp{,6} LISTEN inodes → /proc/<pid>/fd).
     *
     * @return list<int>
     */
    private function portOwners(int $port): array
    {
        $inodes = [];
        foreach (['/proc/net/tcp', '/proc/net/tcp6'] as $table) {
            foreach (@file($table, FILE_IGNORE_NEW_LINES) ?: [] as $i => $line) {
                $f = preg_split('/\s+/', trim($line));
                if ($i === 0 || !is_array($f) || count($f) < 10 || $f[3] !== '0A') {
                    continue;
                }
                $local = explode(':', $f[1]);
                if (hexdec((string) end($local)) === $port) {
                    $inodes['socket:[' . $f[9] . ']'] = true;
                }
            }
        }
        if ($inodes === []) {
            return [];
        }
        $pids = $this->pidsWithFd(static fn (string $link): bool => isset($inodes[$link]));

        return $this->topLevel($pids);
    }

    /**
     * Per-child watchdog (forked in the child before the role starts): if the master disappears
     * (kill -9, crash, terminal closed) the child is re-parented; the watchdog notices within
     * ~200ms, sends SIGTERM for a graceful stop and SIGKILLs the child's tree after ORPHAN_GRACE.
     * It exits by itself as soon as the child is gone.
     */
    private function startWatchdog(string $tag): void
    {
        if (!function_exists('posix_getppid') || !function_exists('posix_kill')) {
            return;
        }
        $masterPid = posix_getppid();
        $childPid = (int) getmypid();
        if ($masterPid <= 1) {
            return;
        }
        $watchdog = new Process(function () use ($tag, $masterPid, $childPid): void {
            $this->setTitle('watchdog ' . $tag);
            ProcessLog::setTag($tag);
            while (true) {
                usleep(200_000);
                if (posix_getppid() !== $childPid) {
                    exit(0);
                }
                $ppid = $this->parentPid($childPid);
                if (@posix_kill($masterPid, 0) && ($ppid === null || $ppid === $masterPid)) {
                    continue;
                }
                ProcessLog::warn(sprintf('master pid=%d is gone — stopping orphaned %s pid=%d (SIGTERM, SIGKILL after %ds)', $masterPid, $tag, $childPid, (int) self::ORPHAN_GRACE));
                $t0 = microtime(true);
                @posix_kill($childPid, SIGTERM);
                while (microtime(true) - $t0 < self::ORPHAN_GRACE) {
                    usleep(100_000);
                    if (posix_getppid() !== $childPid) {
                        ProcessLog::info(sprintf('orphaned %s pid=%d exited after %s', $tag, $childPid, ProcessLog::duration(microtime(true) - $t0)));
                        exit(0);
                    }
                }
                $tree = array_values(array_diff($this->descendants($childPid), [(int) getmypid()]));
                ProcessLog::warn(sprintf('orphaned %s pid=%d did not stop in %ds: SIGKILL %d process(es)', $tag, $childPid, (int) self::ORPHAN_GRACE, count($tree) + 1));
                foreach (array_merge([$childPid], $tree) as $p) {
                    @posix_kill($p, SIGKILL);
                }
                exit(0);
            }
        }, false, 0, false);
        $watchdog->start();
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
