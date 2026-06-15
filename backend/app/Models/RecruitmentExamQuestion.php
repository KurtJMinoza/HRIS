<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class RecruitmentExamQuestion extends Model
{
    public const TYPES = ['Multiple Choice', 'True / False', 'Identification', 'Essay', 'Checkbox', 'Short Answer', 'File Upload'];

    public const DIFFICULTIES = ['Easy', 'Medium', 'Hard'];

    public const CATEGORIES = ['Accounting', 'IT', 'HR', 'Sales', 'Management', 'Custom'];

    protected $fillable = [
        'exam_template_id',
        'question_type',
        'question',
        'choices',
        'correct_answer',
        'points',
        'difficulty',
        'category',
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
