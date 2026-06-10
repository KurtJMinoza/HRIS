<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class RecruitmentApplicant extends Model
{
    public const STATUSES = [
        'New',
        'For Initial Interview',
        'Initial Interview Passed',
        'For Exam',
        'Exam Passed',
        'For Final Interview',
        'Final Interview Passed',
        'For Requirements',
        'Hired',
        'Rejected',
    ];

    protected $fillable = [
        'applicant_no',
        'first_name',
        'last_name',
        'email',
        'phone',
        'applied_position_id',
        'applied_position',
        'department_id',
        'source',
        'status',
        'date_applied',
        'created_employee_id',
    ];

    protected function casts(): array
    {
        return [
            'date_applied' => 'date',
        ];
    }

    public function appliedPosition(): BelongsTo
    {
        return $this->belongsTo(Department::class, 'applied_position_id');
    }

    public function department(): BelongsTo
    {
        return $this->belongsTo(Department::class);
    }

    public function createdEmployee(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_employee_id');
    }

    public function documents(): HasMany
    {
        return $this->hasMany(RecruitmentDocument::class, 'applicant_id')->latest();
    }

    public function interviews(): HasMany
    {
        return $this->hasMany(RecruitmentInterview::class, 'applicant_id')->latest('interview_date');
    }

    public function examAssignments(): HasMany
    {
        return $this->hasMany(RecruitmentExamAssignment::class, 'applicant_id')->latest();
    }

    public function getFullNameAttribute(): string
    {
        return trim($this->first_name.' '.$this->last_name);
    }
}
