<?php

declare(strict_types=1);

namespace Loongs\Rpc\Discovery;

/**
 * One reachable endpoint for a named RPC service.
 *
 * weight defaults to 1. weight <= 0 is excluded by WeightedInstancePicker
 * (with fallback to equal weight 1 if every instance is excluded).
 */
final readonly class ServiceInstance
{
    /**
     * @param array<string, mixed> $metadata
     */
    public function __construct(
        public string $service,
        public string $transport,
        public ?string $endpoint = null,
        public int $weight = 1,
        public array $metadata = [],
        public ?int $timeoutMs = null,
    ) {
        if ($this->service === '') {
            throw new \InvalidArgumentException('ServiceInstance.service must not be empty.');
        }
        if (!in_array($this->transport, ['local', 'loopback', 'remote'], true)) {
            throw new \InvalidArgumentException("Unknown transport [{$this->transport}].");
        }
        if ($this->weight < 0) {
            throw new \InvalidArgumentException('ServiceInstance.weight must be >= 0.');
        }
    }

    /**
     * Shape expected by TransportInterface::send().
     *
     * @return array<string, mixed>
     */
    public function toServiceConfig(): array
    {
        $config = [
            'transport' => $this->transport,
            'weight' => $this->weight,
            'metadata' => $this->metadata,
        ];

        if ($this->endpoint !== null) {
            $config['endpoint'] = $this->endpoint;
        }

        if ($this->timeoutMs !== null) {
            $config['timeout_ms'] = $this->timeoutMs;
        }

        return $config;
    }
}
