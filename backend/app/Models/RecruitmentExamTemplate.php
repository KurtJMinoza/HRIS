<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class RecruitmentExamTemplate extends Model
{
    protected $fillable = [
        'title',
        'category',
        'department_id',
        'position_id',
        'duration_minutes',
        'passing_score',
        'instructions',
        'settings',
        'status',
        'created_by',
    ];

    protected function casts(): array
    {
        return [
            'duration_minutes' => 'integer',
            'passing_score' => 'float',
            'settings' => 'array',
        ];
    }

    public function department(): BelongsTo
    {
        return $this->belongsTo(Department::class, 'department_id');
    }

    public function position(): BelongsTo
    {
        return $this->belongsTo(Department::class, 'position_id');
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function questions(): HasMany
    {
        return $this->hasMany(RecruitmentExamQuestion::class, 'exam_template_id');
    }

    public function assignments(): HasMany
    {
        return $this->hasMany(RecruitmentExamAssignment::class, 'exam_template_id');
    }
}
