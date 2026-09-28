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
 *
 * Hot switch: replace() parses + validates a whole service map first and only then
 * swaps $map in a single assignment. RpcClient::call() resolves its instance list once
 * at the start of the call (PHP arrays are values), so in-flight calls keep the old
 * snapshot while new calls see the new map.
 */
final class ConfigServiceDiscovery implements ServiceDiscoveryInterface
{
    private const TRANSPORTS = ['local', 'loopback', 'remote'];

    /** @var array<string, list<ServiceInstance>> */
    private array $map = [];

    /** Incremented on every successful replace(). */
    private int $version = 0;

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

        $map = $this->map;
        $map[$service] = self::parseInstances($service, $config, false);
        $this->map = $map;
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

    /**
     * Validate the complete map (strict) and atomically swap it in.
     * On any validation error nothing changes and RpcException(400) is thrown.
     *
     * @param array<array-key, mixed> $services
     */
    public function replace(array $services): void
    {
        $map = self::parseServices($services, true);
        $this->map = $map;
        $this->version++;
    }

    public function version(): int
    {
        return $this->version;
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
     * Parse a whole rpc.services map.
     *
     * strict=true (hot reload / CLI) additionally requires: array configs, string names,
     * endpoint for remote instances, http(s) URL endpoints, positive timeout_ms.
     *
     * @param array<array-key, mixed> $services
     * @return array<string, list<ServiceInstance>>
     */
    public static function parseServices(array $services, bool $strict = true): array
    {
        $map = [];
        foreach ($services as $name => $config) {
            $service = (string) $name;
            if ($service === '' || ($strict && !is_string($name))) {
                throw RpcException::badRequest('Service name must be a non-empty string.');
            }
            if (!is_array($config)) {
                if ($strict) {
                    throw RpcException::badRequest("Service [{$service}] config must be an object/array.");
                }
                $config = [];
            }
            /** @var array<string, mixed> $config */
            $map[$service] = self::parseInstances($service, $config, $strict);
        }

        return $map;
    }

    /**
     * @param array<string, mixed> $config
     * @return list<ServiceInstance>
     */
    private static function parseInstances(string $service, array $config, bool $strict): array
    {
        $defaultTransport = strtolower((string) ($config['transport'] ?? 'local'));
        if (!in_array($defaultTransport, self::TRANSPORTS, true)) {
            throw RpcException::badRequest("Unknown transport [{$defaultTransport}] for service [{$service}].");
        }

        $defaultTimeout = self::timeout($service, $config['timeout_ms'] ?? null, $strict);
        /** @var array<string, mixed> $defaultMeta */
        $defaultMeta = isset($config['metadata']) && is_array($config['metadata']) ? $config['metadata'] : [];

        if (array_key_exists('instances', $config)) {
            if (!is_array($config['instances'])) {
                if ($strict) {
                    throw RpcException::badRequest("Service [{$service}] instances must be a list.");
                }
            } else {
                if ($config['instances'] === []) {
                    throw RpcException::badRequest("Service [{$service}] instances must not be empty.");
                }

                $instances = [];
                foreach ($config['instances'] as $index => $row) {
                    if (!is_array($row)) {
                        throw RpcException::badRequest("Service [{$service}] instance #{$index} must be an array.");
                    }

                    $transport = strtolower((string) ($row['transport'] ?? $defaultTransport));
                    if (!in_array($transport, self::TRANSPORTS, true)) {
                        throw RpcException::badRequest("Unknown transport [{$transport}] for service [{$service}] instance #{$index}.");
                    }

                    $endpoint = isset($row['endpoint'])
                        ? (string) $row['endpoint']
                        : (isset($config['endpoint']) ? (string) $config['endpoint'] : null);
                    $endpoint = self::endpoint($service, $transport, $endpoint, $strict, "instance #{$index}");
                    $weight = self::weight($service, $row['weight'] ?? null, $strict);
                    $timeoutMs = isset($row['timeout_ms'])
                        ? self::timeout($service, $row['timeout_ms'], $strict)
                        : $defaultTimeout;
                    /** @var array<string, mixed> $meta */
                    $meta = isset($row['metadata']) && is_array($row['metadata'])
                        ? $row['metadata'] + $defaultMeta
                        : $defaultMeta;

                    $instances[] = new ServiceInstance(
                        service: $service,
                        transport: $transport,
                        endpoint: $endpoint,
                        weight: $weight,
                        metadata: $meta,
                        timeoutMs: $timeoutMs,
                    );
                }

                return $instances;
            }
        }

        // Legacy single-map entry.
        $endpoint = isset($config['endpoint']) ? (string) $config['endpoint'] : null;

        return [
            new ServiceInstance(
                service: $service,
                transport: $defaultTransport,
                endpoint: self::endpoint($service, $defaultTransport, $endpoint, $strict, 'endpoint'),
                weight: self::weight($service, $config['weight'] ?? null, $strict),
                metadata: $defaultMeta,
                timeoutMs: $defaultTimeout,
            ),
        ];
    }

    private static function endpoint(string $service, string $transport, ?string $endpoint, bool $strict, string $where): ?string
    {
        $endpoint = $endpoint !== null ? trim($endpoint) : null;
        if ($endpoint === '') {
            $endpoint = null;
        }

        if (!$strict) {
            return $endpoint;
        }

        if ($endpoint === null) {
            if ($transport === 'remote') {
                throw RpcException::badRequest("Service [{$service}] {$where}: remote transport requires endpoint.");
            }

            return null;
        }

        if (preg_match('#^https?://[^\s/]+(/\S*)?$#i', $endpoint) !== 1) {
            throw RpcException::badRequest("Service [{$service}] {$where}: invalid endpoint [{$endpoint}] (expected http(s)://host:port).");
        }

        return $endpoint;
    }

    private static function weight(string $service, mixed $raw, bool $strict): int
    {
        if ($raw === null) {
            return 1;
        }
        if ($strict && (!is_numeric($raw) || (int) $raw < 0)) {
            throw RpcException::badRequest("Service [{$service}] weight must be an integer >= 0.");
        }

        return max(0, (int) $raw);
    }

    private static function timeout(string $service, mixed $raw, bool $strict): ?int
    {
        if ($raw === null) {
            return null;
        }
        if ($strict && (!is_numeric($raw) || (int) $raw <= 0)) {
            throw RpcException::badRequest("Service [{$service}] timeout_ms must be a positive integer.");
        }

        return (int) $raw;
    }
}
