<?php

declare(strict_types=1);

namespace Loongs\Process;

/**
 * APP_NAME is part of every process title (loong-swoole[<APP_NAME>]: master) and of the default
 * pid / log / lock file names, so it may only contain letters, digits and underscores.
 */
final class InvalidAppNameException extends \InvalidArgumentException
{
    public function __construct(public readonly string $value)
    {
        parent::__construct(sprintf(
            'Invalid APP_NAME %s: only letters, digits and underscores are allowed ([A-Za-z0-9_]+), e.g. APP_NAME=my_app. Fix APP_NAME in .env (empty or unset means "%s").',
            json_encode($value, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
            ProcessManager::DEFAULT_APP_NAME,
        ));
    }
}
