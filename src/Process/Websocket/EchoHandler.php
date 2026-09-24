<?php

declare(strict_types=1);

namespace Loongs\Process\Websocket;

use Swoole\Http\Request as SwooleRequest;
use Swoole\WebSocket\Frame;
use Swoole\WebSocket\Server;

/**
 * Default WebSocket handler: responds to ping and echoes other text frames.
 */
final class EchoHandler implements WebsocketHandlerInterface
{
    public function onOpen(Server $server, SwooleRequest $request): void
    {
        $server->push($request->fd, json_encode([
            'event' => 'open',
            'fd' => $request->fd,
        ], JSON_UNESCAPED_UNICODE));
    }

    public function onMessage(Server $server, Frame $frame): void
    {
        $payload = (string) $frame->data;
        if (strcasecmp(trim($payload), 'ping') === 0) {
            $server->push($frame->fd, 'pong');

            return;
        }

        $server->push($frame->fd, $payload);
    }

    public function onClose(Server $server, int $fd): void
    {
        // no-op
    }
}
