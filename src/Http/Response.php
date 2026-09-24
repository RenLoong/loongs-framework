<?php

declare(strict_types=1);

namespace Loongs\Http;

use Loongs\Middleware\ServeAppPublicMiddleware;
use RuntimeException;
use Swoole\Http\Response as SwooleResponse;

final class Response
{
    private int $status = 200;

    /** @var array<string, string> */
    private array $headers = [
        'Content-Type' => 'application/json; charset=utf-8',
    ];

    private string $content = '';

    public function status(int $code): self
    {
        $this->status = $code;
        return $this;
    }

    public function header(string $name, string $value): self
    {
        $this->headers[$name] = $value;
        return $this;
    }

    /**
     * Standard JSON API shape: {code, message, data}
     */
    public function json(mixed $data = null, string $message = 'ok', int $code = 0, int $httpStatus = 200): self
    {
        $this->status = $httpStatus;
        $this->headers['Content-Type'] = 'application/json; charset=utf-8';
        $this->content = json_encode(
            [
                'code' => $code,
                'message' => $message,
                'data' => $data,
            ],
            JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES
        ) ?: '{"code":500,"message":"json encode failed","data":null}';

        return $this;
    }

    public function html(string $html, int $status = 200): self
    {
        $this->status = $status;
        return $this->raw($html, 'text/html; charset=utf-8');
    }

    public function raw(string $content, string $contentType = 'text/plain; charset=utf-8'): self
    {
        $this->headers['Content-Type'] = $contentType;
        $this->content = $content;
        return $this;
    }

    /**
     * Serve a local file with detected MIME type.
     */
    public static function file(string $path, int $status = 200): self
    {
        if (!is_file($path) || !is_readable($path)) {
            throw new RuntimeException('File not readable: ' . $path);
        }

        $bytes = file_get_contents($path);
        if ($bytes === false) {
            throw new RuntimeException('Unable to read file: ' . $path);
        }

        $response = new self();
        $response->status = $status;
        $response->headers['Content-Type'] = ServeAppPublicMiddleware::mimeFor($path);
        $response->headers['Content-Length'] = (string) strlen($bytes);
        $response->content = $bytes;

        return $response;
    }

    public function getStatus(): int
    {
        return $this->status;
    }

    public function getContent(): string
    {
        return $this->content;
    }

    /** @return array<string, string> */
    public function getHeaders(): array
    {
        return $this->headers;
    }

    public function send(SwooleResponse $swoole): void
    {
        $swoole->status($this->status);
        foreach ($this->headers as $name => $value) {
            $swoole->header($name, $value);
        }
        $swoole->end($this->content);
    }
}
