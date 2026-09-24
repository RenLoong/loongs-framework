<?php

declare(strict_types=1);

namespace Loongs\Rpc\Transport;

use Loongs\Rpc\Contract\RpcRequest;
use Loongs\Rpc\Contract\RpcResponse;
use Loongs\Rpc\Exception\RpcException;
use Loongs\Rpc\Support\HttpJsonTransporter;

/**
 * Same-machine, different process: HTTP JSON to 127.0.0.1 (or configured loopback endpoint).
 */
final class LoopbackTransport implements TransportInterface
{
    public function __construct(
        private readonly HttpJsonTransporter $http = new HttpJsonTransporter(),
    ) {
    }

    /**
     * @param array<string, mixed> $serviceConfig
     */
    public function send(RpcRequest $request, array $serviceConfig = []): RpcResponse
    {
        $endpoint = (string) ($serviceConfig['endpoint'] ?? 'http://127.0.0.1:9501');
        $url = HttpJsonTransporter::resolveUrl($endpoint);

        if (!str_contains($url, '127.0.0.1') && !str_contains($url, 'localhost')) {
            // Soft preference: loopback should target local host; still allow explicit override.
        }

        try {
            $data = $this->http->post($url, $request->toArray(), $request->timeoutMs());
        } catch (RpcException $e) {
            throw $e;
        }

        return RpcResponse::fromArray($data);
    }
}
