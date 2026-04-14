<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class UserAdminActivityLog extends Model
{
    protected $fillable = [
        'subject_user_id',
        'actor_user_id',
        'action',
        'meta',
        'ip_address',
    ];

    protected function casts(): array
    {
        return [
            'meta' => 'array',
        ];
    }

    public function subject(): BelongsTo
    {
        return $this->belongsTo(User::class, 'subject_user_id');
    }

    public function actor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'actor_user_id');
    }
}
