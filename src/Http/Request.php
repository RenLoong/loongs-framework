<?php

declare(strict_types=1);

namespace Loongs\Http;

use Swoole\Http\Request as SwooleRequest;

final class Request
{
    /**
     * Duplicate request headers (Swoole hands them over as an array of values):
     * - headers that may only appear once (RFC 9110; same name list Node.js uses) keep the LAST value.
     *   That matches Swoole 6, which already collapses duplicate host / authorization / content-type /
     *   accept / user-agent to the last value before PHP sees them, so every single-value header
     *   behaves the same way;
     * - every other header is a comma-separated list (RFC 9110 §5.3): values are joined with ", ";
     * - `cookie` is joined with "; " (RFC 6265 §5.4); Swoole normally parses it into $request->cookie instead.
     * All values Swoole delivered stay available, in order, via headerValues().
     *
     * @var array<string, true>
     */
    private const array SINGLE_VALUE_HEADERS = [
        'age' => true, 'authorization' => true, 'content-length' => true, 'content-type' => true,
        'etag' => true, 'expires' => true, 'from' => true, 'host' => true,
        'if-modified-since' => true, 'if-unmodified-since' => true, 'last-modified' => true,
        'location' => true, 'max-forwards' => true, 'proxy-authorization' => true, 'referer' => true,
        'retry-after' => true, 'server' => true, 'user-agent' => true,
    ];

    /** @var array<string, string> lower-case name => combined value */
    private array $headers;

    /** @var array<string, list<string>> lower-case name => every received value, in order */
    private array $headerValues;

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

        [$this->headers, $this->headerValues] = self::normalizeHeaders($swoole->header ?? []);
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

    /** @return array<string, string> lower-case names; duplicates combined as described on SINGLE_VALUE_HEADERS */
    public function headers(): array
    {
        return $this->headers;
    }

    /**
     * Every value received for a header, in order (empty list when absent).
     * Useful when a duplicated header must be rejected rather than combined.
     *
     * @return list<string>
     */
    public function headerValues(string $key): array
    {
        return $this->headerValues[strtolower($key)] ?? [];
    }

    /**
     * Swoole gives a string per header, or an array when the client sent the same header more than once.
     *
     * @param array<array-key, mixed> $raw
     * @return array{0: array<string, string>, 1: array<string, list<string>>}
     */
    public static function normalizeHeaders(array $raw): array
    {
        $values = [];
        foreach ($raw as $key => $value) {
            $name = strtolower((string) $key);
            foreach (is_array($value) ? $value : [$value] as $item) {
                if (is_scalar($item) || $item instanceof \Stringable) {
                    $values[$name][] = (string) $item;
                }
            }
        }

        $headers = [];
        foreach ($values as $name => $list) {
            $headers[$name] = match (true) {
                isset(self::SINGLE_VALUE_HEADERS[$name]) => $list[array_key_last($list)],
                $name === 'cookie' => implode('; ', $list),
                default => implode(', ', $list),
            };
        }

        return [$headers, $values];
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
