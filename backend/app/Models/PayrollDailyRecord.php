<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Payroll Ledger – audit trail, payslip source, reporting source.
 * Stores computed payroll per user per date.
 * Never compute directly from attendance; always go through the pipeline.
 */
class PayrollDailyRecord extends Model
{
    protected $fillable = [
        'user_id',
        'date',
        'policy_id',
        'regular_hours',
        'ot_hours',
        'nd_hours',
        'nd_ot_hours',
        'rule_code',
        'first8_pay',
        'ot_pay',
        'nd_pay',
        'holiday_premium_pay',
        'total_pay',
        'is_ot_approved',
        'approved_ot_hours',
        'unapproved_ot_hours',
        'holiday_type',
        'holiday_name',
        'is_rest_day',
        'late_deduction_minutes',
        'undertime_deduction_minutes',
        'breakdown',
        'conditions',
        'policy_snapshot',
        'worked_minutes',
    ];

    protected function casts(): array
    {
        return [
            'date' => 'date',
            'regular_hours' => 'decimal:2',
            'ot_hours' => 'decimal:2',
            'nd_hours' => 'decimal:2',
            'nd_ot_hours' => 'decimal:2',
            'first8_pay' => 'decimal:2',
            'ot_pay' => 'decimal:2',
            'nd_pay' => 'decimal:2',
            'holiday_premium_pay' => 'decimal:2',
            'total_pay' => 'decimal:2',
            'is_ot_approved' => 'boolean',
            'approved_ot_hours' => 'decimal:2',
            'unapproved_ot_hours' => 'decimal:2',
            'is_rest_day' => 'boolean',
            'breakdown' => 'array',
            'conditions' => 'array',
            'policy_snapshot' => 'array',
        ];
    }

    public function policy(): BelongsTo
    {
        return $this->belongsTo(Policy::class);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
