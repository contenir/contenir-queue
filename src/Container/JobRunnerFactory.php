<?php

declare(strict_types=1);

namespace Contenir\Queue\Container;

use Contenir\Queue\Exception\ConfigurationException;
use Contenir\Queue\Job\JobRunner;
use Contenir\Queue\Storage\JobStoreInterface;
use Psr\Container\ContainerExceptionInterface;
use Psr\Container\ContainerInterface;

/**
 * @api
 */
final class JobRunnerFactory
{
    /**
     * @throws ConfigurationException
     * @throws ContainerExceptionInterface
     */
    public function __invoke(ContainerInterface $container): JobRunner
    {
        $config = QueueConfig::fromContainer($container);

        return new JobRunner(
            $config->handlers(),
            $config->get(JobStoreInterface::class),
            $config->backoff(),
            $config->clock(),
            $config->logger(),
        );
    }
}
