<?php

declare(strict_types=1);

namespace Contenir\Queue\Tests\Unit\Schedule;

use Contenir\Queue\Job\JobState;
use Contenir\Queue\Schedule\ScheduleRun;
use Contenir\Queue\Schedule\ScheduleState;
use DateTimeImmutable;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

#[Group('unit')]
final class ScheduleStateTest extends TestCase
{
    /**
     * @return array<string, array{?JobState, bool}>
     */
    public static function lastJobProvider(): array
    {
        return [
            'never ran' => [null, false],
            'waiting'   => [JobState::Waiting, true],
            'running'   => [JobState::Running, true],
            'done'      => [JobState::Done, false],
            'failed'    => [JobState::Failed, false],
        ];
    }

    #[Test]
    public function aNewStateHasNoLastRun(): void
    {
        $state = new ScheduleState('a', new DateTimeImmutable());

        static::assertSame([null, null, null], [$state->lastRunAt, $state->lastJobId, $state->lastJobState]);
    }

    #[Test]
    public function aRunWithoutAJobWasSkipped(): void
    {
        $at = new DateTimeImmutable();

        static::assertSame(
            [true, false],
            [
                (new ScheduleRun(1, 'a', $at, $at, null, null))->wasSkipped(),
                (new ScheduleRun(2, 'a', $at, $at, 5, null))->wasSkipped(),
            ],
        );
    }

    #[Test]
    #[DataProvider('lastJobProvider')]
    public function theLastRunIsUnfinishedWhileItsJobWaitsOrRuns(?JobState $state, bool $expected): void
    {
        static::assertSame(
            $expected,
            (new ScheduleState('a', new DateTimeImmutable(), null, 1, $state))->isLastRunUnfinished(),
        );
    }
}
