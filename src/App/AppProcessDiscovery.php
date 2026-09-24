<?php

declare(strict_types=1);

namespace Loongs\App;

use Loongs\Process\ProcessType;
use RuntimeException;

/**
 * Discovers apps/{Name}/config/processes.php and returns a prefixed process map.
 *
 * Key "queue" in apps/User/config/processes.php becomes "user.queue".
 * Process title uses the prefixed name: loong-swoole: user.queue.
 *
 * Custom class / crontab handlers must live under App\{Name}\ (fail fast).
 * Injects '_app' => directory name into each entry for role filtering.
 */
final class AppProcessDiscovery
{
    /** Server-like types that bind host:port */
    private const SERVER_TYPES = ['http', 'rpc', 'websocket'];

    public function __construct(
        private readonly string $appsPath,
    ) {
    }

    /**
     * @return array<string, array<string, mixed>> Prefixed process name => config
     */
    public function discover(): array
    {
        $out = [];

        foreach ($this->listApps() as $appDir) {
            $file = $this->appsPath
                . DIRECTORY_SEPARATOR . $appDir
                . DIRECTORY_SEPARATOR . 'config'
                . DIRECTORY_SEPARATOR . 'processes.php';

            if (!is_file($file)) {
                continue;
            }

            /** @var mixed $raw */
            $raw = require $file;
            if (!is_array($raw)) {
                throw new RuntimeException(
                    "App process config must return an array: {$file}",
                );
            }

            $prefix = strtolower($appDir);
            $nsPrefix = 'App\\' . $appDir . '\\';

            foreach ($raw as $key => $cfg) {
                if (!is_string($key) || $key === '' || !is_array($cfg)) {
                    throw new RuntimeException(
                        "Invalid process entry in {$file}: keys must be non-empty strings mapping to arrays.",
                    );
                }
                if (str_contains($key, '.')) {
                    throw new RuntimeException(
                        "App process key [{$key}] in {$file} must not contain '.'; prefix is added automatically.",
                    );
                }

                $prefixed = $prefix . '.' . $key;
                if (isset($out[$prefixed])) {
                    throw new RuntimeException(
                        "Duplicate app process name [{$prefixed}] discovered.",
                    );
                }

                /** @var array<string, mixed> $cfg */
                $cfg['_app'] = $appDir;
                $cfg['_app_slug'] = $prefix;

                $type = strtolower((string) ($cfg['type'] ?? $key));
                $this->validateHandlers($prefixed, $type, $cfg, $nsPrefix, $file);

                // App-owned server processes serve only that app when roles support filtering.
                if (in_array($type, self::SERVER_TYPES, true) && !isset($cfg['app'])) {
                    $cfg['app'] = $appDir;
                }

                $out[$prefixed] = $cfg;
            }
        }

        return $out;
    }

    /**
     * Merge global + app processes; fail on duplicate names or host:port conflicts.
     *
     * @param array<string, array<string, mixed>> $global
     * @param array<string, array<string, mixed>> $appProcesses
     * @return array<string, array<string, mixed>>
     */
    public static function merge(array $global, array $appProcesses): array
    {
        $merged = $global;

        foreach ($appProcesses as $name => $cfg) {
            if (isset($merged[$name])) {
                throw new RuntimeException(
                    "Duplicate process name [{$name}]: conflicts with global or another app process.",
                );
            }
            $merged[$name] = $cfg;
        }

        self::assertNoPortConflicts($merged);

        return $merged;
    }

    /**
     * @param array<string, array<string, mixed>> $processes
     */
    public static function assertNoPortConflicts(array $processes): void
    {
        /** @var array<string, string> $bound address => process name */
        $bound = [];

        foreach ($processes as $name => $cfg) {
            $enabled = $cfg['enabled'] ?? true;
            if (is_string($enabled)) {
                $enabled = filter_var($enabled, FILTER_VALIDATE_BOOLEAN);
            }
            if (!$enabled) {
                continue;
            }

            $type = strtolower((string) ($cfg['type'] ?? $name));
            if (!in_array($type, self::SERVER_TYPES, true)) {
                continue;
            }

            $host = (string) ($cfg['host'] ?? '0.0.0.0');
            $port = (int) ($cfg['port'] ?? 0);
            if ($port <= 0) {
                continue;
            }

            $key = self::normalizeListenKey($host, $port);
            if (isset($bound[$key])) {
                throw new RuntimeException(sprintf(
                    "Port conflict: process [%s] and [%s] both bind %s:%d (enabled server-type).",
                    $bound[$key],
                    $name,
                    $host,
                    $port,
                ));
            }
            $bound[$key] = $name;

            // 0.0.0.0 conflicts with any specific host on same port, and vice versa.
            if ($host === '0.0.0.0' || $host === '*') {
                foreach ($bound as $otherKey => $otherName) {
                    if ($otherName === $name) {
                        continue;
                    }
                    if (str_ends_with($otherKey, ':' . $port)) {
                        throw new RuntimeException(sprintf(
                            "Port conflict: process [%s] (%s) and [%s] (%s) overlap on port %d.",
                            $otherName,
                            $otherKey,
                            $name,
                            $key,
                            $port,
                        ));
                    }
                }
            } else {
                $any = self::normalizeListenKey('0.0.0.0', $port);
                if (isset($bound[$any]) && $bound[$any] !== $name) {
                    throw new RuntimeException(sprintf(
                        "Port conflict: process [%s] (0.0.0.0:%d) and [%s] (%s:%d) overlap.",
                        $bound[$any],
                        $port,
                        $name,
                        $host,
                        $port,
                    ));
                }
            }
        }
    }

    private static function normalizeListenKey(string $host, int $port): string
    {
        $host = strtolower(trim($host));
        if ($host === '*' || $host === '') {
            $host = '0.0.0.0';
        }

        return $host . ':' . $port;
    }

    /**
     * @param array<string, mixed> $cfg
     */
    private function validateHandlers(
        string $prefixed,
        string $type,
        array $cfg,
        string $nsPrefix,
        string $file,
    ): void {
        $processType = ProcessType::tryFromConfig($type);
        if ($processType === null) {
            throw new RuntimeException(
                "Unknown process type [{$type}] for [{$prefixed}] in {$file}.",
            );
        }

        if ($processType === ProcessType::Custom) {
            $class = (string) ($cfg['class'] ?? '');
            if ($class === '') {
                throw new RuntimeException(
                    "Custom process [{$prefixed}] in {$file} requires 'class'.",
                );
            }
            if (!str_starts_with($class, $nsPrefix)) {
                throw new RuntimeException(
                    "Custom process [{$prefixed}] class [{$class}] must be under namespace [{$nsPrefix}].",
                );
            }
        }

        if ($processType === ProcessType::Crontab) {
            $tasks = $cfg['tasks'] ?? [];
            if (!is_array($tasks)) {
                return;
            }
            foreach ($tasks as $i => $task) {
                if (!is_array($task)) {
                    continue;
                }
                $handler = (string) ($task['handler'] ?? $task['class'] ?? '');
                if ($handler === '') {
                    continue;
                }
                if (!str_starts_with($handler, $nsPrefix)) {
                    throw new RuntimeException(
                        "Crontab task [{$prefixed}#{$i}] handler [{$handler}] must be under namespace [{$nsPrefix}].",
                    );
                }
            }
        }

        if ($processType === ProcessType::Queue) {
            // Optional job class hints in config — validate if present.
            foreach (['job', 'jobs', 'handlers'] as $field) {
                if (!isset($cfg[$field])) {
                    continue;
                }
                $values = $cfg[$field];
                $list = is_array($values) ? $values : [$values];
                foreach ($list as $jobClass) {
                    if (!is_string($jobClass) || $jobClass === '') {
                        continue;
                    }
                    if (!str_starts_with($jobClass, $nsPrefix)) {
                        throw new RuntimeException(
                            "Queue process [{$prefixed}] job [{$jobClass}] must be under namespace [{$nsPrefix}].",
                        );
                    }
                }
            }
        }
    }

    /**
     * @return list<string>
     */
    private function listApps(): array
    {
        if (!is_dir($this->appsPath)) {
            return [];
        }

        $names = [];
        $entries = scandir($this->appsPath);
        if ($entries === false) {
            return [];
        }

        foreach ($entries as $name) {
            if ($name === '.' || $name === '..' || str_starts_with($name, '.')) {
                continue;
            }
            $full = $this->appsPath . DIRECTORY_SEPARATOR . $name;
            if (is_dir($full)) {
                $names[] = $name;
            }
        }

        sort($names, SORT_STRING);

        return $names;
    }
}
