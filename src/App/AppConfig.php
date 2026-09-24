<?php

declare(strict_types=1);

namespace Loongs\App;

use Loongs\Config\Repository;
use RuntimeException;

/**
 * Per-app config loader for apps/<Name>/config/*.php.
 *
 * Does NOT merge into the global Config\Repository — keeps app settings isolated.
 * Special file processes.php is excluded (see AppProcessDiscovery).
 * Module-level config/ is not scanned (optional later; not required).
 *
 * Access:
 *   app_config('user', 'user.cache.ttl', 300)
 *   app_config('User', 'user.cache.ttl')   // case-insensitive app dir
 *
 * Filename becomes the top-level key (same convention as server/config/*.php).
 */
final class AppConfig
{
    /** @var array<string, Repository> keyed by resolved directory name */
    private array $repositories = [];

    /** @var array<string, string> lower(app) => directory name */
    private array $dirByLower = [];

    public function __construct(
        private readonly string $appsPath,
    ) {
        $this->indexApps();
    }

    public function appsPath(): string
    {
        return $this->appsPath;
    }

    /**
     * @param string $app App directory name or case-insensitive slug (User / user)
     */
    public function get(string $app, string $key, mixed $default = null): mixed
    {
        return $this->repository($app)->get($key, $default);
    }

    public function has(string $app, string $key): bool
    {
        return $this->repository($app)->has($key);
    }

    public function repository(string $app): Repository
    {
        $dir = $this->resolveAppDir($app);
        if (!isset($this->repositories[$dir])) {
            $path = $this->appsPath . DIRECTORY_SEPARATOR . $dir . DIRECTORY_SEPARATOR . 'config';
            $this->repositories[$dir] = new Repository($path, ['processes']);
        }

        return $this->repositories[$dir];
    }

    /**
     * Resolve User / user / USER → actual directory name (e.g. User).
     */
    public function resolveAppDir(string $app): string
    {
        $app = trim($app);
        if ($app === '') {
            throw new RuntimeException('App name must not be empty.');
        }

        if (is_dir($this->appsPath . DIRECTORY_SEPARATOR . $app)) {
            return $app;
        }

        $lower = strtolower($app);
        if (isset($this->dirByLower[$lower])) {
            return $this->dirByLower[$lower];
        }

        throw new RuntimeException("Unknown app [{$app}] under {$this->appsPath}.");
    }

    private function indexApps(): void
    {
        if (!is_dir($this->appsPath)) {
            return;
        }

        $entries = scandir($this->appsPath);
        if ($entries === false) {
            return;
        }

        foreach ($entries as $name) {
            if ($name === '.' || $name === '..' || str_starts_with($name, '.')) {
                continue;
            }
            $full = $this->appsPath . DIRECTORY_SEPARATOR . $name;
            if (!is_dir($full)) {
                continue;
            }
            $this->dirByLower[strtolower($name)] = $name;
        }
    }
}
