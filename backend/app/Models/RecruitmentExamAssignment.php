<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class RecruitmentExamAssignment extends Model
{
    protected $fillable = [
        'applicant_id',
        'exam_template_id',
        'assigned_by',
        'exam_link_token',
        'started_at',
        'submitted_at',
        'score',
        'result',
        'status',
    ];

    protected function casts(): array
    {
        return [
            'started_at' => 'datetime',
            'submitted_at' => 'datetime',
            'score' => 'float',
        ];
    }

    public function applicant(): BelongsTo
    {
        return $this->belongsTo(RecruitmentApplicant::class, 'applicant_id');
    }

    public function template(): BelongsTo
    {
        return $this->belongsTo(RecruitmentExamTemplate::class, 'exam_template_id');
    }

    public function assigner(): BelongsTo
    {
        return $this->belongsTo(User::class, 'assigned_by');
    }

    public function answers(): HasMany
    {
        return $this->hasMany(RecruitmentExamAnswer::class, 'exam_assignment_id');
    }
}
