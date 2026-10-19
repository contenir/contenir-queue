<?php

declare(strict_types=1);

namespace Contenir\Queue\Worker;

use function bin2hex;
use function gethostname;
use function getmypid;
use function random_bytes;
use function substr;

/**
 * Who a worker is: the host and process it runs in, and a lock id unique
 * to this run, which it locks jobs and its worker row with.
 *
 * @api
 */
final readonly class WorkerIdentity
{
    /**
     * Longest host name kept in a lock id, so the id fits its 64-character
     * columns.
     */
    public const int LOCK_HOST_LENGTH = 40;

    public function __construct(
        public string $host,
        public int $pid,
        public string $lock,
    ) {}

    /**
     * This process, with a fresh lock id.
     *
     * @mago-expect analysis:unhandled-thrown-type random_bytes() only fails when the system has no randomness.
     */
    public static function current(): self
    {
        return self::of((string) gethostname(), (int) getmypid(), bin2hex(random_bytes(4)));
    }

    /**
     * A worker on the given host and process, whose lock id ends with the
     * given run id.
     */
    public static function of(string $host, int $pid, string $run): self
    {
        return new self($host, $pid, substr($host, offset: 0, length: self::LOCK_HOST_LENGTH) . ":{$pid}:{$run}");
    }
}
