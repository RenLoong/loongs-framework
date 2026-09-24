<?php

declare(strict_types=1);

namespace Loongs\Rpc\Client;

use Loongs\Rpc\Contract\RpcResponse;

interface RpcClientInterface
{
    /**
     * @param array<string, mixed> $payload
     * @param array<string, mixed> $meta
     */
    public function call(
        string $service,
        string $method,
        array $payload = [],
        array $meta = [],
    ): RpcResponse;
}
