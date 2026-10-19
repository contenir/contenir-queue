<?php

declare(strict_types=1);

namespace Contenir\Queue\Tests\TestAsset\Worker;

use Closure;
use Contenir\Queue\Tests\TestAsset\Clock\MutableClock;
use Contenir\Queue\Worker\ProcessInterface;
use Override;

/**
 * A process whose sleep moves a test clock on, and whose memory use a test
 * sets.
 */
final class FakeProcess implements ProcessInterface
{
    /** @var list<int> */
    public array $sleeps = [];

    /**
     * @param ?Closure(): void $onSleep Runs after each sleep, to change things mid-run.
     */
    public function __construct(
        private readonly MutableClock $clock,
        public int $memory = 0,
        private readonly ?Closure $onSleep = null,
    ) {}

    #[Override]
    public function memoryUsage(): int
    {
        return $this->memory;
    }

    #[Override]
    public function sleep(int $seconds): void
    {
        $this->sleeps[] = $seconds;
        $this->clock->advance($seconds);
        if (null !== $this->onSleep) {
            ($this->onSleep)();
        }
    }
}
