<?php

namespace App\Http\Controllers;

use App\Models\Company;
use App\Models\PayrollBatchRun;
use App\Models\User;
use App\Models\UserAdminActivityLog;
use App\Services\PayrollReportService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

class PayrollReportController extends Controller
{
    public function __construct(
        private readonly PayrollReportService $payrollReportService,
    ) {}

    public function downloadForRunCompany(Request $request, int $id, int $companyId)
    {
        $actor = $this->authorizedActor($request);
        $run = PayrollBatchRun::query()->findOrFail($id);
        $company = Company::query()->findOrFail($companyId);
        $this->ensureRunMatchesCompany($run, $company);

        return $this->download($request, $run, $company, $actor);
    }

    public function downloadFromReports(Request $request)
    {
        $actor = $this->authorizedActor($request);
        $validated = $request->validate([
            'company_id' => ['required', 'integer', 'exists:companies,id'],
            'payroll_run_id' => ['nullable', 'integer', 'exists:payroll_batch_runs,id', 'required_without:pay_period_id'],
            'pay_period_id' => ['nullable', 'integer', 'exists:payroll_periods,id', 'required_without:payroll_run_id'],
        ]);

        $company = Company::query()->findOrFail((int) $validated['company_id']);
        if (! empty($validated['payroll_run_id'])) {
            $run = PayrollBatchRun::query()->findOrFail((int) $validated['payroll_run_id']);
        } else {
            $run = PayrollBatchRun::query()
                ->where('company_id', (int) $company->id)
                ->where('payroll_period_id', (int) $validated['pay_period_id'])
                ->where('status', PayrollBatchRun::STATUS_FINALIZED)
                ->orderByDesc('finalized_at')
                ->orderByDesc('id')
                ->firstOrFail();
        }
        $this->ensureRunMatchesCompany($run, $company);

        return $this->download($request, $run, $company, $actor);
    }

    private function download(Request $request, PayrollBatchRun $run, Company $company, User $actor)
    {
        try {
            $result = $this->payrollReportService->pdfForRunCompany($run, $company, $actor);
        } catch (\RuntimeException $e) {
            abort(422, $e->getMessage());
        }

        $this->logAudit($request, $actor, $company, $run, 'payroll_report_viewed', (int) $result['employee_count']);
        $this->logAudit($request, $actor, $company, $run, 'payroll_report_downloaded', (int) $result['employee_count']);

        return $result['pdf']->download($result['filename']);
    }

    private function authorizedActor(Request $request): User
    {
        $actor = $request->user();
        abort_unless($actor instanceof User, 403);

        return $actor;
    }

    private function ensureRunMatchesCompany(PayrollBatchRun $run, Company $company): void
    {
        abort_unless((string) $run->status === PayrollBatchRun::STATUS_FINALIZED, 404);
        if ($run->company_id !== null) {
            abort_unless((int) $run->company_id === (int) $company->id, 404);
        }
    }

    private function logAudit(
        Request $request,
        User $actor,
        Company $company,
        PayrollBatchRun $run,
        string $action,
        int $employeeCount
    ): void {
        UserAdminActivityLog::query()->create([
            'subject_user_id' => (int) $actor->id,
            'actor_user_id' => (int) $actor->id,
            'action' => $action,
            'meta' => [
                'company_id' => (int) $company->id,
                'company_name' => (string) $company->name,
                'payroll_run_id' => (int) $run->id,
                'payroll_period_id' => $run->payroll_period_id !== null ? (int) $run->payroll_period_id : null,
                'pay_period_start' => $run->pay_period_start?->toDateString(),
                'pay_period_end' => $run->pay_period_end?->toDateString(),
                'employee_count' => $employeeCount,
                'timestamp' => now()->toIso8601String(),
            ],
            'ip_address' => $request->ip(),
        ]);

        Log::info('Payroll Report downloaded', [
            'action' => $action,
            'actor_user_id' => (int) $actor->id,
            'company_id' => (int) $company->id,
            'payroll_run_id' => (int) $run->id,
            'employee_count' => $employeeCount,
        ]);
    }
}
