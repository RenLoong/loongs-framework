<?php

declare(strict_types=1);

namespace Loongs\Process;

use Loongs\Container\Container;
use Loongs\Database\DatabaseManager;
use Loongs\Http\Application;
use Loongs\Redis\RedisManager;
use Loongs\Rpc\HotReload\RpcServiceReloader;
use Swoole\Server;

/**
 * Shared WorkerStart / WorkerStop / WorkerExit pool lifecycle for Swoole servers.
 */
final class WorkerPools
{
    public static function attach(Server $server, Container $container): void
    {
        $server->on('WorkerStart', static function (Server $server, int $workerId) use ($container): void {
            self::enableCoroutineHooks();
            self::boot($container);
            self::startRpcHotReload($container);
        });

        $close = static function (bool $loopRunning) use ($container): void {
            self::stopRpcHotReload($container);
            self::close($container, $loopRunning);
        };

        // WorkerExit (reload_async) fires on every loop turn while the worker drains: the reactor
        // is alive. WorkerStop runs after the reactor has ended.
        $server->on('WorkerStop', static function (Server $server, int $workerId) use ($close): void {
            $close(false);
        });

        $server->on('WorkerExit', static function (Server $server, int $workerId) use ($close): void {
            $close(true);
        });
    }

    /**
     * Build a fresh Application in every worker (WorkerStart) instead of once before the server
     * forks, so a Swoole worker reload (`./loongs reload` → SIGUSR1) re-reads config/*.php, routes
     * and app code. Pools and the rpc.services hot-reload timer follow the worker lifecycle as in
     * attach(). The rpc hot-reload peer bus (Swoole\Atomic) is created here, before the fork, and
     * shared by all workers of this server. Returns a getter for the current worker's Application.
     *
     * @param callable(): Application $factory
     * @return \Closure(): ?Application
     */
    public static function attachPerWorker(Server $server, callable $factory): \Closure
    {
        $peerBus = class_exists(\Swoole\Atomic::class) ? new \Swoole\Atomic(0) : null;
        $app = null;
        $server->on('WorkerStart', static function (Server $server, int $workerId) use ($factory, $peerBus, &$app): void {
            self::enableCoroutineHooks();
            $app = $factory();
            $container = $app->container();
            if ($peerBus !== null) {
                try {
                    /** @var RpcServiceReloader $reloader */
                    $reloader = $container->make(RpcServiceReloader::class);
                    $reloader->setPeerBus($peerBus);
                } catch (\Throwable) {
                    // no rpc in this app
                }
            }
            self::boot($container);
            self::startRpcHotReload($container);
        });

        $close = static function (bool $loopRunning) use (&$app): void {
            if ($app instanceof Application) {
                self::stopRpcHotReload($app->container());
                self::close($app->container(), $loopRunning);
            }
        };
        $server->on('WorkerStop', static function (Server $server, int $workerId) use ($close): void {
            $close(false);
        });
        $server->on('WorkerExit', static function (Server $server, int $workerId) use ($close): void {
            $close(true);
        });

        return static function () use (&$app): ?Application {
            return $app;
        };
    }

    /**
     * Swoole server mode for a Swoole\Server role, chosen so that `./loongs reload` (SIGUSR1 → worker
     * reload) always has a manager process to act on:
     *   - process entry 'mode' => 'process' | 'base' wins;
     *   - websocket ($preferProcess): SWOOLE_PROCESS — connections live in the master's reactor and
     *     survive a worker reload;
     *   - worker_num <= 1: SWOOLE_PROCESS (SWOOLE_BASE with one worker has no manager, SIGUSR1 is a no-op);
     *   - otherwise SWOOLE_BASE (Swoole 6 default).
     *
     * @param array<string, mixed> $config   process entry (config/process.php)
     * @param array<string, mixed> $settings Swoole settings of that entry
     */
    public static function serverMode(array $config, array $settings, bool $preferProcess = false): int
    {
        $mode = strtolower((string) ($config['mode'] ?? ''));
        if ($mode === 'process' || $mode === 'base') {
            return $mode === 'process' ? SWOOLE_PROCESS : SWOOLE_BASE;
        }
        if ($preferProcess) {
            return SWOOLE_PROCESS;
        }
        $workers = (int) ($settings['worker_num'] ?? (function_exists('swoole_cpu_num') ? swoole_cpu_num() : 1));

        return $workers <= 1 ? SWOOLE_PROCESS : SWOOLE_BASE;
    }

    /** True when a server started with these options has a manager (SIGUSR1 reloads its workers). */
    public static function reloadsWorkers(array $config, array $settings, bool $preferProcess = false): bool
    {
        if (self::serverMode($config, $settings, $preferProcess) === SWOOLE_PROCESS) {
            return true;
        }

        return (int) ($settings['worker_num'] ?? (function_exists('swoole_cpu_num') ? swoole_cpu_num() : 1)) > 1;
    }

    public static function enableCoroutineHooks(): void
    {
        if (class_exists(\Swoole\Runtime::class)) {
            \Swoole\Runtime::enableCoroutine(
                SWOOLE_HOOK_TCP
                | SWOOLE_HOOK_UNIX
                | SWOOLE_HOOK_UDG
                | SWOOLE_HOOK_SSL
                | SWOOLE_HOOK_TLS
                | SWOOLE_HOOK_SLEEP
                | SWOOLE_HOOK_FILE
                | SWOOLE_HOOK_STREAM_FUNCTION
                | SWOOLE_HOOK_SOCKETS
            );
        }
    }

    public static function boot(Container $container): void
    {
        try {
            /** @var DatabaseManager $db */
            $db = $container->make(DatabaseManager::class);
            $db->bootPools();
        } catch (\Throwable) {
            // Database may be optional for some roles.
        }

        try {
            /** @var RedisManager $redis */
            $redis = $container->make(RedisManager::class);
            $redis->bootPools();
        } catch (\Throwable) {
            // Redis may be optional for some roles.
        }
    }

    /**
     * Per-worker timer that hot-reloads rpc.services (see RpcServiceReloader).
     */
    public static function startRpcHotReload(Container $container): void
    {
        if (!$container->has(RpcServiceReloader::class)) {
            return;
        }
        try {
            /** @var RpcServiceReloader $reloader */
            $reloader = $container->make(RpcServiceReloader::class);
            $reloader->startTimer();
        } catch (\Throwable) {
            // hot reload is best-effort; never block worker start
        }
    }

    public static function stopRpcHotReload(Container $container): void
    {
        if (!$container->has(RpcServiceReloader::class)) {
            return;
        }
        try {
            /** @var RpcServiceReloader $reloader */
            $reloader = $container->make(RpcServiceReloader::class);
            $reloader->stopTimer();
        } catch (\Throwable) {
            // ignore
        }
    }

    /**
     * Close this worker's DB / Redis pools. Swoole\ConnectionPool::close() closes a Channel and
     * destroys hooked connections, which must happen inside a coroutine; WorkerStop / WorkerExit
     * callbacks and the code after a role's co_run() are not coroutines (Coroutine::getCid() === -1),
     * so closing there directly raised the uncatchable "API must be called in the coroutine" fatal
     * on every `./loongs stop`.
     *
     * $loopRunning: the caller runs inside a live reactor (WorkerExit with reload_async). The close
     * coroutine is then scheduled on that reactor (a nested Coroutine\run() would block it and
     * starve the io_uring / socket completions the close waits for). Otherwise (WorkerStop, after
     * co_run()) a short private Coroutine\run() is used. No-op when no pool is open.
     */
    public static function close(Container $container, bool $loopRunning = false): void
    {
        if (!self::hasOpenPools($container)) {
            return; // never spawn coroutines for nothing: WorkerExit fires on every loop turn
        }
        self::inCoroutine(static function () use ($container): void {
            self::closeNow($container);
        }, $loopRunning);
    }

    /**
     * Run $fn inside a coroutine (shutdown helper):
     *   - already in a coroutine → call directly;
     *   - $loopRunning → Coroutine::create(): runs synchronously up to its first yield, the live
     *     reactor finishes it;
     *   - otherwise → Swoole\Coroutine\run(). Timers / signal handlers a stopped worker left
     *     behind would keep that loop alive, so it ends with Event::exit() once $fn returns, and a
     *     watchdog ends it after $timeout seconds if $fn never completes.
     * Without Swoole $fn is called directly. Errors are swallowed (shutdown path).
     */
    public static function inCoroutine(callable $fn, bool $loopRunning = false, float $timeout = 3.0): void
    {
        $task = static function () use ($fn): void {
            try {
                $fn();
            } catch (\Throwable) {
                // ignore close errors during shutdown
            }
        };
        try {
            if (!class_exists(\Swoole\Coroutine::class) || \Swoole\Coroutine::getCid() > 0) {
                $task();

                return;
            }
            if ($loopRunning) {
                \Swoole\Coroutine::create($task);

                return;
            }
            \Swoole\Coroutine\run(static function () use ($task, $timeout): void {
                $watchdog = \Swoole\Timer::after(max(1, (int) ($timeout * 1000)), static function (): void {
                    \Swoole\Event::exit();
                });
                $task();
                \Swoole\Timer::clear($watchdog);
                \Swoole\Event::exit();
            });
        } catch (\Throwable) {
            // e.g. a reactor is unexpectedly still running: schedule on it instead
            try {
                \Swoole\Coroutine::create($task);
            } catch (\Throwable) {
            }
        }
    }

    private static function hasOpenPools(Container $container): bool
    {
        foreach ([DatabaseManager::class, RedisManager::class] as $id) {
            try {
                if ($container->has($id) && $container->make($id)->isBooted()) {
                    return true;
                }
            } catch (\Throwable) {
                // not configured for this role
            }
        }

        return false;
    }

    private static function closeNow(Container $container): void
    {
        try {
            /** @var DatabaseManager $db */
            $db = $container->make(DatabaseManager::class);
            if ($db->isBooted()) {
                $db->closePools();
            }
        } catch (\Throwable) {
            // ignore close errors during shutdown
        }

        try {
            /** @var RedisManager $redis */
            $redis = $container->make(RedisManager::class);
            if ($redis->isBooted()) {
                $redis->closePools();
            }
        } catch (\Throwable) {
            // ignore close errors during shutdown
        }
    }
}
