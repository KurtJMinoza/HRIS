<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class DeductionAuditLog extends Model
{
    protected $table = 'deduction_audit_logs';

    protected $fillable = [
        'employee_deduction_id',
        'user_id',
        'actor_user_id',
        'action',
        'amount',
        'remaining_balance_after',
        'old_value',
        'new_value',
        'notes',
        'context',
    ];

    protected function casts(): array
    {
        return [
            'amount' => 'decimal:2',
            'remaining_balance_after' => 'decimal:2',
            'old_value' => 'array',
            'new_value' => 'array',
            'context' => 'array',
        ];
    }

    public function employeeDeduction(): BelongsTo
    {
        return $this->belongsTo(EmployeeDeduction::class, 'employee_deduction_id');
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_id');
    }

    public function actor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'actor_user_id');
    }
}
