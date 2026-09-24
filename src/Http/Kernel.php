<?php

declare(strict_types=1);

namespace Loongs\Http;

use Loongs\Middleware\MiddlewareInterface;
use Loongs\Middleware\Pipeline;
use Loongs\Routing\Router;

final class Kernel
{
    /** @var list<class-string<MiddlewareInterface>> */
    private array $middleware = [];

    public function __construct(
        private readonly Router $router,
        private readonly Pipeline $pipeline,
    ) {
    }

    /**
     * @param list<class-string<MiddlewareInterface>> $middleware
     */
    public function setMiddleware(array $middleware): void
    {
        $this->middleware = $middleware;
    }

    public function handle(Request $request): Response
    {
        return $this->pipeline
            ->through($this->middleware)
            ->then($request, fn (Request $req): Response => $this->router->dispatch($req));
    }
}
