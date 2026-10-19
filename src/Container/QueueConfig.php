<?php

declare(strict_types=1);

namespace Contenir\Queue\Container;

use Contenir\Queue\Clock\SystemClock;
use Contenir\Queue\Exception\ConfigurationException;
use Contenir\Queue\Job\Backoff;
use Contenir\Queue\Worker\DrainOptions;
use Contenir\Queue\Worker\WorkerOptions;
use PhpDb\Adapter\AdapterInterface;
use Psr\Clock\ClockInterface;
use Psr\Container\ContainerExceptionInterface;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;

use function is_array;

/**
 * The `contenir_queue` configuration, and the services it names, read from
 * the container. See {@see \Contenir\Queue\ConfigProvider} for the keys.
 *
 * Values the options refuse (a negative limit, say) raise their own
 * InvalidArgumentException.
 *
 * @api For hosts that build the queue's services themselves.
 *
 * @mago-expect lint:too-many-methods One accessor per setting or service.
 */
final readonly class QueueConfig
{
    public const string KEY = 'contenir_queue';

    private function __construct(
        private ContainerInterface $container,
        private Settings $settings,
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

        return new self($container, new Settings(is_array($queue) ? $queue : [], self::KEY));
    }

    /**
     * The site database, where the queue's tables live.
     *
     * @throws ConfigurationException
     * @throws ContainerExceptionInterface
     */
    public function adapter(): AdapterInterface
    {
        return $this->service($this->settings->string('adapter', AdapterInterface::class), AdapterInterface::class);
    }

    /**
     * @throws ConfigurationException
     */
    public function backoff(): Backoff
    {
        $backoff = $this->settings->section('backoff');

        return new Backoff(
            $backoff->int('initial_seconds', Backoff::DEFAULT_INITIAL_SECONDS),
            $backoff->int('max_seconds', Backoff::DEFAULT_MAX_SECONDS),
            $backoff->int('factor', Backoff::DEFAULT_FACTOR),
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
        $name = $this->settings->string('clock', ClockInterface::class);

        return $this->container->has($name)
            ? $this->service($name, ClockInterface::class)
            : new SystemClock();
    }

    /**
     * @throws ConfigurationException
     */
    public function drainOptions(): DrainOptions
    {
        $drain  = $this->settings->section('drain');
        $worker = $this->settings->section('worker');

        return new DrainOptions(
            maxJobs: $drain->int('max_jobs', 3),
            maxSeconds: $drain->number('max_seconds', 2.0),
            workerName: $worker->string('name', WorkerOptions::DEFAULT_NAME),
            skipWhenHeartbeatWithin: $drain->int('skip_when_heartbeat_within', 120),
            staleAfterSeconds: $worker->int('stale_after_seconds', 3600),
        );
    }

    /**
     * The container entry of the given type, under its own name.
     *
     * @template T of object
     *
     * @param class-string<T> $type
     *
     * @return T
     *
     * @throws ConfigurationException
     * @throws ContainerExceptionInterface
     */
    public function get(string $type): object
    {
        return $this->service($type, $type);
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
        $name = $this->settings->string('handlers', '');

        return '' === $name ? $this->container : $this->service($name, ContainerInterface::class);
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
        $name = $this->settings->string('logger', LoggerInterface::class);

        return $this->container->has($name)
            ? $this->service($name, LoggerInterface::class)
            : new NullLogger();
    }

    /**
     * @throws ConfigurationException
     */
    public function workerOptions(): WorkerOptions
    {
        $worker = $this->settings->section('worker');

        return new WorkerOptions(
            name: $worker->string('name', WorkerOptions::DEFAULT_NAME),
            queues: $worker->strings('queues'),
            maxSeconds: $worker->int('max_seconds', 50),
            maxJobs: $worker->int('max_jobs', 0),
            sleepSeconds: $worker->int('sleep_seconds', 5),
            memoryLimitBytes: $worker->int('memory_limit_mb', 128) * 1024 * 1024,
            lockSeconds: $worker->int('lock_seconds', 300),
            staleAfterSeconds: $worker->int('stale_after_seconds', 3600),
            pruneAfterDays: $worker->int('prune_after_days', 7),
        );
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
}
