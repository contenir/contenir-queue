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
- `ConfigProvider` and PSR-11 factories, configured under `contenir_queue`.
- Mago, PHPUnit unit and integration suites (SQLite, and MySQL in CI), Infection (MSI 100%) and Codecov in CI.
