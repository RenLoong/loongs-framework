<?php

declare(strict_types=1);

namespace Loongs\Rpc\Server;

use Loongs\Rpc\Contract\RpcRequest;
use Loongs\Rpc\Exception\RpcException;

/**
 * In-process handler map: service + method => callable.
 *
 * Callables receive RpcRequest and may return mixed (wrapped as ok data)
 * or an RpcResponse.
 */
final class HandlerRegistry
{
    /** @var array<string, callable(RpcRequest): mixed> */
    private array $handlers = [];

    /**
     * @param callable(RpcRequest): mixed $handler
     */
    public function register(string $service, string $method, callable $handler): void
    {
        $this->handlers[$this->key($service, $method)] = $handler;
    }

    public function has(string $service, string $method): bool
    {
        return isset($this->handlers[$this->key($service, $method)]);
    }

    /**
     * @return callable(RpcRequest): mixed
     */
    public function get(string $service, string $method): callable
    {
        $key = $this->key($service, $method);
        if (!isset($this->handlers[$key])) {
            throw RpcException::notFound("RPC handler [{$service}.{$method}] is not registered.");
        }

        return $this->handlers[$key];
    }

    /**
     * @return callable(RpcRequest): mixed
     */
    public function resolve(RpcRequest $request): callable
    {
        return $this->get($request->service, $request->method);
    }

    /**
     * @return array<string, callable(RpcRequest): mixed>
     */
    public function all(): array
    {
        return $this->handlers;
    }

    private function key(string $service, string $method): string
    {
        return strtolower($service) . '::' . $method;
    }
}
