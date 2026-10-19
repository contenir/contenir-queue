<?php

declare(strict_types=1);

namespace Contenir\Queue\Tests\Unit\Container;

use Contenir\Queue\Container\DrainerFactory;
use Contenir\Queue\Container\HousekeepingFactory;
use Contenir\Queue\Container\PhpDbWorkerStoreFactory;
use Contenir\Queue\Container\WorkerFactory;
use Contenir\Queue\Job\JobRunner;
use Contenir\Queue\Storage\JobStoreInterface;
use Contenir\Queue\Storage\PhpDbWorkerStore;
use Contenir\Queue\Storage\WorkerStoreInterface;
use Contenir\Queue\Tests\TestAsset\Container\ArrayContainer;
use Contenir\Queue\Tests\TestAsset\Log\RecordingLogger;
use Contenir\Queue\Tests\TestAsset\Worker\WorkerKit;
use Contenir\Queue\Worker\Housekeeping;
use Contenir\Queue\Worker\StopReason;
use Contenir\Queue\Worker\WorkerOptions;
use PhpDb\Adapter\AdapterInterface;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Psr\Clock\ClockInterface;
use Psr\Log\LoggerInterface;

#[Group('unit')]
final class WorkerFactoriesTest extends TestCase
{
    #[Test]
    public function buildsHousekeepingOnTheConfiguredServices(): void
    {
        $kit    = new WorkerKit();
        $logger = new RecordingLogger();
        $kit->push();
        $job = $kit->jobs->claimNext([], 'w', $kit->clock->now());
        self::assertNotNull($job);
        $kit->jobs->complete($job, $kit->clock->now());
        $kit->clock->advance(86_400 * 2);

        $housekeeping = (new HousekeepingFactory())($this->container($kit, $logger));

        static::assertSame(
            [Housekeeping::class, 1, ['info: Deleted 1 done jobs older than 1 days']],
            [$housekeeping::class, $housekeeping->pruneDone(1), $logger->lines],
        );
    }

    #[Test]
    public function buildsTheDrainerWithTheConfiguredOptions(): void
    {
        $kit = new WorkerKit();
        $kit->push(3);

        $drainer = (new DrainerFactory())($this->container($kit, new RecordingLogger(), [
            'drain' => ['max_jobs' => 2],
        ]));

        static::assertSame(2, $drainer->drain(WorkerKit::identity()));
    }

    #[Test]
    public function buildsTheWorkerOnTheConfiguredServices(): void
    {
        $kit    = new WorkerKit();
        $logger = new RecordingLogger();
        $kit->push();

        $worker = (new WorkerFactory())($this->container($kit, $logger));
        $report = $worker->run(new WorkerOptions(maxJobs: 1), WorkerKit::identity());

        static::assertSame(
            [StopReason::MaxJobs, 1, 'info: Worker default started on web1:4321', 'web1'],
            [$report->reason, $report->jobsRun, $logger->lines[0], $kit->workers->find('default')?->host],
        );
    }

    #[Test]
    public function buildsTheWorkerStoreOnTheConfiguredAdapter(): void
    {
        $store = (new PhpDbWorkerStoreFactory())(new ArrayContainer([
            AdapterInterface::class => $this->createStub(AdapterInterface::class),
        ]));

        static::assertInstanceOf(PhpDbWorkerStore::class, $store);
    }

    /**
     * @param array<string, mixed> $settings
     */
    private function container(WorkerKit $kit, RecordingLogger $logger, array $settings = []): ArrayContainer
    {
        return new ArrayContainer([
            'config'                    => ['contenir_queue' => $settings],
            JobStoreInterface::class    => $kit->jobs,
            JobRunner::class            => $kit->runner,
            Housekeeping::class         => $kit->housekeeping,
            WorkerStoreInterface::class => $kit->workers,
            ClockInterface::class       => $kit->clock,
            LoggerInterface::class      => $logger,
        ]);
    }
}
