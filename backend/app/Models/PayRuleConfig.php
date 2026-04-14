<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Per-company or global pay rule configuration.
 * Overrides config/payroll.php defaults when set.
 */
class PayRuleConfig extends Model
{
    protected $fillable = [
        'company_id',
        'grace_period_minutes',
        'ot_multiplier_ordinary',
        'rest_day_premium',
        'special_holiday_premium',
        'regular_holiday_premium',
        'rest_on_special',
        'rest_on_regular',
        'nd_percentage',
        'night_start',
        'night_end',
        'is_active',
    ];

    protected function casts(): array
    {
        return [
            'grace_period_minutes' => 'integer',
            'ot_multiplier_ordinary' => 'float',
            'rest_day_premium' => 'float',
            'special_holiday_premium' => 'float',
            'regular_holiday_premium' => 'float',
            'rest_on_special' => 'float',
            'rest_on_regular' => 'float',
            'nd_percentage' => 'float',
            'is_active' => 'boolean',
        ];
    }

    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class);
    }

    /**
     * Get effective config for a company (or global fallback).
     */
    public static function forCompany(?int $companyId): ?self
    {
        if ($companyId !== null) {
            $config = self::query()->where('company_id', $companyId)->where('is_active', true)->first();
            if ($config) {
                return $config;
            }
        }

        return self::query()->whereNull('company_id')->where('is_active', true)->first();
    }

    /**
     * Get base multiplier for first 8 hours.
     */
    public function getBaseMultiplier(?string $holidayType, bool $isRestDay): float
    {
        if ($holidayType === 'regular') {
            return $isRestDay ? $this->rest_on_regular : $this->regular_holiday_premium;
        }
        if ($holidayType === 'special' || $holidayType === 'company') {
            return $isRestDay ? $this->rest_on_special : $this->special_holiday_premium;
        }
        if ($isRestDay) {
            return $this->rest_day_premium;
        }

        return 1.0;
    }

    /**
     * Get OT multiplier (base * 1.30 for +30% on day's base rate).
     */
    public function getOtMultiplier(?string $holidayType, bool $isRestDay): float
    {
        $base = $this->getBaseMultiplier($holidayType, $isRestDay);

        return round($base * 1.30, 2);
    }
}
