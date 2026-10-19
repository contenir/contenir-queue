<?php

declare(strict_types=1);

namespace Contenir\Queue\Exception;

use RuntimeException;
use Throwable;

/**
 * A schedule's next run cannot be worked out, as for a cron expression that
 * never matches (the 30th of February).
 *
 * @api
 */
final class ScheduleException extends RuntimeException
{
    public static function neverDue(string $expression, Throwable $previous): self
    {
        return new self("The cron expression \"{$expression}\" never falls due", previous: $previous);
    }
}
