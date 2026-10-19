<?php

declare(strict_types=1);

namespace Contenir\Queue\Tests\Unit\Console;

use Contenir\Queue\Console\WorkerCommand;
use Contenir\Queue\Exception\StorageException;
use Contenir\Queue\Job\JobState;
use Contenir\Queue\Storage\JobStoreInterface;
use Contenir\Queue\Tests\TestAsset\Worker\WorkerKit;
use Contenir\Queue\Worker\WorkerOptions;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

#[Group('unit')]
final class WorkerCommandTest extends TestCase
{
    private string $output = '';

    /**
     * @return array<string, array{list<string>, string}>
     */
    public static function badArgumentProvider(): array
    {
        return [
            'unknown option'        => [['--fast'], 'Unknown option "--fast"'],
            'time not a number'     => [['--max-time=soon'], '--max-time needs a whole number, not "soon"'],
            'time missing'          => [['--max-time'], '--max-time needs a whole number, not ""'],
            'negative jobs'         => [['--max-jobs=-1'], '--max-jobs needs a whole number, not "-1"'],
            'sleep with a decimal'  => [['--sleep=1.5'], '--sleep needs a whole number, not "1.5"'],
            'memory with a newline' => [["--memory-limit=5\n"], "--memory-limit needs a whole number, not \"5\n\""],
        ];
    }

    #[Test]
    public function anEmptyQueueListMeansEveryQueue(): void
    {
        $kit  = new WorkerKit();
        $mail = $kit->push(1, 'mail');

        $this->command($kit, new WorkerOptions(queues: ['sync']))->run(['--queue=,', '--max-time=1']);

        static::assertSame(JobState::Done, $kit->jobs->find($mail[0])?->state);
    }

    #[Test]
    public function aValueMayContainAnEqualsSign(): void
    {
        $kit = new WorkerKit();

        $this->command($kit, new WorkerOptions())->run(['--name=night=shift', '--max-jobs=0', '--max-time=1']);

        static::assertStringStartsWith('Worker night=shift stopped', $this->output);
    }

    #[Test]
    public function daemonRunsWithoutATimeLimit(): void
    {
        $sleeps = 0;
        $kit    = new WorkerKit(onSleep: static function () use (&$sleeps, &$command): void {
            if (++$sleeps === 2) {
                throw StorageException::noResult();
            }
        });

        $this->command($kit, new WorkerOptions(maxSeconds: 5))->run(['--daemon', '--sleep=30']);

        static::assertSame([30, 30], $kit->process->sleeps);
    }

    #[Test]
    public function helpShowsTheUsageAndRunsNothing(): void
    {
        $kit = new WorkerKit();
        $kit->push();

        $codes = [
            $this->command($kit, new WorkerOptions())->run(['--help']),
            $this->command($kit, new WorkerOptions())->run(['-h', '--max-jobs=1']),
            $this->command($kit, new WorkerOptions())->run(['--max-jobs=1', '--help']),
        ];

        static::assertSame(
            [[0, 0, 0], WorkerCommand::HELP . WorkerCommand::HELP . WorkerCommand::HELP, null],
            [$codes, $this->output, $kit->workers->find('default')],
        );
    }

    #[Test]
    public function maxTimeSetsTheTimeLimit(): void
    {
        $kit = new WorkerKit(secondsPerJob: 4);
        $kit->push(3);

        $this->command($kit, new WorkerOptions())->run(['--max-time=7']);

        static::assertStringStartsWith('Worker default stopped (time up): 2 jobs run', $this->output);
    }

    #[Test]
    public function memoryJustUnderTheLimitKeepsWorking(): void
    {
        $kit                  = new WorkerKit();
        $kit->process->memory = (2 * 1024 * 1024) - 1;
        $kit->push();

        $this->command($kit, new WorkerOptions())->run(['--memory-limit=2', '--max-jobs=1']);

        static::assertStringContainsString('(job limit)', $this->output);
    }

    #[Test]
    public function memoryLimitIsInMegabytes(): void
    {
        $kit                  = new WorkerKit();
        $kit->process->memory = 2 * 1024 * 1024;

        $this->command($kit, new WorkerOptions())->run(['--memory-limit=2']);

        static::assertStringContainsString('(memory limit)', $this->output);
    }

    #[Test]
    public function onlyTheNamedQueuesAreWorked(): void
    {
        $kit  = new WorkerKit();
        $mail = $kit->push(1, 'mail');
        $sync = $kit->push(1, 'sync');

        $this->command($kit, new WorkerOptions())->run(['--queue= sync ,', '--max-time=1']);

        static::assertSame(
            [JobState::Waiting, JobState::Done],
            [$kit->jobs->find($mail[0])?->state, $kit->jobs->find($sync[0])?->state],
        );
    }

    #[Test]
    public function optionsOverrideTheConfiguredSettings(): void
    {
        $kit = new WorkerKit();
        $kit->push(3, 'mail');
        $kit->push(1, 'sync');

        $this->command($kit, new WorkerOptions(maxSeconds: 50))->run([
            '--container=/ignored.php',
            '--queue=mail, ,sync',
            '--max-jobs=4',
            '--name=nightly',
            '--sleep=1',
            '--memory-limit=512',
        ]);

        static::assertSame(
            "Worker nightly stopped (job limit): 4 jobs run, 0 stale jobs recovered, 0 old jobs deleted.\n",
            $this->output,
        );
    }

    /**
     * @param list<string> $args
     */
    #[Test]
    #[DataProvider('badArgumentProvider')]
    public function refusesBadArguments(array $args, string $message): void
    {
        $code = $this->command(new WorkerKit(), new WorkerOptions())->run($args);

        static::assertSame([WorkerCommand::USAGE, "{$message}\n\n" . WorkerCommand::HELP], [$code, $this->output]);
    }

    #[Test]
    public function reportsAStorageFailure(): void
    {
        $jobs = $this->createStub(JobStoreInterface::class);
        $jobs->method('findStale')->willThrowException(StorageException::noResult());

        $code = $this->command(new WorkerKit($jobs), new WorkerOptions())->run([]);

        static::assertSame(
            [WorkerCommand::ERROR, "The database driver returned no result\n"],
            [$code, $this->output],
        );
    }

    #[Test]
    public function reportsAWorkerAlreadyRunning(): void
    {
        $kit = new WorkerKit();
        $kit->workers->hijack('default', 'other', $kit->clock->now()->modify('+1 minute'));

        $code = $this->command($kit, new WorkerOptions())->run([]);

        static::assertSame([WorkerCommand::OK, "Worker default is already running.\n"], [$code, $this->output]);
    }

    #[Test]
    public function runsTheWorkerAndReportsWhatItDid(): void
    {
        $kit = new WorkerKit();
        $kit->push(2);

        $code = $this->command($kit, new WorkerOptions(maxJobs: 2))->run([]);

        static::assertSame(
            [
                WorkerCommand::OK,
                "Worker default stopped (job limit): 2 jobs run, 0 stale jobs recovered, 0 old jobs deleted.\n",
            ],
            [$code, $this->output],
        );
    }

    private function command(WorkerKit $kit, WorkerOptions $defaults): WorkerCommand
    {
        $output = &$this->output;

        return new WorkerCommand($kit->worker(), $defaults, static function (string $text) use (&$output): void {
            $output .= $text;
        });
    }
}
