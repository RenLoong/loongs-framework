<?php

declare(strict_types=1);

namespace Loongs\Process;

use Loongs\Container\Container;
use Loongs\Database\DatabaseManager;
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
