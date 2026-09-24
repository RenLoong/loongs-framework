<?php

declare(strict_types=1);

use Loongs\Database\DatabaseManager;
use Loongs\Http\Application;
use Loongs\Process\Queue\JobInterface;
use Loongs\Process\Queue\QueueManager;
use Loongs\Redis\RedisManager;
use Loongs\Support\BasePath;
use Loongs\Support\Env;

if (!function_exists('env')) {
    function env(string $key, mixed $default = null): mixed
    {
        return Env::get($key, $default);
    }
}

if (!function_exists('db')) {
    /**
     * Database pool manager (process-worker scoped). Prefer db()->run(...).
     * Never create bare PDO in apps — use the pool.
     */
    function db(): DatabaseManager
    {
        /** @var Application|null $application */
        $application = $GLOBALS['__loongs_app'] ?? null;
        if (!$application instanceof Application) {
            throw new \RuntimeException('Application is not booted; cannot resolve db().');
        }

        /** @var DatabaseManager $manager */
        $manager = $application->container()->make(DatabaseManager::class);

        return $manager;
    }
}

if (!function_exists('redis')) {
    /**
     * Redis pool manager (process-worker scoped). Prefer redis()->run(...).
     * Never create bare Redis clients in apps — use the pool.
     */
    function redis(): RedisManager
    {
        /** @var Application|null $application */
        $application = $GLOBALS['__loongs_app'] ?? null;
        if (!$application instanceof Application) {
            throw new \RuntimeException('Application is not booted; cannot resolve redis().');
        }

        /** @var RedisManager $manager */
        $manager = $application->container()->make(RedisManager::class);

        return $manager;
    }
}

if (!function_exists('queue')) {
    /**
     * Queue manager, or push a job when $job is a class-string.
     *
     * queue() → QueueManager
     * queue(MyJob::class, ['x' => 1], 'default') → job id
     *
     * @param class-string<JobInterface>|null $job
     * @param array<string, mixed> $data
     */
    function queue(?string $job = null, array $data = [], string $name = 'default', int $delaySeconds = 0): QueueManager|string
    {
        /** @var Application|null $application */
        $application = $GLOBALS['__loongs_app'] ?? null;
        if (!$application instanceof Application) {
            throw new \RuntimeException('Application is not booted; cannot resolve queue().');
        }

        /** @var QueueManager $manager */
        $manager = $application->container()->make(QueueManager::class);

        if ($job === null) {
            return $manager;
        }

        return $manager->push($job, $data, $name, $delaySeconds);
    }
}



if (!function_exists('base_path')) {
    /**
     * Application base path (the project that required loongs/framework), or a path under it.
     *
     * @see \Loongs\Support\BasePath
     */
    function base_path(string $path = ''): string
    {
        return BasePath::get($path);
    }
}

if (!function_exists('app_config')) {
    /**
     * Per-app config accessor (does not pollute global config()).
     *
     * Filename becomes the top-level key (same as server/config/*.php):
     *   apps/User/config/user.php → app_config('user', 'user.cache.ttl', 300)
     *
     * App name is case-insensitive against apps/<Name> directory.
     */
    function app_config(string $app, string $key, mixed $default = null): mixed
    {
        /** @var \Loongs\Http\Application|null $application */
        $application = $GLOBALS['__loongs_app'] ?? null;
        if ($application instanceof \Loongs\Http\Application) {
            /** @var \Loongs\App\AppConfig $appConfig */
            $appConfig = $application->container()->make(\Loongs\App\AppConfig::class);

            return $appConfig->get($app, $key, $default);
        }

        // Fallback when Application is not booted (CLI / ProcessManager checks).
        $appsPath = BasePath::get('apps');
        if (!is_dir($appsPath)) {
            throw new \RuntimeException(
                'Cannot resolve apps path for app_config(); boot Application first or set LOONGS_BASE_PATH.'
            );
        }

        return (new \Loongs\App\AppConfig($appsPath))->get($app, $key, $default);
    }
}
