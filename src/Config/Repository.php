<?php

declare(strict_types=1);

namespace Loongs\Config;

final class Repository
{
    /** @var array<string, mixed> */
    private array $items = [];

    /**
     * @param list<string> $excludeFiles Basenames (without .php) to skip, e.g. ['processes']
     */
    public function __construct(
        private readonly string $configPath,
        private readonly array $excludeFiles = [],
    ) {
        $this->load();
    }

    private function load(): void
    {
        if (!is_dir($this->configPath)) {
            return;
        }

        $files = glob($this->configPath . '/*.php') ?: [];
        foreach ($files as $file) {
            $key = basename($file, '.php');
            if ($this->excludeFiles !== [] && in_array($key, $this->excludeFiles, true)) {
                continue;
            }
            /** @var mixed $value */
            $value = require $file;
            $this->items[$key] = $value;
        }
    }

    public function get(string $key, mixed $default = null): mixed
    {
        $segments = explode('.', $key);
        $value = $this->items;

        foreach ($segments as $segment) {
            if (!is_array($value) || !array_key_exists($segment, $value)) {
                return $default;
            }
            $value = $value[$segment];
        }

        return $value;
    }

    public function set(string $key, mixed $value): void
    {
        $segments = explode('.', $key);
        $ref = &$this->items;

        foreach ($segments as $i => $segment) {
            if ($i === count($segments) - 1) {
                $ref[$segment] = $value;
                return;
            }
            if (!isset($ref[$segment]) || !is_array($ref[$segment])) {
                $ref[$segment] = [];
            }
            $ref = &$ref[$segment];
        }
    }

    public function has(string $key): bool
    {
        $segments = explode('.', $key);
        $value = $this->items;

        foreach ($segments as $segment) {
            if (!is_array($value) || !array_key_exists($segment, $value)) {
                return false;
            }
            $value = $value[$segment];
        }

        return true;
    }

    /** @return array<string, mixed> */
    public function all(): array
    {
        return $this->items;
    }
}
