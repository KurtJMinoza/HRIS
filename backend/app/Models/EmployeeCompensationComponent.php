<?php

namespace App\Models;

use App\Services\PayrollCalculatorService;
use App\Support\EmployeeProfileCache;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class EmployeeCompensationComponent extends Model
{
    public const ASSIGNMENT_SOURCE_MANUAL_REMOVED = 'manual_removed';

    protected $fillable = [
        'user_id',
        'pay_component_id',
        'structure_name',
        'name',
        'code',
        'type',
        'category',
        'calculation_type',
        'calculation_standard_override',
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
            'calculation_standard_override' => 'string',
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

    public function markManuallyRemoved(): void
    {
        $metadata = is_array($this->metadata ?? null) ? $this->metadata : [];
        $metadata['assignment_source'] = self::ASSIGNMENT_SOURCE_MANUAL_REMOVED;
        $metadata['removed_at'] = now()->toIso8601String();

        $this->forceFill([
            'is_active' => false,
            'effective_to' => now()->toDateString(),
            'metadata' => $metadata,
        ])->save();
    }

    public static function employeeManuallyRemovedBasicSalary(int $userId): bool
    {
        return static::query()
            ->where('user_id', $userId)
            ->whereRaw("upper(code) = 'BASIC_SALARY'")
            ->where('is_active', false)
            ->where('metadata->assignment_source', self::ASSIGNMENT_SOURCE_MANUAL_REMOVED)
            ->exists();
    }

    /**
     * @return list<string>
     */
    public static function manualRemovalSources(): array
    {
        return [
            self::ASSIGNMENT_SOURCE_MANUAL_REMOVED,
            'manual_override',
            'manual',
        ];
    }
}
