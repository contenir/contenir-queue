<?php

declare(strict_types=1);

namespace Contenir\Queue\Tests\Unit\Container;

use Contenir\Queue\Clock\SystemClock;
use Contenir\Queue\Container\QueueConfig;
use Contenir\Queue\Exception\ConfigurationException;
use Contenir\Queue\Job\Backoff;
use Contenir\Queue\Storage\JobStoreInterface;
use Contenir\Queue\Tests\TestAsset\Clock\MutableClock;
use Contenir\Queue\Tests\TestAsset\Container\ArrayContainer;
use Contenir\Queue\Tests\TestAsset\Log\RecordingLogger;
use PhpDb\Adapter\AdapterInterface;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Psr\Clock\ClockInterface;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;
use stdClass;

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
                'contenir_queue setting "adapter" must be a string',
            ],
            'backoff not an array'         => [
                ['backoff' => 5],
                static fn(QueueConfig $c): mixed => $c->backoff(),
                'contenir_queue setting "backoff" must be an array',
            ],
            'backoff value not an integer' => [
                ['backoff' => ['factor' => '3']],
                static fn(QueueConfig $c): mixed => $c->backoff(),
                'contenir_queue setting "factor" must be an integer',
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
    public function jobStoreFromTheContainer(): void
    {
        $store = $this->createStub(JobStoreInterface::class);

        static::assertSame($store, self::config([], [JobStoreInterface::class => $store])->jobStore());
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
    public function systemClockWhenTheContainerHasNone(): void
    {
        static::assertInstanceOf(SystemClock::class, self::config()->clock());
    }
}
