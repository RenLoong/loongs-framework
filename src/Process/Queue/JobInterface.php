<?php

declare(strict_types=1);

namespace Loongs\Process\Queue;

interface JobInterface
{
    /**
     * @param array<string, mixed> $data
     */
    public function handle(array $data): void;
}
