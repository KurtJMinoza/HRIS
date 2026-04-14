<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class PayrollBreakdown extends Model
{
    protected $fillable = [
        'payroll_period_id',
        'date',
        'status',
        'is_rest_day',
        'holiday_type',
        'holiday_name',
        'rule_code',
        'required_minutes',
        'worked_minutes',
        'regular_day_minutes',
        'regular_night_minutes',
        'ot_day_minutes',
        'ot_night_minutes',
        'late_deduction_minutes',
        'undertime_deduction_minutes',
        'regular_pay',
        'ot_pay',
        'nd_pay',
        'holiday_premium_pay',
        'approved_ot_minutes',
        'unapproved_ot_minutes',
        'conditions',
        'breakdown',
        'total_pay',
    ];

    protected function casts(): array
    {
        return [
            'date' => 'date',
            'is_rest_day' => 'boolean',
            'total_pay' => 'decimal:2',
            'regular_pay' => 'decimal:2',
            'ot_pay' => 'decimal:2',
            'nd_pay' => 'decimal:2',
            'holiday_premium_pay' => 'decimal:2',
            'conditions' => 'array',
            'breakdown' => 'array',
        ];
    }

    public function payrollPeriod(): BelongsTo
    {
        return $this->belongsTo(PayrollPeriod::class);
    }
}
