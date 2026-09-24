<?php

declare(strict_types=1);

namespace Loongs\Rpc\Contract;

use InvalidArgumentException;

/**
 * Synchronous JSON-RPC request envelope.
 *
 * {"id":"uuid","service":"user","method":"User.GetById","payload":{},"meta":{}}
 */
final readonly class RpcRequest
{
    /**
     * @param array<string, mixed> $payload
     * @param array<string, mixed> $meta
     */
    public function __construct(
        public string $id,
        public string $service,
        public string $method,
        public array $payload = [],
        public array $meta = [],
    ) {
        if ($this->id === '') {
            throw new InvalidArgumentException('RpcRequest.id must not be empty.');
        }
        if ($this->service === '') {
            throw new InvalidArgumentException('RpcRequest.service must not be empty.');
        }
        if ($this->method === '') {
            throw new InvalidArgumentException('RpcRequest.method must not be empty.');
        }
    }

    /**
     * @param array<string, mixed> $payload
     * @param array<string, mixed> $meta
     */
    public static function make(
        string $service,
        string $method,
        array $payload = [],
        array $meta = [],
        ?string $id = null,
    ): self {
        return new self(
            id: $id ?? self::generateId(),
            service: $service,
            method: $method,
            payload: $payload,
            meta: $meta,
        );
    }

    /**
     * @param array<string, mixed> $data
     */
    public static function fromArray(array $data): self
    {
        $payload = $data['payload'] ?? [];
        $meta = $data['meta'] ?? [];

        if (!is_array($payload)) {
            throw new InvalidArgumentException('RpcRequest.payload must be an array.');
        }
        if (!is_array($meta)) {
            throw new InvalidArgumentException('RpcRequest.meta must be an array.');
        }

        return new self(
            id: (string) ($data['id'] ?? self::generateId()),
            service: (string) ($data['service'] ?? ''),
            method: (string) ($data['method'] ?? ''),
            payload: $payload,
            meta: $meta,
        );
    }

    /**
     * @return array{id: string, service: string, method: string, payload: array<string, mixed>, meta: array<string, mixed>}
     */
    public function toArray(): array
    {
        return [
            'id' => $this->id,
            'service' => $this->service,
            'method' => $this->method,
            'payload' => $this->payload,
            'meta' => $this->meta,
        ];
    }

    public function toJson(): string
    {
        $json = json_encode($this->toArray(), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        if ($json === false) {
            throw new InvalidArgumentException('Failed to encode RpcRequest to JSON.');
        }

        return $json;
    }

    public function timeoutMs(int $default = 3000): int
    {
        $value = $this->meta['timeout_ms'] ?? $default;
        $ms = (int) $value;

        return $ms > 0 ? $ms : $default;
    }

    public function traceId(): ?string
    {
        $trace = $this->meta['trace_id'] ?? null;

        return $trace === null || $trace === '' ? null : (string) $trace;
    }

    public static function generateId(): string
    {
        $bytes = random_bytes(16);
        $bytes[6] = chr((ord($bytes[6]) & 0x0f) | 0x40);
        $bytes[8] = chr((ord($bytes[8]) & 0x3f) | 0x80);

        $hex = bin2hex($bytes);

        return sprintf(
            '%s-%s-%s-%s-%s',
            substr($hex, 0, 8),
            substr($hex, 8, 4),
            substr($hex, 12, 4),
            substr($hex, 16, 4),
            substr($hex, 20, 12),
        );
    }
}
