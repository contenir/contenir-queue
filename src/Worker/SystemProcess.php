<?php

declare(strict_types=1);

namespace Contenir\Queue\Worker;

use Override;

use function max;
use function memory_get_usage;
use function sleep;

/**
 * This PHP process. A signal ends a sleep early, so a worker under a
 * supervisor stops promptly.
 *
 * @api
 */
final class SystemProcess implements ProcessInterface
{
    #[Override]
    public function memoryUsage(): int
    {
        return memory_get_usage(real_usage: true);
    }

    #[Override]
    public function sleep(int $seconds): void
    {
        sleep(max(0, $seconds));
    }
}
