<?php

declare(strict_types=1);

namespace Loongs\Rpc\Discovery;

use Loongs\Rpc\Exception\RpcException;

/**
 * Programmatic / test double discovery. Merges cleanly with ConfigServiceDiscovery
 * via CompositeServiceDiscovery when needed later.
 */
final class StaticServiceDiscovery implements ServiceDiscoveryInterface
{
    /** @var array<string, list<ServiceInstance>> */
    private array $map = [];

    /**
     * @param array<string, list<ServiceInstance>> $services
     */
    public function __construct(array $services = [])
    {
        foreach ($services as $name => $instances) {
            $this->set((string) $name, $instances);
        }
    }

    /**
     * @param list<ServiceInstance> $instances
     */
    public function set(string $service, array $instances): void
    {
        if ($service === '') {
            throw RpcException::badRequest('Service name must not be empty.');
        }
        if ($instances === []) {
            throw RpcException::badRequest("Service [{$service}] must have at least one instance.");
        }

        $this->map[$service] = array_values($instances);
    }

    public function has(string $service): bool
    {
        return isset($this->map[$service]) && $this->map[$service] !== [];
    }

    /**
     * @return list<ServiceInstance>
     */
    public function resolve(string $service): array
    {
        if (!$this->has($service)) {
            throw RpcException::notFound("RPC service [{$service}] is not registered.");
        }

        return $this->map[$service];
    }
}
