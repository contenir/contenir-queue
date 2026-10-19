<?php

declare(strict_types=1);

namespace Contenir\Queue\Container;

use Contenir\Queue\Exception\ConfigurationException;
use Contenir\Queue\Storage\PhpDbWorkerStore;
use Psr\Container\ContainerExceptionInterface;
use Psr\Container\ContainerInterface;

/**
 * @api
 */
final class PhpDbWorkerStoreFactory
{
    /**
     * @throws ConfigurationException
     * @throws ContainerExceptionInterface
     */
    public function __invoke(ContainerInterface $container): PhpDbWorkerStore
    {
        return new PhpDbWorkerStore(QueueConfig::fromContainer($container)->adapter());
    }
}
