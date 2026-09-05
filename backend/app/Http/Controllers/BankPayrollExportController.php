<?php

namespace App\Http\Controllers;

use App\Models\PayrollBatchRun;
use App\Models\User;
use App\Models\UserAdminActivityLog;
use App\Services\BankPayrollExportService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

class BankPayrollExportController extends Controller
{
    public function __construct(
        private readonly BankPayrollExportService $bankPayrollExportService,
    ) {}

    public function cutoffs(Request $request): JsonResponse
    {
        $this->authorizedActor($request);

        return response()->json([
            'cutoffs' => $this->bankPayrollExportService->listFinalizedCutoffs(),
        ]);
    }

    public function previewByCutoff(Request $request, string $bank): JsonResponse
    {
        $actor = $this->authorizedActor($request);
        [$start, $end] = $this->validatedCutoffDates($request);
        $bankCode = $this->bankPayrollExportService->normalizeBankCode($bank);

        try {
            $payload = $this->bankPayrollExportService->buildExportPayloadForCutoffDates($start, $end, $bankCode);
        } catch (\RuntimeException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }

        $this->logCutoffAudit($request, $actor, $payload, 'bank_payroll_export_previewed', (int) $payload['eligible_count'], $bankCode);

        return response()->json([
            'bank' => $payload['bank'],
            'bank_label' => $payload['bank_label'],
            'eligible_count' => $payload['eligible_count'],
            'excluded_count' => $payload['excluded_count'],
            'excluded' => $payload['excluded'],
            'total_salary' => $payload['total_salary'],
            'company_count' => $payload['company_count'],
            'pay_period_start' => $payload['pay_period_start'],
            'pay_period_end' => $payload['pay_period_end'],
            'company_scope' => $payload['company_scope'],
        ]);
    }

    public function downloadXlsxByCutoff(Request $request, string $bank)
    {
        return $this->downloadByCutoff($request, $bank, 'xlsx');
    }

    public function downloadCsvByCutoff(Request $request, string $bank)
    {
        return $this->downloadByCutoff($request, $bank, 'csv');
    }

    public function downloadPdfByCutoff(Request $request, string $bank)
    {
        return $this->downloadByCutoff($request, $bank, 'pdf');
    }

    public function preview(Request $request, int $id, string $bank): JsonResponse
    {
        $actor = $this->authorizedActor($request);
        $run = PayrollBatchRun::query()->findOrFail($id);
        $bankCode = $this->bankPayrollExportService->normalizeBankCode($bank);

        try {
            $payload = $this->bankPayrollExportService->buildExportPayloadForCutoff($run, $bankCode);
        } catch (\RuntimeException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }

        $this->logCutoffAudit($request, $actor, $payload, 'bank_payroll_export_previewed', (int) $payload['eligible_count'], $bankCode, $run);

        return response()->json([
            'bank' => $payload['bank'],
            'bank_label' => $payload['bank_label'],
            'eligible_count' => $payload['eligible_count'],
            'excluded_count' => $payload['excluded_count'],
            'excluded' => $payload['excluded'],
            'total_salary' => $payload['total_salary'],
            'company_count' => $payload['company_count'],
            'pay_period_start' => $payload['pay_period_start'],
            'pay_period_end' => $payload['pay_period_end'],
            'company_scope' => $payload['company_scope'],
        ]);
    }

    public function downloadXlsx(Request $request, int $id, string $bank)
    {
        return $this->download($request, $id, $bank, 'xlsx');
    }

    public function downloadCsv(Request $request, int $id, string $bank)
    {
        return $this->download($request, $id, $bank, 'csv');
    }

    public function downloadPdf(Request $request, int $id, string $bank)
    {
        return $this->download($request, $id, $bank, 'pdf');
    }

    private function downloadByCutoff(Request $request, string $bank, string $format)
    {
        $actor = $this->authorizedActor($request);
        [$start, $end] = $this->validatedCutoffDates($request);
        $bankCode = $this->bankPayrollExportService->normalizeBankCode($bank);

        try {
            $payload = $this->bankPayrollExportService->buildExportPayloadForCutoffDates($start, $end, $bankCode);
            $result = match ($format) {
                'xlsx' => $this->bankPayrollExportService->xlsxForCutoffDates($start, $end, $bankCode),
                'csv' => $this->bankPayrollExportService->csvForCutoffDates($start, $end, $bankCode),
                'pdf' => $this->bankPayrollExportService->pdfForCutoffDates($start, $end, $bankCode),
                default => throw new \RuntimeException('Unsupported export format.'),
            };
        } catch (\RuntimeException $e) {
            abort(422, $e->getMessage());
        }

        $this->logCutoffAudit($request, $actor, $payload, 'bank_payroll_export_'.$format.'_downloaded', (int) $result['employee_count'], $bankCode);

        if ($format === 'pdf') {
            return $result['pdf']->download($result['filename']);
        }

        $contentType = $format === 'csv'
            ? 'text/csv; charset=UTF-8'
            : 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet';

        return response()->streamDownload($result['write'], $result['filename'], [
            'Content-Type' => $contentType,
            'Cache-Control' => 'no-store, no-cache, must-revalidate, max-age=0',
        ]);
    }

    private function download(Request $request, int $id, string $bank, string $format)
    {
        $actor = $this->authorizedActor($request);
        $run = PayrollBatchRun::query()->findOrFail($id);
        $bankCode = $this->bankPayrollExportService->normalizeBankCode($bank);

        try {
            $payload = $this->bankPayrollExportService->buildExportPayloadForCutoff($run, $bankCode);
            $result = match ($format) {
                'xlsx' => $this->bankPayrollExportService->xlsxForCutoff($run, $bankCode),
                'csv' => $this->bankPayrollExportService->csvForCutoff($run, $bankCode),
                'pdf' => $this->bankPayrollExportService->pdfForCutoff($run, $bankCode),
                default => throw new \RuntimeException('Unsupported export format.'),
            };
        } catch (\RuntimeException $e) {
            abort(422, $e->getMessage());
        }

        $this->logCutoffAudit($request, $actor, $payload, 'bank_payroll_export_'.$format.'_downloaded', (int) $result['employee_count'], $bankCode, $run);

        if ($format === 'pdf') {
            return $result['pdf']->download($result['filename']);
        }

        $contentType = $format === 'csv'
            ? 'text/csv; charset=UTF-8'
            : 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet';

        return response()->streamDownload($result['write'], $result['filename'], [
            'Content-Type' => $contentType,
            'Cache-Control' => 'no-store, no-cache, must-revalidate, max-age=0',
        ]);
    }

    /** @return array{0:string,1:string} */
    private function validatedCutoffDates(Request $request): array
    {
        $validated = $request->validate([
            'from_date' => ['required', 'date'],
            'to_date' => ['required', 'date', 'after_or_equal:from_date'],
        ]);

        return [
            (string) $validated['from_date'],
            (string) $validated['to_date'],
        ];
    }

    private function authorizedActor(Request $request): User
    {
        $actor = $request->user();
        abort_unless($actor instanceof User, 403);

        return $actor;
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    private function logCutoffAudit(
        Request $request,
        User $actor,
        array $payload,
        string $action,
        int $employeeCount,
        string $bankCode,
        ?PayrollBatchRun $run = null,
    ): void {
        UserAdminActivityLog::query()->create([
            'subject_user_id' => (int) $actor->id,
            'actor_user_id' => (int) $actor->id,
            'action' => $action,
            'meta' => [
                'bank_code' => $bankCode,
                'company_scope' => $payload['company_scope'] ?? 'All Companies',
                'company_count' => (int) ($payload['company_count'] ?? 0),
                'payroll_run_id' => $run?->id,
                'payroll_period_id' => $run?->payroll_period_id,
                'pay_period_start' => $payload['pay_period_start'] ?? null,
                'pay_period_end' => $payload['pay_period_end'] ?? null,
                'employee_count' => $employeeCount,
                'timestamp' => now()->toIso8601String(),
            ],
            'ip_address' => $request->ip(),
        ]);

        Log::info('Bank Payroll Export', [
            'action' => $action,
            'bank_code' => $bankCode,
            'actor_user_id' => (int) $actor->id,
            'payroll_run_id' => $run?->id,
            'employee_count' => $employeeCount,
            'company_count' => (int) ($payload['company_count'] ?? 0),
        ]);
    }
}
