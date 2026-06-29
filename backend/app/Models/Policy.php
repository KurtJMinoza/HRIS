<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

class Policy extends Model
{
    public const STATUS_ACTIVE = 'active';

    public const STATUS_ARCHIVED = 'archived';

    /**
     * DOLE floor. Holiday premium rates themselves remain in policy_multipliers;
     * this JSON only stores entitlement, attendance, succession, and coverage rules.
     */
    public const DEFAULT_HOLIDAY_POLICY = [
        'pay_unworked_regular' => true,
        'pay_unworked_special' => false,
        'unworked_special_multiplier' => 1.0,
        'attendance' => [
            'require_previous_workday_presence' => true,
            'paid_leave_qualifies' => true,
            'skip_rest_days' => true,
            'skip_company_non_working_days' => true,
            'unpaid_absence_disqualifies' => true,
        ],
        'successive_regular_holidays' => true,
        'coverage' => [
            'rank_and_file' => true,
            'probationary' => true,
            'regular' => true,
            'managerial' => false,
            'consultants' => false,
            'contractual' => false,
            'fixed_term' => false,
            'government' => false,
            'field_personnel' => false,
            'micro_retail_service' => false,
        ],
    ];

    public const HOLIDAY_MULTIPLIER_MINIMUMS = [
        'RH' => ['first8_multiplier' => 2.00, 'ot_multiplier' => 2.60],
        'RHRD' => ['first8_multiplier' => 2.60, 'ot_multiplier' => 3.38],
        'SH' => ['first8_multiplier' => 1.30, 'ot_multiplier' => 1.69],
        'SHRD' => ['first8_multiplier' => 1.50, 'ot_multiplier' => 1.95],
        'DH' => ['first8_multiplier' => 3.00, 'ot_multiplier' => 3.90],
        'DHRD' => ['first8_multiplier' => 3.00, 'ot_multiplier' => 3.90],
    ];

    protected $fillable = [
        'name',
        'company_id',
        'branch_id',
        'effective_date',
        'status',
        'version',
        'version_label',
        'priority_order_json',
        'holiday_policy',
    ];

    protected function casts(): array
    {
        return [
            'effective_date' => 'date',
            'priority_order_json' => 'array',
            'holiday_policy' => 'array',
        ];
    }

    /** @return array<string, mixed> */
    public function resolvedHolidayPolicy(): array
    {
        $stored = is_array($this->holiday_policy) ? $this->holiday_policy : [];

        return array_replace_recursive(self::DEFAULT_HOLIDAY_POLICY, $stored);
    }

    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class);
    }

    public function branch(): BelongsTo
    {
        return $this->belongsTo(Branch::class);
    }

    public function multipliers(): HasMany
    {
        return $this->hasMany(PolicyMultiplier::class);
    }

    public function ndSetting(): HasOne
    {
        return $this->hasOne(PolicyNdSetting::class);
    }

    public function isActive(): bool
    {
        return $this->status === self::STATUS_ACTIVE;
    }

    public function scopeActive($query)
    {
        return $query->where('status', self::STATUS_ACTIVE);
    }
}
