<?php

declare(strict_types=1);

namespace Contenir\Queue\Tests\Unit\Container;

use Contenir\Queue\Container\JobRunnerFactory;
use Contenir\Queue\Container\PhpDbJobStoreFactory;
use Contenir\Queue\Container\QueueFactory;
use Contenir\Queue\Job\JobOutcome;
use Contenir\Queue\Job\JobRunner;
use Contenir\Queue\Queue;
use Contenir\Queue\Storage\JobStoreInterface;
use Contenir\Queue\Storage\PhpDbJobStore;
use Contenir\Queue\Tests\TestAsset\Clock\MutableClock;
use Contenir\Queue\Tests\TestAsset\Container\ArrayContainer;
use Contenir\Queue\Tests\TestAsset\Job\CallbackHandler;
use Contenir\Queue\Tests\TestAsset\Job\JobFactory;
use Contenir\Queue\Tests\TestAsset\Log\RecordingLogger;
use PhpDb\Adapter\AdapterInterface;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Psr\Clock\ClockInterface;
use Psr\Log\LoggerInterface;

use function count;

#[Group('unit')]
final class FactoriesTest extends TestCase
{
    #[Test]
    public function buildsTheQueueOnTheStoreAndClock(): void
    {
        $clock = new MutableClock();
        $store = $this->createMock(JobStoreInterface::class);
        $store->expects(self::once())
            ->method('push')
            ->with(static::anything(), static::anything(), static::anything(), $clock->now())
            ->willReturn(3);

        $queue = (new QueueFactory())(new ArrayContainer([
            JobStoreInterface::class => $store,
            ClockInterface::class    => $clock,
        ]));

        static::assertSame([Queue::class, 3], [$queue::class, $queue->push('test.job')]);
    }

    #[Test]
    public function buildsTheRunnerFromTheConfiguredServices(): void
    {
        $clock   = new MutableClock();
        $logger  = new RecordingLogger();
        $handler = CallbackHandler::succeeding();
        $store   = $this->createMock(JobStoreInterface::class);
        $store->expects(self::once())
            ->method('retry')
            ->with(static::anything(), $clock->now()->modify('+7 seconds'))
            ->willReturn(true);

        $runner = (new JobRunnerFactory())(new ArrayContainer([
            'config'                 => ['contenir_queue' => ['backoff' => ['initial_seconds' => 7]]],
            JobStoreInterface::class => $store,
            ClockInterface::class    => $clock,
            LoggerInterface::class   => $logger,
            JobFactory::HANDLER      => $handler,
        ]));

        static::assertSame(
            [JobRunner::class, JobOutcome::Retrying, 1],
            [$runner::class, $runner->recordFailure(JobFactory::running(), 'x'), count($logger->lines)],
        );
    }

    #[Test]
    public function buildsTheStoreOnTheConfiguredAdapter(): void
    {
        $store = (new PhpDbJobStoreFactory())(new ArrayContainer([
            AdapterInterface::class => $this->createStub(AdapterInterface::class),
        ]));

        static::assertInstanceOf(PhpDbJobStore::class, $store);
    }
}
