<?php

namespace App\Models;

use App\Services\PayrollCalculatorService;
use App\Support\EmployeeProfileCache;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class EmployeeCompensationComponent extends Model
{
    protected $fillable = [
        'user_id',
        'pay_component_id',
        'structure_name',
        'name',
        'code',
        'type',
        'category',
        'calculation_type',
        'value',
        'hourly_rate',
        'hours',
        'formula',
        'is_taxable',
        'contributes_sss',
        'contributes_philhealth',
        'contributes_pagibig',
        'is_proratable',
        'is_custom',
        'effective_from',
        'effective_to',
        'is_active',
        'metadata',
        'schedule_override',
    ];

    protected static function booted(): void
    {
        $invalidate = static function (EmployeeCompensationComponent $component): void {
            if ($component->user_id) {
                $uid = (int) $component->user_id;
                EmployeeProfileCache::forgetForUser($uid);
                try {
                    app(PayrollCalculatorService::class)->forgetCompensationSummaryCacheForUser($uid);
                } catch (\Throwable) {
                    // Avoid blocking saves if cache store is unavailable.
                }
            }
        };

        static::saved($invalidate);
        static::deleted($invalidate);
    }

    protected function casts(): array
    {
        return [
            'value' => 'decimal:2',
            'hourly_rate' => 'decimal:2',
            'hours' => 'decimal:2',
            'is_taxable' => 'boolean',
            'contributes_sss' => 'boolean',
            'contributes_philhealth' => 'boolean',
            'contributes_pagibig' => 'boolean',
            'is_proratable' => 'boolean',
            'is_custom' => 'boolean',
            'is_active' => 'boolean',
            'effective_from' => 'date',
            'effective_to' => 'date',
            'metadata' => 'array',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function payComponent(): BelongsTo
    {
        return $this->belongsTo(PayComponent::class, 'pay_component_id');
    }
}
