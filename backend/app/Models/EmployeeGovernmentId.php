<?php

namespace App\Models;

use App\Support\EmployeeProfileCache;
use App\Support\LegacyEncryptedString;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class EmployeeGovernmentId extends Model
{
    protected $table = 'employee_government_ids';

    protected $fillable = [
        'user_id',
        'sss_number',
        'philhealth_number',
        'pagibig_number',
        'tin_number',
    ];

    protected static function booted(): void
    {
        $invalidate = static function (EmployeeGovernmentId $record): void {
            if ($record->user_id) {
                EmployeeProfileCache::forgetForUser((int) $record->user_id);
            }
        };

        static::saved($invalidate);
        static::deleted($invalidate);
    }

    protected function sssNumber(): Attribute
    {
        return $this->plaintextGovernmentIdAttribute();
    }

    protected function philhealthNumber(): Attribute
    {
        return $this->plaintextGovernmentIdAttribute();
    }

    protected function pagibigNumber(): Attribute
    {
        return $this->plaintextGovernmentIdAttribute();
    }

    protected function tinNumber(): Attribute
    {
        return $this->plaintextGovernmentIdAttribute();
    }

    private function plaintextGovernmentIdAttribute(): Attribute
    {
        return Attribute::make(
            get: static fn (?string $value): ?string => LegacyEncryptedString::normalize($value),
            set: static fn (?string $value): ?string => LegacyEncryptedString::normalize($value),
        );
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
