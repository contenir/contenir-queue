<?php

declare(strict_types=1);

namespace Contenir\Queue\Exception;

use RuntimeException;
use Throwable;

/**
 * The queue's tables could not be read or written: the database refused a
 * statement, its driver returned nothing, or a stored value is unreadable.
 *
 * @api
 */
final class StorageException extends RuntimeException
{
    public static function failed(Throwable $previous): self
    {
        return new self("The queue database failed: {$previous->getMessage()}", previous: $previous);
    }

    public static function missingColumn(string $column): self
    {
        return new self("The queue row has no \"{$column}\" column");
    }

    public static function noResult(): self
    {
        return new self('The database driver returned no result');
    }

    public static function notATime(string $value): self
    {
        return new self("Not a database time: {$value}");
    }

    public static function unreadable(string $column, string $expected): self
    {
        return new self("The queue column \"{$column}\" does not hold {$expected}");
    }
}
