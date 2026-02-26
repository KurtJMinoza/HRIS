<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Overtime;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class OvertimeController extends Controller
{
    /**
     * List overtime records with filters and summary aggregates.
     *
     * Filters:
     * - from_date, to_date (YYYY-MM-DD)
     * - department (string)
     * - employee_id (int)
     * - status (pending|approved|rejected)
     * - ot_type (string)
     */
    public function index(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'from_date' => ['nullable', 'date'],
            'to_date' => ['nullable', 'date'],
            'department' => ['nullable', 'string', 'max:255'],
            'employee_id' => ['nullable', 'integer', 'exists:users,id'],
            'status' => ['nullable', 'string', 'in:pending,approved,rejected'],
            'ot_type' => ['nullable', 'string', 'max:50'],
            'per_page' => ['nullable', 'integer', 'min:1', 'max:200'],
        ]);

        $from = isset($validated['from_date'])
            ? Carbon::parse($validated['from_date'])->startOfDay()
            : null;
        $to = isset($validated['to_date'])
            ? Carbon::parse($validated['to_date'])->endOfDay()
            : null;

        if ($from && $to && $to->lessThan($from)) {
            [$from, $to] = [$to->copy()->startOfDay(), $from->copy()->endOfDay()];
        }

        $query = Overtime::query()
            ->with(['user:id,name,department,profile_image'])
            ->orderByDesc('date')
            ->orderByDesc('id');

        if ($from) {
            $query->whereDate('date', '>=', $from->toDateString());
        }
        if ($to) {
            $query->whereDate('date', '<=', $to->toDateString());
        }
        if (! empty($validated['department'])) {
            $dept = $validated['department'];
            $query->whereHas('user', function ($q) use ($dept) {
                $q->where('department', $dept);
            });
        }
        if (! empty($validated['employee_id'])) {
            $query->where('user_id', $validated['employee_id']);
        }
        if (! empty($validated['status'])) {
            $query->where('status', $validated['status']);
        }
        if (! empty($validated['ot_type'])) {
            $query->where('ot_type', $validated['ot_type']);
        }

        $perPage = (int) ($validated['per_page'] ?? 50);
        $perPage = $perPage > 0 ? $perPage : 50;

        $paginator = $query->paginate($perPage)->withQueryString();

        $items = $paginator->getCollection()->map(function (Overtime $o) {
            $user = $o->user;
            return [
                'id' => $o->id,
                'employee_id' => $o->user_id,
                'employee_name' => $user?->name,
                'employee_profile_image' => $user?->profile_image ? asset('storage/' . $user->profile_image) : null,
                'department' => $user?->department,
                'date' => $o->date?->toDateString(),
                'schedule_end' => $o->schedule_end?->format('H:i'),
                'time_out' => $o->time_out?->format('H:i'),
                'expected_end_time' => $o->expected_end_time?->format('H:i'),
                'computed_hours' => $o->computed_hours,
                'computed_minutes' => $o->computed_minutes,
                'ot_type' => $o->ot_type,
                'status' => $o->status,
                'remarks' => $o->remarks,
                'locked_at' => $o->locked_at?->toIso8601String(),
                'created_at' => $o->created_at?->toIso8601String(),
            ];
        })->values();

        // Summary aggregates across the filtered set (ignores pagination).
        $allForSummary = (clone $query)->get();
        $today = today()->toDateString();
        $startOfMonth = today()->startOfMonth()->toDateString();

        $totalOtTodayHours = $allForSummary
            ->where('date', $today)
            ->sum('computed_hours');

        $pendingCount = $allForSummary
            ->where('status', Overtime::STATUS_PENDING)
            ->count();

        $approvedThisMonthHours = $allForSummary
            ->filter(function (Overtime $o) use ($startOfMonth) {
                return $o->status === Overtime::STATUS_APPROVED
                    && $o->date
                    && $o->date->toDateString() >= $startOfMonth;
            })
            ->sum('computed_hours');

        $topEmployees = $allForSummary
            ->groupBy('user_id')
            ->map(function ($rows, $userId) {
                /** @var \Illuminate\Support\Collection<int, Overtime> $rows */
                $first = $rows->first();
                $totalHours = $rows->sum('computed_hours');
                $user = $first->user;

                return [
                    'employee_id' => (int) $userId,
                    'employee_name' => $user?->name,
                    'department' => $user?->department,
                    'total_hours' => round((float) $totalHours, 2),
                ];
            })
            ->sortByDesc('total_hours')
            ->values()
            ->take(5)
            ->all();

        $approvedTotal = $allForSummary
            ->where('status', Overtime::STATUS_APPROVED)
            ->sum('computed_hours');
        $pendingTotal = $allForSummary
            ->where('status', Overtime::STATUS_PENDING)
            ->sum('computed_hours');

        $monthlySummary = $allForSummary
            ->groupBy(function (Overtime $o) {
                return $o->date ? $o->date->format('Y-m') : null;
            })
            ->filter(fn ($_, $monthKey) => $monthKey !== null)
            ->map(function ($rows, $monthKey) {
                /** @var \Illuminate\Support\Collection<int, Overtime> $rows */
                $month = Carbon::createFromFormat('Y-m', (string) $monthKey);
                $approvedHours = $rows
                    ->where('status', Overtime::STATUS_APPROVED)
                    ->sum('computed_hours');
                $pendingHours = $rows
                    ->where('status', Overtime::STATUS_PENDING)
                    ->sum('computed_hours');

                return [
                    'month' => $month->format('Y-m'),
                    'label' => $month->format('M Y'),
                    'approved_hours' => round((float) $approvedHours, 2),
                    'pending_hours' => round((float) $pendingHours, 2),
                    'record_count' => $rows->count(),
                ];
            })
            ->values()
            ->all();

        return response()->json([
            'overtimes' => $items,
            'pagination' => [
                'current_page' => $paginator->currentPage(),
                'per_page' => $paginator->perPage(),
                'total' => $paginator->total(),
                'last_page' => $paginator->lastPage(),
            ],
            'summary' => [
                'total_ot_today_hours' => round((float) $totalOtTodayHours, 2),
                'pending_requests' => $pendingCount,
                'approved_this_month_hours' => round((float) $approvedThisMonthHours, 2),
                'approved_total_hours' => round((float) $approvedTotal, 2),
                'pending_total_hours' => round((float) $pendingTotal, 2),
                'top_employees' => $topEmployees,
                'monthly_summary' => $monthlySummary,
            ],
        ]);
    }

    /**
     * Detailed overtime view including adjustment history.
     */
    public function show(int $id): JsonResponse
    {
        $overtime = Overtime::query()
            ->with([
                'user:id,name,department,profile_image',
                'adjustments.admin:id,name',
            ])
            ->findOrFail($id);

        $user = $overtime->user;

        $adjustments = $overtime->adjustments
            ->sortByDesc('created_at')
            ->map(function ($adj) {
                return [
                    'id' => $adj->id,
                    'original_minutes' => $adj->original_minutes,
                    'original_hours' => (float) $adj->original_hours,
                    'updated_minutes' => $adj->updated_minutes,
                    'updated_hours' => (float) $adj->updated_hours,
                    'reason' => $adj->reason,
                    'notes' => $adj->notes,
                    'admin_id' => $adj->admin_id,
                    'admin_name' => $adj->admin?->name,
                    'created_at' => $adj->created_at?->toIso8601String(),
                ];
            })
            ->values();

        return response()->json([
            'overtime' => [
                'id' => $overtime->id,
                'employee_id' => $overtime->user_id,
                'employee_name' => $user?->name,
                'employee_profile_image' => $user?->profile_image ? asset('storage/' . $user->profile_image) : null,
                'department' => $user?->department,
                'date' => $overtime->date?->toDateString(),
                'schedule_end' => $overtime->schedule_end?->format('H:i'),
                'time_out' => $overtime->time_out?->format('H:i'),
                'expected_end_time' => $overtime->expected_end_time?->format('H:i'),
                'computed_hours' => (float) $overtime->computed_hours,
                'computed_minutes' => $overtime->computed_minutes,
                'ot_type' => $overtime->ot_type,
                'status' => $overtime->status,
                'reason' => $overtime->reason,
                'attachment_url' => $overtime->attachment_path
                    ? asset('storage/' . $overtime->attachment_path)
                    : null,
                'remarks' => $overtime->remarks,
                'locked_at' => $overtime->locked_at?->toIso8601String(),
                'created_at' => $overtime->created_at?->toIso8601String(),
                'updated_at' => $overtime->updated_at?->toIso8601String(),
                'adjustments' => $adjustments,
            ],
        ]);
    }

    /**
     * Approve or reject an overtime entry.
     */
    public function updateStatus(Request $request, int $id): JsonResponse
    {
        $validated = $request->validate([
            'status' => ['required', 'string', 'in:approved,rejected'],
            'remarks' => ['nullable', 'string', 'max:2000'],
        ]);

        $overtime = Overtime::findOrFail($id);
        if ($overtime->status !== Overtime::STATUS_PENDING) {
            return response()->json([
                'message' => 'Only pending overtime records can be updated.',
            ], Response::HTTP_UNPROCESSABLE_ENTITY);
        }

        $status = $validated['status'];
        $remarks = $validated['remarks'] ?? null;

        $overtime->status = $status;
        if ($remarks !== null && $remarks !== '') {
            $overtime->remarks = $remarks;
        }
        $overtime->locked_at = now();
        $overtime->updated_by = $request->user()?->id;
        $overtime->save();

        return response()->json([
            'message' => 'Overtime status updated.',
            'overtime' => $this->mapOvertime($overtime->fresh('user')),
        ]);
    }

    /**
     * Manually adjust overtime hours for a pending record.
     * Logs the change in overtime_adjustments.
     */
    public function updateHours(Request $request, int $id): JsonResponse
    {
        $validated = $request->validate([
            'hours' => ['required', 'numeric', 'min:0'],
            'reason' => ['required', 'string', 'max:2000'],
            'notes' => ['nullable', 'string'],
        ]);

        $overtime = Overtime::findOrFail($id);
        if ($overtime->status !== Overtime::STATUS_PENDING) {
            return response()->json([
                'message' => 'Only pending overtime records can be adjusted.',
            ], Response::HTTP_UNPROCESSABLE_ENTITY);
        }

        $admin = $request->user();
        if (! $admin instanceof User || ! $admin->isAdmin()) {
            return response()->json([
                'message' => 'Only admins can adjust overtime.',
            ], Response::HTTP_FORBIDDEN);
        }

        $newHours = (float) $validated['hours'];
        $newMinutes = (int) round($newHours * 60);
        $newHours = round($newMinutes / 60, 2);

        $originalMinutes = (int) $overtime->computed_minutes;
        $originalHours = (float) $overtime->computed_hours;

        $overtime->adjustments()->create([
            'admin_id' => $admin->id,
            'original_minutes' => $originalMinutes,
            'original_hours' => $originalHours,
            'updated_minutes' => $newMinutes,
            'updated_hours' => $newHours,
            'reason' => $validated['reason'],
            'notes' => $validated['notes'] ?? null,
        ]);

        $overtime->computed_minutes = $newMinutes;
        $overtime->computed_hours = $newHours;
        $overtime->updated_by = $admin->id;
        $overtime->save();

        return response()->json([
            'message' => 'Overtime hours updated.',
            'overtime' => $this->mapOvertime($overtime->fresh('user')),
        ]);
    }

    /**
     * Export overtime records as CSV using the same filters as index().
     */
    public function export(Request $request)
    {
        $validated = $request->validate([
            'from_date' => ['nullable', 'date'],
            'to_date' => ['nullable', 'date'],
            'department' => ['nullable', 'string', 'max:255'],
            'employee_id' => ['nullable', 'integer', 'exists:users,id'],
            'status' => ['nullable', 'string', 'in:pending,approved,rejected'],
            'ot_type' => ['nullable', 'string', 'max:50'],
            'format' => ['nullable', 'string', 'in:csv'],
        ]);

        // Reuse the same query logic as index() but without pagination.
        $from = isset($validated['from_date'])
            ? Carbon::parse($validated['from_date'])->startOfDay()
            : null;
        $to = isset($validated['to_date'])
            ? Carbon::parse($validated['to_date'])->endOfDay()
            : null;

        if ($from && $to && $to->lessThan($from)) {
            [$from, $to] = [$to->copy()->startOfDay(), $from->copy()->endOfDay()];
        }

        $query = Overtime::query()->with(['user:id,name,department']);

        if ($from) {
            $query->whereDate('date', '>=', $from->toDateString());
        }
        if ($to) {
            $query->whereDate('date', '<=', $to->toDateString());
        }
        if (! empty($validated['department'])) {
            $dept = $validated['department'];
            $query->whereHas('user', function ($q) use ($dept) {
                $q->where('department', $dept);
            });
        }
        if (! empty($validated['employee_id'])) {
            $query->where('user_id', $validated['employee_id']);
        }
        if (! empty($validated['status'])) {
            $query->where('status', $validated['status']);
        }
        if (! empty($validated['ot_type'])) {
            $query->where('ot_type', $validated['ot_type']);
        }

        $rows = $query->orderBy('date')->orderBy('user_id')->get();

        $headers = [
            'Content-Type' => 'text/csv',
            'Content-Disposition' => 'attachment; filename="overtime-export-' . now()->format('Ymd_His') . '.csv"',
        ];

        $lines = [];
        $lines[] = implode(',', [
            'Employee ID',
            'Employee Name',
            'Department',
            'Date',
            'Schedule End',
            'Time Out',
            'Overtime Hours',
            'Overtime Minutes',
            'OT Type',
            'Status',
            'Remarks',
        ]);

        foreach ($rows as $o) {
            $user = $o->user;
            $line = [
                $user?->id,
                $user?->name,
                $user?->department,
                $o->date?->toDateString(),
                $o->schedule_end?->format('H:i'),
                $o->time_out?->format('H:i'),
                (float) $o->computed_hours,
                (int) $o->computed_minutes,
                $o->ot_type,
                $o->status,
                $o->remarks,
            ];

            $lines[] = $this->toCsvLine($line);
        }

        $content = implode("\n", $lines) . "\n";

        return response($content, 200, $headers);
    }

    private function toCsvLine(array $fields): string
    {
        return implode(',', array_map(function ($value) {
            $str = (string) $value;
            $str = str_replace('"', '""', $str);

            return '"' . $str . '"';
        }, $fields));
    }

    private function mapOvertime(Overtime $overtime): array
    {
        $user = $overtime->user;

        return [
            'id' => $overtime->id,
            'employee_id' => $overtime->user_id,
            'employee_name' => $user?->name,
            'employee_profile_image' => $user?->profile_image ? asset('storage/' . $user->profile_image) : null,
            'department' => $user?->department,
            'date' => $overtime->date?->toDateString(),
            'schedule_end' => $overtime->schedule_end?->format('H:i'),
            'time_out' => $overtime->time_out?->format('H:i'),
            'expected_end_time' => $overtime->expected_end_time?->format('H:i'),
            'computed_hours' => (float) $overtime->computed_hours,
            'computed_minutes' => $overtime->computed_minutes,
            'ot_type' => $overtime->ot_type,
            'status' => $overtime->status,
            'reason' => $overtime->reason,
            'attachment_url' => $overtime->attachment_path
                ? asset('storage/' . $overtime->attachment_path)
                : null,
            'remarks' => $overtime->remarks,
            'locked_at' => $overtime->locked_at?->toIso8601String(),
            'created_at' => $overtime->created_at?->toIso8601String(),
            'updated_at' => $overtime->updated_at?->toIso8601String(),
        ];
    }
}

