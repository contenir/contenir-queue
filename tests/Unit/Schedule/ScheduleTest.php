<?php

declare(strict_types=1);

namespace Contenir\Queue\Tests\Unit\Schedule;

use Contenir\Queue\Schedule\Frequency;
use Contenir\Queue\Schedule\Schedule;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

use function str_repeat;
use function strlen;

#[Group('unit')]
final class ScheduleTest extends TestCase
{
    /**
     * @return array<string, array{string, string, string, string}>
     */
    public static function invalidProvider(): array
    {
        $name = 'A schedule name is 1 to 128 letters, digits, dots, dashes, colons or underscores, not ';

        return [
            'empty name'          => ['', 'job', 'default', "{$name}\"\""],
            'name with a space'   => ['forms retention', 'job', 'default', "{$name}\"forms retention\""],
            'name with a newline' => ["forms\n", 'job', 'default', "{$name}\"forms\n\""],
            'name too long'       => [
                str_repeat('a', times: 129),
                'job',
                'default',
                $name . '"' . str_repeat('a', times: 129) . '"',
            ],
            'empty job class'     => ['n', '', 'default', 'A schedule needs a job class of 1 to 255 characters'],
            'job class too long'  => [
                'n',
                str_repeat('j', times: 256),
                'default',
                'A schedule needs a job class of 1 to 255 characters',
            ],
            'empty queue'         => ['n', 'job', '', 'A schedule needs a queue name of 1 to 64 characters'],
            'queue too long'      => [
                'n',
                'job',
                str_repeat('q', times: 65),
                'A schedule needs a queue name of 1 to 64 characters',
            ],
        ];
    }

    #[Test]
    public function acceptsNamesAndClassesAtTheirLimits(): void
    {
        $schedule = new Schedule(
            'Az09._:-' . str_repeat('a', times: 120),
            str_repeat('j', times: 255),
            Frequency::everyMinutes(1),
            queue: str_repeat('q', times: 64),
        );

        static::assertSame(128, strlen($schedule->name));
    }

    #[Test]
    public function defaultsToNoPayloadOnTheDefaultQueue(): void
    {
        $schedule = new Schedule('n', 'job', Frequency::everyMinutes(1));

        static::assertSame([[], 'default'], [$schedule->payload, $schedule->queue]);
    }

    #[Test]
    public function keepsWhatItRuns(): void
    {
        $frequency = Frequency::everyMinutes(5);
        $schedule  = new Schedule('forms.retention', 'job', $frequency, ['days' => 30], 'mail');

        static::assertSame(
            ['forms.retention', 'job', $frequency, ['days' => 30], 'mail'],
            [$schedule->name, $schedule->jobClass, $schedule->frequency, $schedule->payload, $schedule->queue],
        );
    }

    #[Test]
    #[DataProvider('invalidProvider')]
    public function refusesWhatCannotBeStoredOrQueued(string $name, string $job, string $queue, string $message): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage($message);

        new Schedule($name, $job, Frequency::everyMinutes(1), queue: $queue);
    }
}
