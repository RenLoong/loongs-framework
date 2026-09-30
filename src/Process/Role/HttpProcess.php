<?php

declare(strict_types=1);

namespace Loongs\Process\Role;

use Loongs\Http\Application;
use Loongs\Http\Request;
use Loongs\Http\Response;
use Loongs\Process\ProcessInterface;
use Loongs\Process\ProcessLog;
use Loongs\Process\WorkerPools;
use Loongs\Support\Env;
use Swoole\Http\Request as SwooleRequest;
use Swoole\Http\Response as SwooleResponse;
use Swoole\Http\Server;

/**
 * HTTP server (routes, middleware, /health, /rpc).
 *
 * The Application is built per worker (WorkerStart), not before the fork, so `./loongs reload`
 * (SIGUSR1 → Swoole worker reload, reload_async) serves changed config / routes / app code
 * without closing the listening socket.
 */
final class HttpProcess implements ProcessInterface
{
    public function __construct(
        private readonly string $basePath,
    ) {
    }

    public function handle(string $name, array $config): void
    {
        $onlyApp = isset($config['app']) && (string) $config['app'] !== '' ? (string) $config['app'] : null;
        $host = (string) ($config['host'] ?? Env::get('HTTP_HOST', '0.0.0.0'));
        $port = (int) ($config['port'] ?? Env::get('HTTP_PORT', 9501));
        /** @var array<string, mixed> $settings */
        $settings = is_array($config['settings'] ?? null) ? $config['settings'] : [];

        $server = new Server($host, $port, WorkerPools::serverMode($config, $settings));
        if ($settings !== []) {
            $server->set($settings);
        }
        $basePath = $this->basePath;
        $app = WorkerPools::attachPerWorker($server, static fn (): Application => new Application($basePath, $onlyApp));

        $server->on('request', static function (SwooleRequest $swooleReq, SwooleResponse $swooleRes) use ($app): void {
            try {
                $current = $app();
                if ($current === null) {
                    throw new \RuntimeException('worker not booted');
                }
                $current->kernel()->handle(new Request($swooleReq))->send($swooleRes);
            } catch (\Throwable $e) {
                (new Response())->json(
                    data: [
                        'exception' => $e::class,
                        'message' => $e->getMessage(),
                    ],
                    message: 'Internal Server Error',
                    code: 500,
                    httpStatus: 500,
                )->send($swooleRes);
            }
        });

        ProcessLog::info(sprintf('HTTP server starting Swoole\\Http\\Server on %s:%d workers=%d', $host, $port, (int) ($settings['worker_num'] ?? 1)));
        $server->start();
    }
}
