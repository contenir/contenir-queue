<?php

declare(strict_types=1);

namespace Contenir\Queue\Tests\Unit\Worker;

use Contenir\Queue\Worker\StopReason;
use Contenir\Queue\Worker\WorkerReport;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

#[Group('unit')]
final class WorkerReportTest extends TestCase
{
    #[Test]
    public function aRunThatDidNothingCountsNothing(): void
    {
        $report = new WorkerReport(StopReason::AlreadyRunning);

        static::assertSame([0, 0, 0], [$report->jobsRun, $report->recovered, $report->pruned]);
    }
}
