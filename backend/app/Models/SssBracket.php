<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class SssBracket extends Model
{
    protected $fillable = [
        'statutory_contribution_id',
        'range_start',
        'range_end',
        'range_label',
        'range_from',
        'range_to',
        'salary_min',
        'salary_max',
        'msc',
        'ee_share',
        'er_share',
        'ec_amount',
        'total',
        'employer_ss',
        'employer_ec',
        'employer_total',
        'employee_ss',
        'employee_total',
        'overall_total',
        'effective_from',
        'is_active',
    ];

    protected function casts(): array
    {
        return [
            'salary_min' => 'decimal:2',
            'salary_max' => 'decimal:2',
            'range_start' => 'decimal:2',
            'range_end' => 'decimal:2',
            'range_from' => 'decimal:2',
            'range_to' => 'decimal:2',
            'msc' => 'decimal:2',
            'ee_share' => 'decimal:2',
            'er_share' => 'decimal:2',
            'ec_amount' => 'decimal:2',
            'total' => 'decimal:2',
            'employer_ss' => 'decimal:2',
            'employer_ec' => 'decimal:2',
            'employer_total' => 'decimal:2',
            'employee_ss' => 'decimal:2',
            'employee_total' => 'decimal:2',
            'overall_total' => 'decimal:2',
            'effective_from' => 'date',
            'is_active' => 'boolean',
        ];
    }

    public function statutoryContribution(): BelongsTo
    {
        return $this->belongsTo(StatutoryContribution::class, 'statutory_contribution_id');
    }
}
