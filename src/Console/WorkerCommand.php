<?php

declare(strict_types=1);

namespace Contenir\Queue\Console;

use Closure;
use Contenir\Queue\Exception\StorageException;
use Contenir\Queue\Worker\StopReason;
use Contenir\Queue\Worker\Worker;
use Contenir\Queue\Worker\WorkerOptions;
use InvalidArgumentException;

use function array_filter;
use function array_map;
use function array_values;
use function explode;
use function preg_match;
use function sprintf;

/**
 * `bin/contenir-queue`: parses the command line, runs the worker and reports
 * what it did. Settings come from the `contenir_queue.worker` configuration;
 * options override them for this run.
 *
 * @api
 */
final readonly class WorkerCommand
{
    public const int OK    = 0;
    public const int ERROR = 1;
    public const int USAGE = 2;

    public const string HELP = <<<'TXT'
        Usage: contenir-queue [options]

        Runs due jobs. Cron runs it every minute; it stops when nothing is due, or
        after max-time seconds.

          --container=FILE    PHP file returning the PSR-11 container
                              (default: $CONTENIR_QUEUE_CONTAINER, else config/container.php)
          --queue=NAME[,NAME] Only take jobs from these queues (default: all)
          --max-time=SECONDS  Stop taking jobs after this long (default: 50)
          --daemon            Run until stopped, waiting for work, for a process supervisor
          --max-jobs=N        Stop after N jobs
          --sleep=SECONDS     With --daemon, wait this long when nothing is due (default: 5)
          --memory-limit=MB   Stop once the process uses this much memory (default: 128)
          --name=NAME         Worker name, for its lock and heartbeat (default: default)
          --help              Show this help

        TXT;

    /**
     * @param Closure(string): void $write Writes output.
     */
    public function __construct(
        private Worker $worker,
        private WorkerOptions $defaults,
        private Closure $write,
    ) {}

    /**
     * @throws InvalidArgumentException
     */
    private static function count(string $option, string $value): int
    {
        if (1 !== preg_match('/^\d+$/D', $value)) {
            throw new InvalidArgumentException("{$option} needs a whole number, not \"{$value}\"");
        }

        return (int) $value;
    }

    /**
     * @return list<string>
     */
    private static function queues(string $value): array
    {
        return array_values(array_filter(
            array_map(trim(...), explode(',', $value)),
            static fn(string $queue): bool => '' !== $queue,
        ));
    }

    /**
     * @param list<string> $args The arguments after the command's name.
     */
    public function run(array $args): int
    {
        try {
            $options = $this->options($args);
        } catch (InvalidArgumentException $e) {
            ($this->write)($e->getMessage() . "\n\n" . self::HELP);

            return self::USAGE;
        }

        if (null === $options) {
            ($this->write)(self::HELP);

            return self::OK;
        }

        try {
            $report = $this->worker->run($options);
        } catch (StorageException $e) {
            ($this->write)("{$e->getMessage()}\n");

            return self::ERROR;
        }

        ($this->write)(
            StopReason::AlreadyRunning === $report->reason
                ? "Worker {$options->name} is already running.\n"
                : sprintf(
                    "Worker %s stopped (%s): %d jobs run, %d stale jobs recovered, %d old jobs deleted.\n",
                    $options->name,
                    $report->reason->value,
                    $report->jobsRun,
                    $report->recovered,
                    $report->pruned,
                ),
        );

        return self::OK;
    }

    /**
     * The options for this run, or null when help was asked for.
     *
     * @param list<string> $args
     *
     * @throws InvalidArgumentException
     */
    private function options(array $args): ?WorkerOptions
    {
        $options = $this->defaults;
        foreach ($args as $arg) {
            $parts   = explode('=', $arg, limit: 2);
            $option  = $parts[0];
            $value   = $parts[1] ?? '';
            $options = match ($option) {
                '--help', '-h'   => null,
                '--container'    => $options,
                '--daemon'       => $options?->with(['maxSeconds' => 0]),
                '--queue'        => $options?->with(['queues' => self::queues($value)]),
                '--max-time'     => $options?->with(['maxSeconds' => self::count($option, $value)]),
                '--max-jobs'     => $options?->with(['maxJobs' => self::count($option, $value)]),
                '--sleep'        => $options?->with(['sleepSeconds' => self::count($option, $value)]),
                '--memory-limit' => $options?->with(['memoryLimitBytes' => self::count($option, $value) * 1024 * 1024]),
                '--name'         => $options?->with(['name' => $value]),
                default          => throw new InvalidArgumentException("Unknown option \"{$arg}\""),
            };
        }

        return $options;
    }
}
