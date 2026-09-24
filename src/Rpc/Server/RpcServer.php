<?php

declare(strict_types=1);

namespace Loongs\Rpc\Server;

use Loongs\Rpc\Contract\RpcRequest;
use Loongs\Rpc\Contract\RpcResponse;
use Loongs\Rpc\Exception\RpcException;
use Throwable;

final class RpcServer
{
    public function __construct(
        private readonly HandlerRegistry $handlers,
    ) {
    }

    public function handle(RpcRequest $request): RpcResponse
    {
        try {
            $handler = $this->handlers->resolve($request);
            $result = $handler($request);

            if ($result instanceof RpcResponse) {
                return new RpcResponse(
                    code: $result->code,
                    message: $result->message,
                    data: $result->data,
                    id: $result->id ?? $request->id,
                );
            }

            return RpcResponse::ok($result, $request->id);
        } catch (RpcException $e) {
            return RpcResponse::fail(
                code: $e->rpcCode(),
                message: $e->getMessage(),
                data: $e->rpcData(),
                id: $request->id,
            );
        } catch (Throwable $e) {
            return RpcResponse::fail(
                code: 500,
                message: $e->getMessage() !== '' ? $e->getMessage() : 'Internal RPC error',
                data: null,
                id: $request->id,
            );
        }
    }

    /**
     * Handle a raw JSON body (HTTP /rpc endpoint).
     */
    public function handleJson(string $json): RpcResponse
    {
        if ($json === '') {
            return RpcResponse::fail(400, 'Empty RPC body');
        }

        /** @var mixed $decoded */
        $decoded = json_decode($json, true);
        if (!is_array($decoded)) {
            return RpcResponse::fail(400, 'Invalid JSON RPC body');
        }

        try {
            $request = RpcRequest::fromArray($decoded);
        } catch (Throwable $e) {
            return RpcResponse::fail(
                400,
                $e->getMessage() !== '' ? $e->getMessage() : 'Invalid RPC request',
                id: isset($decoded['id']) ? (string) $decoded['id'] : null,
            );
        }

        return $this->handle($request);
    }

    public function handlers(): HandlerRegistry
    {
        return $this->handlers;
    }
}
