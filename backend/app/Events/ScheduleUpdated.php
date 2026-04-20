<?php

namespace App\Events;

use App\Models\WorkingSchedule;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

/**
 * Fired when an admin working schedule template changes or roster assignment changes.
 *
 * `$schedule` is null when the template row was deleted ({@see ScheduleController::destroy()}) so queued
 * listeners do not fail model resolution after the DB row is gone.
 *
 * @phpstan-type ScheduleContext 'updated'|'assigned'|'destroyed'
 */
class ScheduleUpdated
{
    use Dispatchable, SerializesModels;

    /**
     * @param  list<int>  $affectedUserIds
     */
    public function __construct(
        public ?WorkingSchedule $schedule,
        public array $affectedUserIds,
        public ?string $context = null,
    ) {}
}
