<?php

declare(strict_types=1);

namespace Contenir\Queue\Tests\Unit\Container;

use Contenir\Queue\Container\PhpDbScheduleStoreFactory;
use Contenir\Queue\Container\QueueMonitorFactory;
use Contenir\Queue\Container\SchedulerFactory;
use Contenir\Queue\Container\SchedulesFactory;
use Contenir\Queue\Queue;
use Contenir\Queue\QueueInterface;
use Contenir\Queue\Schedule\Frequency;
use Contenir\Queue\Schedule\Schedule;
use Contenir\Queue\Schedule\Schedules;
use Contenir\Queue\Storage\JobBrowserInterface;
use Contenir\Queue\Storage\PhpDbScheduleStore;
use Contenir\Queue\Storage\ScheduleStoreInterface;
use Contenir\Queue\Storage\WorkerStoreInterface;
use Contenir\Queue\Tests\TestAsset\Container\ArrayContainer;
use Contenir\Queue\Tests\TestAsset\Log\RecordingLogger;
use Contenir\Queue\Tests\TestAsset\Worker\WorkerKit;
use PhpDb\Adapter\AdapterInterface;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Psr\Clock\ClockInterface;
use Psr\Log\LoggerInterface;

#[Group('unit')]
final class ScheduleFactoriesTest extends TestCase
{
    #[Test]
    public function buildsTheMonitorOnTheConfiguredServices(): void
    {
        $kit  = new WorkerKit();
        $jobs = $this->createStub(JobBrowserInterface::class);
        $jobs->method('countByState')->willReturn(['waiting' => 1, 'running' => 0, 'done' => 0, 'failed' => 3]);
        $kit->workers->acquire(
            'default',
            WorkerKit::identity(),
            $kit->clock->now(),
            $kit->clock->now()->modify('-30 seconds'),
        );

        $monitor = (new QueueMonitorFactory())(new ArrayContainer([
            JobBrowserInterface::class    => $jobs,
            ScheduleStoreInterface::class => $kit->scheduleStore,
            Schedules::class              => $kit->schedules,
            WorkerStoreInterface::class   => $kit->workers,
            ClockInterface::class         => $kit->clock,
        ]));
        $health = $monitor->health();

        static::assertSame([30, 3], [$health->heartbeatAge, $health->failed]);
    }

    #[Test]
    public function buildsTheSchedulerOnTheConfiguredServices(): void
    {
        $kit    = new WorkerKit();
        $logger = new RecordingLogger();
        $kit->schedules->add(new Schedule('sync', 'test.handler', Frequency::everyMinutes(1)));

        $scheduler = (new SchedulerFactory())(new ArrayContainer([
            Schedules::class              => $kit->schedules,
            ScheduleStoreInterface::class => $kit->scheduleStore,
            QueueInterface::class         => new Queue($kit->jobs, $kit->clock),
            ClockInterface::class         => $kit->clock,
            LoggerInterface::class        => $logger,
        ]));

        static::assertSame([1, ['info: Schedule sync queued job 1']], [$scheduler->tick(), $logger->lines]);
    }

    #[Test]
    public function buildsTheSchedulesFromConfiguration(): void
    {
        $schedules = (new SchedulesFactory())(new ArrayContainer([
            'config' => ['contenir_queue' => ['schedules' => ['sync' => ['job' => 'j', 'every_minutes' => 5]]]],
        ]));

        static::assertSame('sync', $schedules->get('sync')?->name);
    }

    #[Test]
    public function buildsTheScheduleStoreOnTheConfiguredAdapter(): void
    {
        $store = (new PhpDbScheduleStoreFactory())(new ArrayContainer([
            AdapterInterface::class => $this->createStub(AdapterInterface::class),
        ]));

        static::assertInstanceOf(PhpDbScheduleStore::class, $store);
    }
}
