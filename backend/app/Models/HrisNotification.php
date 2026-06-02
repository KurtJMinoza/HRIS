<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class HrisNotification extends Model
{
    use HasUuids;

    protected $table = 'database_notifications';

    public $incrementing = false;

    protected $keyType = 'string';

    protected $fillable = [
        'id',
        'notifiable_type',
        'notifiable_id',
        'type',
        'title',
        'message',
        'module',
        'entity_id',
        'entity_type',
        'action_url',
        'recipient_user_id',
        'recipient_role',
        'company_id',
        'department_id',
        'priority',
        'read_at',
        'dismissed_at',
        'data',
    ];

    protected $casts = [
        'entity_id' => 'integer',
        'recipient_user_id' => 'integer',
        'company_id' => 'integer',
        'department_id' => 'integer',
        'read_at' => 'datetime',
        'dismissed_at' => 'datetime',
        'data' => 'array',
    ];

    public function recipient(): BelongsTo
    {
        return $this->belongsTo(User::class, 'recipient_user_id');
    }

    public function scopeVisible(Builder $query): Builder
    {
        return $query->whereNull('dismissed_at');
    }

    public function scopeUnread(Builder $query): Builder
    {
        return $query->whereNull('read_at');
    }
}
