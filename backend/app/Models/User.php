<?php

namespace App\Models;

// use Illuminate\Contracts\Auth\MustVerifyEmail;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Laravel\Sanctum\HasApiTokens;
use Illuminate\Support\Str;

class User extends Authenticatable
{
    /** @use HasFactory<\Database\Factories\UserFactory> */
    use HasApiTokens, HasFactory, Notifiable;

    /**
     * The attributes that are mass assignable.
     *
     * @var list<string>
     */
    public const ROLE_EMPLOYEE = 'employee';
    public const ROLE_ADMIN = 'admin';

    protected $fillable = [
        'name',
        'email',
        'password',
        'role',
        'qr_token',
        'qr_token_generated_at',
        'face_descriptor',
        'face_descriptor_samples',
        'face_image',
        'schedule',
        'working_schedule_id',
        'is_active',
        'department',
        'department_id',
        'profile_image',
    ];

    /**
     * The attributes that should be hidden for serialization.
     *
     * @var list<string>
     */
    protected $hidden = [
        'password',
        'remember_token',
    ];

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'password' => 'hashed',
            'qr_token_generated_at' => 'datetime',
            'schedule' => 'array',
            'face_descriptor_samples' => 'array',
            'is_active' => 'boolean',
        ];
    }

    /**
     * Last attendance log for this user on the current date (by created_at date).
     */
    public function lastAttendanceToday(): ?AttendanceLog
    {
        return AttendanceLog::where('user_id', $this->id)
            ->whereDate('created_at', today())
            ->orderByDesc('created_at')
            ->first();
    }

    /**
     * True if the user has any clock_in record today (prevents duplicate time-in per day).
     */
    public function hasTimedInToday(): bool
    {
        return AttendanceLog::where('user_id', $this->id)
            ->whereDate('created_at', today())
            ->where('type', AttendanceLog::TYPE_CLOCK_IN)
            ->exists();
    }

    /**
     * True if the user can clock out (last log today is clock_in; no clock_out after it yet).
     */
    public function canClockOutToday(): bool
    {
        $last = $this->lastAttendanceToday();

        return $last && $last->type === AttendanceLog::TYPE_CLOCK_IN;
    }

    /**
     * True if the user has both clock_in and clock_out for today (attendance completed).
     */
    public function hasCompletedAttendanceToday(): bool
    {
        $hasIn = AttendanceLog::where('user_id', $this->id)
            ->whereDate('created_at', today())
            ->where('type', AttendanceLog::TYPE_CLOCK_IN)
            ->exists();
        $hasOut = AttendanceLog::where('user_id', $this->id)
            ->whereDate('created_at', today())
            ->where('type', AttendanceLog::TYPE_CLOCK_OUT)
            ->exists();

        return $hasIn && $hasOut;
    }

    public function isAdmin(): bool
    {
        return $this->role === self::ROLE_ADMIN;
    }

    public function isEmployee(): bool
    {
        return $this->role === self::ROLE_EMPLOYEE;
    }

    public static function generateQrTokenFor(User $user): string
    {
        // Human-readable QR payload derived from employee ID.
        // Example: DTR-EMP-0001 for user ID 1.
        return sprintf('DTR-EMP-%04d', $user->id);
    }

    public function departmentRelation(): \Illuminate\Database\Eloquent\Relations\BelongsTo
    {
        return $this->belongsTo(Department::class, 'department_id');
    }

    public function workingSchedule(): \Illuminate\Database\Eloquent\Relations\BelongsTo
    {
        return $this->belongsTo(WorkingSchedule::class, 'working_schedule_id');
    }
}
