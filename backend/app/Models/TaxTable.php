<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class TaxTable extends Model
{
    protected $fillable = [
        'calendar_year',
        'code',
        'label',
        'effective_from',
        'effective_to',
        'payload',
        'is_active',
        'source_reference',
    ];

    protected function casts(): array
    {
        return [
            'calendar_year' => 'integer',
            'effective_from' => 'date',
            'effective_to' => 'date',
            'payload' => 'array',
            'is_active' => 'boolean',
        ];
    }

    public static function activeTrainAnnualForYear(int $year): ?self
    {
        return self::query()
            ->where('calendar_year', $year)
            ->where('is_active', true)
            ->where('code', 'train_annual')
            ->orderByDesc('effective_from')
            ->first();
    }
}
