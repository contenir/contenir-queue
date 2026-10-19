<?php

declare(strict_types=1);

namespace Contenir\Queue\Storage;

use Contenir\Queue\Exception\StorageException;
use DateTimeImmutable;

use function array_key_exists;
use function is_array;
use function is_int;
use function is_scalar;
use function is_string;
use function preg_match;

/**
 * Typed reads from a database row, whichever driver fetched it: MySQL may
 * return numbers as strings, SQLite as integers.
 *
 * @internal
 */
final readonly class Row
{
    /**
     * @param array<array-key, mixed> $values
     */
    public function __construct(
        private array $values,
    ) {}

    /**
     * A row as a driver fetched it. Anything but an array has no columns, so
     * every read from it fails.
     */
    public static function from(mixed $values): self
    {
        return new self(is_array($values) ? $values : []);
    }

    /**
     * @throws StorageException
     *
     * @mago-expect analysis:mixed-assignment Column values are untyped; the type is checked here.
     */
    public function int(string $column): int
    {
        $value = $this->value($column);
        if (is_int($value) || is_string($value) && preg_match('/^-?\d+$/D', $value) === 1) {
            return (int) $value;
        }

        throw StorageException::unreadable($column, 'an integer');
    }

    /**
     * @throws StorageException
     */
    public function nullableInt(string $column): ?int
    {
        return null === $this->nullableString($column) ? null : $this->int($column);
    }

    /**
     * @throws StorageException
     *
     * @mago-expect analysis:mixed-assignment Column values are untyped; the type is checked here.
     */
    public function nullableString(string $column): ?string
    {
        $value = $this->value($column);

        return match (true) {
            null === $value => null,
            is_scalar($value) => (string) $value,
            default           => throw StorageException::unreadable($column, 'a string'),
        };
    }

    /**
     * @throws StorageException
     */
    public function nullableTime(string $column): ?DateTimeImmutable
    {
        $value = $this->nullableString($column);

        return null === $value ? null : DbTime::parse($value);
    }

    /**
     * @throws StorageException
     */
    public function string(string $column): string
    {
        return $this->nullableString($column) ?? throw StorageException::unreadable($column, 'a string');
    }

    /**
     * @throws StorageException
     */
    public function time(string $column): DateTimeImmutable
    {
        return DbTime::parse($this->string($column));
    }

    /**
     * @throws StorageException
     */
    private function value(string $column): mixed
    {
        return array_key_exists($column, $this->values)
            ? $this->values[$column]
            : throw StorageException::missingColumn($column);
    }
}
