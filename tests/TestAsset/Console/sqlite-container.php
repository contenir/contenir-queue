<?php

declare(strict_types=1);

use Contenir\Queue\Tests\TestAsset\Container\FactoryContainer;
use Contenir\Queue\Tests\TestAsset\Database\TestDatabase;
use Contenir\Queue\Tests\TestAsset\Job\CallbackHandler;
use PhpDb\Adapter\AdapterInterface;

/**
 * The services bin/contenir-queue needs, on the SQLite file named by
 * CONTENIR_QUEUE_TEST_SQLITE, with a "sync" handler that does nothing.
 */
return new FactoryContainer([
    AdapterInterface::class => TestDatabase::sqliteFile((string) getenv('CONTENIR_QUEUE_TEST_SQLITE')),
    'sync'                  => CallbackHandler::succeeding(),
]);
