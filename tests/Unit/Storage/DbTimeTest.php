<?php

declare(strict_types=1);

namespace Contenir\Queue\Tests\Unit\Storage;

use Contenir\Queue\Exception\StorageException;
use Contenir\Queue\Storage\DbTime;
use DateTimeImmutable;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

#[Group('unit')]
final class DbTimeTest extends TestCase
{
    #[Test]
    public function formatsInUtc(): void
    {
        static::assertSame(
            '2026-10-18 23:30:00',
            DbTime::format(new DateTimeImmutable('2026-10-19 10:30:00.75 Australia/Sydney')),
        );
    }

    #[Test]
    public function parsesAsUtcToTheSecond(): void
    {
        $time = DbTime::parse('2026-10-19 09:00:05');

        static::assertSame('2026-10-19T09:00:05.000000+00:00', $time->format('Y-m-d\TH:i:s.uP'));
    }

    #[Test]
    public function rejectsAnythingElse(): void
    {
        $this->expectException(StorageException::class);
        $this->expectExceptionMessage('Not a database time: 2026-10-19');

        DbTime::parse('2026-10-19');
    }
}
