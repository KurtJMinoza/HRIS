<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class WorkingScheduleDay extends Model
{
    public const DAY_KEYS = ['mon', 'tue', 'wed', 'thu', 'fri', 'sat', 'sun'];

    protected $fillable = [
        'working_schedule_id',
        'day_of_week',
        'is_working_day',
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
    ];

    protected function casts(): array
    {
        return [
            'is_working_day' => 'boolean',
            'crosses_midnight' => 'boolean',
            'break_minutes' => 'integer',
            'expected_paid_minutes' => 'integer',
            'grace_period_minutes' => 'integer',
            'early_timein_minutes' => 'integer',
            'overtime_buffer_minutes' => 'integer',
            'half_day_threshold_minutes' => 'integer',
        ];
    }

    public function workingSchedule(): BelongsTo
    {
        return $this->belongsTo(WorkingSchedule::class);
    }

    /**
     * Per-day config for attendance/payroll (same shape as fixed schedule days).
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
                : (($parent->expected_paid_minutes !== null && (int) $parent->expected_paid_minutes > 0)
                    ? (int) $parent->expected_paid_minutes
                    : $this->expectedPaidMinutes()),
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

    public static function normalizeTime(?string $value): ?string
    {
        if ($value === null || trim($value) === '') {
            return null;
        }
        $v = trim($value);
        if (strlen($v) >= 5 && preg_match('/^\d{1,2}:\d{2}(:\d{2})?$/', $v)) {
            return substr($v, 0, 5);
        }

        return $v;
    }
}
