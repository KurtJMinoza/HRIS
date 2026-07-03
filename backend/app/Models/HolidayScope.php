<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class HolidayScope extends Model
{
    protected $fillable = [
        'holiday_id',
        'scope_type',
        'scope_id',
        'company_id',
        'branch_id',
        'division_id',
        'department_id',
        'section_id',
        'employee_id',
    ];

    protected $casts = [
        'holiday_id' => 'integer',
        'scope_id' => 'integer',
        'company_id' => 'integer',
        'branch_id' => 'integer',
        'division_id' => 'integer',
        'department_id' => 'integer',
        'section_id' => 'integer',
        'employee_id' => 'integer',
    ];

    public function holiday(): BelongsTo
    {
        return $this->belongsTo(Holiday::class);
    }
}
