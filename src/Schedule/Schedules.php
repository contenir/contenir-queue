<?php

declare(strict_types=1);

namespace Contenir\Queue\Schedule;

use InvalidArgumentException;

use function array_key_exists;
use function array_values;

/**
 * The schedules a site runs. The host adds them as it boots: its own, its
 * add-ons' declarations, and any from configuration.
 *
 * @api
 */
final class Schedules
{
    /** @var array<string, Schedule> */
    private array $schedules = [];

    /**
     * @throws InvalidArgumentException When a schedule of that name is already added.
     */
    public function add(Schedule $schedule): void
    {
        if (array_key_exists($schedule->name, $this->schedules)) {
            throw new InvalidArgumentException("There is already a schedule named \"{$schedule->name}\"");
        }

        $this->schedules[$schedule->name] = $schedule;
    }

    /**
     * @return list<Schedule>
     */
    public function all(): array
    {
        return array_values($this->schedules);
    }

    public function get(string $name): ?Schedule
    {
        return $this->schedules[$name] ?? null;
    }
}
