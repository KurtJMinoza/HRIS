<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Holiday extends Model
{
    protected $fillable = [
        'date',
        'name',
        'type',
        'scope',
        'company_id',
        'branch_id',
        'department_id',
        'employee_id',
        'description',
        'regions',
        'is_recurring',
        'status',
    ];

    protected $casts = [
        'date' => 'date:Y-m-d',
        'regions' => 'array',
        'is_recurring' => 'boolean',
        'company_id' => 'integer',
        'branch_id' => 'integer',
        'department_id' => 'integer',
        'employee_id' => 'integer',
    ];
}
