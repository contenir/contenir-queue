<?php

declare(strict_types=1);

namespace Contenir\Queue\Tests\Integration\Storage;

use Closure;
use Contenir\Queue\Exception\StorageException;
use Contenir\Queue\Storage\Database;
use Contenir\Queue\Tests\TestAsset\Database\NullResultStatement;
use PDO;
use PDOException;
use PhpDb\Adapter\Adapter;
use PhpDb\Adapter\Exception\InvalidQueryException;
use PhpDb\Sqlite\AdapterPlatform;
use PhpDb\Sqlite\Pdo\Connection;
use PhpDb\Sqlite\Pdo\Driver;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * How database failures reach the stores. A plain in-memory SQLite database
 * with one table: these cases do not depend on the platform.
 */
#[Group('integration')]
final class DatabaseTest extends TestCase
{
    private static function adapter(?NullResultStatement $statement = null): Adapter
    {
        $pdo = new PDO('sqlite::memory:');
        $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $pdo->exec('CREATE TABLE t (id INTEGER PRIMARY KEY AUTOINCREMENT, name TEXT)');
        $pdo->exec("INSERT INTO t (name) VALUES ('a'), ('b')");
        $driver = null === $statement
            ? new Driver(connection: new Connection($pdo))
            : new Driver(
                connection: new Connection($pdo),
                statementPrototype: $statement,
            );

        return new Adapter($driver, new AdapterPlatform($driver));
    }

    /**
     * The class of the database error a StorageException carries.
     *
     * @param Closure(): void $statement
     */
    private static function failure(Closure $statement): string
    {
        try {
            $statement();
        } catch (StorageException $e) {
            static::assertStringStartsWith('The queue database failed: ', $e->getMessage());

            return $e->getPrevious()::class;
        }

        static::fail('The statement did not fail');
    }

    #[Test]
    public function affectedRowsCountsTheChange(): void
    {
        $db = new Database(self::adapter());

        static::assertSame(2, $db->affectedRows($db->sql->update('t')->set(['name' => 'z'])));
    }

    #[Test]
    public function aStatementThatCannotBePreparedIsAStorageFailure(): void
    {
        $db = new Database(self::adapter());

        static::assertSame(PDOException::class, self::failure(static function () use ($db): void {
            $db->rows($db->sql->select('missing_table'));
        }));
    }

    #[Test]
    public function aStatementTheDatabaseRefusesIsAStorageFailure(): void
    {
        $db = new Database(self::adapter());

        static::assertSame(InvalidQueryException::class, self::failure(static function () use ($db): void {
            $db->insert($db->sql->insert('t')->values(['id' => 1, 'name' => 'dup']));
        }));
    }

    #[Test]
    public function insertReturnsTheGeneratedId(): void
    {
        $db = new Database(self::adapter());

        static::assertSame(3, $db->insert($db->sql->insert('t')->values(['name' => 'c'])));
    }

    #[Test]
    public function noResultFromTheDriverIsAStorageFailure(): void
    {
        $db = new Database(self::adapter(new NullResultStatement()));

        $this->expectException(StorageException::class);
        $this->expectExceptionMessage('The database driver returned no result');

        $db->affectedRows($db->sql->update('t')->set(['name' => 'z']));
    }

    #[Test]
    public function readsRowsInOrder(): void
    {
        $db = new Database(self::adapter());

        static::assertSame(['a', 'b'], [
            $db->rows($db->sql->select('t')->order('id'))[0]->string('name'),
            $db->rows($db->sql->select('t')->order('id'))[1]->string('name'),
        ]);
    }

    #[Test]
    public function rowIsTheFirstOrNull(): void
    {
        $db = new Database(self::adapter());

        static::assertSame(['a', null], [
            $db->row($db->sql->select('t')->order('id'))?->string('name'),
            $db->row($db->sql->select('t')->where(['id' => 99])),
        ]);
    }
}
