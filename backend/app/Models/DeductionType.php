<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class DeductionType extends Model
{
    protected $table = 'pay_deduction_types';

    public const TYPE_LOAN = 'loan';

    public const TYPE_BENEFIT = 'benefit';

    public const TYPE_OTHER = 'other';

    public const TYPES = [
        self::TYPE_LOAN,
        self::TYPE_BENEFIT,
        self::TYPE_OTHER,
    ];

    protected $fillable = [
        'company_id',
        'name',
        'slug',
        'type',
        'is_government',
        'pay_component_id',
        'with_interest',
        'interest_rate_percent',
        'interest_type',
        'is_active',
    ];

    protected function casts(): array
    {
        return [
            'is_government' => 'boolean',
            'with_interest' => 'boolean',
            'interest_rate_percent' => 'decimal:4',
            'is_active' => 'boolean',
        ];
    }

    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class);
    }

    public function payComponent(): BelongsTo
    {
        return $this->belongsTo(PayComponent::class, 'pay_component_id');
    }

    public function employeeDeductions(): HasMany
    {
        return $this->hasMany(EmployeeDeduction::class, 'deduction_type_id');
    }

    public function loanRequests(): HasMany
    {
        return $this->hasMany(LoanRequest::class, 'deduction_type_id');
    }

    /**
     * Links a loan-type deduction pay component to the loan-requests / employee_deductions master row.
     */
    public static function ensureForLoanPayComponent(PayComponent $pc): self
    {
        if ($pc->type !== PayComponent::TYPE_DEDUCTION) {
            throw ValidationException::withMessages(['pay_component_id' => ['Only deduction pay components can be used for loans.']]);
        }

        $isLoan = false;
        if (Schema::hasColumn('pay_components', 'is_loan')) {
            $isLoan = (bool) $pc->is_loan;
        }
        if (! $isLoan && strcasecmp(trim((string) $pc->category), 'Loan') === 0) {
            $isLoan = true;
        }
        if (! $isLoan) {
            throw ValidationException::withMessages([
                'pay_component_id' => ['This pay component is not marked as a loan. Enable “Loan pay component” or set category to Loan.'],
            ]);
        }

        $existing = self::query()->where('pay_component_id', $pc->id)->first();
        if ($existing) {
            return $existing;
        }

        return self::create([
            'company_id' => null,
            'name' => $pc->name,
            'slug' => 'loan-pc-'.$pc->id.'-'.Str::lower(Str::random(6)),
            'type' => self::TYPE_LOAN,
            'is_government' => false,
            'pay_component_id' => $pc->id,
            'is_active' => true,
        ]);
    }
}
