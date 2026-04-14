<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ProbationMilestoneNotification extends Model
{
    public const MILESTONE_FIVE_MONTH = 'five_month';

    public const MILESTONE_SIX_MONTH = 'six_month';

    protected $fillable = [
        'user_id',
        'milestone',
        'milestone_date',
        'notified_at',
    ];

    protected function casts(): array
    {
        return [
            'milestone_date' => 'date',
            'notified_at' => 'datetime',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
