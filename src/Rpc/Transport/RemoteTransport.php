<?php

declare(strict_types=1);

namespace Loongs\Rpc\Transport;

use Loongs\Rpc\Contract\RpcRequest;
use Loongs\Rpc\Contract\RpcResponse;
use Loongs\Rpc\Exception\RpcException;
use Loongs\Rpc\Support\HttpJsonTransporter;

/**
 * Cross-machine transport: HTTP JSON to configured remote host.
 * Shares HttpJsonTransporter with LoopbackTransport.
 */
final class RemoteTransport implements TransportInterface
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
        $endpoint = (string) ($serviceConfig['endpoint'] ?? '');
        if ($endpoint === '') {
            throw RpcException::badRequest(
                "Remote transport requires endpoint for service [{$request->service}]."
            );
        }

        $url = HttpJsonTransporter::resolveUrl($endpoint);

        try {
            $data = $this->http->post($url, $request->toArray(), $request->timeoutMs());
        } catch (RpcException $e) {
            throw $e;
        }

        return RpcResponse::fromArray($data);
    }
}
