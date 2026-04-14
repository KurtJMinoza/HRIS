<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class TaxCalculationLog extends Model
{
    protected $fillable = [
        'user_id',
        'company_id',
        'context',
        'input',
        'result',
        'ip_address',
        'created_by_user_id',
    ];

    protected function casts(): array
    {
        return [
            'input' => 'array',
            'result' => 'array',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function createdBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by_user_id');
    }
}
