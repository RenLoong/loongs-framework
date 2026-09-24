<?php

declare(strict_types=1);

namespace Loongs\Template;

use Loongs\Http\Response;

/**
 * Facade-like helper for controllers: sync PHP views + SPA shells (app or module).
 */
final class Template
{
    public function __construct(
        private readonly ViewFactory $factory,
        private readonly PhpEngine $php,
        private readonly SpaRenderer $spa,
    ) {
    }

    public function factory(): ViewFactory
    {
        return $this->factory;
    }

    /**
     * Render a sync PHP view (with optional layout) as HTML Response.
     *
     * @param array<string, mixed> $data
     */
    public function view(
        string $app,
        string $template,
        array $data = [],
        ?string $layout = 'layout',
        ?string $module = null,
    ): Response {
        $html = $this->php->render($app, $template, $data, $layout, $module);

        return (new Response())->html($html);
    }

    /**
     * Return SPA shell HTML from public/index.html or public/{variant}/index.html.
     */
    public function spa(string $app, ?string $variant = null, ?string $module = null): Response
    {
        $html = $this->spa->render($app, $variant, $module);

        return (new Response())->html($html);
    }
}
