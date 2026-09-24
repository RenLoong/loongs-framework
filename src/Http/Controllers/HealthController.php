<?php

declare(strict_types=1);

namespace Loongs\Http\Controllers;

use Loongs\Http\Request;
use Loongs\Http\Response;

/**
 * Process-level health endpoint for server infrastructure (not a product app).
 */
final class HealthController
{
    public function index(Request $request): Response
    {
        return (new Response())->json(
            data: [
                'status' => 'ok',
                'app' => 'loongs/loongs',
                'framework' => 'loong-swoole',
                'php' => PHP_VERSION,
                'request_id' => $request->getAttribute('request_id'),
                'time' => date('c'),
            ],
            message: 'ok',
            code: 0,
        );
    }
}
