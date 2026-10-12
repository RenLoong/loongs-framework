<?php

declare(strict_types=1);

namespace Loongs\App;

/**
 * Discovers RPC client service entries from apps/<Name> and merges with root config/rpc.php.
 *
 * Discovery order per app directory (dot dirs skipped):
 *   1. apps/<Name>/config/rpc.php — if present, require it:
 *      - returns ["services" => [...]] → use that map
 *      - else a map of serviceName => config arrays → treat whole return as services
 *   2. else apps/<Name>/rpc.php (handler registration) → auto-contribute
 *      service key = strtolower(<Name>) with transport=local, timeout_ms=3000
 *
 * Merge: array_replace($appServices, $rootServices) — root config/rpc.php wins on conflict.
 * Used by Application::bootRpc and RpcServiceReloader::configServices (hot-reload / rpc:show).
 */
final class AppRpcServices
{
    public const DEFAULT_TIMEOUT_MS = 3000;

    /**
     * @return array<string, array<string, mixed>>
     */
    public static function discover(string $appsPath): array
    {
        $out = [];

        foreach (self::listApps($appsPath) as $appDir) {
            $appRoot = $appsPath . DIRECTORY_SEPARATOR . $appDir;
            $configFile = $appRoot . DIRECTORY_SEPARATOR . 'config' . DIRECTORY_SEPARATOR . 'rpc.php';
            $handlersFile = $appRoot . DIRECTORY_SEPARATOR . 'rpc.php';

            if (is_file($configFile)) {
                foreach (self::loadConfigFile($configFile) as $name => $cfg) {
                    if (!is_string($name) || $name === '' || !is_array($cfg)) {
                        continue;
                    }
                    $out[$name] = $cfg;
                }
                continue;
            }

            if (is_file($handlersFile)) {
                $key = strtolower($appDir);
                $out[$key] = [
                    'transport' => 'local',
                    'timeout_ms' => self::DEFAULT_TIMEOUT_MS,
                    'metadata' => [
                        'app' => $appDir,
                        'source' => 'app-rpc.php',
                    ],
                ];
            }
        }

        return $out;
    }

    /**
     * App services first; root wins on the same key.
     *
     * @param array<array-key, mixed> $appServices
     * @param array<array-key, mixed> $rootServices
     * @return array<array-key, mixed>
     */
    public static function merge(array $appServices, array $rootServices): array
    {
        return array_replace($appServices, $rootServices);
    }

    /**
     * Fingerprint of apps/<Name>/rpc.php and apps/<Name>/config/rpc.php (path+mtime+size).
     * Adding/changing an app file invalidates RpcServiceReloader without editing root rpc.php.
     */
    public static function fingerprint(string $appsPath): string
    {
        if (!is_dir($appsPath)) {
            return 'apps:none';
        }

        $parts = [];
        foreach (self::listApps($appsPath) as $appDir) {
            $appRoot = $appsPath . DIRECTORY_SEPARATOR . $appDir;
            foreach ([
                $appRoot . DIRECTORY_SEPARATOR . 'config' . DIRECTORY_SEPARATOR . 'rpc.php',
                $appRoot . DIRECTORY_SEPARATOR . 'rpc.php',
            ] as $file) {
                clearstatcache(true, $file);
                if (!is_file($file)) {
                    continue;
                }
                $mtime = (string) (@filemtime($file) ?: 0);
                $size = (string) (@filesize($file) ?: 0);
                $parts[] = $file . '=' . $mtime . ':' . $size;
            }
        }

        if ($parts === []) {
            return 'apps:empty';
        }

        return hash('sha256', implode("\n", $parts));
    }

    /**
     * @return array<string, array<string, mixed>>
     */
    private static function loadConfigFile(string $file): array
    {
        if (function_exists('opcache_invalidate')) {
            @opcache_invalidate($file, true);
        }

        /** @var mixed $value */
        $value = (static function () use ($file): mixed {
            return require $file;
        })();

        if (!is_array($value)) {
            return [];
        }

        if (isset($value['services']) && is_array($value['services'])) {
            /** @var array<string, array<string, mixed>> $services */
            $services = [];
            foreach ($value['services'] as $name => $cfg) {
                if (is_string($name) && $name !== '' && is_array($cfg)) {
                    $services[$name] = $cfg;
                }
            }

            return $services;
        }

        // Whole return is a service map (serviceName => config).
        $services = [];
        foreach ($value as $name => $cfg) {
            if (!is_string($name) || $name === '' || !is_array($cfg)) {
                continue;
            }
            // Skip non-service top-level keys that look like rpc.php root options.
            if (in_array($name, ['path', 'default_timeout_ms', 'register_demo_handlers', 'node', 'hot_reload', 'retry', 'iouring'], true)) {
                continue;
            }
            $services[$name] = $cfg;
        }

        return $services;
    }

    /**
     * @return list<string>
     */
    private static function listApps(string $appsPath): array
    {
        if (!is_dir($appsPath)) {
            return [];
        }

        $entries = scandir($appsPath);
        if ($entries === false) {
            return [];
        }

        $names = [];
        foreach ($entries as $name) {
            if ($name === '.' || $name === '..' || str_starts_with($name, '.')) {
                continue;
            }
            $full = $appsPath . DIRECTORY_SEPARATOR . $name;
            if (is_dir($full)) {
                $names[] = $name;
            }
        }

        sort($names, SORT_STRING);

        return $names;
    }
}
