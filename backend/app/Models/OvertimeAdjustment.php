<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class OvertimeAdjustment extends Model
{
    protected $fillable = [
        'overtime_id',
        'admin_id',
        'original_minutes',
        'original_hours',
        'updated_minutes',
        'updated_hours',
        'reason',
        'notes',
    ];

    protected function casts(): array
    {
        return [
            'original_minutes' => 'integer',
            'updated_minutes' => 'integer',
        ];
    }

    public function overtime(): BelongsTo
    {
        return $this->belongsTo(Overtime::class);
    }

    public function admin(): BelongsTo
    {
        return $this->belongsTo(User::class, 'admin_id');
    }
}

