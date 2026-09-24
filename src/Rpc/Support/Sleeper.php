<?php

declare(strict_types=1);

namespace Loongs\Rpc\Support;

/**
 * Coroutine-aware sleep helper for RpcClient backoff.
 *
 * Uses Swoole\Coroutine::sleep when running inside a coroutine (getCid() > 0);
 * otherwise falls back to usleep. API is milliseconds (int) to match RetryPolicy.
 */
final class Sleeper
{
    /**
     * Sleep for the given number of milliseconds.
     */
    public function sleepMs(int $milliseconds): void
    {
        if ($milliseconds <= 0) {
            return;
        }

        $seconds = $milliseconds / 1000.0;

        if (
            class_exists(\Swoole\Coroutine::class)
            && \Swoole\Coroutine::getCid() > 0
        ) {
            \Swoole\Coroutine::sleep($seconds);

            return;
        }

        usleep($milliseconds * 1000);
    }

    /**
     * Sleep for the given number of seconds (float).
     */
    public function sleep(float $seconds): void
    {
        if ($seconds <= 0.0) {
            return;
        }

        $this->sleepMs((int) round($seconds * 1000));
    }
}
