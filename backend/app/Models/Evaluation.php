<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use stdClass;

class Evaluation extends Model
{
    use HasFactory;

    const STATUS_DRAFT = 'draft';
    const STATUS_SUBMITTED = 'submitted';
    const STATUS_UNDER_REVIEW = 'under_review';
    const STATUS_COMPLETED = 'completed';

    protected $fillable = [
        'company_id',
        'branch_id',
        'department_id',
        'evaluation_form_id',
        'evaluation_assignment_id',
        'employee_id',
        'evaluator_id',
        'evaluator_role',
        'overall_score',
        'overall_rating',
        'scores',
        'remarks',
        'status',
        'evaluated_at',
        'submitted_at',
        'reviewed_at',
        'reviewed_by',
        'reviewer_remarks',
    ];

    protected function casts(): array
    {
        return [
            'overall_score' => 'decimal:2',
            'evaluated_at' => 'datetime',
            'submitted_at' => 'datetime',
            'reviewed_at' => 'datetime',
        ];
    }

    protected function scores(): Attribute
    {
        return Attribute::make(
            get: fn (?string $value) => $value === null ? null : self::normalizeSurveyScores(json_decode($value, true)),
            set: fn ($value) => json_encode($value),
        );
    }

    /**
     * PHP json_encode turns {"0":5,"1":5} into [5,5]. Force matrix answers to JSON
     * objects so SurveyJS can restore radio selections in the view modal.
     *
     * @param  array<string, mixed>|null  $scores
     * @return array<string, mixed>|null
     */
    private static function normalizeSurveyScores(?array $scores): ?array
    {
        if ($scores === null || !isset($scores['survey_data']) || !is_array($scores['survey_data'])) {
            return $scores;
        }

        foreach ($scores['survey_data'] as $key => $val) {
            if (!is_array($val) || !array_is_list($val)) {
                continue;
            }

            $obj = new stdClass();
            foreach ($val as $i => $cell) {
                $obj->{(string) $i} = $cell;
            }
            $scores['survey_data'][$key] = $obj;
        }

        return $scores;
    }

    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class);
    }

    public function branch(): BelongsTo
    {
        return $this->belongsTo(Branch::class);
    }

    public function department(): BelongsTo
    {
        return $this->belongsTo(Department::class);
    }

    public function evaluationForm(): BelongsTo
    {
        return $this->belongsTo(EvaluationForm::class, 'evaluation_form_id');
    }

    public function evaluationAssignment(): BelongsTo
    {
        return $this->belongsTo(EvaluationAssignment::class, 'evaluation_assignment_id');
    }

    public function employee(): BelongsTo
    {
        return $this->belongsTo(User::class, 'employee_id');
    }

    public function evaluator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'evaluator_id');
    }

    public function reviewer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'reviewed_by');
    }
}
