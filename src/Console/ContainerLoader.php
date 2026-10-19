<?php

declare(strict_types=1);

namespace Contenir\Queue\Console;

use Contenir\Queue\Exception\ConfigurationException;
use Psr\Container\ContainerInterface;

use function is_file;
use function str_starts_with;
use function strlen;
use function substr;

/**
 * Finds and loads the host's PSR-11 container for the command line: a PHP
 * file that returns it, as Mezzio's config/container.php does.
 *
 * The file is `--container=FILE` if given, else the CONTENIR_QUEUE_CONTAINER
 * environment variable, else config/container.php under the working
 * directory.
 *
 * @api
 */
final class ContainerLoader
{
    public const string OPTION       = '--container=';
    public const string ENV          = 'CONTENIR_QUEUE_CONTAINER';
    public const string DEFAULT_FILE = 'config/container.php';

    /**
     * @param list<string> $args The command's arguments.
     */
    public static function file(array $args, ?string $env, string $cwd): string
    {
        foreach ($args as $arg) {
            if (str_starts_with($arg, self::OPTION)) {
                return substr($arg, strlen(self::OPTION));
            }
        }

        return null !== $env && '' !== $env ? $env : "{$cwd}/" . self::DEFAULT_FILE;
    }

    /**
     * @throws ConfigurationException When the file is missing or returns no container.
     *
     * @mago-expect analysis:mixed-assignment An included file returns anything; the type is checked here.
     */
    public static function load(string $file): ContainerInterface
    {
        if (! is_file($file)) {
            throw ConfigurationException::noContainer($file, 'does not exist');
        }

        $container = require $file;

        return $container instanceof ContainerInterface
            ? $container
            : throw ConfigurationException::noContainer($file, 'does not return a PSR-11 container');
    }
}
