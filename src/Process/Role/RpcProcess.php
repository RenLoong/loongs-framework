<?php

declare(strict_types=1);

namespace Loongs\Process\Role;

use Loongs\Http\Application;
use Loongs\Http\Request;
use Loongs\Http\Response;
use Loongs\Process\ProcessInterface;
use Loongs\Process\WorkerPools;
use Loongs\Rpc\Server\RpcServer;
use Loongs\Rpc\Support\IoUringSupport;
use Loongs\Container\Container;
use Loongs\Support\Env;
use Swoole\Coroutine;
use Swoole\Coroutine\Http\Server as CoroutineHttpServer;
use Swoole\Http\Request as SwooleRequest;
use Swoole\Http\Response as SwooleResponse;
use Swoole\Http\Server as HttpServer;
use Swoole\Process;
use Throwable;

/**
 * Dedicated RPC HTTP endpoint (POST /rpc, GET /health).
 *
 * When IoUringSupport::usesNetworkUring() is true (Swoole built with
 * --enable-uring-socket and RPC_IOURING≠off), serves via
 * Swoole\Coroutine\Http\Server so accept/read/write go through UringSocket.
 * Otherwise falls back to classic Swoole\Http\Server (epoll).
 */
final class RpcProcess implements ProcessInterface
{
    public function __construct(
        private readonly string $basePath,
    ) {
    }

    public function handle(string $name, array $config): void
    {
        $onlyApp = isset($config['app']) ? (string) $config['app'] : null;
        $app = new Application($this->basePath, $onlyApp !== '' ? $onlyApp : null);
        $container = $app->container();

        /** @var array<string, mixed> $iouringConfig */
        $iouringConfig = $app->config()->get('rpc.iouring', []);
        if (!is_array($iouringConfig)) {
            $iouringConfig = [];
        }
        $iouring = IoUringSupport::bootstrap($iouringConfig);
        IoUringSupport::applyFileTunables($iouringConfig);

        $host = (string) ($config['host'] ?? Env::get('RPC_HOST', '0.0.0.0'));
        $port = (int) ($config['port'] ?? Env::get('RPC_PORT', 9502));
        /** @var array<string, mixed> $settings */
        $settings = is_array($config['settings'] ?? $config['settings'] ?? null) ? ($config['settings'] ?? $config['settings']) : [
            'worker_num' => 1,
            'reload_async' => true,
            'max_wait_time' => 60,
            'package_max_length' => 2 * 1024 * 1024,
        ];
        $settings = IoUringSupport::mergeServerSettings($settings, $iouring);

        $path = (string) ($app->config()->get('rpc.path', '/rpc'));
        if ($path === '') {
            $path = '/rpc';
        }

        if (IoUringSupport::usesNetworkUring($iouring)) {
            $this->runUringServer($name, $host, $port, $path, $settings, $container);
        } else {
            $this->runEpollServer($name, $host, $port, $path, $settings, $container);
        }
    }

    /**
     * @param array<string, mixed> $settings
     */
    private function runUringServer(
        string $name,
        string $host,
        int $port,
        string $path,
        array $settings,
        Container $container,
    ): void {
        $workerNum = max(1, (int) ($settings['worker_num'] ?? 1));

        echo sprintf(
            "[%s] RPC process [%s] starting Coroutine\\Http\\Server network=uring_socket on %s:%d path=%s workers=%d\n",
            date('Y-m-d H:i:s'),
            $name,
            $host,
            $port,
            $path,
            $workerNum,
        );

        if ($workerNum === 1) {
            $this->serveUringWorker($host, $port, $path, $settings, $container);

            return;
        }

        // Multiple workers: SO_REUSEPORT via Coroutine\Http\Server 4th ctor arg.
        $pool = new \Swoole\Process\Pool($workerNum);
        $pool->on('WorkerStart', function (\Swoole\Process\Pool $pool, int $workerId) use ($host, $port, $path, $settings, $container): void {
            echo sprintf(
                "[%s] RPC uring worker#%d pid=%d\n",
                date('Y-m-d H:i:s'),
                $workerId,
                getmypid(),
            );
            $this->serveUringWorker($host, $port, $path, $settings, $container);
        });
        $pool->start();
    }

    /**
     * @param array<string, mixed> $settings
     */
    private function serveUringWorker(
        string $host,
        int $port,
        string $path,
        array $settings,
        Container $container,
    ): void {
        WorkerPools::enableCoroutineHooks();
        WorkerPools::boot($container);

        $packageMax = (int) ($settings['package_max_length'] ?? (2 * 1024 * 1024));

        Coroutine\run(function () use ($host, $port, $path, $packageMax, $container): void {
            $server = new CoroutineHttpServer($host, $port, false, true);
            if ($packageMax > 0 && method_exists($server, 'set')) {
                /** @psalm-suppress InvalidArgument */
                $server->set(['package_max_length' => $packageMax]);
            }

            $handler = $this->makeRequestHandler($container, $path);

            $server->handle($path, $handler);
            if ($path !== '/rpc') {
                $server->handle('/rpc', $handler);
            }
            $server->handle('/health', static function (SwooleRequest $req, SwooleResponse $res): void {
                (new Response())->json(['status' => 'ok', 'role' => 'rpc'], 'ok', 0, 200)->send($res);
            });
            $server->handle('/rpc/health', static function (SwooleRequest $req, SwooleResponse $res): void {
                (new Response())->json(['status' => 'ok', 'role' => 'rpc'], 'ok', 0, 200)->send($res);
            });

            $shuttingDown = false;
            $shutdown = static function () use ($server, &$shuttingDown): void {
                if ($shuttingDown) {
                    return;
                }
                $shuttingDown = true;
                try {
                    $server->shutdown();
                } catch (Throwable) {
                    // ignore
                }
            };

            Process::signal(SIGTERM, $shutdown);
            Process::signal(SIGINT, $shutdown);

            $server->start();
        });

        WorkerPools::close($container);
    }

    /**
     * @param array<string, mixed> $settings
     */
    private function runEpollServer(
        string $name,
        string $host,
        int $port,
        string $path,
        array $settings,
        Container $container,
    ): void {
        $server = new HttpServer($host, $port);
        if ($settings !== []) {
            $server->set($settings);
        }

        WorkerPools::attach($server, $container);

        $server->on('request', $this->makeRequestHandler($container, $path));

        echo sprintf(
            "[%s] RPC process [%s] starting Swoole\\Http\\Server network=epoll on %s:%d path=%s\n",
            date('Y-m-d H:i:s'),
            $name,
            $host,
            $port,
            $path,
        );
        $server->start();
    }

    /**
     * @return callable(SwooleRequest, SwooleResponse): void
     */
    private function makeRequestHandler(Container $container, string $path): callable
    {
        return static function (SwooleRequest $swooleReq, SwooleResponse $swooleRes) use ($container, $path): void {
            try {
                $request = new Request($swooleReq);
                $uriPath = (string) (($swooleReq->server['request_uri'] ?? '/') ?: '/');
                $uri = parse_url($uriPath, PHP_URL_PATH) ?: $uriPath;
                $method = strtoupper((string) ($swooleReq->server['request_method'] ?? 'GET'));

                if ($method === 'GET' && ($uri === '/health' || $uri === '/rpc/health')) {
                    (new Response())->json(['status' => 'ok', 'role' => 'rpc'], 'ok', 0, 200)->send($swooleRes);

                    return;
                }

                if ($method === 'POST' && ($uri === $path || $uri === rtrim($path, '/'))) {
                    /** @var RpcServer $rpc */
                    $rpc = $container->make(RpcServer::class);
                    $rpcResponse = $rpc->handleJson($request->body());
                    (new Response())->raw(
                        $rpcResponse->toJson(),
                        'application/json; charset=utf-8',
                    )->send($swooleRes);

                    return;
                }

                (new Response())->json(['path' => $uri], 'Not Found', 404, 404)->send($swooleRes);
            } catch (Throwable $e) {
                (new Response())->json(
                    [
                        'exception' => $e::class,
                        'message' => $e->getMessage(),
                    ],
                    'Internal Server Error',
                    500,
                    500,
                )->send($swooleRes);
            }
        };
    }
}
