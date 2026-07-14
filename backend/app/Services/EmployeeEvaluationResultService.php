<?php

namespace App\Services;

use App\Models\Evaluation;
use App\Models\EvaluationAssignment;
use Carbon\Carbon;
use Carbon\CarbonInterface;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Cache;

/**
 * Shared lookup for completed Performance Evaluation results used by dashboards.
 * Does not recalculate scores — reads stored evaluation results only.
 */
class EmployeeEvaluationResultService
{
    public const MODE_WITHIN_PERIOD = 'within_period';

    public const MODE_LATEST_AS_OF_PERIOD_END = 'latest_as_of_period_end';

    /** @var list<string> */
    private const APPLICABLE_STATUSES = [
        Evaluation::STATUS_COMPLETED,
        Evaluation::STATUS_UNDER_REVIEW,
        Evaluation::STATUS_SUBMITTED,
    ];

    public function __construct(
        private readonly EvaluationScoringService $evaluationScoringService,
    ) {}

    /**
     * @return array{
     *     evaluation_id: int,
     *     employee_id: int,
     *     evaluation_percentage: float,
     *     performance_level: string|null,
     *     evaluated_at: string|null,
     *     status: string
     * }|null
     */
    public function getLatestApplicableResult(
        int $employeeId,
        CarbonInterface|string $startDate,
        CarbonInterface|string $endDate,
        ?string $mode = null,
    ): ?array {
        $results = $this->getLatestApplicableResultsForEmployees(
            [$employeeId],
            $startDate,
            $endDate,
            $mode,
        );

        return $results->get($employeeId);
    }

    /**
     * Batch-fetch latest applicable evaluation per employee (one query).
     *
     * @param  list<int>  $employeeIds
     * @return Collection<int, array{
     *     evaluation_id: int,
     *     employee_id: int,
     *     evaluation_percentage: float,
     *     performance_level: string|null,
     *     evaluated_at: string|null,
     *     status: string
     * }>
     */
    public function getLatestApplicableResultsForEmployees(
        array $employeeIds,
        CarbonInterface|string $startDate,
        CarbonInterface|string $endDate,
        ?string $mode = null,
    ): Collection {
        $employeeIds = array_values(array_unique(array_filter(
            array_map('intval', $employeeIds),
            static fn (int $id): bool => $id > 0,
        )));
        if ($employeeIds === []) {
            return collect();
        }

        $mode = $this->normalizeMode($mode);
        $start = $this->toDateString($startDate);
        $end = $this->toDateString($endDate);
        $version = (int) Cache::get('employee_evaluation_result:version', 1);
        $cacheKey = sprintf(
            'employee_evaluation_result:v%d:%s:%s:%s:%s',
            $version,
            $mode,
            $start,
            $end,
            md5(implode(',', $employeeIds)),
        );

        /** @var array<int, array<string, mixed>> $cached */
        $cached = Cache::remember($cacheKey, now()->addMinutes(5), function () use ($employeeIds, $start, $end, $mode): array {
            $query = Evaluation::query()
                ->whereIn('employee_id', $employeeIds)
                ->whereIn('status', self::APPLICABLE_STATUSES)
                ->whereNotNull('evaluated_at')
                ->where(function ($query): void {
                    $query->whereNull('evaluation_assignment_id')
                        ->orWhereHas('evaluationAssignment', function ($assignment): void {
                            $assignment->where('status', '!=', EvaluationAssignment::STATUS_CANCELLED);
                        });
                })
                ->orderByDesc('evaluated_at')
                ->orderByDesc('id');

            if ($mode === self::MODE_LATEST_AS_OF_PERIOD_END) {
                $query->whereDate('evaluated_at', '<=', $end);
            } else {
                $query->whereDate('evaluated_at', '>=', $start)
                    ->whereDate('evaluated_at', '<=', $end);
            }

            $rows = [];
            foreach ($query->get() as $evaluation) {
                $employeeId = (int) $evaluation->employee_id;
                if (isset($rows[$employeeId])) {
                    continue;
                }
                $mapped = $this->mapEvaluation($evaluation);
                if ($mapped !== null) {
                    $rows[$employeeId] = $mapped;
                }
            }

            return $rows;
        });

        return collect($cached);
    }

    public static function bumpCacheVersion(): void
    {
        $current = (int) Cache::get('employee_evaluation_result:version', 1);
        Cache::forever('employee_evaluation_result:version', $current + 1);
    }

    public function normalizeMode(?string $mode): string
    {
        $resolved = $mode ?: (string) config('attendance.evaluation_result_mode', self::MODE_WITHIN_PERIOD);

        return $resolved === self::MODE_LATEST_AS_OF_PERIOD_END
            ? self::MODE_LATEST_AS_OF_PERIOD_END
            : self::MODE_WITHIN_PERIOD;
    }

    /**
     * @return array{
     *     evaluation_id: int,
     *     employee_id: int,
     *     evaluation_percentage: float,
     *     performance_level: string|null,
     *     evaluated_at: string|null,
     *     status: string
     * }|null
     */
    private function mapEvaluation(Evaluation $evaluation): ?array
    {
        $pct = $this->evaluationScoringService->resolveOverallPercentage(
            $evaluation->scores,
            $evaluation->overall_score,
        );
        if ($pct === null) {
            return null;
        }

        $evaluatedAt = $evaluation->evaluated_at instanceof Carbon
            ? $evaluation->evaluated_at->toDateString()
            : ($evaluation->evaluated_at
                ? Carbon::parse($evaluation->evaluated_at)->toDateString()
                : null);

        return [
            'evaluation_id' => (int) $evaluation->id,
            'employee_id' => (int) $evaluation->employee_id,
            'evaluation_percentage' => $pct,
            'performance_level' => $evaluation->overall_rating ?: null,
            'evaluated_at' => $evaluatedAt,
            'status' => (string) $evaluation->status,
        ];
    }

    private function toDateString(CarbonInterface|string $value): string
    {
        if ($value instanceof CarbonInterface) {
            return $value->toDateString();
        }

        return Carbon::parse($value)->toDateString();
    }
}
