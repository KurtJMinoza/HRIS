<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class StatutoryContribution extends Model
{
    protected $fillable = [
        'name',
        'code',
        'employer_rate',
        'employee_rate',
        'min_salary',
        'max_salary',
        'msc',
        'salary_floor',
        'salary_ceiling',
        'tier_threshold',
        'monthly_cap',
        'brackets',
        'effective_from',
        'company_id',
        'is_active',
        'metadata',
        'compliance_reference',
    ];

    protected function casts(): array
    {
        return [
            'employer_rate' => 'decimal:6',
            'employee_rate' => 'decimal:6',
            'min_salary' => 'decimal:2',
            'max_salary' => 'decimal:2',
            'msc' => 'decimal:2',
            'salary_floor' => 'decimal:2',
            'salary_ceiling' => 'decimal:2',
            'tier_threshold' => 'decimal:2',
            'monthly_cap' => 'decimal:2',
            'brackets' => 'array',
            'effective_from' => 'date',
            'is_active' => 'boolean',
            'metadata' => 'array',
        ];
    }

    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class, 'company_id');
    }
}
