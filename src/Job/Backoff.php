<?php

declare(strict_types=1);

namespace Contenir\Queue\Job;

use InvalidArgumentException;

use function min;

/**
 * How long a failed job waits before its next attempt. The wait starts at one
 * minute and doubles after each failure up to a ceiling of 24 hours; once a
 * job has failed after waiting the full ceiling, it gets no more attempts.
 *
 * With the defaults that is 13 attempts over about two and a half days: waits
 * of 1, 2, 4 … 1,024 minutes, then 24 hours.
 */
final readonly class Backoff
{
    public const int DEFAULT_INITIAL_SECONDS = 60;
    public const int DEFAULT_MAX_SECONDS     = 86_400;
    public const int DEFAULT_FACTOR          = 2;

    public function __construct(
        public int $initialSeconds = self::DEFAULT_INITIAL_SECONDS,
        public int $maxSeconds = self::DEFAULT_MAX_SECONDS,
        public int $factor = self::DEFAULT_FACTOR,
    ) {
        if ($initialSeconds < 1) {
            throw new InvalidArgumentException('The first retry wait must be at least one second');
        }

        if ($maxSeconds < $initialSeconds) {
            throw new InvalidArgumentException('The longest retry wait cannot be shorter than the first');
        }

        if ($factor < 2) {
            throw new InvalidArgumentException('The retry wait must at least double after each failure');
        }
    }

    /**
     * The wait, in seconds, after the given attempt fails, or null when that
     * attempt was the last.
     */
    public function delayAfter(int $attempt): ?int
    {
        $delay = $this->initialSeconds;
        for ($failed = 1; $failed < $attempt; ++$failed) {
            if ($delay >= $this->maxSeconds) {
                return null;
            }

            $delay *= $this->factor;
        }

        return min($delay, $this->maxSeconds);
    }
}
