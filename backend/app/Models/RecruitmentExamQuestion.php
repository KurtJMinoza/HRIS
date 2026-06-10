<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class RecruitmentExamQuestion extends Model
{
    public const TYPES = ['Multiple Choice', 'True / False', 'Short Answer', 'Essay', 'File Upload'];

    protected $fillable = [
        'exam_template_id',
        'question_type',
        'question',
        'choices',
        'correct_answer',
        'points',
    ];

    protected function casts(): array
    {
        return [
            'choices' => 'array',
            'points' => 'float',
        ];
    }

    public function template(): BelongsTo
    {
        return $this->belongsTo(RecruitmentExamTemplate::class, 'exam_template_id');
    }
}
