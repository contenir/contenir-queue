<?php

declare(strict_types=1);

namespace Contenir\Queue\Storage;

use Contenir\Queue\Exception\StorageException;
use Contenir\Queue\Job\Job;
use Contenir\Queue\Job\JobState;
use DateTimeImmutable;
use Override;
use PhpDb\Adapter\AdapterInterface;
use PhpDb\Sql\Expression;
use PhpDb\Sql\Where;

/**
 * Jobs in the `queue_job` table, through php-db, on MySQL or SQLite.
 *
 * @api
 */
final readonly class PhpDbJobStore implements JobStoreInterface
{
    public const string TABLE = 'queue_job';

    /**
     * How many due jobs a claim looks at before giving up for this turn: if
     * other workers take all of them first, the next turn tries again.
     */
    private const int CLAIM_CANDIDATES = 10;

    private Database $db;

    public function __construct(AdapterInterface $adapter)
    {
        $this->db = new Database($adapter);
    }

    /**
     * @throws StorageException
     */
    private static function hydrate(Row $row): Job
    {
        return new Job(
            id: $row->int('id'),
            queue: $row->string('queue'),
            jobClass: $row->string('job_class'),
            encodedPayload: $row->string('payload'),
            state: JobState::from($row->string('state')),
            attempts: $row->int('attempts'),
            nextAttemptAt: $row->time('next_attempt_at'),
            lockedBy: $row->nullableString('locked_by'),
            lockedAt: $row->nullableTime('locked_at'),
            lastError: $row->nullableString('last_error'),
            createdAt: $row->time('created_at'),
            updatedAt: $row->time('updated_at'),
            finishedAt: $row->nullableTime('finished_at'),
        );
    }

    #[Override]
    public function claimNext(array $queues, string $lockedBy, DateTimeImmutable $now): ?Job
    {
        $at     = DbTime::format($now);
        $select = $this->db
            ->sql
            ->select(self::TABLE)
            ->columns(['id'])
            ->where(static function (Where $where) use ($at, $queues): void {
                $where->equalTo('state', JobState::Waiting->value);
                $where->lessThanOrEqualTo('next_attempt_at', $at);
                if ([] !== $queues) {
                    $where->in('queue', $queues);
                }
            })
            ->order(['next_attempt_at ASC', 'id ASC'])
            ->limit(self::CLAIM_CANDIDATES);

        foreach ($this->db->rows($select) as $candidate) {
            $id     = $candidate->int('id');
            $update = $this->db
                ->sql
                ->update(self::TABLE)
                ->set([
                    'state'      => JobState::Running->value,
                    'attempts'   => new Expression('attempts + 1'),
                    'locked_by'  => $lockedBy,
                    'locked_at'  => $at,
                    'updated_at' => $at,
                ])
                ->where(['id' => $id, 'state' => JobState::Waiting->value]);

            if ($this->db->affectedRows($update) === 1) {
                return $this->find($id);
            }
        }

        return null;
    }

    #[Override]
    public function complete(Job $job, DateTimeImmutable $now): bool
    {
        return $this->finish(
            $job,
            [
                'state'       => JobState::Done->value,
                'finished_at' => DbTime::format($now),
            ],
            $now,
        );
    }

    #[Override]
    public function fail(Job $job, string $error, DateTimeImmutable $now): bool
    {
        return $this->finish(
            $job,
            [
                'state'       => JobState::Failed->value,
                'last_error'  => $error,
                'finished_at' => DbTime::format($now),
            ],
            $now,
        );
    }

    #[Override]
    public function find(int $id): ?Job
    {
        $row = $this->db->row($this->db->sql->select(self::TABLE)->where(['id' => $id]));

        return null === $row ? null : self::hydrate($row);
    }

    #[Override]
    public function push(
        string $queue,
        string $jobClass,
        string $encodedPayload,
        DateTimeImmutable $nextAttemptAt,
        DateTimeImmutable $now,
    ): int {
        $at = DbTime::format($now);

        return $this->db->insert($this->db
            ->sql
            ->insert(self::TABLE)
            ->values([
                'queue'           => $queue,
                'job_class'       => $jobClass,
                'payload'         => $encodedPayload,
                'state'           => JobState::Waiting->value,
                'attempts'        => 0,
                'next_attempt_at' => DbTime::format($nextAttemptAt),
                'created_at'      => $at,
                'updated_at'      => $at,
            ]));
    }

    #[Override]
    public function retry(Job $job, DateTimeImmutable $nextAttemptAt, string $error, DateTimeImmutable $now): bool
    {
        return $this->finish(
            $job,
            [
                'state'           => JobState::Waiting->value,
                'next_attempt_at' => DbTime::format($nextAttemptAt),
                'last_error'      => $error,
            ],
            $now,
        );
    }

    /**
     * Update a running job and release its lock, but only while the lock is
     * still the one it was claimed with.
     *
     * @param array<string, string> $values
     *
     * @throws StorageException
     */
    private function finish(Job $job, array $values, DateTimeImmutable $now): bool
    {
        $update = $this->db
            ->sql
            ->update(self::TABLE)
            ->set([
                ...$values,
                'locked_by'  => null,
                'locked_at'  => null,
                'updated_at' => DbTime::format($now),
            ])
            ->where([
                'id'        => $job->id,
                'state'     => JobState::Running->value,
                'locked_by' => $job->lockedBy,
            ]);

        return $this->db->affectedRows($update) === 1;
    }
}
