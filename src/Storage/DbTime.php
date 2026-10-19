<?php

declare(strict_types=1);

namespace Contenir\Queue\Storage;

use Contenir\Queue\Exception\StorageException;
use DateTimeImmutable;

use function gmdate;

/**
 * Times as the queue's tables hold them: UTC, to the second, in a format MySQL
 * DATETIME and SQLite both sort as text.
 *
 * @internal
 */
final class DbTime
{
    public const string FORMAT = 'Y-m-d H:i:s';

    public static function format(DateTimeImmutable $time): string
    {
        return gmdate(self::FORMAT, $time->getTimestamp());
    }

    /**
     * @throws StorageException
     */
    public static function parse(string $value): DateTimeImmutable
    {
        $time = DateTimeImmutable::createFromFormat(self::FORMAT . ' e', "{$value} UTC");

        return false === $time ? throw StorageException::notATime($value) : $time;
    }
}
