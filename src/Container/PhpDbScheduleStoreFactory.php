<?php

declare(strict_types=1);

namespace Contenir\Queue\Container;

use Contenir\Queue\Exception\ConfigurationException;
use Contenir\Queue\Storage\PhpDbScheduleStore;
use Psr\Container\ContainerExceptionInterface;
use Psr\Container\ContainerInterface;

/**
 * @api
 */
final class PhpDbScheduleStoreFactory
{
    /**
     * @throws ConfigurationException
     * @throws ContainerExceptionInterface
     */
    public function __invoke(ContainerInterface $container): PhpDbScheduleStore
    {
        return new PhpDbScheduleStore(QueueConfig::fromContainer($container)->adapter());
    }
}
