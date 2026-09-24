<?php

declare(strict_types=1);

namespace Loongs\Process\Role;

use Loongs\Http\Application;
use Loongs\Process\ProcessInterface;
use Loongs\Process\Queue\JobInterface;
use Loongs\Process\Queue\QueueManager;
use Loongs\Process\Queue\RedisQueue;
use Loongs\Process\WorkerPools;
use Loongs\Redis\RedisManager;
use Swoole\Coroutine;
use Throwable;
use function Swoole\Coroutine\run as co_run;

/**
 * Redis list consumer loop (BRPOP) with retry + delayed + failed list.
 */
final class QueueProcess implements ProcessInterface
{
    private bool $running = true;

    public function __construct(
        private readonly string $basePath,
    ) {
    }

    public function handle(string $name, array $config): void
    {
        \Swoole\Process::signal(SIGTERM, function (): void {
            $this->running = false;
        });
        \Swoole\Process::signal(SIGINT, function (): void {
            $this->running = false;
        });

        WorkerPools::enableCoroutineHooks();

        $app = new Application($this->basePath);
        $container = $app->container();
        WorkerPools::boot($container);

        /** @var RedisManager $redis */
        $redis = $container->make(RedisManager::class);
        $connection = (string) ($config['connection'] ?? 'default');
        $prefix = (string) ($config['prefix'] ?? 'loong:queue:');
        /** @var list<string> $queues */
        $queues = is_array($config['queues'] ?? null) ? array_values(array_filter($config['queues'], 'is_string')) : ['default'];
        if ($queues === []) {
            $queues = ['default'];
        }
        $timeout = max(1, (int) ($config['timeout'] ?? 1));

        $queue = new RedisQueue($redis, $connection !== '' ? $connection : 'default', $prefix !== '' ? $prefix : 'loong:queue:');

        // Register QueueManager for helpers / dispatch from other processes.
        $container->instance(QueueManager::class, new QueueManager($redis, $app->config()));
        $container->instance(RedisQueue::class, $queue);

        echo sprintf(
            "[%s] Queue process [%s] queues=%s connection=%s\n",
            date('Y-m-d H:i:s'),
            $name,
            implode(',', $queues),
            $connection,
        );

        co_run(function () use ($queue, $queues, $timeout, $container): void {
            $i = 0;
            while ($this->running) {
                $q = $queues[$i % count($queues)];
                $i++;
                try {
                    $payload = $queue->pop($q, $timeout);
                } catch (Throwable $e) {
                    fwrite(STDERR, sprintf("[queue] pop error: %s\n", $e->getMessage()));
                    Coroutine::sleep(0.5);
                    continue;
                }

                if ($payload === null) {
                    continue;
                }

                $this->processJob($queue, $payload, $q, $container);
            }
        });

        WorkerPools::close($container);
    }

    /**
     * @param array<string, mixed> $payload
     */
    private function processJob(RedisQueue $queue, array $payload, string $queueName, \Loongs\Container\Container $container): void
    {
        $jobClass = (string) ($payload['job'] ?? '');
        $data = is_array($payload['data'] ?? null) ? $payload['data'] : [];
        $attempts = (int) ($payload['attempts'] ?? 0) + 1;
        $maxAttempts = (int) ($payload['max_attempts'] ?? 3);
        $payload['attempts'] = $attempts;

        if ($jobClass === '' || !class_exists($jobClass)) {
            $queue->ackFailed($payload, $queueName, "Job class [{$jobClass}] not found");

            return;
        }

        try {
            /** @var JobInterface $job */
            $job = $container->has($jobClass) ? $container->make($jobClass) : new $jobClass();
            if (!$job instanceof JobInterface) {
                throw new \RuntimeException("{$jobClass} must implement JobInterface");
            }
            $job->handle($data);
        } catch (Throwable $e) {
            if ($attempts >= $maxAttempts) {
                $queue->ackFailed($payload, $queueName, $e->getMessage());
                fwrite(STDERR, sprintf("[queue] job %s failed permanently: %s\n", $payload['id'] ?? '?', $e->getMessage()));
            } else {
                $queue->release($payload, $queueName, min(30, $attempts));
                fwrite(STDERR, sprintf("[queue] job %s retry %d: %s\n", $payload['id'] ?? '?', $attempts, $e->getMessage()));
            }
        }
    }
}
