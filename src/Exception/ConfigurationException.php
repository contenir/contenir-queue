<?php

declare(strict_types=1);

namespace Contenir\Queue\Exception;

use UnexpectedValueException;

/**
 * A `contenir_queue` setting, or a container entry it names, is not what the
 * queue needs.
 *
 * @api
 */
final class ConfigurationException extends UnexpectedValueException
{
    public static function invalidService(string $name, string $type): self
    {
        return new self("The container entry \"{$name}\" is not a {$type}");
    }

    public static function invalidSetting(string $path, string $expected): self
    {
        return new self("The setting \"{$path}\" must be {$expected}");
    }

    public static function noContainer(string $file, string $problem): self
    {
        return new self("The container file \"{$file}\" {$problem}");
    }
}
