<?php

namespace App\Models;

use App\Support\EmployeeProfileCache;
use App\Support\GovernmentExemptionCache;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class EmployeeGovernmentDeductionSetting extends Model
{
    protected $fillable = [
        'user_id',
        'deduct_sss',
        'deduct_philhealth',
        'deduct_pagibig',
        'deduct_withholding_tax',
        'applies_to_regular_payroll',
        'applies_to_execom_payroll',
        'exemption_reason',
        'is_active',
        'created_by',
        'updated_by',
    ];

    protected static function booted(): void
    {
        $invalidate = static function (EmployeeGovernmentDeductionSetting $setting): void {
            if ($setting->user_id) {
                $userId = (int) $setting->user_id;
                EmployeeProfileCache::forgetForUser($userId);
                GovernmentExemptionCache::clearPayrollCaches($userId);
            }
        };

        static::saved($invalidate);
        static::deleted($invalidate);
    }

    protected function casts(): array
    {
        return [
            'deduct_sss' => 'boolean',
            'deduct_philhealth' => 'boolean',
            'deduct_pagibig' => 'boolean',
            'deduct_withholding_tax' => 'boolean',
            'applies_to_regular_payroll' => 'boolean',
            'applies_to_execom_payroll' => 'boolean',
            'is_active' => 'boolean',
        ];
    }

    public function employee(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_id');
    }

    public function updatedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'updated_by');
    }

    public function createdBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }
}
