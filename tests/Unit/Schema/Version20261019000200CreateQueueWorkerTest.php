<?php

declare(strict_types=1);

namespace Contenir\Queue\Tests\Unit\Schema;

use Contenir\Queue\Schema\Version20261019000200CreateQueueWorker;
use Contenir\Queue\Tests\Trait\AdapterPlatformStubTrait;
use PhpDb\Migration\SchemaInspector;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

#[Group('unit')]
final class Version20261019000200CreateQueueWorkerTest extends TestCase
{
    use AdapterPlatformStubTrait;

    #[Test]
    public function createsTheTableOnMysql(): void
    {
        static::assertSame(
            [
                'CREATE TABLE `queue_worker` ('
                    . ' `name` VARCHAR(64) NOT NULL,'
                    . ' `owner` VARCHAR(64) NULL,'
                    . ' `locked_until` DATETIME NULL,'
                    . ' `heartbeat_at` DATETIME NULL,'
                    . ' `started_at` DATETIME NULL,'
                    . ' `host` VARCHAR(255) NULL,'
                    . ' `pid` INT UNSIGNED NULL,'
                    . ' PRIMARY KEY (`name`)'
                    . ') ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci',
            ],
            $this->preview('MySQL', tableExists: false),
        );
    }

    #[Test]
    public function createsTheTableOnSqlite(): void
    {
        static::assertSame(
            [
                'CREATE TABLE "queue_worker" ('
                    . ' "name" VARCHAR(64) NOT NULL PRIMARY KEY,'
                    . ' "owner" VARCHAR(64) NULL,'
                    . ' "locked_until" DATETIME NULL,'
                    . ' "heartbeat_at" DATETIME NULL,'
                    . ' "started_at" DATETIME NULL,'
                    . ' "host" VARCHAR(255) NULL,'
                    . ' "pid" INTEGER NULL'
                    . ')',
            ],
            $this->preview('SQLite', tableExists: false),
        );
    }

    #[Test]
    public function describesItself(): void
    {
        $migration = new Version20261019000200CreateQueueWorker();

        static::assertSame(
            ['20261019000200', 'Create the queue_worker table'],
            [$migration->getVersion(), $migration->getDescription()],
        );
    }

    #[Test]
    public function leavesAnExistingTableOnEitherPlatform(): void
    {
        static::assertSame([[], []], [
            $this->preview('MySQL', tableExists: true),
            $this->preview('SQLite', tableExists: true),
        ]);
    }

    /**
     * @return array<string>
     */
    private function preview(string $platform, bool $tableExists): array
    {
        $inspector = $this->createStub(SchemaInspector::class);
        $inspector->method('tableExists')->willReturnMap([['queue_worker', $tableExists]]);

        return (new Version20261019000200CreateQueueWorker())->preview($this->adapterNamed($platform), $inspector);
    }
}
