<?php

declare(strict_types=1);

namespace Loongs\Rpc\Client;

use Loongs\Rpc\Contract\RpcRequest;
use Loongs\Rpc\Contract\RpcResponse;
use Loongs\Rpc\Discovery\ServiceDiscoveryInterface;
use Loongs\Rpc\Discovery\ServiceInstance;
use Loongs\Rpc\Discovery\WeightedInstancePicker;
use Loongs\Rpc\Exception\RpcException;
use Loongs\Rpc\Retry\RetryPolicy;
use Loongs\Rpc\Support\Sleeper;
use Loongs\Rpc\Transport\TransportInterface;
use Throwable;

/**
 * Resolves instances via Discovery, then applies RetryPolicy.
 *
 * Instance order: WeightedInstancePicker orders once per call() —
 * equal positive weights keep config order; unequal weights get a weighted shuffle.
 * Failover walks that order; after a full pass (or when failover=false),
 * sleep backoff_ms * multiplier^(round-1) and retry until max_attempts sends.
 */
final class RpcClient implements RpcClientInterface
{
    public function __construct(
        private readonly ServiceDiscoveryInterface $discovery,
        private readonly TransportInterface $local,
        private readonly TransportInterface $loopback,
        private readonly TransportInterface $remote,
        private readonly RetryPolicy $retryPolicy = new RetryPolicy(),
        private readonly WeightedInstancePicker $picker = new WeightedInstancePicker(),
        private readonly Sleeper $sleeper = new Sleeper(),
    ) {
    }

    /**
     * @param array<string, mixed> $payload
     * @param array<string, mixed> $meta
     */
    public function call(
        string $service,
        string $method,
        array $payload = [],
        array $meta = [],
    ): RpcResponse {
        $instances = $this->picker->order($this->discovery->resolve($service));
        $policy = $this->retryPolicy->withMeta($meta);

        $lastException = null;
        $attempt = 0; // 0-based send counter
        $instanceCount = count($instances);

        while ($attempt < $policy->maxAttempts) {
            $instance = $this->pickInstance($instances, $attempt, $policy);
            $this->maybeBackoff($attempt, $instanceCount, $policy);

            $instanceMeta = $meta;
            $timeoutMs = $instance->timeoutMs ?? $policy->timeoutMs;
            if (!isset($instanceMeta['timeout_ms'])) {
                $instanceMeta['timeout_ms'] = $timeoutMs;
            }

            $request = RpcRequest::make($service, $method, $payload, $instanceMeta);
            $transport = $this->resolveTransport($instance->transport);
            $config = $instance->toServiceConfig();

            try {
                $response = $transport->send($request, $config);

                if ($response->isOk() || !$policy->shouldRetryResponse($response)) {
                    return $response;
                }

                // Business failure configured as retryable — treat like a soft failure.
                $lastException = RpcException::transport(
                    "RPC business code {$response->code} for [{$service}.{$method}] (retryable).",
                );
            } catch (Throwable $e) {
                if (!$policy->shouldRetryException($e)) {
                    throw $e;
                }
                $lastException = $e instanceof RpcException
                    ? $e
                    : RpcException::transport($e->getMessage(), $e);
            }

            $attempt++;
        }

        if ($lastException instanceof Throwable) {
            throw $lastException;
        }

        throw RpcException::transport("RPC call to [{$service}.{$method}] failed with no attempts.");
    }

    /**
     * @param list<ServiceInstance> $instances
     */
    private function pickInstance(array $instances, int $attempt, RetryPolicy $policy): ServiceInstance
    {
        if (!$policy->failover) {
            return $instances[0];
        }

        return $instances[$attempt % count($instances)];
    }

    private function maybeBackoff(int $attempt, int $instanceCount, RetryPolicy $policy): void
    {
        if ($attempt === 0) {
            return;
        }

        // Failover: no sleep between different instances in the same pass;
        // sleep only when starting a new round (or always when failover is off).
        if ($policy->failover && $instanceCount > 1 && ($attempt % $instanceCount) !== 0) {
            return;
        }

        $delay = $policy->delayMs($attempt);
        if ($delay > 0) {
            $this->sleeper->sleepMs($delay);
        }
    }

    private function resolveTransport(string $name): TransportInterface
    {
        return match ($name) {
            'local' => $this->local,
            'loopback' => $this->loopback,
            'remote' => $this->remote,
            default => throw RpcException::badRequest("Unknown RPC transport [{$name}]."),
        };
    }
}
