<?php

declare(strict_types=1);

namespace Contenir\Queue\Tests\Unit;

use Contenir\Queue\Queue;
use Contenir\Queue\Storage\JobStoreInterface;
use Contenir\Queue\Tests\TestAsset\Clock\MutableClock;
use DateTimeImmutable;
use InvalidArgumentException;
use JsonException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

use function str_repeat;

use const NAN;

#[Group('unit')]
final class QueueTest extends TestCase
{
    /**
     * @return array<string, array{string, string, string}>
     */
    public static function invalidNameProvider(): array
    {
        return [
            'empty job class'    => ['', 'default', 'The job class must be 1 to 255 characters'],
            'job class too long' => [
                str_repeat('a', times: 256),
                'default',
                'The job class must be 1 to 255 characters',
            ],
            'empty queue'        => ['test.job', '', 'The queue must be 1 to 64 characters'],
            'queue too long'     => ['test.job', str_repeat('q', times: 65), 'The queue must be 1 to 64 characters'],
        ];
    }

    #[Test]
    public function acceptsNamesAtTheirLongest(): void
    {
        $store = $this->createStub(JobStoreInterface::class);
        $store->method('push')->willReturn(1);

        static::assertSame(1, (new Queue($store, new MutableClock()))->push(
            str_repeat('a', times: 255),
            [],
            str_repeat('q', times: 64),
        ));
    }

    #[Test]
    public function pushesAJobDueNow(): void
    {
        $clock = new MutableClock();
        $store = $this->createMock(JobStoreInterface::class);
        $store->expects(self::once())
            ->method('push')
            ->with(
                'default',
                'sync.contacts',
                '{"url":"https://example.com/a","name":"Zoë"}',
                $clock->now(),
                $clock->now(),
            )
            ->willReturn(5);

        $id = (new Queue($store, $clock))->push('sync.contacts', ['url' => 'https://example.com/a', 'name' => 'Zoë']);

        static::assertSame(5, $id);
    }

    #[Test]
    public function pushesAJobForLaterOnANamedQueue(): void
    {
        $clock     = new MutableClock();
        $notBefore = new DateTimeImmutable('2026-10-20 00:00:00 UTC');
        $store     = $this->createMock(JobStoreInterface::class);
        $store->expects(self::once())
            ->method('push')
            ->with('mail', 'mail.send', '[]', $notBefore, $clock->now())
            ->willReturn(6);

        (new Queue($store, $clock))->push('mail.send', [], 'mail', $notBefore);
    }

    #[Test]
    public function rejectsAPayloadJsonCannotCarry(): void
    {
        $this->expectException(JsonException::class);

        (new Queue($this->createStub(JobStoreInterface::class), new MutableClock()))->push('test.job', ['n' => NAN]);
    }

    #[Test]
    #[DataProvider('invalidNameProvider')]
    public function rejectsNamesTheTableCannotHold(string $jobClass, string $queue, string $message): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage($message);

        (new Queue($this->createStub(JobStoreInterface::class), new MutableClock()))->push($jobClass, [], $queue);
    }
}
