<?php

declare(strict_types=1);

namespace Contenir\Queue\Tests\TestAsset\Container;

use Override;
use Psr\Container\ContainerInterface;

use function array_key_exists;

/**
 * A PSR-11 container over a fixed array of entries.
 */
final readonly class ArrayContainer implements ContainerInterface
{
    /**
     * @param array<string, mixed> $entries
     */
    public function __construct(
        private array $entries = [],
    ) {}

    #[Override]
    public function get(string $id): mixed
    {
        if (! array_key_exists($id, $this->entries)) {
            throw new NotFoundException("No entry \"{$id}\"");
        }

        return $this->entries[$id];
    }

    #[Override]
    public function has(string $id): bool
    {
        return array_key_exists($id, $this->entries);
    }
}
