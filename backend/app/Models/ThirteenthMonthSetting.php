<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ThirteenthMonthSetting extends Model
{
    public const BASIS_BASIC = 'basic';
    public const BASIS_GROSS = 'gross';
    public const COVERAGE_DEC_NOV = 'dec_nov';
    public const COVERAGE_CALENDAR_YEAR = 'calendar_year';
    public const COVERAGE_FIRST_HALF = 'first_half';
    public const COVERAGE_SECOND_HALF = 'second_half';
    public const COVERAGE_CUSTOM = 'custom';

    protected $fillable = [
        'company_scope_type','company_ids','basis_type','coverage_type',
        'coverage_start_month','coverage_start_year','coverage_end_month','coverage_end_year',
        'is_active','updated_by',
    ];

    protected function casts(): array
    {
        return [
            'company_ids'=>'array','coverage_start_month'=>'integer','coverage_start_year'=>'integer',
            'coverage_end_month'=>'integer','coverage_end_year'=>'integer','is_active'=>'boolean',
        ];
    }

    public function updatedByUser(): BelongsTo { return $this->belongsTo(User::class,'updated_by'); }

    public function coverageStart(): \Carbon\Carbon
    {
        return \Carbon\Carbon::create($this->coverage_start_year,$this->coverage_start_month,1)->startOfDay();
    }

    public function coverageEnd(): \Carbon\Carbon
    {
        return \Carbon\Carbon::create($this->coverage_end_year,$this->coverage_end_month,1)->endOfMonth()->endOfDay();
    }

    public function appliesToCompany(int $companyId): bool
    {
        return $this->company_scope_type === 'all'
            || in_array($companyId,array_map('intval',$this->company_ids ?? []),true);
    }
}
