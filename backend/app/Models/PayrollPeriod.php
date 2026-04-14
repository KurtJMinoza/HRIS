<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class PayrollPeriod extends Model
{
    protected $fillable = [
        'user_id',
        'pay_cycle_id',
        'from_date',
        'to_date',
        'pay_cycle_code',
        'cycle_label',
        'reference_date',
        'cut_off_start_date',
        'cut_off_end_date',
        'pay_date',
        'pro_ration_type',
        'daily_rate',
        'basic_salary_used',
        'total_pay',
        'employee_statutory_total',
        'employer_statutory_total',
        'net_pay',
        'total_worked_minutes',
        'status',
    ];

    protected function casts(): array
    {
        return [
            'from_date' => 'date',
            'to_date' => 'date',
            'reference_date' => 'date',
            'cut_off_start_date' => 'date',
            'cut_off_end_date' => 'date',
            'pay_date' => 'date',
            'daily_rate' => 'decimal:2',
            'basic_salary_used' => 'decimal:2',
            'total_pay' => 'decimal:2',
            'employee_statutory_total' => 'decimal:2',
            'employer_statutory_total' => 'decimal:2',
            'net_pay' => 'decimal:2',
        ];
    }

    public const STATUS_DRAFT = 'draft';

    public const STATUS_COMPUTED = 'computed';

    public const STATUS_LOCKED = 'locked';

    public function user(): \Illuminate\Database\Eloquent\Relations\BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function payCycle(): \Illuminate\Database\Eloquent\Relations\BelongsTo
    {
        return $this->belongsTo(PayCycle::class, 'pay_cycle_id');
    }

    public function breakdowns(): HasMany
    {
        return $this->hasMany(PayrollBreakdown::class, 'payroll_period_id');
    }
}
