<?php

declare(strict_types=1);

namespace Contenir\Queue\Tests\TestAsset\Database;

use Override;
use PhpDb\Adapter\Driver\Pdo\Statement;
use PhpDb\Adapter\Driver\ResultInterface;
use PhpDb\Adapter\ParameterContainer;

/**
 * A statement whose execution yields no result, as a misbehaving driver might.
 */
final class NullResultStatement extends Statement
{
    #[Override]
    public function execute(ParameterContainer|array|null $parameters = null): ?ResultInterface
    {
        return null;
    }
}
