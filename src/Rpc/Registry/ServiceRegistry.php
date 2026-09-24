<?php

declare(strict_types=1);

namespace Loongs\Rpc\Registry;

use Loongs\Rpc\Discovery\ConfigServiceDiscovery;
use Loongs\Rpc\Discovery\ServiceDiscoveryInterface;
use Loongs\Rpc\Discovery\ServiceInstance;
use Loongs\Rpc\Exception\RpcException;

/**
 * Thin facade over ServiceDiscoveryInterface for backward-compatible callers.
 *
 * Prefer injecting ServiceDiscoveryInterface into new code.
 * get() returns the first instance as a legacy transport config array.
 */
final class ServiceRegistry
{
    private ServiceDiscoveryInterface $discovery;

    /**
     * @param array<string, array<string, mixed>>|ServiceDiscoveryInterface $services
     */
    public function __construct(array|ServiceDiscoveryInterface $services = [])
    {
        $this->discovery = $services instanceof ServiceDiscoveryInterface
            ? $services
            : new ConfigServiceDiscovery($services);
    }

    public function discovery(): ServiceDiscoveryInterface
    {
        return $this->discovery;
    }

    /**
     * @param array<string, mixed> $config
     */
    public function register(string $service, array $config): void
    {
        if ($this->discovery instanceof ConfigServiceDiscovery) {
            $this->discovery->register($service, $config);

            return;
        }

        throw RpcException::badRequest('ServiceRegistry::register requires ConfigServiceDiscovery.');
    }

    /**
     * @param array<string, array<string, mixed>> $services
     */
    public function load(array $services): void
    {
        if ($this->discovery instanceof ConfigServiceDiscovery) {
            $this->discovery->load($services);

            return;
        }

        throw RpcException::badRequest('ServiceRegistry::load requires ConfigServiceDiscovery.');
    }

    public function has(string $service): bool
    {
        return $this->discovery->has($service);
    }

    /**
     * Legacy single-config view (first instance).
     *
     * @return array<string, mixed>
     */
    public function get(string $service): array
    {
        $instances = $this->discovery->resolve($service);

        return $instances[0]->toServiceConfig();
    }

    /**
     * @return list<ServiceInstance>
     */
    public function resolve(string $service): array
    {
        return $this->discovery->resolve($service);
    }

    /**
     * @return array<string, array<string, mixed>>
     */
    public function all(): array
    {
        if ($this->discovery instanceof ConfigServiceDiscovery) {
            $out = [];
            foreach ($this->discovery->all() as $name => $instances) {
                $out[$name] = $instances[0]->toServiceConfig();
                if (count($instances) > 1) {
                    $out[$name]['instances'] = array_map(
                        static fn (ServiceInstance $i): array => $i->toServiceConfig(),
                        $instances,
                    );
                }
            }

            return $out;
        }

        return [];
    }
}
