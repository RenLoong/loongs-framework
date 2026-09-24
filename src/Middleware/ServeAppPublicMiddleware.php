<?php

declare(strict_types=1);

namespace Loongs\Middleware;

use Closure;
use Loongs\Http\Request;
use Loongs\Http\Response;

/**
 * Early global middleware: GET /{appSlug}/{asset...} → apps/{App}/public/{asset}
 * or apps/{App}/Modules/{Module}/public/{rest} when the first asset segment is a module slug.
 *
 * App/module slug = strtolower(directory name). Blocks path traversal.
 * Falls through to $next when no matching file exists (or empty asset path).
 */
final class ServeAppPublicMiddleware implements MiddlewareInterface
{
    /** @var array<string, string>|null slug => AppName */
    private ?array $slugMap = null;

    /** @var array<string, array<string, string>> appName => [moduleSlug => ModuleName] */
    private array $moduleSlugMaps = [];

    /** @var array<string, string> */
    private const MIME = [
        'png' => 'image/png',
        'jpg' => 'image/jpeg',
        'jpeg' => 'image/jpeg',
        'gif' => 'image/gif',
        'webp' => 'image/webp',
        'svg' => 'image/svg+xml',
        'ico' => 'image/x-icon',
        'css' => 'text/css; charset=utf-8',
        'js' => 'application/javascript; charset=utf-8',
        'mjs' => 'application/javascript; charset=utf-8',
        'html' => 'text/html; charset=utf-8',
        'htm' => 'text/html; charset=utf-8',
        'json' => 'application/json; charset=utf-8',
        'txt' => 'text/plain; charset=utf-8',
        'woff' => 'font/woff',
        'woff2' => 'font/woff2',
        'ttf' => 'font/ttf',
        'otf' => 'font/otf',
        'map' => 'application/json',
        'wasm' => 'application/wasm',
        'mp4' => 'video/mp4',
        'webm' => 'video/webm',
        'mp3' => 'audio/mpeg',
        'pdf' => 'application/pdf',
    ];

    public function __construct(
        private readonly string $appsPath,
    ) {
    }

    public function handle(Request $request, Closure $next): Response
    {
        if ($request->method() !== 'GET' && $request->method() !== 'HEAD') {
            return $next($request);
        }

        $path = $request->path();
        $trimmed = trim($path, '/');
        if ($trimmed === '') {
            return $next($request);
        }

        $parts = explode('/', $trimmed, 2);
        $slug = strtolower($parts[0]);
        $asset = $parts[1] ?? '';

        if ($asset === '' || str_contains($asset, '..') || str_contains($asset, "\0")) {
            return $next($request);
        }

        $appName = $this->resolveApp($slug);
        if ($appName === null) {
            return $next($request);
        }

        // 1) App public
        $file = $this->resolveUnderPublic(
            $this->appsPath . DIRECTORY_SEPARATOR . $appName . DIRECTORY_SEPARATOR . 'public',
            $asset,
        );
        if ($file !== null) {
            return Response::file($file);
        }

        // 2) Module public: first segment of asset = module slug
        $assetParts = explode('/', $asset, 2);
        $moduleSlug = strtolower($assetParts[0]);
        $rest = $assetParts[1] ?? '';
        if ($rest === '' || str_contains($rest, '..') || str_contains($rest, "\0")) {
            return $next($request);
        }

        $moduleName = $this->resolveModule($appName, $moduleSlug);
        if ($moduleName === null) {
            return $next($request);
        }

        $file = $this->resolveUnderPublic(
            $this->appsPath
                . DIRECTORY_SEPARATOR . $appName
                . DIRECTORY_SEPARATOR . 'Modules'
                . DIRECTORY_SEPARATOR . $moduleName
                . DIRECTORY_SEPARATOR . 'public',
            $rest,
        );
        if ($file !== null) {
            return Response::file($file);
        }

        return $next($request);
    }

    private function resolveUnderPublic(string $publicDir, string $asset): ?string
    {
        $publicRoot = realpath($publicDir);
        if ($publicRoot === false || !is_dir($publicRoot)) {
            return null;
        }

        $candidate = $publicRoot . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, $asset);
        $real = realpath($candidate);
        if ($real === false || !is_file($real)) {
            return null;
        }

        $prefix = $publicRoot . DIRECTORY_SEPARATOR;
        if ($real !== $publicRoot && !str_starts_with($real, $prefix)) {
            return null;
        }

        return $real;
    }

    private function resolveApp(string $slug): ?string
    {
        if ($this->slugMap === null) {
            $this->slugMap = $this->buildSlugMap($this->appsPath);
        }

        return $this->slugMap[$slug] ?? null;
    }

    private function resolveModule(string $appName, string $moduleSlug): ?string
    {
        if (!isset($this->moduleSlugMaps[$appName])) {
            $modulesPath = $this->appsPath
                . DIRECTORY_SEPARATOR . $appName
                . DIRECTORY_SEPARATOR . 'Modules';
            $this->moduleSlugMaps[$appName] = $this->buildSlugMap($modulesPath);
        }

        return $this->moduleSlugMaps[$appName][$moduleSlug] ?? null;
    }

    /** @return array<string, string> */
    private function buildSlugMap(string $dir): array
    {
        $map = [];
        if (!is_dir($dir)) {
            return $map;
        }

        $entries = scandir($dir);
        if ($entries === false) {
            return $map;
        }

        foreach ($entries as $name) {
            if ($name === '.' || $name === '..' || str_starts_with($name, '.')) {
                continue;
            }
            $full = $dir . DIRECTORY_SEPARATOR . $name;
            if (!is_dir($full)) {
                continue;
            }
            $map[strtolower($name)] = $name;
        }

        return $map;
    }

    public static function mimeFor(string $path): string
    {
        $ext = strtolower(pathinfo($path, PATHINFO_EXTENSION));

        return self::MIME[$ext] ?? 'application/octet-stream';
    }
}
