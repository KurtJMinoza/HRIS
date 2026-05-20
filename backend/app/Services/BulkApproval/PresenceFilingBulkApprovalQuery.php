<?php

namespace App\Services\BulkApproval;

use App\Models\AttendanceCorrection;
use App\Models\User;
use App\Services\AttendanceCorrectionApprovalService;
use App\Services\DataScopeService;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;

class PresenceFilingBulkApprovalQuery
{
    public function __construct(
        private readonly DataScopeService $dataScopeService,
        private readonly AttendanceCorrectionApprovalService $approvalService,
    ) {}

    /**
     * @param  array<string, mixed>  $filters
     */
    public function baseQuery(User $actor, array $filters): Builder
    {
        $statusFilter = $filters['status'] ?? $filters['status_filter'] ?? 'pending';
        if (! is_string($statusFilter) || $statusFilter === '') {
            $statusFilter = 'pending';
        }

        $query = AttendanceCorrection::query()
            ->with([
                'user',
                'filedBy',
                'firstApprover',
                'secondApprover',
                'approvals' => fn ($q) => $q->orderBy('acted_at')->orderBy('id')->with('approver'),
            ])
            ->orderByDesc('filed_at');

        if ($statusFilter === 'pending') {
            $query->where('pending_approval', true)
                ->where('approved', false)
                ->whereNull('rejected_at');
        } elseif ($statusFilter === 'approved') {
            $query->where('approved', true);
        } elseif ($statusFilter === 'rejected') {
            $query->whereNotNull('rejected_at');
        }

        $fromDate = $filters['date_from'] ?? $filters['from_date'] ?? null;
        $toDate = $filters['date_to'] ?? $filters['to_date'] ?? null;
        if (is_string($fromDate) && $fromDate !== '') {
            $query->whereDate('date', '>=', $fromDate);
        }
        if (is_string($toDate) && $toDate !== '') {
            $query->whereDate('date', '<=', $toDate);
        }

        $issueType = $filters['issue_type'] ?? null;
        if (is_string($issueType) && $issueType !== '' && $issueType !== 'all') {
            if ($issueType === 'missing_in') {
                $query->where(function ($q) {
                    $q->where('issue_kind', 'missing_in')
                        ->orWhere(function ($q2) {
                            $q2->whereNull('issue_kind')->whereNull('time_in')->whereNotNull('time_out');
                        });
                });
            } elseif ($issueType === 'missing_out') {
                $query->where(function ($q) {
                    $q->where('issue_kind', 'missing_out')
                        ->orWhere(function ($q2) {
                            $q2->whereNull('issue_kind')->whereNull('time_out')->whereNotNull('time_in');
                        });
                });
            } elseif ($issueType === 'both') {
                $query->where(function ($q) {
                    $q->where('issue_kind', 'both')
                        ->orWhere(function ($q2) {
                            $q2->whereNull('issue_kind')->whereNull('time_in')->whereNull('time_out');
                        });
                });
            }
        }

        $searchQ = $filters['search'] ?? $filters['q'] ?? null;
        if (is_string($searchQ) && trim($searchQ) !== '') {
            $raw = trim($searchQ);
            $term = '%'.$raw.'%';
            $query->where(function ($q) use ($term, $raw) {
                $q->whereHas('user', fn ($u) => $u->where('name', 'like', $term))
                    ->orWhereHas('filedBy', fn ($u) => $u->where('name', 'like', $term))
                    ->orWhereHas('user', fn ($u) => $u->where('employee_code', 'like', $term));
                $idProbe = ltrim($raw, '#');
                if (ctype_digit($idProbe)) {
                    $q->orWhere('id', (int) $idProbe);
                }
            });
        }

        $this->dataScopeService->restrictAttendanceCorrectionsQuery($actor, $query);

        return $query;
    }

    /**
     * @param  array<string, mixed>  $filters
     * @return int[]
     */
    public function approvableIds(User $actor, array $filters, int $max = 2000): array
    {
        $query = $this->baseQuery($actor, $filters);
        $query->where('pending_approval', true)
            ->where('approved', false)
            ->whereNull('rejected_at');

        $ids = [];
        $query->select('id')->orderBy('id')->chunkById(200, function ($rows) use ($actor, &$ids, $max) {
            $corrections = AttendanceCorrection::query()
                ->with([
                    'user',
                    'filedBy',
                    'firstApprover',
                    'secondApprover',
                    'approvals' => fn ($q) => $q->orderBy('acted_at')->orderBy('id')->with('approver'),
                ])
                ->whereIn('id', $rows->pluck('id'))
                ->get();

            foreach ($corrections as $correction) {
                if (count($ids) >= $max) {
                    return false;
                }
                if ($this->approvalService->canApprove($actor, $correction)) {
                    $ids[] = (int) $correction->id;
                }
            }

            return count($ids) < $max;
        });

        return $ids;
    }
}
