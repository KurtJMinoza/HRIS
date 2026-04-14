<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Audit trail for leave credit balance changes (deductions, HR adjustments, annual reset).
 *
 * @property-read User|null $actor
 * @property-read LeaveRequest|null $leaveRequest
 */
class LeaveCreditTransaction extends Model
{
    public const TYPE_DEDUCTION = 'deduction';

    public const TYPE_ADDITION = 'addition';

    public const TYPE_ADJUSTMENT = 'adjustment';

    public const TYPE_ANNUAL_RESET = 'annual_reset';

    protected $table = 'leave_credit_transactions';

    protected $fillable = [
        'user_id',
        'change_type',
        'delta',
        'balance_after',
        'reason',
        'leave_request_id',
        'actor_id',
        'leave_type_context',
    ];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function actor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'actor_id');
    }

    public function leaveRequest(): BelongsTo
    {
        return $this->belongsTo(LeaveRequest::class);
    }
}
