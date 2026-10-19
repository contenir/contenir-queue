<?php

declare(strict_types=1);

namespace Contenir\Queue\Tests\Integration\Schema;

use Contenir\Queue\Schema\Version20261019000100CreateQueueJob;
use Contenir\Queue\Tests\TestAsset\Database\TestDatabase;
use Contenir\Queue\Tests\Trait\TestDatabaseTrait;
use PhpDb\Migration\SchemaInspector;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

use function array_map;
use function sort;

#[Group('integration')]
final class MigrationsTest extends TestCase
{
    use TestDatabaseTrait;

    #[Test]
    public function createsTheJobTable(): void
    {
        $columns = array_map(
            static fn(array $column): string => (string) $column['name'],
            (new SchemaInspector($this->adapter, TestDatabase::metadata($this->adapter)))->getColumns('queue_job'),
        );
        sort($columns);

        static::assertSame(
            [
                'attempts',
                'created_at',
                'finished_at',
                'id',
                'job_class',
                'last_error',
                'locked_at',
                'locked_by',
                'next_attempt_at',
                'payload',
                'queue',
                'state',
                'updated_at',
            ],
            $columns,
        );
    }

    #[Test]
    public function nothingIsPendingAfterMigrating(): void
    {
        static::assertSame([], TestDatabase::migrations($this->adapter)->getPendingMigrations());
    }

    #[Test]
    public function runningAgainOnAnExistingTableChangesNothing(): void
    {
        $result = (new Version20261019000100CreateQueueJob())->up(
            $this->adapter,
            new SchemaInspector($this->adapter, TestDatabase::metadata($this->adapter)),
        );

        static::assertFalse($result->isFailed());
    }

    protected function setUp(): void
    {
        $this->setUpDatabase();
    }

    protected function tearDown(): void
    {
        $this->tearDownDatabase();
    }
}
