<?php

declare(strict_types=1);

/**
 * Redis connections for loongs/framework (Loongs\Redis\RedisManager).
 *
 * Published by loongs/composer-plugin when missing. Safe to edit; never overwritten.
 */
$env = static function (string $key, mixed $default = null): mixed {
    if (function_exists('env')) {
        return env($key, $default);
    }
    $v = $_ENV[$key] ?? $_SERVER[$key] ?? false;

    return $v === false ? $default : $v;
};

return [
    'default' => $env('REDIS_CONNECTION', 'default'),
    'connections' => [
        'default' => [
            'host' => $env('REDIS_HOST', '127.0.0.1'),
            'port' => (int) $env('REDIS_PORT', 6379),
            'auth' => $env('REDIS_AUTH', ''),
            'db' => (int) $env('REDIS_DB', 0),
            'timeout' => (float) $env('REDIS_TIMEOUT', 3.0),
            'read_timeout' => (float) $env('REDIS_READ_TIMEOUT', 3.0),
            // Non-zero required: Swoole RedisPool packs read_timeout as connect() arg#4
            // unless retry_interval is set (phpredis 6 expects ?string persistent_id there).
            'retry_interval' => (int) $env('REDIS_RETRY_INTERVAL', 100),
            'pool' => [
                'size' => (int) $env('REDIS_POOL_SIZE', 16),
                'wait_timeout' => (float) $env('REDIS_POOL_WAIT_TIMEOUT', -1),
            ],
        ],
    ],
];
