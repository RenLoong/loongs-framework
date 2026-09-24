<?php

declare(strict_types=1);

namespace Loongs\Process\Role;

use DateTimeImmutable;
use Loongs\Http\Application;
use Loongs\Process\Crontab\CronExpression;
use Loongs\Process\ProcessInterface;
use Loongs\Process\WorkerPools;
use Swoole\Coroutine;
use Swoole\Timer;
use Throwable;
use function Swoole\Coroutine\run as co_run;

/**
 * Tick every second; run matching task handlers in coroutines with overlap guard.
 */
final class CrontabProcess implements ProcessInterface
{
    private bool $running = true;

    /** @var array<string, bool> */
    private array $runningTasks = [];

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

        /** @var list<array<string, mixed>> $tasks */
        $tasks = is_array($config['tasks'] ?? null) ? $config['tasks'] : [];

        $compiled = [];
        foreach ($tasks as $i => $task) {
            if (!is_array($task)) {
                continue;
            }
            $rule = (string) ($task['rule'] ?? '');
            $handler = (string) ($task['handler'] ?? '');
            if ($rule === '' || $handler === '') {
                continue;
            }
            try {
                $expr = new CronExpression($rule);
            } catch (Throwable $e) {
                fwrite(STDERR, sprintf("[crontab] skip task #%d: %s\n", $i, $e->getMessage()));
                continue;
            }
            $compiled[] = [
                'id' => (string) ($task['name'] ?? ('task-' . $i)),
                'rule' => $rule,
                'handler' => $handler,
                'expr' => $expr,
                'overlap' => (bool) ($task['overlap'] ?? false),
            ];
        }

        echo sprintf(
            "[%s] Crontab process [%s] tasks=%d\n",
            date('Y-m-d H:i:s'),
            $name,
            count($compiled),
        );

        co_run(function () use ($compiled, $container): void {
            $lastSecond = -1;
            Timer::tick(200, function () use (&$lastSecond, $compiled, $container) {
                if (!$this->running) {
                    Timer::clearAll();

                    return;
                }

                $now = new DateTimeImmutable('now');
                $sec = (int) $now->format('s') + ((int) $now->format('i') * 60) + ((int) $now->format('G') * 3600);
                // Deduplicate within the same wall-clock second.
                $bucket = (int) $now->format('YmdHis');
                if ($bucket === $lastSecond) {
                    return;
                }
                $lastSecond = $bucket;

                foreach ($compiled as $task) {
                    /** @var CronExpression $expr */
                    $expr = $task['expr'];
                    if (!$expr->isDue($now)) {
                        continue;
                    }
                    $id = $task['id'];
                    if (!$task['overlap'] && ($this->runningTasks[$id] ?? false)) {
                        continue;
                    }
                    $this->runningTasks[$id] = true;
                    Coroutine::create(function () use ($task, $container, $id): void {
                        try {
                            $handler = (string) $task['handler'];
                            if (str_contains($handler, '@')) {
                                [$class, $method] = explode('@', $handler, 2);
                            } elseif (str_contains($handler, '::')) {
                                [$class, $method] = explode('::', $handler, 2);
                            } else {
                                $class = $handler;
                                $method = 'handle';
                            }
                            if (!class_exists($class)) {
                                throw new \RuntimeException("Crontab handler class [{$class}] not found");
                            }
                            $object = $container->has($class) ? $container->make($class) : new $class();
                            if (!is_callable([$object, $method])) {
                                throw new \RuntimeException("Crontab handler [{$class}::{$method}] is not callable");
                            }
                            $object->{$method}();
                        } catch (Throwable $e) {
                            fwrite(STDERR, sprintf("[crontab] %s error: %s\n", $id, $e->getMessage()));
                        } finally {
                            unset($this->runningTasks[$id]);
                        }
                    });
                }
            });

            // Keep the coroutine event loop alive until SIGTERM.
            while ($this->running) {
                Coroutine::sleep(0.5);
            }
            Timer::clearAll();
        });

        WorkerPools::close($container);
    }
}
