<?php

declare(strict_types=1);

namespace Contenir\Queue\Container;

use Contenir\Queue\Exception\ConfigurationException;
use Contenir\Queue\Job\JobRunner;
use Contenir\Queue\Storage\JobStoreInterface;
use Contenir\Queue\Worker\Housekeeping;
use Psr\Container\ContainerExceptionInterface;
use Psr\Container\ContainerInterface;

/**
 * @api
 */
final class HousekeepingFactory
{
    /**
     * @throws ConfigurationException
     * @throws ContainerExceptionInterface
     */
    public function __invoke(ContainerInterface $container): Housekeeping
    {
        $config = QueueConfig::fromContainer($container);

        return new Housekeeping(
            $config->get(JobStoreInterface::class),
            $config->get(JobRunner::class),
            $config->clock(),
            $config->logger(),
        );
    }
}
