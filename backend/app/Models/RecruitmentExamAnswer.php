<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class RecruitmentExamAnswer extends Model
{
    protected $fillable = [
        'exam_assignment_id',
        'question_id',
        'answer',
        'file_path',
        'score',
        'checked_by',
    ];

    protected function casts(): array
    {
        return [
            'score' => 'float',
        ];
    }

    public function assignment(): BelongsTo
    {
        return $this->belongsTo(RecruitmentExamAssignment::class, 'exam_assignment_id');
    }

    public function question(): BelongsTo
    {
        return $this->belongsTo(RecruitmentExamQuestion::class, 'question_id');
    }

    public function checker(): BelongsTo
    {
        return $this->belongsTo(User::class, 'checked_by');
    }
}
