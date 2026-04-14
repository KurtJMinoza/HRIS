<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class StatutoryRateHistory extends Model
{
    protected $fillable = [
        'statutory_contribution_id',
        'code',
        'company_id',
        'effective_from',
        'action',
        'old_values',
        'new_values',
        'changed_fields',
        'changed_by_user_id',
        'ip_address',
        'user_agent',
    ];

    protected function casts(): array
    {
        return [
            'effective_from' => 'date',
            'old_values' => 'array',
            'new_values' => 'array',
            'changed_fields' => 'array',
        ];
    }

    public function changedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'changed_by_user_id');
    }

    public function statutoryContribution(): BelongsTo
    {
        return $this->belongsTo(StatutoryContribution::class);
    }
}
