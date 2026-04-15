<?php

namespace App\Services;

use App\Models\EmployeeGovernmentLoan;
use App\Models\StatutoryRemittance;
use App\Models\User;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\Schema;

/**
 * Statutory remittance batch builder (Philippines): SSS R-3 style listings, PhilHealth RF1 line data,
 * Pag-IBIG MCRF-style rows, BIR withholding summary stubs.
 *
 * Official PDF/XML formats (e-SRS, ePHIC, etc.) require agency portals — we export structured JSON/CSV-ready
 * payloads for HR review and third-party filing tools.
 */
class RemittanceService
{
    public function __construct(
        private readonly PayrollCalculatorService $calculator,
        private readonly DataScopeService $dataScopeService,
    ) {}

    /**
     * Aggregate estimated employee deductions and employer liabilities for in-scope active employees.
     * Uses {@see PayrollCalculatorService::calculateAllStatutoryContributions()} per employee (same basis as Compliance Audit).
     */
    public function buildDashboardSummary(\Illuminate\Contracts\Auth\Authenticatable $actor, ?int $companyId = null): array
    {
        $user = $actor instanceof User ? $actor : null;
        if ($user === null) {
            return $this->emptyDashboard();
        }

        $query = User::query()
            ->whereIn('role', User::ROSTER_ELIGIBLE_ROLES)
            ->where('is_active', true);

        $this->dataScopeService->restrictEmployeeQuery($user, $query);

        if ($companyId !== null) {
            $query->where(function (Builder $q) use ($companyId) {
                $q->where('company_id', $companyId)
                    ->orWhereHas('branch', fn (Builder $b) => $b->where('company_id', $companyId))
                    ->orWhereHas('departmentRelation', fn (Builder $d) => $d->whereHas('branch', fn (Builder $b) => $b->where('company_id', $companyId)));
            });
        }

        $employees = $query->get(['id', 'name', 'employee_code', 'monthly_salary', 'monthly_rate', 'daily_rate']);

        $sumEe = 0.0;
        $sumEr = 0.0;
        $sumWht = 0.0;
        $headcount = 0;

        foreach ($employees as $emp) {
            $basic = $this->calculator->resolveBasicSalaryForPayroll($emp);
            if ($basic <= 0) {
                continue;
            }
            $headcount++;
            $stat = $this->calculator->calculateAllStatutoryContributions($basic);
            $sumEe += (float) ($stat['totals']['employee_deduction'] ?? 0);
            $sumEr += (float) ($stat['totals']['employer_liability'] ?? 0);

            $wht = $this->calculator->calculateWithholdingTax([
                'monthly_taxable_compensation' => $basic,
                'method' => 'annualized',
                'period_type' => 'monthly',
            ]);
            $sumWht += (float) ($wht['withholding_per_month'] ?? 0);
        }

        $pending = $this->pendingRemittanceCounts($companyId);

        return [
            'period_label' => now()->timezone(config('app.timezone', 'Asia/Manila'))->format('F Y'),
            'headcount_included' => $headcount,
            'estimated_total_employee_statutory' => round($sumEe, 2),
            'estimated_total_employer_liability' => round($sumEr, 2),
            'estimated_total_withholding_tax_monthly' => round($sumWht, 2),
            'pending_remittances' => $pending,
            'disclaimer' => 'Estimates use current salary fields and statutory tables; actual payroll may differ (OT, loans, adjustments).',
        ];
    }

    /**
     * @return array<string, int>
     */
    public function pendingRemittanceCounts(?int $companyId = null): array
    {
        if (! Schema::hasTable('statutory_remittances')) {
            return [
                'SSS' => 0,
                'PHILHEALTH' => 0,
                'PAGIBIG' => 0,
                'BIR' => 0,
            ];
        }

        $q = StatutoryRemittance::query()->where('status', 'pending');
        if ($companyId !== null) {
            $q->where(function (Builder $x) use ($companyId) {
                $x->whereNull('company_id')->orWhere('company_id', $companyId);
            });
        }

        $counts = $q->selectRaw('agency, count(*) as c')
            ->groupBy('agency')
            ->pluck('c', 'agency')
            ->all();

        return [
            'SSS' => (int) ($counts['SSS'] ?? 0),
            'PHILHEALTH' => (int) ($counts['PHILHEALTH'] ?? 0),
            'PAGIBIG' => (int) ($counts['PAGIBIG'] ?? 0),
            'BIR' => (int) ($counts['BIR'] ?? 0),
        ];
    }

    /**
     * @param  array{agency?: string, status?: string, year?: int, month?: int, page?: int, per_page?: int, company_id?: int}  $filters
     * @return LengthAwarePaginator<int, StatutoryRemittance>
     */
    public function listRemittances(array $filters): LengthAwarePaginator
    {
        $page = max(1, (int) ($filters['page'] ?? 1));
        $perPage = min(100, max(5, (int) ($filters['per_page'] ?? 20)));

        $query = StatutoryRemittance::query()->orderByDesc('period_year')->orderByDesc('period_month')->orderByDesc('id');

        if (! empty($filters['company_id'])) {
            $query->where(function (Builder $q) use ($filters) {
                $q->whereNull('company_id')->orWhere('company_id', (int) $filters['company_id']);
            });
        }

        if (! empty($filters['agency'])) {
            $query->where('agency', strtoupper((string) $filters['agency']));
        }
        if (! empty($filters['status'])) {
            $query->where('status', (string) $filters['status']);
        }
        if (! empty($filters['year'])) {
            $query->where('period_year', (int) $filters['year']);
        }
        if (! empty($filters['month'])) {
            $query->where('period_month', (int) $filters['month']);
        }

        return $query->paginate($perPage, ['*'], 'page', $page);
    }

    /**
     * Build remittance payload and persist a batch row.
     *
     * @return array{remittance: StatutoryRemittance, rows: array<int, array<string, mixed>>, notes: string[]}
     */
    public function generateRemittanceBatch(
        User $actor,
        string $agency,
        string $reportKind,
        int $periodYear,
        int $periodMonth,
        ?int $companyId = null
    ): array {
        $agency = strtoupper(trim($agency));
        if (! in_array($agency, ['SSS', 'PHILHEALTH', 'PAGIBIG', 'BIR'], true)) {
            throw new \InvalidArgumentException('Invalid agency.');
        }

        $notes = [
            'Official filing may require the agency e-portal (SSS e-SRS, PhilHealth ERF, Pag-IBIG Employer Online, BIR eFPS).',
        ];

        $query = User::query()
            ->whereIn('role', User::ROSTER_ELIGIBLE_ROLES)
            ->where('is_active', true);
        $this->dataScopeService->restrictEmployeeQuery($actor, $query);

        if ($companyId !== null) {
            $query->where(function (Builder $q) use ($companyId) {
                $q->where('company_id', $companyId)
                    ->orWhereHas('branch', fn (Builder $b) => $b->where('company_id', $companyId))
                    ->orWhereHas('departmentRelation', fn (Builder $d) => $d->whereHas('branch', fn (Builder $b) => $b->where('company_id', $companyId)));
            });
        }

        $employees = $query->orderBy('employee_code')->get(['id', 'name', 'employee_code', 'monthly_salary', 'monthly_rate', 'daily_rate']);

        $rows = [];
        $totalEe = 0.0;
        $totalEr = 0.0;

        foreach ($employees as $emp) {
            $basic = $this->calculator->resolveBasicSalaryForPayroll($emp);
            if ($basic <= 0) {
                continue;
            }

            $stat = $this->calculator->calculateAllStatutoryContributions($basic);
            $sss = $stat['sss'] ?? [];
            $ph = $stat['philhealth'] ?? [];
            $hdmf = $stat['pagibig'] ?? [];

            if ($agency === 'SSS' && in_array($reportKind, ['r3', 'r5', 'monthly_listing'], true)) {
                $rows[] = [
                    'employee_id' => $emp->id,
                    'employee_code' => $emp->employee_code,
                    'name' => $emp->name,
                    'monthly_salary' => round($basic, 2),
                    'msc' => $sss['msc_used'] ?? null,
                    'employee_ss' => $sss['employee_amount'] ?? 0,
                    'employer_ss' => $sss['employer_amount'] ?? 0,
                    'ec' => $sss['ec_amount'] ?? 0,
                ];
                $totalEe += (float) ($sss['employee_amount'] ?? 0);
                $totalEr += (float) (($sss['employer_amount'] ?? 0) + ($sss['ec_amount'] ?? 0));
            } elseif ($agency === 'PHILHEALTH' && in_array($reportKind, ['rf1', 'premium_listing'], true)) {
                $rows[] = [
                    'employee_id' => $emp->id,
                    'employee_code' => $emp->employee_code,
                    'name' => $emp->name,
                    'monthly_salary' => round($basic, 2),
                    'premium_base' => $ph['metadata']['applied_salary'] ?? null,
                    'employee_share' => $ph['employee_amount'] ?? 0,
                    'employer_share' => $ph['employer_amount'] ?? 0,
                ];
                $totalEe += (float) ($ph['employee_amount'] ?? 0);
                $totalEr += (float) ($ph['employer_amount'] ?? 0);
            } elseif ($agency === 'PAGIBIG' && in_array($reportKind, ['mcrf', 'monthly_listing'], true)) {
                $rows[] = [
                    'employee_id' => $emp->id,
                    'employee_code' => $emp->employee_code,
                    'name' => $emp->name,
                    'monthly_salary' => round($basic, 2),
                    'employee_share' => $hdmf['employee_amount'] ?? 0,
                    'employer_share' => $hdmf['employer_amount'] ?? 0,
                ];
                $totalEe += (float) ($hdmf['employee_amount'] ?? 0);
                $totalEr += (float) ($hdmf['employer_amount'] ?? 0);
            } elseif ($agency === 'BIR') {
                $wht = $this->calculator->calculateWithholdingTax([
                    'monthly_taxable_compensation' => $basic,
                    'method' => 'annualized',
                    'period_type' => 'monthly',
                ]);
                $rows[] = [
                    'employee_id' => $emp->id,
                    'employee_code' => $emp->employee_code,
                    'name' => $emp->name,
                    'gross_taxable_monthly' => $wht['gross_monthly_taxable_compensation'] ?? $basic,
                    'taxable_monthly' => $wht['monthly_taxable_compensation'] ?? $basic,
                    'withholding_tax_monthly' => $wht['withholding_per_month'] ?? 0,
                ];
                $totalEe += (float) ($wht['withholding_per_month'] ?? 0);
                $totalEr += 0.0;
            }
        }

        if ($rows === [] && $agency !== 'BIR') {
            $notes[] = 'No employees with positive basic salary in scope — empty listing.';
        }

        $remittance = StatutoryRemittance::query()->create([
            'company_id' => $companyId,
            'period_year' => $periodYear,
            'period_month' => $periodMonth,
            'agency' => $agency,
            'report_kind' => $reportKind,
            'status' => 'pending',
            'payload' => [
                'generated_at' => now()->toIso8601String(),
                'row_count' => count($rows),
                'rows' => $rows,
            ],
            'total_employee_amount' => round($totalEe, 2),
            'total_employer_amount' => round($totalEr, 2),
            'generated_by_user_id' => $actor->id,
        ]);

        return [
            'remittance' => $remittance,
            'rows' => $rows,
            'notes' => $notes,
        ];
    }

    public function sumActiveGovernmentLoans(int $userId): array
    {
        if (! Schema::hasTable('employee_government_loans')) {
            return [
                'sss_monthly' => 0.0,
                'pagibig_monthly' => 0.0,
                'total_monthly' => 0.0,
            ];
        }

        $lines = EmployeeGovernmentLoan::query()
            ->where('user_id', $userId)
            ->where('is_active', true)
            ->get(['agency', 'monthly_amortization']);

        $sss = 0.0;
        $pag = 0.0;
        foreach ($lines as $line) {
            $amt = (float) $line->monthly_amortization;
            if (strtoupper((string) $line->agency) === 'SSS') {
                $sss += $amt;
            } elseif (strtoupper((string) $line->agency) === 'PAGIBIG') {
                $pag += $amt;
            }
        }

        return [
            'sss_monthly' => round($sss, 2),
            'pagibig_monthly' => round($pag, 2),
            'total_monthly' => round($sss + $pag, 2),
        ];
    }

    private function emptyDashboard(): array
    {
        return [
            'period_label' => '',
            'headcount_included' => 0,
            'estimated_total_employee_statutory' => 0.0,
            'estimated_total_employer_liability' => 0.0,
            'estimated_total_withholding_tax_monthly' => 0.0,
            'pending_remittances' => [
                'SSS' => 0,
                'PHILHEALTH' => 0,
                'PAGIBIG' => 0,
                'BIR' => 0,
            ],
            'disclaimer' => '',
        ];
    }
}
