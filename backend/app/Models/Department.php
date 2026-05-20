<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Department extends Model
{
    use HasFactory;

    protected $fillable = [
        'name',
        'branch_id',
        'office_location',
        'description',
        'logo',
        'department_head_id',
    ];

    public function branch(): BelongsTo
    {
        return $this->belongsTo(Branch::class);
    }

    public function departmentHead(): BelongsTo
    {
        return $this->belongsTo(User::class, 'department_head_id');
    }

    public function employees(): HasMany
    {
        return $this->hasMany(User::class, 'department_id');
    }

    public function divisions(): HasMany
    {
        return $this->hasMany(Division::class, 'department_id');
    }

    public function sectionsOrUnits(): HasMany
    {
        return $this->hasMany(SectionUnit::class, 'department_id');
    }

    public function benefitCatalogs(): HasMany
    {
        return $this->hasMany(BenefitCatalog::class, 'department_id');
    }

    public function teams(): HasMany
    {
        return $this->hasMany(Team::class, 'department_id');
    }
}
