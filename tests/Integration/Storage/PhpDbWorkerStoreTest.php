<?php

declare(strict_types=1);

namespace Contenir\Queue\Tests\Integration\Storage;

use Contenir\Queue\Storage\DbTime;
use Contenir\Queue\Storage\PhpDbWorkerStore;
use Contenir\Queue\Tests\Trait\TestDatabaseTrait;
use Contenir\Queue\Worker\WorkerIdentity;
use Contenir\Queue\Worker\WorkerStatus;
use DateTimeImmutable;
use PhpDb\Adapter\Adapter;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

use function strlen;

#[Group('integration')]
#[Group('repository')]
final class PhpDbWorkerStoreTest extends TestCase
{
    use TestDatabaseTrait;

    private PhpDbWorkerStore $store;

    private static function at(string $time): DateTimeImmutable
    {
        return DbTime::parse('2026-10-19 ' . (5 === strlen($time) ? "{$time}:00" : $time));
    }

    private static function worker(string $run): WorkerIdentity
    {
        return WorkerIdentity::of('web1', 7, $run);
    }

    #[Test]
    public function aHeldLockIsRefused(): void
    {
        $this->store->acquire('default', self::worker('a'), self::at('09:05'), self::at('09:00'));

        static::assertSame(
            [false, false, 'web1:7:a'],
            [
                $this->store->acquire('default', self::worker('b'), self::at('09:06'), self::at('09:01')),
                $this->store->acquire('default', self::worker('b'), self::at('09:10'), self::at('09:05')),
                $this->store->find('default')?->owner,
            ],
        );
    }

    #[Test]
    public function aLockThatRanOutCanBeTaken(): void
    {
        $this->store->acquire('default', self::worker('a'), self::at('09:05'), self::at('09:00'));

        static::assertSame(
            [true, 'web1:7:b', '09:05:01'],
            [
                $this->store->acquire('default', self::worker('b'), self::at('09:10'), self::at('09:05:01')),
                $this->store->find('default')?->owner,
                $this->store->find('default')?->startedAt?->format('H:i:s'),
            ],
        );
    }

    #[Test]
    public function aReleasedLockCanBeTaken(): void
    {
        $this->store->acquire('default', self::worker('a'), self::at('09:05'), self::at('09:00'));
        $this->store->release('default', 'web1:7:a', self::at('09:01'));

        static::assertTrue($this->store->acquire('default', self::worker('b'), self::at('09:06'), self::at('09:01')));
    }

    #[Test]
    public function aWorkerThatNeverRanHasNoRow(): void
    {
        static::assertNull($this->store->find('default'));
    }

    #[Test]
    public function locksAreKeptPerName(): void
    {
        $this->store->acquire('default', self::worker('a'), self::at('09:05'), self::at('09:00'));

        static::assertTrue($this->store->acquire('mail', self::worker('b'), self::at('09:05'), self::at('09:00')));
    }

    #[Test]
    public function losingTheRaceToInsertTheRowIsARefusal(): void
    {
        $this->adapter->query(
            'MySQL' === $this->adapter->getPlatform()->getName()
                ? 'CREATE TRIGGER queue_worker_race BEFORE INSERT ON queue_worker FOR EACH ROW'
                . " SIGNAL SQLSTATE '23000' SET MESSAGE_TEXT = 'Another worker inserted first'"
                : 'CREATE TRIGGER queue_worker_race BEFORE INSERT ON queue_worker'
                . " BEGIN SELECT RAISE(ABORT, 'Another worker inserted first'); END",
            Adapter::QUERY_MODE_EXECUTE,
        );

        static::assertFalse($this->store->acquire('default', self::worker('b'), self::at('09:06'), self::at('09:01')));
    }

    #[Test]
    public function onlyTheOwnerCanRelease(): void
    {
        $this->store->acquire('default', self::worker('a'), self::at('09:05'), self::at('09:00'));

        $this->store->release('default', 'web1:7:b', self::at('09:02'));

        static::assertSame(['web1:7:a', '09:00:00'], [
            $this->store->find('default')?->owner,
            $this->store->find('default')?->heartbeatAt?->format('H:i:s'),
        ]);
    }

    #[Test]
    public function releasingLeavesAFinalHeartbeat(): void
    {
        $this->store->acquire('default', self::worker('a'), self::at('09:05'), self::at('09:00'));

        $this->store->release('default', 'web1:7:a', self::at('09:02'));
        $status = $this->store->find('default');

        static::assertSame(
            [null, null, '09:02:00', 'web1'],
            [$status?->owner, $status?->lockedUntil, $status?->heartbeatAt?->format('H:i:s'), $status?->host],
        );
    }

    #[Test]
    public function renewingAndReleasingOneNameLeavesTheOthers(): void
    {
        $this->store->acquire('default', self::worker('a'), self::at('09:05'), self::at('09:00'));
        $this->store->acquire('mail', self::worker('a'), self::at('09:05'), self::at('09:00'));

        $this->store->renew('default', 'web1:7:a', self::at('09:30'), self::at('09:01'));
        $this->store->release('default', 'web1:7:a', self::at('09:02'));

        static::assertSame(
            ['web1:7:a', '09:05:00', '09:00:00'],
            [
                $this->store->find('mail')?->owner,
                $this->store->find('mail')?->lockedUntil?->format('H:i:s'),
                $this->store->find('mail')?->heartbeatAt?->format('H:i:s'),
            ],
        );
    }

    #[Test]
    public function renewingExtendsTheLockAndBeats(): void
    {
        $this->store->acquire('default', self::worker('a'), self::at('09:05'), self::at('09:00'));

        $renewed = $this->store->renew('default', 'web1:7:a', self::at('09:08'), self::at('09:03'));

        static::assertSame(
            [true, '09:08:00', '09:03:00', '09:00:00'],
            [
                $renewed,
                $this->store->find('default')?->lockedUntil?->format('H:i:s'),
                $this->store->find('default')?->heartbeatAt?->format('H:i:s'),
                $this->store->find('default')?->startedAt?->format('H:i:s'),
            ],
        );
    }

    #[Test]
    public function renewingSomeoneElsesLockFails(): void
    {
        $this->store->acquire('default', self::worker('a'), self::at('09:05'), self::at('09:00'));

        static::assertSame(
            [false, '09:05:00', false],
            [
                $this->store->renew('default', 'web1:7:b', self::at('09:09'), self::at('09:01')),
                $this->store->find('default')?->lockedUntil?->format('H:i:s'),
                $this->store->renew('mail', 'web1:7:a', self::at('09:09'), self::at('09:01')),
            ],
        );
    }

    #[Test]
    public function renewingTwiceInOneSecondStillSucceeds(): void
    {
        $this->store->acquire('default', self::worker('a'), self::at('09:05'), self::at('09:00'));

        static::assertSame(
            [true, true],
            [
                $this->store->renew('default', 'web1:7:a', self::at('09:05'), self::at('09:00')),
                $this->store->renew('default', 'web1:7:a', self::at('09:05'), self::at('09:00')),
            ],
        );
    }

    #[Test]
    public function takingOneNamesLockLeavesTheOthers(): void
    {
        $this->store->acquire('default', self::worker('a'), self::at('09:05'), self::at('09:00'));
        $this->store->acquire('mail', self::worker('a'), self::at('09:05'), self::at('09:00'));

        $this->store->acquire('default', self::worker('b'), self::at('09:20'), self::at('09:10'));

        static::assertSame('web1:7:a', $this->store->find('mail')?->owner);
    }

    #[Test]
    public function theFirstWorkerTakesTheLock(): void
    {
        $acquired = $this->store->acquire('default', self::worker('a'), self::at('09:05'), self::at('09:00'));

        static::assertEquals(
            [
                true,
                new WorkerStatus(
                    'default',
                    'web1:7:a',
                    self::at('09:05'),
                    self::at('09:00'),
                    self::at('09:00'),
                    'web1',
                    7,
                ),
            ],
            [$acquired, $this->store->find('default')],
        );
    }

    protected function setUp(): void
    {
        $this->setUpDatabase();
        $this->store = new PhpDbWorkerStore($this->adapter);
    }

    protected function tearDown(): void
    {
        $this->tearDownDatabase();
    }
}
