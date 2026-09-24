<?php

declare(strict_types=1);

namespace Loongs\Process;

use Loongs\Container\Container;
use Loongs\Database\DatabaseManager;
use Loongs\Redis\RedisManager;
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
        });

        $close = static function () use ($container): void {
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
