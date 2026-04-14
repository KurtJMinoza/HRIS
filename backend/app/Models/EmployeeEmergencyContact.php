<?php

namespace App\Models;

use App\Support\EmployeeProfileCache;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class EmployeeEmergencyContact extends Model
{
    protected $table = 'employee_emergency_contacts';

    protected $fillable = [
        'user_id',
        'full_name',
        'relationship',
        'phone_number',
        'address',
        'is_primary',
    ];

    protected static function booted(): void
    {
        $invalidate = static function (EmployeeEmergencyContact $contact): void {
            if ($contact->user_id) {
                EmployeeProfileCache::forgetForUser((int) $contact->user_id);
            }
        };

        static::saved($invalidate);
        static::deleted($invalidate);
    }

    protected function casts(): array
    {
        return [
            'is_primary' => 'boolean',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
