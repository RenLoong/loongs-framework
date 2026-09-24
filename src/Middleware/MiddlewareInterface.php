<?php

declare(strict_types=1);

namespace Loongs\Middleware;

use Closure;
use Loongs\Http\Request;
use Loongs\Http\Response;

interface MiddlewareInterface
{
    /**
     * @param Closure(Request): Response $next
     */
    public function handle(Request $request, Closure $next): Response;
}
