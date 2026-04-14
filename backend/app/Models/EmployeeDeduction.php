<?php

namespace App\Models;

use App\Support\EmployeeProfileCache;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class EmployeeDeduction extends Model
{
    protected $table = 'pay_employee_deductions';

    public const SOURCE_MANUAL = 'manual';

    public const SOURCE_LOAN_REQUEST = 'loan_request';

    protected $fillable = [
        'user_id',
        'deduction_type_id',
        'pay_component_id',
        'amount',
        'start_date',
        'end_date',
        'remaining_balance',
        'is_active',
        'source',
        'loan_request_id',
        'deduction_schedule',
        'notes',
        'total_loan_amount',
        'total_repayment_amount',
        'is_amortized',
        'with_interest',
        'is_court_ordered_garnishment',
        'is_legally_allowed',
        'priority_override',
        'interest_rate_annual',
        'interest_type',
        'next_due_date',
    ];

    protected static function booted(): void
    {
        $invalidate = static function (EmployeeDeduction $deduction): void {
            if ($deduction->user_id) {
                EmployeeProfileCache::forgetForUser((int) $deduction->user_id);
            }
        };

        static::saved($invalidate);
        static::deleted($invalidate);
    }

    protected function casts(): array
    {
        return [
            'amount' => 'decimal:2',
            'start_date' => 'date',
            'end_date' => 'date',
            'remaining_balance' => 'decimal:2',
            'total_loan_amount' => 'decimal:2',
            'total_repayment_amount' => 'decimal:2',
            'is_amortized' => 'boolean',
            'with_interest' => 'boolean',
            'is_court_ordered_garnishment' => 'boolean',
            'is_legally_allowed' => 'boolean',
            'priority_override' => 'integer',
            'interest_rate_annual' => 'decimal:4',
            'next_due_date' => 'date',
            'is_active' => 'boolean',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function deductionType(): BelongsTo
    {
        return $this->belongsTo(DeductionType::class, 'deduction_type_id');
    }

    public function payComponent(): BelongsTo
    {
        return $this->belongsTo(PayComponent::class, 'pay_component_id');
    }

    public function loanRequest(): BelongsTo
    {
        return $this->belongsTo(LoanRequest::class, 'loan_request_id');
    }

    public function amortizationSchedule(): HasMany
    {
        return $this->hasMany(LoanAmortization::class, 'employee_deduction_id')->orderBy('installment_number');
    }

    public function auditLogs(): HasMany
    {
        return $this->hasMany(DeductionAuditLog::class, 'employee_deduction_id')->latest();
    }
}
