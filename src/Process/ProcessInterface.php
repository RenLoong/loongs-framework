<?php

declare(strict_types=1);

namespace Loongs\Process;

/**
 * Runnable unit for a ProcessManager child (or a custom process class).
 */
interface ProcessInterface
{
    /**
     * @param array<string, mixed> $config Process entry from process.php
     */
    public function handle(string $name, array $config): void;
}
