<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class PayrollDailyLog extends Model
{
    protected $fillable = [
        'user_id',
        'company_id',
        'date',
        'review_status',
        'is_rest_day',
        'holiday_type',
        'holiday_name',
        'regular_day_minutes',
        'regular_night_minutes',
        'ot_day_minutes',
        'ot_night_minutes',
        'approved_ot_minutes',
        'unapproved_ot_minutes',
        'late_deduction_minutes',
        'total_pay',
        'conditions',
        'breakdown',
    ];

    protected function casts(): array
    {
        return [
            'date' => 'date',
            'is_rest_day' => 'boolean',
            'conditions' => 'array',
            'breakdown' => 'array',
            'total_pay' => 'decimal:2',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
