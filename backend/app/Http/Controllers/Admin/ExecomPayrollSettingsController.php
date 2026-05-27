<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\ExecomPayrollSetting;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class ExecomPayrollSettingsController extends Controller
{
    public function show(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'company_id' => ['nullable', 'integer', 'exists:companies,id'],
        ]);
        $companyId = isset($validated['company_id']) ? (int) $validated['company_id'] : null;

        return response()->json([
            'settings' => $this->payload(ExecomPayrollSetting::forCompany($companyId)),
        ]);
    }

    public function update(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'company_id' => ['nullable', 'integer', 'exists:companies,id'],
            'apply_government_deductions' => ['sometimes', 'boolean'],
            'apply_custom_deductions' => ['sometimes', 'boolean'],
            'apply_allowances' => ['sometimes', 'boolean'],
            'allow_overtime' => ['sometimes', 'boolean'],
            'allow_holiday_pay' => ['sometimes', 'boolean'],
            'auto_present_attendance_reports' => ['sometimes', 'boolean'],
        ]);
        $companyId = isset($validated['company_id']) ? (int) $validated['company_id'] : null;
        $settings = ExecomPayrollSetting::query()->updateOrCreate(
            ['company_id' => $companyId],
            [
                ...ExecomPayrollSetting::defaults($companyId),
                ...$validated,
                'company_id' => $companyId,
                'updated_by' => $request->user()?->id,
            ]
        );

        return response()->json([
            'message' => 'EXECOM payroll settings updated.',
            'settings' => $this->payload($settings),
        ]);
    }

    private function payload(ExecomPayrollSetting $settings): array
    {
        return [
            'company_id' => $settings->company_id ? (int) $settings->company_id : null,
            'apply_government_deductions' => (bool) $settings->apply_government_deductions,
            'apply_custom_deductions' => (bool) $settings->apply_custom_deductions,
            'apply_allowances' => (bool) $settings->apply_allowances,
            'allow_overtime' => (bool) $settings->allow_overtime,
            'allow_holiday_pay' => (bool) $settings->allow_holiday_pay,
            'auto_present_attendance_reports' => (bool) $settings->auto_present_attendance_reports,
        ];
    }
}
