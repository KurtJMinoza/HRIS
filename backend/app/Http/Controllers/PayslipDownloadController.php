<?php

namespace App\Http\Controllers;

use App\Models\Payslip;
use App\Models\User;
use App\Services\DataScopeService;
use App\Services\HrRoleResolver;
use App\Services\PayslipService;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

class PayslipDownloadController extends Controller
{
    public function __construct(
        private readonly PayslipService $payslipService,
        private readonly DataScopeService $dataScopeService,
        private readonly HrRoleResolver $hrRoleResolver,
    ) {}

    public function download(Request $request, int $id): BinaryFileResponse
    {
        $actor = $request->user();
        abort_unless($actor instanceof User, 403);

        $payslip = Payslip::query()
            ->with(['employee.company', 'employee.branch', 'employee.departmentRelation', 'employee.governmentIds'])
            ->findOrFail($id);

        $employee = $payslip->employee;
        abort_unless($employee instanceof User, 404);

        $isSelfDownload = (int) $employee->id === (int) $actor->id;
        if ($isSelfDownload) {
            // Keep self-service restrictions identical to EmployeePayslipController.
            abort_if($payslip->status === Payslip::STATUS_DRAFT, 404);
            abort_unless($payslip->is_sent || $payslip->delivered_at !== null, 404);
            abort_unless($actor->canAccessSelfServiceEmployeeProfile(), 403);
        } else {
            $canUseHrScope = $actor->isAdmin() || $this->hrRoleResolver->resolve($actor)->canAccessHrPanel();
            abort_unless($canUseHrScope, 403);
            $this->dataScopeService->ensureEmployeeAccessible($actor, $employee);
        }

        $relative = $this->payslipService->generatePdf($payslip, $employee);
        $payslip->update(['pdf_path' => $relative]);

        $employeeCode = trim((string) ($employee->employee_code ?? ''));
        $filename = $employeeCode !== ''
            ? 'Payslip-'.$employeeCode.'.pdf'
            : 'payslip-'.$payslip->id.'.pdf';

        return response()->download(
            storage_path('app/private/'.$relative),
            $filename,
            ['Content-Type' => 'application/pdf'],
        );
    }
}
