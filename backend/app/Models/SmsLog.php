<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class SmsLog extends Model
{
    protected $fillable = [
        'user_id',
        'to_number',
        'message',
        'context',
        'status',
        'http_status',
        'response_body',
        'error_message',
    ];

    public const STATUS_SUCCESS = 'success';

    public const STATUS_FAILED = 'failed';

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
