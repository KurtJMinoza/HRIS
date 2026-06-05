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
        'time_in_clicked_at',
        'time_out_clicked_at',
        'server_received_at',
        'validation_completed_at',
        'ip_address',
        'user_agent',
        'latitude',
        'longitude',
        'accuracy_meters',
        'geofence_validation_id',
        'geofence_status',
        'matched_geofence_id',
        'similarity_score',
        'liveness_score',
        'authentication_method',
        'method',
        'processing_delay_seconds',
        'client_attempt_id',
        'overtime_hours',
        'night_hours',
        'premium_type',
        'calculated_pay_factor',
    ];

    protected function casts(): array
    {
        return [
            'verified_at' => 'datetime',
            'time_in_clicked_at' => 'datetime',
            'time_out_clicked_at' => 'datetime',
            'server_received_at' => 'datetime',
            'validation_completed_at' => 'datetime',
            'overtime_hours' => 'float',
            'night_hours' => 'float',
            'latitude' => 'float',
            'longitude' => 'float',
            'accuracy_meters' => 'float',
            'processing_delay_seconds' => 'integer',
            'calculated_pay_factor' => 'array',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function geofenceValidation(): BelongsTo
    {
        return $this->belongsTo(GeofenceValidationLog::class, 'geofence_validation_id');
    }

    public function matchedGeofence(): BelongsTo
    {
        return $this->belongsTo(BranchGeofence::class, 'matched_geofence_id');
    }
}
