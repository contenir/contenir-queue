<?php

declare(strict_types=1);

namespace Contenir\Queue\Container;

use Contenir\Queue\Exception\ConfigurationException;
use Contenir\Queue\Job\JobRunner;
use Contenir\Queue\Schedule\Scheduler;
use Contenir\Queue\Storage\WorkerStoreInterface;
use Contenir\Queue\Worker\Drainer;
use Contenir\Queue\Worker\Housekeeping;
use Psr\Container\ContainerExceptionInterface;
use Psr\Container\ContainerInterface;

/**
 * @api
 */
final class DrainerFactory
{
    /**
     * @throws ConfigurationException
     * @throws ContainerExceptionInterface
     */
    public function __invoke(ContainerInterface $container): Drainer
    {
        $config = QueueConfig::fromContainer($container);

        return new Drainer(
            $config->get(JobRunner::class),
            $config->get(Housekeeping::class),
            $config->get(WorkerStoreInterface::class),
            $config->get(Scheduler::class),
            $config->clock(),
            $config->drainOptions(),
        );
    }
}
