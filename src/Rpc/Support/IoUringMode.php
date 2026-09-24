<?php

declare(strict_types=1);

namespace Loongs\Rpc\Support;

/**
 * Runtime switch for Swoole io_uring integration.
 *
 * - Auto: enable when the installed Swoole build + kernel support it
 * - On: prefer io_uring; fall back to epoll/curl with a status line when unsupported
 * - Off: never apply io_uring settings; force classic Http\Server + curl
 */
enum IoUringMode: string
{
    case Auto = 'auto';
    case On = 'on';
    case Off = 'off';

    public static function fromConfig(mixed $value): self
    {
        if ($value instanceof self) {
            return $value;
        }

        $normalized = strtolower(trim((string) $value));

        return match ($normalized) {
            'on', '1', 'true', 'yes', 'enable', 'enabled' => self::On,
            'off', '0', 'false', 'no', 'disable', 'disabled' => self::Off,
            default => self::Auto,
        };
    }
}
