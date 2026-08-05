<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class WorkingScheduleDayOption extends Model
{
    protected $fillable = [
        'working_schedule_day_id',
        'option_name',
        'time_in',
        'time_out',
        'break_start',
        'break_end',
        'break_minutes',
        'expected_paid_minutes',
        'grace_period_minutes',
        'early_timein_minutes',
        'overtime_buffer_minutes',
        'half_day_threshold_minutes',
        'crosses_midnight',
        'is_default',
        'matching_start_tolerance_minutes',
        'matching_end_tolerance_minutes',
        'sequence',
    ];

    protected function casts(): array
    {
        return [
            'break_minutes' => 'integer',
            'expected_paid_minutes' => 'integer',
            'grace_period_minutes' => 'integer',
            'early_timein_minutes' => 'integer',
            'overtime_buffer_minutes' => 'integer',
            'half_day_threshold_minutes' => 'integer',
            'crosses_midnight' => 'boolean',
            'is_default' => 'boolean',
            'matching_start_tolerance_minutes' => 'integer',
            'matching_end_tolerance_minutes' => 'integer',
            'sequence' => 'integer',
        ];
    }

    public function day(): BelongsTo
    {
        return $this->belongsTo(WorkingScheduleDay::class, 'working_schedule_day_id');
    }

    /**
     * Fixed-style config consumed by ScheduleComputationService.
     *
     * @return array<string, mixed>
     */
    public function toDayConfig(WorkingSchedule $parent, array $restDays): array
    {
        $breaks = [];
        if (! empty($this->break_start) && ! empty($this->break_end)) {
            $breaks[] = [
                'start' => $this->break_start,
                'end' => $this->break_end,
                'is_paid' => false,
            ];
        }

        return [
            'matched_schedule_option_id' => $this->id,
            'matched_schedule_option_name' => $this->option_name,
            'match_source' => $this->is_default ? 'default' : null,
            'in' => $this->time_in,
            'out' => $this->time_out,
            'break_start' => $this->break_start,
            'break_end' => $this->break_end,
            'breaks' => $breaks,
            'work_blocks' => [],
            'shift_type' => WorkingSchedule::SHIFT_FLEXIBLE,
            'schedule_type' => WorkingSchedule::SHIFT_FLEXIBLE,
            'crosses_midnight' => (bool) $this->crosses_midnight,
            'expected_paid_minutes' => ($this->expected_paid_minutes !== null && (int) $this->expected_paid_minutes > 0)
                ? (int) $this->expected_paid_minutes
                : $this->expectedPaidMinutes(),
            'half_day_threshold_minutes' => $this->half_day_threshold_minutes
                ?? (($parent->half_day_threshold_minutes !== null && (int) $parent->half_day_threshold_minutes > 0)
                    ? (int) $parent->half_day_threshold_minutes
                    : null),
            'grace_period_minutes' => $this->grace_period_minutes ?? $parent->grace_period_minutes ?? 5,
            'early_timein_minutes' => $this->early_timein_minutes ?? $parent->early_timein_minutes ?? 60,
            'late_allowance_minutes' => $parent->late_allowance_minutes,
            'early_timeout_minutes' => $parent->early_timeout_minutes,
            'overtime_buffer_minutes' => $this->overtime_buffer_minutes ?? $parent->overtime_buffer_minutes ?? 15,
            'rest_days' => $restDays,
        ];
    }

    public function expectedPaidMinutes(): int
    {
        if (empty($this->time_in) || empty($this->time_out)) {
            return 0;
        }

        $inMin = WorkingSchedule::timeToMinutes($this->time_in);
        $outMin = WorkingSchedule::timeToMinutes($this->time_out);
        $span = $outMin - $inMin;
        if ($span <= 0) {
            $span += 1440;
        }

        $unpaidBreak = 0;
        if (! empty($this->break_start) && ! empty($this->break_end)) {
            $bs = WorkingSchedule::timeToMinutes($this->break_start);
            $be = WorkingSchedule::timeToMinutes($this->break_end);
            $dur = $be - $bs;
            if ($dur <= 0) {
                $dur += 1440;
            }
            $unpaidBreak = $dur;
        } elseif ($this->break_minutes !== null && $this->break_minutes > 0) {
            $unpaidBreak = (int) $this->break_minutes;
        }

        return max(0, $span - $unpaidBreak);
    }
}
