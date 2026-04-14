<?php

namespace App\Models;

use App\Support\EmployeeProfileCache;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class EmployeeTaxInfo extends Model
{
    protected $table = 'employee_tax_info';

    protected $fillable = [
        'user_id',
        'withholding_method',
        'period_type',
        'tax_table_version',
        'dependents',
        'is_mwe',
        'mwe_monthly_ceiling',
        'is_senior_citizen',
        'is_pwd',
        'is_solo_parent',
        'tax_regime',
        'additional_exemption_amount',
        'metadata',
    ];

    protected static function booted(): void
    {
        $invalidate = static function (EmployeeTaxInfo $taxInfo): void {
            if ($taxInfo->user_id) {
                EmployeeProfileCache::forgetForUser((int) $taxInfo->user_id);
            }
        };

        static::saved($invalidate);
        static::deleted($invalidate);
    }

    protected function casts(): array
    {
        return [
            'dependents' => 'integer',
            'metadata' => 'array',
            'is_mwe' => 'boolean',
            'is_senior_citizen' => 'boolean',
            'is_pwd' => 'boolean',
            'is_solo_parent' => 'boolean',
            'mwe_monthly_ceiling' => 'decimal:2',
            'additional_exemption_amount' => 'decimal:2',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
