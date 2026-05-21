<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Company extends Model
{
    use HasFactory;

    protected $fillable = [
        'name',
        'logo',
        'company_head_id',
        'default_pay_cycle_id',
        'phone',
        'email',
        'tin',
        'address',
        'founded_at',
        'payroll_settings',
    ];

    protected function casts(): array
    {
        return [
            'founded_at' => 'date',
            'payroll_settings' => 'array',
        ];
    }

    public function companyHead(): BelongsTo
    {
        return $this->belongsTo(User::class, 'company_head_id');
    }

    public function defaultPayCycle(): BelongsTo
    {
        return $this->belongsTo(PayCycle::class, 'default_pay_cycle_id');
    }

    public function branches(): HasMany
    {
        return $this->hasMany(Branch::class);
    }

    public function divisions(): HasMany
    {
        return $this->hasMany(Division::class);
    }

    public function sectionsOrUnits(): HasMany
    {
        return $this->hasMany(SectionUnit::class);
    }

    public function employees(): HasMany
    {
        return $this->hasMany(User::class, 'company_id');
    }

    public function departments(): HasMany
    {
        return $this->hasMany(Department::class, 'company_id');
    }

    public function payCycles(): HasMany
    {
        return $this->hasMany(PayCycle::class);
    }

    public function availablePayCycles(): BelongsToMany
    {
        return $this->belongsToMany(PayCycle::class, 'company_pay_cycle')
            ->withTimestamps();
    }
}
