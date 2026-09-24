<?php

declare(strict_types=1);

namespace Loongs\Rpc\Retry;

use Loongs\Rpc\Contract\RpcResponse;
use Loongs\Rpc\Exception\RpcException;
use Throwable;

/**
 * Timeout / retry / failover policy for RpcClient.
 *
 * Behavior (documented):
 * - Prefer failover across discovered instances first (consecutive attempts walk the list).
 * - After a full pass (or when failover is off), apply backoff before the next attempt.
 * - Do not retry clear business failures (successful RPC envelope with code != 0)
 *   unless retry_on_business is enabled.
 *
 * Per-call meta overrides (optional keys):
 * timeout_ms, max_attempts, backoff_ms, backoff_multiplier, failover, retry_on_business
 */
final readonly class RetryPolicy
{
    /**
     * @param list<int> $retryOnHttpStatuses  e.g. [500, 502, 503, 504]
     */
    public function __construct(
        public int $maxAttempts = 3,
        public int $timeoutMs = 3000,
        public int $backoffMs = 100,
        public float $backoffMultiplier = 2.0,
        public bool $failover = true,
        public bool $retryOnBusiness = false,
        public array $retryOnHttpStatuses = [500, 502, 503, 504],
    ) {
        if ($this->maxAttempts < 1) {
            throw new \InvalidArgumentException('RetryPolicy.maxAttempts must be >= 1.');
        }
        if ($this->timeoutMs < 1) {
            throw new \InvalidArgumentException('RetryPolicy.timeoutMs must be >= 1.');
        }
        if ($this->backoffMs < 0) {
            throw new \InvalidArgumentException('RetryPolicy.backoffMs must be >= 0.');
        }
        if ($this->backoffMultiplier < 1.0) {
            throw new \InvalidArgumentException('RetryPolicy.backoffMultiplier must be >= 1.0.');
        }
    }

    /**
     * @param array<string, mixed> $config  rpc.retry (+ optional default_timeout_ms from parent)
     */
    public static function fromConfig(array $config, int $defaultTimeoutMs = 3000): self
    {
        $timeout = (int) ($config['timeout_ms'] ?? $defaultTimeoutMs);
        $statuses = $config['retry_on_http'] ?? [500, 502, 503, 504];
        if (!is_array($statuses)) {
            $statuses = [500, 502, 503, 504];
        }

        /** @var list<int> $httpStatuses */
        $httpStatuses = array_values(array_map(static fn (mixed $v): int => (int) $v, $statuses));

        return new self(
            maxAttempts: max(1, (int) ($config['max_attempts'] ?? 3)),
            timeoutMs: $timeout > 0 ? $timeout : $defaultTimeoutMs,
            backoffMs: max(0, (int) ($config['backoff_ms'] ?? 100)),
            backoffMultiplier: max(1.0, (float) ($config['backoff_multiplier'] ?? 2.0)),
            failover: filter_var($config['failover'] ?? true, FILTER_VALIDATE_BOOLEAN),
            retryOnBusiness: filter_var($config['retry_on_business'] ?? false, FILTER_VALIDATE_BOOLEAN),
            retryOnHttpStatuses: $httpStatuses,
        );
    }

    /**
     * Merge per-call meta overrides onto a base policy.
     *
     * @param array<string, mixed> $meta
     */
    public function withMeta(array $meta): self
    {
        return new self(
            maxAttempts: isset($meta['max_attempts']) ? max(1, (int) $meta['max_attempts']) : $this->maxAttempts,
            timeoutMs: isset($meta['timeout_ms']) ? max(1, (int) $meta['timeout_ms']) : $this->timeoutMs,
            backoffMs: isset($meta['backoff_ms']) ? max(0, (int) $meta['backoff_ms']) : $this->backoffMs,
            backoffMultiplier: isset($meta['backoff_multiplier'])
                ? max(1.0, (float) $meta['backoff_multiplier'])
                : $this->backoffMultiplier,
            failover: array_key_exists('failover', $meta)
                ? filter_var($meta['failover'], FILTER_VALIDATE_BOOLEAN)
                : $this->failover,
            retryOnBusiness: array_key_exists('retry_on_business', $meta)
                ? filter_var($meta['retry_on_business'], FILTER_VALIDATE_BOOLEAN)
                : $this->retryOnBusiness,
            retryOnHttpStatuses: $this->retryOnHttpStatuses,
        );
    }

    /**
     * Delay before attempt index $attempt (0-based). First attempt is never delayed.
     */
    public function delayMs(int $attempt): int
    {
        if ($attempt <= 0 || $this->backoffMs === 0) {
            return 0;
        }

        $ms = (int) round($this->backoffMs * ($this->backoffMultiplier ** ($attempt - 1)));

        return max(0, $ms);
    }

    public function shouldRetryException(Throwable $e): bool
    {
        if ($e instanceof RpcException) {
            return $e->isRetryable();
        }

        return false;
    }

    /**
     * Business envelope (HTTP transport succeeded). Only retry when explicitly enabled.
     */
    public function shouldRetryResponse(RpcResponse $response): bool
    {
        if ($response->isOk()) {
            return false;
        }

        return $this->retryOnBusiness;
    }

    public function shouldRetryHttpStatus(int $status): bool
    {
        return in_array($status, $this->retryOnHttpStatuses, true);
    }
}
