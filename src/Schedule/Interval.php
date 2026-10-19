<?php

declare(strict_types=1);

namespace Contenir\Queue\Schedule;

use DateTimeImmutable;
use Override;

use function intdiv;

/**
 * Every so many minutes, keeping to its own beat: a run handled late does not
 * push the ones after it later.
 *
 * @internal
 */
final readonly class Interval implements RecurrenceInterface
{
    public function __construct(
        private int $minutes,
    ) {}

    /**
     * At once.
     */
    #[Override]
    public function first(DateTimeImmutable $now): DateTimeImmutable
    {
        return $now;
    }

    /**
     * The run due plus the fewest whole intervals that land after now.
     */
    #[Override]
    public function next(DateTimeImmutable $due, DateTimeImmutable $now): DateTimeImmutable
    {
        $interval = $this->minutes * 60;
        $seconds  = (intdiv($now->getTimestamp() - $due->getTimestamp(), $interval) + 1) * $interval;

        return $due->modify("{$seconds} seconds");
    }
}
