<?php

declare(strict_types=1);

namespace Contenir\Queue\Tests\Unit\Schema;

use Contenir\Queue\Schema\Version20261019000300CreateQueueSchedule;
use Contenir\Queue\Tests\Trait\AdapterPlatformStubTrait;
use PhpDb\Migration\SchemaInspector;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

#[Group('unit')]
final class Version20261019000300CreateQueueScheduleTest extends TestCase
{
    use AdapterPlatformStubTrait;

    private const string MYSQL_SCHEDULE =
        'CREATE TABLE `queue_schedule` ('
            . ' `name` VARCHAR(128) NOT NULL,'
            . ' `next_run_at` DATETIME NOT NULL,'
            . ' `last_run_at` DATETIME NULL,'
            . ' `last_job_id` BIGINT UNSIGNED NULL,'
            . ' `updated_at` DATETIME NOT NULL,'
            . ' PRIMARY KEY (`name`)'
            . ') ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci';

    private const string MYSQL_RUN =
        'CREATE TABLE `queue_schedule_run` ('
            . ' `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,'
            . ' `schedule` VARCHAR(128) NOT NULL,'
            . ' `due_at` DATETIME NOT NULL,'
            . ' `recorded_at` DATETIME NOT NULL,'
            . ' `job_id` BIGINT UNSIGNED NULL,'
            . ' PRIMARY KEY (`id`),'
            . ' KEY `queue_schedule_run_schedule` (`schedule`, `due_at`),'
            . ' KEY `queue_schedule_run_recorded` (`recorded_at`)'
            . ') ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci';

    private const string SQLITE_SCHEDULE =
        'CREATE TABLE "queue_schedule" ('
            . ' "name" VARCHAR(128) NOT NULL PRIMARY KEY,'
            . ' "next_run_at" DATETIME NOT NULL,'
            . ' "last_run_at" DATETIME NULL,'
            . ' "last_job_id" INTEGER NULL,'
            . ' "updated_at" DATETIME NOT NULL'
            . ')';

    private const string SQLITE_RUN =
        'CREATE TABLE "queue_schedule_run" ('
            . ' "id" INTEGER NOT NULL PRIMARY KEY AUTOINCREMENT,'
            . ' "schedule" VARCHAR(128) NOT NULL,'
            . ' "due_at" DATETIME NOT NULL,'
            . ' "recorded_at" DATETIME NOT NULL,'
            . ' "job_id" INTEGER NULL'
            . ')';

    private const array SQLITE_INDEXES = [
        'CREATE INDEX IF NOT EXISTS "queue_schedule_run_schedule" ON "queue_schedule_run" ("schedule", "due_at")',
        'CREATE INDEX IF NOT EXISTS "queue_schedule_run_recorded" ON "queue_schedule_run" ("recorded_at")',
    ];

    #[Test]
    public function createsBothTablesAndTheIndexesOnSqlite(): void
    {
        static::assertSame(
            [self::SQLITE_SCHEDULE, self::SQLITE_RUN, ...self::SQLITE_INDEXES],
            $this->preview('SQLite', false, false),
        );
    }

    #[Test]
    public function createsBothTablesOnMysql(): void
    {
        static::assertSame([self::MYSQL_SCHEDULE, self::MYSQL_RUN], $this->preview('MySQL', false, false));
    }

    #[Test]
    public function createsOnlyTheMissingTableOnMysql(): void
    {
        static::assertSame(
            [[self::MYSQL_RUN], [self::MYSQL_SCHEDULE], []],
            [
                $this->preview('MySQL', true, false),
                $this->preview('MySQL', false, true),
                $this->preview('MySQL', true, true),
            ],
        );
    }

    #[Test]
    public function createsOnlyTheMissingTableOnSqlite(): void
    {
        static::assertSame(
            [
                [self::SQLITE_RUN, ...self::SQLITE_INDEXES],
                [self::SQLITE_SCHEDULE, ...self::SQLITE_INDEXES],
                self::SQLITE_INDEXES,
            ],
            [
                $this->preview('SQLite', true, false),
                $this->preview('SQLite', false, true),
                $this->preview('SQLite', true, true),
            ],
        );
    }

    #[Test]
    public function describesItself(): void
    {
        $migration = new Version20261019000300CreateQueueSchedule();

        static::assertSame(
            ['20261019000300', 'Create the queue_schedule and queue_schedule_run tables'],
            [$migration->getVersion(), $migration->getDescription()],
        );
    }

    /**
     * @return array<string>
     */
    private function preview(string $platform, bool $schedule, bool $run): array
    {
        $inspector = $this->createStub(SchemaInspector::class);
        $inspector->method('tableExists')
            ->willReturnMap([
                ['queue_schedule',     $schedule],
                ['queue_schedule_run', $run],
            ]);

        return (new Version20261019000300CreateQueueSchedule())->preview($this->adapterNamed($platform), $inspector);
    }
}
