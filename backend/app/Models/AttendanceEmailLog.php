<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class AttendanceEmailLog extends Model
{
    public $timestamps = false;

    protected $fillable = [
        'employee_id',
        'date',
        'reminder_type',
        'sent_at',
    ];

    protected function casts(): array
    {
        return [
            'date' => 'date',
            'sent_at' => 'datetime',
        ];
    }
}
