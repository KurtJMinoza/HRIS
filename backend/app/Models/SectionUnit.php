<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class SectionUnit extends Model
{
    use HasFactory;

    protected $table = 'sections_or_units';

    protected $fillable = [
        'name',
        'code',
        'company_id',
        'branch_id',
        'department_id',
        'division_id',
        'section_unit_head_id',
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

    public function division(): BelongsTo
    {
        return $this->belongsTo(Division::class);
    }

    public function sectionUnitHead(): BelongsTo
    {
        return $this->belongsTo(User::class, 'section_unit_head_id');
    }

    public function employees(): HasMany
    {
        return $this->hasMany(User::class, 'section_unit_id');
    }
}
