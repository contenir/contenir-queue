<?php

declare(strict_types=1);

namespace Contenir\Queue\Schema;

use Override;
use PhpDb\Migration\AbstractMigration;
use UnexpectedValueException;

/**
 * The `queue_worker` table: one row per worker name, holding the lock that
 * lets only one worker run at a time and the heartbeat each run writes.
 *
 * @internal Run by the host's migration runner.
 *
 * @mago-expect analysis:missing-constructor AbstractMigration sets its adapter and inspector in up() and preview().
 */
final class Version20261019000200CreateQueueWorker extends AbstractMigration
{
    #[Override]
    public function getDescription(): string
    {
        return 'Create the queue_worker table';
    }

    #[Override]
    public function getVersion(): string
    {
        return '20261019000200';
    }

    /**
     * @throws UnexpectedValueException On a database other than MySQL or SQLite.
     */
    #[Override]
    protected function define(): void
    {
        $exists = $this->inspector->tableExists('queue_worker');

        if (Platform::isMysql($this->adapter)) {
            $this->executeSqlIf(
                ! $exists,
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
                'Create table "queue_worker"',
                'Table "queue_worker" already exists',
            );

            return;
        }

        $this->executeSqlIf(
            ! $exists,
            'CREATE TABLE "queue_worker" ('
                . ' "name" VARCHAR(64) NOT NULL PRIMARY KEY,'
                . ' "owner" VARCHAR(64) NULL,'
                . ' "locked_until" DATETIME NULL,'
                . ' "heartbeat_at" DATETIME NULL,'
                . ' "started_at" DATETIME NULL,'
                . ' "host" VARCHAR(255) NULL,'
                . ' "pid" INTEGER NULL'
                . ')',
            'Create table "queue_worker"',
            'Table "queue_worker" already exists',
        );
    }
}
