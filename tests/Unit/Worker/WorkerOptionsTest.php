<?php

declare(strict_types=1);

namespace Contenir\Queue\Tests\Unit\Worker;

use Contenir\Queue\Worker\WorkerOptions;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

#[Group('unit')]
final class WorkerOptionsTest extends TestCase
{
    /**
     * @return array<string, array{array<string, mixed>, string}>
     */
    public static function invalidProvider(): array
    {
        $times  = 'The lock, stale and sleep times must be at least one second';
        $limits = 'Worker limits cannot be negative';

        return [
            'no name'              => [['name' => ''], 'A worker needs a name'],
            'lock under a second'  => [['lockSeconds' => 0], $times],
            'stale under a second' => [['staleAfterSeconds' => 0], $times],
            'sleep under a second' => [['sleepSeconds' => 0], $times],
            'negative time'        => [['maxSeconds' => -1], $limits],
            'negative jobs'        => [['maxJobs' => -1], $limits],
            'negative memory'      => [['memoryLimitBytes' => -1], $limits],
            'negative prune'       => [['pruneAfterDays' => -1], $limits],
        ];
    }

    /**
     * @return list<mixed>
     */
    private static function values(WorkerOptions $options): array
    {
        return [
            $options->name,
            $options->queues,
            $options->maxSeconds,
            $options->maxJobs,
            $options->sleepSeconds,
            $options->memoryLimitBytes,
            $options->lockSeconds,
            $options->staleAfterSeconds,
            $options->pruneAfterDays,
        ];
    }

    #[Test]
    public function defaultsSuitCron(): void
    {
        static::assertEquals(
            ['default', [], 50, 0, 5, 134_217_728, 300, 3_600, 7],
            self::values(new WorkerOptions()),
        );
    }

    /**
     * @param array<string, mixed> $arguments
     */
    #[Test]
    #[DataProvider('invalidProvider')]
    public function refusesValuesThatCannotWork(array $arguments, string $message): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage($message);

        new WorkerOptions(...$arguments);
    }

    #[Test]
    public function theSmallestValuesAreAccepted(): void
    {
        static::assertEquals(
            ['w', [], 0, 0, 1, 0, 1, 1, 0],
            self::values(new WorkerOptions('w', [], 0, 0, 1, 0, 1, 1, 0)),
        );
    }

    #[Test]
    public function withReplacesOnlyWhatItIsGiven(): void
    {
        $options = new WorkerOptions('w', ['a'], 10, 2, 3, 4, 5, 6, 7);

        static::assertEquals(
            [
                ['w', ['a'], 10, 2, 3, 4, 5, 6, 7],
                ['x', ['b'], 0, 9, 8, 1, 5, 6, 7],
            ],
            [
                self::values($options->with([])),
                self::values($options->with([
                    'name'             => 'x',
                    'queues'           => ['b'],
                    'maxSeconds'       => 0,
                    'maxJobs'          => 9,
                    'sleepSeconds'     => 8,
                    'memoryLimitBytes' => 1,
                ])),
            ],
        );
    }
}
