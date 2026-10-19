<?php

declare(strict_types=1);

namespace Contenir\Queue\Tests\TestAsset\Job;

use Closure;
use Contenir\Queue\Job\Job;
use Contenir\Queue\Job\JobHandlerInterface;
use Override;

/**
 * A handler that runs a closure and remembers the jobs it was given.
 */
final class CallbackHandler implements JobHandlerInterface
{
    /** @var list<Job> */
    public array $handled = [];

    /**
     * @param Closure(Job): void $callback
     */
    public function __construct(
        private readonly Closure $callback,
    ) {}

    public static function succeeding(): self
    {
        return new self(static function (Job $job): void {});
    }

    #[Override]
    public function handle(Job $job): void
    {
        $this->handled[] = $job;
        ($this->callback)($job);
    }
}
