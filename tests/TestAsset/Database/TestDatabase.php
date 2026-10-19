<?php

declare(strict_types=1);

namespace Contenir\Queue\Tests\TestAsset\Database;

use Contenir\Queue\ConfigProvider;
use PDO;
use PDOException;
use PhpDb\Adapter\Adapter;
use PhpDb\Adapter\AdapterInterface;
use PhpDb\Metadata\MetadataInterface;
use PhpDb\Migration\MigrationRunner;
use PhpDb\Migration\MismatchStrategy;
use PhpDb\Mysql;
use PhpDb\Sqlite;
use PHPUnit\Framework\SkippedWithMessageException;

use function getenv;
use function is_string;
use function sprintf;

/**
 * The database integration tests run against: SQLite in memory by default,
 * or MySQL when CONTENIR_TEST_DB_PLATFORM is "mysql" (connection settings in
 * CONTENIR_TEST_DB_HOST, _PORT, _NAME, _USER and _PASSWORD).
 */
final class TestDatabase
{
    /**
     * Every table the queue's migrations create, plus the runner's own.
     */
    public const array TABLES = ['queue_job', 'queue_worker', 'migrations'];

    public static function connect(): AdapterInterface
    {
        return self::env('PLATFORM', 'sqlite') === 'mysql' ? self::mysql() : self::sqlite();
    }

    public static function drop(AdapterInterface $adapter): void
    {
        foreach (self::TABLES as $table) {
            $adapter->query("DROP TABLE IF EXISTS {$table}", Adapter::QUERY_MODE_EXECUTE);
        }
    }

    public static function metadata(AdapterInterface $adapter): MetadataInterface
    {
        return $adapter->getPlatform()->getName() === 'MySQL'
            ? new Mysql\Metadata\Source($adapter)
            : new Sqlite\Metadata\Source($adapter);
    }

    public static function migrations(AdapterInterface $adapter): MigrationRunner
    {
        return new MigrationRunner(
            $adapter,
            ConfigProvider::schemaPath(),
            ConfigProvider::SCHEMA_NAMESPACE,
            MismatchStrategy::Report,
            self::metadata($adapter),
        );
    }

    /**
     * SQLite in a file, for tests that hand the database to another process.
     */
    public static function sqliteFile(string $file): AdapterInterface
    {
        return self::sqlite($file);
    }

    private static function env(string $name, string $default): string
    {
        $value = getenv("CONTENIR_TEST_DB_{$name}");

        return is_string($value) && '' !== $value ? $value : $default;
    }

    private static function mysql(): AdapterInterface
    {
        try {
            $pdo = new PDO(
                sprintf(
                    'mysql:host=%s;port=%s;dbname=%s;charset=utf8mb4',
                    self::env('HOST', '127.0.0.1'),
                    self::env('PORT', '3306'),
                    self::env('NAME', 'contenir_queue_test'),
                ),
                self::env('USER', 'contenir'),
                self::env('PASSWORD', 'contenir'),
            );
        } catch (PDOException $e) {
            throw new SkippedWithMessageException("MySQL is not reachable: {$e->getMessage()}");
        }

        $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $driver = new Mysql\Pdo\Driver(new Mysql\Pdo\Connection($pdo));

        return new Adapter($driver, new Mysql\AdapterPlatform($driver));
    }

    private static function sqlite(string $file = ':memory:'): AdapterInterface
    {
        $pdo = new PDO("sqlite:{$file}");
        $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $driver = new Sqlite\Pdo\Driver(connection: new Sqlite\Pdo\Connection($pdo));

        return new Adapter($driver, new Sqlite\AdapterPlatform($driver));
    }
}
