<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class EmployeeTransferLog extends Model
{
    protected $fillable = [
        'employee_id',
        'admin_id',
        'from_branch_id',
        'to_branch_id',
        'from_company_id',
        'to_company_id',
        'transfer_date',
        'reason',
        'branch_manager_removed',
    ];

    protected $casts = [
        'transfer_date' => 'date',
        'branch_manager_removed' => 'boolean',
    ];

    public function employee(): BelongsTo
    {
        return $this->belongsTo(User::class, 'employee_id');
    }

    public function admin(): BelongsTo
    {
        return $this->belongsTo(User::class, 'admin_id');
    }

    public function fromBranch(): BelongsTo
    {
        return $this->belongsTo(Branch::class, 'from_branch_id');
    }

    public function toBranch(): BelongsTo
    {
        return $this->belongsTo(Branch::class, 'to_branch_id');
    }
}
