<?php

declare(strict_types=1);

namespace Contenir\Queue\Tests\Unit\Console;

use Contenir\Queue\Console\ContainerLoader;
use Contenir\Queue\Exception\ConfigurationException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

use function dirname;

#[Group('unit')]
final class ContainerLoaderTest extends TestCase
{
    /**
     * @return array<string, array{list<string>, ?string, string}>
     */
    public static function fileProvider(): array
    {
        return [
            'the option wins'            => [['--max-time=5', '--container=/a/c.php'], '/env/c.php', '/a/c.php'],
            'then the environment'       => [['--max-time=5'], '/env/c.php', '/env/c.php'],
            'an empty environment'       => [[], '', '/site/config/container.php'],
            'then the working directory' => [[], null, '/site/config/container.php'],
            'a bare option is not one'   => [['--container'], null, '/site/config/container.php'],
        ];
    }

    private static function fixture(string $name): string
    {
        return dirname(__DIR__, levels: 2) . '/TestAsset/Console/' . $name;
    }

    /**
     * @param list<string> $args
     */
    #[Test]
    #[DataProvider('fileProvider')]
    public function findsTheContainerFile(array $args, ?string $env, string $expected): void
    {
        static::assertSame($expected, ContainerLoader::file($args, $env, '/site'));
    }

    #[Test]
    public function loadsTheContainerAFileReturns(): void
    {
        static::assertSame('loaded', ContainerLoader::load(self::fixture('container.php'))->get('marker'));
    }

    #[Test]
    public function refusesAFileThatReturnsSomethingElse(): void
    {
        $file = self::fixture('not-a-container.php');

        $this->expectException(ConfigurationException::class);
        $this->expectExceptionMessage("The container file \"{$file}\" does not return a PSR-11 container");

        ContainerLoader::load($file);
    }

    #[Test]
    public function refusesAMissingFile(): void
    {
        $this->expectException(ConfigurationException::class);
        $this->expectExceptionMessage('The container file "/no/such/file.php" does not exist');

        ContainerLoader::load('/no/such/file.php');
    }
}
