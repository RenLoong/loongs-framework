<?php

declare(strict_types=1);

namespace Loongs\Service;

/**
 * In-memory (per worker/process) registry of sub-providers for logical services.
 *
 * v1: process-local only. Redis / shared store can replace this later without
 * changing Menus.register / Menus.export contracts.
 *
 * Entry shape: { rpc_service, package, app, version, registered_at }.
 */
final class ProviderRegistry
{
    /** @var array<string, array<string, array<string, mixed>>> serviceId → key → entry */
    private array $byService = [];

    /**
     * Idempotent upsert. Key = rpc_service (preferred) or app.
     *
     * @param array{rpc_service?: string, app?: string, package?: string, version?: string|null} $provider
     * @return array<string, mixed> stored entry
     */
    public function register(string $serviceId, array $provider): array
    {
        $serviceId = strtolower(trim($serviceId));
        $rpc = strtolower(trim((string) ($provider['rpc_service'] ?? '')));
        $app = trim((string) ($provider['app'] ?? ''));
        if ($rpc === '' && $app === '') {
            throw new \InvalidArgumentException('Provider requires rpc_service or app.');
        }
        if ($rpc === '') {
            $rpc = strtolower($app);
        }
        $entry = [
            'rpc_service' => $rpc,
            'package' => (string) ($provider['package'] ?? ''),
            'app' => $app !== '' ? $app : $rpc,
            'version' => isset($provider['version']) && $provider['version'] !== '' && $provider['version'] !== null
                ? (string) $provider['version']
                : null,
            'registered_at' => gmdate('c'),
        ];
        $this->byService[$serviceId][$rpc] = $entry;

        return $entry;
    }

    public function unregister(string $serviceId, string $rpcServiceOrApp): bool
    {
        $serviceId = strtolower(trim($serviceId));
        $key = strtolower(trim($rpcServiceOrApp));
        if ($key === '' || !isset($this->byService[$serviceId])) {
            return false;
        }
        if (isset($this->byService[$serviceId][$key])) {
            unset($this->byService[$serviceId][$key]);

            return true;
        }
        foreach ($this->byService[$serviceId] as $rpc => $entry) {
            if (strcasecmp((string) ($entry['app'] ?? ''), $rpcServiceOrApp) === 0) {
                unset($this->byService[$serviceId][$rpc]);

                return true;
            }
        }

        return false;
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function providers(string $serviceId): array
    {
        $serviceId = strtolower(trim($serviceId));
        $list = array_values($this->byService[$serviceId] ?? []);
        usort($list, static fn (array $a, array $b): int => strcmp((string) $a['rpc_service'], (string) $b['rpc_service']));

        return $list;
    }

    /**
     * Merge extra providers (e.g. manifest discovery). Dedupes by rpc_service; existing wins.
     *
     * @param list<array<string, mixed>> $extra
     * @return list<array<string, mixed>>
     */
    public function mergeProviders(string $serviceId, array $extra): array
    {
        $map = [];
        foreach ($this->providers($serviceId) as $p) {
            $map[(string) $p['rpc_service']] = $p;
        }
        foreach ($extra as $p) {
            if (!is_array($p)) {
                continue;
            }
            $rpc = strtolower(trim((string) ($p['rpc_service'] ?? '')));
            if ($rpc === '' || isset($map[$rpc])) {
                continue;
            }
            $map[$rpc] = [
                'rpc_service' => $rpc,
                'package' => (string) ($p['package'] ?? ''),
                'app' => (string) ($p['app'] ?? $rpc),
                'version' => isset($p['version']) && $p['version'] !== null && $p['version'] !== ''
                    ? (string) $p['version']
                    : null,
                'registered_at' => (string) ($p['registered_at'] ?? 'discovery'),
            ];
        }
        $list = array_values($map);
        usort($list, static fn (array $a, array $b): int => strcmp((string) $a['rpc_service'], (string) $b['rpc_service']));

        return $list;
    }
}
