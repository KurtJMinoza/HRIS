<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class OrgApprovalRecord extends Model
{
    use HasFactory;

    public const STATUS_PENDING = 'pending';

    public const STATUS_APPROVED = 'approved';

    public const STATUS_REJECTED = 'rejected';

    protected $fillable = [
        'request_id',
        'module_type',
        'approval_level',
        'approval_label',
        'approver_role',
        'approver_id',
        'approver_name',
        'eligible_approver_ids',
        'routing_rule',
        'approval_status',
        'remarks',
        'approved_at',
        'sequence_order',
    ];

    protected function casts(): array
    {
        return [
            'approved_at' => 'datetime',
            'eligible_approver_ids' => 'array',
        ];
    }

    public function approver(): BelongsTo
    {
        return $this->belongsTo(User::class, 'approver_id');
    }
}
