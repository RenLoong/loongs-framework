<?php

declare(strict_types=1);

namespace Loongs\Process\Role;

use Loongs\Process\ProcessLog;
use Loongs\Http\Application;
use Loongs\Process\ProcessInterface;
use Loongs\Support\Env;

/**
 * Runs the existing HTTP Application server (routes, middleware, /health, /rpc).
 */
final class HttpProcess implements ProcessInterface
{
    public function __construct(
        private readonly string $basePath,
    ) {
    }

    public function handle(string $name, array $config): void
    {
        $onlyApp = isset($config['app']) ? (string) $config['app'] : null;
        $app = new Application($this->basePath, $onlyApp !== '' ? $onlyApp : null);

        $host = (string) ($config['host'] ?? Env::get('HTTP_HOST', '0.0.0.0'));
        $port = (int) ($config['port'] ?? Env::get('HTTP_PORT', 9501));
        /** @var array<string, mixed> $settings */
        $settings = is_array($config['settings'] ?? null) ? $config['settings'] : [];

        $server = $app->createServer($host, $port, $settings);
        ProcessLog::info(sprintf('HTTP server starting Swoole\\Http\\Server on %s:%d workers=%d', $host, $port, (int) ($settings['worker_num'] ?? 1)));
        $server->start();
    }
}
