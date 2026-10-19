<?php

declare(strict_types=1);

namespace Contenir\Queue\Container;

use Contenir\Queue\Exception\ConfigurationException;
use Contenir\Queue\Schedule\Schedules;
use Psr\Container\ContainerExceptionInterface;
use Psr\Container\ContainerInterface;

/**
 * The schedules from `contenir_queue.schedules`. The host adds its own and
 * its add-ons' to the same service as it boots.
 *
 * @api
 */
final class SchedulesFactory
{
    /**
     * @throws ConfigurationException
     * @throws ContainerExceptionInterface
     */
    public function __invoke(ContainerInterface $container): Schedules
    {
        return QueueConfig::fromContainer($container)->schedules();
    }
}
