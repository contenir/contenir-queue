<?php

declare(strict_types=1);

namespace Contenir\Queue\Schema;

use Override;
use PhpDb\Migration\AbstractMigration;
use UnexpectedValueException;

/**
 * The `queue_job` table: one row per job, kept after it finishes so the host
 * can show what ran and what failed.
 *
 * @internal Run by the host's migration runner.
 *
 * @mago-expect analysis:missing-constructor AbstractMigration sets its adapter and inspector in up() and preview().
 */
final class Version20261019000100CreateQueueJob extends AbstractMigration
{
    #[Override]
    public function getDescription(): string
    {
        return 'Create the queue_job table';
    }

    #[Override]
    public function getVersion(): string
    {
        return '20261019000100';
    }

    /**
     * @throws UnexpectedValueException On a database other than MySQL or SQLite.
     */
    #[Override]
    protected function define(): void
    {
        $exists = $this->inspector->tableExists('queue_job');

        if (Platform::isMysql($this->adapter)) {
            $this->executeSqlIf(
                ! $exists,
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
                'Create table "queue_job"',
                'Table "queue_job" already exists',
            );

            return;
        }

        $this->executeSqlIf(
            ! $exists,
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
            'Create table "queue_job"',
            'Table "queue_job" already exists',
        );
        $this->executeSql(
            'CREATE INDEX IF NOT EXISTS "queue_job_due" ON "queue_job" ("state", "next_attempt_at")',
            'Index "queue_job.queue_job_due"',
        );
        $this->executeSql(
            'CREATE INDEX IF NOT EXISTS "queue_job_queue" ON "queue_job" ("queue", "state")',
            'Index "queue_job.queue_job_queue"',
        );
    }
}
