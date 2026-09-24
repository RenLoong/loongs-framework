<?php

declare(strict_types=1);

namespace Loongs\Process\Websocket;

use Swoole\Http\Request as SwooleRequest;
use Swoole\WebSocket\Frame;
use Swoole\WebSocket\Server;

interface WebsocketHandlerInterface
{
    public function onOpen(Server $server, SwooleRequest $request): void;

    public function onMessage(Server $server, Frame $frame): void;

    public function onClose(Server $server, int $fd): void;
}
