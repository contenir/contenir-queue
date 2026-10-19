<?php

declare(strict_types=1);

namespace Contenir\Queue\Job;

use DateInterval;
use DateTimeImmutable;
use RuntimeException;

use function ctype_digit;
use function max;
use function trim;

/**
 * Thrown by a handler that knows when its next attempt should be: a remote
 * service answered 429 or 503 with a Retry-After header, for instance. The
 * attempt still counts towards the job's limit, and a wait longer than the
 * back-off ceiling is cut to the ceiling.
 */
final class RetryLaterException extends RuntimeException
{
    /**
     * An HTTP date (RFC 9110), such as "Sun, 06 Nov 1994 08:49:37 GMT". PHP
     * 8.5 deprecates DateTimeInterface::RFC7231, which reads the same text but
     * ignores the zone.
     */
    private const string HTTP_DATE = 'D, d M Y H:i:s T';

    private function __construct(
        string $message,
        private readonly ?int $seconds,
        private readonly ?DateTimeImmutable $at,
    ) {
        parent::__construct($message);
    }

    /**
     * Retry no sooner than the given number of seconds from now.
     */
    public static function after(int $seconds, string $message = 'Retry later'): self
    {
        return new self($message, max(0, $seconds), null);
    }

    /**
     * Retry no sooner than the given time.
     */
    public static function at(DateTimeImmutable $at, string $message = 'Retry later'): self
    {
        return new self($message, null, $at);
    }

    /**
     * Retry when an HTTP Retry-After header says: either a number of seconds or
     * an HTTP date. A header that is neither falls back to the usual back-off.
     */
    public static function fromRetryAfter(string $header, string $message = 'Retry later'): self
    {
        $value = trim($header);
        if (ctype_digit($value)) {
            return self::after((int) $value, $message);
        }

        $at = DateTimeImmutable::createFromFormat(self::HTTP_DATE, $value);

        return new self($message, null, false === $at ? null : $at);
    }

    /**
     * The earliest time for the next attempt, or null to use the back-off.
     */
    public function retryAt(DateTimeImmutable $now): ?DateTimeImmutable
    {
        if (null !== $this->seconds) {
            return $now->add(new DateInterval("PT{$this->seconds}S"));
        }

        return $this->at;
    }
}
