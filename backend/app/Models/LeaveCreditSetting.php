<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class LeaveCreditSetting extends Model
{
    protected $fillable = [
        'reset_month',
        'reset_day',
        'updated_by',
    ];

    protected function casts(): array
    {
        return [
            'reset_month' => 'integer',
            'reset_day' => 'integer',
        ];
    }

    public function updatedByUser(): BelongsTo
    {
        return $this->belongsTo(User::class, 'updated_by');
    }
}
