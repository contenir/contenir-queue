<?php

declare(strict_types=1);

namespace Contenir\Queue\Storage;

use Contenir\Queue\Exception\StorageException;
use Contenir\Queue\Job\JobState;
use Contenir\Queue\Schedule\ScheduleRun;
use Contenir\Queue\Schedule\ScheduleState;
use DateTimeImmutable;
use Override;
use PhpDb\Adapter\AdapterInterface;
use PhpDb\Sql\Select;
use PhpDb\Sql\Where;

/**
 * Schedules in the `queue_schedule` table and their runs in
 * `queue_schedule_run`, each joined to its job in `queue_job` for the job's
 * state.
 *
 * @api
 */
final readonly class PhpDbScheduleStore implements ScheduleStoreInterface
{
    public const string TABLE     = 'queue_schedule';
    public const string RUN_TABLE = 'queue_schedule_run';

    private Database $db;

    public function __construct(AdapterInterface $adapter)
    {
        $this->db = new Database($adapter);
    }

    /**
     * @throws StorageException
     */
    private static function jobState(Row $row, string $column = 'job_state'): ?JobState
    {
        $state = $row->nullableString($column);

        return null === $state ? null : JobState::from($state);
    }

    #[Override]
    public function advance(string $name, DateTimeImmutable $due, DateTimeImmutable $next, DateTimeImmutable $now): bool
    {
        $update = $this->db
            ->sql
            ->update(self::TABLE)
            ->set([
                'next_run_at' => DbTime::format($next),
                'last_run_at' => DbTime::format($due),
                'updated_at'  => DbTime::format($now),
            ])
            ->where(['name' => $name, 'next_run_at' => DbTime::format($due)]);

        return 1 === $this->db->affectedRows($update);
    }

    #[Override]
    public function pruneRuns(DateTimeImmutable $before): int
    {
        $at = DbTime::format($before);

        return $this->db->affectedRows(
            $this->db
                ->sql
                ->delete(self::RUN_TABLE)
                ->where(static function (Where $where) use ($at): void {
                    $where->lessThan('recorded_at', $at);
                }),
        );
    }

    #[Override]
    public function recordRun(string $name, DateTimeImmutable $due, ?int $jobId, DateTimeImmutable $now): void
    {
        $at = DbTime::format($now);
        $this->db->insert($this->db
            ->sql
            ->insert(self::RUN_TABLE)
            ->values([
                'schedule'    => $name,
                'due_at'      => DbTime::format($due),
                'recorded_at' => $at,
                'job_id'      => $jobId,
            ]));

        if (null !== $jobId) {
            $this->db->affectedRows(
                $this->db
                    ->sql
                    ->update(self::TABLE)
                    ->set(['last_job_id' => $jobId, 'updated_at' => $at])
                    ->where(['name' => $name]),
            );
        }
    }

    #[Override]
    public function runs(string $name, int $limit): array
    {
        $select = $this->db
            ->sql
            ->select()
            ->from(['r' => self::RUN_TABLE])
            ->join(['j' => PhpDbJobStore::TABLE], 'j.id = r.job_id', ['job_state' => 'state'], Select::JOIN_LEFT)
            ->where(['r.schedule' => $name])
            ->order(['r.due_at DESC', 'r.id DESC'])
            ->limit($limit);

        $runs = [];
        foreach ($this->db->rows($select) as $row) {
            $runs[] = new ScheduleRun(
                id: $row->int('id'),
                schedule: $row->string('schedule'),
                dueAt: $row->time('due_at'),
                recordedAt: $row->time('recorded_at'),
                jobId: $row->nullableInt('job_id'),
                jobState: self::jobState($row),
            );
        }

        return $runs;
    }

    #[Override]
    public function start(string $name, DateTimeImmutable $nextRunAt, DateTimeImmutable $now): bool
    {
        try {
            $this->db->insert($this->db
                ->sql
                ->insert(self::TABLE)
                ->values([
                    'name'        => $name,
                    'next_run_at' => DbTime::format($nextRunAt),
                    'updated_at'  => DbTime::format($now),
                ]));
        } catch (StorageException) {
            return false;
        }

        return true;
    }

    #[Override]
    public function states(): array
    {
        $select = $this->db
            ->sql
            ->select()
            ->from(['s' => self::TABLE])
            ->join(
                ['j' => PhpDbJobStore::TABLE],
                'j.id = s.last_job_id',
                ['last_job_state' => 'state'],
                Select::JOIN_LEFT,
            );

        $states = [];
        foreach ($this->db->rows($select) as $row) {
            $name          = $row->string('name');
            $states[$name] = new ScheduleState(
                name: $name,
                nextRunAt: $row->time('next_run_at'),
                lastRunAt: $row->nullableTime('last_run_at'),
                lastJobId: $row->nullableInt('last_job_id'),
                lastJobState: self::jobState($row, 'last_job_state'),
            );
        }

        return $states;
    }
}
