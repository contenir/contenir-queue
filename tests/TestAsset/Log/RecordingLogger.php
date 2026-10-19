<?php

declare(strict_types=1);

namespace Contenir\Queue\Tests\TestAsset\Log;

use Override;
use Psr\Log\AbstractLogger;
use Stringable;

use function is_scalar;
use function strtr;

/**
 * Keeps every log entry, with its placeholders filled in, for assertions.
 */
final class RecordingLogger extends AbstractLogger
{
    /** @var list<string> */
    public array $lines = [];

    /**
     * Untyped `$message`, so this also implements psr/log 1, whose interface
     * declares no type.
     *
     * @param string|Stringable $message
     * @param array<array-key, mixed> $context
     */
    #[Override]
    public function log($level, $message, array $context = []): void
    {
        $replace = [];
        foreach ($context as $key => $value) {
            $replace["{{$key}}"] = is_scalar($value) ? (string) $value : '?';
        }

        $this->lines[] = (string) $level . ': ' . strtr((string) $message, $replace);
    }
}
