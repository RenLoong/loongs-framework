<?php

declare(strict_types=1);

namespace Loongs\Rpc\HotReload;

use Loongs\Config\Repository;
use Loongs\Rpc\Discovery\ConfigServiceDiscovery;
use Loongs\Rpc\Discovery\ServiceInstance;
use Loongs\Rpc\Exception\RpcException;
use Throwable;

/**
 * Hot switch for rpc.services without restarting workers.
 *
 * Sources (effective = config ⊕ overrides, override entry replaces the whole service):
 *   1. config/rpc.php  → 'services'   (re-included fresh; opcache invalidated first)
 *   2. runtime override JSON          (rpc.hot_reload.override_file, written by `start rpc:*`)
 *
 * Every worker owns one reloader bound to its own ConfigServiceDiscovery. A Swoole timer
 * (rpc.hot_reload.interval_ms) calls check(): content fingerprints (sha256) of both files
 * are compared; on change the complete effective map is validated (strict) and swapped
 * atomically via ConfigServiceDiscovery::replace(). Invalid input is logged and the
 * previous map stays active. RpcClient additionally calls maybeReload() (throttled by the
 * same interval) so processes without a timer (queue/crontab/custom/CLI) also converge.
 *
 * Only rpc.services is hot-reloaded; retry/iouring/node still need a restart.
 */
final class RpcServiceReloader
{
    private ?string $appliedFingerprint = null;

    private ?string $rejectedFingerprint = null;

    private float $lastCheckAt = 0.0;

    private bool $checking = false;

    private ?int $timerId = null;

    /** @var array<string, string> service => config|override */
    private array $sources = [];

    /** @var callable(string): void */
    private $logger;

    /**
     * @param null|callable(string): void $logger
     */
    public function __construct(
        private readonly ConfigServiceDiscovery $discovery,
        private readonly string $configFile,
        private readonly RpcServiceOverrides $overrides,
        private readonly bool $enabled = true,
        private readonly int $intervalMs = 1000,
        ?callable $logger = null,
    ) {
        $this->logger = $logger ?? static function (string $line): void {
            @fwrite(STDERR, sprintf("[%s] [rpc-hot] pid=%d %s\n", date('Y-m-d H:i:s'), getmypid(), $line));
        };
    }

    public static function fromConfig(Repository $config, string $basePath, ConfigServiceDiscovery $discovery): self
    {
        /** @var mixed $hot */
        $hot = $config->get('rpc.hot_reload', []);
        $hot = is_array($hot) ? $hot : [];

        $enabled = filter_var($hot['enabled'] ?? true, FILTER_VALIDATE_BOOLEAN);
        $interval = (int) ($hot['interval_ms'] ?? 1000);

        return new self(
            discovery: $discovery,
            configFile: rtrim($basePath, '/\\') . '/config/rpc.php',
            overrides: new RpcServiceOverrides(self::resolvePath($basePath, (string) ($hot['override_file'] ?? 'runtime/rpc_services.json'))),
            enabled: $enabled,
            intervalMs: max(50, $interval),
        );
    }

    public static function resolvePath(string $basePath, string $path): string
    {
        if ($path === '') {
            $path = 'runtime/rpc_services.json';
        }
        if ($path[0] === '/' || (strlen($path) > 2 && $path[1] === ':')) {
            return $path;
        }

        return rtrim($basePath, '/\\') . '/' . ltrim($path, '/\\');
    }

    public function enabled(): bool
    {
        return $this->enabled;
    }

    public function intervalMs(): int
    {
        return $this->intervalMs;
    }

    public function overrides(): RpcServiceOverrides
    {
        return $this->overrides;
    }

    public function configFile(): string
    {
        return $this->configFile;
    }

    public function discovery(): ConfigServiceDiscovery
    {
        return $this->discovery;
    }

    /** @return array<string, string> */
    public function sources(): array
    {
        return $this->sources;
    }

    /**
     * services from config/rpc.php, re-included from disk (opcache invalidated).
     *
     * @return array<array-key, mixed>
     */
    public function configServices(): array
    {
        clearstatcache(true, $this->configFile);
        if (!is_file($this->configFile)) {
            return [];
        }
        if (function_exists('opcache_invalidate')) {
            @opcache_invalidate($this->configFile, true);
        }

        $file = $this->configFile;
        /** @var mixed $value */
        $value = (static function () use ($file): mixed {
            return require $file;
        })();

        if (!is_array($value)) {
            throw RpcException::badRequest("{$file} must return an array.");
        }
        $services = $value['services'] ?? [];
        if (!is_array($services)) {
            throw RpcException::badRequest("{$file}: 'services' must be an array.");
        }

        return $services;
    }

    /**
     * Effective map (config ⊕ overrides) + per-service source.
     *
     * @return array{services: array<array-key, mixed>, sources: array<string, string>}
     */
    public function effective(): array
    {
        $services = $this->configServices();
        $sources = [];
        foreach (array_keys($services) as $name) {
            $sources[(string) $name] = 'config';
        }
        foreach ($this->overrides->services() as $name => $config) {
            $services[$name] = $config;
            $sources[$name] = 'override';
        }

        return ['services' => $services, 'sources' => $sources];
    }

    public function fingerprint(): string
    {
        clearstatcache(true, $this->configFile);
        $cfg = is_file($this->configFile) ? (string) @file_get_contents($this->configFile) : '';

        return hash('sha256', $cfg) . ':' . $this->overrides->fingerprint();
    }

    /**
     * Reload when sources changed (or always when $force). Returns true when swapped.
     */
    public function check(bool $force = false): bool
    {
        if ($this->checking) {
            return false;
        }
        $this->checking = true;
        $this->lastCheckAt = microtime(true);

        try {
            try {
                $fingerprint = $this->fingerprint();
            } catch (Throwable $e) {
                $this->log('fingerprint failed, keeping previous services: ' . $e->getMessage());

                return false;
            }

            if (!$force && $fingerprint === $this->appliedFingerprint) {
                return false;
            }
            if (!$force && $fingerprint === $this->rejectedFingerprint) {
                return false; // already logged; wait for the next edit
            }

            try {
                $effective = $this->effective();
                $this->discovery->replace($effective['services']);
            } catch (Throwable $e) {
                $this->rejectedFingerprint = $fingerprint;
                $this->log('reload rejected, keeping previous services (version '
                    . $this->discovery->version() . '): ' . $e->getMessage());

                return false;
            }

            $boot = $this->appliedFingerprint === null;
            $this->appliedFingerprint = $fingerprint;
            $this->rejectedFingerprint = null;
            $this->sources = $effective['sources'];

            $overridden = array_keys(array_filter($this->sources, static fn (string $s): bool => $s === 'override'));
            if (!$boot || $overridden !== []) {
                $this->log(sprintf(
                    '%s services (version %d): %s',
                    $boot ? 'applied runtime overrides at boot' : 'reloaded',
                    $this->discovery->version(),
                    $this->describe(),
                ));
            }

            return true;
        } finally {
            $this->checking = false;
        }
    }

    /**
     * Cheap call-path check, throttled to intervalMs (no-op when disabled).
     */
    public function maybeReload(): void
    {
        if (!$this->enabled) {
            return;
        }
        if ((microtime(true) - $this->lastCheckAt) * 1000 < $this->intervalMs) {
            return;
        }
        $this->check();
    }

    /**
     * Start the per-worker Swoole timer. Safe to call more than once.
     */
    public function startTimer(): ?int
    {
        if (!$this->enabled || $this->timerId !== null || !class_exists(\Swoole\Timer::class)) {
            return $this->timerId;
        }

        $id = \Swoole\Timer::tick($this->intervalMs, function (): void {
            try {
                $this->check();
            } catch (Throwable $e) {
                $this->log('timer check failed: ' . $e->getMessage());
            }
        });
        $this->timerId = is_int($id) ? $id : null;

        return $this->timerId;
    }

    /**
     * Clear the timer (WorkerStop / WorkerExit / shutdown) so workers can exit.
     */
    public function stopTimer(): void
    {
        if ($this->timerId !== null && class_exists(\Swoole\Timer::class)) {
            @\Swoole\Timer::clear($this->timerId);
        }
        $this->timerId = null;
    }

    /**
     * One-line summary: user=override[loopback http://127.0.0.1:9502] demo=config[local]
     */
    public function describe(): string
    {
        $parts = [];
        foreach ($this->discovery->all() as $name => $instances) {
            $targets = array_map(
                static fn (ServiceInstance $i): string => $i->transport
                    . ($i->endpoint !== null ? ' ' . $i->endpoint : '')
                    . ($i->weight !== 1 ? ' w=' . $i->weight : ''),
                $instances,
            );
            $parts[] = sprintf('%s=%s[%s]', $name, $this->sources[$name] ?? 'config', implode(', ', $targets));
        }

        return $parts === [] ? '(no services)' : implode(' ', $parts);
    }

    private function log(string $line): void
    {
        try {
            ($this->logger)($line);
        } catch (Throwable) {
            // never let logging break the worker
        }
    }
}
