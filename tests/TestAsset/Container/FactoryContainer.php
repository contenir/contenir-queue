<?php

declare(strict_types=1);

namespace Contenir\Queue\Tests\TestAsset\Container;

use Contenir\Queue\ConfigProvider;
use Override;
use Psr\Container\ContainerInterface;

use function array_key_exists;
use function is_callable;

/**
 * A PSR-11 container that builds services from ConfigProvider's aliases and
 * factories, as laminas-servicemanager would, plus fixed entries a test
 * supplies (the adapter, a clock, handlers).
 */
final class FactoryContainer implements ContainerInterface
{
    /** @var array<string, string> */
    private array $aliases;

    /** @var array<string, class-string> */
    private array $factories;

    /**
     * @param array<string, mixed> $entries
     * @param array<string, mixed> $settings The `contenir_queue` configuration.
     */
    public function __construct(
        private array $entries,
        array $settings = [],
    ) {
        $provider                = (new ConfigProvider())();
        $dependencies            = (new ConfigProvider())->getDependencies();
        $this->aliases           = $dependencies['aliases'];
        $this->factories         = $dependencies['factories'];
        $this->entries['config'] = [
            ...$provider,
            'contenir_queue' => [...((array) $provider['contenir_queue']), ...$settings],
        ];
    }

    #[Override]
    public function get(string $id): mixed
    {
        if (array_key_exists($id, $this->entries)) {
            return $this->entries[$id];
        }

        if (array_key_exists($id, $this->aliases)) {
            return $this->entries[$id] = $this->get($this->aliases[$id]);
        }

        if (array_key_exists($id, $this->factories)) {
            $factory = new $this->factories[$id]();
            if (is_callable($factory)) {
                return $this->entries[$id] = $factory($this);
            }
        }

        throw new NotFoundException("No entry \"{$id}\"");
    }

    #[Override]
    public function has(string $id): bool
    {
        return (
            array_key_exists($id, $this->entries)
                || array_key_exists($id, $this->aliases)
                || array_key_exists($id, $this->factories)
        );
    }
}
