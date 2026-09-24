<?php

declare(strict_types=1);

namespace Loongs\Middleware;

use Closure;
use Loongs\Container\Container;
use Loongs\Http\Request;
use Loongs\Http\Response;

final class Pipeline
{
    /** @var list<class-string<MiddlewareInterface>|MiddlewareInterface> */
    private array $middleware = [];

    public function __construct(
        private readonly Container $container,
    ) {
    }

    /**
     * @param list<class-string<MiddlewareInterface>|MiddlewareInterface> $middleware
     */
    public function through(array $middleware): self
    {
        $clone = clone $this;
        $clone->middleware = $middleware;
        return $clone;
    }

    /**
     * @param Closure(Request): Response $destination
     */
    public function then(Request $request, Closure $destination): Response
    {
        $pipeline = array_reduce(
            array_reverse($this->middleware),
            function (Closure $next, string|MiddlewareInterface $layer): Closure {
                return function (Request $request) use ($layer, $next): Response {
                    $middleware = $layer instanceof MiddlewareInterface
                        ? $layer
                        : $this->container->make($layer);

                    return $middleware->handle($request, $next);
                };
            },
            $destination,
        );

        return $pipeline($request);
    }
}
