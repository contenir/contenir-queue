<?php

declare(strict_types=1);

namespace Contenir\Queue\Schedule;

use InvalidArgumentException;

use function explode;
use function preg_match;
use function str_contains;
use function strtolower;

/**
 * Reads one field of a cron expression into the values it allows: `*`,
 * numbers, names, ranges (`1-5`), steps (`*\/15`, `1-30/5`, `5/15`) and lists
 * of those.
 *
 * @internal
 *
 * @mago-expect lint:cyclomatic-complexity Each part of the syntax is one branch.
 */
final readonly class CronField
{
    /**
     * @param array<string, int> $names Names the field takes for its values, in lower case.
     */
    public function __construct(
        private int $min,
        private int $max,
        private array $names = [],
    ) {}

    /**
     * @return array<int, true>
     *
     * @throws InvalidArgumentException When the field is not one this reads.
     */
    public function allowed(string $field): array
    {
        $allowed = [];
        foreach (explode(',', $field) as $part) {
            $pieces = explode('/', $part, limit: 2);
            $step   = $this->step($pieces[1] ?? null);
            [$from, $to] = $this->bounds($pieces[0], $step);
            for ($value = $from; $value <= $to; $value += $step) {
                $allowed[$value] = true;
            }
        }

        return $allowed;
    }

    /**
     * The first and last values a range covers: all of them for `*`, both ends
     * of `a-b`, and one value, or that value up to the last with a step.
     *
     * @return array{int, int}
     *
     * @throws InvalidArgumentException
     */
    private function bounds(string $range, int $step): array
    {
        if ('*' === $range) {
            return [$this->min, $this->max];
        }

        if (! str_contains($range, '-')) {
            $value = $this->value($range);

            return [$value, 1 === $step ? $value : $this->max];
        }

        $ends = explode('-', $range, limit: 2);
        $from = $this->value($ends[0]);
        $to   = $this->value($ends[1] ?? '');
        if ($from > $to) {
            throw new InvalidArgumentException("the range \"{$range}\" runs backwards");
        }

        return [$from, $to];
    }

    /**
     * @throws InvalidArgumentException
     */
    private function step(?string $step): int
    {
        if (null === $step) {
            return 1;
        }

        $value = (int) $step;
        if (1 !== preg_match('/^[1-9]\d*$/D', $step) || $value > $this->max) {
            throw new InvalidArgumentException("\"{$step}\" is not a step from 1 to {$this->max}");
        }

        return $value;
    }

    /**
     * @throws InvalidArgumentException
     */
    private function value(string $text): int
    {
        $value = $this->names[strtolower($text)] ?? (1 === preg_match('/^\d+$/D', $text) ? (int) $text : null);
        if (null === $value || $value < $this->min || $value > $this->max) {
            throw new InvalidArgumentException("\"{$text}\" is not a value from {$this->min} to {$this->max}");
        }

        return $value;
    }
}
