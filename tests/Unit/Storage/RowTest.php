<?php

declare(strict_types=1);

namespace Contenir\Queue\Tests\Unit\Storage;

use Contenir\Queue\Exception\StorageException;
use Contenir\Queue\Storage\Row;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use stdClass;

#[Group('unit')]
final class RowTest extends TestCase
{
    /**
     * @return array<string, array{callable(Row): mixed, string}>
     */
    public static function unreadableProvider(): array
    {
        return [
            'missing column'          => [
                static fn(Row $row): mixed => $row->int('absent'),
                'The queue row has no "absent" column',
            ],
            'integer from text'       => [
                static fn(Row $row): mixed => $row->int('text'),
                'The queue column "text" does not hold an integer',
            ],
            'integer from a decimal'  => [
                static fn(Row $row): mixed => $row->int('decimal'),
                'The queue column "decimal" does not hold an integer',
            ],
            'integer with a newline'  => [
                static fn(Row $row): mixed => $row->int('newline'),
                'The queue column "newline" does not hold an integer',
            ],
            'integer from null'       => [
                static fn(Row $row): mixed => $row->int('null'),
                'The queue column "null" does not hold an integer',
            ],
            'string from null'        => [
                static fn(Row $row): mixed => $row->string('null'),
                'The queue column "null" does not hold a string',
            ],
            'string from an object'   => [
                static fn(Row $row): mixed => $row->nullableString('object'),
                'The queue column "object" does not hold a string',
            ],
            'time from text'          => [
                static fn(Row $row): mixed => $row->time('text'),
                'Not a database time: abc',
            ],
            'nullable time from text' => [
                static fn(Row $row): mixed => $row->nullableTime('text'),
                'Not a database time: abc',
            ],
        ];
    }

    #[Test]
    public function fromAnythingElseHasNoColumns(): void
    {
        $this->expectException(StorageException::class);
        $this->expectExceptionMessage('The queue row has no "id" column');

        Row::from('5')->int('id');
    }

    #[Test]
    public function fromKeepsAnArrayRow(): void
    {
        static::assertSame(5, Row::from(['id' => 5])->int('id'));
    }

    #[Test]
    public function readsIntegersWhicheverWayTheDriverReturnsThem(): void
    {
        $row = new Row(['a' => '12', 'b' => 3, 'c' => '-4']);

        static::assertSame([12, 3, -4], [$row->int('a'), $row->int('b'), $row->int('c')]);
    }

    #[Test]
    public function readsStrings(): void
    {
        $row = new Row(['a' => 'x', 'b' => 4, 'c' => null]);

        static::assertSame(['x', '4', null, '4'], [
            $row->string('a'),
            $row->nullableString('b'),
            $row->nullableString('c'),
            $row->string('b'),
        ]);
    }

    #[Test]
    public function readsTimes(): void
    {
        $row = new Row(['a' => '2026-10-19 09:00:00', 'b' => null]);

        static::assertSame(['2026-10-19 09:00:00', null, '2026-10-19 09:00:00'], [
            $row->time('a')->format('Y-m-d H:i:s'),
            $row->nullableTime('b'),
            $row->nullableTime('a')?->format('Y-m-d H:i:s'),
        ]);
    }

    /**
     * @param callable(Row): mixed $read
     */
    #[Test]
    #[DataProvider('unreadableProvider')]
    public function refusesValuesItCannotRead(callable $read, string $message): void
    {
        $this->expectException(StorageException::class);
        $this->expectExceptionMessage($message);

        $read(new Row([
            'text'    => 'abc',
            'decimal' => '1.5',
            'newline' => "1\n",
            'null'    => null,
            'object'  => new stdClass(),
        ]));
    }
}
