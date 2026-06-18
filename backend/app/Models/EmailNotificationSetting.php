<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class EmailNotificationSetting extends Model
{
    protected $fillable = [
        'notification_key',
        'label',
        'description',
        'enabled',
        'recipient_type',
        'custom_recipient_email',
        'template_id',
        'queue_name',
        'retry_attempts',
    ];

    protected function casts(): array
    {
        return [
            'enabled' => 'boolean',
        ];
    }

    public function template(): BelongsTo
    {
        return $this->belongsTo(EmailTemplate::class, 'template_id');
    }
}
