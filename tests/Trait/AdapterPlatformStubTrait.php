<?php

declare(strict_types=1);

namespace Contenir\Queue\Tests\Trait;

use PhpDb\Adapter\AdapterInterface;
use PhpDb\Adapter\Platform\PlatformInterface;

/**
 * Stub database adapters that only know their platform's name, for code that
 * picks an SQL dialect without running anything.
 */
trait AdapterPlatformStubTrait
{
    private function adapterNamed(string $platform): AdapterInterface
    {
        $platformStub = $this->createStub(PlatformInterface::class);
        $platformStub->method('getName')->willReturn($platform);
        $adapter = $this->createStub(AdapterInterface::class);
        $adapter->method('getPlatform')->willReturn($platformStub);

        return $adapter;
    }
}
