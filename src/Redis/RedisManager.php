<?php

declare(strict_types=1);

namespace Loongs\Redis;

use Loongs\Config\Repository;
use Redis;
use RuntimeException;
use Swoole\Database\RedisConfig;
use Swoole\Database\RedisPool;

/**
 * Multi-connection wrapper around Swoole\Database\RedisPool.
 *
 * Pools are process-worker scoped: boot in WorkerStart, close in WorkerStop/Exit.
 */
final class RedisManager
{
    /** @var array<string, mixed> */
    private array $config;

    /** @var array<string, RedisPool> */
    private array $pools = [];

    private bool $booted = false;

    public function __construct(Repository $repository)
    {
        /** @var array<string, mixed> $cfg */
        $cfg = $repository->get('redis', []);
        $this->config = is_array($cfg) ? $cfg : [];
    }

    public function bootPools(): void
    {
        if ($this->booted) {
            return;
        }

        $connections = $this->config['connections'] ?? [];
        if (!is_array($connections)) {
            $connections = [];
        }

        foreach ($connections as $name => $cfg) {
            if (!is_string($name) || !is_array($cfg)) {
                continue;
            }
            $this->pools[$name] = $this->makePool($cfg);
        }

        $this->booted = true;
    }

    public function closePools(): void
    {
        foreach ($this->pools as $pool) {
            $pool->close();
        }
        $this->pools = [];
        $this->booted = false;
    }

    public function isBooted(): bool
    {
        return $this->booted;
    }

    /** @return list<string> */
    public function connectionNames(): array
    {
        $connections = $this->config['connections'] ?? [];
        if (!is_array($connections)) {
            return [];
        }

        return array_values(array_filter(array_keys($connections), 'is_string'));
    }

    public function getDefaultConnection(): string
    {
        $default = $this->config['default'] ?? 'default';
        return is_string($default) && $default !== '' ? $default : 'default';
    }

    public function connection(?string $name = null): Redis
    {
        $pool = $this->pool($name);
        $timeout = $this->waitTimeout($name);
        /** @var Redis $redis */
        $redis = $pool->get($timeout);
        return $redis;
    }

    public function put(Redis $redis, ?string $name = null): void
    {
        $this->pool($name)->put($redis);
    }

    /**
     * @template T
     * @param callable(Redis): T $fn
     * @return T
     */
    public function run(callable $fn, ?string $name = null): mixed
    {
        $redis = $this->connection($name);
        try {
            return $fn($redis);
        } finally {
            $this->put($redis, $name);
        }
    }

    public function get(string $key, ?string $name = null): mixed
    {
        return $this->run(static fn (Redis $r): mixed => $r->get($key), $name);
    }

    public function set(string $key, mixed $value, ?string $name = null): bool
    {
        return (bool) $this->run(static fn (Redis $r): mixed => $r->set($key, $value), $name);
    }

    public function setex(string $key, int $seconds, mixed $value, ?string $name = null): bool
    {
        return (bool) $this->run(static fn (Redis $r): mixed => $r->setex($key, $seconds, $value), $name);
    }

    public function del(string ...$keys): int
    {
        if ($keys === []) {
            return 0;
        }

        return (int) $this->run(static fn (Redis $r): mixed => $r->del(...$keys));
    }

    public function exists(string $key, ?string $name = null): bool
    {
        return (bool) $this->run(static fn (Redis $r): mixed => $r->exists($key), $name);
    }

    private function pool(?string $name): RedisPool
    {
        if (!$this->booted) {
            throw new RuntimeException('Redis pools are not booted. Call bootPools() in WorkerStart.');
        }

        $name ??= $this->getDefaultConnection();
        if (!isset($this->pools[$name])) {
            throw new RuntimeException("Redis connection [{$name}] is not configured.");
        }

        return $this->pools[$name];
    }

    private function waitTimeout(?string $name): float
    {
        $name ??= $this->getDefaultConnection();
        $connections = $this->config['connections'] ?? [];
        if (!is_array($connections) || !isset($connections[$name]) || !is_array($connections[$name])) {
            return -1.0;
        }
        $pool = $connections[$name]['pool'] ?? [];
        if (!is_array($pool)) {
            return -1.0;
        }
        if (isset($pool['wait_timeout'])) {
            return (float) $pool['wait_timeout'];
        }
        return -1.0;
    }

    /** @param array<string, mixed> $cfg */
    private function makePool(array $cfg): RedisPool
    {
        $redisConfig = (new RedisConfig())
            ->withHost((string) ($cfg['host'] ?? '127.0.0.1'))
            ->withPort((int) ($cfg['port'] ?? 6379))
            ->withTimeout((float) ($cfg['timeout'] ?? 3.0))
            ->withReadTimeout((float) ($cfg['read_timeout'] ?? 3.0))
            ->withRetryInterval((int) ($cfg['retry_interval'] ?? 100))
            ->withDbIndex((int) ($cfg['db'] ?? 0));

        $auth = (string) ($cfg['auth'] ?? '');
        if ($auth !== '') {
            $redisConfig = $redisConfig->withAuth($auth);
        }

        $poolCfg = $cfg['pool'] ?? [];
        $size = 16;
        if (is_array($poolCfg) && isset($poolCfg['size'])) {
            $size = max(1, (int) $poolCfg['size']);
        }

        return new RedisPool($redisConfig, $size);
    }
}
