<?php

declare(strict_types=1);

namespace Loongs\Http;

use Swoole\Http\Request as SwooleRequest;

final class Request
{
    /** @var array<string, string> */
    private array $headers;

    /** @var array<string, mixed> */
    private array $query;

    /** @var array<string, mixed> */
    private array $post;

    private string $body;

    private string $method;

    private string $path;

    /** @var array<string, mixed> */
    private array $attributes = [];

    public function __construct(private readonly SwooleRequest $swoole)
    {
        $server = $swoole->server ?? [];
        $this->method = strtoupper((string) ($server['request_method'] ?? 'GET'));
        $uri = (string) ($server['request_uri'] ?? '/');
        $this->path = parse_url($uri, PHP_URL_PATH) ?: '/';
        $this->query = $swoole->get ?? [];
        $this->post = $swoole->post ?? [];
        $this->body = (string) ($swoole->rawContent() ?: '');

        $rawHeaders = $swoole->header ?? [];
        $this->headers = [];
        foreach ($rawHeaders as $key => $value) {
            $this->headers[strtolower((string) $key)] = (string) $value;
        }
    }

    public function method(): string
    {
        return $this->method;
    }

    public function path(): string
    {
        return $this->path;
    }

    public function query(string $key, mixed $default = null): mixed
    {
        return $this->query[$key] ?? $default;
    }

    /** @return array<string, mixed> */
    public function queryAll(): array
    {
        return $this->query;
    }

    public function input(string $key, mixed $default = null): mixed
    {
        return $this->post[$key] ?? $this->query[$key] ?? $default;
    }

    /** @return array<string, mixed> */
    public function postAll(): array
    {
        return $this->post;
    }

    public function body(): string
    {
        return $this->body;
    }

    /** @return array<string, mixed>|null */
    public function json(): ?array
    {
        if ($this->body === '') {
            return null;
        }
        /** @var mixed $decoded */
        $decoded = json_decode($this->body, true);
        return is_array($decoded) ? $decoded : null;
    }

    public function header(string $key, ?string $default = null): ?string
    {
        return $this->headers[strtolower($key)] ?? $default;
    }

    /** @return array<string, string> */
    public function headers(): array
    {
        return $this->headers;
    }

    public function swoole(): SwooleRequest
    {
        return $this->swoole;
    }

    public function setAttribute(string $key, mixed $value): void
    {
        $this->attributes[$key] = $value;
    }

    public function getAttribute(string $key, mixed $default = null): mixed
    {
        return $this->attributes[$key] ?? $default;
    }
}
