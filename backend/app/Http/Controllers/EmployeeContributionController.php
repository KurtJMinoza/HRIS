<?php

namespace App\Http\Controllers;

use App\Models\EmployeeStatutoryContribution;
use App\Services\PayrollCalculatorService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class EmployeeContributionController extends Controller
{
    public function __construct(
        private readonly PayrollCalculatorService $calculator,
    ) {}

    public function mine(Request $request): JsonResponse
    {
        $user = $request->user();
        $basicSalary = $this->calculator->resolveBasicSalaryForPayroll($user);
        $preview = $this->calculator->calculateAllStatutoryContributions($basicSalary);

        $history = EmployeeStatutoryContribution::query()
            ->where('employee_id', $user->id)
            ->orderByDesc('period_year')
            ->orderByDesc('period_month')
            ->orderBy('type')
            ->limit(24)
            ->get();

        return response()->json([
            'basic_salary' => $basicSalary,
            'preview' => $preview,
            'history' => $history,
        ]);
    }
}
