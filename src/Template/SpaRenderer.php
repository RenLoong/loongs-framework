<?php

declare(strict_types=1);

namespace Loongs\Template;

use RuntimeException;

/**
 * Reads SPA shell HTML from apps/<App>/[Modules/<Module>/]public/index.html
 * or public/{variant}/index.html.
 */
final class SpaRenderer
{
    public function __construct(
        private readonly ViewFactory $factory,
    ) {
    }

    public function render(string $app, ?string $variant = null, ?string $module = null): string
    {
        $path = $this->factory->spaFile($app, $variant, $module);
        if (!is_file($path)) {
            $label = $variant === null || $variant === '' ? 'index.html' : $variant . '/index.html';
            $scope = $module !== null && $module !== '' ? $app . '/Modules/' . $module : $app;
            throw new RuntimeException(sprintf('SPA shell not found: %s/public/%s', $scope, $label));
        }

        $html = file_get_contents($path);
        if ($html === false) {
            throw new RuntimeException('Unable to read SPA shell: ' . $path);
        }

        return $html;
    }
}
