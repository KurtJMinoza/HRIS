<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class WorkingSchedule extends Model
{
    public const SHIFT_FIXED = 'fixed';
    public const SHIFT_FLEXIBLE = 'flexible';
    public const SHIFT_SPLIT = 'split';
    public const SHIFT_OVERNIGHT = 'overnight';
    public const SHIFT_ROTATING = 'rotating';
    public const SHIFT_COMPRESSED = 'compressed';

    public const SHIFT_TYPES = [
        self::SHIFT_FIXED,
        self::SHIFT_FLEXIBLE,
        self::SHIFT_SPLIT,
        self::SHIFT_OVERNIGHT,
        self::SHIFT_ROTATING,
        self::SHIFT_COMPRESSED,
    ];

    protected $fillable = [
        'name',
        'schedule_code',
        'shift_type',
        'time_in',
        'time_out',
        'break_start',
        'break_end',
        'breaks',
        'work_blocks',
        'crosses_midnight',
        'expected_paid_minutes',
        'half_day_threshold_minutes',
        'grace_period_minutes',
        'early_timein_minutes',
        'late_allowance_minutes',
        'early_timeout_minutes',
        'overtime_buffer_minutes',
        'rest_days',
        'flexible_required_minutes',
        'flexible_earliest_in',
        'flexible_latest_out',
        'core_hours_start',
        'core_hours_end',
        'is_active',
        'description',
    ];

    public function days(): HasMany
    {
        return $this->hasMany(WorkingScheduleDay::class)
            ->with('options')
            ->orderByRaw("FIELD(day_of_week, 'mon', 'tue', 'wed', 'thu', 'fri', 'sat', 'sun')");
    }

    public function isFlexiblePerDay(): bool
    {
        return ($this->shift_type ?? self::SHIFT_FIXED) === self::SHIFT_FLEXIBLE
            && $this->relationLoaded('days')
            && $this->days->isNotEmpty();
    }

    protected function casts(): array
    {
        return [
            'rest_days' => 'array',
            'breaks' => 'array',
            'work_blocks' => 'array',
            'crosses_midnight' => 'boolean',
            'is_active' => 'boolean',
            'expected_paid_minutes' => 'integer',
            'half_day_threshold_minutes' => 'integer',
            'grace_period_minutes' => 'integer',
            'early_timein_minutes' => 'integer',
            'late_allowance_minutes' => 'integer',
            'early_timeout_minutes' => 'integer',
            'overtime_buffer_minutes' => 'integer',
            'flexible_required_minutes' => 'integer',
        ];
    }

    /**
     * Computed expected paid minutes: explicit value, or auto-calculated from shift span minus unpaid breaks.
     */
    public function getEffectivePaidMinutesAttribute(): int
    {
        if ($this->expected_paid_minutes !== null && $this->expected_paid_minutes > 0) {
            return $this->expected_paid_minutes;
        }

        return $this->computePaidMinutesFromTimes();
    }

    /**
     * Half-day threshold: explicit value, or half of effective paid minutes.
     */
    public function getEffectiveHalfDayThresholdAttribute(): int
    {
        if ($this->half_day_threshold_minutes !== null && $this->half_day_threshold_minutes > 0) {
            return $this->half_day_threshold_minutes;
        }

        return (int) floor($this->effective_paid_minutes / 2);
    }

    /**
     * All break definitions merged: JSON `breaks` array + legacy break_start/break_end.
     *
     * @return list<array{start: string, end: string, is_paid: bool}>
     */
    public function getAllBreaks(): array
    {
        $breaks = [];

        if (is_array($this->breaks)) {
            foreach ($this->breaks as $b) {
                if (! is_array($b)) {
                    continue;
                }
                $breaks[] = [
                    'start' => $b['start'] ?? $b['break_start'] ?? null,
                    'end' => $b['end'] ?? $b['break_end'] ?? null,
                    'is_paid' => (bool) ($b['is_paid'] ?? false),
                ];
            }
        }

        if (! empty($this->break_start) && ! empty($this->break_end)) {
            $hasLegacy = false;
            foreach ($breaks as $b) {
                if ($b['start'] === $this->break_start && $b['end'] === $this->break_end) {
                    $hasLegacy = true;
                    break;
                }
            }
            if (! $hasLegacy) {
                $breaks[] = [
                    'start' => $this->break_start,
                    'end' => $this->break_end,
                    'is_paid' => false,
                ];
            }
        }

        return array_filter($breaks, fn ($b) => ! empty($b['start']) && ! empty($b['end']));
    }

    /**
     * Get all work blocks for split shift type.
     *
     * @return list<array{start: string, end: string}>
     */
    public function getWorkBlocks(): array
    {
        if (! is_array($this->work_blocks) || empty($this->work_blocks)) {
            return [];
        }

        return array_values(array_filter(
            $this->work_blocks,
            fn ($b) => is_array($b) && ! empty($b['start']) && ! empty($b['end'])
        ));
    }

    /**
     * Auto-calculate paid minutes from shift span minus unpaid break durations.
     */
    private function computePaidMinutesFromTimes(): int
    {
        if ($this->shift_type === self::SHIFT_SPLIT) {
            return $this->computeSplitShiftMinutes();
        }

        if (empty($this->time_in) || empty($this->time_out)) {
            return 0;
        }

        $inMin = self::timeToMinutes($this->time_in);
        $outMin = self::timeToMinutes($this->time_out);
        $span = $outMin - $inMin;
        if ($span <= 0) {
            $span += 1440;
        }

        $unpaidBreak = 0;
        foreach ($this->getAllBreaks() as $b) {
            if ($b['is_paid']) {
                continue;
            }
            $bs = self::timeToMinutes($b['start']);
            $be = self::timeToMinutes($b['end']);
            $dur = $be - $bs;
            if ($dur <= 0) {
                $dur += 1440;
            }
            $unpaidBreak += $dur;
        }

        return max(0, $span - $unpaidBreak);
    }

    private function computeSplitShiftMinutes(): int
    {
        $blocks = $this->getWorkBlocks();
        if (empty($blocks)) {
            return 0;
        }

        $total = 0;
        foreach ($blocks as $block) {
            $s = self::timeToMinutes($block['start']);
            $e = self::timeToMinutes($block['end']);
            $dur = $e - $s;
            if ($dur <= 0) {
                $dur += 1440;
            }
            $total += $dur;
        }

        $unpaidBreak = 0;
        foreach ($this->getAllBreaks() as $b) {
            if ($b['is_paid']) {
                continue;
            }
            $bs = self::timeToMinutes($b['start']);
            $be = self::timeToMinutes($b['end']);
            $dur = $be - $bs;
            if ($dur <= 0) {
                $dur += 1440;
            }
            $unpaidBreak += $dur;
        }

        return max(0, $total - $unpaidBreak);
    }

    public static function timeToMinutes(?string $time): int
    {
        if (! $time || ! preg_match('/^(\d{1,2}):(\d{2})/', $time, $m)) {
            return 0;
        }

        return (int) $m[1] * 60 + (int) $m[2];
    }
}
