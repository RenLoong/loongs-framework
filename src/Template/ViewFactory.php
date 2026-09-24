<?php

declare(strict_types=1);

namespace Loongs\Template;

/**
 * Resolves views/ and public/ paths for an app (or module) under apps/{App}/[Modules/{Module}/].
 */
final class ViewFactory
{
    public function __construct(
        private readonly string $basePath,
    ) {
    }

    public function basePath(): string
    {
        return $this->basePath;
    }

    public function appsPath(): string
    {
        return $this->basePath . '/apps';
    }

    public function viewsPath(string $app, ?string $module = null): string
    {
        if ($module !== null && $module !== '') {
            return $this->appsPath() . '/' . $app . '/Modules/' . $module . '/views';
        }

        return $this->appsPath() . '/' . $app . '/views';
    }

    public function publicPath(string $app, ?string $module = null): string
    {
        if ($module !== null && $module !== '') {
            return $this->appsPath() . '/' . $app . '/Modules/' . $module . '/public';
        }

        return $this->appsPath() . '/' . $app . '/public';
    }

    public function viewFile(string $app, string $template, ?string $module = null): string
    {
        $template = str_replace(['\\', '..'], ['/', ''], $template);
        $template = trim($template, '/');

        return $this->viewsPath($app, $module) . '/' . $template . '.php';
    }

    public function spaFile(string $app, ?string $variant = null, ?string $module = null): string
    {
        $public = $this->publicPath($app, $module);
        if ($variant === null || $variant === '') {
            return $public . '/index.html';
        }

        $variant = str_replace(['\\', '..'], ['/', ''], $variant);
        $variant = trim($variant, '/');

        return $public . '/' . $variant . '/index.html';
    }
}
