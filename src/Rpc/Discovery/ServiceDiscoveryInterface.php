<?php

declare(strict_types=1);

namespace Loongs\Rpc\Discovery;

/**
 * Resolves service name → one or more ServiceInstance values.
 * Config today; Consul/etcd later without changing RpcClient.
 */
interface ServiceDiscoveryInterface
{
    /**
     * @return list<ServiceInstance>
     */
    public function resolve(string $service): array;

    public function has(string $service): bool;
}
