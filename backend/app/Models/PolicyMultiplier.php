<?php

namespace App\Models;

use App\Enums\PolicyConditionKey;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class PolicyMultiplier extends Model
{
    protected $fillable = [
        'policy_id',
        'condition_key',
        'first8_multiplier',
        'ot_multiplier',
        'nd_addon_multiplier',
    ];

    protected function casts(): array
    {
        return [
            'first8_multiplier' => 'float',
            'ot_multiplier' => 'float',
            'nd_addon_multiplier' => 'float',
        ];
    }

    public function policy(): BelongsTo
    {
        return $this->belongsTo(Policy::class);
    }

    public function getConditionKeyEnum(): ?PolicyConditionKey
    {
        return PolicyConditionKey::tryFrom($this->condition_key);
    }

    public function toEngineFormat(): array
    {
        return [
            'first_8' => (float) $this->first8_multiplier,
            'ot' => (float) $this->ot_multiplier,
            'nd_base' => (float) $this->first8_multiplier,
            'nd_addon' => (float) $this->nd_addon_multiplier,
        ];
    }
}
