<?php

namespace App\Services\BulkApproval;

use App\Enums\HrRole;
use App\Models\AttendanceCorrection;
use App\Models\OrgApprovalRecord;
use App\Models\User;
use App\Services\AttendanceCorrectionApprovalService;
use App\Services\DataScopeService;
use App\Services\OrgApprovalWorkflowService;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;

class PresenceFilingBulkApprovalQuery
{
    public function __construct(
        private readonly DataScopeService $dataScopeService,
        private readonly AttendanceCorrectionApprovalService $approvalService,
        private readonly OrgApprovalWorkflowService $approvalWorkflowService,
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
        if ((string) ($actor->hr_role ?? '') === HrRole::AdminHr->value) {
            return $this->fastAdminHrApprovableIds($actor, $filters, $max);
        }

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
                if ($this->canBulkApprove($actor, $correction)) {
                    $ids[] = (int) $correction->id;
                }
            }

            return count($ids) < $max;
        });

        return $ids;
    }

    public function approvableCount(User $actor, array $filters): int
    {
        if ((string) ($actor->hr_role ?? '') === HrRole::AdminHr->value) {
            return (int) $this->fastAdminHrApprovableQuery($actor, $filters)
                ->count('attendance_corrections.id');
        }

        return count($this->approvableIds($actor, $filters));
    }

    /**
     * @param  array<string, mixed>  $filters
     * @return int[]
     */
    private function fastAdminHrApprovableIds(User $actor, array $filters, int $max): array
    {
        return $this->fastAdminHrApprovableQuery($actor, $filters)
            ->reorder()
            ->orderBy('attendance_corrections.id')
            ->limit($max)
            ->pluck('attendance_corrections.id')
            ->map(fn ($id) => (int) $id)
            ->all();
    }

    /**
     * Fast SQL-only path for Admin HR final approvals.
     *
     * @param  array<string, mixed>  $filters
     */
    private function fastAdminHrApprovableQuery(User $actor, array $filters): Builder
    {
        $query = $this->baseQuery($actor, array_merge($filters, ['status' => 'pending']));
        $query->setEagerLoads([]);
        $query->reorder();
        $query
            ->select('attendance_corrections.id')
            ->join('org_approval_records as current_approval', function ($join): void {
                $join->on('current_approval.request_id', '=', 'attendance_corrections.id')
                    ->where('current_approval.module_type', '=', OrgApprovalWorkflowService::MODULE_ATTENDANCE_CORRECTION)
                    ->where('current_approval.approval_status', '=', OrgApprovalRecord::STATUS_PENDING)
                    ->where('current_approval.approver_role', '=', HrRole::AdminHr->value);
            })
            ->where('attendance_corrections.pending_approval', true)
            ->where('attendance_corrections.approved', false)
            ->whereNull('attendance_corrections.rejected_at')
            ->where(function ($q): void {
                $q->where(function ($missingIn): void {
                    $missingIn->where('attendance_corrections.issue_kind', 'missing_in')
                        ->whereNotNull('attendance_corrections.time_in');
                })->orWhere(function ($missingOut): void {
                    $missingOut->where('attendance_corrections.issue_kind', 'missing_out')
                        ->whereNotNull('attendance_corrections.time_out');
                })->orWhere(function ($both): void {
                    $both->where('attendance_corrections.issue_kind', 'both')
                        ->whereNotNull('attendance_corrections.time_in')
                        ->whereNotNull('attendance_corrections.time_out')
                        ->whereColumn('attendance_corrections.time_out', '>', 'attendance_corrections.time_in');
                })->orWhere(function ($legacy): void {
                    $legacy->whereNull('attendance_corrections.issue_kind')
                        ->where(function ($legacyCase): void {
                            $legacyCase->where(function ($missingIn): void {
                                $missingIn->whereNull('attendance_corrections.time_in')
                                    ->whereNotNull('attendance_corrections.time_out')
                                    ->whereRaw('0 = 1');
                            })->orWhere(function ($missingOut): void {
                                $missingOut->whereNull('attendance_corrections.time_out')
                                    ->whereNotNull('attendance_corrections.time_in')
                                    ->whereRaw('0 = 1');
                            })->orWhere(function ($both): void {
                                $both->whereNotNull('attendance_corrections.time_in')
                                    ->whereNotNull('attendance_corrections.time_out')
                                    ->whereColumn('attendance_corrections.time_out', '>', 'attendance_corrections.time_in');
                            });
                        });
                });
            })
            ->distinct();

        return $query;
    }

    public function canBulkApprove(User $actor, AttendanceCorrection $correction): bool
    {
        if (! $this->approvalService->canApprove($actor, $correction)) {
            return false;
        }

        $employee = $correction->user;
        if (! $employee) {
            return false;
        }

        $requestor = $correction->relationLoaded('filedBy') ? $correction->filedBy : null;
        $pending = $this->approvalWorkflowService->currentPendingRecord(
            $correction,
            OrgApprovalWorkflowService::MODULE_ATTENDANCE_CORRECTION,
            $employee,
            $requestor,
        );

        if ($pending?->approver_role !== HrRole::AdminHr->value) {
            return true;
        }

        return $correction->hasRequiredTimesForFinalApproval();
    }
}
