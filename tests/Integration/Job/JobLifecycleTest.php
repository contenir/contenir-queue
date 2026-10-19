<?php

declare(strict_types=1);

namespace Contenir\Queue\Tests\Integration\Job;

use Contenir\Queue\Job\Backoff;
use Contenir\Queue\Job\Job;
use Contenir\Queue\Job\JobRunner;
use Contenir\Queue\Job\JobState;
use Contenir\Queue\Job\RetryLaterException;
use Contenir\Queue\Queue;
use Contenir\Queue\Storage\PhpDbJobStore;
use Contenir\Queue\Tests\TestAsset\Clock\MutableClock;
use Contenir\Queue\Tests\TestAsset\Container\ArrayContainer;
use Contenir\Queue\Tests\TestAsset\Job\CallbackHandler;
use Contenir\Queue\Tests\Trait\TestDatabaseTrait;
use LogicException;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;

use function array_fill;

/**
 * A job pushed, claimed and run against the real tables, through to done or
 * failed.
 */
#[Group('integration')]
final class JobLifecycleTest extends TestCase
{
    use TestDatabaseTrait;

    private MutableClock $clock;

    private PhpDbJobStore $store;

    #[Test]
    public function aFailingJobRetriesUntilItFails(): void
    {
        $id      = (new Queue($this->store, $this->clock))->push('sync');
        $handler = new CallbackHandler(static function (Job $job): void {
            throw new LogicException('Down');
        });

        $states = [];
        for ($attempt = 1; $attempt <= 13; ++$attempt) {
            $this->runNext($handler);
            $states[] = $this->store->find($id)?->state;
            $this->clock->advance(86_400);
        }

        static::assertSame(
            [
                ...array_fill(
                    start_index: 0,
                    count: 12,
                    value: JobState::Waiting,
                ),
                JobState::Failed,
                13,
            ],
            [...$states, $this->store->find($id)?->attempts],
        );
    }

    #[Test]
    public function aPushedJobRunsWithItsPayload(): void
    {
        $seen    = [];
        $handler = new CallbackHandler(static function (Job $job) use (&$seen): void {
            $seen = $job->payload();
        });
        $id = (new Queue($this->store, $this->clock))->push('sync', ['record' => 12]);

        $this->runNext($handler);

        static::assertSame([['record' => 12], JobState::Done], [$seen, $this->store->find($id)?->state]);
    }

    #[Test]
    public function retryAfterHoldsTheJobBack(): void
    {
        $id = (new Queue($this->store, $this->clock))->push('sync');

        $this->runNext(new CallbackHandler(static function (Job $job): void {
            throw RetryLaterException::fromRetryAfter('3600');
        }));
        $this->clock->advance(3_599);
        $early = $this->store->claimNext([], 'w', $this->clock->now());
        $this->clock->advance(1);

        static::assertSame([null, $id], [$early, $this->store->claimNext([], 'w', $this->clock->now())?->id]);
    }

    protected function setUp(): void
    {
        $this->setUpDatabase();
        $this->clock = new MutableClock();
        $this->store = new PhpDbJobStore($this->adapter);
    }

    protected function tearDown(): void
    {
        $this->tearDownDatabase();
    }

    private function runNext(CallbackHandler $handler): void
    {
        $job = $this->store->claimNext([], 'worker', $this->clock->now());
        self::assertNotNull($job);

        (new JobRunner(
            new ArrayContainer(['sync' => $handler]),
            $this->store,
            new Backoff(),
            $this->clock,
            new NullLogger(),
        ))
            ->run($job);
    }
}
