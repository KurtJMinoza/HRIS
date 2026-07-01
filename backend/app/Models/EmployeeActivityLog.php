<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class EmployeeActivityLog extends Model
{
    public const CATEGORY_AUTH = 'auth';

    public const CATEGORY_NAVIGATION = 'navigation';

    public const EVENT_LOGIN = 'login';

    public const EVENT_LOGOUT = 'logout';

    public const EVENT_PAGE_VIEW = 'page_view';

    public const EVENT_MODULE_OPEN = 'module_open';

    protected $fillable = [
        'user_id',
        'event_type',
        'category',
        'module',
        'title',
        'path',
        'summary',
        'auth_method',
        'session_token_id',
        'ip_address',
        'user_agent',
        'device_type',
        'meta',
        'occurred_at',
    ];

    protected function casts(): array
    {
        return [
            'meta' => 'array',
            'occurred_at' => 'datetime',
            'session_token_id' => 'integer',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
