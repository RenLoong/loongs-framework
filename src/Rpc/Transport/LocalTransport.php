<?php

declare(strict_types=1);

namespace Loongs\Rpc\Transport;

use Loongs\Rpc\Contract\RpcRequest;
use Loongs\Rpc\Contract\RpcResponse;
use Loongs\Rpc\Server\RpcServer;

/**
 * Same-process transport. Still goes through RpcServer + HandlerRegistry
 * (protocol path), never short-circuits to foreign app classes from caller code.
 */
final class LocalTransport implements TransportInterface
{
    public function __construct(
        private readonly RpcServer $server,
    ) {
    }

    /**
     * @param array<string, mixed> $serviceConfig
     */
    public function send(RpcRequest $request, array $serviceConfig = []): RpcResponse
    {
        // Round-trip through envelope encode/decode so Local matches other transports.
        $encoded = $request->toJson();
        /** @var array<string, mixed> $decoded */
        $decoded = json_decode($encoded, true, 512, JSON_THROW_ON_ERROR);
        $normalized = RpcRequest::fromArray($decoded);

        return $this->server->handle($normalized);
    }
}
