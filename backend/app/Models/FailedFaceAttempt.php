<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class FailedFaceAttempt extends Model
{
    /** Failure reason for Admin panel: spoof_detected, liveness_failed, no_face_detected, face_not_recognized, face_not_registered, service_unavailable, schedule_validation_failed, leave_restriction, rate_limited, etc. */
    protected $fillable = [
        'user_id',
        'ip_address',
        'user_agent',
        'is_spoof',
        'failure_reason',
    ];

    protected function casts(): array
    {
        return [
            'is_spoof' => 'boolean',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
