<?php

declare(strict_types=1);

namespace Contenir\Queue\Tests\Integration\Storage;

use Contenir\Queue\Job\JobState;
use Contenir\Queue\Schedule\ScheduleRun;
use Contenir\Queue\Schedule\ScheduleState;
use Contenir\Queue\Storage\DbTime;
use Contenir\Queue\Storage\PhpDbJobStore;
use Contenir\Queue\Storage\PhpDbScheduleStore;
use Contenir\Queue\Tests\Trait\TestDatabaseTrait;
use DateTimeImmutable;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

use function array_map;
use function strlen;

#[Group('integration')]
#[Group('repository')]
final class PhpDbScheduleStoreTest extends TestCase
{
    use TestDatabaseTrait;

    private PhpDbJobStore $jobs;

    private PhpDbScheduleStore $store;

    private static function at(string $time): DateTimeImmutable
    {
        return DbTime::parse('2026-10-19 ' . (5 === strlen($time) ? "{$time}:00" : $time));
    }

    #[Test]
    public function aDeletedLastJobHasNoState(): void
    {
        $this->store->start('sync', self::at('09:05'), self::at('09:00'));
        $this->store->recordRun('sync', self::at('09:05'), 999, self::at('09:05'));

        static::assertSame([999, null], [
            $this->store->states()['sync']->lastJobId,
            $this->store->states()['sync']->lastJobState,
        ]);
    }

    #[Test]
    public function advanceMovesOnlyItsSchedule(): void
    {
        $this->store->start('a', self::at('09:05'), self::at('09:00'));
        $this->store->start('b', self::at('09:05'), self::at('09:00'));

        $this->store->advance('a', self::at('09:05'), self::at('09:10'), self::at('09:05'));

        static::assertSame('09:05:00', $this->store->states()['b']->nextRunAt->format('H:i:s'));
    }

    #[Test]
    public function advanceRefusesARunAlreadyTaken(): void
    {
        $this->store->start('sync', self::at('09:05'), self::at('09:00'));
        $this->store->advance('sync', self::at('09:05'), self::at('09:10'), self::at('09:05'));

        static::assertSame(
            [false, false, '09:10:00'],
            [
                $this->store->advance('sync', self::at('09:05'), self::at('09:15'), self::at('09:05')),
                $this->store->advance('other', self::at('09:10'), self::at('09:15'), self::at('09:05')),
                $this->store->states()['sync']->nextRunAt->format('H:i:s'),
            ],
        );
    }

    #[Test]
    public function advanceTakesTheDueRun(): void
    {
        $this->store->start('sync', self::at('09:05'), self::at('09:00'));

        $advanced = $this->store->advance('sync', self::at('09:05'), self::at('09:10'), self::at('09:05:02'));

        static::assertEquals(
            [true, new ScheduleState('sync', self::at('09:10'), self::at('09:05'))],
            [$advanced, $this->store->states()['sync']],
        );
    }

    #[Test]
    public function aQueuedRunBecomesTheLastJob(): void
    {
        $this->store->start('sync', self::at('09:05'), self::at('09:00'));
        $this->store->start('other', self::at('09:05'), self::at('09:00'));
        $job = $this->jobs->push('default', 'j', '[]', self::at('09:05'), self::at('09:05'));

        $this->store->recordRun('sync', self::at('09:05'), $job, self::at('09:05'));

        static::assertEquals(
            [
                'sync'  => new ScheduleState('sync', self::at('09:05'), null, $job, JobState::Waiting),
                'other' => new ScheduleState('other', self::at('09:05')),
            ],
            $this->store->states(),
        );
    }

    #[Test]
    public function aScheduleStartsOnce(): void
    {
        $this->store->start('sync', self::at('09:05'), self::at('09:00'));

        static::assertSame(
            [false, '09:05:00'],
            [
                $this->store->start('sync', self::at('09:10'), self::at('09:01')),
                $this->store->states()['sync']->nextRunAt->format('H:i:s'),
            ],
        );
    }

    #[Test]
    public function aSkippedRunKeepsTheLastJob(): void
    {
        $this->store->start('sync', self::at('09:05'), self::at('09:00'));
        $job = $this->jobs->push('default', 'j', '[]', self::at('09:05'), self::at('09:05'));
        $this->store->recordRun('sync', self::at('09:05'), $job, self::at('09:05'));

        $this->store->recordRun('sync', self::at('09:10'), null, self::at('09:10'));

        static::assertSame($job, $this->store->states()['sync']->lastJobId);
    }

    #[Test]
    public function noSchedulesAreTrackedAtFirst(): void
    {
        static::assertSame([], $this->store->states());
    }

    #[Test]
    public function pruneRunsDeletesRunsRecordedBeforeTheCutOff(): void
    {
        $this->store->start('sync', self::at('09:00'), self::at('09:00'));
        $this->store->recordRun('sync', self::at('08:00'), null, self::at('08:00'));
        $this->store->recordRun('sync', self::at('08:30'), null, self::at('08:30'));

        static::assertSame(
            [1, [2]],
            [
                $this->store->pruneRuns(self::at('08:30')),
                array_map(static fn(ScheduleRun $run): int => $run->id, $this->store->runs('sync', 10)),
            ],
        );
    }

    #[Test]
    public function runsAreLimited(): void
    {
        $this->store->start('sync', self::at('09:00'), self::at('09:00'));
        $this->store->recordRun('sync', self::at('09:10'), null, self::at('09:10'));
        $this->store->recordRun('sync', self::at('09:00'), null, self::at('09:11'));
        $this->store->recordRun('sync', self::at('09:05'), null, self::at('09:12'));
        $this->store->recordRun('sync', self::at('09:05'), null, self::at('09:13'));

        static::assertSame(
            [1, 4],
            array_map(static fn(ScheduleRun $run): int => $run->id, $this->store->runs('sync', 2)),
        );
    }

    #[Test]
    public function runsAreListedNewestFirstWithTheirJobsState(): void
    {
        $this->store->start('sync', self::at('09:00'), self::at('09:00'));
        $job = $this->jobs->push('default', 'j', '[]', self::at('09:00'), self::at('09:00'));
        $this->store->recordRun('sync', self::at('09:00'), $job, self::at('09:00:01'));
        $this->store->recordRun('sync', self::at('09:05'), null, self::at('09:05:01'));
        $this->store->recordRun('other', self::at('09:07'), null, self::at('09:07'));

        static::assertEquals(
            [
                new ScheduleRun(2, 'sync', self::at('09:05'), self::at('09:05:01'), null, null),
                new ScheduleRun(1, 'sync', self::at('09:00'), self::at('09:00:01'), $job, JobState::Waiting),
            ],
            $this->store->runs('sync', 10),
        );
    }

    #[Test]
    public function startTracksASchedule(): void
    {
        $started = $this->store->start('sync', self::at('09:05'), self::at('09:00'));

        static::assertEquals(
            [true, ['sync' => new ScheduleState('sync', self::at('09:05'))]],
            [$started, $this->store->states()],
        );
    }

    protected function setUp(): void
    {
        $this->setUpDatabase();
        $this->store = new PhpDbScheduleStore($this->adapter);
        $this->jobs  = new PhpDbJobStore($this->adapter);
    }

    protected function tearDown(): void
    {
        $this->tearDownDatabase();
    }
}
