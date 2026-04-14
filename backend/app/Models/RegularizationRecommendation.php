<?php

namespace App\Models;

use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class RegularizationRecommendation extends Model
{
    public const STATUS_PENDING = 'pending';

    public const STATUS_APPROVED = 'approved';

    public const STATUS_REJECTED = 'rejected';

    /** Default probation → regular workflow (employment status automation). */
    public const TYPE_PROBATION_TO_REGULAR = 'probation_to_regular';

    public const TYPE_CONTRACT_RENEWAL = 'contract_renewal';

    public const TYPE_CONTRACT_EXTENSION = 'contract_extension';

    public const TYPE_END_CONTRACT = 'end_contract';

    public const TYPE_PROJECT_EXTENSION = 'project_extension';

    public const TYPE_PROJECT_COMPLETION = 'project_completion';

    public const TYPE_PERFORMANCE_BASED = 'performance_based';

    protected $fillable = [
        'user_id',
        'recommended_by',
        'recommendation_type',
        'recommendation_notes',
        'status',
        'hr_reviewed_by',
        'hr_reviewed_at',
        'hr_notes',
        'recommended_at',
        'effective_date',
        'expiration_date',
        'processed',
        'processed_at',
    ];

    protected function casts(): array
    {
        return [
            'recommended_at' => 'datetime',
            'hr_reviewed_at' => 'datetime',
            'processed_at' => 'datetime',
            'effective_date' => 'date',
            'expiration_date' => 'date',
            'processed' => 'boolean',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function recommendedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'recommended_by');
    }

    public function hrReviewedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'hr_reviewed_by');
    }

    public function isApproved(): bool
    {
        return $this->status === self::STATUS_APPROVED;
    }

    public function isPending(): bool
    {
        return $this->status === self::STATUS_PENDING;
    }

    /**
     * "Active approval" means the recommendation is already approved and its
     * effective date has started (today/future). These rows should not appear
     * in submit/upcoming queues to avoid duplicate recommendations.
     */
    public function scopeActiveApproved(Builder $query, CarbonInterface|string $asOf): Builder
    {
        $asOfDate = $asOf instanceof CarbonInterface ? $asOf->toDateString() : (string) $asOf;

        return $query
            ->where('status', self::STATUS_APPROVED)
            ->whereDate('effective_date', '>=', $asOfDate);
    }

    /**
     * Computed workflow status used by UI:
     * - pending / rejected
     * - approved (decision recorded, still in effect pipeline)
     * - completed (already processed/finalized)
     */
    public function workflowStatus(): string
    {
        if ($this->status === self::STATUS_APPROVED) {
            return $this->processed ? 'completed' : 'approved';
        }

        return $this->status ?: self::STATUS_PENDING;
    }
}
