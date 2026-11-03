<?php

declare(strict_types=1);

namespace Loongs\Service;

/**
 * Read apps/<App>/loongs.json manifest helpers (services + menus export).
 */
final class Manifest
{
    public const FILE = 'loongs.json';

    /**
     * @return array<string, mixed>|null
     */
    public static function read(string $appDir): ?array
    {
        $file = rtrim($appDir, '/\\') . DIRECTORY_SEPARATOR . self::FILE;
        if (!is_file($file)) {
            return null;
        }
        try {
            $doc = json_decode((string) file_get_contents($file), true, 64, JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            return null;
        }

        return is_array($doc) ? $doc : null;
    }

    /**
     * @param array<string, mixed> $doc
     * @return list<array<string, mixed>>
     */
    public static function services(array $doc): array
    {
        $services = $doc['services'] ?? [];
        if (!is_array($services) || !array_is_list($services)) {
            return [];
        }
        $out = [];
        foreach ($services as $row) {
            if (is_array($row)) {
                $out[] = $row;
            }
        }

        return $out;
    }

    /**
     * Menus.export payload from this app's own loongs.json only.
     *
     * @return array<string, mixed>
     */
    public static function exportMenus(string $appDir): array
    {
        $app = basename(rtrim($appDir, '/\\'));
        $doc = self::read($appDir);
        if ($doc === null) {
            return [
                'ok' => false,
                'app' => $app,
                'package' => '',
                'name' => $app,
                'description' => '',
                'icon' => '',
                'version' => null,
                'version_name' => null,
                'menus' => [],
                'error' => 'loongs.json missing or invalid',
            ];
        }
        $menus = $doc['menus'] ?? [];
        if (!is_array($menus) || !array_is_list($menus)) {
            $menus = [];
        }

        return [
            'ok' => true,
            'app' => $app,
            'package' => (string) ($doc['package'] ?? ''),
            'name' => (string) ($doc['name'] ?? $app),
            'description' => (string) ($doc['description'] ?? ''),
            'icon' => (string) ($doc['icon'] ?? ''),
            'version' => isset($doc['version']) ? (string) $doc['version'] : null,
            'version_name' => isset($doc['version_name']) ? (string) $doc['version_name'] : null,
            'menus' => $menus,
        ];
    }

    /** Logical service id → RPC method prefix, e.g. menus → Menus. */
    public static function methodPrefix(string $serviceId): string
    {
        $parts = preg_split('/[-_]+/', strtolower(trim($serviceId))) ?: [];
        $out = '';
        foreach ($parts as $p) {
            if ($p === '') {
                continue;
            }
            $out .= ucfirst($p);
        }

        return $out !== '' ? $out : 'Service';
    }
}
