<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class EmployeeStatutoryContribution extends Model
{
    protected $fillable = [
        'employee_id',
        'type',
        'period_month',
        'period_year',
        'basic_salary_used',
        'msc_used',
        'bracket_range',
        'employer_amount',
        'employee_amount',
        'ec_amount',
        'total_amount',
        'remitted',
        'notes',
        'metadata',
    ];

    protected function casts(): array
    {
        return [
            'basic_salary_used' => 'decimal:2',
            'msc_used' => 'decimal:2',
            'employer_amount' => 'decimal:2',
            'employee_amount' => 'decimal:2',
            'ec_amount' => 'decimal:2',
            'total_amount' => 'decimal:2',
            'remitted' => 'boolean',
            'metadata' => 'array',
        ];
    }

    public function employee(): BelongsTo
    {
        return $this->belongsTo(User::class, 'employee_id');
    }
}
