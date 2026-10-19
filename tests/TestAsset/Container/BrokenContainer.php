<?php

declare(strict_types=1);

namespace Contenir\Queue\Tests\TestAsset\Container;

use Override;
use Psr\Container\ContainerInterface;

/**
 * A container that has every entry but fails to build any of them, as when a
 * factory needs a database that is down.
 */
final class BrokenContainer implements ContainerInterface
{
    #[Override]
    public function get(string $id): mixed
    {
        throw new ContainerException("Could not build \"{$id}\"");
    }

    #[Override]
    public function has(string $id): bool
    {
        return true;
    }
}
