<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class AttendanceDailySummary extends Model
{
    protected $table = 'attendance_daily_summaries';

    protected $guarded = ['id'];

    protected $casts = [
        'date' => 'date',
        'total_hours' => 'float',
        'scheduled_regular_hours' => 'float',
        'late_minutes' => 'integer',
        'undertime_minutes' => 'integer',
        'overtime_minutes' => 'integer',
        'approved_ot_hours' => 'float',
        'payable_ot_hours' => 'float',
        'rendered_ot_hours' => 'float',
        'nd_hours' => 'float',
        'overtime_pay' => 'float',
        'night_differential_pay' => 'float',
        'total_premium_pay' => 'float',
        'payroll_impact_hours' => 'float',
        'is_rest_day' => 'boolean',
        'has_correction' => 'boolean',
        'correction_approved' => 'boolean',
        'has_approved_overtime' => 'boolean',
        'time_out_next_day' => 'boolean',
        'extra' => 'array',
    ];

    public function employee(): BelongsTo
    {
        return $this->belongsTo(User::class, 'employee_id');
    }

    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class, 'company_id');
    }

    public function branch(): BelongsTo
    {
        return $this->belongsTo(Branch::class, 'branch_id');
    }

    public function department(): BelongsTo
    {
        return $this->belongsTo(Department::class, 'department_id');
    }

    public static function upsertFromRow(int $employeeId, string $date, array $data): self
    {
        return static::query()->updateOrCreate(
            ['employee_id' => $employeeId, 'date' => $date],
            $data
        );
    }
}
