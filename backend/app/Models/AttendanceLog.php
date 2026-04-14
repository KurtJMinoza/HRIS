<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class AttendanceLog extends Model
{
    public const TYPE_CLOCK_IN = 'clock_in';

    public const TYPE_CLOCK_OUT = 'clock_out';

    public const AUTH_METHOD_FACE = 'Face Recognition';

    public const AUTH_METHOD_QR = 'QR Code';

    public const AUTH_METHOD_CREDENTIALS = 'Credentials';

    /** Synthetic punches after HR-approved presence filing / correction */
    public const AUTH_METHOD_HR_APPROVED_CORRECTION = 'HR Approved Correction';

    protected $fillable = [
        'user_id',
        'type',
        'verified_at',
        'ip_address',
        'user_agent',
        'latitude',
        'longitude',
        'similarity_score',
        'liveness_score',
        'authentication_method',
        'overtime_hours',
        'night_hours',
        'premium_type',
        'calculated_pay_factor',
    ];

    protected function casts(): array
    {
        return [
            'verified_at' => 'datetime',
            'overtime_hours' => 'float',
            'night_hours' => 'float',
            'calculated_pay_factor' => 'array',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
