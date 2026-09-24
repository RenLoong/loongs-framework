<?php

declare(strict_types=1);

namespace Loongs\Process\Role;

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
        $app = new Application($this->basePath, (isset($config['app']) && (string) $config['app'] !== '') ? (string) $config['app'] : null);
        $container = $app->container();

        $host = (string) ($config['host'] ?? Env::get('WS_HOST', '0.0.0.0'));
        $port = (int) ($config['port'] ?? Env::get('WS_PORT', 9503));
        /** @var array<string, mixed> $settings */
        $settings = is_array($config['settings'] ?? null) ? $config['settings'] : [
            'worker_num' => 1,
            'reload_async' => true,
        ];

        $handlerClass = (string) ($config['handler'] ?? EchoHandler::class);
        if (!class_exists($handlerClass) || !is_subclass_of($handlerClass, WebsocketHandlerInterface::class)) {
            $handlerClass = EchoHandler::class;
        }

        /** @var WebsocketHandlerInterface $handler */
        $handler = $container->has($handlerClass)
            ? $container->make($handlerClass)
            : new $handlerClass();

        $server = new Server($host, $port);
        if ($settings !== []) {
            $server->set($settings);
        }

        WorkerPools::attach($server, $container);

        $server->on('open', static function (Server $server, SwooleRequest $request) use ($handler): void {
            $handler->onOpen($server, $request);
        });

        $server->on('message', static function (Server $server, Frame $frame) use ($handler): void {
            $handler->onMessage($server, $frame);
        });

        $server->on('close', static function (Server $server, int $fd) use ($handler): void {
            $handler->onClose($server, $fd);
        });

        echo sprintf("[%s] WebSocket process [%s] starting on %s:%d handler=%s\n", date('Y-m-d H:i:s'), $name, $host, $port, $handlerClass);
        $server->start();
    }
}
