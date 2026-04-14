<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class OvertimeApprovalAudit extends Model
{
    protected $fillable = [
        'overtime_id',
        'actor_id',
        'employee_id',
        'action',
        'details',
        'approver_role',
    ];

    public function overtime(): BelongsTo
    {
        return $this->belongsTo(Overtime::class);
    }

    public function actor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'actor_id');
    }
}
