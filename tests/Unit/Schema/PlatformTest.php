<?php

declare(strict_types=1);

namespace Contenir\Queue\Tests\Unit\Schema;

use Contenir\Queue\Schema\Platform;
use Contenir\Queue\Tests\Trait\AdapterPlatformStubTrait;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use UnexpectedValueException;

#[Group('unit')]
final class PlatformTest extends TestCase
{
    use AdapterPlatformStubTrait;

    #[Test]
    public function mysqlIsMysql(): void
    {
        static::assertTrue(Platform::isMysql($this->adapterNamed('MySQL')));
    }

    #[Test]
    public function refusesOtherPlatforms(): void
    {
        $this->expectException(UnexpectedValueException::class);
        $this->expectExceptionMessage('contenir/queue supports MySQL and SQLite, not PostgreSQL');

        Platform::isMysql($this->adapterNamed('PostgreSQL'));
    }

    #[Test]
    public function sqliteIsNotMysql(): void
    {
        static::assertFalse(Platform::isMysql($this->adapterNamed('SQLite')));
    }
}
