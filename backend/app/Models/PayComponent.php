<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Facades\Schema;

class PayComponent extends Model
{
    use SoftDeletes;

    public const COMPONENT_SYSTEM = 'system';

    public const COMPONENT_USER = 'user';

    public const TYPE_EARNING = 'earning';

    public const TYPE_DEDUCTION = 'deduction';

    public const CALC_FIXED = 'fixed_amount';

    public const CALC_PERCENT_BASIC = 'percent_basic';

    public const CALC_PERCENT_GROSS = 'percent_gross';

    public const CALC_FORMULA = 'formula';

    public const CALC_DAILY = 'daily_rate';

    public const CALC_HOURLY = 'hourly';

    public const STANDARD_PAYROLL = 'payroll_standard';

    public const STANDARD_MONTHLY = 'monthly_standard';

    public const TYPES = [
        self::TYPE_EARNING,
        self::TYPE_DEDUCTION,
    ];

    public const CALCULATION_TYPES = [
        self::CALC_FIXED,
        self::CALC_PERCENT_BASIC,
        self::CALC_PERCENT_GROSS,
        self::CALC_FORMULA,
        self::CALC_DAILY,
        self::CALC_HOURLY,
    ];

    public const COMPONENT_TYPES = [
        self::COMPONENT_SYSTEM,
        self::COMPONENT_USER,
    ];

    public const CALCULATION_STANDARDS = [
        self::STANDARD_PAYROLL,
        self::STANDARD_MONTHLY,
    ];

    protected $fillable = [
        'name',
        'code',
        'type',
        'category',
        'calculation_type',
        'calculation_standard',
        'default_value',
        'formula',
        'is_taxable',
        'contributes_sss',
        'contributes_philhealth',
        'contributes_pagibig',
        'is_proratable',
        'apply_to_all',
        'component_type',
        'is_system_protected',
        'effective_from',
        'effective_to',
        'is_active',
        'is_loan',
        'is_amortized',
        'default_term_months',
        'metadata',
    ];

    protected function casts(): array
    {
        return [
            'default_value' => 'decimal:2',
            'calculation_standard' => 'string',
            'is_taxable' => 'boolean',
            'contributes_sss' => 'boolean',
            'contributes_philhealth' => 'boolean',
            'contributes_pagibig' => 'boolean',
            'is_proratable' => 'boolean',
            'apply_to_all' => 'boolean',
            'is_system_protected' => 'boolean',
            'is_active' => 'boolean',
            'is_loan' => 'boolean',
            'is_amortized' => 'boolean',
            'default_term_months' => 'integer',
            'effective_from' => 'date',
            'effective_to' => 'date',
            'metadata' => 'array',
        ];
    }

    public function employeeAssignments(): HasMany
    {
        return $this->hasMany(EmployeeCompensationComponent::class, 'pay_component_id');
    }

    protected static function booted(): void
    {
        static::deleting(function (PayComponent $component) {
            if ($component->isForceDeleting()) {
                return;
            }

            if (! Schema::hasTable('employee_compensation_components')) {
                return;
            }

            EmployeeCompensationComponent::query()
                ->where('pay_component_id', $component->id)
                ->update(['is_active' => false]);
        });
    }
}
