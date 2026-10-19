<?php

declare(strict_types=1);

namespace Contenir\Queue\Tests\Unit\Container;

use Contenir\Queue\Clock\SystemClock;
use Contenir\Queue\Container\QueueConfig;
use Contenir\Queue\Exception\ConfigurationException;
use Contenir\Queue\Job\Backoff;
use Contenir\Queue\Schedule\Schedule;
use Contenir\Queue\Storage\JobStoreInterface;
use Contenir\Queue\Tests\TestAsset\Clock\MutableClock;
use Contenir\Queue\Tests\TestAsset\Container\ArrayContainer;
use Contenir\Queue\Tests\TestAsset\Log\RecordingLogger;
use Contenir\Queue\Worker\DrainOptions;
use Contenir\Queue\Worker\WorkerOptions;
use PhpDb\Adapter\AdapterInterface;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Psr\Clock\ClockInterface;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;
use stdClass;

use function array_map;
use function date_default_timezone_get;

#[Group('unit')]
final class QueueConfigTest extends TestCase
{
    /**
     * @return array<string, array{array<string, mixed>, callable(QueueConfig): mixed, string}>
     */
    public static function invalidSettingProvider(): array
    {
        return [
            'adapter not a string'         => [
                ['adapter' => 1],
                static fn(QueueConfig $c): mixed => $c->adapter(),
                'The setting "contenir_queue.adapter" must be a string',
            ],
            'backoff not an array'         => [
                ['backoff' => 5],
                static fn(QueueConfig $c): mixed => $c->backoff(),
                'The setting "contenir_queue.backoff" must be an array',
            ],
            'backoff value not an integer' => [
                ['backoff' => ['factor' => '3']],
                static fn(QueueConfig $c): mixed => $c->backoff(),
                'The setting "contenir_queue.backoff.factor" must be an integer',
            ],
        ];
    }

    /**
     * @return array<string, array{mixed}>
     */
    public static function unreadableConfigProvider(): array
    {
        return [
            'no config'           => [null],
            'config not an array' => ['config'],
            'queue not an array'  => [['contenir_queue' => 'x']],
            'queue missing'       => [['other' => []]],
        ];
    }

    /**
     * @param array<string, mixed> $settings
     * @param array<string, mixed> $entries
     */
    private static function config(array $settings = [], array $entries = []): QueueConfig
    {
        return QueueConfig::fromContainer(new ArrayContainer([
            'config' => ['contenir_queue' => $settings],
            ...$entries,
        ]));
    }

    #[Test]
    public function adapterFromANamedEntry(): void
    {
        $adapter = $this->createStub(AdapterInterface::class);

        static::assertSame($adapter, self::config(['adapter' => 'db.site'], ['db.site' => $adapter])->adapter());
    }

    #[Test]
    public function adapterFromTheDefaultEntry(): void
    {
        $adapter = $this->createStub(AdapterInterface::class);

        static::assertSame($adapter, self::config([], [AdapterInterface::class => $adapter])->adapter());
    }

    #[Test]
    public function aScheduleNeedsCronOrAnInterval(): void
    {
        $this->expectException(ConfigurationException::class);
        $this->expectExceptionMessage(
            'The setting "contenir_queue.schedules.sync.every_minutes" must be given',
        );

        self::config(['schedules' => ['sync' => ['job' => 'sync.job']]])->schedules();
    }

    #[Test]
    public function aScheduleTimeZoneMustExist(): void
    {
        $this->expectException(ConfigurationException::class);
        $this->expectExceptionMessage(
            'The setting "contenir_queue.schedules.sync.timezone" must be a time zone, not "Mars/Olympus"',
        );

        self::config(['schedules' => ['sync' => ['job' => 'j', 'cron' => '@daily', 'timezone' => 'Mars/Olympus']]])
            ->schedules();
    }

    #[Test]
    public function backoffSettings(): void
    {
        $config = self::config(['backoff' => ['initial_seconds' => 5, 'max_seconds' => 50, 'factor' => 3]]);

        static::assertEquals(new Backoff(5, 50, 3), $config->backoff());
    }

    #[Test]
    public function clockFromANamedEntry(): void
    {
        $clock = new MutableClock();

        static::assertSame($clock, self::config(['clock' => 'clock'], ['clock' => $clock])->clock());
    }

    #[Test]
    public function clockFromTheContainer(): void
    {
        $clock = new MutableClock();

        static::assertSame($clock, self::config([], [ClockInterface::class => $clock])->clock());
    }

    #[Test]
    public function drainOptionsDefaultToAFewJobs(): void
    {
        static::assertEquals(new DrainOptions(), self::config()->drainOptions());
    }

    #[Test]
    public function drainOptionsFromSettings(): void
    {
        static::assertEquals(
            new DrainOptions(5, 1.5, 'nightly', 300, 900),
            self::config([
                'drain'  => ['max_jobs' => 5, 'max_seconds' => 1.5, 'skip_when_heartbeat_within' => 300],
                'worker' => ['name' => 'nightly', 'stale_after_seconds' => 900],
            ])->drainOptions(),
        );
    }

    #[Test]
    public function getRefusesAnEntryOfAnotherType(): void
    {
        $this->expectException(ConfigurationException::class);
        $this->expectExceptionMessage(
            'The container entry "Contenir\\Queue\\Storage\\JobStoreInterface" is not a ' . JobStoreInterface::class,
        );

        self::config([], [JobStoreInterface::class => new stdClass()])->get(JobStoreInterface::class);
    }

    #[Test]
    public function getReturnsTheEntryOfAType(): void
    {
        $store = $this->createStub(JobStoreInterface::class);

        static::assertSame(
            $store,
            self::config([], [JobStoreInterface::class => $store])->get(JobStoreInterface::class),
        );
    }

    #[Test]
    public function handlersComeFromThisContainerByDefault(): void
    {
        $container = new ArrayContainer();

        static::assertSame($container, QueueConfig::fromContainer($container)->handlers());
    }

    #[Test]
    public function handlersFromANamedContainer(): void
    {
        $handlers = new ArrayContainer();

        static::assertSame($handlers, self::config(['handlers' => 'jobs'], ['jobs' => $handlers])->handlers());
    }

    #[Test]
    public function loggerFromANamedEntry(): void
    {
        $logger = new RecordingLogger();

        static::assertSame($logger, self::config(['logger' => 'log'], ['log' => $logger])->logger());
    }

    #[Test]
    public function loggerFromTheContainer(): void
    {
        $logger = new RecordingLogger();

        static::assertSame($logger, self::config([], [LoggerInterface::class => $logger])->logger());
    }

    #[Test]
    #[DataProvider('unreadableConfigProvider')]
    public function missingConfigMeansDefaults(mixed $config): void
    {
        $entries = null === $config ? [] : ['config' => $config];
        $queue   = QueueConfig::fromContainer(new ArrayContainer($entries));

        static::assertEquals(new Backoff(), $queue->backoff());
    }

    #[Test]
    public function noLoggingWhenTheContainerHasNoLogger(): void
    {
        static::assertInstanceOf(NullLogger::class, self::config()->logger());
    }

    #[Test]
    public function noSchedulesByDefault(): void
    {
        static::assertSame([], self::config()->schedules()->all());
    }

    #[Test]
    public function rejectsAnAdapterEntryOfTheWrongType(): void
    {
        $this->expectException(ConfigurationException::class);
        $this->expectExceptionMessage(
            'The container entry "PhpDb\Adapter\AdapterInterface" is not a PhpDb\Adapter\AdapterInterface',
        );

        self::config([], [AdapterInterface::class => new stdClass()])->adapter();
    }

    /**
     * @param array<string, mixed> $settings
     * @param callable(QueueConfig): mixed $read
     */
    #[Test]
    #[DataProvider('invalidSettingProvider')]
    public function rejectsSettingsOfTheWrongType(array $settings, callable $read, string $message): void
    {
        $this->expectException(ConfigurationException::class);
        $this->expectExceptionMessage($message);

        $read(self::config($settings));
    }

    #[Test]
    public function schedulesFromSettings(): void
    {
        $schedules = self::config([
            'timezone'  => 'Australia/Perth',
            'schedules' => [
                'sync'      => [
                    'job'           => 'sync.job',
                    'every_minutes' => 15,
                    'payload'       => ['all' => true],
                    'queue'         => 'mail',
                ],
                'nightly'   => ['job' => 'nightly.job', 'cron' => '0 2 * * *'],
                'retention' => ['job' => 'retention.job', 'cron' => '@daily', 'timezone' => 'Australia/Sydney'],
                5           => ['job' => 'five.job', 'every_minutes' => 5],
            ],
        ])
            ->schedules()
            ->all();

        static::assertSame(
            [
                ['sync', 'sync.job', 'every 15 minutes', ['all' => true], 'mail'],
                ['nightly', 'nightly.job', '0 2 * * * (Australia/Perth)', [], 'default'],
                ['retention', 'retention.job', '@daily (Australia/Sydney)', [], 'default'],
                ['5', 'five.job', 'every 5 minutes', [], 'default'],
            ],
            array_map(static fn(Schedule $s): array => [
                $s->name,
                $s->jobClass,
                $s->frequency->describe(),
                $s->payload,
                $s->queue,
            ], $schedules),
        );
    }

    #[Test]
    public function systemClockWhenTheContainerHasNone(): void
    {
        static::assertInstanceOf(SystemClock::class, self::config()->clock());
    }

    #[Test]
    public function timezoneDefaultsToPhps(): void
    {
        static::assertSame(date_default_timezone_get(), self::config()->timezone()->getName());
    }

    #[Test]
    public function timezoneFromSettings(): void
    {
        static::assertSame('Australia/Sydney', self::config(['timezone' => 'Australia/Sydney'])->timezone()->getName());
    }

    #[Test]
    public function timezoneMustExist(): void
    {
        $this->expectException(ConfigurationException::class);
        $this->expectExceptionMessage('The setting "contenir_queue.timezone" must be a time zone, not "Nowhere"');

        self::config(['timezone' => 'Nowhere'])->timezone();
    }

    #[Test]
    public function workerOptionsDefaultToCron(): void
    {
        static::assertEquals(new WorkerOptions(), self::config()->workerOptions());
    }

    #[Test]
    public function workerOptionsFromSettings(): void
    {
        static::assertEquals(
            new WorkerOptions('nightly', ['mail'], 0, 10, 2, 64 * 1024 * 1024, 60, 600, 30),
            self::config([
                'worker' => [
                    'name'                => 'nightly',
                    'queues'              => ['mail'],
                    'max_seconds'         => 0,
                    'max_jobs'            => 10,
                    'sleep_seconds'       => 2,
                    'memory_limit_mb'     => 64,
                    'lock_seconds'        => 60,
                    'stale_after_seconds' => 600,
                    'prune_after_days'    => 30,
                ],
            ])->workerOptions(),
        );
    }
}
