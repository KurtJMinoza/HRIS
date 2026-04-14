<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class LoanAmortization extends Model
{
    protected $table = 'pay_loan_amortizations';

    public const STATUS_PENDING = 'pending';

    public const STATUS_PAID = 'paid';

    public const STATUS_OVERDUE = 'overdue';

    public const STATUS_SKIPPED = 'skipped';

    protected $fillable = [
        'employee_deduction_id',
        'loan_request_id',
        'installment_number',
        'due_date',
        'pay_date',
        'period_label',
        'principal',
        'interest',
        'total_installment',
        'status',
        'paid_at',
        'payroll_period_id',
    ];

    protected function casts(): array
    {
        return [
            'due_date' => 'date',
            'pay_date' => 'date',
            'principal' => 'decimal:2',
            'interest' => 'decimal:2',
            'total_installment' => 'decimal:2',
            'paid_at' => 'datetime',
        ];
    }

    public function employeeDeduction(): BelongsTo
    {
        return $this->belongsTo(EmployeeDeduction::class, 'employee_deduction_id');
    }

    public function loanRequest(): BelongsTo
    {
        return $this->belongsTo(LoanRequest::class, 'loan_request_id');
    }

    public function payrollPeriod(): BelongsTo
    {
        return $this->belongsTo(PayrollPeriod::class, 'payroll_period_id');
    }
}
