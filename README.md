# contenir/queue

[![Continuous Integration](https://github.com/contenir/contenir-queue/actions/workflows/continuous-integration.yml/badge.svg)](https://github.com/contenir/contenir-queue/actions/workflows/continuous-integration.yml)
[![codecov](https://codecov.io/gh/contenir/contenir-queue/graph/badge.svg)](https://codecov.io/gh/contenir/contenir-queue)

Durable jobs, retries and schedules for [Contenir](https://github.com/contenir) CMS. Every CMS installs it, so hosts
and add-ons can hand work to the background and know it will run.

- **Jobs** live in a table in the site's database, MySQL or SQLite. A job is never lost because nothing was running
  when it was pushed.
- **Retries** back off from one minute to 24 hours, honour a `Retry-After`, and then mark the job failed.
- **A worker**, `bin/contenir-queue`, that cron runs every minute. It takes a lock so only one runs, and writes a
  heartbeat a host can check. It also runs under a process supervisor.
- **Draining without cron**: a web request can run a few due jobs after its response has been sent, so a site whose
  cron entry is missing slows down instead of stopping.
- **Schedules**: recurring work every so many minutes or on a cron expression. Each run is recorded, and a run whose
  last job hasn't finished is skipped.
- **Data for screens and checks**, with no UI: jobs by state with retry and delete, schedules with their last and next
  runs, and the heartbeat behind a "Background jobs aren't running" warning.

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
        'worker'   => [
            'name'                => 'default', // the lock and heartbeat
            'queues'              => [],        // all
            'max_seconds'         => 50,        // 0: run until stopped, waiting for work
            'max_jobs'            => 0,         // no limit
            'sleep_seconds'       => 5,         // with no time limit, the wait when nothing is due
            'memory_limit_mb'     => 128,
            'lock_seconds'        => 300,       // how long a dead worker keeps others out
            'stale_after_seconds' => 3600,      // a job locked this long is retried
            'prune_after_days'    => 7,         // done jobs older than this are deleted; 0 keeps them
        ],
        'drain'    => ['max_jobs' => 3, 'max_seconds' => 2, 'skip_when_heartbeat_within' => 120],
        'timezone' => '',                                     // for cron schedules; '' is PHP's default
        'schedules' => [
            'forms.retention' => ['job' => RedactExpiredEntries::class, 'cron' => '15 3 * * *'],
            'search.reindex'  => ['job' => Reindex::class, 'every_minutes' => 60, 'payload' => ['full' => false]],
        ],
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
failed. A job whose handler isn't in the container (its add-on is disabled, say) fails at once; retry it from the
screen once the handler is back. An error while the container builds a handler is an ordinary failure, since it may
pass.

States: `waiting` (new, or waiting for a retry), `running` (locked by the worker running it), `done` and `failed`.
Finished jobs stay in the table.

## The worker

Cron runs the worker every minute from the site's directory:

```
* * * * * cd /path/to/site && php vendor/bin/contenir-queue
```

It needs the host's PSR-11 container: a PHP file that returns it, given as `--container=FILE`, or in the
`CONTENIR_QUEUE_CONTAINER` environment variable, or at `config/container.php` by default.

Each run takes the worker lock. If another run still holds it, this one says so and stops. Otherwise it retries jobs
whose worker died mid-run, deletes done jobs older than a week, queues any schedules that are due, and runs due jobs
one at a time. It stops as soon as nothing is due, so on a quiet site each run is over in a moment; a job pushed
mid-minute waits for the next run. After 50 seconds it takes no new jobs, finishes the one it is running, releases the
lock and exits. Every turn renews the lock, and the renewal is the heartbeat.

For a busy site, run it under a process supervisor instead:

```
php vendor/bin/contenir-queue --daemon
```

It runs with no time limit and waits for work, looking again every five seconds when nothing is due. It stops when
it receives SIGTERM or SIGINT (with the `pcntl` extension), reaches its memory limit or `--max-jobs`,
then finishes its current job and exits for the supervisor to restart it. `--help` lists the options, which override
the `worker` settings.

From PHP, `Worker::run(WorkerOptions)` does the same and returns a `WorkerReport`.

## Without cron

After the response has been sent, a host can run a few due jobs:

```php
use Contenir\Queue\Worker\Drainer;

if (function_exists('fastcgi_finish_request')) {
    fastcgi_finish_request();
}

$container->get(Drainer::class)->drain();
```

It runs at most three jobs and starts none after two seconds. It does nothing while the worker's heartbeat is less
than two minutes old, so it only works when cron doesn't. It doesn't delete old done jobs; the worker does that.

## Schedules

A schedule pushes a job each time it falls due. Configure them under `schedules` (above), or add them to the
`Schedules` service as the host boots:

```php
use Contenir\Queue\Schedule\Frequency;
use Contenir\Queue\Schedule\Schedule;
use Contenir\Queue\Schedule\Schedules;

$schedules = $container->get(Schedules::class);
$schedules->add(new Schedule('mailchimp.sync', SyncAudience::class, Frequency::everyMinutes(15)));
$schedules->add(new Schedule(
    'forms.retention',
    RedactExpiredEntries::class,
    Frequency::cron('15 3 * * *', new DateTimeZone('Australia/Sydney')),
));
```

`Frequency::everyMinutes()` and `Frequency::cron()` match contenir/extension-api's `Frequency`, so a host maps an
add-on's `Registry::schedule()` declarations onto these directly. A minute is the finest interval.

Cron expressions have the standard five fields: minute, hour, day of month, month and day of week. A field takes `*`,
numbers, ranges (`1-5`), steps (`*/15`, `1-30/5`) and lists (`0,30`). Months and days also take their names (`jan`,
`mon-fri`), and Sunday is 0 or 7. When both day fields are restricted, either one matching is enough, as in cron
itself. `@hourly`, `@daily` (or `@midnight`), `@weekly`, `@monthly` and `@yearly` (or `@annually`) are accepted too.
An expression is read on the wall clock of its time zone; a time that a daylight-saving change skips runs an hour
later, and a time that it repeats runs once.

The worker checks for due schedules on every turn, and so does the drain. An interval schedule runs as soon as it is
first seen, then keeps to its beat; a cron schedule waits for its next matching time. If the job from the last run is
still waiting or running, the run is skipped. Either way it is recorded in `queue_schedule_run`. Runs missed while
nothing was running are not made up: the schedule runs once and moves on to its next time.

## Screens and checks

`QueueMonitor` has what a host's screens need, and draws nothing:

```php
use Contenir\Queue\Job\JobState;
use Contenir\Queue\Monitor\QueueMonitor;

$monitor = $container->get(QueueMonitor::class);

$monitor->counts();                     // JobCounts: waiting, running, done, failed
$monitor->jobs(JobState::Failed, 50);   // newest first; waiting jobs by next attempt
$monitor->retry($id);                   // a failed job gets a fresh set of attempts
$monitor->delete($id);                  // any job that isn't running
$monitor->schedules();                  // ScheduleStatus: frequency, last run and its job's state, next run
$monitor->runs('forms.retention');      // a schedule's recent runs, skipped ones included

$health = $monitor->health();           // QueueHealth
if ($health->isStopped()) {
    // "Background jobs aren't running. {$health->waiting} jobs are waiting; the oldest is
    //  {$health->oldestWaitingAge} seconds old."
}
```

`isConfigured()` is true while the worker has written a heartbeat in the last 10 minutes, and `isStopped()` once it is
over an hour old (or the worker has never run), as the extensions plan sets out. Both take another threshold.

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
