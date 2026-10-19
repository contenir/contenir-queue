<?php

declare(strict_types=1);

namespace Contenir\Queue\Container;

use Contenir\Queue\Exception\ConfigurationException;
use Contenir\Queue\Monitor\QueueMonitor;
use Contenir\Queue\Schedule\Schedules;
use Contenir\Queue\Storage\JobBrowserInterface;
use Contenir\Queue\Storage\ScheduleStoreInterface;
use Contenir\Queue\Storage\WorkerStoreInterface;
use Psr\Container\ContainerExceptionInterface;
use Psr\Container\ContainerInterface;

/**
 * @api
 */
final class QueueMonitorFactory
{
    /**
     * @throws ConfigurationException
     * @throws ContainerExceptionInterface
     */
    public function __invoke(ContainerInterface $container): QueueMonitor
    {
        $config = QueueConfig::fromContainer($container);

        return new QueueMonitor(
            $config->get(JobBrowserInterface::class),
            $config->get(ScheduleStoreInterface::class),
            $config->get(Schedules::class),
            $config->get(WorkerStoreInterface::class),
            $config->clock(),
        );
    }
}
