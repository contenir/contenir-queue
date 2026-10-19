<?php

declare(strict_types=1);

namespace Contenir\Queue\Container;

use Contenir\Queue\Exception\ConfigurationException;
use Contenir\Queue\Job\JobRunner;
use Contenir\Queue\Storage\WorkerStoreInterface;
use Contenir\Queue\Worker\Housekeeping;
use Contenir\Queue\Worker\SystemProcess;
use Contenir\Queue\Worker\Worker;
use Psr\Container\ContainerExceptionInterface;
use Psr\Container\ContainerInterface;

/**
 * @api
 */
final class WorkerFactory
{
    /**
     * @throws ConfigurationException
     * @throws ContainerExceptionInterface
     */
    public function __invoke(ContainerInterface $container): Worker
    {
        $config = QueueConfig::fromContainer($container);

        return new Worker(
            $config->get(JobRunner::class),
            $config->get(Housekeeping::class),
            $config->get(WorkerStoreInterface::class),
            $config->clock(),
            $config->logger(),
            new SystemProcess(),
        );
    }
}
