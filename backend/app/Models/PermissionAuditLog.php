<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class PermissionAuditLog extends Model
{
    protected $fillable = [
        'actor_user_id',
        'target_role_key',
        'permission_id',
        'permission_slug',
        'action',
        'context',
        'ip_address',
        'user_agent',
    ];

    protected function casts(): array
    {
        return [
            'context' => 'array',
        ];
    }

    public function actor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'actor_user_id');
    }

    public function permission(): BelongsTo
    {
        return $this->belongsTo(Permission::class);
    }
}
