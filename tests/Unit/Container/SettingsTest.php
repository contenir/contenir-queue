<?php

declare(strict_types=1);

namespace Contenir\Queue\Tests\Unit\Container;

use Contenir\Queue\Container\Settings;
use Contenir\Queue\Exception\ConfigurationException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

use function array_keys;
use function array_map;

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
            'array from text'          => [
                static fn(Settings $s): mixed => $s->array('text'),
                'The setting "q.text" must be an array',
            ],
            'map of text'              => [
                static fn(Settings $s): mixed => $s->map('list'),
                'The setting "q.list.0" must be an array',
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
    public function aNumericMapKeyIsAName(): void
    {
        static::assertSame(
            ['5'],
            array_map(strval(...), array_keys((new Settings(['m' => [5 => []]], 'q'))->map('m'))),
        );
    }

    #[Test]
    public function hasIsTrueForAnyValueButNull(): void
    {
        $settings = new Settings(['zero' => 0, 'empty' => '', 'null' => null], 'q');

        static::assertSame(
            [true, true, false, false],
            [$settings->has('zero'), $settings->has('empty'), $settings->has('null'), $settings->has('none')],
        );
    }

    #[Test]
    public function nullCountsAsAbsent(): void
    {
        static::assertSame(4, (new Settings(['n' => null], 'q'))->int('n', 4));
    }

    #[Test]
    public function readsArraysAndMaps(): void
    {
        $settings = new Settings(['a' => [1, 'x' => 2], 'm' => ['one' => ['v' => 1], 'two' => []]], 'q');

        static::assertSame(
            [[1, 'x' => 2], [], ['one', 'two'], 1, 'q.m.two.v'],
            [
                $settings->array('a'),
                $settings->array('none'),
                array_keys($settings->map('m')),
                $settings->map('m')['one']->int('v', 0),
                $settings->map('m')['two']->path('v'),
            ],
        );
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

    #[Test]
    public function requiredIntMustBeGivenAsAnInteger(): void
    {
        $settings = new Settings(['n' => 3, 'text' => '3', 'null' => null], 'q');
        $failures = [];
        foreach (['text', 'null', 'none'] as $key) {
            try {
                $settings->requiredInt($key);
            } catch (ConfigurationException $e) {
                $failures[] = $e->getMessage();
            }
        }

        static::assertSame(
            [
                3,
                [
                    'The setting "q.text" must be an integer',
                    'The setting "q.null" must be given',
                    'The setting "q.none" must be given',
                ],
            ],
            [$settings->requiredInt('n'), $failures],
        );
    }
}
