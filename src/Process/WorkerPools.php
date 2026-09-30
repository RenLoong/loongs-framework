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

        $close = static function () use ($container): void {
            self::stopRpcHotReload($container);
            self::close($container);
        };

        $server->on('WorkerStop', static function (Server $server, int $workerId) use ($close): void {
            $close();
        });

        $server->on('WorkerExit', static function (Server $server, int $workerId) use ($close): void {
            $close();
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

        $close = static function () use (&$app): void {
            if ($app instanceof Application) {
                self::stopRpcHotReload($app->container());
                self::close($app->container());
            }
        };
        $server->on('WorkerStop', static function (Server $server, int $workerId) use ($close): void {
            $close();
        });
        $server->on('WorkerExit', static function (Server $server, int $workerId) use ($close): void {
            $close();
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

    public static function close(Container $container): void
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
