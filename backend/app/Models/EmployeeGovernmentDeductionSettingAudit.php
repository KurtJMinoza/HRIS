<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class EmployeeGovernmentDeductionSettingAudit extends Model
{
    protected $fillable = [
        'employee_id',
        'deduction_type',
        'old_value',
        'new_value',
        'changed_by',
        'reason',
    ];

    protected function casts(): array
    {
        return [
            'old_value' => 'boolean',
            'new_value' => 'boolean',
        ];
    }

    public function employee(): BelongsTo
    {
        return $this->belongsTo(User::class, 'employee_id');
    }

    public function changedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'changed_by');
    }
}
