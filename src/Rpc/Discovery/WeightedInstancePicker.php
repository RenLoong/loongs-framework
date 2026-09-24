<?php

declare(strict_types=1);

namespace Loongs\Rpc\Discovery;

/**
 * Orders service instances for a call / failover round.
 *
 * Behavior:
 * - weight <= 0 is excluded; if every instance is excluded, all are treated as weight 1.
 * - When all eligible positive weights are equal, the original config order is preserved
 *   (stable) so deterministic failover smokes keep working.
 * - When weights differ, performs a weighted shuffle (sample without replacement) using
 *   discrete weighted picks; inject $randomInt for tests.
 *
 * @phpstan-type RandomInt callable(int, int): int
 */
final class WeightedInstancePicker
{
    /** @var callable(int, int): int */
    private $randomInt;

    /**
     * @param null|callable(int, int): int $randomInt  Defaults to random_int.
     */
    public function __construct(?callable $randomInt = null)
    {
        $this->randomInt = $randomInt ?? static fn (int $min, int $max): int => random_int($min, $max);
    }

    /**
     * @param list<ServiceInstance> $instances
     * @return list<ServiceInstance>
     */
    public function order(array $instances): array
    {
        if ($instances === []) {
            return [];
        }

        $eligible = [];
        foreach ($instances as $instance) {
            if ($instance->weight > 0) {
                $eligible[] = $instance;
            }
        }

        if ($eligible === []) {
            // All excluded → equal weight 1, preserve original order.
            return array_values($instances);
        }

        $weights = array_map(static fn (ServiceInstance $i): int => $i->weight, $eligible);
        $unique = array_unique($weights);
        if (count($unique) === 1) {
            return $eligible;
        }

        return $this->weightedShuffle($eligible);
    }

    /**
     * Weighted sample without replacement among the given instances.
     *
     * @param list<ServiceInstance> $instances  Must be non-empty with weight > 0.
     * @return list<ServiceInstance>
     */
    private function weightedShuffle(array $instances): array
    {
        $pool = $instances;
        $ordered = [];

        while ($pool !== []) {
            $pick = $this->pickOne($pool);
            $ordered[] = $pool[$pick];
            array_splice($pool, $pick, 1);
        }

        return $ordered;
    }

    /**
     * @param list<ServiceInstance> $pool
     */
    private function pickOne(array $pool): int
    {
        $total = 0;
        foreach ($pool as $instance) {
            $total += $instance->weight;
        }

        if ($total < 1) {
            return 0;
        }

        $ticket = ($this->randomInt)(1, $total);
        $cumulative = 0;
        foreach ($pool as $index => $instance) {
            $cumulative += $instance->weight;
            if ($ticket <= $cumulative) {
                return $index;
            }
        }

        return array_key_last($pool) ?? 0;
    }
}
