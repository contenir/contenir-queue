<?php

declare(strict_types=1);

namespace Contenir\Queue\Storage;

use Contenir\Queue\Exception\StorageException;
use PDOException;
use PhpDb\Adapter\AdapterInterface;
use PhpDb\Adapter\Driver\ResultInterface;
use PhpDb\Exception\ExceptionInterface;
use PhpDb\Sql\Insert;
use PhpDb\Sql\PreparableSqlInterface;
use PhpDb\Sql\Select;
use PhpDb\Sql\Sql;

/**
 * Runs php-db statements for the stores, so that every database failure
 * reaches them as a {@see StorageException}.
 *
 * @internal
 */
final readonly class Database
{
    public Sql $sql;

    public function __construct(AdapterInterface $adapter)
    {
        $this->sql = new Sql($adapter);
    }

    /**
     * @throws StorageException
     */
    public function affectedRows(PreparableSqlInterface $statement): int
    {
        return $this->execute($statement)->getAffectedRows();
    }

    /**
     * Run an insert and return the id the database generated.
     *
     * @throws StorageException
     */
    public function insert(Insert $insert): int
    {
        return (int) $this->execute($insert)->getGeneratedValue();
    }

    /**
     * The first row a select returns, if any.
     *
     * @throws StorageException
     */
    public function row(Select $select): ?Row
    {
        return $this->rows($select)[0] ?? null;
    }

    /**
     * @return list<Row>
     *
     * @throws StorageException
     *
     * @mago-expect analysis:mixed-assignment Result rows are untyped; Row checks each value it reads.
     */
    public function rows(Select $select): array
    {
        $rows = [];
        foreach ($this->execute($select) as $values) {
            $rows[] = Row::from($values);
        }

        return $rows;
    }

    /**
     * @throws StorageException
     */
    private function execute(PreparableSqlInterface $statement): ResultInterface
    {
        try {
            $result = $this->sql->prepareStatementForSqlObject($statement)->execute();
        } catch (ExceptionInterface|PDOException $e) {
            throw StorageException::failed($e);
        }

        return $result ?? throw StorageException::noResult();
    }
}
