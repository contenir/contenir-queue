<?php

declare(strict_types=1);

namespace Contenir\Queue\Tests\Unit\Job;

use Contenir\Queue\Job\Backoff;
use Contenir\Queue\Job\Job;
use Contenir\Queue\Job\JobHandlerInterface;
use Contenir\Queue\Job\JobOutcome;
use Contenir\Queue\Job\JobRunner;
use Contenir\Queue\Job\RetryLaterException;
use Contenir\Queue\Job\UnrecoverableJobException;
use Contenir\Queue\Storage\JobStoreInterface;
use Contenir\Queue\Tests\TestAsset\Clock\MutableClock;
use Contenir\Queue\Tests\TestAsset\Container\ArrayContainer;
use Contenir\Queue\Tests\TestAsset\Job\CallbackHandler;
use Contenir\Queue\Tests\TestAsset\Job\JobFactory;
use Contenir\Queue\Tests\TestAsset\Log\RecordingLogger;
use DateTimeImmutable;
use LogicException;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use stdClass;
use Throwable;

use function count;
use function str_repeat;

#[Group('unit')]
final class JobRunnerTest extends TestCase
{
    private MutableClock $clock;

    private RecordingLogger $logger;

    private JobStoreInterface&MockObject $store;

    private static function at(string $time): DateTimeImmutable
    {
        return new DateTimeImmutable("{$time} UTC");
    }

    private static function throwing(Throwable $e): CallbackHandler
    {
        return new CallbackHandler(static function (Job $job) use ($e): void {
            throw $e;
        });
    }

    #[Test]
    public function callsTheHandlerWithTheJob(): void
    {
        $handler = CallbackHandler::succeeding();
        $job     = JobFactory::running();
        $this->store->method('complete')->willReturn(true);

        $this->runner($handler)->run($job);

        static::assertSame([$job], $handler->handled);
    }

    #[Test]
    public function completesAJobWhoseHandlerReturns(): void
    {
        $job = JobFactory::running();
        $this->store->expects(self::once())->method('complete')->with($job, $this->clock->now())->willReturn(true);

        $outcome = $this->runner(CallbackHandler::succeeding())->run($job);

        static::assertSame(
            [JobOutcome::Done, ['info: Job 42 (test.handler) done']],
            [$outcome, $this->logger->lines],
        );
    }

    #[Test]
    public function completionAfterTheLockWasLostIsReported(): void
    {
        $this->store->method('complete')->willReturn(false);

        $outcome = $this->runner(CallbackHandler::succeeding())->run(JobFactory::running());

        static::assertSame(
            [JobOutcome::Lost, ['warning: Job 42 (test.handler) was no longer locked by this worker when it finished']],
            [$outcome, $this->logger->lines],
        );
    }

    #[Test]
    public function entryThatIsNotAHandlerIsAnOrdinaryFailure(): void
    {
        $job = JobFactory::running(attempts: 1);
        $this->store
            ->expects(self::once())
            ->method('retry')
            ->with(
                $job,
                static::anything(),
                'UnexpectedValueException: The container entry "test.handler" is not a ' . JobHandlerInterface::class,
            )
            ->willReturn(true);

        (new JobRunner(
            new ArrayContainer([JobFactory::HANDLER => new stdClass()]),
            $this->store,
            new Backoff(),
            $this->clock,
            $this->logger,
        ))->run($job);
    }

    #[Test]
    public function exceptionRetriesAfterTheBackoff(): void
    {
        $job = JobFactory::running(attempts: 3);
        $this->store
            ->expects(self::once())
            ->method('retry')
            ->with($job, self::at('2026-10-19 09:04:00'), 'LogicException: Boom', $this->clock->now())
            ->willReturn(true);

        $outcome = $this->runner(self::throwing(new LogicException('Boom')))->run($job);

        static::assertSame(
            [
                JobOutcome::Retrying,
                [
                    'warning: Job 42 (test.handler) attempt 3 failed, retrying at 2026-10-19T09:04:00+00:00: '
                        . 'LogicException: Boom',
                ],
            ],
            [$outcome, $this->logger->lines],
        );
    }

    #[Test]
    public function failsAJobWhoseLastAttemptThrows(): void
    {
        $job = JobFactory::running(attempts: 13);
        $this->store
            ->expects(self::once())
            ->method('fail')
            ->with($job, 'LogicException: Boom', $this->clock->now())
            ->willReturn(true);
        $this->store->expects(self::never())->method('retry');

        $outcome = $this->runner(self::throwing(new LogicException('Boom')))->run($job);

        static::assertSame(
            [JobOutcome::Failed, ['error: Job 42 (test.handler) failed after 13 attempts: LogicException: Boom']],
            [$outcome, $this->logger->lines],
        );
    }

    #[Test]
    public function failureAfterTheLockWasLostIsReported(): void
    {
        $this->store->method('fail')->willReturn(false);

        $outcome = $this->runner(self::throwing(new UnrecoverableJobException('x')))->run(JobFactory::running());

        static::assertSame([JobOutcome::Lost, 1], [$outcome, count($this->logger->lines)]);
    }

    #[Test]
    public function longErrorsAreCut(): void
    {
        $job = JobFactory::running(attempts: 1);
        $this->store
            ->expects(self::once())
            ->method('retry')
            ->with($job, static::anything(), 'LogicException: ' . str_repeat('é', JobRunner::MAX_ERROR_LENGTH - 16))
            ->willReturn(true);

        $this->runner(self::throwing(new LogicException(str_repeat('é', JobRunner::MAX_ERROR_LENGTH))))->run($job);
    }

    #[Test]
    public function missingHandlerIsAnOrdinaryFailure(): void
    {
        $job = JobFactory::running(attempts: 1);
        $this->store
            ->expects(self::once())
            ->method('retry')
            ->with($job, self::at('2026-10-19 09:01:00'), static::stringContains('No entry "test.handler"'))
            ->willReturn(true);

        $outcome = (new JobRunner(new ArrayContainer(), $this->store, new Backoff(), $this->clock, $this->logger))
            ->run($job);

        static::assertSame(JobOutcome::Retrying, $outcome);
    }

    #[Test]
    public function recordFailureRetriesAJobThatStoppedMidRun(): void
    {
        $job = JobFactory::running(attempts: 1);
        $this->store
            ->expects(self::once())
            ->method('retry')
            ->with($job, self::at('2026-10-19 09:01:00'), 'The worker stopped', $this->clock->now())
            ->willReturn(true);

        static::assertSame(JobOutcome::Retrying, $this->runner(CallbackHandler::succeeding())->recordFailure(
            $job,
            'The worker stopped',
        ));
    }

    #[Test]
    public function retryAfterTheLockWasLostIsReported(): void
    {
        $this->store->method('retry')->willReturn(false);

        $outcome = $this->runner(self::throwing(new LogicException('x')))->run(JobFactory::running());

        static::assertSame([JobOutcome::Lost, 1], [$outcome, count($this->logger->lines)]);
    }

    #[Test]
    public function retryLaterAtTheCeilingIsKept(): void
    {
        $job = JobFactory::running(attempts: 1);
        $this->store
            ->expects(self::once())
            ->method('retry')
            ->with($job, self::at('2026-10-20 09:00:00'))
            ->willReturn(true);

        $this->runner(self::throwing(RetryLaterException::after(86_400)))->run($job);
    }

    #[Test]
    public function retryLaterIsCutToTheBackoffCeiling(): void
    {
        $job = JobFactory::running(attempts: 1);
        $this->store
            ->expects(self::once())
            ->method('retry')
            ->with($job, self::at('2026-10-20 09:00:00'))
            ->willReturn(true);

        $this->runner(self::throwing(RetryLaterException::after(86_401)))->run($job);
    }

    #[Test]
    public function retryLaterOnTheLastAttemptFails(): void
    {
        $job = JobFactory::running(attempts: 13);
        $this->store->expects(self::once())->method('fail')->willReturn(true);

        $outcome = $this->runner(self::throwing(RetryLaterException::after(5)))->run($job);

        static::assertSame(JobOutcome::Failed, $outcome);
    }

    #[Test]
    public function retryLaterUsesTheRequestedTime(): void
    {
        $job = JobFactory::running(attempts: 1);
        $this->store
            ->expects(self::once())
            ->method('retry')
            ->with($job, self::at('2026-10-19 09:10:00'), RetryLaterException::class . ': Busy', $this->clock->now())
            ->willReturn(true);

        $outcome = $this->runner(self::throwing(RetryLaterException::after(600, 'Busy')))->run($job);

        static::assertSame(JobOutcome::Retrying, $outcome);
    }

    #[Test]
    public function retryLaterWithoutATimeUsesTheBackoff(): void
    {
        $job = JobFactory::running(attempts: 2);
        $this->store
            ->expects(self::once())
            ->method('retry')
            ->with($job, self::at('2026-10-19 09:02:00'))
            ->willReturn(true);

        $this->runner(self::throwing(RetryLaterException::fromRetryAfter('whenever')))->run($job);
    }

    #[Test]
    public function unrecoverableFailsAtOnce(): void
    {
        $job = JobFactory::running(attempts: 1);
        $this->store
            ->expects(self::once())
            ->method('fail')
            ->with($job, UnrecoverableJobException::class . ': Gone', $this->clock->now())
            ->willReturn(true);
        $this->store->expects(self::never())->method('retry');

        $outcome = $this->runner(self::throwing(new UnrecoverableJobException('Gone')))->run($job);

        static::assertSame(JobOutcome::Failed, $outcome);
    }

    protected function setUp(): void
    {
        $this->clock  = new MutableClock();
        $this->logger = new RecordingLogger();
        $this->store  = $this->createMock(JobStoreInterface::class);
    }

    private function runner(CallbackHandler $handler): JobRunner
    {
        return new JobRunner(
            new ArrayContainer([JobFactory::HANDLER => $handler]),
            $this->store,
            new Backoff(),
            $this->clock,
            $this->logger,
        );
    }
}
