<?php

declare(strict_types=1);

namespace Loongs\Process\Example;

use Loongs\Process\ProcessLog;
use Loongs\Process\ProcessInterface;
use Loongs\Redis\RedisManager;
use Swoole\Coroutine;
use function Swoole\Coroutine\run as co_run;

/**
 * Demo custom process: periodically writes a heartbeat key to Redis.
 */
final class ExampleCustomProcess implements ProcessInterface
{
    private bool $running = true;

    public function __construct(
        private readonly ?RedisManager $redis = null,
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

        $interval = max(1, (int) ($config['interval'] ?? 2));
        $key = (string) ($config['heartbeat_key'] ?? 'loong:process:custom:heartbeat');

        ProcessLog::info(sprintf('ExampleCustomProcess interval=%ds key=%s', $interval, $key));

        co_run(function () use ($interval, $key): void {
            while ($this->running) {
                $ts = (string) time();
                if ($this->redis instanceof RedisManager && $this->redis->isBooted()) {
                    try {
                        $this->redis->setex($key, $interval * 5, $ts);
                    } catch (\Throwable $e) {
                        ProcessLog::warn('custom redis error: ' . $e->getMessage());
                    }
                }
                Coroutine::sleep($interval);
            }
        });
    }
}
