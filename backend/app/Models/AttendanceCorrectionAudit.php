<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class AttendanceCorrectionAudit extends Model
{
    protected $fillable = [
        'attendance_correction_id',
        'admin_id',
        'employee_id',
        'date',
        'previous_time_in',
        'previous_time_out',
        'new_time_in',
        'new_time_out',
        'reason',
        'action',
        'e_signature',
        'approver_role',
    ];

    protected function casts(): array
    {
        return [
            'date' => 'date',
            'previous_time_in' => 'datetime',
            'previous_time_out' => 'datetime',
            'new_time_in' => 'datetime',
            'new_time_out' => 'datetime',
        ];
    }

    public function correction()
    {
        return $this->belongsTo(AttendanceCorrection::class, 'attendance_correction_id');
    }

    public function admin()
    {
        return $this->belongsTo(User::class, 'admin_id');
    }
}
