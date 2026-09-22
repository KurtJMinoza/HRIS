<?php

namespace App\Models;

use Carbon\Carbon;
use Illuminate\Database\Eloquent\Builder;
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

    /** Direct admin manual attendance (no approval workflow) */
    public const AUTH_METHOD_ADMIN_MANUAL = 'Admin Manual Attendance';

    /**
     * Synthetic / non-kiosk punches that must not appear on the login kiosk Recent Activity feed.
     *
     * @return list<string>
     */
    public static function nonKioskRecentAuthMethods(): array
    {
        return [
            self::AUTH_METHOD_ADMIN_MANUAL,
            self::AUTH_METHOD_HR_APPROVED_CORRECTION,
        ];
    }

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

    /**
     * Actual punch instant: verified_at when set, else legacy created_at.
     */
    public static function punchInstant(self $log): ?Carbon
    {
        $t = $log->verified_at ?? $log->created_at;
        if ($t === null) {
            return null;
        }

        return $t instanceof Carbon ? $t->copy() : Carbon::parse($t);
    }

    /**
     * Match logs whose effective punch falls inside a UTC window (same rule as {@see User::attendanceLogEffectiveDateQuery}).
     *
     * @param  Builder<AttendanceLog>  $query
     */
    public function scopeWhereEffectiveStampBetween(Builder $query, Carbon $startUtc, Carbon $endUtc): Builder
    {
        return $query->where(function (Builder $q) use ($startUtc, $endUtc): void {
            $q->whereBetween('verified_at', [$startUtc, $endUtc])
                ->orWhere(function (Builder $fallback) use ($startUtc, $endUtc): void {
                    $fallback->whereNull('verified_at')
                        ->whereBetween('created_at', [$startUtc, $endUtc]);
                });
        });
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
