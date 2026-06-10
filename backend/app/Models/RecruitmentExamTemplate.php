<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class RecruitmentExamTemplate extends Model
{
    protected $fillable = [
        'title',
        'position_id',
        'duration_minutes',
        'passing_score',
        'status',
        'created_by',
    ];

    protected function casts(): array
    {
        return [
            'duration_minutes' => 'integer',
            'passing_score' => 'float',
        ];
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
}
