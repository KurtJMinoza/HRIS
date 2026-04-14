<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class EmployeeStatusHistory extends Model
{
    protected $fillable = [
        'user_id',
        'previous_status',
        'new_status',
        'effective_date',
        'trigger_type',
        'actor_id',
        'remarks',
    ];

    protected function casts(): array
    {
        return [
            'effective_date' => 'date',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function actor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'actor_id');
    }
}
