<?php

declare(strict_types=1);

namespace Loongs\Rpc\Exception;

use RuntimeException;
use Throwable;

/**
 * Transport / protocol failures. Business errors prefer RpcResponse with code != 0.
 */
final class RpcException extends RuntimeException
{
    public function __construct(
        string $message,
        private readonly int $rpcCode = 500,
        private readonly mixed $rpcData = null,
        ?Throwable $previous = null,
    ) {
        parent::__construct($message, $rpcCode, $previous);
    }

    public function rpcCode(): int
    {
        return $this->rpcCode;
    }

    public function rpcData(): mixed
    {
        return $this->rpcData;
    }

    /**
     * Transport / gateway / timeout failures are safe to retry / failover.
     * Client errors (400/404) are not.
     */
    public function isRetryable(): bool
    {
        return in_array($this->rpcCode, [500, 502, 503, 504], true);
    }

    public static function transport(string $message, ?Throwable $previous = null): self
    {
        return new self($message, 502, null, $previous);
    }

    public static function timeout(string $message = 'RPC request timed out'): self
    {
        return new self($message, 504);
    }

    public static function notFound(string $message): self
    {
        return new self($message, 404);
    }

    public static function badRequest(string $message): self
    {
        return new self($message, 400);
    }

    public static function httpStatus(int $status, string $message): self
    {
        $code = $status >= 500 ? 502 : ($status === 404 ? 404 : 400);

        return new self($message, $code >= 500 ? 502 : $code);
    }
}
