<?php

declare(strict_types=1);

namespace Loongs\Process\Role;

use Loongs\Process\ProcessLog;
use Loongs\Http\Application;
use Loongs\Process\ProcessInterface;
use Loongs\Process\Websocket\EchoHandler;
use Loongs\Process\Websocket\WebsocketHandlerInterface;
use Loongs\Process\WorkerPools;
use Loongs\Support\Env;
use Swoole\Http\Request as SwooleRequest;
use Swoole\WebSocket\Frame;
use Swoole\WebSocket\Server;

final class WebsocketProcess implements ProcessInterface
{
    public function __construct(
        private readonly string $basePath,
    ) {
    }

    public function handle(string $name, array $config): void
    {
        $onlyApp = (isset($config['app']) && (string) $config['app'] !== '') ? (string) $config['app'] : null;

        $host = (string) ($config['host'] ?? Env::get('WS_HOST', '0.0.0.0'));
        $port = (int) ($config['port'] ?? Env::get('WS_PORT', 9503));
        /** @var array<string, mixed> $settings */
        $settings = is_array($config['settings'] ?? null) ? $config['settings'] : [
            'worker_num' => 1,
            'reload_async' => true,
        ];

        $handlerClass = (string) ($config['handler'] ?? EchoHandler::class);

        $server = new Server($host, $port, WorkerPools::serverMode($config, $settings, preferProcess: true));
        if ($settings !== []) {
            $server->set($settings);
        }

        // Application + handler per worker (not before the fork): `./loongs reload` → fresh code.
        $basePath = $this->basePath;
        $app = WorkerPools::attachPerWorker($server, static fn (): Application => new Application($basePath, $onlyApp));
        $handler = null;
        $resolve = static function () use ($app, $handlerClass, &$handler): WebsocketHandlerInterface {
            if ($handler === null) {
                $class = class_exists($handlerClass) && is_subclass_of($handlerClass, WebsocketHandlerInterface::class) ? $handlerClass : EchoHandler::class;
                $container = $app()?->container();
                /** @var WebsocketHandlerInterface $made */
                $made = $container !== null && $container->has($class) ? $container->make($class) : new $class();
                $handler = $made;
            }

            return $handler;
        };

        $server->on('open', static function (Server $server, SwooleRequest $request) use ($resolve): void {
            $resolve()->onOpen($server, $request);
        });

        $server->on('message', static function (Server $server, Frame $frame) use ($resolve): void {
            $resolve()->onMessage($server, $frame);
        });

        $server->on('close', static function (Server $server, int $fd) use ($resolve): void {
            $resolve()->onClose($server, $fd);
        });

        ProcessLog::info(sprintf('WebSocket server starting on %s:%d handler=%s', $host, $port, $handlerClass));
        $server->start();
    }
}
