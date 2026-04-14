<?php

namespace App\Http\Controllers;

use App\Services\PremiumReportService;
use Carbon\Carbon;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Premium Pay Report – Reports-First MVP.
 *
 * Employee: GET /reports/premiums (own data)
 * Admin: GET /admin/reports/premiums (filter by employee, date range)
 */
class PremiumReportController extends Controller
{
    public function __construct(
        private readonly PremiumReportService $premiumReport,
    ) {}

    /**
     * Employee: Get own premium pay breakdown (ND, OT) for date range.
     */
    public function employee(Request $request): JsonResponse
    {
        $user = $request->user();
        if (! $user || $user->role !== 'employee') {
            return response()->json(['message' => 'Unauthorized'], 403);
        }

        $validated = $request->validate([
            'from_date' => ['required', 'date'],
            'to_date' => ['nullable', 'date'],
        ]);

        $from = Carbon::parse($validated['from_date'])->startOfDay();
        $to = isset($validated['to_date'])
            ? Carbon::parse($validated['to_date'])->endOfDay()
            : $from->copy()->endOfDay();

        if ($to->lessThan($from)) {
            [$from, $to] = [$to->copy()->startOfDay(), $from->copy()->endOfDay()];
        }

        $result = $this->premiumReport->computeForEmployee($user, $from, $to);

        return response()->json($result);
    }

    /**
     * Admin: Get premium pay breakdown for employees (filter by employee, company, date range).
     */
    public function admin(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'from_date' => ['required', 'date'],
            'to_date' => ['nullable', 'date'],
            'employee_id' => ['nullable', 'integer', 'exists:users,id'],
            'company_id' => ['nullable', 'integer', 'exists:companies,id'],
        ]);

        $from = Carbon::parse($validated['from_date'])->startOfDay();
        $to = isset($validated['to_date'])
            ? Carbon::parse($validated['to_date'])->endOfDay()
            : $from->copy()->endOfDay();

        if ($to->lessThan($from)) {
            [$from, $to] = [$to->copy()->startOfDay(), $from->copy()->endOfDay()];
        }

        $employeesQuery = \App\Models\User::query()
            ->where('role', \App\Models\User::ROLE_EMPLOYEE)
            ->where('is_active', true);

        if (! empty($validated['employee_id'])) {
            $employeesQuery->where('id', $validated['employee_id']);
        }

        if (! empty($validated['company_id'])) {
            $cid = (int) $validated['company_id'];
            $employeesQuery->where(function ($q) use ($cid) {
                $q->where('company_id', $cid)
                    ->orWhereHas('branch', fn ($b) => $b->where('company_id', $cid))
                    ->orWhereHas('departmentRelation', fn ($d) => $d->whereHas('branch', fn ($b) => $b->where('company_id', $cid)))
                    ->orWhereHas('companyHeadships', fn ($c) => $c->where('id', $cid));
            });
        }

        $this->dataScopeService->restrictEmployeeQuery($request->user(), $employeesQuery);

        $employees = $employeesQuery->orderBy('name')->get();

        $results = $this->premiumReport->computeForEmployees($employees, $from, $to);

        return response()->json([
            'from_date' => $from->toDateString(),
            'to_date' => $to->toDateString(),
            'employees' => $results,
        ]);
    }
}
