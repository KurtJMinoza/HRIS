<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class StatutoryRemittance extends Model
{
    protected $fillable = [
        'company_id',
        'period_year',
        'period_month',
        'agency',
        'report_kind',
        'status',
        'file_name',
        'payload',
        'total_employee_amount',
        'total_employer_amount',
        'generated_by_user_id',
    ];

    protected function casts(): array
    {
        return [
            'payload' => 'array',
            'total_employee_amount' => 'decimal:2',
            'total_employer_amount' => 'decimal:2',
        ];
    }

    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class);
    }

    public function generatedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'generated_by_user_id');
    }
}
