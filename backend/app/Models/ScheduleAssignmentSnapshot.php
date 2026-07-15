<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ScheduleAssignmentSnapshot extends Model
{
    protected $fillable = [
        'employee_schedule_assignment_id',
        'schedule_name',
        'schedule_type',
        'start_time',
        'end_time',
        'crosses_midnight',
        'scheduled_minutes',
        'paid_minutes',
        'grace_period_minutes',
        'late_deduction_policy',
        'half_day_policy',
        'workweek_days',
        'rest_days',
        'break_rules',
        'overtime_rules',
        'night_differential_rules',
        'schedule_payload',
    ];

    protected function casts(): array
    {
        return [
            'crosses_midnight' => 'boolean',
            'scheduled_minutes' => 'integer',
            'paid_minutes' => 'integer',
            'grace_period_minutes' => 'integer',
            'late_deduction_policy' => 'array',
            'half_day_policy' => 'array',
            'workweek_days' => 'array',
            'rest_days' => 'array',
            'break_rules' => 'array',
            'overtime_rules' => 'array',
            'night_differential_rules' => 'array',
            'schedule_payload' => 'array',
        ];
    }

    public function assignment(): BelongsTo
    {
        return $this->belongsTo(EmployeeScheduleAssignment::class, 'employee_schedule_assignment_id');
    }
}
