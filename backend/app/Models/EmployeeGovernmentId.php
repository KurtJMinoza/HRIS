<?php

namespace App\Models;

use App\Support\EmployeeProfileCache;
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

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
