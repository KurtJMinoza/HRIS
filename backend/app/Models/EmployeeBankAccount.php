<?php

namespace App\Models;

use App\Support\EmployeeProfileCache;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class EmployeeBankAccount extends Model
{
    protected $fillable = [
        'user_id',
        'bank_name',
        'bank_code',
        'account_number',
    ];

    protected static function booted(): void
    {
        $invalidate = static function (EmployeeBankAccount $record): void {
            if ($record->user_id) {
                EmployeeProfileCache::forgetForUser((int) $record->user_id);
            }
        };

        static::saved($invalidate);
        static::deleted($invalidate);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
