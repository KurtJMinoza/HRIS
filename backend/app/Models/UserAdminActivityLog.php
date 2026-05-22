<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class UserAdminActivityLog extends Model
{
    protected $fillable = [
        'subject_user_id',
        'actor_user_id',
        'actor_role',
        'action',
        'meta',
        'ip_address',
    ];

    protected function casts(): array
    {
        return [
            'meta' => 'array',
        ];
    }

    protected static function booted(): void
    {
        static::creating(function (UserAdminActivityLog $log): void {
            if ($log->actor_role !== null || $log->actor_user_id === null) {
                return;
            }

            $actor = User::query()->find($log->actor_user_id);
            if (! $actor) {
                return;
            }

            $log->actor_role = $actor->isSuperAdmin() ? 'Super Admin' : (string) $actor->hr_role;
        });
    }

    public function subject(): BelongsTo
    {
        return $this->belongsTo(User::class, 'subject_user_id');
    }

    public function actor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'actor_user_id');
    }
}
