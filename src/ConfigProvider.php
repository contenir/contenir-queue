<?php

declare(strict_types=1);

namespace Contenir\Queue;

use Contenir\Queue\Container\DrainerFactory;
use Contenir\Queue\Container\HousekeepingFactory;
use Contenir\Queue\Container\JobRunnerFactory;
use Contenir\Queue\Container\PhpDbJobStoreFactory;
use Contenir\Queue\Container\PhpDbWorkerStoreFactory;
use Contenir\Queue\Container\QueueConfig;
use Contenir\Queue\Container\QueueFactory;
use Contenir\Queue\Container\WorkerFactory;
use Contenir\Queue\Job\JobRunner;
use Contenir\Queue\Storage\JobStoreInterface;
use Contenir\Queue\Storage\PhpDbJobStore;
use Contenir\Queue\Storage\PhpDbWorkerStore;
use Contenir\Queue\Storage\WorkerStoreInterface;
use Contenir\Queue\Worker\Drainer;
use Contenir\Queue\Worker\Housekeeping;
use Contenir\Queue\Worker\Worker;
use PhpDb\Adapter\AdapterInterface;
use Psr\Clock\ClockInterface;
use Psr\Log\LoggerInterface;

use function dirname;

/**
 * Services and settings for a PSR-11 container that reads Laminas-style
 * configuration (laminas-servicemanager, or any container built from the same
 * arrays).
 *
 * Settings under `contenir_queue`:
 *
 * - `adapter`: the container entry for the site database (default
 *   {@see AdapterInterface}).
 * - `clock`, `logger`: container entries for a PSR-20 clock and a PSR-3
 *   logger, used when the container has them; otherwise the system clock in
 *   UTC and no logging.
 * - `handlers`: a container entry for the PSR-11 container that job handlers
 *   come from; empty means this container.
 * - `backoff`: `initial_seconds` (60), `max_seconds` (86400) and `factor` (2).
 * - `worker`: `name` ("default"), `queues` (all), `max_seconds` (50, 0 for
 *   no limit), `max_jobs` (0, none), `sleep_seconds` (5), `memory_limit_mb`
 *   (128), `lock_seconds` (300), `stale_after_seconds` (3600) and
 *   `prune_after_days` (7, 0 keeps done jobs). See {@see Worker\WorkerOptions}.
 * - `drain`: `max_jobs` (3), `max_seconds` (2) and
 *   `skip_when_heartbeat_within` (120). See {@see Worker\DrainOptions}.
 *
 * The queue's tables are created by the migrations in `schema/`, listed under
 * `contenir.schema_migrations` for the host's migration runner.
 *
 * @api
 */
final class ConfigProvider
{
    public const string SCHEMA_NAMESPACE = __NAMESPACE__ . '\\Schema';

    /**
     * The directory of the queue's migrations, for a host that runs them with
     * peptolab/phpdb-migration under {@see SCHEMA_NAMESPACE}.
     */
    public static function schemaPath(): string
    {
        return dirname(__DIR__) . '/schema';
    }

    /**
     * @return array{aliases: array<string, string>, factories: array<string, class-string>}
     */
    public function getDependencies(): array
    {
        return [
            'aliases'   => [
                QueueInterface::class       => Queue::class,
                JobStoreInterface::class    => PhpDbJobStore::class,
                WorkerStoreInterface::class => PhpDbWorkerStore::class,
            ],
            'factories' => [
                Drainer::class          => DrainerFactory::class,
                Housekeeping::class     => HousekeepingFactory::class,
                JobRunner::class        => JobRunnerFactory::class,
                PhpDbJobStore::class    => PhpDbJobStoreFactory::class,
                PhpDbWorkerStore::class => PhpDbWorkerStoreFactory::class,
                Queue::class            => QueueFactory::class,
                Worker::class           => WorkerFactory::class,
            ],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function __invoke(): array
    {
        return [
            'dependencies'   => $this->getDependencies(),
            QueueConfig::KEY => [
                'adapter'  => AdapterInterface::class,
                'clock'    => ClockInterface::class,
                'logger'   => LoggerInterface::class,
                'handlers' => '',
            ],
            'contenir'       => [
                'schema_migrations' => [
                    self::SCHEMA_NAMESPACE => self::schemaPath(),
                ],
            ],
        ];
    }
}
