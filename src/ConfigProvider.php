<?php

declare(strict_types=1);

namespace Contenir\Queue;

use Contenir\Queue\Container\JobRunnerFactory;
use Contenir\Queue\Container\PhpDbJobStoreFactory;
use Contenir\Queue\Container\QueueConfig;
use Contenir\Queue\Container\QueueFactory;
use Contenir\Queue\Job\JobRunner;
use Contenir\Queue\Storage\JobStoreInterface;
use Contenir\Queue\Storage\PhpDbJobStore;
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
                QueueInterface::class    => Queue::class,
                JobStoreInterface::class => PhpDbJobStore::class,
            ],
            'factories' => [
                JobRunner::class     => JobRunnerFactory::class,
                PhpDbJobStore::class => PhpDbJobStoreFactory::class,
                Queue::class         => QueueFactory::class,
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
