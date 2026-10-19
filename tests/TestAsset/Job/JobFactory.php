<?php

declare(strict_types=1);

namespace Contenir\Queue\Tests\TestAsset\Job;

use Contenir\Queue\Job\Job;
use Contenir\Queue\Job\JobState;
use DateTimeImmutable;
use DateTimeZone;

/**
 * Jobs for unit tests, running under a lock unless told otherwise.
 */
final class JobFactory
{
    public const string HANDLER = 'test.handler';
    public const string LOCK    = 'worker-1';

    public static function running(int $attempts = 1, string $payload = '{"id":7}'): Job
    {
        $at = new DateTimeImmutable('2026-10-19 08:59:00', new DateTimeZone('UTC'));

        return new Job(
            id: 42,
            queue: 'default',
            jobClass: self::HANDLER,
            encodedPayload: $payload,
            state: JobState::Running,
            attempts: $attempts,
            nextAttemptAt: $at,
            lockedBy: self::LOCK,
            lockedAt: $at,
            lastError: null,
            createdAt: $at,
            updatedAt: $at,
            finishedAt: null,
        );
    }
}
