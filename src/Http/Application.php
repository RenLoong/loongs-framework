<?php

declare(strict_types=1);

namespace Loongs\Http;

use Loongs\App\AppConfig;
use Loongs\App\AppDiscovery;
use Loongs\App\AppMiddlewareResolver;
use Loongs\Config\Repository;
use Loongs\Redis\RedisManager;
use Loongs\Database\DatabaseManager;
use Loongs\Container\Container;
use Loongs\Middleware\Pipeline;
use Loongs\Middleware\RequestIdMiddleware;
use Loongs\Middleware\ServeAppPublicMiddleware;
use Loongs\Process\Queue\QueueManager;
use Loongs\Process\WorkerPools;
use Loongs\Routing\Router;
use Loongs\Rpc\Client\RpcClient;
use Loongs\Rpc\Client\RpcClientInterface;
use Loongs\Rpc\Contract\RpcRequest;
use Loongs\Rpc\Contract\RpcResponse;
use Loongs\Rpc\Discovery\ConfigServiceDiscovery;
use Loongs\Rpc\Discovery\ServiceDiscoveryInterface;
use Loongs\Rpc\Registry\ServiceRegistry;
use Loongs\Rpc\Retry\RetryPolicy;
use Loongs\Rpc\Server\HandlerRegistry;
use Loongs\Rpc\Server\RpcServer;
use Loongs\Rpc\Support\HttpJsonTransporter;
use Loongs\Rpc\Support\IoUringSupport;
use Loongs\Rpc\Transport\LocalTransport;
use Loongs\Rpc\Transport\LoopbackTransport;
use Loongs\Rpc\Transport\RemoteTransport;
use Loongs\Support\BasePath;
use Loongs\Support\Env;
use Loongs\Template\PhpEngine;
use Loongs\Template\SpaRenderer;
use Loongs\Template\Template;
use Loongs\Template\ViewFactory;
use Swoole\Http\Request as SwooleRequest;
use Swoole\Http\Response as SwooleResponse;
use Swoole\Http\Server;

final class Application
{
    private Container $container;
    private Repository $config;
    private Router $router;
    private Kernel $kernel;
    private string $basePath;

    /** When set, AppDiscovery only loads this app's routes/rpc handlers. */
    private ?string $onlyApp = null;

    public function __construct(string $basePath, ?string $onlyApp = null)
    {
        $this->basePath = rtrim($basePath, '/\\');
        BasePath::set($this->basePath);
        $this->onlyApp = ($onlyApp !== null && $onlyApp !== '') ? $onlyApp : null;
        $this->container = new Container();
        $this->boot();
        $GLOBALS['__loongs_app'] = $this;
    }

    public function onlyApp(): ?string
    {
        return $this->onlyApp;
    }

    private function boot(): void
    {
        $this->loadEnv();

        $this->config = new Repository($this->basePath . '/config');
        $this->container->instance(Container::class, $this->container);
        $this->container->instance(Repository::class, $this->config);
        $this->container->instance(Application::class, $this);

        $appConfig = new AppConfig($this->basePath . '/apps');
        $this->container->instance(AppConfig::class, $appConfig);

        $this->router = new Router($this->container);
        $this->container->instance(Router::class, $this->router);

        $pipeline = new Pipeline($this->container);
        $this->container->instance(Pipeline::class, $pipeline);
        $this->router->setPipeline($pipeline);

        $appsPath = $this->basePath . '/apps';
        $appMiddleware = new AppMiddlewareResolver($appsPath);
        $this->container->instance(AppMiddlewareResolver::class, $appMiddleware);
        $this->router->setAppMiddlewareResolver($appMiddleware);

        $viewFactory = new ViewFactory($this->basePath);
        $phpEngine = new PhpEngine($viewFactory);
        $spaRenderer = new SpaRenderer($viewFactory);
        $template = new Template($viewFactory, $phpEngine, $spaRenderer);
        $this->container->instance(ViewFactory::class, $viewFactory);
        $this->container->instance(PhpEngine::class, $phpEngine);
        $this->container->instance(SpaRenderer::class, $spaRenderer);
        $this->container->instance(Template::class, $template);

        $this->kernel = new Kernel($this->router, $pipeline);
        $this->kernel->setMiddleware([
            RequestIdMiddleware::class,
            ServeAppPublicMiddleware::class,
        ]);
        $this->container->instance(Kernel::class, $this->kernel);

        // Bind ServeAppPublic with appsPath (constructor needs string)
        $this->container->singleton(ServeAppPublicMiddleware::class, static function () use ($appsPath): ServeAppPublicMiddleware {
            return new ServeAppPublicMiddleware($appsPath);
        });

        $this->bootRpc();

        $this->container->singleton(DatabaseManager::class, function (): DatabaseManager {
            return new DatabaseManager($this->config);
        });
        $this->container->singleton(RedisManager::class, function (): RedisManager {
            return new RedisManager($this->config);
        });

        $this->container->singleton(QueueManager::class, function (): QueueManager {
            /** @var RedisManager $redis */
            $redis = $this->container->make(RedisManager::class);

            return new QueueManager($redis, $this->config);
        });

        // Optional loongs/cache — register only when the package is installed.
        if (class_exists(\Loongs\Cache\CacheManager::class)) {
            $this->container->singleton(\Loongs\Cache\CacheManager::class, function (): \Loongs\Cache\CacheManager {
                $redis = null;
                if (class_exists(RedisManager::class)) {
                    $redis = $this->container->make(RedisManager::class);
                }
                /** @var mixed $cfg */
                $cfg = $this->config->get('cache', []);
                return new \Loongs\Cache\CacheManager(
                    is_array($cfg) ? $cfg : [],
                    $redis,
                    $this->basePath,
                );
            });
        }
        $this->loadRoutes();
    }

    private function loadRoutes(): void
    {
        $router = $this->router;
        $app = $this;
        $container = $this->container;

        $routesDir = $this->basePath . '/routes';
        if (is_dir($routesDir)) {
            $files = glob($routesDir . '/*.php') ?: [];
            sort($files, SORT_STRING);
            foreach ($files as $routesFile) {
                require $routesFile;
            }
        }

        $discovery = new AppDiscovery($this->basePath . '/apps');
        $this->container->instance(AppDiscovery::class, $discovery);
        $discovery->registerRoutes($router, $app, $container, $this->onlyApp);

        /** @var HandlerRegistry $handlerRegistry */
        $handlerRegistry = $this->container->make(HandlerRegistry::class);
        $discovery->registerRpcHandlers($handlerRegistry, $app, $container, $this->onlyApp);
    }

    private function bootRpc(): void
    {
        /** @var array<string, mixed> $iouringConfig */
        $iouringConfig = $this->config->get('rpc.iouring', []);
        if (!is_array($iouringConfig)) {
            $iouringConfig = [];
        }
        IoUringSupport::bootstrap($iouringConfig);

        $handlerRegistry = new HandlerRegistry();
        $this->container->instance(HandlerRegistry::class, $handlerRegistry);

        /** @var array<string, array<string, mixed>> $services */
        $services = $this->config->get('rpc.services', []);
        if (!is_array($services)) {
            $services = [];
        }

        $discovery = new ConfigServiceDiscovery($services);
        $this->container->instance(ConfigServiceDiscovery::class, $discovery);
        $this->container->instance(ServiceDiscoveryInterface::class, $discovery);

        $serviceRegistry = new ServiceRegistry($discovery);
        $this->container->instance(ServiceRegistry::class, $serviceRegistry);

        $rpcServer = new RpcServer($handlerRegistry);
        $this->container->instance(RpcServer::class, $rpcServer);

        $http = new HttpJsonTransporter();
        $this->container->instance(HttpJsonTransporter::class, $http);

        $local = new LocalTransport($rpcServer);
        $loopback = new LoopbackTransport($http);
        $remote = new RemoteTransport($http);
        $this->container->instance(LocalTransport::class, $local);
        $this->container->instance(LoopbackTransport::class, $loopback);
        $this->container->instance(RemoteTransport::class, $remote);

        /** @var array<string, mixed> $retryConfig */
        $retryConfig = $this->config->get('rpc.retry', []);
        if (!is_array($retryConfig)) {
            $retryConfig = [];
        }
        $defaultTimeout = (int) $this->config->get('rpc.default_timeout_ms', 3000);
        $retryPolicy = RetryPolicy::fromConfig($retryConfig, $defaultTimeout > 0 ? $defaultTimeout : 3000);
        $this->container->instance(RetryPolicy::class, $retryPolicy);

        $client = new RpcClient($discovery, $local, $loopback, $remote, $retryPolicy);
        $this->container->instance(RpcClient::class, $client);
        $this->container->instance(RpcClientInterface::class, $client);

        if (filter_var($this->config->get('rpc.register_demo_handlers', true), FILTER_VALIDATE_BOOLEAN)) {
            $this->registerDemoRpcHandlers($handlerRegistry);
        }
    }

    private function registerDemoRpcHandlers(HandlerRegistry $handlers): void
    {
        $handlers->register('demo', 'Demo.Ping', static function (RpcRequest $request): array {
            return [
                'pong' => true,
                'echo' => $request->payload,
                'service' => $request->service,
                'method' => $request->method,
            ];
        });

        $handlers->register('demo', 'Demo.Echo', static function (RpcRequest $request): RpcResponse {
            return RpcResponse::ok([
                'echo' => $request->payload,
            ], $request->id);
        });
    }

    private function loadEnv(): void
    {
        Env::load($this->basePath . '/.env');
    }

    public function container(): Container
    {
        return $this->container;
    }

    public function config(): Repository
    {
        return $this->config;
    }

    public function router(): Router
    {
        return $this->router;
    }

    public function kernel(): Kernel
    {
        return $this->kernel;
    }

    public function basePath(string $path = ''): string
    {
        return $path === '' ? $this->basePath : $this->basePath . '/' . ltrim($path, '/\\');
    }

    /**
     * @param array<string, mixed>|null $settings
     */
    public function createServer(?string $host = null, ?int $port = null, ?array $settings = null): Server
    {
        $host = $host ?? (string) $this->config->get(
            'process.processes.http.host',
            Env::get('HTTP_HOST', '0.0.0.0'),
        );
        $port = $port ?? (int) $this->config->get(
            'process.processes.http.port',
            Env::get('HTTP_PORT', 9501),
        );

        $server = new Server($host, $port);

        if ($settings === null) {
            $cfgSettings = $this->config->get('process.processes.http.settings', []);
            $settings = is_array($cfgSettings) ? $cfgSettings : [];
        }
        if ($settings !== []) {
            $server->set($settings);
        }

        $kernel = $this->kernel;
        WorkerPools::attach($server, $this->container);

        $server->on('request', function (SwooleRequest $swooleReq, SwooleResponse $swooleRes) use ($kernel): void {
            try {
                $request = new Request($swooleReq);
                $response = $kernel->handle($request);
                $response->send($swooleRes);
            } catch (\Throwable $e) {
                $error = (new Response())->json(
                    data: [
                        'exception' => $e::class,
                        'message' => $e->getMessage(),
                    ],
                    message: 'Internal Server Error',
                    code: 500,
                    httpStatus: 500,
                );
                $error->send($swooleRes);
            }
        });

        return $server;
    }

    public function run(): void
    {
        $server = $this->createServer();
        $host = (string) $this->config->get('process.processes.http.host', Env::get('HTTP_HOST', '0.0.0.0'));
        $port = (int) $this->config->get('process.processes.http.port', Env::get('HTTP_PORT', 9501));
        echo sprintf("[%s] HTTP server starting on %s:%d\n", date('Y-m-d H:i:s'), $host, $port);
        $server->start();
    }
}
