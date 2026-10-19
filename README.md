# contenir/queue

[![Continuous Integration](https://github.com/contenir/contenir-queue/actions/workflows/continuous-integration.yml/badge.svg)](https://github.com/contenir/contenir-queue/actions/workflows/continuous-integration.yml)
[![codecov](https://codecov.io/gh/contenir/contenir-queue/graph/badge.svg)](https://codecov.io/gh/contenir/contenir-queue)

Durable jobs, retries and schedules for [Contenir](https://github.com/contenir) CMS. Every CMS installs it, so hosts
and add-ons can hand work to the background and know it will run.

- **Jobs** live in a table in the site's database, MySQL or SQLite. A job is never lost because nothing was running
  when it was pushed.
- **Retries** back off from one minute to 24 hours, honour a `Retry-After`, and then mark the job failed.

It is framework free: job handlers come from any PSR-11 container, logging is PSR-3, time is a PSR-20 clock, and
storage is [php-db](https://github.com/php-db/phpdb). There are no Laminas MVC or Mezzio types; a host wires it in.

## Requirements

- PHP 8.3, 8.4 or 8.5
- php-db/phpdb 0.6 with its MySQL or SQLite driver
- peptolab/phpdb-migration 0.2, to create the tables

## Install

```bash
composer require contenir/queue
```

php-db 0.6 has no stable release yet, so the site's `composer.json` needs `"minimum-stability": "dev"` with
`"prefer-stable": true`, or `"php-db/phpdb": "0.6.x-dev@dev"` in its own `require`.

### Tables

The migrations in `schema/` create the tables. They run on MySQL and SQLite, under the namespace
`Contenir\Queue\Schema`. A host that runs peptolab/phpdb-migration points it at them:

```php
use Contenir\Queue\ConfigProvider;
use PhpDb\Migration\MigrationRunner;

$runner = new MigrationRunner($siteAdapter, ConfigProvider::schemaPath(), ConfigProvider::SCHEMA_NAMESPACE);
$runner->ensureMigrationsTable();
$runner->runPending();
```

`ConfigProvider` also lists them under `contenir.schema_migrations`, as the other Contenir packages with tables do.

### Services

`Contenir\Queue\ConfigProvider` registers the services for a container built from Laminas-style configuration. Its
settings, all optional, live under `contenir_queue`:

```php
return [
    'contenir_queue' => [
        'adapter'  => PhpDb\Adapter\AdapterInterface::class, // the site database
        'clock'    => Psr\Clock\ClockInterface::class,       // the system clock in UTC if absent
        'logger'   => Psr\Log\LoggerInterface::class,        // no logging if absent
        'handlers' => '',                                     // a container for job handlers; '' is this one
        'backoff'  => ['initial_seconds' => 60, 'max_seconds' => 86400, 'factor' => 2],
    ],
];
```

## Jobs

A job names a handler and carries a payload. Push it with `QueueInterface`:

```php
use Contenir\Queue\QueueInterface;

$queue = $container->get(QueueInterface::class);
$queue->push(SyncContact::class, ['contact' => 42]);
$queue->push(SendDigest::class, [], queue: 'mail', notBefore: new DateTimeImmutable('tomorrow 06:00'));
```

The handler is the container entry the job names, and implements `JobHandlerInterface`:

```php
use Contenir\Queue\Job\Job;
use Contenir\Queue\Job\JobHandlerInterface;
use Contenir\Queue\Job\RetryLaterException;
use Contenir\Queue\Job\UnrecoverableJobException;

final class SyncContact implements JobHandlerInterface
{
    public function handle(Job $job): void
    {
        $response = $this->api->send($job->payload()['contact']);

        if ($response->getStatusCode() === 429) {
            throw RetryLaterException::fromRetryAfter($response->getHeaderLine('Retry-After'));
        }

        if ($response->getStatusCode() === 404) {
            throw new UnrecoverableJobException('The contact no longer exists');
        }
    }
}
```

Returning marks the job done. Anything thrown is recorded as the job's last error, and then:

| Thrown | What happens |
|---|---|
| `RetryLaterException` | The next attempt waits until the time it gives (seconds or an HTTP date), up to 24 hours. |
| `UnrecoverableJobException` | The job fails at once. |
| Anything else | The next attempt waits 1, 2, 4 … 1,024 minutes, then 24 hours. |

A job gets 13 attempts over about two and a half days. After a failure that follows the 24-hour wait, it is marked
failed. A missing handler is an ordinary failure, so a job for a disabled add-on waits and retries rather than
vanishing.

States: `waiting` (new, or waiting for a retry), `running` (locked by the worker running it), `done` and `failed`.
Finished jobs stay in the table.

## Development

```bash
composer check
```

runs Mago (format, lint, analyse), the unit and integration suites, and Infection, which must kill every mutant. The
integration suite runs on SQLite in memory, then on MySQL when one answers: set `CONTENIR_TEST_DB_HOST`, `_PORT`,
`_NAME`, `_USER` and `_PASSWORD` (defaults `127.0.0.1`, `3306`, `contenir_queue_test`, `contenir`, `contenir`). The
tests drop and recreate the queue's tables and the `migrations` table in that database, so give them one of their
own.

## Licence

BSD-3-Clause. See [LICENSE.md](LICENSE.md).
