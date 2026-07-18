<?php

namespace App\Services;

use Carbon\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Date-aware KPI performance from mergedatabase-demo.
 * Scores come only from merged_kpi_period_snapshots in the selected range.
 * Never spills lifetime averages onto days without a snapshot.
 */
class MergedKpiPerformanceService
{
    private const CONNECTION = 'mergedatabase';

    /**
     * @param  list<int>|Collection<int, int>  $hrisUserIds
     * @return array{
     *   by_employee: Collection<int, array<string, mixed>>,
     *   by_company: Collection<int, float>
     * }
     */
    public function getPerformanceForRange(string $fromDate, string $toDate, iterable $hrisUserIds): array
    {
        $ids = collect($hrisUserIds)
            ->map(static fn ($id) => (int) $id)
            ->filter(static fn (int $id) => $id > 0)
            ->unique()
            ->values();

        $empty = [
            'by_employee' => collect(),
            'by_company' => collect(),
        ];

        if ($ids->isEmpty()) {
            return $empty;
        }

        try {
            if (! $this->connectionReady()) {
                return $empty;
            }

            $mappedSourceIds = $this->mapHrisIdsToMergedSourceIds($ids);
            $kpiIdentity = $this->loadKpiIdentityRows();
            $emailToSourceId = $this->mapEmailsToSourceUserIds($ids, $kpiIdentity);

            $hrisToSource = [];
            foreach ($ids as $hrisUserId) {
                $sourceUserId = $mappedSourceIds->get($hrisUserId)
                    ?? ($emailToSourceId[$hrisUserId] ?? null);
                if ($sourceUserId === null && $kpiIdentity->has($hrisUserId)) {
                    $sourceUserId = $hrisUserId;
                }
                if ($sourceUserId !== null) {
                    $hrisToSource[$hrisUserId] = (int) $sourceUserId;
                }
            }

            $periodStats = $this->loadPeriodStatsBySourceUser($fromDate, $toDate);
            $companyAvgsFromSnapshots = $this->loadCompanyAveragesFromSnapshots($fromDate, $toDate);

            if ($periodStats === [] && $companyAvgsFromSnapshots === []) {
                return $empty;
            }

            $scoring = app(EvaluationScoringService::class);
            $byEmployee = collect();

            foreach ($ids as $hrisUserId) {
                $sourceUserId = $hrisToSource[$hrisUserId] ?? null;
                if ($sourceUserId === null) {
                    continue;
                }

                $period = $periodStats[$sourceUserId] ?? null;
                $identity = $kpiIdentity->get($sourceUserId);
                $byDate = is_array($period) && is_array($period['by_date'] ?? null)
                    ? $period['by_date']
                    : [];

                if (! is_array($period) || $byDate === [] || ! isset($period['average_percent'])) {
                    continue;
                }

                $pct = (float) $period['average_percent'];

                $byEmployee->put($hrisUserId, [
                    'employee_id' => $hrisUserId,
                    'source_user_id' => $sourceUserId,
                    'performance_percentage' => $pct,
                    'evaluation_percentage' => $pct,
                    'by_date' => $byDate,
                    'overall_percent' => is_array($identity) ? ($identity['overall_percent'] ?? null) : null,
                    'average_percent' => is_array($identity) ? ($identity['average_percent'] ?? null) : null,
                    'overall_efficiency' => is_array($identity) ? ($identity['overall_efficiency'] ?? null) : null,
                    'task_efficiency' => is_array($identity) ? ($identity['task_efficiency'] ?? null) : null,
                    'ticket_efficiency' => is_array($identity) ? ($identity['ticket_efficiency'] ?? null) : null,
                    'performance_level' => $scoring->ratingLabelFromPercentage($pct),
                    'source' => 'merged_kpi_period_snapshots',
                    'display_name' => is_array($identity) ? ($identity['display_name'] ?? null) : null,
                    'agent_email' => is_array($identity) ? ($identity['agent_email'] ?? null) : null,
                    'computed_at' => is_array($identity) ? ($identity['computed_at'] ?? null) : null,
                    'snapshot_count' => is_array($period) ? (int) ($period['snapshot_count'] ?? count($byDate)) : 0,
                ]);
            }

            $byCompany = collect($companyAvgsFromSnapshots);
            $hrisCompanyGroups = DB::table('users')
                ->whereIn('id', $byEmployee->keys()->all())
                ->get(['id', 'company_id']);

            $companyBuckets = [];
            foreach ($hrisCompanyGroups as $row) {
                $companyId = (int) ($row->company_id ?? 0);
                $perf = $byEmployee->get((int) $row->id);
                if ($companyId <= 0 || ! is_array($perf) || ! isset($perf['performance_percentage'])) {
                    continue;
                }
                $companyBuckets[$companyId][] = (float) $perf['performance_percentage'];
            }
            foreach ($companyBuckets as $companyId => $pcts) {
                if ($pcts !== []) {
                    $byCompany->put($companyId, round(array_sum($pcts) / count($pcts), 2));
                }
            }

            return [
                'by_employee' => $byEmployee,
                'by_company' => $byCompany,
            ];
        } catch (Throwable $e) {
            Log::warning('[MergedKpiPerformance] failed to load date-scoped KPI', [
                'message' => $e->getMessage(),
                'from' => $fromDate,
                'to' => $toDate,
            ]);

            return $empty;
        }
    }

    /**
     * Snapshot-based performance for a single calendar day only.
     *
     * @param  list<int>|Collection<int, int>  $hrisUserIds
     * @return Collection<int, array<string, mixed>>
     */
    public function getPerformanceByEmployeeIds(iterable $hrisUserIds): Collection
    {
        $today = Carbon::now(config('attendance.timezone', config('app.timezone', 'UTC')))->toDateString();

        return $this->getPerformanceForRange($today, $today, $hrisUserIds)['by_employee'];
    }

    /**
     * Prefer overall KPI % when the user has KPI activity; otherwise use overall_efficiency.
     *
     * @param  array<string, mixed>  $kpi
     */
    public function resolvePerformancePercentage(array $kpi): ?float
    {
        $hasActivity = ((int) ($kpi['kpi_count'] ?? 0)) > 0
            || ((int) ($kpi['snapshot_count'] ?? 0)) > 0
            || ((int) ($kpi['total_items'] ?? 0)) > 0;

        $overall = array_key_exists('overall_percent', $kpi) && $kpi['overall_percent'] !== null
            ? (float) $kpi['overall_percent']
            : null;
        $average = array_key_exists('average_percent', $kpi) && $kpi['average_percent'] !== null
            ? (float) $kpi['average_percent']
            : null;
        $overallEfficiency = array_key_exists('overall_efficiency', $kpi) && $kpi['overall_efficiency'] !== null
            ? (float) $kpi['overall_efficiency']
            : null;

        if ($hasActivity) {
            if ($overall !== null) {
                return round(max(0.0, min(100.0, $overall)), 2);
            }
            if ($average !== null) {
                return round(max(0.0, min(100.0, $average)), 2);
            }
        }

        if ($overallEfficiency !== null) {
            return round(max(0.0, min(100.0, $overallEfficiency)), 2);
        }

        if ($average !== null && $average > 0) {
            return round(max(0.0, min(100.0, $average)), 2);
        }

        if ($overall !== null && $overall > 0) {
            return round(max(0.0, min(100.0, $overall)), 2);
        }

        return null;
    }

    /**
     * @return array<int, array{average_percent: float, by_date: array<string, float>, snapshot_count: int}>
     */
    private function loadPeriodStatsBySourceUser(string $fromDate, string $toDate): array
    {
        $rows = DB::connection(self::CONNECTION)
            ->table('merged_kpi_period_snapshots as s')
            ->join('merged_kpi_maintenance as m', 'm.source_id', '=', 's.kpi_maintenance_id')
            ->whereNotNull('m.assigned_merged_source_user_id')
            ->where('s.frequency', 'DAILY')
            ->whereRaw("SUBSTRING_INDEX(s.period_key, ':', -1) BETWEEN ? AND ?", [$fromDate, $toDate])
            ->get([
                's.period_key',
                's.frequency',
                's.percent',
                'm.assigned_merged_source_user_id',
            ]);

        $buckets = [];
        foreach ($rows as $row) {
            $sourceUserId = (int) $row->assigned_merged_source_user_id;
            if ($sourceUserId <= 0) {
                continue;
            }
            $frequency = strtoupper((string) $row->frequency);
            $dateKey = $this->resolveSnapshotDateKey((string) $row->period_key, $frequency, $fromDate, $toDate);
            if ($dateKey === null) {
                continue;
            }
            $pct = max(0.0, min(100.0, (float) $row->percent));
            if ($frequency === 'DAILY') {
                $buckets[$sourceUserId]['daily'][] = $pct;
                $buckets[$sourceUserId]['dates'][$dateKey][] = $pct;
            } else {
                $buckets[$sourceUserId]['monthly'][] = $pct;
            }
        }

        $out = [];
        foreach ($buckets as $sourceUserId => $bucket) {
            $byDate = [];
            foreach (($bucket['dates'] ?? []) as $dateKey => $pcts) {
                $byDate[$dateKey] = round(array_sum($pcts) / count($pcts), 2);
            }
            // Prefer daily snapshots for the selected range; monthly only when no daily data.
            $all = ($bucket['daily'] ?? []) !== []
                ? $bucket['daily']
                : ($bucket['monthly'] ?? []);
            if ($all === []) {
                continue;
            }
            $out[$sourceUserId] = [
                'average_percent' => round(array_sum($all) / count($all), 2),
                'by_date' => $byDate,
                'snapshot_count' => count($all),
            ];
        }

        return $out;
    }

    /**
     * @return array<int, float> company_id => average percent
     */
    private function loadCompanyAveragesFromSnapshots(string $fromDate, string $toDate): array
    {
        $rows = DB::connection(self::CONNECTION)
            ->table('merged_kpi_period_snapshots as s')
            ->join('merged_kpi_maintenance as m', 'm.source_id', '=', 's.kpi_maintenance_id')
            ->leftJoin('merged_users as u', 'u.source_user_id', '=', 'm.assigned_merged_source_user_id')
            ->whereNotNull('u.company_id')
            ->where('s.frequency', 'DAILY')
            ->whereRaw("SUBSTRING_INDEX(s.period_key, ':', -1) BETWEEN ? AND ?", [$fromDate, $toDate])
            ->get([
                's.percent',
                's.frequency',
                'u.company_id',
            ]);

        $dailyBuckets = [];
        foreach ($rows as $row) {
            $companyId = (int) $row->company_id;
            if ($companyId <= 0) {
                continue;
            }
            $dailyBuckets[$companyId][] = max(0.0, min(100.0, (float) $row->percent));
        }

        $out = [];
        foreach ($dailyBuckets as $companyId => $pcts) {
            if ($pcts === []) {
                continue;
            }
            $out[$companyId] = round(array_sum($pcts) / count($pcts), 2);
        }

        return $out;
    }

    private function resolveSnapshotDateKey(string $periodKey, string $frequency, string $fromDate, string $toDate): ?string
    {
        $parts = explode(':', $periodKey);
        $rawDate = end($parts);
        if (! is_string($rawDate) || ! preg_match('/^\d{4}-\d{2}-\d{2}$/', $rawDate)) {
            return null;
        }

        if (strtoupper($frequency) === 'DAILY') {
            if ($rawDate < $fromDate || $rawDate > $toDate) {
                return null;
            }

            return $rawDate;
        }

        // Monthly snapshot: attach to the clamp of month-start within the selected range.
        try {
            $monthStart = Carbon::parse($rawDate)->startOfMonth();
            $monthEnd = $monthStart->copy()->endOfMonth();
            $rangeStart = Carbon::parse($fromDate)->startOfDay();
            $rangeEnd = Carbon::parse($toDate)->startOfDay();
            if ($monthEnd->lt($rangeStart) || $monthStart->gt($rangeEnd)) {
                return null;
            }
            $attached = $monthStart->greaterThan($rangeStart) ? $monthStart : $rangeStart;

            return $attached->toDateString();
        } catch (Throwable) {
            return null;
        }
    }

    private function connectionReady(): bool
    {
        $name = (string) config('database.connections.'.self::CONNECTION.'.database', '');

        return $name !== '';
    }

    /**
     * Identity and overall KPI rows from merged_kpi_user_averages.
     * Period snapshots remain the only source for exact daily by_date values.
     *
     * @return Collection<int, array<string, mixed>> keyed by source_user_id
     */
    private function loadKpiIdentityRows(): Collection
    {
        $rows = DB::connection(self::CONNECTION)
            ->table('merged_kpi_user_averages')
            ->select([
                'source_user_id',
                'portal_account_id',
                'agent_email',
                'display_name',
                'kpi_count',
                'snapshot_count',
                'total_items',
                'overall_percent',
                'average_percent',
                'task_efficiency',
                'ticket_efficiency',
                'overall_efficiency',
                'computed_at',
            ])
            ->get();

        return $rows->keyBy(static fn ($row) => (int) $row->source_user_id)
            ->map(static fn ($row) => (array) $row);
    }

    /**
     * @deprecated Scores must come from period snapshots; retained for existing unit tests of tie-break logic.
     * @return Collection<int, array<string, mixed>>
     */
    private function loadKpiAverages(): Collection
    {
        return $this->loadKpiIdentityRows();
    }

    /**
     * @param  Collection<int, int>  $hrisUserIds
     * @return Collection<int, int> hris user id => merged_source_user_id
     */
    private function mapHrisIdsToMergedSourceIds(Collection $hrisUserIds): Collection
    {
        $sources = $this->hrisExternalSources();

        $rows = DB::connection(self::CONNECTION)
            ->table('external_user_mappings')
            ->whereIn('external_source', $sources)
            ->whereIn('external_user_id', $hrisUserIds->all())
            ->get(['external_user_id', 'merged_source_user_id']);

        $map = collect();
        foreach ($rows as $row) {
            $map->put((int) $row->external_user_id, (int) $row->merged_source_user_id);
        }

        return $map;
    }

    /**
     * @param  Collection<int, int>  $hrisUserIds
     * @param  Collection<int, array<string, mixed>>  $kpiBySourceUserId
     * @return array<int, int> hris user id => source_user_id
     */
    private function mapEmailsToSourceUserIds(Collection $hrisUserIds, Collection $kpiBySourceUserId): array
    {
        $users = DB::table('users')
            ->whereIn('id', $hrisUserIds->all())
            ->whereNotNull('email')
            ->get(['id', 'email']);

        if ($users->isEmpty()) {
            return [];
        }

        $emailToHrisId = [];
        foreach ($users as $user) {
            $email = strtolower(trim((string) $user->email));
            if ($email !== '') {
                $emailToHrisId[$email] = (int) $user->id;
            }
        }

        if ($emailToHrisId === []) {
            return [];
        }

        $result = [];

        foreach ($kpiBySourceUserId as $sourceUserId => $kpi) {
            $agentEmail = strtolower(trim((string) ($kpi['agent_email'] ?? '')));
            if ($agentEmail !== '' && isset($emailToHrisId[$agentEmail])) {
                $result[$emailToHrisId[$agentEmail]] = (int) $sourceUserId;
            }
        }

        $legacyRows = DB::connection(self::CONNECTION)
            ->table('external_user_mappings')
            ->whereIn('external_user_id', $hrisUserIds->all())
            ->whereNotNull('legacy_email')
            ->get(['external_user_id', 'merged_source_user_id', 'legacy_email']);

        foreach ($legacyRows as $row) {
            $legacy = strtolower(trim((string) $row->legacy_email));
            $hrisId = (int) $row->external_user_id;
            if ($legacy !== '' && isset($emailToHrisId[$legacy]) && $kpiBySourceUserId->has((int) $row->merged_source_user_id)) {
                $result[$hrisId] = (int) $row->merged_source_user_id;
            }
        }

        $mergedUsers = DB::connection(self::CONNECTION)
            ->table('merged_users')
            ->whereNotNull('email')
            ->get(['source_user_id', 'email']);

        foreach ($mergedUsers as $row) {
            $email = strtolower(trim((string) $row->email));
            if ($email !== '' && isset($emailToHrisId[$email]) && $kpiBySourceUserId->has((int) $row->source_user_id)) {
                $result[$emailToHrisId[$email]] = (int) $row->source_user_id;
            }
        }

        return $result;
    }

    /**
     * @return list<string>
     */
    private function hrisExternalSources(): array
    {
        $dbName = (string) config('database.connections.mysql.database', 'hrisdemo');

        return array_values(array_unique(array_filter([
            $dbName,
            'hrisdemo',
            'hris',
            env('DB_MERGE_HRIS_SOURCE', null),
        ])));
    }
}
