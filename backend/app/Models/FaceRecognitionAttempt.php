<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class FaceRecognitionAttempt extends Model
{
    protected $fillable = [
        'employee_id',
        'matched_employee_id',
        'similarity_score',
        'second_best_score',
        'margin_score',
        'liveness_score',
        'decision',
        'reason',
        'mode',
        'device_id',
        'camera_info',
        'ip_address',
        'user_agent',
        'metadata',
    ];

    protected function casts(): array
    {
        return [
            'similarity_score' => 'float',
            'second_best_score' => 'float',
            'margin_score' => 'float',
            'liveness_score' => 'float',
            'metadata' => 'array',
        ];
    }

    public function employee(): BelongsTo
    {
        return $this->belongsTo(User::class, 'employee_id');
    }

    public function matchedEmployee(): BelongsTo
    {
        return $this->belongsTo(User::class, 'matched_employee_id');
    }
}
