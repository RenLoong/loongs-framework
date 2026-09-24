<?php

declare(strict_types=1);

namespace Loongs\Process\Queue;

use Loongs\Config\Repository;
use Loongs\Redis\RedisManager;

final class QueueManager
{
    private RedisQueue $queue;

    public function __construct(RedisManager $redis, Repository $config)
    {
        /** @var array<string, mixed> $process */
        $process = $config->get('process.processes.queue', []);
        if (!is_array($process)) {
            $process = [];
        }
        $connection = (string) ($process['connection'] ?? 'default');
        $prefix = (string) ($process['prefix'] ?? 'loong:queue:');
        $this->queue = new RedisQueue($redis, $connection !== '' ? $connection : 'default', $prefix !== '' ? $prefix : 'loong:queue:');
    }

    public function connection(): RedisQueue
    {
        return $this->queue;
    }

    /**
     * @param class-string<JobInterface> $job
     * @param array<string, mixed> $data
     */
    public function push(string $job, array $data = [], string $queue = 'default', int $delaySeconds = 0, int $maxAttempts = 3): string
    {
        return $this->queue->push($job, $data, $queue, $delaySeconds, $maxAttempts);
    }

    /**
     * @param class-string<JobInterface> $job
     * @param array<string, mixed> $data
     */
    public function later(int $delaySeconds, string $job, array $data = [], string $queue = 'default'): string
    {
        return $this->push($job, $data, $queue, $delaySeconds);
    }
}
