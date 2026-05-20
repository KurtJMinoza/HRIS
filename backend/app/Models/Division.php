<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Division extends Model
{
    use HasFactory;

    protected $fillable = [
        'name',
        'code',
        'company_id',
        'branch_id',
        'department_id',
        'division_head_id',
        'status',
        'description',
    ];

    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class);
    }

    public function branch(): BelongsTo
    {
        return $this->belongsTo(Branch::class);
    }

    public function department(): BelongsTo
    {
        return $this->belongsTo(Department::class);
    }

    public function divisionHead(): BelongsTo
    {
        return $this->belongsTo(User::class, 'division_head_id');
    }

    public function sectionsOrUnits(): HasMany
    {
        return $this->hasMany(SectionUnit::class, 'division_id');
    }

    public function employees(): HasMany
    {
        return $this->hasMany(User::class, 'division_id');
    }
}
