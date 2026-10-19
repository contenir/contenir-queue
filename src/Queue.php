<?php

declare(strict_types=1);

namespace Contenir\Queue;

use Contenir\Queue\Storage\JobStoreInterface;
use DateTimeImmutable;
use InvalidArgumentException;
use Override;
use Psr\Clock\ClockInterface;

use function json_encode;
use function strlen;

use const JSON_THROW_ON_ERROR;
use const JSON_UNESCAPED_SLASHES;
use const JSON_UNESCAPED_UNICODE;

/**
 * @api
 */
final readonly class Queue implements QueueInterface
{
    public const int MAX_JOB_CLASS_LENGTH = 255;
    public const int MAX_QUEUE_LENGTH     = 64;

    public function __construct(
        private JobStoreInterface $jobs,
        private ClockInterface $clock,
    ) {}

    private static function assertName(string $what, string $name, int $maxLength): void
    {
        if ('' === $name || strlen($name) > $maxLength) {
            throw new InvalidArgumentException("The {$what} must be 1 to {$maxLength} characters");
        }
    }

    #[Override]
    public function push(
        string $jobClass,
        array $payload = [],
        string $queue = self::DEFAULT_QUEUE,
        ?DateTimeImmutable $notBefore = null,
    ): int {
        self::assertName('job class', $jobClass, self::MAX_JOB_CLASS_LENGTH);
        self::assertName('queue', $queue, self::MAX_QUEUE_LENGTH);

        $now = $this->clock->now();

        return $this->jobs->push(
            $queue,
            $jobClass,
            json_encode($payload, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE),
            $notBefore ?? $now,
            $now,
        );
    }
}
