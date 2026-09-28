<?php

declare(strict_types=1);

namespace Loongs\Rpc\HotReload;

use Loongs\Rpc\Exception\RpcException;
use RuntimeException;

/**
 * Runtime override file for rpc.services (default: runtime/rpc_services.json).
 *
 * Shape:
 *   {"version":1,"updated_at":"2026-09-28T10:00:00+08:00","services":{"user":{...}}}
 *
 * Each entry REPLACES the whole service config from config/rpc.php (no deep merge),
 * so what you write is exactly what workers use. Writes are atomic (temp file in the
 * same directory + rename), so readers never see a half-written file.
 */
final class RpcServiceOverrides
{
    public function __construct(
        private readonly string $path,
    ) {
    }

    public function path(): string
    {
        return $this->path;
    }

    public function exists(): bool
    {
        clearstatcache(true, $this->path);

        return is_file($this->path);
    }

    /**
     * Content fingerprint used by the reloader ("missing" when absent).
     */
    public function fingerprint(): string
    {
        $raw = $this->readRaw();

        return $raw === null ? 'missing' : hash('sha256', $raw);
    }

    /**
     * Parsed service overrides (empty when the file does not exist).
     *
     * @return array<string, array<string, mixed>>
     * @throws RpcException when the file is unreadable / not valid override JSON
     */
    public function services(): array
    {
        $raw = $this->readRaw();
        if ($raw === null || trim($raw) === '') {
            return [];
        }

        try {
            /** @var mixed $decoded */
            $decoded = json_decode($raw, true, 64, JSON_THROW_ON_ERROR);
        } catch (\JsonException $e) {
            throw RpcException::badRequest("Invalid RPC override file {$this->path}: {$e->getMessage()}");
        }

        if (!is_array($decoded) || (array_is_list($decoded) && $decoded !== [])) {
            throw RpcException::badRequest("Invalid RPC override file {$this->path}: root must be a JSON object.");
        }

        $services = $decoded['services'] ?? [];
        if (!is_array($services) || (array_is_list($services) && $services !== [])) {
            throw RpcException::badRequest("Invalid RPC override file {$this->path}: \"services\" must be an object.");
        }

        $out = [];
        foreach ($services as $name => $config) {
            if (!is_string($name) || $name === '') {
                throw RpcException::badRequest("Invalid RPC override file {$this->path}: service names must be non-empty strings.");
            }
            if (!is_array($config) || (array_is_list($config) && $config !== [])) {
                throw RpcException::badRequest("Invalid RPC override file {$this->path}: service [{$name}] must be an object.");
            }
            /** @var array<string, mixed> $config */
            $out[$name] = $config;
        }

        return $out;
    }

    /**
     * @param array<string, mixed> $config
     */
    public function set(string $service, array $config): void
    {
        $services = $this->services();
        $services[$service] = $config;
        $this->write($services);
    }

    /**
     * @return bool true when an override existed and was removed
     */
    public function reset(string $service): bool
    {
        $services = $this->services();
        if (!array_key_exists($service, $services)) {
            return false;
        }
        unset($services[$service]);
        $this->write($services);

        return true;
    }

    /**
     * Drop every override (works even when the current file is invalid).
     */
    public function resetAll(): void
    {
        $this->write([]);
    }

    /**
     * Atomic write: temp file in the same directory, fsync-ish flush, rename().
     *
     * @param array<string, array<string, mixed>> $services
     */
    public function write(array $services): void
    {
        $dir = dirname($this->path);
        if (!is_dir($dir) && !@mkdir($dir, 0775, true) && !is_dir($dir)) {
            throw new RuntimeException("Cannot create directory {$dir}");
        }

        ksort($services);
        $payload = json_encode(
            [
                'version' => 1,
                'updated_at' => date(DATE_ATOM),
                'services' => $services === [] ? new \stdClass() : $services,
            ],
            JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR,
        ) . "\n";

        $tmp = sprintf('%s/.%s.%d.%s.tmp', $dir, basename($this->path), getmypid(), bin2hex(random_bytes(4)));
        $fh = @fopen($tmp, 'xb');
        if ($fh === false) {
            throw new RuntimeException("Cannot open temp file {$tmp}");
        }

        try {
            $written = fwrite($fh, $payload);
            if ($written !== strlen($payload)) {
                throw new RuntimeException("Short write to {$tmp}");
            }
            fflush($fh);
        } finally {
            fclose($fh);
        }

        if (is_file($this->path)) {
            // Keep ownership/permissions of the existing file where possible.
            $perms = @fileperms($this->path);
            if ($perms !== false) {
                @chmod($tmp, $perms & 0777);
            }
            $owner = @fileowner($this->path);
            $group = @filegroup($this->path);
            if ($owner !== false) {
                @chown($tmp, $owner);
            }
            if ($group !== false) {
                @chgrp($tmp, $group);
            }
        } else {
            @chmod($tmp, 0664);
        }

        if (!@rename($tmp, $this->path)) {
            @unlink($tmp);
            throw new RuntimeException("Cannot rename {$tmp} to {$this->path}");
        }
        clearstatcache(true, $this->path);
    }

    private function readRaw(): ?string
    {
        clearstatcache(true, $this->path);
        if (!is_file($this->path)) {
            return null;
        }

        $raw = @file_get_contents($this->path);
        if ($raw === false) {
            throw RpcException::badRequest("Cannot read RPC override file {$this->path}.");
        }

        return $raw;
    }
}
