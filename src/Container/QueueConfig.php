<?php

declare(strict_types=1);

namespace Contenir\Queue\Container;

use Contenir\Queue\Clock\SystemClock;
use Contenir\Queue\Exception\ConfigurationException;
use Contenir\Queue\Job\Backoff;
use Contenir\Queue\Storage\JobStoreInterface;
use PhpDb\Adapter\AdapterInterface;
use Psr\Clock\ClockInterface;
use Psr\Container\ContainerExceptionInterface;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;

use function is_array;
use function is_int;
use function is_string;

/**
 * The `contenir_queue` configuration, and the services it names, read from
 * the container. See {@see \Contenir\Queue\ConfigProvider} for the keys.
 *
 * @api For hosts that build the queue's services themselves.
 *
 * @mago-expect lint:too-many-methods One accessor per setting or service, plus the readers they share.
 */
final readonly class QueueConfig
{
    public const string KEY = 'contenir_queue';

    /**
     * @param array<array-key, mixed> $config
     */
    private function __construct(
        private ContainerInterface $container,
        private array $config,
    ) {}

    /**
     * @throws ContainerExceptionInterface
     *
     * @mago-expect analysis:mixed-assignment The config service is untyped; its shape is checked here.
     */
    public static function fromContainer(ContainerInterface $container): self
    {
        $config = $container->has('config') ? $container->get('config') : [];
        $queue  = is_array($config) ? $config[self::KEY] ?? [] : [];

        return new self($container, is_array($queue) ? $queue : []);
    }

    /**
     * @param array<array-key, mixed> $values
     *
     * @throws ConfigurationException
     *
     * @mago-expect analysis:mixed-assignment Config values are untyped; the type is checked here.
     */
    private static function intIn(array $values, string $key, int $default): int
    {
        $value = $values[$key] ?? $default;

        return is_int($value) ? $value : throw ConfigurationException::invalidSetting($key, 'an integer');
    }

    /**
     * The site database, where the queue's tables live.
     *
     * @throws ConfigurationException
     * @throws ContainerExceptionInterface
     */
    public function adapter(): AdapterInterface
    {
        return $this->service($this->string('adapter', AdapterInterface::class), AdapterInterface::class);
    }

    /**
     * @throws ConfigurationException
     */
    public function backoff(): Backoff
    {
        $backoff = $this->section('backoff');

        return new Backoff(
            self::intIn($backoff, 'initial_seconds', Backoff::DEFAULT_INITIAL_SECONDS),
            self::intIn($backoff, 'max_seconds', Backoff::DEFAULT_MAX_SECONDS),
            self::intIn($backoff, 'factor', Backoff::DEFAULT_FACTOR),
        );
    }

    /**
     * The PSR-20 clock the configuration names, if the container has it, or
     * the system clock in UTC.
     *
     * @throws ConfigurationException
     * @throws ContainerExceptionInterface
     */
    public function clock(): ClockInterface
    {
        $name = $this->string('clock', ClockInterface::class);

        return $this->container->has($name)
            ? $this->service($name, ClockInterface::class)
            : new SystemClock();
    }

    /**
     * The container job handlers come from: the one the configuration names,
     * or this container.
     *
     * @throws ConfigurationException
     * @throws ContainerExceptionInterface
     */
    public function handlers(): ContainerInterface
    {
        $name = $this->string('handlers', '');

        return '' === $name ? $this->container : $this->service($name, ContainerInterface::class);
    }

    /**
     * @throws ConfigurationException
     * @throws ContainerExceptionInterface
     */
    public function jobStore(): JobStoreInterface
    {
        return $this->service(JobStoreInterface::class, JobStoreInterface::class);
    }

    /**
     * The PSR-3 logger the configuration names, if the container has it, or a
     * logger that discards everything.
     *
     * @throws ConfigurationException
     * @throws ContainerExceptionInterface
     */
    public function logger(): LoggerInterface
    {
        $name = $this->string('logger', LoggerInterface::class);

        return $this->container->has($name)
            ? $this->service($name, LoggerInterface::class)
            : new NullLogger();
    }

    /**
     * @return array<array-key, mixed>
     *
     * @throws ConfigurationException
     *
     * @mago-expect analysis:mixed-assignment Config values are untyped; the type is checked here.
     */
    private function section(string $key): array
    {
        $section = $this->config[$key] ?? [];

        return is_array($section) ? $section : throw ConfigurationException::invalidSetting($key, 'an array');
    }

    /**
     * @template T of object
     *
     * @param class-string<T> $type
     *
     * @return T
     *
     * @throws ConfigurationException
     * @throws ContainerExceptionInterface
     *
     * @mago-expect analysis:mixed-assignment Container services are untyped; the type is checked here.
     */
    private function service(string $name, string $type): object
    {
        $service = $this->container->get($name);

        return $service instanceof $type ? $service : throw ConfigurationException::invalidService($name, $type);
    }

    /**
     * @throws ConfigurationException
     *
     * @mago-expect analysis:mixed-assignment Config values are untyped; the type is checked here.
     */
    private function string(string $key, string $default): string
    {
        $value = $this->config[$key] ?? $default;

        return is_string($value) ? $value : throw ConfigurationException::invalidSetting($key, 'a string');
    }
}
