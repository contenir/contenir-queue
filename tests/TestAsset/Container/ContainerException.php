<?php

declare(strict_types=1);

namespace Contenir\Queue\Tests\TestAsset\Container;

use Psr\Container\ContainerExceptionInterface;
use RuntimeException;

final class ContainerException extends RuntimeException implements ContainerExceptionInterface {}
