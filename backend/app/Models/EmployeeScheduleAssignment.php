<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasOne;

class EmployeeScheduleAssignment extends Model
{
    public const STATUS_ACTIVE = 'active';
    public const STATUS_DRAFT = 'draft';
    public const STATUS_SUPERSEDED = 'superseded';

    protected $fillable = [
        'employee_id',
        'schedule_template_id',
        'assignment_snapshot_id',
        'effective_start_date',
        'effective_end_date',
        'assignment_type',
        'source_scope_type',
        'source_scope_id',
        'assignment_status',
        'is_adjustment',
        'adjustment_reason',
        'created_by',
    ];

    protected function casts(): array
    {
        return [
            'effective_start_date' => 'date',
            'effective_end_date' => 'date',
            'is_adjustment' => 'boolean',
        ];
    }

    public function employee(): BelongsTo
    {
        return $this->belongsTo(User::class, 'employee_id');
    }

    public function template(): BelongsTo
    {
        return $this->belongsTo(WorkingSchedule::class, 'schedule_template_id');
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function snapshot(): HasOne
    {
        return $this->hasOne(ScheduleAssignmentSnapshot::class, 'employee_schedule_assignment_id');
    }

    public function scopeActive(Builder $query): Builder
    {
        return $query->where('assignment_status', self::STATUS_ACTIVE);
    }

    public function scopeCoveringDate(Builder $query, string $date): Builder
    {
        return $query
            ->whereDate('effective_start_date', '<=', $date)
            ->where(function (Builder $q) use ($date): void {
                $q->whereNull('effective_end_date')
                    ->orWhereDate('effective_end_date', '>=', $date);
            });
    }
}
