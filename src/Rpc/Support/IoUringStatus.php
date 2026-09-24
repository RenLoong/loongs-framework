<?php

declare(strict_types=1);

namespace Loongs\Rpc\Support;

/**
 * Immutable snapshot of the io_uring decision for RPC.
 *
 * @phpstan-type Coverage 'none'|'file'|'network'|'file+network'
 * @phpstan-type Backend 'epoll'|'io_uring(file)'|'uring_socket'
 * @phpstan-type NetworkBackend 'curl'|'uring_socket'|'n/a'
 */
final readonly class IoUringStatus
{
    /**
     * @param array<string, mixed> $appliedSettings Settings passed to swoole_async_set
     */
    public function __construct(
        public IoUringMode $mode,
        public bool $wanted,
        public bool $active,
        public bool $fileSupported,
        public bool $socketSupported,
        public bool $kernelAllowed,
        public bool $networkActive,
        public string $backend,
        public string $networkBackend,
        public string $coverage,
        public string $reason,
        public array $appliedSettings = [],
    ) {
    }

    public function statusLine(): string
    {
        $flag = $this->active ? 'active' : 'inactive';
        $net = $this->networkActive ? 'on' : 'off';

        return sprintf(
            'RPC io_uring backend=%s (%s) network=%s/%s mode=%s coverage=%s reason=%s',
            $this->backend,
            $flag,
            $this->networkBackend,
            $net,
            $this->mode->value,
            $this->coverage,
            $this->reason,
        );
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'mode' => $this->mode->value,
            'wanted' => $this->wanted,
            'active' => $this->active,
            'network_active' => $this->networkActive,
            'backend' => $this->backend,
            'network_backend' => $this->networkBackend,
            'coverage' => $this->coverage,
            'file_supported' => $this->fileSupported,
            'socket_supported' => $this->socketSupported,
            'kernel_allowed' => $this->kernelAllowed,
            'reason' => $this->reason,
            'applied_settings' => $this->appliedSettings,
        ];
    }
}
