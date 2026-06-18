<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphTo;

class EmailLog extends Model
{
    protected $fillable = [
        'recipient_email',
        'recipient_user_id',
        'notification_key',
        'subject',
        'status',
        'sent_at',
        'failed_at',
        'error_message',
        'related_type',
        'related_id',
    ];

    protected function casts(): array
    {
        return [
            'sent_at' => 'datetime',
            'failed_at' => 'datetime',
        ];
    }

    public function recipient(): BelongsTo
    {
        return $this->belongsTo(User::class, 'recipient_user_id');
    }

    public function related(): MorphTo
    {
        return $this->morphTo('related');
    }
}
