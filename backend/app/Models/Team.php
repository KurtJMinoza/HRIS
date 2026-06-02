<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Team extends Model
{
    use HasFactory;

    protected $fillable = [
        'name',
        'department_id',
        'team_leader_id',
    ];

    protected static function booted(): void
    {
        static::saved(function (self $team): void {
            foreach (array_filter([$team->team_leader_id, $team->getOriginal('team_leader_id')]) as $employeeId) {
                try {
                    app(\App\Services\EmployeeLevelResolver::class)->syncCachedLevel((int) $employeeId, 'team_leader_changed');
                } catch (\Throwable) {
                    // Employee level cache refresh should never block team maintenance.
                }
            }
        });
    }

    public function department(): BelongsTo
    {
        return $this->belongsTo(Department::class);
    }

    public function teamLeader(): BelongsTo
    {
        return $this->belongsTo(User::class, 'team_leader_id');
    }

    public function employees(): HasMany
    {
        return $this->hasMany(User::class, 'team_id');
    }
}
