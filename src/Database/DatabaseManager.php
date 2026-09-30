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
 * Health: put() only reusable connections. A connection that is lost / killed /
 * in an unknown state goes to discard(): it is dropped and the pool opens a
 * replacement (size stays constant). run() does this automatically: on an
 * exception it rolls back an open transaction and discards lost connections.
 * When every connection is busy, connection() waits pool.wait_timeout seconds
 * (-1 = forever) and then throws PoolExhaustedException.
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

    /**
     * Close every pool (must run inside a coroutine; WorkerPools::close() takes care of that).
     * Pools are detached first, so a re-entrant call (WorkerExit fires repeatedly while a close
     * coroutine is suspended) is a no-op instead of closing the same pool twice.
     */
    public function closePools(): void
    {
        $pools = $this->pools;
        $this->pools = [];
        $this->booted = false;
        foreach ($pools as $pool) {
            $pool->close();
        }
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
        $pdo = $pool->get($timeout);
        if (!is_object($pdo)) { // Channel::pop() timed out
            throw new PoolExhaustedException(sprintf(
                'Database pool [%s] exhausted: no connection freed within %ss (pool.size=%d).',
                $name ?? $this->getDefaultConnection(),
                $timeout,
                $this->stats($name)['size'],
            ));
        }
        /** @var PDO|PDOProxy $pdo */
        return $pdo;
    }

    /**
     * Drop a broken connection instead of returning it; the pool opens a replacement
     * (Swoole ConnectionPool::put(null): open count -1, then a new connection is made).
     * If the replacement cannot connect right now, the next connection() retries.
     */
    public function discard(?object $pdo = null, ?string $name = null): void
    {
        $pool = $this->pool($name);
        unset($pdo); // the caller's reference is the last one; the pool never sees it again
        try {
            $pool->put(null);
        } catch (\Throwable) {
            // replacement connect failed: open count is already decremented, get() will retry
        }
    }

    /**
     * @return array{size: int, open: int, idle: int, active: int}
     */
    public function stats(?string $name = null): array
    {
        $pool = $this->pool($name);
        try {
            $read = \Closure::bind(function (): array {
                /** @var \Swoole\ConnectionPool $this */
                return [
                    'size' => (int) $this->size,
                    'open' => (int) $this->num,
                    'idle' => $this->pool instanceof \Swoole\Coroutine\Channel ? $this->pool->length() : 0,
                ];
            }, $pool, \Swoole\ConnectionPool::class);
            $s = $read();
        } catch (\Throwable) {
            $s = ['size' => 0, 'open' => 0, 'idle' => 0];
        }
        $s['active'] = max(0, $s['open'] - $s['idle']);

        return $s;
    }

    public function put(object $pdo, ?string $name = null): void
    {
        $this->pool($name)->put($pdo);
    }

    /**
     * Borrow a connection, run $fn, always give it back (even on throw): an open
     * transaction is rolled back; a lost / broken connection is discarded.
     *
     * @template T
     * @param callable(PDO|PDOProxy): T $fn
     * @return T
     */
    public function run(callable $fn, ?string $name = null): mixed
    {
        $pdo = $this->connection($name);
        try {
            $result = $fn($pdo);
        } catch (\Throwable $e) {
            $this->giveBack($pdo, $name, self::isLostConnection($e));
            throw $e;
        }
        $this->giveBack($pdo, $name, false);

        return $result;
    }

    /** True when $e says the connection itself is unusable (gone away, killed, lost, out of sync). */
    public static function isLostConnection(\Throwable $e): bool
    {
        for ($x = $e; $x !== null; $x = $x->getPrevious()) {
            $code = $x instanceof \PDOException && is_array($x->errorInfo ?? null) ? (int) ($x->errorInfo[1] ?? 0) : 0;
            if (in_array($code, [1053, 1077, 1152, 1156, 1927, 2002, 2003, 2006, 2013, 2014, 2027, 2055, 4031], true)) {
                return true;
            }
            foreach (['server has gone away', 'Lost connection', 'Connection was killed', 'Broken pipe', 'Error while sending',
                'Packets out of order', 'Commands out of sync', 'disconnected by the server', 'Connection reset'] as $needle) {
                if (stripos($x->getMessage(), $needle) !== false) {
                    return true;
                }
            }
        }

        return false;
    }

    private function giveBack(object $pdo, ?string $name, bool $broken): void
    {
        if (!$broken) {
            try {
                if ($pdo->inTransaction()) {
                    $pdo->rollBack();
                }
            } catch (\Throwable) {
                $broken = true;
            }
        }
        $broken ? $this->discard($pdo, $name) : $this->put($pdo, $name);
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
