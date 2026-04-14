<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasOne;

class LoanRequest extends Model
{
    protected $table = 'pay_loan_requests';

    public const STATUS_PENDING = 'pending';

    public const STATUS_APPROVED = 'approved';

    public const STATUS_REJECTED = 'rejected';

    protected $fillable = [
        'user_id',
        'requested_by_user_id',
        'deduction_type_id',
        'pay_component_id',
        'requested_amount',
        'installment_amount',
        'preferred_monthly_deduction',
        'term_months',
        'with_interest',
        'interest_rate_percent',
        'interest_type',
        'total_repayment_amount',
        'deduction_schedule',
        'reason',
        'status',
        'approval_stage',
        'pending_approval',
        'first_approver_id',
        'first_approved_at',
        'second_approver_id',
        'second_approved_at',
        'rejected_at',
        'rejected_by',
        'rejection_note',
        'reviewed_at',
        'reviewed_by',
        'approved_by',
        'approved_at',
        'employee_deduction_id',
    ];

    protected function casts(): array
    {
        return [
            'requested_amount' => 'decimal:2',
            'installment_amount' => 'decimal:2',
            'preferred_monthly_deduction' => 'decimal:2',
            'term_months' => 'integer',
            'with_interest' => 'boolean',
            'interest_rate_percent' => 'decimal:4',
            'total_repayment_amount' => 'decimal:2',
            'pending_approval' => 'boolean',
            'first_approved_at' => 'datetime',
            'second_approved_at' => 'datetime',
            'rejected_at' => 'datetime',
            'reviewed_at' => 'datetime',
            'approved_at' => 'datetime',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /** User account that filed the request (may differ from {@see $user} when a manager requests for a subordinate). */
    public function requestedByUser(): BelongsTo
    {
        return $this->belongsTo(User::class, 'requested_by_user_id');
    }

    public function deductionType(): BelongsTo
    {
        return $this->belongsTo(DeductionType::class, 'deduction_type_id');
    }

    public function payComponent(): BelongsTo
    {
        return $this->belongsTo(PayComponent::class, 'pay_component_id');
    }

    public function firstApprover(): BelongsTo
    {
        return $this->belongsTo(User::class, 'first_approver_id');
    }

    public function secondApprover(): BelongsTo
    {
        return $this->belongsTo(User::class, 'second_approver_id');
    }

    public function rejectedByUser(): BelongsTo
    {
        return $this->belongsTo(User::class, 'rejected_by');
    }

    public function reviewedByUser(): BelongsTo
    {
        return $this->belongsTo(User::class, 'reviewed_by');
    }

    public function approvedByUser(): BelongsTo
    {
        return $this->belongsTo(User::class, 'approved_by');
    }

    public function employeeDeduction(): BelongsTo
    {
        return $this->belongsTo(EmployeeDeduction::class, 'employee_deduction_id');
    }

    public function createdEmployeeDeduction(): HasOne
    {
        return $this->hasOne(EmployeeDeduction::class, 'loan_request_id');
    }
}
