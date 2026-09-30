<?php

declare(strict_types=1);

namespace Loongs\Rpc\HotReload;

use Loongs\Config\Repository;
use Loongs\Rpc\Discovery\ConfigServiceDiscovery;
use Loongs\Rpc\Discovery\ServiceInstance;
use Loongs\Rpc\Exception\RpcException;
use Loongs\Support\BasePath;
use Loongs\Support\Env;
use Throwable;

/**
 * Code API for RPC hot switch (the CLI `loongs rpc:*` uses this same class).
 *
 *   $rpc = rpc_services();                       // or inject RpcServiceManager
 *   $rpc->switch('user', 'loopback', 'http://127.0.0.1:9502');
 *   $rpc->switch('user', 'remote', 'http://10.0.0.12:9502');
 *   $rpc->set('user', ['transport' => 'remote', 'instances' => [...]]);
 *   $rpc->reset('user');  $rpc->resetAll();
 *   $rpc->show('user');   $rpc->reload();  $rpc->version();
 *
 * Every write:
 *   1. strict-validates the new service config (same parser as the workers) —
 *      invalid input throws RpcException(400) and the override file is not touched;
 *   2. writes rpc.hot_reload.override_file atomically (temp + rename);
 *   3. swaps the calling process's discovery immediately (reload);
 *   4. bumps the per-server shared version (Swoole\Atomic) so the other workers of the
 *      same Swoole server reload on their next RpcClient::call() without waiting;
 *      other processes (rpc/queue/crontab/custom, other nodes) follow via their timer
 *      within rpc.hot_reload.interval_ms.
 */
final class RpcServiceManager
{
    private const TRANSPORTS = ['local', 'loopback', 'remote'];

    public function __construct(
        private readonly RpcServiceReloader $reloader,
    ) {
    }

    /**
     * Standalone manager (CLI / scripts without a booted Application): loads .env + config
     * from $basePath and works on a private discovery. Writes still reach running workers
     * through the override file (≤ interval_ms).
     *
     * @param null|callable(string): void $logger  default: silent
     */
    public static function fromBasePath(?string $basePath = null, ?callable $logger = null): self
    {
        $basePath = rtrim($basePath ?? BasePath::get(), '/\\');
        BasePath::set($basePath);
        Env::load($basePath . '/.env');
        $config = new Repository($basePath . '/config');
        $reloader = RpcServiceReloader::fromConfig(
            $config,
            $basePath,
            new ConfigServiceDiscovery(),
            $logger ?? static function (string $line): void {
            },
        );
        $reloader->check(true);

        return new self($reloader);
    }

    public function reloader(): RpcServiceReloader
    {
        return $this->reloader;
    }

    /**
     * Point a service at local / loopback / remote. Keeps timeout_ms + metadata of the
     * current effective entry. loopback without endpoint → http://127.0.0.1:$RPC_PORT.
     *
     * @return array<string, mixed> the override written
     */
    public function switch(string $service, string $transport, ?string $endpoint = null): array
    {
        $service = $this->serviceName($service);
        $transport = strtolower(trim($transport));
        $endpoint = $endpoint !== null && trim($endpoint) !== '' ? trim($endpoint) : null;

        if (!in_array($transport, self::TRANSPORTS, true)) {
            throw RpcException::badRequest("Unknown transport [{$transport}] (expected local|loopback|remote).");
        }

        $effective = $this->reloader->effective();
        if (!array_key_exists($service, $effective['services'])) {
            throw RpcException::notFound("Service [{$service}] is not defined in config/rpc.php or overrides (use set() to add one).");
        }

        /** @var mixed $current */
        $current = $effective['services'][$service];
        $current = is_array($current) ? $current : [];

        $config = ['transport' => $transport];
        foreach (['timeout_ms', 'metadata'] as $keep) {
            if (array_key_exists($keep, $current)) {
                $config[$keep] = $current[$keep];
            }
        }

        if ($transport === 'loopback' && $endpoint === null) {
            $endpoint = 'http://127.0.0.1:' . (int) Env::get('RPC_PORT', 9502);
        }
        if ($transport === 'remote' && $endpoint === null) {
            throw RpcException::badRequest("switch('{$service}', 'remote') requires an endpoint, e.g. http://10.0.0.12:9502.");
        }
        if ($transport !== 'local' && $endpoint !== null) {
            $config['endpoint'] = $endpoint;
        }

        return $this->write($service, $config);
    }

    /**
     * Replace the whole service config (instances, weights, metadata, timeout_ms…).
     *
     * @param array<string, mixed> $config
     * @return array<string, mixed>
     */
    public function set(string $service, array $config): array
    {
        $service = $this->serviceName($service);
        if ($config === [] || array_is_list($config)) {
            throw RpcException::badRequest("Service [{$service}] config must be a non-empty associative array.");
        }

        return $this->write($service, $config);
    }

    /**
     * Drop the runtime override for one service (→ config/rpc.php). false = had none.
     */
    public function reset(string $service): bool
    {
        $service = $this->serviceName($service);
        $removed = $this->reloader->overrides()->reset($service);
        if ($removed) {
            $this->applyNow();
        }

        return $removed;
    }

    /**
     * Drop every runtime override (also repairs an invalid override file).
     */
    public function resetAll(): void
    {
        $this->reloader->overrides()->resetAll();
        $this->applyNow();
    }

    /**
     * Force an immediate reload in this process (no-op result false when nothing
     * changed or the sources are invalid — the previous map is kept).
     */
    public function reload(): bool
    {
        return $this->reloader->check(true);
    }

    /** Discovery map version of this process (increments on every swap). */
    public function version(): int
    {
        return $this->reloader->discovery()->version();
    }

    /**
     * Effective configuration as computed from disk right now (what workers converge to),
     * plus what THIS process currently has loaded.
     *
     * @return array{
     *   hot_reload: array{enabled: bool, interval_ms: int},
     *   config_file: string,
     *   override_file: array{path: string, state: string, valid: bool, error: ?string},
     *   valid: bool,
     *   error: ?string,
     *   version: int,
     *   services: array<string, array{source: string, config: mixed, instances: list<array<string, mixed>>}>,
     *   loaded: array<string, list<array<string, mixed>>>
     * }
     */
    public function show(?string $service = null): array
    {
        $overrides = $this->reloader->overrides();
        $override = ['path' => $overrides->path(), 'state' => 'missing', 'valid' => true, 'error' => null];
        $overrideServices = [];
        if ($overrides->exists()) {
            try {
                $overrideServices = $overrides->services();
                $override['state'] = 'valid';
            } catch (Throwable $e) {
                $override = ['path' => $overrides->path(), 'state' => 'invalid', 'valid' => false, 'error' => $e->getMessage()];
            }
        }

        $services = $this->reloader->configServices();
        $sources = [];
        foreach (array_keys($services) as $name) {
            $sources[(string) $name] = 'config';
        }
        foreach ($overrideServices as $name => $cfg) {
            $services[$name] = $cfg;
            $sources[$name] = 'override';
        }

        $error = null;
        try {
            $map = ConfigServiceDiscovery::parseServices($services, true);
        } catch (Throwable $e) {
            $error = $e->getMessage();
            $map = ConfigServiceDiscovery::parseServices($services, false);
        }

        $out = [];
        foreach ($map as $name => $instances) {
            if ($service !== null && $service !== $name) {
                continue;
            }
            $out[$name] = [
                'source' => $sources[$name] ?? 'config',
                'config' => $services[$name] ?? null,
                'instances' => array_map(self::instanceToArray(...), $instances),
            ];
        }
        if ($service !== null && $out === []) {
            throw RpcException::notFound("Service [{$service}] is not defined.");
        }

        $loaded = [];
        foreach ($this->reloader->discovery()->all() as $name => $instances) {
            if ($service === null || $service === $name) {
                $loaded[$name] = array_map(self::instanceToArray(...), $instances);
            }
        }

        return [
            'hot_reload' => ['enabled' => $this->reloader->enabled(), 'interval_ms' => $this->reloader->intervalMs()],
            'config_file' => $this->reloader->configFile(),
            'override_file' => $override,
            'valid' => $error === null && $override['valid'],
            'error' => $error,
            'version' => $this->version(),
            'services' => $out,
            'loaded' => $loaded,
        ];
    }

    /**
     * @param array<string, mixed> $config
     * @return array<string, mixed>
     */
    private function write(string $service, array $config): array
    {
        // Validate the single entry and the resulting effective map before touching disk.
        ConfigServiceDiscovery::parseServices([$service => $config], true);
        $effective = $this->reloader->effective(); // throws if the current override file is invalid
        $effective['services'][$service] = $config;
        ConfigServiceDiscovery::parseServices($effective['services'], true);

        $this->reloader->overrides()->set($service, $config);
        $this->applyNow();

        return $config;
    }

    private function applyNow(): void
    {
        $this->reloader->check(true);
        $this->reloader->notifyPeers();
    }

    private function serviceName(string $service): string
    {
        $service = trim($service);
        if ($service === '') {
            throw RpcException::badRequest('Service name must not be empty.');
        }

        return $service;
    }

    /**
     * @return array<string, mixed>
     */
    private static function instanceToArray(ServiceInstance $i): array
    {
        return [
            'transport' => $i->transport,
            'endpoint' => $i->endpoint,
            'weight' => $i->weight,
            'timeout_ms' => $i->timeoutMs,
            'metadata' => $i->metadata,
        ];
    }
}
