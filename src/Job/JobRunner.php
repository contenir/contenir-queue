<?php

declare(strict_types=1);

namespace Contenir\Queue\Job;

use Contenir\Queue\Exception\StorageException;
use Contenir\Queue\Storage\JobStoreInterface;
use DateTimeImmutable;
use Psr\Clock\ClockInterface;
use Psr\Container\ContainerExceptionInterface;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;
use Throwable;
use UnexpectedValueException;

use function mb_substr;
use function min;
use function sprintf;

/**
 * Runs one claimed job: gets its handler from the container, calls it, and
 * records the result. A failure waits for its next attempt as the back-off and
 * any Retry-After say, until the attempts run out and the job is failed.
 *
 * @api
 */
final readonly class JobRunner
{
    /**
     * Longest error message kept on a job, in characters.
     */
    public const int MAX_ERROR_LENGTH = 2000;

    public function __construct(
        private ContainerInterface $handlers,
        private JobStoreInterface $jobs,
        private Backoff $backoff,
        private ClockInterface $clock,
        private LoggerInterface $logger,
    ) {}

    /**
     * @return array{id: int, job: string, attempts: int}
     */
    private static function context(Job $job): array
    {
        return ['id' => $job->id, 'job' => $job->jobClass, 'attempts' => $job->attempts];
    }

    private static function describe(Throwable $e): string
    {
        return mb_substr($e::class . ': ' . $e->getMessage(), start: 0, length: self::MAX_ERROR_LENGTH);
    }

    /**
     * Record that a running job failed without being run to the end, as when
     * its worker stopped mid-job, and retry or fail it as for any failure.
     *
     * @throws StorageException
     */
    public function recordFailure(Job $job, string $error): JobOutcome
    {
        return $this->retryOrFail($job, $error, null);
    }

    /**
     * Run a job this worker has claimed. Whatever the handler throws is
     * recorded on the job; only a failure to record it escapes.
     *
     * @throws StorageException
     */
    public function run(Job $job): JobOutcome
    {
        try {
            $this->handler($job)->handle($job);
        } catch (UnrecoverableJobException $e) {
            return $this->fail($job, self::describe($e));
        } catch (RetryLaterException $e) {
            return $this->retryOrFail($job, self::describe($e), $e->retryAt($this->clock->now()));
        } catch (Throwable $e) {
            return $this->retryOrFail($job, self::describe($e), null);
        }

        if (! $this->jobs->complete($job, $this->clock->now())) {
            return $this->lost($job);
        }

        $this->logger->info('Job {id} ({job}) done', self::context($job));

        return JobOutcome::Done;
    }

    /**
     * @throws StorageException
     */
    private function fail(Job $job, string $error): JobOutcome
    {
        if (! $this->jobs->fail($job, $error, $this->clock->now())) {
            return $this->lost($job);
        }

        $this->logger->error('Job {id} ({job}) failed after {attempts} attempts: {error}', [
            ...self::context($job),
            'error' => $error,
        ]);

        return JobOutcome::Failed;
    }

    /**
     * @throws ContainerExceptionInterface
     * @throws UnexpectedValueException
     *
     * @mago-expect analysis:mixed-assignment Container services are untyped; the type is checked here.
     */
    private function handler(Job $job): JobHandlerInterface
    {
        $handler = $this->handlers->get($job->jobClass);
        if (! $handler instanceof JobHandlerInterface) {
            throw new UnexpectedValueException(sprintf(
                'The container entry "%s" is not a %s',
                $job->jobClass,
                JobHandlerInterface::class,
            ));
        }

        return $handler;
    }

    private function lost(Job $job): JobOutcome
    {
        $this->logger->warning('Job {id} ({job}) was no longer locked by this worker when it finished', self::context(
            $job,
        ));

        return JobOutcome::Lost;
    }

    /**
     * @throws StorageException
     */
    private function retryOrFail(Job $job, string $error, ?DateTimeImmutable $requested): JobOutcome
    {
        $delay = $this->backoff->delayAfter($job->attempts);
        if (null === $delay) {
            return $this->fail($job, $error);
        }

        $now    = $this->clock->now();
        $latest = $now->modify("+{$this->backoff->maxSeconds} seconds");
        $next   = min($requested ?? $now->modify("+{$delay} seconds"), $latest);

        if (! $this->jobs->retry($job, $next, $error, $now)) {
            return $this->lost($job);
        }

        $this->logger->warning('Job {id} ({job}) attempt {attempts} failed, retrying at {next}: {error}', [
            ...self::context($job),
            'next'  => $next->format(DateTimeImmutable::ATOM),
            'error' => $error,
        ]);

        return JobOutcome::Retrying;
    }
}
