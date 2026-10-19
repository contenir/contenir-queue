# Changelog

All notable changes to this project are documented here. The format follows
[Keep a Changelog](https://keepachangelog.com/en/1.1.0/), and the project
adheres to [Semantic Versioning](https://semver.org/).

## [Unreleased] - 2026-10-19

### Added

- Durable jobs in a `queue_job` table on MySQL or SQLite, created by a peptolab/phpdb-migration migration in
  `schema/`: job class, payload, queue, state (waiting, running, done, failed), attempts, next attempt, lock and last
  error.
- `QueueInterface::push()` to store a job, now or for later, on a named queue.
- `JobHandlerInterface` for handlers, resolved from a PSR-11 container by the job's class.
- `JobRunner`, which runs a claimed job and records the result. Failures retry with `Backoff` (1 minute doubling to
  24 hours, then failed). `RetryLaterException` sets the next attempt, including from an HTTP `Retry-After`, and
  `UnrecoverableJobException` fails a job at once.
- `PhpDbJobStore`, which claims jobs atomically so several workers and web requests can share the table, and only
  finishes a job while it still holds the lock it was claimed with.
- `bin/contenir-queue`, the worker cron runs every minute. It takes a lock in a `queue_worker` table so only one runs,
  renews it as a heartbeat, works for up to 50 seconds (configurable) and exits. `--daemon` runs it until SIGTERM,
  its memory limit or a job limit, for a process supervisor.
- `Worker`, which the command runs. Before taking jobs it retries jobs whose worker died mid-run (locked for over an
  hour) and deletes done jobs older than seven days.
- `Drainer::drain()`, for a host to call after the response has been sent: it runs up to three due jobs within two
  seconds, unless the worker's heartbeat is under two minutes old.
- Schedules: `Schedule` with a `Frequency` (`everyMinutes()`, or `cron()` in a time zone, using
  dragonmantank/cron-expression), added to the `Schedules` service or configured under `contenir_queue.schedules`.
  `Scheduler::tick()`, run by the worker on every turn and by the drain, pushes the job of each schedule that has
  fallen due. A run whose last job is still waiting or running is skipped. Each run is recorded in
  `queue_schedule_run`, and the schedule's state is kept in `queue_schedule`.
- `QueueMonitor`, the data for a host's screens and checks: job counts, jobs by state, retry and delete, schedules
  with their last and next runs, a schedule's recent runs, and `QueueHealth` with the heartbeat's age, what is
  waiting and what has failed.
- `ConfigProvider` and PSR-11 factories, configured under `contenir_queue`.
- Mago, PHPUnit unit and integration suites (SQLite, and MySQL in CI), Infection (MSI 100%) and Codecov in CI.
