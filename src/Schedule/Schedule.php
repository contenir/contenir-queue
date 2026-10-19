<?php

declare(strict_types=1);

namespace Contenir\Queue\Schedule;

use Contenir\Queue\Queue;
use Contenir\Queue\QueueInterface;
use InvalidArgumentException;

use function preg_match;
use function strlen;

/**
 * Recurring work: when it falls due, the scheduler pushes a job for the named
 * handler, with the payload, onto the queue.
 *
 * @api
 */
final readonly class Schedule
{
    public const int MAX_NAME_LENGTH = 128;

    /**
     * @param array<array-key, mixed> $payload
     *
     * @throws InvalidArgumentException When the name or job class is unusable.
     */
    public function __construct(
        public string $name,
        public string $jobClass,
        public Frequency $frequency,
        public array $payload = [],
        public string $queue = QueueInterface::DEFAULT_QUEUE,
    ) {
        if (1 !== preg_match('/^[A-Za-z0-9._:-]{1,' . self::MAX_NAME_LENGTH . '}$/D', $name)) {
            throw new InvalidArgumentException(
                "A schedule name is 1 to 128 letters, digits, dots, dashes, colons or underscores, not \"{$name}\"",
            );
        }

        if ('' === $jobClass || strlen($jobClass) > Queue::MAX_JOB_CLASS_LENGTH) {
            throw new InvalidArgumentException('A schedule needs a job class of 1 to 255 characters');
        }

        if ('' === $queue || strlen($queue) > Queue::MAX_QUEUE_LENGTH) {
            throw new InvalidArgumentException('A schedule needs a queue name of 1 to 64 characters');
        }
    }
}
