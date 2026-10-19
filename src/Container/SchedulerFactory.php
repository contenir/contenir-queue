<?php

declare(strict_types=1);

namespace Contenir\Queue\Container;

use Contenir\Queue\Exception\ConfigurationException;
use Contenir\Queue\QueueInterface;
use Contenir\Queue\Schedule\Scheduler;
use Contenir\Queue\Schedule\Schedules;
use Contenir\Queue\Storage\ScheduleStoreInterface;
use Psr\Container\ContainerExceptionInterface;
use Psr\Container\ContainerInterface;

/**
 * @api
 */
final class SchedulerFactory
{
    /**
     * @throws ConfigurationException
     * @throws ContainerExceptionInterface
     */
    public function __invoke(ContainerInterface $container): Scheduler
    {
        $config = QueueConfig::fromContainer($container);

        return new Scheduler(
            $config->get(Schedules::class),
            $config->get(ScheduleStoreInterface::class),
            $config->get(QueueInterface::class),
            $config->clock(),
            $config->logger(),
        );
    }
}
