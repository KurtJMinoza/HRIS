<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;

class PayCycle extends Model
{
    use HasFactory;

    public const CODE_MONTHLY = 'monthly';

    public const CODE_SEMI_MONTHLY = 'semi_monthly';

    public const CODE_WEEKLY = 'weekly';

    public const CODE_BI_WEEKLY = 'bi_weekly';

    public const CODE_DAILY = 'daily';

    public const CODE_PROJECT = 'project';

    public const CUT_OFF_FIXED_DAY = 'fixed_day';

    public const CUT_OFF_DAY_OF_WEEK = 'day_of_week';

    public const CUT_OFF_CUSTOM = 'custom';

    public const PAY_DAY_OFFSET = 'offset';

    public const PAY_DAY_FIXED_DAY = 'fixed_day';

    public const PAY_DAY_CUSTOM = 'custom';

    public const PRORATION_NONE = 'none';

    public const PRORATION_DAILY = 'daily';

    public const PRORATION_HOURLY = 'hourly';

    public const WEEKEND_ADJUST_NONE = 'none';

    public const WEEKEND_ADJUST_PREVIOUS_FRIDAY = 'previous_friday';

    protected $fillable = [
        'company_id',
        'name',
        'code',
        'cut_off_type',
        'cut_off_value',
        'pay_day_type',
        'pay_day_value',
        'pay_day_offset',
        'pro_ration_type',
        'is_active',
        'is_default',
        'metadata',
    ];

    protected function casts(): array
    {
        return [
            'cut_off_value' => 'array',
            'pay_day_value' => 'array',
            'metadata' => 'array',
            'is_active' => 'boolean',
            'is_default' => 'boolean',
        ];
    }

    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class);
    }

    public function companies(): BelongsToMany
    {
        return $this->belongsToMany(Company::class, 'company_pay_cycle')
            ->withTimestamps();
    }

    public function employees(): HasMany
    {
        return $this->hasMany(User::class, 'pay_cycle_id');
    }

    public function payrollPeriods(): HasMany
    {
        return $this->hasMany(PayrollPeriod::class, 'pay_cycle_id');
    }
}
