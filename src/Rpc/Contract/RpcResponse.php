<?php

declare(strict_types=1);

namespace Loongs\Rpc\Contract;

use InvalidArgumentException;

/**
 * Synchronous JSON-RPC response envelope.
 *
 * {"code":0,"message":"ok","data":{},"id":"uuid"}
 */
final readonly class RpcResponse
{
    public function __construct(
        public int $code,
        public string $message,
        public mixed $data = null,
        public ?string $id = null,
    ) {
    }

    public static function ok(mixed $data = null, ?string $id = null, string $message = 'ok'): self
    {
        return new self(code: 0, message: $message, data: $data, id: $id);
    }

    public static function fail(
        int $code,
        string $message,
        mixed $data = null,
        ?string $id = null,
    ): self {
        if ($code === 0) {
            throw new InvalidArgumentException('RpcResponse::fail code must be non-zero.');
        }

        return new self(code: $code, message: $message, data: $data, id: $id);
    }

    /**
     * @param array<string, mixed> $data
     */
    public static function fromArray(array $data): self
    {
        return new self(
            code: (int) ($data['code'] ?? 500),
            message: (string) ($data['message'] ?? 'unknown error'),
            data: $data['data'] ?? null,
            id: isset($data['id']) ? (string) $data['id'] : null,
        );
    }

    public function isOk(): bool
    {
        return $this->code === 0;
    }

    /**
     * @return array{code: int, message: string, data: mixed, id: string|null}
     */
    public function toArray(): array
    {
        return [
            'code' => $this->code,
            'message' => $this->message,
            'data' => $this->data,
            'id' => $this->id,
        ];
    }

    public function toJson(): string
    {
        $json = json_encode($this->toArray(), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        if ($json === false) {
            return '{"code":500,"message":"json encode failed","data":null,"id":null}';
        }

        return $json;
    }
}
