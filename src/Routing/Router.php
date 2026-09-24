<?php

declare(strict_types=1);

namespace Loongs\Routing;

use Loongs\App\AppMiddlewareResolver;
use Loongs\Container\Container;
use Loongs\Http\Request;
use Loongs\Http\Response;
use Loongs\Middleware\Attribute\Middleware as MiddlewareAttribute;
use Loongs\Middleware\Pipeline;
use ReflectionClass;
use ReflectionMethod;
use RuntimeException;

final class Router
{
    /** @var list<Route> */
    private array $routes = [];

    private ?string $currentApp = null;

    private ?string $currentModule = null;

    private ?AppMiddlewareResolver $appMiddleware = null;

    private ?Pipeline $pipeline = null;

    public function __construct(
        private readonly Container $container,
    ) {
    }

    public function setCurrentApp(?string $app): void
    {
        $this->currentApp = $app;
    }

    public function currentApp(): ?string
    {
        return $this->currentApp;
    }

    public function setCurrentModule(?string $module): void
    {
        $this->currentModule = $module;
    }

    public function currentModule(): ?string
    {
        return $this->currentModule;
    }

    public function setAppMiddlewareResolver(AppMiddlewareResolver $resolver): void
    {
        $this->appMiddleware = $resolver;
    }

    public function setPipeline(Pipeline $pipeline): void
    {
        $this->pipeline = $pipeline;
    }

    /**
     * @param callable|array{0: class-string, 1: string} $action
     * @param list<class-string> $middleware
     */
    public function add(string $method, string $path, callable|array $action, array $middleware = []): self
    {
        $this->routes[] = new Route(
            strtoupper($method),
            $this->normalize($path),
            $action,
            $middleware,
            $this->currentApp,
            $this->currentModule,
        );

        return $this;
    }

    /** @param callable|array{0: class-string, 1: string} $action */
    public function get(string $path, callable|array $action, array $middleware = []): self
    {
        return $this->add('GET', $path, $action, $middleware);
    }

    /** @param callable|array{0: class-string, 1: string} $action */
    public function post(string $path, callable|array $action, array $middleware = []): self
    {
        return $this->add('POST', $path, $action, $middleware);
    }

    /** @param callable|array{0: class-string, 1: string} $action */
    public function put(string $path, callable|array $action, array $middleware = []): self
    {
        return $this->add('PUT', $path, $action, $middleware);
    }

    /** @param callable|array{0: class-string, 1: string} $action */
    public function delete(string $path, callable|array $action, array $middleware = []): self
    {
        return $this->add('DELETE', $path, $action, $middleware);
    }

    public function dispatch(Request $request): Response
    {
        $method = $request->method();
        $path = $this->normalize($request->path());

        foreach ($this->routes as $route) {
            if ($route->method !== $method) {
                continue;
            }

            $params = $this->matchPath($route->path, $path);
            if ($params === null) {
                continue;
            }

            foreach ($params as $key => $value) {
                $request->setAttribute($key, $value);
            }
            $request->setAttribute('route_params', $params);

            $stack = $this->buildMiddlewareStack($route);
            if ($stack === []) {
                return $this->runAction($route->action, $request);
            }

            $pipeline = $this->pipeline ?? $this->container->make(Pipeline::class);

            return $pipeline
                ->through($stack)
                ->then($request, fn (Request $req): Response => $this->runAction($route->action, $req));
        }

        return (new Response())->json(
            data: null,
            message: 'Not Found',
            code: 404,
            httpStatus: 404,
        );
    }

    /**
     * Exact match, or {param} segments → captured attributes.
     *
     * @return array<string, string>|null null = no match
     */
    private function matchPath(string $pattern, string $path): ?array
    {
        if ($pattern === $path) {
            return [];
        }

        if (!str_contains($pattern, '{')) {
            return null;
        }

        $names = [];
        $placeholder = '___LOONGS_PARAM___';
        $withTokens = preg_replace_callback(
            '#\{([a-zA-Z_][a-zA-Z0-9_]*)\}#',
            static function (array $m) use (&$names, $placeholder): string {
                $names[] = $m[1];

                return $placeholder;
            },
            $pattern,
        );
        if (!is_string($withTokens) || $names === []) {
            return null;
        }

        $regex = '#^' . str_replace($placeholder, '([^/]+)', preg_quote($withTokens, '#')) . '$#';
        if (!preg_match($regex, $path, $matches)) {
            return null;
        }

        $params = [];
        foreach ($names as $i => $name) {
            $params[$name] = (string) $matches[$i + 1];
        }

        return $params;
    }

    /**
     * Build App → Module → Controller → Route middleware (Kernel already ran Global).
     *
     * @return list<class-string>
     */
    private function buildMiddlewareStack(Route $route): array
    {
        $stack = [];

        if ($this->appMiddleware !== null && $route->app !== null) {
            foreach ($this->appMiddleware->forApp($route->app) as $mw) {
                $stack[] = $mw;
            }

            if ($route->module !== null) {
                foreach ($this->appMiddleware->forModule($route->app, $route->module) as $mw) {
                    $stack[] = $mw;
                }
            }
        }

        foreach ($this->resolveControllerMiddleware($route->action) as $mw) {
            $stack[] = $mw;
        }

        foreach ($route->middleware as $mw) {
            $stack[] = $mw;
        }

        return $stack;
    }

    /**
     * @param callable|array{0: class-string, 1: string} $action
     * @return list<class-string>
     */
    private function resolveControllerMiddleware(callable|array $action): array
    {
        if (!is_array($action)) {
            return [];
        }

        [$class, $method] = $action;
        if (!is_string($class) || !class_exists($class)) {
            return [];
        }

        $list = [];

        // Fallback: public static function middleware(): array
        if (method_exists($class, 'middleware')) {
            $ref = new ReflectionMethod($class, 'middleware');
            if ($ref->isStatic() && $ref->isPublic()) {
                /** @var mixed $result */
                $result = $class::middleware();
                if (is_array($result)) {
                    foreach ($result as $item) {
                        if (is_string($item) && $item !== '') {
                            $list[] = $item;
                        }
                    }
                }
            }
        }

        // PHP 8 attributes on class + method
        try {
            $classRef = new ReflectionClass($class);
            foreach ($classRef->getAttributes(MiddlewareAttribute::class) as $attr) {
                /** @var MiddlewareAttribute $instance */
                $instance = $attr->newInstance();
                foreach ($instance->middleware as $mw) {
                    $list[] = $mw;
                }
            }

            if ($classRef->hasMethod($method)) {
                $methodRef = $classRef->getMethod($method);
                foreach ($methodRef->getAttributes(MiddlewareAttribute::class) as $attr) {
                    /** @var MiddlewareAttribute $instance */
                    $instance = $attr->newInstance();
                    foreach ($instance->middleware as $mw) {
                        $list[] = $mw;
                    }
                }
            }
        } catch (\ReflectionException) {
            // ignore
        }

        return $list;
    }

    /**
     * @param callable|array{0: class-string, 1: string} $action
     */
    private function runAction(callable|array $action, Request $request): Response
    {
        if (is_array($action)) {
            [$class, $method] = $action;
            if (is_string($class)) {
                $class = ControllerVersionResolver::resolve($request, $class);
            }
            $controller = $this->container->make($class);
            $result = $controller->{$method}($request);
        } else {
            $result = $action($request);
        }

        if ($result instanceof Response) {
            return $result;
        }

        throw new RuntimeException('Route action must return a Loongs\\Http\\Response instance.');
    }

    private function normalize(string $path): string
    {
        if ($path === '') {
            return '/';
        }
        $path = '/' . trim($path, '/');

        return $path === '/' ? '/' : (rtrim($path, '/') ?: '/');
    }

    /** @return list<Route> */
    public function all(): array
    {
        return $this->routes;
    }
}
