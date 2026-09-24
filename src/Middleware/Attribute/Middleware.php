<?php

declare(strict_types=1);

namespace Loongs\Middleware\Attribute;

use Attribute;

/**
 * Repeatable middleware attribute for controller class and/or method.
 *
 * @example #[Middleware(DemoMiddleware::class)]
 * @example #[Middleware(A::class, B::class)]
 */
#[Attribute(Attribute::TARGET_CLASS | Attribute::TARGET_METHOD | Attribute::IS_REPEATABLE)]
final class Middleware
{
    /** @var list<class-string> */
    public readonly array $middleware;

    /**
     * @param class-string ...$middleware
     */
    public function __construct(string ...$middleware)
    {
        $this->middleware = array_values($middleware);
    }
}
