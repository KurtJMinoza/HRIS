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
        'description',
        'regions',
        'is_recurring',
        'status',
    ];

    protected $casts = [
        'date' => 'date:Y-m-d',
        'regions' => 'array',
        'is_recurring' => 'boolean',
    ];
}
