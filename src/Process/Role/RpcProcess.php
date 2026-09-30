<?php

declare(strict_types=1);

namespace Loongs\Process\Role;

use Loongs\Config\Repository;
use Loongs\Container\Container;
use Loongs\Http\Application;
use Loongs\Http\Request;
use Loongs\Http\Response;
use Loongs\Process\ProcessInterface;
use Loongs\Process\ProcessLog;
use Loongs\Process\WorkerPools;
use Loongs\Rpc\Server\RpcServer;
use Loongs\Rpc\Support\IoUringSupport;
use Loongs\Support\Env;
use Swoole\Coroutine;
use Swoole\Coroutine\Http\Server as CoroutineHttpServer;
use Swoole\Event;
use Swoole\Http\Request as SwooleRequest;
use Swoole\Http\Response as SwooleResponse;
use Swoole\Http\Server as HttpServer;
use Swoole\Process;
use Swoole\Timer;
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
    /**
     * Upper bound for draining in-flight requests on SIGTERM — stop, or `./loongs reload`, where the
     * master first starts a replacement on the same port (SO_REUSEPORT) and then SIGTERMs this one.
     * The master SIGKILLs after 15s.
     */
    private const MAX_DRAIN_SECONDS = 10;

    public function __construct(
        private readonly string $basePath,
    ) {
    }

    public function handle(string $name, array $config): void
    {
        $onlyApp = isset($config['app']) && (string) $config['app'] !== '' ? (string) $config['app'] : null;
        // Config only (no Application yet): the epoll server builds one per worker (reloadable),
        // the uring server builds one in this process (reload = replacement process, see ProcessManager).
        $appConfig = new Repository($this->basePath . '/config');

        /** @var array<string, mixed> $iouringConfig */
        $iouringConfig = $appConfig->get('rpc.iouring', []);
        if (!is_array($iouringConfig)) {
            $iouringConfig = [];
        }
        // Only the RPC role logs the io_uring decision (Application::bootRpc() stays quiet).
        $iouring = IoUringSupport::bootstrap($iouringConfig, log: true);
        IoUringSupport::applyFileTunables($iouringConfig);

        $host = (string) ($config['host'] ?? Env::get('RPC_HOST', '0.0.0.0'));
        $port = (int) ($config['port'] ?? Env::get('RPC_PORT', 9502));
        /** @var array<string, mixed> $settings */
        $settings = is_array($config['settings'] ?? null) ? $config['settings'] : [
            'worker_num' => 1,
            'reload_async' => true,
            'max_wait_time' => 60,
            'package_max_length' => 2 * 1024 * 1024,
        ];
        $settings = IoUringSupport::mergeServerSettings($settings, $iouring);

        $path = (string) ($appConfig->get('rpc.path', '/rpc'));
        if ($path === '') {
            $path = '/rpc';
        }

        if (IoUringSupport::usesNetworkUring($iouring)) {
            $app = new Application($this->basePath, $onlyApp);
            $this->runUringServer($host, $port, $path, $settings, $app->container());
        } else {
            $this->runEpollServer($host, $port, $path, $settings, $onlyApp, WorkerPools::serverMode($config, $settings));
        }
    }

    /**
     * @param array<string, mixed> $settings
     */
    private function runUringServer(
        string $host,
        int $port,
        string $path,
        array $settings,
        Container $container,
    ): void {
        $workerNum = max(1, (int) ($settings['worker_num'] ?? 1));

        ProcessLog::info(sprintf(
            'RPC server starting Coroutine\\Http\\Server network=uring_socket on %s:%d path=%s workers=%d',
            $host,
            $port,
            $path,
            $workerNum,
        ));

        if ($workerNum === 1) {
            $this->serveUringWorker($host, $port, $path, $settings, $container);

            return;
        }

        // Multiple workers: SO_REUSEPORT via Coroutine\Http\Server 4th ctor arg.
        $parentTag = ProcessLog::tag();
        $pool = new \Swoole\Process\Pool($workerNum);
        $pool->on('WorkerStart', function (\Swoole\Process\Pool $pool, int $workerId) use ($parentTag, $host, $port, $path, $settings, $container): void {
            ProcessLog::setTag($parentTag . '/w' . $workerId);
            ProcessLog::info(sprintf('RPC uring worker#%d pid=%d', $workerId, getmypid()));
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
        $maxDrain = max(1, min(self::MAX_DRAIN_SECONDS, (int) ($settings['max_wait_time'] ?? self::MAX_DRAIN_SECONDS)));

        Coroutine\run(function () use ($host, $port, $path, $packageMax, $maxDrain, $container): void {
            $server = new CoroutineHttpServer($host, $port, false, true);
            if ($packageMax > 0 && method_exists($server, 'set')) {
                /** @psalm-suppress InvalidArgument */
                $server->set(['package_max_length' => $packageMax]);
            }

            $inflight = 0;
            $rpcHandler = $this->makeRequestHandler($container, $path);
            $handler = static function (SwooleRequest $req, SwooleResponse $res) use ($rpcHandler, &$inflight): void {
                ++$inflight;
                try {
                    $rpcHandler($req, $res);
                } finally {
                    --$inflight;
                }
            };
            $health = static function (SwooleRequest $req, SwooleResponse $res): void {
                (new Response())->json(['status' => 'ok', 'role' => 'rpc'], 'ok', 0, 200)->send($res);
            };

            $server->handle($path, $handler);
            if ($path !== '/rpc') {
                $server->handle('/rpc', $handler);
            }
            $server->handle('/health', $health);
            $server->handle('/rpc/health', $health);

            WorkerPools::startRpcHotReload($container);

            /*
             * Graceful stop WITHOUT Coroutine\Http\Server::shutdown().
             *
             * Swoole 6.2.2 + --enable-uring-socket bug: shutdown() → Socket::cancel(SW_EVENT_READ)
             * resumes the accept coroutine directly (UringSocket does not override cancel(), so the
             * pending io_uring ACCEPT is neither cancelled nor completed). Iouring::accept() then
             * returns the event's initial result 0, uring_accept() wraps fd 0 (stdin) as a client
             * socket, `new UringSocket(conn)` sets TCP_NODELAY on it →
             *   WARNING Socket::set_option(): setsockopt(0, 6, 1, 4) failed ... non-socket[88]
             * and a bogus onAccept coroutine runs on fd 0 while the ACCEPT SQE stays orphaned.
             * (swoole-src v6.2.2: ext-src/swoole_http_server_coro.cc shutdown/start,
             *  src/network/socket.cc Socket::cancel, src/coroutine/uring_socket.cc accept,
             *  src/coroutine/iouring.cc accept/yield.)
             *
             * Instead: stop timers, drain in-flight requests (bounded), then stop the reactor with
             * Event::exit() so Coroutine\run() returns; the accept coroutine is never resumed and the
             * ring is torn down with the process. Deadlock check is disabled for that last step
             * because the (intentionally) suspended accept coroutine would otherwise be reported.
             */
            $stopping = false;
            $stop = static function (int $signo) use ($container, $maxDrain, &$inflight, &$stopping): void {
                if ($stopping) {
                    return;
                }
                $stopping = true;
                $t0 = microtime(true);
                WorkerPools::stopRpcHotReload($container);

                $pending = $inflight;
                if ($pending > 0) {
                    ProcessLog::info(sprintf('RPC stopping (%s): draining %d in-flight request(s), max %ds', self::signalName($signo), $pending, $maxDrain));
                }
                $deadline = $t0 + $maxDrain;
                while ($inflight > 0 && microtime(true) < $deadline) {
                    Coroutine::sleep(0.02);
                }
                if ($inflight > 0) {
                    ProcessLog::warn(sprintf('RPC drain timeout: %d request(s) still running, exiting anyway', $inflight));
                }
                ProcessLog::info(sprintf('RPC stopped (%s) in-flight=%d drained in %s', self::signalName($signo), $pending, ProcessLog::duration(microtime(true) - $t0)));

                Coroutine::set(['enable_deadlock_check' => false]);
                Timer::clearAll();
                Event::exit();
            };

            // Only the master's SIGTERM stops us. SIGINT (Ctrl+C hits the whole process group) is
            // swallowed: reacting to it would exit the reactor early and the master's SIGTERM that
            // follows would then kill the process by signal instead of a clean exit code 0.
            Process::signal(SIGTERM, $stop);
            Process::signal(SIGINT, static function (): void {
            });

            $server->start();
        });

        WorkerPools::close($container);
    }

    private static function signalName(int $signo): string
    {
        return match ($signo) {
            SIGTERM => 'SIGTERM',
            SIGINT => 'SIGINT',
            default => 'signal ' . $signo,
        };
    }

    /**
     * @param array<string, mixed> $settings
     */
    private function runEpollServer(
        string $host,
        int $port,
        string $path,
        array $settings,
        ?string $onlyApp,
        int $mode,
    ): void {
        $server = new HttpServer($host, $port, $mode);
        if ($settings !== []) {
            $server->set($settings);
        }

        // Application per worker: `./loongs reload` → Swoole worker reload with fresh rpc handlers.
        $basePath = $this->basePath;
        $app = WorkerPools::attachPerWorker($server, static fn (): Application => new Application($basePath, $onlyApp));
        $handler = null;
        $server->on('request', function (SwooleRequest $req, SwooleResponse $res) use ($app, $path, &$handler): void {
            if ($handler === null) {
                $current = $app();
                if ($current === null) {
                    (new Response())->json([], 'worker not booted', 503, 503)->send($res);

                    return;
                }
                $handler = $this->makeRequestHandler($current->container(), $path);
            }
            $handler($req, $res);
        });

        ProcessLog::info(sprintf(
            'RPC server starting Swoole\\Http\\Server network=epoll on %s:%d path=%s workers=%d',
            $host,
            $port,
            $path,
            (int) ($settings['worker_num'] ?? 1),
        ));
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
