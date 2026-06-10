<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class RecruitmentInterview extends Model
{
    public const TYPES = ['initial', 'final'];

    protected $fillable = [
        'applicant_id',
        'interview_type',
        'interviewer_id',
        'interview_date',
        'mode',
        'score',
        'notes',
        'result',
        'next_step',
        'evaluation',
    ];

    protected function casts(): array
    {
        return [
            'interview_date' => 'datetime',
            'score' => 'float',
            'evaluation' => 'array',
        ];
    }

    public function applicant(): BelongsTo
    {
        return $this->belongsTo(RecruitmentApplicant::class, 'applicant_id');
    }

    public function interviewer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'interviewer_id');
    }
}
