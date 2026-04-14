<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class PolicyNdSetting extends Model
{
    protected $fillable = [
        'policy_id',
        'start_time',
        'end_time',
        'nd_addon_multiplier',
        'apply_to_regular',
        'apply_to_ot',
        'apply_to_premium_days',
    ];

    protected function casts(): array
    {
        return [
            'nd_addon_multiplier' => 'float',
            'apply_to_regular' => 'boolean',
            'apply_to_ot' => 'boolean',
            'apply_to_premium_days' => 'boolean',
        ];
    }

    public function policy(): BelongsTo
    {
        return $this->belongsTo(Policy::class);
    }

    public function getStartHour(): int
    {
        $parts = explode(':', $this->start_time ?? '22:00');

        return (int) ($parts[0] ?? 22);
    }

    public function getEndHour(): int
    {
        $parts = explode(':', $this->end_time ?? '06:00');

        return (int) ($parts[0] ?? 6);
    }

    public function toConfigFormat(): array
    {
        return [
            'start_hour' => $this->getStartHour(),
            'end_hour' => $this->getEndHour(),
            'premium_multiplier' => (float) $this->nd_addon_multiplier,
            'apply_to_regular' => $this->apply_to_regular,
            'apply_to_ot' => $this->apply_to_ot,
            'apply_to_premium_days' => $this->apply_to_premium_days,
        ];
    }
}
