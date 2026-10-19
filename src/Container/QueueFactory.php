<?php

declare(strict_types=1);

namespace Contenir\Queue\Container;

use Contenir\Queue\Exception\ConfigurationException;
use Contenir\Queue\Queue;
use Contenir\Queue\Storage\JobStoreInterface;
use Psr\Container\ContainerExceptionInterface;
use Psr\Container\ContainerInterface;

/**
 * @api
 */
final class QueueFactory
{
    /**
     * @throws ConfigurationException
     * @throws ContainerExceptionInterface
     */
    public function __invoke(ContainerInterface $container): Queue
    {
        $config = QueueConfig::fromContainer($container);

        return new Queue($config->get(JobStoreInterface::class), $config->clock());
    }
}
