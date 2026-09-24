<?php

declare(strict_types=1);

namespace Loongs\Process;

enum ProcessType: string
{
    case Http = 'http';
    case Rpc = 'rpc';
    case Websocket = 'websocket';
    case Queue = 'queue';
    case Crontab = 'crontab';
    case Custom = 'custom';

    public static function tryFromConfig(mixed $value): ?self
    {
        if ($value instanceof self) {
            return $value;
        }
        if (!is_string($value) || $value === '') {
            return null;
        }

        return self::tryFrom(strtolower($value));
    }
}
