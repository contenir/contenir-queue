<?php

declare(strict_types=1);

namespace Contenir\Queue\Storage;

use Contenir\Queue\Exception\StorageException;
use Contenir\Queue\Worker\WorkerIdentity;
use Contenir\Queue\Worker\WorkerStatus;
use DateTimeImmutable;
use Override;
use PhpDb\Adapter\AdapterInterface;
use PhpDb\Sql\Predicate\IsNull;
use PhpDb\Sql\Predicate\Operator;
use PhpDb\Sql\Predicate\PredicateSet;

/**
 * Worker locks and heartbeats in the `queue_worker` table.
 *
 * MySQL counts only rows an UPDATE changes, so a renewal in the same second
 * can report none; each change is read back instead of trusting the count.
 *
 * @api
 */
final readonly class PhpDbWorkerStore implements WorkerStoreInterface
{
    public const string TABLE = 'queue_worker';

    private Database $db;

    public function __construct(AdapterInterface $adapter)
    {
        $this->db = new Database($adapter);
    }

    #[Override]
    public function acquire(
        string $name,
        WorkerIdentity $worker,
        DateTimeImmutable $lockedUntil,
        DateTimeImmutable $now,
    ): bool {
        $at     = DbTime::format($now);
        $values = [
            'owner'        => $worker->lock,
            'locked_until' => DbTime::format($lockedUntil),
            'heartbeat_at' => $at,
            'started_at'   => $at,
            'host'         => $worker->host,
            'pid'          => $worker->pid,
        ];

        return null === $this->find($name)
            ? $this->insert($name, $values)
            : $this->takeOver($name, $values, $at);
    }

    #[Override]
    public function find(string $name): ?WorkerStatus
    {
        $row = $this->db->row($this->db->sql->select(self::TABLE)->where(['name' => $name]));

        return null === $row
            ? null
            : new WorkerStatus(
                name: $row->string('name'),
                owner: $row->nullableString('owner'),
                lockedUntil: $row->nullableTime('locked_until'),
                heartbeatAt: $row->nullableTime('heartbeat_at'),
                startedAt: $row->nullableTime('started_at'),
                host: $row->nullableString('host'),
                pid: $row->nullableInt('pid'),
            );
    }

    #[Override]
    public function release(string $name, string $owner, DateTimeImmutable $now): void
    {
        $this->db->affectedRows(
            $this->db
                ->sql
                ->update(self::TABLE)
                ->set(['owner' => null, 'locked_until' => null, 'heartbeat_at' => DbTime::format($now)])
                ->where(['name' => $name, 'owner' => $owner]),
        );
    }

    #[Override]
    public function renew(string $name, string $owner, DateTimeImmutable $lockedUntil, DateTimeImmutable $now): bool
    {
        $this->db->affectedRows(
            $this->db
                ->sql
                ->update(self::TABLE)
                ->set(['locked_until' => DbTime::format($lockedUntil), 'heartbeat_at' => DbTime::format($now)])
                ->where(['name' => $name, 'owner' => $owner]),
        );

        return $this->find($name)?->owner === $owner;
    }

    /**
     * Create the worker's row with the lock taken. False if another worker
     * created it first.
     *
     * @param array<string, int|string> $values
     *
     * @throws StorageException
     */
    private function insert(string $name, array $values): bool
    {
        try {
            $this->db->insert($this->db->sql->insert(self::TABLE)->values(['name' => $name, ...$values]));
        } catch (StorageException) {
            return false;
        }

        return true;
    }

    /**
     * Take the lock if no one holds it or it has run out, and say whether this
     * worker now holds it.
     *
     * @param array{owner: string, locked_until: string, heartbeat_at: string, started_at: string, host: string, pid: int} $values
     *
     * @throws StorageException
     */
    private function takeOver(string $name, array $values, string $at): bool
    {
        $this->db->affectedRows(
            $this->db
                ->sql
                ->update(self::TABLE)
                ->set($values)
                ->where([
                    'name' => $name,
                    new PredicateSet(
                        [new IsNull('owner'), new Operator('locked_until', Operator::OPERATOR_LESS_THAN, $at)],
                        PredicateSet::OP_OR,
                    ),
                ]),
        );

        return $this->find($name)?->owner === $values['owner'];
    }
}
