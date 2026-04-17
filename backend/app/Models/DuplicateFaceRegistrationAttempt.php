<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class DuplicateFaceRegistrationAttempt extends Model
{
    protected $fillable = [
        'attempted_for_user_id',
        'existing_user_id',
        'similarity_score',
        'detection_method',
        'ip_address',
        'user_agent',
    ];

    public function attemptedForUser(): BelongsTo
    {
        return $this->belongsTo(User::class, 'attempted_for_user_id');
    }

    public function existingUser(): BelongsTo
    {
        return $this->belongsTo(User::class, 'existing_user_id');
    }
}
