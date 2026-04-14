<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class EmployeeGovernmentLoan extends Model
{
    protected $fillable = [
        'user_id',
        'agency',
        'loan_kind',
        'reference_no',
        'monthly_amortization',
        'outstanding_balance',
        'start_date',
        'end_date',
        'is_active',
        'metadata',
    ];

    protected function casts(): array
    {
        return [
            'monthly_amortization' => 'decimal:2',
            'outstanding_balance' => 'decimal:2',
            'start_date' => 'date',
            'end_date' => 'date',
            'is_active' => 'boolean',
            'metadata' => 'array',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
