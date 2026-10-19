<?php

declare(strict_types=1);

namespace Contenir\Queue\Tests\Unit\Schedule;

use Contenir\Queue\Schedule\Frequency;
use Contenir\Queue\Schedule\Schedule;
use Contenir\Queue\Schedule\Schedules;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

#[Group('unit')]
final class SchedulesTest extends TestCase
{
    #[Test]
    public function findsAScheduleByName(): void
    {
        $schedules = new Schedules();
        $schedule  = new Schedule('a', 'job', Frequency::everyMinutes(1));
        $schedules->add($schedule);

        static::assertSame([$schedule, null], [$schedules->get('a'), $schedules->get('b')]);
    }

    #[Test]
    public function listsSchedulesInTheOrderTheyWereAdded(): void
    {
        $schedules = new Schedules();
        $b         = new Schedule('b', 'job', Frequency::everyMinutes(1));
        $a         = new Schedule('a', 'job', Frequency::everyMinutes(1));
        $schedules->add($b);
        $schedules->add($a);

        static::assertSame([$b, $a], $schedules->all());
    }

    #[Test]
    public function refusesASecondScheduleWithTheSameName(): void
    {
        $schedules = new Schedules();
        $schedules->add(new Schedule('a', 'job', Frequency::everyMinutes(1)));

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('There is already a schedule named "a"');

        $schedules->add(new Schedule('a', 'other', Frequency::everyMinutes(5)));
    }
}
