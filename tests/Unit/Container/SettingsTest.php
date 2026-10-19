<?php

declare(strict_types=1);

namespace Contenir\Queue\Tests\Unit\Container;

use Contenir\Queue\Container\Settings;
use Contenir\Queue\Exception\ConfigurationException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

#[Group('unit')]
final class SettingsTest extends TestCase
{
    /**
     * @return array<string, array{callable(Settings): mixed, string}>
     */
    public static function invalidProvider(): array
    {
        return [
            'int from text'            => [
                static fn(Settings $s): mixed => $s->int('text', 1),
                'The setting "q.text" must be an integer',
            ],
            'number from text'         => [
                static fn(Settings $s): mixed => $s->number('text', 1.0),
                'The setting "q.text" must be a number',
            ],
            'string from a number'     => [
                static fn(Settings $s): mixed => $s->string('int', ''),
                'The setting "q.int" must be a string',
            ],
            'section from text'        => [
                static fn(Settings $s): mixed => $s->section('text'),
                'The setting "q.text" must be an array',
            ],
            'strings from text'        => [
                static fn(Settings $s): mixed => $s->strings('text'),
                'The setting "q.text" must be a list of strings',
            ],
            'strings from a map'       => [
                static fn(Settings $s): mixed => $s->strings('map'),
                'The setting "q.map" must be a list of strings',
            ],
            'strings holding a number' => [
                static fn(Settings $s): mixed => $s->strings('mixed'),
                'The setting "q.mixed" must be a list of strings',
            ],
            'nested path'              => [
                static fn(Settings $s): mixed => $s->section('inner')->int('x', 0),
                'The setting "q.inner.x" must be an integer',
            ],
        ];
    }

    private static function settings(): Settings
    {
        return new Settings([
            'int'   => 3,
            'float' => 1.5,
            'text'  => 'abc',
            'list'  => ['a', 'b'],
            'map'   => ['a' => 'b'],
            'mixed' => ['a', 1],
            'inner' => ['x' => 'no', 'y' => 'y'],
        ], 'q');
    }

    #[Test]
    public function absentKeysTakeTheirDefaults(): void
    {
        $settings = self::settings();

        static::assertSame(
            [7, 'd', 2.5, [], 9],
            [
                $settings->int('none', 7),
                $settings->string('none', 'd'),
                $settings->number('none', 2.5),
                $settings->strings('none'),
                $settings->section('none')->int('x', 9),
            ],
        );
    }

    #[Test]
    public function nullCountsAsAbsent(): void
    {
        static::assertSame(4, (new Settings(['n' => null], 'q'))->int('n', 4));
    }

    #[Test]
    public function readsValuesOfTheRightType(): void
    {
        $settings = self::settings();

        static::assertSame(
            [3, 'abc', 3.0, 1.5, ['a', 'b'], 'y'],
            [
                $settings->int('int', 0),
                $settings->string('text', ''),
                $settings->number('int', 0.0),
                $settings->number('float', 0.0),
                $settings->strings('list'),
                $settings->section('inner')->string('y', ''),
            ],
        );
    }

    /**
     * @param callable(Settings): mixed $read
     */
    #[Test]
    #[DataProvider('invalidProvider')]
    public function refusesValuesOfTheWrongType(callable $read, string $message): void
    {
        $this->expectException(ConfigurationException::class);
        $this->expectExceptionMessage($message);

        $read(self::settings());
    }
}
