<?php

declare(strict_types=1);

namespace Contenir\Queue\Tests\Integration\Worker;

use Contenir\Queue\Job\Job;
use Contenir\Queue\QueueInterface;
use Contenir\Queue\Storage\WorkerStoreInterface;
use Contenir\Queue\Tests\TestAsset\Clock\MutableClock;
use Contenir\Queue\Tests\TestAsset\Container\FactoryContainer;
use Contenir\Queue\Tests\TestAsset\Job\CallbackHandler;
use Contenir\Queue\Tests\TestAsset\Log\RecordingLogger;
use Contenir\Queue\Tests\Trait\TestDatabaseTrait;
use Contenir\Queue\Worker\Drainer;
use Contenir\Queue\Worker\StopReason;
use Contenir\Queue\Worker\Worker;
use Contenir\Queue\Worker\WorkerIdentity;
use Contenir\Queue\Worker\WorkerOptions;
use PhpDb\Adapter\AdapterInterface;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Psr\Clock\ClockInterface;
use Psr\Log\LoggerInterface;

use function array_map;

/**
 * The worker and the drain built from ConfigProvider's factories and run
 * against the real tables.
 */
#[Group('integration')]
#[Group('handler')]
final class WorkerLifecycleTest extends TestCase
{
    use TestDatabaseTrait;

    private MutableClock $clock;

    private FactoryContainer $container;

    private CallbackHandler $handler;

    #[Test]
    public function aSecondWorkerWaitsForTheFirst(): void
    {
        $this->workers()->acquire(
            'default',
            WorkerIdentity::of('web1', 7, 'a'),
            $this->clock->now()->modify('+5 minutes'),
            $this->clock->now(),
        );
        $worker = $this->container->get(Worker::class);
        self::assertInstanceOf(Worker::class, $worker);

        $report = $worker->run(new WorkerOptions(), WorkerIdentity::of('web1', 8, 'b'));

        static::assertSame(StopReason::AlreadyRunning, $report->reason);
    }

    #[Test]
    public function theDrainRunsJobsUntilAWorkerBeats(): void
    {
        $queue = $this->container->get(QueueInterface::class);
        self::assertInstanceOf(QueueInterface::class, $queue);
        $id      = $queue->push('sync');
        $drainer = $this->container->get(Drainer::class);
        self::assertInstanceOf(Drainer::class, $drainer);

        $first = $drainer->drain(WorkerIdentity::of('web1', 9, 'c'));
        $queue->push('sync');
        $this->workers()->acquire(
            'default',
            WorkerIdentity::of('web1', 7, 'a'),
            $this->clock->now(),
            $this->clock->now(),
        );

        static::assertSame([1, 0], [$first, $drainer->drain(WorkerIdentity::of('web1', 9, 'd'))]);
        unset($id);
    }

    #[Test]
    public function theWorkerRunsPushedJobsAndLeavesAHeartbeat(): void
    {
        $queue = $this->container->get(QueueInterface::class);
        self::assertInstanceOf(QueueInterface::class, $queue);
        $queue->push('sync', ['n' => 1]);
        $queue->push('sync', ['n' => 2]);
        $worker = $this->container->get(Worker::class);
        self::assertInstanceOf(Worker::class, $worker);

        $report = $worker->run(new WorkerOptions(maxJobs: 2), WorkerIdentity::of('web1', 7, 'a'));

        static::assertSame(
            [StopReason::MaxJobs, [['n' => 1], ['n' => 2]], 0],
            [
                $report->reason,
                array_map(static fn(Job $job): array => $job->payload(), $this->handler->handled),
                $this->workers()->find('default')?->heartbeatAge($this->clock->now()),
            ],
        );
    }

    protected function setUp(): void
    {
        $this->setUpDatabase();
        $this->clock     = new MutableClock();
        $this->handler   = CallbackHandler::succeeding();
        $this->container = new FactoryContainer([
            AdapterInterface::class => $this->adapter,
            ClockInterface::class   => $this->clock,
            LoggerInterface::class  => new RecordingLogger(),
            'sync'                  => $this->handler,
        ]);
    }

    protected function tearDown(): void
    {
        $this->tearDownDatabase();
    }

    private function workers(): WorkerStoreInterface
    {
        $workers = $this->container->get(WorkerStoreInterface::class);
        self::assertInstanceOf(WorkerStoreInterface::class, $workers);

        return $workers;
    }
}
