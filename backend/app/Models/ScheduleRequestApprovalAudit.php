<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ScheduleRequestApprovalAudit extends Model
{
    protected $fillable = [
        'schedule_request_id',
        'actor_id',
        'employee_id',
        'action',
        'details',
        'approver_role',
    ];

    public function scheduleRequest(): BelongsTo
    {
        return $this->belongsTo(ScheduleRequest::class);
    }

    public function actor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'actor_id');
    }
}
