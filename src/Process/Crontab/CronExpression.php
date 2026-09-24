<?php

declare(strict_types=1);

namespace Loongs\Process\Crontab;

use InvalidArgumentException;

/**
 * Minimal cron expression matcher supporting 5 fields (min hour dom month dow)
 * or 6 fields (sec min hour dom month dow).
 */
final class CronExpression
{
    /** @var list<string> */
    private array $parts;

    private bool $hasSeconds;

    public function __construct(string $expression)
    {
        $expression = trim(preg_replace('/\s+/', ' ', $expression) ?? $expression);
        $parts = explode(' ', $expression);
        $count = count($parts);
        if ($count !== 5 && $count !== 6) {
            throw new InvalidArgumentException("Cron expression must have 5 or 6 fields, got {$count}: [{$expression}]");
        }
        $this->hasSeconds = $count === 6;
        $this->parts = $parts;
    }

    public function isDue(\DateTimeInterface $time): bool
    {
        $sec = (int) $time->format('s');
        $min = (int) $time->format('i');
        $hour = (int) $time->format('G');
        $dom = (int) $time->format('j');
        $month = (int) $time->format('n');
        $dow = (int) $time->format('w'); // 0=Sun

        if ($this->hasSeconds) {
            return $this->matchField($this->parts[0], $sec, 0, 59)
                && $this->matchField($this->parts[1], $min, 0, 59)
                && $this->matchField($this->parts[2], $hour, 0, 23)
                && $this->matchField($this->parts[3], $dom, 1, 31)
                && $this->matchField($this->parts[4], $month, 1, 12)
                && $this->matchField($this->parts[5], $dow, 0, 6);
        }

        return $this->matchField($this->parts[0], $min, 0, 59)
            && $this->matchField($this->parts[1], $hour, 0, 23)
            && $this->matchField($this->parts[2], $dom, 1, 31)
            && $this->matchField($this->parts[3], $month, 1, 12)
            && $this->matchField($this->parts[4], $dow, 0, 6);
    }

    private function matchField(string $field, int $value, int $min, int $max): bool
    {
        if ($field === '*') {
            return true;
        }

        foreach (explode(',', $field) as $part) {
            $part = trim($part);
            if ($part === '') {
                continue;
            }

            $step = 1;
            if (str_contains($part, '/')) {
                [$part, $stepStr] = explode('/', $part, 2);
                $step = max(1, (int) $stepStr);
            }

            if ($part === '*' || $part === '') {
                if ($this->inStep($value, $min, $max, $step)) {
                    return true;
                }
                continue;
            }

            if (str_contains($part, '-')) {
                [$a, $b] = explode('-', $part, 2);
                $start = (int) $a;
                $end = (int) $b;
                if ($value >= $start && $value <= $end && (($value - $start) % $step === 0)) {
                    return true;
                }
                continue;
            }

            $n = (int) $part;
            if ($value === $n) {
                return true;
            }
            // For */step on a single number? already handled.
            if ($step > 1 && $value >= $n && (($value - $n) % $step === 0) && $value <= $max) {
                // e.g. 5/10 meaning 5,15,25...
                return true;
            }
        }

        return false;
    }

    private function inStep(int $value, int $min, int $max, int $step): bool
    {
        if ($value < $min || $value > $max) {
            return false;
        }

        return ($value - $min) % $step === 0;
    }
}
