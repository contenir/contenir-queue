<?php

declare(strict_types=1);

namespace Contenir\Queue\Tests\Unit\Job;

use Contenir\Queue\Job\RetryLaterException;
use DateTimeImmutable;
use DateTimeZone;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

#[Group('unit')]
final class RetryLaterExceptionTest extends TestCase
{
    /**
     * @return array<string, array{string, ?string}>
     */
    public static function retryAfterProvider(): array
    {
        return [
            'seconds'                 => ['120', '2026-10-19 09:02:00'],
            'seconds with whitespace' => [' 30 ', '2026-10-19 09:00:30'],
            'zero seconds'            => ['0', '2026-10-19 09:00:00'],
            'an HTTP date'            => ['Mon, 19 Oct 2026 10:15:00 GMT', '2026-10-19 10:15:00'],
            'a date in another zone'  => ['Mon, 19 Oct 2026 21:15:00 +1100', '2026-10-19 10:15:00'],
            'neither'                 => ['soon', null],
            'a negative number'       => ['-5', null],
            'empty'                   => ['', null],
        ];
    }

    private static function format(?DateTimeImmutable $at): string
    {
        self::assertNotNull($at);

        return $at->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d H:i:s');
    }

    private static function now(): DateTimeImmutable
    {
        return new DateTimeImmutable('2026-10-19 09:00:00', new DateTimeZone('UTC'));
    }

    #[Test]
    public function afterCountsSecondsFromNow(): void
    {
        static::assertSame('2026-10-19 09:01:30', self::format(RetryLaterException::after(90)->retryAt(self::now())));
    }

    #[Test]
    public function afterTreatsANegativeWaitAsNow(): void
    {
        static::assertSame('2026-10-19 09:00:00', self::format(RetryLaterException::after(-10)->retryAt(self::now())));
    }

    #[Test]
    public function atIsAFixedTime(): void
    {
        $at = new DateTimeImmutable('2026-10-20 00:00:00', new DateTimeZone('UTC'));

        static::assertSame($at, RetryLaterException::at($at)->retryAt(self::now()));
    }

    #[Test]
    public function carriesItsMessage(): void
    {
        static::assertSame(
            ['Retry later', 'Rate limited', 'Busy', 'Wait'],
            [
                RetryLaterException::after(1)->getMessage(),
                RetryLaterException::after(1, 'Rate limited')->getMessage(),
                RetryLaterException::at(self::now(), 'Busy')->getMessage(),
                RetryLaterException::fromRetryAfter('5', 'Wait')->getMessage(),
            ],
        );
    }

    #[Test]
    public function defaultMessagesForEveryConstructor(): void
    {
        static::assertSame(['Retry later', 'Retry later'], [
            RetryLaterException::at(self::now())->getMessage(),
            RetryLaterException::fromRetryAfter('x')->getMessage(),
        ]);
    }

    #[Test]
    #[DataProvider('retryAfterProvider')]
    public function readsARetryAfterHeader(string $header, ?string $expected): void
    {
        $at = RetryLaterException::fromRetryAfter($header)->retryAt(self::now());

        static::assertSame($expected, null === $at ? null : self::format($at));
    }

    #[Test]
    public function retryAfterHeaderMessageIsKeptWhenTheDateIsInvalid(): void
    {
        static::assertSame('Slow down', RetryLaterException::fromRetryAfter('later', 'Slow down')->getMessage());
    }
}
