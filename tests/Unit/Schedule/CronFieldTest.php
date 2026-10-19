<?php

declare(strict_types=1);

namespace Contenir\Queue\Tests\Unit\Schedule;

use Contenir\Queue\Schedule\CronField;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

use function array_keys;
use function sort;

#[Group('unit')]
final class CronFieldTest extends TestCase
{
    /**
     * @return array<string, array{string, list<int>}>
     */
    public static function allowedProvider(): array
    {
        return [
            'any'                   => ['*', [0, 1, 2, 3, 4, 5, 6, 7, 8, 9, 10]],
            'a value'               => ['5', [5]],
            'the lowest value'      => ['0', [0]],
            'the highest value'     => ['10', [10]],
            'a leading zero'        => ['05', [5]],
            'a list'                => ['1,4,9', [1, 4, 9]],
            'a repeated value'      => ['3,3', [3]],
            'a range'               => ['2-5', [2, 3, 4, 5]],
            'a one-value range'     => ['4-4', [4]],
            'a stepped range'       => ['1-9/3', [1, 4, 7]],
            'every so many'         => ['*/4', [0, 4, 8]],
            'a step of one'         => ['*/1', [0, 1, 2, 3, 4, 5, 6, 7, 8, 9, 10]],
            'the largest step'      => ['*/10', [0, 10]],
            'from a value, stepped' => ['5/2', [5, 7, 9]],
            'a name'                => ['two', [2]],
            'a name in capitals'    => ['TWO', [2]],
            'a range of names'      => ['one-three', [1, 2, 3]],
            'a mixed list'          => ['0,two-3,8/2', [0, 2, 3, 8, 10]],
        ];
    }

    /**
     * @return array<string, array{string, string}>
     */
    public static function invalidProvider(): array
    {
        return [
            'empty'                 => ['', '"" is not a value from 0 to 10'],
            'below the lowest'      => ['-1', '"" is not a value from 0 to 10'],
            'above the highest'     => ['11', '"11" is not a value from 0 to 10'],
            'a word'                => ['six', '"six" is not a value from 0 to 10'],
            'a decimal'             => ['1.5', '"1.5" is not a value from 0 to 10'],
            'a trailing newline'    => ["1\n", "\"1\n\" is not a value from 0 to 10"],
            'an empty list item'    => ['1,,2', '"" is not a value from 0 to 10'],
            'an open range'         => ['3-', '"" is not a value from 0 to 10'],
            'a range past the end'  => ['3-11', '"11" is not a value from 0 to 10'],
            'a backwards range'     => ['5-2', 'the range "5-2" runs backwards'],
            'a three-part range'    => ['1-2-3', '"2-3" is not a value from 0 to 10'],
            'a zero step'           => ['*/0', '"0" is not a step from 1 to 10'],
            'a step too large'      => ['*/11', '"11" is not a step from 1 to 10'],
            'an empty step'         => ['*/', '"" is not a step from 1 to 10'],
            'a word for a step'     => ['*/x', '"x" is not a step from 1 to 10'],
            'a step with a newline' => ["*/2\n", "\"2\n\" is not a step from 1 to 10"],
            'a leading zero step'   => ['*/02', '"02" is not a step from 1 to 10'],
            'two steps'             => ['*/2/3', '"2/3" is not a step from 1 to 10'],
        ];
    }

    private static function field(): CronField
    {
        return new CronField(0, 10, ['one' => 1, 'two' => 2, 'three' => 3]);
    }

    #[Test]
    public function aFieldStartingAboveZeroCountsFromItsLowest(): void
    {
        static::assertSame([1, 3, 5], array_keys((new CronField(1, 5))->allowed('*/2')));
    }

    #[Test]
    public function namesAreOptional(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('"two" is not a value from 0 to 10');

        (new CronField(0, 10))->allowed('two');
    }

    /**
     * @param list<int> $expected
     */
    #[Test]
    #[DataProvider('allowedProvider')]
    public function readsWhatAFieldAllows(string $field, array $expected): void
    {
        $values = array_keys(self::field()->allowed($field));
        sort($values);

        static::assertSame($expected, $values);
    }

    #[Test]
    #[DataProvider('invalidProvider')]
    public function refusesWhatItCannotRead(string $field, string $message): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage($message);

        self::field()->allowed($field);
    }
}
