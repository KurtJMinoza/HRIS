<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Permission extends Model
{
    protected $fillable = [
        'slug',
        'module',
        'label',
        'description',
    ];
}
