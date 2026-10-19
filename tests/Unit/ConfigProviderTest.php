<?php

declare(strict_types=1);

namespace Contenir\Queue\Tests\Unit;

use Contenir\Queue\ConfigProvider;
use Contenir\Queue\Container\DrainerFactory;
use Contenir\Queue\Container\HousekeepingFactory;
use Contenir\Queue\Container\JobRunnerFactory;
use Contenir\Queue\Container\PhpDbJobStoreFactory;
use Contenir\Queue\Container\PhpDbScheduleStoreFactory;
use Contenir\Queue\Container\PhpDbWorkerStoreFactory;
use Contenir\Queue\Container\QueueFactory;
use Contenir\Queue\Container\QueueMonitorFactory;
use Contenir\Queue\Container\SchedulerFactory;
use Contenir\Queue\Container\SchedulesFactory;
use Contenir\Queue\Container\WorkerFactory;
use Contenir\Queue\Job\JobRunner;
use Contenir\Queue\Monitor\QueueMonitor;
use Contenir\Queue\Queue;
use Contenir\Queue\QueueInterface;
use Contenir\Queue\Schedule\Scheduler;
use Contenir\Queue\Schedule\Schedules;
use Contenir\Queue\Schema\Version20261019000100CreateQueueJob;
use Contenir\Queue\Storage\JobBrowserInterface;
use Contenir\Queue\Storage\JobStoreInterface;
use Contenir\Queue\Storage\PhpDbJobStore;
use Contenir\Queue\Storage\PhpDbScheduleStore;
use Contenir\Queue\Storage\PhpDbWorkerStore;
use Contenir\Queue\Storage\ScheduleStoreInterface;
use Contenir\Queue\Storage\WorkerStoreInterface;
use Contenir\Queue\Worker\Drainer;
use Contenir\Queue\Worker\Housekeeping;
use Contenir\Queue\Worker\Worker;
use PhpDb\Adapter\AdapterInterface;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Psr\Clock\ClockInterface;
use Psr\Log\LoggerInterface;
use ReflectionClass;

use function dirname;
use function realpath;

#[Group('unit')]
final class ConfigProviderTest extends TestCase
{
    #[Test]
    public function defaultSettings(): void
    {
        static::assertSame(
            [
                'adapter'  => AdapterInterface::class,
                'clock'    => ClockInterface::class,
                'logger'   => LoggerInterface::class,
                'handlers' => '',
            ],
            (new ConfigProvider())()['contenir_queue'],
        );
    }

    #[Test]
    public function dependenciesCanBeReadOnTheirOwn(): void
    {
        static::assertSame((new ConfigProvider())()['dependencies'], (new ConfigProvider())->getDependencies());
    }

    #[Test]
    public function listsTheMigrations(): void
    {
        static::assertSame(
            ['schema_migrations' => [
                (new ReflectionClass(Version20261019000100CreateQueueJob::class))->getNamespaceName() =>
                    ConfigProvider::schemaPath(),
            ]],
            (new ConfigProvider())()['contenir'],
        );
    }

    #[Test]
    public function registersTheServices(): void
    {
        static::assertSame(
            [
                'aliases'   => [
                    QueueInterface::class         => Queue::class,
                    JobBrowserInterface::class    => PhpDbJobStore::class,
                    JobStoreInterface::class      => PhpDbJobStore::class,
                    ScheduleStoreInterface::class => PhpDbScheduleStore::class,
                    WorkerStoreInterface::class   => PhpDbWorkerStore::class,
                ],
                'factories' => [
                    Drainer::class            => DrainerFactory::class,
                    Housekeeping::class       => HousekeepingFactory::class,
                    JobRunner::class          => JobRunnerFactory::class,
                    PhpDbJobStore::class      => PhpDbJobStoreFactory::class,
                    PhpDbScheduleStore::class => PhpDbScheduleStoreFactory::class,
                    PhpDbWorkerStore::class   => PhpDbWorkerStoreFactory::class,
                    Queue::class              => QueueFactory::class,
                    QueueMonitor::class       => QueueMonitorFactory::class,
                    Scheduler::class          => SchedulerFactory::class,
                    Schedules::class          => SchedulesFactory::class,
                    Worker::class             => WorkerFactory::class,
                ],
            ],
            (new ConfigProvider())()['dependencies'],
        );
    }

    #[Test]
    public function schemaPathIsTheSchemaDirectory(): void
    {
        static::assertSame(
            realpath(dirname(__DIR__, levels: 2) . '/schema'),
            realpath(ConfigProvider::schemaPath()),
        );
    }
}
