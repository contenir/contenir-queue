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
     * @throws ConfigurationException
     *
     * @mago-expect analysis:mixed-assignment Config values are untyped; the type is checked here.
     */
    public function section(string $key): self
    {
        $value = $this->values[$key] ?? [];

        return is_array($value) ? new self($value, "{$this->path}.{$key}") : throw $this->invalid($key, 'an array');
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
        return ConfigurationException::invalidSetting("{$this->path}.{$key}", $expected);
    }
}
