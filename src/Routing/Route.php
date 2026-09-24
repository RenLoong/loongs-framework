<?php

declare(strict_types=1);

namespace Loongs\Routing;

final readonly class Route
{
    /**
     * @param string $method HTTP method (uppercase)
     * @param string $path   Path pattern, e.g. /health
     * @param callable|array{0: class-string, 1: string} $action
     * @param list<class-string> $middleware
     * @param string|null $app    Owning app directory name (e.g. Website), if any
     * @param string|null $module Owning module directory name (e.g. Admin), if any
     */
    public function __construct(
        public string $method,
        public string $path,
        public mixed $action,
        public array $middleware = [],
        public ?string $app = null,
        public ?string $module = null,
    ) {
    }
}
