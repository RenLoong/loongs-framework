<?php

declare(strict_types=1);

namespace Loongs\Template;

use RuntimeException;

/**
 * Renders apps/<App>/[Modules/<Module>/]views/{template}.php with extract + optional layout.
 */
final class PhpEngine
{
    public function __construct(
        private readonly ViewFactory $factory,
    ) {
    }

    /**
     * @param array<string, mixed> $data
     */
    public function render(
        string $app,
        string $template,
        array $data = [],
        ?string $layout = 'layout',
        ?string $module = null,
    ): string {
        $content = $this->renderFile($app, $template, $data, $module);

        if ($layout === null || $layout === '') {
            return $content;
        }

        return $this->renderFile($app, $layout, array_merge($data, ['content' => $content]), $module);
    }

    /**
     * @param array<string, mixed> $data
     */
    public function renderFile(string $app, string $template, array $data = [], ?string $module = null): string
    {
        $path = $this->factory->viewFile($app, $template, $module);
        if (!is_file($path)) {
            $label = $module !== null && $module !== '' ? $app . '/Modules/' . $module : $app;
            throw new RuntimeException(sprintf('View not found: %s/%s (%s)', $label, $template, $path));
        }

        return $this->evaluate($path, $data);
    }

    /**
     * @param array<string, mixed> $data
     */
    private function evaluate(string $path, array $data): string
    {
        extract($data, EXTR_SKIP);
        ob_start();
        include $path;
        $out = ob_get_clean();

        return is_string($out) ? $out : '';
    }
}
