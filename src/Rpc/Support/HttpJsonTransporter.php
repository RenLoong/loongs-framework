<?php

declare(strict_types=1);

namespace Loongs\Rpc\Support;

use Loongs\Rpc\Exception\RpcException;
use Swoole\Coroutine;
use Swoole\Coroutine\Http\Client as CoroutineHttpClient;

/**
 * Shared HTTP JSON POST helper for Loopback / Remote transports.
 *
 * Prefer Swoole\Coroutine\Http\Client (UringSocket when --enable-uring-socket)
 * when IoUringSupport::usesNetworkUring() is true; otherwise curl / stream.
 * Idle coroutine clients are pooled per host:port for keep-alive reuse.
 */
final class HttpJsonTransporter
{
    private const DEFAULT_TIMEOUT_MS = 3000;

    private const POOL_MAX_PER_HOST = 32;

    /** @var array<string, list<CoroutineHttpClient>> */
    private static array $pool = [];

    /**
     * @param array<string, mixed> $payload
     * @return array<string, mixed>
     */
    public function post(string $url, array $payload, int $timeoutMs = self::DEFAULT_TIMEOUT_MS): array
    {
        if ($url === '') {
            throw RpcException::badRequest('RPC endpoint URL must not be empty.');
        }

        $timeoutMs = $timeoutMs > 0 ? $timeoutMs : self::DEFAULT_TIMEOUT_MS;
        $body = json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        if ($body === false) {
            throw RpcException::badRequest('Failed to encode RPC payload.');
        }

        if (IoUringSupport::usesNetworkUring()) {
            return $this->postWithCoroutineClient($url, $body, $timeoutMs);
        }

        if (function_exists('curl_init')) {
            return $this->postWithCurl($url, $body, $timeoutMs);
        }

        return $this->postWithStream($url, $body, $timeoutMs);
    }

    /**
     * @return array<string, mixed>
     */
    private function postWithCoroutineClient(string $url, string $body, int $timeoutMs): array
    {
        $runner = function () use ($url, $body, $timeoutMs): array {
            $parts = self::parseUrl($url);
            $client = self::borrowClient($parts['host'], $parts['port'], $parts['ssl'], $timeoutMs);
            $ok = false;
            try {
                $client->setHeaders([
                    'Content-Type' => 'application/json; charset=utf-8',
                    'Accept' => 'application/json',
                    'Connection' => 'keep-alive',
                ]);
                $posted = $client->post($parts['path'], $body);
                $status = (int) $client->statusCode;
                $raw = (string) $client->body;
                $errCode = (int) $client->errCode;

                if ($posted === false || $status < 0 || $errCode !== 0) {
                    $msg = $client->errMsg !== '' ? $client->errMsg : "errCode={$errCode}";
                    if ($errCode === 110 || stripos($msg, 'timeout') !== false) {
                        throw RpcException::timeout("RPC HTTP timeout after {$timeoutMs}ms: {$msg}");
                    }
                    throw RpcException::transport("RPC coroutine HTTP failed: {$msg}");
                }

                $decoded = $this->decodeResponse($raw, $status);
                $ok = true;

                return $decoded;
            } finally {
                self::releaseClient($client, $parts['host'], $parts['port'], $parts['ssl'], $ok);
            }
        };

        if (Coroutine::getCid() >= 0) {
            return $runner();
        }

        $result = null;
        $error = null;
        \Swoole\Coroutine\run(static function () use ($runner, &$result, &$error): void {
            try {
                $result = $runner();
            } catch (\Throwable $e) {
                $error = $e;
            }
        });

        if ($error instanceof \Throwable) {
            throw $error;
        }

        /** @var array<string, mixed> $result */
        return $result ?? [];
    }

    /**
     * @return array{host:string,port:int,ssl:bool,path:string}
     */
    private static function parseUrl(string $url): array
    {
        $parts = parse_url($url);
        if ($parts === false || !isset($parts['host'])) {
            throw RpcException::badRequest("Invalid RPC URL [{$url}].");
        }

        $scheme = strtolower((string) ($parts['scheme'] ?? 'http'));
        $ssl = $scheme === 'https';
        $port = isset($parts['port']) ? (int) $parts['port'] : ($ssl ? 443 : 80);
        $path = (string) ($parts['path'] ?? '/');
        if ($path === '') {
            $path = '/';
        }
        if (isset($parts['query']) && $parts['query'] !== '') {
            $path .= '?' . $parts['query'];
        }

        return [
            'host' => (string) $parts['host'],
            'port' => $port,
            'ssl' => $ssl,
            'path' => $path,
        ];
    }

    private static function poolKey(string $host, int $port, bool $ssl): string
    {
        return ($ssl ? 'https' : 'http') . "://{$host}:{$port}";
    }

    private static function borrowClient(string $host, int $port, bool $ssl, int $timeoutMs): CoroutineHttpClient
    {
        $key = self::poolKey($host, $port, $ssl);
        if (!empty(self::$pool[$key])) {
            /** @var CoroutineHttpClient $client */
            $client = array_pop(self::$pool[$key]);
            $client->set(['timeout' => max(0.001, $timeoutMs / 1000)]);

            return $client;
        }

        $client = new CoroutineHttpClient($host, $port, $ssl);
        $client->set([
            'timeout' => max(0.001, $timeoutMs / 1000),
            'keep_alive' => true,
        ]);

        return $client;
    }

    private static function releaseClient(
        CoroutineHttpClient $client,
        string $host,
        int $port,
        bool $ssl,
        bool $reusable,
    ): void {
        $key = self::poolKey($host, $port, $ssl);
        if (!$reusable || $client->errCode !== 0 || $client->statusCode < 0) {
            $client->close();

            return;
        }

        $bucket = self::$pool[$key] ?? [];
        if (count($bucket) >= self::POOL_MAX_PER_HOST) {
            $client->close();

            return;
        }

        $bucket[] = $client;
        self::$pool[$key] = $bucket;
    }

    /**
     * @return array<string, mixed>
     */
    private function postWithCurl(string $url, string $body, int $timeoutMs): array
    {
        $ch = curl_init($url);
        if ($ch === false) {
            throw RpcException::transport('Failed to initialize curl.');
        }

        $connectTimeoutMs = max(1, min($timeoutMs, 5000));

        curl_setopt_array($ch, [
            CURLOPT_POST => true,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_HTTPHEADER => [
                'Content-Type: application/json; charset=utf-8',
                'Accept: application/json',
            ],
            CURLOPT_POSTFIELDS => $body,
            CURLOPT_TIMEOUT_MS => $timeoutMs,
            CURLOPT_CONNECTTIMEOUT_MS => $connectTimeoutMs,
            CURLOPT_FOLLOWLOCATION => false,
        ]);

        $raw = curl_exec($ch);
        $errno = curl_errno($ch);
        $error = curl_error($ch);
        $status = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        if ($raw === false) {
            if ($errno === CURLE_OPERATION_TIMEDOUT || str_contains(strtolower($error), 'timed out')) {
                throw RpcException::timeout("RPC HTTP timeout after {$timeoutMs}ms: {$error}");
            }
            throw RpcException::transport("RPC HTTP request failed: {$error}", null);
        }

        return $this->decodeResponse((string) $raw, $status);
    }

    /**
     * @return array<string, mixed>
     */
    private function postWithStream(string $url, string $body, int $timeoutMs): array
    {
        $timeoutSec = max(1.0, $timeoutMs / 1000);
        $context = stream_context_create([
            'http' => [
                'method' => 'POST',
                'header' => "Content-Type: application/json; charset=utf-8\r\nAccept: application/json\r\n",
                'content' => $body,
                'timeout' => $timeoutSec,
                'ignore_errors' => true,
            ],
        ]);

        $raw = @file_get_contents($url, false, $context);
        if ($raw === false) {
            $err = error_get_last();
            $detail = is_array($err) ? (string) ($err['message'] ?? '') : '';
            if (str_contains(strtolower($detail), 'timed out')) {
                throw RpcException::timeout("RPC HTTP timeout after {$timeoutMs}ms for [{$url}].");
            }
            throw RpcException::transport("RPC HTTP request failed for [{$url}].");
        }

        $status = 0;
        if (isset($http_response_header) && is_array($http_response_header) && $http_response_header !== []) {
            if (preg_match('/\s(\d{3})\s/', $http_response_header[0], $m)) {
                $status = (int) $m[1];
            }
        }

        return $this->decodeResponse($raw, $status);
    }

    /**
     * @return array<string, mixed>
     */
    private function decodeResponse(string $raw, int $status): array
    {
        if ($status >= 500) {
            throw RpcException::transport(
                "RPC HTTP {$status}: " . substr($raw, 0, 200),
            );
        }

        if ($status >= 400 && $status !== 0) {
            /** @var mixed $probe */
            $probe = json_decode($raw, true);
            if (!is_array($probe) || !array_key_exists('code', $probe)) {
                throw RpcException::badRequest(
                    "RPC HTTP {$status}: " . substr($raw, 0, 200),
                );
            }
        }

        /** @var mixed $decoded */
        $decoded = json_decode($raw, true);
        if (!is_array($decoded)) {
            throw RpcException::transport(
                "Invalid JSON RPC response (HTTP {$status}): " . substr($raw, 0, 200),
            );
        }

        /** @var array<string, mixed> $decoded */
        return $decoded;
    }

    /**
     * Normalize endpoint to a full /rpc URL.
     */
    public static function resolveUrl(string $endpoint): string
    {
        $endpoint = trim($endpoint);
        if ($endpoint === '') {
            throw RpcException::badRequest('RPC endpoint is empty.');
        }

        if (str_ends_with($endpoint, '/rpc')) {
            return $endpoint;
        }

        return rtrim($endpoint, '/') . '/rpc';
    }
}
