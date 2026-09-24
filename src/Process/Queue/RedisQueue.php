<?php

declare(strict_types=1);

namespace Loongs\Process\Queue;

use Loongs\Redis\RedisManager;
use Redis;
use RuntimeException;
use Throwable;

/**
 * Simple Redis list queue with delayed ZSET and failed list.
 *
 * Keys (per queue name):
 *   loong:queue:{name}           LIST  ready jobs
 *   loong:queue:{name}:delayed   ZSET  score = run_at (unix)
 *   loong:queue:{name}:failed    LIST  failed payloads
 */
final class RedisQueue
{
    public function __construct(
        private readonly RedisManager $redis,
        private readonly string $connection = 'default',
        private readonly string $prefix = 'loong:queue:',
    ) {
    }

    /**
     * @param class-string<JobInterface> $job
     * @param array<string, mixed> $data
     */
    public function push(string $job, array $data = [], string $queue = 'default', int $delaySeconds = 0, int $maxAttempts = 3): string
    {
        $id = bin2hex(random_bytes(8));
        $payload = [
            'id' => $id,
            'job' => $job,
            'data' => $data,
            'attempts' => 0,
            'max_attempts' => max(1, $maxAttempts),
            'queue' => $queue,
            'pushed_at' => time(),
        ];

        $json = json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        if ($json === false) {
            throw new RuntimeException('Failed to encode queue job.');
        }

        if ($delaySeconds > 0) {
            $score = time() + $delaySeconds;
            $this->redis->run(function (Redis $r) use ($queue, $json, $score): void {
                $r->zAdd($this->delayedKey($queue), $score, $json);
            }, $this->connection);
        } else {
            $this->redis->run(function (Redis $r) use ($queue, $json): void {
                $r->lPush($this->readyKey($queue), $json);
            }, $this->connection);
        }

        return $id;
    }

    /**
     * Move due delayed jobs into the ready list, then BRPOP one job.
     *
     * @return array<string, mixed>|null
     */
    public function pop(string $queue, int $timeoutSeconds = 1): ?array
    {
        $this->promoteDelayed($queue);

        /** @var array{0:string,1:string}|false|null $result */
        $result = $this->redis->run(function (Redis $r) use ($queue, $timeoutSeconds) {
            return $r->brPop([$this->readyKey($queue)], max(1, $timeoutSeconds));
        }, $this->connection);

        if (!is_array($result) || count($result) < 2) {
            return null;
        }

        $raw = (string) $result[1];
        /** @var mixed $decoded */
        $decoded = json_decode($raw, true);
        if (!is_array($decoded) || !isset($decoded['job'])) {
            return null;
        }

        /** @var array<string, mixed> $decoded */
        $decoded['_raw'] = $raw;

        return $decoded;
    }

    /**
     * @param array<string, mixed> $payload
     */
    public function ackFailed(array $payload, string $queue, string $error): void
    {
        $payload['failed_at'] = time();
        $payload['error'] = $error;
        unset($payload['_raw']);
        $json = json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        if ($json === false) {
            return;
        }
        $this->redis->run(function (Redis $r) use ($queue, $json): void {
            $r->lPush($this->failedKey($queue), $json);
        }, $this->connection);
    }

    /**
     * @param array<string, mixed> $payload
     */
    public function release(array $payload, string $queue, int $delaySeconds = 1): void
    {
        unset($payload['_raw']);
        $json = json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        if ($json === false) {
            return;
        }
        if ($delaySeconds > 0) {
            $score = time() + $delaySeconds;
            $this->redis->run(function (Redis $r) use ($queue, $json, $score): void {
                $r->zAdd($this->delayedKey($queue), $score, $json);
            }, $this->connection);
        } else {
            $this->redis->run(function (Redis $r) use ($queue, $json): void {
                $r->lPush($this->readyKey($queue), $json);
            }, $this->connection);
        }
    }

    public function promoteDelayed(string $queue): void
    {
        $now = time();
        $this->redis->run(function (Redis $r) use ($queue, $now): void {
            $key = $this->delayedKey($queue);
            // zRangeByScore returns values with scores up to $now
            $items = $r->zRangeByScore($key, '-inf', (string) $now);
            if (!is_array($items) || $items === []) {
                return;
            }
            foreach ($items as $item) {
                if (!is_string($item)) {
                    continue;
                }
                $removed = $r->zRem($key, $item);
                if ($removed) {
                    $r->lPush($this->readyKey($queue), $item);
                }
            }
        }, $this->connection);
    }

    private function readyKey(string $queue): string
    {
        return $this->prefix . $queue;
    }

    private function delayedKey(string $queue): string
    {
        return $this->prefix . $queue . ':delayed';
    }

    private function failedKey(string $queue): string
    {
        return $this->prefix . $queue . ':failed';
    }
}
