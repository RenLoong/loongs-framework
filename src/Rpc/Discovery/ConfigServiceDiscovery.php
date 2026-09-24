<?php

declare(strict_types=1);

namespace Loongs\Rpc\Discovery;

use Loongs\Rpc\Exception\RpcException;

/**
 * Reads rpc.services from config.
 *
 * Backward compatible single-endpoint:
 *   'user' => ['transport' => 'loopback', 'endpoint' => 'http://127.0.0.1:9501']
 *
 * Multi-instance:
 *   'user' => [
 *     'transport' => 'remote',
 *     'instances' => [
 *       ['endpoint' => 'http://10.0.0.1:9501', 'weight' => 1],
 *       ['endpoint' => 'http://10.0.0.2:9501', 'weight' => 2],
 *     ],
 *   ]
 *
 * Instance weight feeds WeightedInstancePicker (equal weights keep config order;
 * unequal weights get a weighted shuffle at the start of each RpcClient::call()).
 * weight <= 0 is excluded by the picker (fallback: all weight 1 if every row is excluded).
 */
final class ConfigServiceDiscovery implements ServiceDiscoveryInterface
{
    /** @var array<string, list<ServiceInstance>> */
    private array $map = [];

    /**
     * @param array<string, array<string, mixed>> $services
     */
    public function __construct(array $services = [])
    {
        foreach ($services as $name => $config) {
            $this->register((string) $name, is_array($config) ? $config : []);
        }
    }

    /**
     * @param array<string, mixed> $config
     */
    public function register(string $service, array $config): void
    {
        if ($service === '') {
            throw RpcException::badRequest('Service name must not be empty.');
        }

        $this->map[$service] = $this->parseInstances($service, $config);
    }

    /**
     * @param array<string, array<string, mixed>> $services
     */
    public function load(array $services): void
    {
        foreach ($services as $name => $config) {
            $this->register((string) $name, is_array($config) ? $config : []);
        }
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

    /**
     * @return array<string, list<ServiceInstance>>
     */
    public function all(): array
    {
        return $this->map;
    }

    /**
     * @param array<string, mixed> $config
     * @return list<ServiceInstance>
     */
    private function parseInstances(string $service, array $config): array
    {
        $defaultTransport = strtolower((string) ($config['transport'] ?? 'local'));
        if (!in_array($defaultTransport, ['local', 'loopback', 'remote'], true)) {
            throw RpcException::badRequest("Unknown transport [{$defaultTransport}] for service [{$service}].");
        }

        $defaultTimeout = isset($config['timeout_ms']) ? (int) $config['timeout_ms'] : null;
        /** @var array<string, mixed> $defaultMeta */
        $defaultMeta = isset($config['metadata']) && is_array($config['metadata']) ? $config['metadata'] : [];

        if (isset($config['instances']) && is_array($config['instances'])) {
            if ($config['instances'] === []) {
                throw RpcException::badRequest("Service [{$service}] instances must not be empty.");
            }

            $instances = [];
            foreach ($config['instances'] as $index => $row) {
                if (!is_array($row)) {
                    throw RpcException::badRequest("Service [{$service}] instance #{$index} must be an array.");
                }

                $transport = strtolower((string) ($row['transport'] ?? $defaultTransport));
                if (!in_array($transport, ['local', 'loopback', 'remote'], true)) {
                    throw RpcException::badRequest("Unknown transport [{$transport}] for service [{$service}] instance #{$index}.");
                }

                $endpoint = isset($row['endpoint']) ? (string) $row['endpoint'] : (isset($config['endpoint']) ? (string) $config['endpoint'] : null);
                $weight = isset($row['weight']) ? max(0, (int) $row['weight']) : 1;
                $timeoutMs = isset($row['timeout_ms']) ? (int) $row['timeout_ms'] : $defaultTimeout;
                /** @var array<string, mixed> $meta */
                $meta = isset($row['metadata']) && is_array($row['metadata'])
                    ? $row['metadata'] + $defaultMeta
                    : $defaultMeta;

                $instances[] = new ServiceInstance(
                    service: $service,
                    transport: $transport,
                    endpoint: $endpoint !== '' ? $endpoint : null,
                    weight: $weight,
                    metadata: $meta,
                    timeoutMs: $timeoutMs,
                );
            }

            return $instances;
        }

        // Legacy single-map entry.
        $endpoint = isset($config['endpoint']) ? (string) $config['endpoint'] : null;

        return [
            new ServiceInstance(
                service: $service,
                transport: $defaultTransport,
                endpoint: $endpoint !== '' ? $endpoint : null,
                weight: isset($config['weight']) ? max(0, (int) $config['weight']) : 1,
                metadata: $defaultMeta,
                timeoutMs: $defaultTimeout,
            ),
        ];
    }
}
