<?php

namespace App\Http\Controllers;

use App\Models\Payslip;
use App\Models\User;
use App\Services\PayslipService;
use App\Support\PayslipStoredSnapshotViewPayload;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

/**
 * Employee self-service payslips — own records only (`user_id` === auth id).
 * Org heads use the same endpoints for their own employee record.
 *
 * Visibility requires HR “Send payslips” ({@see Payslip::$is_sent} / {@see Payslip::$sent_at} via {@see PayslipService::sendPayslip}),
 * not merely finalized PDF generation.
 */
class EmployeePayslipController extends Controller
{
    public function __construct(
        private readonly PayslipService $payslipService,
    ) {}

    private function ensureSelfServiceAccess(Request $request): void
    {
        abort_unless($request->user()?->canAccessSelfServiceEmployeeProfile(), 403);
    }

    public function index(Request $request): JsonResponse
    {
        $this->ensureSelfServiceAccess($request);
        $user = $request->user();
        $v = $request->validate([
            'per_page' => ['nullable', 'integer', 'min:1', 'max:50'],
            'page' => ['nullable', 'integer', 'min:1'],
            'from_date' => ['nullable', 'date'],
            'to_date' => ['nullable', 'date', 'after_or_equal:from_date'],
            /** `all` = any published; other values filter to that status (delivered payslips only). */
            'status' => ['nullable', 'string', 'in:all,finalized,generated,emailed,sent_finalized,viewed'],
        ]);

        $published = Payslip::lockingStatuses();

        $q = Payslip::query()
            ->where('user_id', $user->id)
            ->whereIn('status', $published)
            ->where(function ($sub) {
                $sub->where('is_sent', true)
                    ->orWhereNotNull('delivered_at');
            });

        if (! empty($v['from_date'])) {
            $q->whereDate('pay_period_end', '>=', (string) $v['from_date']);
        }
        if (! empty($v['to_date'])) {
            $q->whereDate('pay_period_start', '<=', (string) $v['to_date']);
        }
        $st = (string) ($v['status'] ?? 'all');
        if ($st !== 'all' && in_array($st, $published, true)) {
            $q->where('status', $st);
        }

        $paginated = $q->orderByDesc('pay_period_end')->paginate((int) ($v['per_page'] ?? 15));

        return response()->json($paginated);
    }

    public function show(Request $request, int $id): JsonResponse
    {
        $this->ensureSelfServiceAccess($request);
        $user = $request->user();
        $payslip = Payslip::query()->where('user_id', $user->id)->findOrFail($id);
        abort_if($payslip->status === Payslip::STATUS_DRAFT, 404);
        abort_unless($payslip->is_sent || $payslip->delivered_at !== null, 404);

        return response()->json($payslip);
    }

    /**
     * Full preview JSON (stored snapshot) — same shape as GET /admin/payslips/{id}/data for finalized rows.
     */
    public function viewData(Request $request, int $id): JsonResponse
    {
        $this->ensureSelfServiceAccess($request);
        $user = $request->user();
        $payslip = Payslip::query()
            ->with(['employee.company', 'employee.branch', 'employee.departmentRelation', 'employee.governmentIds', 'company'])
            ->where('user_id', $user->id)
            ->findOrFail($id);

        abort_if($payslip->status === Payslip::STATUS_DRAFT, 404);
        abort_unless($payslip->is_sent || $payslip->delivered_at !== null, 404);

        $employee = $payslip->employee;
        abort_unless($employee instanceof User, 404);

        $company = $payslip->company ?? $employee->company;

        return response()->json(PayslipStoredSnapshotViewPayload::fromStoredPayslip(
            $payslip,
            $employee,
            $this->payslipService,
            $this->publicCompanyLogoUrl($company?->logo)
        ));
    }

    public function download(Request $request, int $id): BinaryFileResponse
    {
        $this->ensureSelfServiceAccess($request);
        $user = $request->user();
        $payslip = Payslip::query()->where('user_id', $user->id)->findOrFail($id);
        abort_if($payslip->status === Payslip::STATUS_DRAFT, 404);
        abort_unless($payslip->is_sent || $payslip->delivered_at !== null, 404);
        // Regenerate so employee downloads use the same clean template as admin preview/download.
        $relative = $this->payslipService->generatePdf($payslip, $user);
        $payslip->update(['pdf_path' => $relative]);

        // Keep status as finalized / sent_finalized for HR reporting (Finalize Payroll shows a single “Finalized” label).
        // Do not overwrite to `viewed` on download.
        $employeeCode = trim((string) ($user->employee_code ?? ''));
        $filename = $employeeCode !== ''
            ? 'Payslip-'.$employeeCode.'.pdf'
            : 'payslip-'.$payslip->id.'.pdf';

        return response()->download(storage_path('app/private/'.$relative), $filename, ['Content-Type' => 'application/pdf']);
    }

    /**
     * Optional print-friendly PDF (larger margins via template flag).
     */
    public function downloadPrint(Request $request, int $id): BinaryFileResponse
    {
        $this->ensureSelfServiceAccess($request);
        $user = $request->user();
        $payslip = Payslip::query()->where('user_id', $user->id)->findOrFail($id);
        abort_if($payslip->status === Payslip::STATUS_DRAFT, 404);
        abort_unless($payslip->is_sent || $payslip->delivered_at !== null, 404);
        $relative = $this->payslipService->generatePrintPdf($payslip, $user);
        $full = storage_path('app/private/'.$relative);

        return response()->download($full, 'payslip-print-'.$payslip->id.'.pdf', ['Content-Type' => 'application/pdf']);
    }

    private function encodeStoragePath(string $path): string
    {
        $segments = explode('/', trim($path, '/'));
        $encoded = array_map(static fn (string $segment) => rawurlencode($segment), $segments);

        return implode('/', $encoded);
    }

    private function publicCompanyLogoUrl(?string $path): ?string
    {
        if (! is_string($path) || trim($path) === '') {
            return null;
        }
        $normalized = trim($path);
        if (str_starts_with($normalized, 'http://') || str_starts_with($normalized, 'https://')) {
            return $normalized;
        }
        $normalized = ltrim($normalized, '/');
        if (str_starts_with($normalized, 'storage/')) {
            $normalized = ltrim(substr($normalized, strlen('storage/')), '/');
        }

        return '/api/media/public/'.$this->encodeStoragePath($normalized);
    }
}
