<?php

namespace App\Services;

use Carbon\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Date-aware KPI performance from mergedatabase-live (connection: mergedatabase).
 * Powers employee KPI Performance and admin Company Efficiency performance.
 *
 * Primary month score is merge "Avg efficiency"
 * (`merged_user_efficiency_breakdowns.overall_efficiency`, MONTHLY).
 * Daily history still comes from merged_kpi_period_snapshots (contributor progress).
 * When a short range (e.g. Today) has no monthly efficiency, falls back to
 * snapshots / identity averages, with lookback for empty ETL lag.
 */
class MergedKpiPerformanceService
{
    private const CONNECTION = 'mergedatabase';

    /** Max days to look back when the requested range has no DAILY snapshots. */
    private const EMPTY_RANGE_LOOKBACK_DAYS = 14;

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

            $effectiveFrom = $fromDate;
            $effectiveTo = $toDate;
            $asOfDate = null;
            $asOfBySource = [];

            $periodStats = $this->loadPeriodStatsBySourceUser($effectiveFrom, $effectiveTo);
            $companyAvgsFromSnapshots = $this->loadCompanyAveragesFromSnapshots($effectiveFrom, $effectiveTo);
            // Month/week ranges use merge Avg efficiency. Single-day views keep snapshot lookback.
            $monthlyEfficiency = $fromDate === $toDate
                ? []
                : $this->loadMonthlyEfficiencyBySourceUser($fromDate, $toDate);

            // Today / empty ETL lag: use latest daily snapshot on or before end date.
            if ($periodStats === [] && $companyAvgsFromSnapshots === [] && $monthlyEfficiency === []) {
                $fallbackDate = $this->latestDailySnapshotDateOnOrBefore(
                    $toDate,
                    self::EMPTY_RANGE_LOOKBACK_DAYS,
                );
                if ($fallbackDate !== null) {
                    $effectiveFrom = $fallbackDate;
                    $effectiveTo = $fallbackDate;
                    $asOfDate = $fallbackDate;
                    $periodStats = $this->loadPeriodStatsBySourceUser($effectiveFrom, $effectiveTo);
                    $companyAvgsFromSnapshots = $this->loadCompanyAveragesFromSnapshots($effectiveFrom, $effectiveTo);
                    if ($monthlyEfficiency === []) {
                        $monthlyEfficiency = $this->loadMonthlyEfficiencyBySourceUser($effectiveFrom, $effectiveTo);
                    }
                }
            }

            // Single-day request with lookback: alias snapshot day onto the requested day
            // so Company Efficiency employee rows (exact date match) still show KPI.
            if ($asOfDate !== null && $fromDate === $toDate && $asOfDate !== $fromDate) {
                foreach ($periodStats as $sourceUserId => $period) {
                    $byDate = is_array($period['by_date'] ?? null) ? $period['by_date'] : [];
                    if (isset($byDate[$asOfDate]) && is_numeric($byDate[$asOfDate])) {
                        $periodStats[$sourceUserId]['by_date'][$fromDate] = (float) $byDate[$asOfDate];
                    }
                }
            }

            if ($fromDate === $toDate) {
                $lookbackFrom = Carbon::parse($toDate)
                    ->subDays(self::EMPTY_RANGE_LOOKBACK_DAYS)
                    ->toDateString();
                $lookbackStats = $this->loadPeriodStatsBySourceUser($lookbackFrom, $toDate);
                foreach ($hrisToSource as $sourceUserId) {
                    $sourceUserId = (int) $sourceUserId;
                    if (isset($periodStats[$sourceUserId]) || ! isset($lookbackStats[$sourceUserId])) {
                        continue;
                    }

                    $byDate = is_array($lookbackStats[$sourceUserId]['by_date'] ?? null)
                        ? $lookbackStats[$sourceUserId]['by_date']
                        : [];
                    if ($byDate === []) {
                        continue;
                    }
                    ksort($byDate);
                    $latestDate = array_key_last($byDate);
                    if (! is_string($latestDate) || ! is_numeric($byDate[$latestDate] ?? null)) {
                        continue;
                    }

                    $pct = round((float) $byDate[$latestDate], 2);
                    $periodStats[$sourceUserId] = [
                        'average_percent' => $pct,
                        'by_date' => [$fromDate => $pct],
                        'snapshot_count' => 1,
                    ];
                    $asOfBySource[$sourceUserId] = $latestDate;
                }
            }

            $scoring = app(EvaluationScoringService::class);
            $byEmployee = collect();

            // Identity averages only for windows near real KPI data (not empty historical ranges).
            $kpiAnchorDate = $this->latestDailySnapshotDateOnOrBefore(
                max($toDate, Carbon::now(config('attendance.timezone', config('app.timezone', 'UTC')))->toDateString()),
                800,
            );
            $allowIdentityFallback = $kpiAnchorDate !== null
                && $toDate >= Carbon::parse($kpiAnchorDate)->startOfMonth()->subMonth()->toDateString();

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
                $periodEfficiency = $monthlyEfficiency[$sourceUserId] ?? null;

                // Merge portal "Avg efficiency" for the selected month(s) wins over
                // recomputed snapshot day averages (which mix contributor_progress rows).
                if ($periodEfficiency !== null) {
                    $pct = $periodEfficiency;
                    $source = 'merged_user_efficiency_breakdowns';
                    $snapshotCount = (int) ($period['snapshot_count'] ?? count($byDate));
                } elseif (is_array($period) && $byDate !== [] && isset($period['average_percent'])) {
                    $pct = (float) $period['average_percent'];
                    $source = 'merged_kpi_period_snapshots';
                    $snapshotCount = (int) ($period['snapshot_count'] ?? count($byDate));
                } elseif ($allowIdentityFallback && is_array($identity)) {
                    // No daily snapshots in range (or unassigned maintenance) — use identity averages
                    // so Employee Dashboard Performance still reflects KPI for mapped agents.
                    $pct = $this->resolvePerformancePercentage($identity);
                    if ($pct === null) {
                        continue;
                    }
                    $byDate = [];
                    $source = 'merged_kpi_user_averages';
                    $snapshotCount = (int) ($identity['snapshot_count'] ?? 0);
                } else {
                    continue;
                }

                $byEmployee->put($hrisUserId, [
                    'employee_id' => $hrisUserId,
                    'source_user_id' => $sourceUserId,
                    'performance_percentage' => $pct,
                    'evaluation_percentage' => $pct,
                    'by_date' => $byDate,
                    'overall_percent' => is_array($identity) ? ($identity['overall_percent'] ?? null) : null,
                    'average_percent' => is_array($identity) ? ($identity['average_percent'] ?? null) : null,
                    'overall_efficiency' => $periodEfficiency
                        ?? (is_array($identity) ? ($identity['overall_efficiency'] ?? null) : null),
                    'task_efficiency' => is_array($identity) ? ($identity['task_efficiency'] ?? null) : null,
                    'ticket_efficiency' => is_array($identity) ? ($identity['ticket_efficiency'] ?? null) : null,
                    'period_efficiency_percentage' => $periodEfficiency,
                    'performance_level' => $scoring->ratingLabelFromPercentage($pct),
                    'source' => $source,
                    'as_of_date' => $asOfBySource[$sourceUserId] ?? $asOfDate,
                    'display_name' => is_array($identity) ? ($identity['display_name'] ?? null) : null,
                    'agent_email' => is_array($identity) ? ($identity['agent_email'] ?? null) : null,
                    'computed_at' => is_array($identity) ? ($identity['computed_at'] ?? null) : null,
                    'snapshot_count' => $snapshotCount,
                ]);
            }

            if ($byEmployee->isEmpty() && $companyAvgsFromSnapshots === []) {
                return $empty;
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

    /** @param  array<string, mixed>  $kpi */
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
        if ($hasActivity) {
            if ($average !== null) {
                return round(max(0.0, min(100.0, $average)), 2);
            }
            if ($overall !== null) {
                return round(max(0.0, min(100.0, $overall)), 2);
            }
        }

        return null;
    }

    /**
     * Merge portal "Avg efficiency" for MONTHLY periods covering the range.
     *
     * @return array<int, float> source_user_id => overall_efficiency
     */
    private function loadMonthlyEfficiencyBySourceUser(string $fromDate, string $toDate): array
    {
        if (! $this->connectionReady()) {
            return [];
        }

        $periodKeys = [];
        $cursor = Carbon::parse($fromDate)->startOfMonth();
        $end = Carbon::parse($toDate)->startOfMonth();
        while ($cursor->lte($end)) {
            $periodKeys[] = $cursor->format('Y-m');
            $cursor->addMonth();
        }
        if ($periodKeys === []) {
            return [];
        }

        try {
            $rows = DB::connection(self::CONNECTION)
                ->table('merged_user_efficiency_breakdowns')
                ->where('frequency', 'MONTHLY')
                ->whereIn('period_key', $periodKeys)
                ->whereNotNull('overall_efficiency')
                ->get(['source_user_id', 'period_key', 'overall_efficiency']);
        } catch (Throwable $e) {
            Log::warning('[MergedKpiPerformance] failed to load monthly avg efficiency', [
                'message' => $e->getMessage(),
                'from' => $fromDate,
                'to' => $toDate,
            ]);

            return [];
        }

        $buckets = [];
        foreach ($rows as $row) {
            $sourceUserId = (int) ($row->source_user_id ?? 0);
            if ($sourceUserId <= 0 || ! is_numeric($row->overall_efficiency)) {
                continue;
            }
            $buckets[$sourceUserId][] = max(0.0, min(100.0, (float) $row->overall_efficiency));
        }

        $out = [];
        foreach ($buckets as $sourceUserId => $values) {
            if ($values === []) {
                continue;
            }
            $out[$sourceUserId] = round(array_sum($values) / count($values), 2);
        }

        return $out;
    }

    /**
     * @return array<int, array{average_percent: float, by_date: array<string, float>, snapshot_count: int}>
     */
    private function loadPeriodStatsBySourceUser(string $fromDate, string $toDate): array
    {
        $resolver = $this->buildContributorResolver();
        $rows = DB::connection(self::CONNECTION)
            ->table('merged_kpi_period_snapshots as s')
            ->join('merged_kpi_maintenance as m', 'm.source_id', '=', 's.kpi_maintenance_id')
            ->whereIn('s.frequency', ['DAILY', 'MONTHLY'])
            ->whereRaw("SUBSTRING_INDEX(s.period_key, ':', -1) BETWEEN ? AND ?", [$fromDate, $toDate])
            ->get([
                's.period_key',
                's.frequency',
                's.percent',
                's.done',
                's.total',
                's.contributor_progress',
                'm.assigned_merged_source_user_id',
            ]);

        $buckets = [];
        foreach ($rows as $row) {
            $frequency = strtoupper((string) $row->frequency);
            $dateKey = $this->resolveSnapshotDateKey((string) $row->period_key, $frequency, $fromDate, $toDate);
            if ($dateKey === null) {
                continue;
            }

            $contributors = $this->resolveContributorProgressRows($row, $resolver);
            $sourceUserId = (int) ($row->assigned_merged_source_user_id ?? 0);
            if ($sourceUserId > 0 && ! $this->contributorsContainSourceUser($contributors, $sourceUserId)) {
                $contributors[] = [
                    'source_user_id' => $sourceUserId,
                    'percent' => max(0.0, min(100.0, (float) $row->percent)),
                ];
            }
            if ($contributors === []) {
                continue;
            }

            foreach ($contributors as $contributor) {
                $sourceUserId = (int) ($contributor['source_user_id'] ?? 0);
                if ($sourceUserId <= 0) {
                    continue;
                }
                $pct = max(0.0, min(100.0, (float) ($contributor['percent'] ?? 0)));
                if ($frequency === 'DAILY') {
                    $buckets[$sourceUserId]['daily'][] = $pct;
                    $buckets[$sourceUserId]['dates'][$dateKey][] = $pct;
                } else {
                    $buckets[$sourceUserId]['monthly'][] = $pct;
                    $buckets[$sourceUserId]['dates'][$dateKey][] = $pct;
                }
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

    private function contributorsContainSourceUser(array $contributors, int $sourceUserId): bool
    {
        foreach ($contributors as $contributor) {
            if ((int) ($contributor['source_user_id'] ?? 0) === $sourceUserId) {
                return true;
            }
        }

        return false;
    }

    /**
     * @return list<array{source_user_id: int, percent: float}>
     */
    private function resolveContributorProgressRows(object $row, array $resolver): array
    {
        $raw = $row->contributor_progress ?? null;
        if (! is_string($raw) || trim($raw) === '') {
            return [];
        }

        $decoded = json_decode($raw, true);
        if (! is_array($decoded)) {
            return [];
        }

        $out = [];
        foreach ($decoded as $entry) {
            if (! is_array($entry)) {
                continue;
            }

            $sourceUserId = $this->resolveContributorSourceUserId($entry, $resolver);
            if ($sourceUserId === null) {
                continue;
            }

            $total = max(0, (int) ($entry['total'] ?? 0));
            $done = max(0, (int) ($entry['done'] ?? 0));
            $pct = $total > 0
                ? round(($done / $total) * 100, 2)
                : max(0.0, min(100.0, (float) ($row->percent ?? 0)));

            $out[] = [
                'source_user_id' => $sourceUserId,
                'percent' => $pct,
            ];
        }

        return $out;
    }

    private function resolveContributorSourceUserId(array $entry, array $resolver): ?int
    {
        $id = $this->normalizeIdentityKey((string) ($entry['id'] ?? ''));
        if ($id !== '' && isset($resolver['keys'][$id])) {
            return $resolver['keys'][$id];
        }

        $name = $this->normalizePersonKey((string) ($entry['name'] ?? ''));
        if ($name !== '' && isset($resolver['names'][$name])) {
            return $resolver['names'][$name];
        }

        if ($name === '') {
            return null;
        }

        $bestId = null;
        $bestScore = 0.0;
        foreach ($resolver['name_candidates'] as $candidate) {
            $candidateName = (string) ($candidate['name'] ?? '');
            if ($candidateName === '') {
                continue;
            }

            $score = $this->nameMatchScore($name, $candidateName);
            if ($score > $bestScore) {
                $bestScore = $score;
                $bestId = (int) $candidate['source_user_id'];
            }
        }

        return $bestScore >= 0.84 ? $bestId : null;
    }

    /**
     * @return array{keys: array<string, int>, names: array<string, int>, name_candidates: list<array{source_user_id: int, name: string}>}
     */
    private function buildContributorResolver(): array
    {
        $keys = [];
        $names = [];
        $candidates = [];

        $addKey = static function (?string $key, int $sourceUserId) use (&$keys): void {
            $key = $key !== null ? strtolower(trim($key)) : '';
            if ($key !== '') {
                $keys[$key] = $sourceUserId;
            }
        };

        $addName = function (?string $name, int $sourceUserId) use (&$names, &$candidates): void {
            $normalized = $this->normalizePersonKey((string) $name);
            if ($normalized === '') {
                return;
            }
            $names[$normalized] = $sourceUserId;
            $candidates[] = [
                'source_user_id' => $sourceUserId,
                'name' => $normalized,
            ];
        };

        $identityRows = DB::connection(self::CONNECTION)
            ->table('merged_kpi_user_averages')
            ->get(['source_user_id', 'portal_account_id', 'agent_email', 'display_name']);
        $canonicalSourceIds = [];
        foreach ($identityRows as $row) {
            $sourceUserId = (int) $row->source_user_id;
            $canonicalSourceIds[] = $sourceUserId;
            $addKey((string) ($row->portal_account_id ?? ''), $sourceUserId);
            $addKey((string) ($row->agent_email ?? ''), $sourceUserId);
            $addName((string) ($row->display_name ?? ''), $sourceUserId);
            $addName($this->flipCommaName((string) ($row->display_name ?? '')), $sourceUserId);
        }

        $mergedUsers = DB::connection(self::CONNECTION)
            ->table('merged_users')
            ->whereIn('source_user_id', $canonicalSourceIds)
            ->get(['source_user_id', 'username', 'email', 'name']);
        foreach ($mergedUsers as $row) {
            $sourceUserId = (int) $row->source_user_id;
            $addKey((string) ($row->username ?? ''), $sourceUserId);
            $addKey((string) ($row->email ?? ''), $sourceUserId);
            $addName((string) ($row->name ?? ''), $sourceUserId);
            $addName($this->flipCommaName((string) ($row->name ?? '')), $sourceUserId);
        }

        $aliases = DB::connection(self::CONNECTION)
            ->table('merged_username_aliases')
            ->whereIn('source_user_id', $canonicalSourceIds)
            ->get(['source_user_id', 'username']);
        foreach ($aliases as $row) {
            $sourceUserId = (int) $row->source_user_id;
            $addKey((string) ($row->username ?? ''), $sourceUserId);
            $addName((string) ($row->username ?? ''), $sourceUserId);
        }

        return [
            'keys' => $keys,
            'names' => $names,
            'name_candidates' => $candidates,
        ];
    }

    private function normalizeIdentityKey(string $value): string
    {
        return strtolower(trim($value));
    }

    private function normalizePersonKey(string $value): string
    {
        $value = strtolower(trim($value));
        $value = preg_replace('/[^a-z0-9]+/', ' ', $value) ?? '';

        return trim(preg_replace('/\s+/', ' ', $value) ?? '');
    }

    private function flipCommaName(string $value): ?string
    {
        if (! str_contains($value, ',')) {
            return null;
        }
        [$last, $first] = array_map('trim', explode(',', $value, 2));

        return trim($first.' '.$last);
    }

    private function nameMatchScore(string $a, string $b): float
    {
        if ($a === $b) {
            return 1.0;
        }

        $aTokens = array_values(array_filter(explode(' ', $a)));
        $bTokens = array_values(array_filter(explode(' ', $b)));
        if ($aTokens === [] || $bTokens === []) {
            return 0.0;
        }

        $aFirst = $aTokens[0] ?? '';
        $aLast = $aTokens[count($aTokens) - 1] ?? '';
        $bFirst = $bTokens[0] ?? '';
        $bLast = $bTokens[count($bTokens) - 1] ?? '';

        $firstMatches = $aFirst !== '' && in_array($aFirst, $bTokens, true);
        $lastMatches = $aLast !== '' && in_array($aLast, $bTokens, true);
        if ($firstMatches && $lastMatches) {
            return 0.94;
        }

        $intersection = array_intersect($aTokens, $bTokens);
        $tokenScore = count($intersection) / max(count(array_unique($aTokens)), count(array_unique($bTokens)));
        similar_text($a, $b, $similarity);

        if ($aFirst !== '' && $bFirst !== '' && levenshtein($aFirst, $bFirst) <= 1) {
            $tokenScore += 0.1;
        }
        if ($aLast !== '' && $bLast !== '' && levenshtein($aLast, $bLast) <= 1) {
            $tokenScore += 0.15;
        }

        return max(min($tokenScore, 1.0), ((float) $similarity) / 100);
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
        if ($name === '') {
            return false;
        }

        try {
            DB::connection(self::CONNECTION)->select('select 1 as ok');

            return true;
        } catch (Throwable $e) {
            Log::warning('[MergedKpiPerformance] mergedatabase connection not ready', [
                'database' => $name,
                'message' => $e->getMessage(),
            ]);

            return false;
        }
    }

    /**
     * Latest DAILY snapshot calendar date on or before $onOrBefore, within $lookbackDays.
     */
    private function latestDailySnapshotDateOnOrBefore(string $onOrBefore, int $lookbackDays): ?string
    {
        $lookbackDays = max(1, $lookbackDays);
        try {
            $from = Carbon::parse($onOrBefore)->subDays($lookbackDays)->toDateString();
        } catch (Throwable) {
            return null;
        }

        $row = DB::connection(self::CONNECTION)
            ->table('merged_kpi_period_snapshots')
            ->where('frequency', 'DAILY')
            ->whereRaw("SUBSTRING_INDEX(period_key, ':', -1) BETWEEN ? AND ?", [$from, $onOrBefore])
            ->orderByRaw("SUBSTRING_INDEX(period_key, ':', -1) DESC")
            ->first(['period_key']);

        if ($row === null || ! is_string($row->period_key ?? null)) {
            return null;
        }

        $parts = explode(':', (string) $row->period_key);
        $rawDate = end($parts);

        return is_string($rawDate) && preg_match('/^\d{4}-\d{2}-\d{2}$/', $rawDate)
            ? $rawDate
            : null;
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
