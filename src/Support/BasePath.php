<?php

declare(strict_types=1);

namespace Loongs\Support;

use Composer\InstalledVersions;
use Loongs\Http\Application;

/**
 * Resolves the application base path (the project that required loongs/framework).
 *
 * Priority:
 * 1. Booted Application ($GLOBALS['__loongs_app']) or an explicit set() pin
 * 2. Constant LOONGS_BASE_PATH (defined by server/start), then env LOONGS_BASE_PATH (advanced override)
 * 3. Composer root package install_path (path-repo or Packagist vendor layouts)
 * 4. getcwd()
 */
final class BasePath
{
    private static ?string $resolved = null;

    public static function get(string $path = ''): string
    {
        $base = self::resolve();
        if ($path === '') {
            return $base;
        }

        return $base . '/' . ltrim($path, '/\\');
    }

    public static function resolve(): string
    {
        if (self::$resolved !== null) {
            return self::$resolved;
        }

        return self::$resolved = self::detect();
    }

    /** Pin the base path (Application / ProcessManager boot). */
    public static function set(string $basePath): void
    {
        self::$resolved = rtrim($basePath, '/\\');
    }

    public static function forget(): void
    {
        self::$resolved = null;
    }

    private static function detect(): string
    {
        $app = $GLOBALS['__loongs_app'] ?? null;
        if ($app instanceof Application) {
            return rtrim($app->basePath(), '/\\');
        }

        if (defined('LOONGS_BASE_PATH')) {
            $constant = constant('LOONGS_BASE_PATH');
            if (is_string($constant) && $constant !== '') {
                return rtrim($constant, '/\\');
            }
        }

        $env = Env::get('LOONGS_BASE_PATH');
        if (is_string($env) && $env !== '') {
            return rtrim($env, '/\\');
        }

        if (class_exists(InstalledVersions::class)) {
            try {
                $root = InstalledVersions::getRootPackage();
                $install = $root['install_path'] ?? null;
                if (is_string($install) && $install !== '') {
                    $real = realpath($install);

                    return $real !== false ? $real : rtrim($install, '/\\');
                }
            } catch (\Throwable) {
                // fall through
            }
        }

        $cwd = getcwd();

        return $cwd !== false ? rtrim($cwd, '/\\') : '.';
    }
}
