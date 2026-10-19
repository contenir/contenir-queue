<?php

declare(strict_types=1);

namespace Contenir\Queue\Schema;

use Override;
use PhpDb\Migration\AbstractMigration;
use UnexpectedValueException;

/**
 * The `queue_schedule` table, one row per schedule that has run, and
 * `queue_schedule_run`, one row each time a schedule fell due.
 *
 * @internal Run by the host's migration runner.
 *
 * @mago-expect analysis:missing-constructor AbstractMigration sets its adapter and inspector in up() and preview().
 */
final class Version20261019000300CreateQueueSchedule extends AbstractMigration
{
    #[Override]
    public function getDescription(): string
    {
        return 'Create the queue_schedule and queue_schedule_run tables';
    }

    #[Override]
    public function getVersion(): string
    {
        return '20261019000300';
    }

    /**
     * @throws UnexpectedValueException On a database other than MySQL or SQLite.
     */
    #[Override]
    protected function define(): void
    {
        $schedule = $this->inspector->tableExists('queue_schedule');
        $run      = $this->inspector->tableExists('queue_schedule_run');

        if (Platform::isMysql($this->adapter)) {
            $this->executeSqlIf(
                ! $schedule,
                'CREATE TABLE `queue_schedule` ('
                    . ' `name` VARCHAR(128) NOT NULL,'
                    . ' `next_run_at` DATETIME NOT NULL,'
                    . ' `last_run_at` DATETIME NULL,'
                    . ' `last_job_id` BIGINT UNSIGNED NULL,'
                    . ' `updated_at` DATETIME NOT NULL,'
                    . ' PRIMARY KEY (`name`)'
                    . ') ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci',
                'Create table "queue_schedule"',
                'Table "queue_schedule" already exists',
            );
            $this->executeSqlIf(
                ! $run,
                'CREATE TABLE `queue_schedule_run` ('
                    . ' `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,'
                    . ' `schedule` VARCHAR(128) NOT NULL,'
                    . ' `due_at` DATETIME NOT NULL,'
                    . ' `recorded_at` DATETIME NOT NULL,'
                    . ' `job_id` BIGINT UNSIGNED NULL,'
                    . ' PRIMARY KEY (`id`),'
                    . ' KEY `queue_schedule_run_schedule` (`schedule`, `due_at`),'
                    . ' KEY `queue_schedule_run_recorded` (`recorded_at`)'
                    . ') ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci',
                'Create table "queue_schedule_run"',
                'Table "queue_schedule_run" already exists',
            );

            return;
        }

        $this->executeSqlIf(
            ! $schedule,
            'CREATE TABLE "queue_schedule" ('
                . ' "name" VARCHAR(128) NOT NULL PRIMARY KEY,'
                . ' "next_run_at" DATETIME NOT NULL,'
                . ' "last_run_at" DATETIME NULL,'
                . ' "last_job_id" INTEGER NULL,'
                . ' "updated_at" DATETIME NOT NULL'
                . ')',
            'Create table "queue_schedule"',
            'Table "queue_schedule" already exists',
        );
        $this->executeSqlIf(
            ! $run,
            'CREATE TABLE "queue_schedule_run" ('
                . ' "id" INTEGER NOT NULL PRIMARY KEY AUTOINCREMENT,'
                . ' "schedule" VARCHAR(128) NOT NULL,'
                . ' "due_at" DATETIME NOT NULL,'
                . ' "recorded_at" DATETIME NOT NULL,'
                . ' "job_id" INTEGER NULL'
                . ')',
            'Create table "queue_schedule_run"',
            'Table "queue_schedule_run" already exists',
        );
        $this->executeSql(
            'CREATE INDEX IF NOT EXISTS "queue_schedule_run_schedule" ON "queue_schedule_run" ("schedule", "due_at")',
            'Index "queue_schedule_run.queue_schedule_run_schedule"',
        );
        $this->executeSql(
            'CREATE INDEX IF NOT EXISTS "queue_schedule_run_recorded" ON "queue_schedule_run" ("recorded_at")',
            'Index "queue_schedule_run.queue_schedule_run_recorded"',
        );
    }
}
