<?php

declare(strict_types=1);

namespace Loongs\Database;

use Loongs\Config\Repository;
use PDO;
use RuntimeException;
use Swoole\Database\PDOConfig;
use Swoole\Database\PDOPool;
use Swoole\Database\PDOProxy;

/**
 * Multi-connection wrapper around Swoole\Database\PDOPool.
 *
 * Pools must be created in WorkerStart (per worker process), never in the master
 * before Server::start(). Prefer run()/get+put so connections always return.
 *
 * Health: a broken PDO should not be put back if you know it is dead; call
 * put() only for reusable connections. Swoole's pool creates a new connection
 * when the channel is empty, so limited auto-heal exists, but poisoned conns
 * returned to the pool can fail until evicted by failed use + recreate.
 */
final class DatabaseManager
{
    /** @var array<string, mixed> */
    private array $config;

    /** @var array<string, PDOPool> */
    private array $pools = [];

    private bool $booted = false;

    public function __construct(Repository $repository)
    {
        /** @var array<string, mixed> $cfg */
        $cfg = $repository->get('database', []);
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
        $default = $this->config['default'] ?? 'mysql';
        return is_string($default) && $default !== '' ? $default : 'mysql';
    }

    /**
     * @return PDO|PDOProxy
     */
    public function connection(?string $name = null): object
    {
        $pool = $this->pool($name);
        $timeout = $this->waitTimeout($name);
        /** @var PDO|PDOProxy $pdo */
        $pdo = $pool->get($timeout);
        return $pdo;
    }

    public function put(object $pdo, ?string $name = null): void
    {
        $this->pool($name)->put($pdo);
    }

    /**
     * Borrow a connection, run $fn, always put back (even on throw).
     *
     * @template T
     * @param callable(PDO|PDOProxy): T $fn
     * @return T
     */
    public function run(callable $fn, ?string $name = null): mixed
    {
        $pdo = $this->connection($name);
        try {
            return $fn($pdo);
        } finally {
            $this->put($pdo, $name);
        }
    }

    /**
     * @param array<int|string, mixed> $bindings
     * @return list<array<string, mixed>>
     */
    public function select(string $sql, array $bindings = [], ?string $name = null): array
    {
        return $this->run(static function (object $pdo) use ($sql, $bindings): array {
            $stmt = $pdo->prepare($sql);
            $stmt->execute($bindings);
            /** @var list<array<string, mixed>> $rows */
            $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
            return $rows;
        }, $name);
    }

    /**
     * @param array<int|string, mixed> $bindings
     */
    public function statement(string $sql, array $bindings = [], ?string $name = null): int
    {
        return $this->run(static function (object $pdo) use ($sql, $bindings): int {
            $stmt = $pdo->prepare($sql);
            $stmt->execute($bindings);
            return $stmt->rowCount();
        }, $name);
    }

    /**
     * @param array<int|string, mixed> $bindings
     * @return \PDOStatement
     */
    public function query(string $sql, array $bindings = [], ?string $name = null): \PDOStatement
    {
        return $this->run(static function (object $pdo) use ($sql, $bindings): \PDOStatement {
            $stmt = $pdo->prepare($sql);
            $stmt->execute($bindings);
            return $stmt;
        }, $name);
    }

    private function pool(?string $name): PDOPool
    {
        if (!$this->booted) {
            throw new RuntimeException('Database pools are not booted. Call bootPools() in WorkerStart.');
        }

        $name ??= $this->getDefaultConnection();
        if (!isset($this->pools[$name])) {
            throw new RuntimeException("Database connection [{$name}] is not configured.");
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
    private function makePool(array $cfg): PDOPool
    {
        $pdoConfig = (new PDOConfig())
            ->withDriver((string) ($cfg['driver'] ?? 'mysql'))
            ->withHost((string) ($cfg['host'] ?? '127.0.0.1'))
            ->withPort((int) ($cfg['port'] ?? 3306))
            ->withDbname((string) ($cfg['database'] ?? ''))
            ->withCharset((string) ($cfg['charset'] ?? 'utf8mb4'))
            ->withUsername((string) ($cfg['username'] ?? 'root'))
            ->withPassword((string) ($cfg['password'] ?? ''));

        $socket = trim((string) ($cfg['unix_socket'] ?? ''));
        if ($socket !== '') {
            $pdoConfig = $pdoConfig->withUnixSocket($socket);
        }

        $options = $cfg['options'] ?? [];
        if (is_array($options) && $options !== []) {
            /** @var array<int, mixed> $options */
            $pdoConfig = $pdoConfig->withOptions($options);
        }

        $poolCfg = $cfg['pool'] ?? [];
        $size = 16;
        if (is_array($poolCfg) && isset($poolCfg['size'])) {
            $size = max(1, (int) $poolCfg['size']);
        }

        return new PDOPool($pdoConfig, $size);
    }
}
