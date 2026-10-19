<?php

declare(strict_types=1);

namespace Contenir\Queue\Job;

use DateTimeImmutable;
use JsonException;

use function is_array;
use function json_decode;

use const JSON_THROW_ON_ERROR;

/**
 * A job as stored: the handler class to run, its payload, the queue it waits
 * on, and its progress. Times are UTC.
 *
 * `attempts` counts the runs started so far, so while a handler runs it is
 * the number of the current attempt.
 *
 * @api
 */
final readonly class Job
{
    /**
     * @mago-expect lint:excessive-parameter-list One parameter per column of the queue_job table.
     */
    public function __construct(
        public int $id,
        public string $queue,
        public string $jobClass,
        public string $encodedPayload,
        public JobState $state,
        public int $attempts,
        public DateTimeImmutable $nextAttemptAt,
        public ?string $lockedBy,
        public ?DateTimeImmutable $lockedAt,
        public ?string $lastError,
        public DateTimeImmutable $createdAt,
        public DateTimeImmutable $updatedAt,
        public ?DateTimeImmutable $finishedAt,
    ) {}

    /**
     * The payload the job was pushed with. Decoded on each call, so a payload
     * that cannot be read fails the run that needs it rather than the worker.
     *
     * @return array<array-key, mixed>
     *
     * @throws JsonException When the stored payload is not a JSON object or array.
     *
     * @mago-expect analysis:mixed-assignment Decoded JSON is untyped; its shape is checked here.
     */
    public function payload(): array
    {
        $payload = json_decode($this->encodedPayload, associative: true, flags: JSON_THROW_ON_ERROR);
        if (! is_array($payload)) {
            throw new JsonException('The job payload is not a JSON object or array');
        }

        return $payload;
    }
}
