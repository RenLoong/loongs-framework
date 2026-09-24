<?php

declare(strict_types=1);

namespace Loongs\Rpc\Transport;

use Loongs\Rpc\Contract\RpcRequest;
use Loongs\Rpc\Contract\RpcResponse;

interface TransportInterface
{
    /**
     * @param array<string, mixed> $serviceConfig
     */
    public function send(RpcRequest $request, array $serviceConfig = []): RpcResponse;
}
