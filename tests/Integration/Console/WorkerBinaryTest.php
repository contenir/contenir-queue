<?php

declare(strict_types=1);

namespace Contenir\Queue\Tests\Integration\Console;

use Contenir\Queue\Console\WorkerCommand;
use Contenir\Queue\Queue;
use Contenir\Queue\Storage\PhpDbJobStore;
use Contenir\Queue\Tests\TestAsset\Clock\MutableClock;
use Contenir\Queue\Tests\TestAsset\Database\TestDatabase;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

use function dirname;
use function escapeshellarg;
use function exec;
use function implode;
use function is_file;
use function sys_get_temp_dir;
use function tempnam;
use function unlink;

use const PHP_BINARY;

/**
 * bin/contenir-queue as cron runs it, against an SQLite file.
 */
#[Group('integration')]
#[Group('slow')]
final class WorkerBinaryTest extends TestCase
{
    private string $database;

    private static function fixture(): string
    {
        return dirname(__DIR__, levels: 2) . '/TestAsset/Console/sqlite-container.php';
    }

    #[Test]
    public function findsTheContainerFromTheEnvironment(): void
    {
        [$code, $output] = $this->runBinary('--max-time=1', '--sleep=1');

        static::assertSame(
            [
                WorkerCommand::OK,
                'Worker default stopped (time up): 0 jobs run, 0 stale jobs recovered, 0 old jobs deleted.',
            ],
            [$code, $output],
        );
    }

    #[Test]
    public function reportsAMissingContainer(): void
    {
        [$code, $output] = $this->runBinary('--container=/no/such/container.php');

        static::assertSame(
            [WorkerCommand::ERROR, 'The container file "/no/such/container.php" does not exist'],
            [$code, $output],
        );
    }

    #[Test]
    public function runsDueJobsAndReports(): void
    {
        $store = new PhpDbJobStore(TestDatabase::sqliteFile($this->database));
        $id    = (new Queue($store, new MutableClock('-1 minute')))->push('sync');

        [$code, $output] = $this->runBinary('--container=' . self::fixture(), '--max-jobs=1');

        static::assertSame(
            [
                WorkerCommand::OK,
                'Worker default stopped (job limit): 1 jobs run, 0 stale jobs recovered, 0 old jobs deleted.',
                'done',
            ],
            [$code, $output, $store->find($id)?->state->value],
        );
    }

    protected function setUp(): void
    {
        $this->database = (string) tempnam(sys_get_temp_dir(), prefix: 'contenir-queue-');
        $runner         = TestDatabase::migrations(TestDatabase::sqliteFile($this->database));
        $runner->ensureMigrationsTable();
        $runner->runPending();
    }

    protected function tearDown(): void
    {
        if (is_file($this->database)) {
            unlink($this->database);
        }
    }

    /**
     * @return array{int, string}
     */
    private function runBinary(string ...$args): array
    {
        $command =
            'CONTENIR_QUEUE_TEST_SQLITE='
            . escapeshellarg($this->database)
            . ' CONTENIR_QUEUE_CONTAINER='
            . escapeshellarg(self::fixture())
            . ' '
            . escapeshellarg(PHP_BINARY)
            . ' '
            . escapeshellarg(dirname(__DIR__, levels: 3) . '/bin/contenir-queue');
        foreach ($args as $arg) {
            $command .= ' ' . escapeshellarg($arg);
        }

        $lines = [];
        exec("{$command} 2>&1", $lines, $code);

        return [$code, implode("\n", $lines)];
    }
}
