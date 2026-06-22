<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\AttendanceDailySummary;
use App\Services\AttendanceCacheService;
use App\Services\DataScopeService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\Rule;

/**
 * Lightweight read-model endpoint for Admin Attendance.
 *
 * Reads from `attendance_daily_summaries` with DB-level pagination, search,
 * and filtering. Falls back to legacy AttendanceMonitoringController::index()
 * if the read model is empty for the requested range.
 */
class AttendanceListController extends Controller
{
    private const CACHE_TTL_SECONDS = 45;

    private const FILTERS_CACHE_TTL = 600;

    public function __construct(
        private readonly DataScopeService $dataScopeService,
    ) {}

    /**
     * GET /api/admin/attendance/list
     *
     * Returns only the columns needed for the admin attendance table.
     */
    public function index(Request $request): JsonResponse
    {
        $startMs = microtime(true);

        $validated = $request->validate([
            'from_date' => ['nullable', 'date'],
            'to_date' => ['nullable', 'date'],
            'date' => ['nullable', 'date'],
            'company_id' => ['nullable', 'integer'],
            'branch_id' => ['nullable', 'integer'],
            'department_id' => ['nullable', 'integer'],
            'department' => ['nullable', 'string', 'max:255'],
            'company' => ['nullable', 'string', 'max:255'],
            'status' => ['nullable', 'string', 'in:present,late,absent,halfday,undertime,incomplete,rest,holiday,leave'],
            'premium_type' => ['nullable', 'string', 'in:ordinary,rest_day,special_holiday,regular_holiday,special_holiday_rest_day,regular_holiday_rest_day'],
            'search' => ['nullable', 'string', 'max:200'],
            'page' => ['nullable', 'integer', 'min:1'],
            'per_page' => ['nullable', 'integer', Rule::in([25, 50, 100])],
            'pending_attention' => ['sometimes', 'boolean'],
            'employee_id' => ['nullable', 'integer'],
        ]);

        $perPage = (int) ($validated['per_page'] ?? 25);
        $page = max(1, (int) ($validated['page'] ?? 1));

        $tz = config('attendance.timezone', config('app.timezone', 'Asia/Manila'));
        $fromDate = $validated['from_date'] ?? $validated['to_date'] ?? $validated['date'] ?? now($tz)->toDateString();
        $toDate = $validated['to_date'] ?? $validated['from_date'] ?? $validated['date'] ?? $fromDate;

        if ($toDate < $fromDate) {
            [$fromDate, $toDate] = [$toDate, $fromDate];
        }

        $actor = $request->user();
        $scopedEmployeeIds = $this->dataScopeService->getScopedEmployeeIdsForUser($actor, 'attendance');

        $cacheKey = $this->buildCacheKey($actor->id, $validated, $fromDate, $toDate, $page, $perPage);
        $cached = Cache::get($cacheKey);
        if (is_array($cached)) {
            $cached['meta']['cache_hit'] = true;
            $cached['meta']['total_response_ms'] = (int) round((microtime(true) - $startMs) * 1000);
            return response()->json($cached);
        }

        $summaryCount = AttendanceDailySummary::query()
            ->whereBetween('date', [$fromDate, $toDate])
            ->when($scopedEmployeeIds !== null, fn ($q) => $q->whereIn('employee_id', $scopedEmployeeIds))
            ->count();

        if ($summaryCount === 0) {
            return $this->fallbackToLegacy($request);
        }

        $query = AttendanceDailySummary::query()
            ->select([
                'id',
                'employee_id',
                'employee_name',
                'employee_code',
                'position',
                'profile_image',
                'company_id',
                'company_name',
                'branch_id',
                'branch_name',
                'department_id',
                'department_name',
                'date',
                'day_name',
                'schedule_label',
                'schedule_in',
                'schedule_out',
                'time_in',
                'time_out',
                'formatted_time_in',
                'formatted_time_out',
                'time_out_next_day',
                'total_hours',
                'scheduled_regular_hours',
                'late_minutes',
                'undertime_minutes',
                'overtime_minutes',
                'approved_ot_hours',
                'payable_ot_hours',
                'rendered_ot_hours',
                'nd_hours',
                'overtime_pay',
                'night_differential_pay',
                'total_premium_pay',
                'premium_type',
                'status',
                'presence_label',
                'presence_issue',
                'overtime_status',
                'is_rest_day',
                'holiday_name',
                'holiday_type',
                'has_correction',
                'correction_approved',
                'has_approved_overtime',
                'payroll_impact_hours',
            ])
            ->whereBetween('date', [$fromDate, $toDate]);

        if ($scopedEmployeeIds !== null) {
            $query->whereIn('employee_id', $scopedEmployeeIds);
        }

        if (! empty($validated['company_id'])) {
            $query->where('company_id', (int) $validated['company_id']);
        }
        if (! empty($validated['company'])) {
            $query->where('company_name', trim($validated['company']));
        }
        if (! empty($validated['branch_id'])) {
            $query->where('branch_id', (int) $validated['branch_id']);
        }
        if (! empty($validated['department_id'])) {
            $query->where('department_id', (int) $validated['department_id']);
        }
        if (! empty($validated['department'])) {
            $query->where('department_name', trim($validated['department']));
        }
        if (! empty($validated['employee_id'])) {
            $query->where('employee_id', (int) $validated['employee_id']);
        }
        if (! empty($validated['status'])) {
            $query->where('status', $validated['status']);
        }
        if (! empty($validated['pending_attention'])) {
            $query->where(function ($q) {
                $q->where('status', 'incomplete')
                    ->orWhere('presence_issue', 'correction_pending')
                    ->orWhere(fn ($sub) => $sub->where('has_correction', true)->where('correction_approved', false));
            });
        }

        if (! empty($validated['search'])) {
            $needle = '%' . trim($validated['search']) . '%';
            $query->where(function ($q) use ($needle) {
                $q->where('employee_name', 'like', $needle)
                    ->orWhere('employee_code', 'like', $needle)
                    ->orWhere('company_name', 'like', $needle)
                    ->orWhere('department_name', 'like', $needle);
            });
        }

        $query->orderBy('date', 'desc')->orderBy('employee_name', 'asc');

        $paginator = $query->paginate($perPage, ['*'], 'page', $page);

        $rows = $paginator->getCollection()->map(fn (AttendanceDailySummary $s) => $this->formatRow($s))->values()->all();

        $totals = $this->computeTotals($rows);

        $response = [
            'from_date' => $fromDate,
            'to_date' => $toDate,
            'rows' => $rows,
            'meta' => [
                'current_page' => $paginator->currentPage(),
                'last_page' => $paginator->lastPage(),
                'per_page' => $paginator->perPage(),
                'total' => $paginator->total(),
                'totals' => $totals,
                'cache_hit' => false,
                'source' => 'read_model',
            ],
        ];

        Cache::put($cacheKey, $response, self::CACHE_TTL_SECONDS);

        $totalMs = (int) round((microtime(true) - $startMs) * 1000);
        $response['meta']['total_response_ms'] = $totalMs;

        Log::info('AttendanceListController: served from read model', [
            'actor_user_id' => (int) $actor->id,
            'from_date' => $fromDate,
            'to_date' => $toDate,
            'rows_returned' => count($rows),
            'total_rows' => $paginator->total(),
            'total_response_ms' => $totalMs,
        ]);

        return response()->json($response);
    }

    /**
     * GET /api/admin/attendance/{id}/details-lite
     *
     * Lazy-loaded heavy details for a single attendance day.
     */
    public function detailsLite(int $id): JsonResponse
    {
        $summary = AttendanceDailySummary::query()->findOrFail($id);

        $extra = is_array($summary->extra) ? $summary->extra : [];

        return response()->json([
            'id' => (int) $summary->id,
            'employee_id' => (int) $summary->employee_id,
            'employee_name' => $summary->employee_name,
            'employee_code' => $summary->employee_code,
            'position' => $summary->position,
            'profile_image' => $summary->profile_image,
            'company_name' => $summary->company_name,
            'department_name' => $summary->department_name,
            'date' => $summary->date?->toDateString(),
            'day_name' => $summary->day_name,
            'schedule_in' => $summary->schedule_in,
            'schedule_out' => $summary->schedule_out,
            'time_in' => $summary->time_in,
            'time_out' => $summary->time_out,
            'formatted_time_in' => $summary->formatted_time_in,
            'formatted_time_out' => $summary->formatted_time_out,
            'time_out_next_day' => $summary->time_out_next_day,
            'total_hours' => $summary->total_hours,
            'total_rendered_hours' => $summary->total_hours,
            'scheduled_regular_hours' => $summary->scheduled_regular_hours,
            'late_minutes' => $summary->late_minutes,
            'late_label' => $extra['late_label'] ?? null,
            'undertime_minutes' => $summary->undertime_minutes,
            'overtime_minutes' => $summary->overtime_minutes,
            'approved_overtime_hours' => $summary->approved_ot_hours,
            'payable_overtime_hours' => $summary->payable_ot_hours,
            'rendered_overtime_hours' => $summary->rendered_ot_hours,
            'actual_rendered_overtime_hours' => $summary->rendered_ot_hours,
            'unapproved_overtime_hours' => $extra['unapproved_overtime_hours'] ?? null,
            'ot_payable_basis' => $extra['ot_payable_basis'] ?? null,
            'overtime_reduction_reason' => $extra['overtime_reduction_reason'] ?? null,
            'night_hours' => $summary->nd_hours,
            'overtime_pay' => $summary->overtime_pay,
            'night_differential_pay' => $summary->night_differential_pay,
            'total_premium_pay' => $summary->total_premium_pay,
            'premium_type' => $summary->premium_type,
            'premium_description' => $extra['premium_description'] ?? null,
            'calculated_pay_factor' => $extra['calculated_pay_factor'] ?? null,
            'status' => $summary->status,
            'presence_label' => $summary->presence_label,
            'presence_issue' => $summary->presence_issue,
            'overtime_status' => $summary->overtime_status,
            'is_rest_day' => $summary->is_rest_day,
            'holiday_name' => $summary->holiday_name,
            'holiday_type' => $summary->holiday_type,
            'has_correction' => $summary->has_correction,
            'correction_id' => $extra['correction_id'] ?? null,
            'correction_approved' => $summary->correction_approved,
            'correction_remarks' => $extra['correction_remarks'] ?? null,
            'has_approved_overtime' => $summary->has_approved_overtime,
            'approved_ot_end_time' => $extra['approved_ot_end_time'] ?? null,
            'effective_expected_out' => $extra['effective_expected_out'] ?? null,
            'payroll_impact_hours' => $summary->payroll_impact_hours,
            'employee_formatted_name' => $extra['employee_formatted_name'] ?? $summary->employee_name,
            'employee_level' => $extra['employee_level'] ?? null,
            'employee_level_label' => $extra['employee_level_label'] ?? null,
        ]);
    }

    /**
     * GET /api/admin/attendance/filters
     *
     * Cached dropdown data for the attendance UI.
     */
    public function filters(Request $request): JsonResponse
    {
        $actor = $request->user();
        $cacheKey = 'attendance:filters:' . $actor->id;

        $data = Cache::remember($cacheKey, self::FILTERS_CACHE_TTL, function () use ($actor) {
            $scopedEmployeeIds = $this->dataScopeService->getScopedEmployeeIdsForUser($actor, 'attendance');

            $query = AttendanceDailySummary::query();
            if ($scopedEmployeeIds !== null) {
                $query->whereIn('employee_id', $scopedEmployeeIds);
            }

            $companies = (clone $query)->whereNotNull('company_name')
                ->distinct()->pluck('company_name')->sort()->values()->all();
            $branches = (clone $query)->whereNotNull('branch_name')
                ->distinct()->pluck('branch_name')->sort()->values()->all();
            $departments = (clone $query)->whereNotNull('department_name')
                ->distinct()->pluck('department_name')->sort()->values()->all();

            return [
                'companies' => $companies,
                'branches' => $branches,
                'departments' => $departments,
                'statuses' => ['present', 'late', 'absent', 'halfday', 'undertime', 'incomplete', 'rest', 'holiday', 'leave'],
            ];
        });

        return response()->json($data);
    }

    private function formatRow(AttendanceDailySummary $s): array
    {
        return [
            'id' => (int) $s->id,
            'employee_id' => (int) $s->employee_id,
            'employee_name' => $s->employee_name,
            'employee_code' => $s->employee_code,
            'profile_image' => $s->profile_image,
            'company_name' => $s->company_name,
            'department' => $s->department_name,
            'date' => $s->date?->toDateString(),
            'day_name' => $s->day_name,
            'schedule_in' => $s->schedule_in,
            'schedule_out' => $s->schedule_out,
            'time_in' => $s->time_in,
            'time_out' => $s->time_out,
            'formatted_time_in' => $s->formatted_time_in,
            'formatted_time_out' => $s->formatted_time_out,
            'time_out_next_day' => $s->time_out_next_day,
            'total_rendered_hours' => $s->total_hours,
            'total_hours' => $s->total_hours,
            'scheduled_regular_hours' => $s->scheduled_regular_hours,
            'late_minutes' => $s->late_minutes,
            'undertime_minutes' => $s->undertime_minutes,
            'overtime_minutes' => $s->overtime_minutes,
            'approved_overtime_hours' => $s->approved_ot_hours,
            'payable_overtime_hours' => $s->payable_ot_hours,
            'rendered_overtime_hours' => $s->rendered_ot_hours,
            'night_hours' => $s->nd_hours,
            'overtime_pay' => $s->overtime_pay,
            'night_differential_pay' => $s->night_differential_pay,
            'total_premium_pay' => $s->total_premium_pay,
            'premium_type' => $s->premium_type,
            'status' => $s->status,
            'presence_label' => $s->presence_label,
            'presence_issue' => $s->presence_issue,
            'overtime_status' => $s->overtime_status,
            'is_rest_day' => $s->is_rest_day,
            'holiday_name' => $s->holiday_name,
            'holiday_type' => $s->holiday_type,
            'schedule_label' => $s->schedule_label,
            'has_correction' => $s->has_correction,
            'correction_approved' => $s->correction_approved,
            'has_approved_overtime' => $s->has_approved_overtime,
            'payroll_impact_hours' => $s->payroll_impact_hours,
        ];
    }

    private function computeTotals(array $rows): array
    {
        $present = 0;
        $absent = 0;
        $late = 0;
        $leaveOrHalfday = 0;
        $restDay = 0;
        $holiday = 0;
        $totalHours = 0.0;

        foreach ($rows as $r) {
            $status = $r['status'] ?? '';
            match ($status) {
                'present' => $present++,
                'absent' => $absent++,
                'late' => $late++,
                'leave', 'halfday' => $leaveOrHalfday++,
                'rest' => $restDay++,
                'holiday' => $holiday++,
                default => null,
            };
            $totalHours += (float) ($r['total_rendered_hours'] ?? $r['total_hours'] ?? 0);
        }

        return [
            'present_count' => $present,
            'absent_count' => $absent,
            'late_count' => $late,
            'leave_or_halfday_count' => $leaveOrHalfday,
            'rest_day_count' => $restDay,
            'holiday_count' => $holiday,
            'total_hours_rendered' => $totalHours,
        ];
    }

    private function buildCacheKey(int $userId, array $validated, string $from, string $to, int $page, int $perPage): string
    {
        $searchHash = substr(hash('xxh128', ($validated['search'] ?? '') . '|' . ($validated['status'] ?? '')), 0, 12);

        return sprintf(
            'attendance:list:%d:%s:%s:%s:%s:%s:%d:%d:%s:%s',
            $userId,
            $validated['company_id'] ?? '_',
            $validated['branch_id'] ?? '_',
            $validated['department_id'] ?? '_',
            $from,
            $to,
            $page,
            $perPage,
            $searchHash,
            ! empty($validated['pending_attention']) ? '1' : '0',
        );
    }

    private function fallbackToLegacy(Request $request): JsonResponse
    {
        $controller = app(AttendanceMonitoringController::class);

        return $controller->index($request);
    }
}
