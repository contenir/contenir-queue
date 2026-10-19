<?php

declare(strict_types=1);

namespace Contenir\Queue\Tests\Unit\Schema;

use Contenir\Queue\Schema\Version20261019000100CreateQueueJob;
use Contenir\Queue\Tests\Trait\AdapterPlatformStubTrait;
use PhpDb\Migration\SchemaInspector;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

#[Group('unit')]
final class Version20261019000100CreateQueueJobTest extends TestCase
{
    use AdapterPlatformStubTrait;

    #[Test]
    public function createsTheTableOnMysql(): void
    {
        static::assertSame(
            [
                'CREATE TABLE `queue_job` ('
                    . ' `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,'
                    . " `queue` VARCHAR(64) NOT NULL DEFAULT 'default',"
                    . ' `job_class` VARCHAR(255) NOT NULL,'
                    . ' `payload` MEDIUMTEXT NOT NULL,'
                    . " `state` VARCHAR(16) NOT NULL DEFAULT 'waiting',"
                    . ' `attempts` INT UNSIGNED NOT NULL DEFAULT 0,'
                    . ' `next_attempt_at` DATETIME NOT NULL,'
                    . ' `locked_by` VARCHAR(64) NULL,'
                    . ' `locked_at` DATETIME NULL,'
                    . ' `last_error` TEXT NULL,'
                    . ' `created_at` DATETIME NOT NULL,'
                    . ' `updated_at` DATETIME NOT NULL,'
                    . ' `finished_at` DATETIME NULL,'
                    . ' PRIMARY KEY (`id`),'
                    . ' KEY `queue_job_due` (`state`, `next_attempt_at`),'
                    . ' KEY `queue_job_queue` (`queue`, `state`)'
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
                'CREATE TABLE "queue_job" ('
                    . ' "id" INTEGER NOT NULL PRIMARY KEY AUTOINCREMENT,'
                    . " \"queue\" VARCHAR(64) NOT NULL DEFAULT 'default',"
                    . ' "job_class" VARCHAR(255) NOT NULL,'
                    . ' "payload" TEXT NOT NULL,'
                    . " \"state\" VARCHAR(16) NOT NULL DEFAULT 'waiting',"
                    . ' "attempts" INTEGER NOT NULL DEFAULT 0,'
                    . ' "next_attempt_at" DATETIME NOT NULL,'
                    . ' "locked_by" VARCHAR(64) NULL,'
                    . ' "locked_at" DATETIME NULL,'
                    . ' "last_error" TEXT NULL,'
                    . ' "created_at" DATETIME NOT NULL,'
                    . ' "updated_at" DATETIME NOT NULL,'
                    . ' "finished_at" DATETIME NULL'
                    . ')',
                'CREATE INDEX IF NOT EXISTS "queue_job_due" ON "queue_job" ("state", "next_attempt_at")',
                'CREATE INDEX IF NOT EXISTS "queue_job_queue" ON "queue_job" ("queue", "state")',
            ],
            $this->preview('SQLite', tableExists: false),
        );
    }

    #[Test]
    public function describesItself(): void
    {
        $migration = new Version20261019000100CreateQueueJob();

        static::assertSame(
            ['20261019000100', 'Create the queue_job table'],
            [$migration->getVersion(), $migration->getDescription()],
        );
    }

    #[Test]
    public function leavesAnExistingMysqlTable(): void
    {
        static::assertSame([], $this->preview('MySQL', tableExists: true));
    }

    #[Test]
    public function onlyEnsuresTheIndexesOfAnExistingSqliteTable(): void
    {
        static::assertSame(
            [
                'CREATE INDEX IF NOT EXISTS "queue_job_due" ON "queue_job" ("state", "next_attempt_at")',
                'CREATE INDEX IF NOT EXISTS "queue_job_queue" ON "queue_job" ("queue", "state")',
            ],
            $this->preview('SQLite', tableExists: true),
        );
    }

    /**
     * @return array<string>
     */
    private function preview(string $platform, bool $tableExists): array
    {
        $inspector = $this->createStub(SchemaInspector::class);
        $inspector->method('tableExists')->willReturnMap([['queue_job', $tableExists]]);

        return (new Version20261019000100CreateQueueJob())->preview($this->adapterNamed($platform), $inspector);
    }
}
