<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class WorkingSchedule extends Model
{
    protected $fillable = [
        'name',
        'time_in',
        'break_start',
        'break_end',
        'time_out',
        'grace_period_minutes',
        'early_timein_minutes',
        'late_allowance_minutes',
        'early_timeout_minutes',
        'overtime_buffer_minutes',
        'rest_days',
    ];

    protected function casts(): array
    {
        return [
            'rest_days' => 'array',
            'grace_period_minutes' => 'integer',
            'early_timein_minutes' => 'integer',
            'late_allowance_minutes' => 'integer',
            'early_timeout_minutes' => 'integer',
            'overtime_buffer_minutes' => 'integer',
        ];
    }
}

