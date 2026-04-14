<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class PayrollRule extends Model
{
    protected $fillable = [
        'code',
        'condition',
        'first8_multiplier',
        'ot_multiplier',
        'nd_base_multiplier',
        'is_active',
    ];

    protected function casts(): array
    {
        return [
            'first8_multiplier' => 'decimal:2',
            'ot_multiplier' => 'decimal:2',
            'nd_base_multiplier' => 'decimal:2',
            'is_active' => 'boolean',
        ];
    }
}
