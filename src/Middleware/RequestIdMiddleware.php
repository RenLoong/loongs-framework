<?php

declare(strict_types=1);

namespace Loongs\Middleware;

use Closure;
use Loongs\Http\Request;
use Loongs\Http\Response;

final class RequestIdMiddleware implements MiddlewareInterface
{
    public function handle(Request $request, Closure $next): Response
    {
        $requestId = $request->header('x-request-id')
            ?? bin2hex(random_bytes(8));

        $request->setAttribute('request_id', $requestId);

        $response = $next($request);
        $response->header('X-Request-Id', $requestId);

        return $response;
    }
}
