<?php

declare(strict_types=1);

namespace Contenir\Queue\Container;

use Contenir\Queue\Exception\ConfigurationException;

use function array_is_list;
use function is_array;
use function is_float;
use function is_int;
use function is_string;

/**
 * Typed reads from one level of the `contenir_queue` configuration. An absent
 * key takes its default; a key present with the wrong type is an error.
 *
 * @internal
 *
 * @mago-expect lint:too-many-methods One typed reader per kind of setting.
 * @mago-expect lint:cyclomatic-complexity Each reader checks its own type.
 */
final readonly class Settings
{
    /**
     * @param array<array-key, mixed> $values
     */
    public function __construct(
        private array $values,
        private string $path,
    ) {}

    /**
     * An array of anything, such as a job's payload.
     *
     * @return array<array-key, mixed>
     *
     * @throws ConfigurationException
     *
     * @mago-expect analysis:mixed-assignment Config values are untyped; the type is checked here.
     */
    public function array(string $key): array
    {
        $value = $this->values[$key] ?? [];

        return is_array($value) ? $value : throw $this->invalid($key, 'an array');
    }

    public function has(string $key): bool
    {
        return null !== ($this->values[$key] ?? null);
    }

    /**
     * @throws ConfigurationException
     *
     * @mago-expect analysis:mixed-assignment Config values are untyped; the type is checked here.
     */
    public function int(string $key, int $default): int
    {
        $value = $this->values[$key] ?? $default;

        return is_int($value) ? $value : throw $this->invalid($key, 'an integer');
    }

    /**
     * The sections of a map, by their names. PHP keeps a numeric name as an
     * integer key.
     *
     * @return array<array-key, self>
     *
     * @throws ConfigurationException
     */
    public function map(string $key): array
    {
        $sections = [];
        foreach ($this->array($key) as $name => $_) {
            $sections[$name] = $this->section($key)->section((string) $name);
        }

        return $sections;
    }

    /**
     * A number of seconds, whole or not.
     *
     * @throws ConfigurationException
     *
     * @mago-expect analysis:mixed-assignment Config values are untyped; the type is checked here.
     */
    public function number(string $key, float $default): float
    {
        $value = $this->values[$key] ?? $default;

        return is_int($value) || is_float($value) ? $value : throw $this->invalid($key, 'a number');
    }

    /**
     * The path of a key, for messages.
     */
    public function path(string $key): string
    {
        return "{$this->path}.{$key}";
    }

    /**
     * An integer that must be given.
     *
     * @throws ConfigurationException
     *
     * @mago-expect analysis:mixed-assignment Config values are untyped; the type is checked here.
     */
    public function requiredInt(string $key): int
    {
        $value = $this->values[$key] ?? null;

        return match (true) {
            null === $value => throw $this->invalid($key, 'given'),
            is_int($value) => $value,
            default        => throw $this->invalid($key, 'an integer'),
        };
    }

    /**
     * @throws ConfigurationException
     *
     * @mago-expect analysis:mixed-assignment Config values are untyped; the type is checked here.
     */
    public function section(string $key): self
    {
        $value = $this->values[$key] ?? [];

        return is_array($value) ? new self($value, $this->path($key)) : throw $this->invalid($key, 'an array');
    }

    /**
     * @throws ConfigurationException
     *
     * @mago-expect analysis:mixed-assignment Config values are untyped; the type is checked here.
     */
    public function string(string $key, string $default): string
    {
        $value = $this->values[$key] ?? $default;

        return is_string($value) ? $value : throw $this->invalid($key, 'a string');
    }

    /**
     * @return list<string>
     *
     * @throws ConfigurationException
     *
     * @mago-expect analysis:mixed-assignment Config values are untyped; the type is checked here.
     */
    public function strings(string $key): array
    {
        $value = $this->values[$key] ?? [];
        if (! is_array($value) || ! array_is_list($value)) {
            throw $this->invalid($key, 'a list of strings');
        }

        $strings = [];
        foreach ($value as $item) {
            $strings[] = is_string($item) ? $item : throw $this->invalid($key, 'a list of strings');
        }

        return $strings;
    }

    private function invalid(string $key, string $expected): ConfigurationException
    {
        return ConfigurationException::invalidSetting($this->path($key), $expected);
    }
}
