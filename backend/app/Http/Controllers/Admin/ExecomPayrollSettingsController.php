<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\ExecomPayrollSetting;
use App\Services\ExecomPayrollPolicyResolver;
use App\Support\PayrollCacheInvalidator;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class ExecomPayrollSettingsController extends Controller
{
    /** @var list<string> */
    private const BOOLEAN_KEYS = [
        'apply_custom_deductions',
        'apply_allowances',
        'allow_paid_leave',
        'allow_overtime',
        'allow_holiday_pay',
        'auto_present_attendance_reports',
    ];

    public function __construct(
        private readonly ExecomPayrollPolicyResolver $policyResolver,
    ) {}

    public function show(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'company_id' => ['nullable', 'integer', 'exists:companies,id'],
        ]);
        $companyId = isset($validated['company_id']) ? (int) $validated['company_id'] : null;

        return response()->json([
            'settings' => $this->payload($companyId),
        ]);
    }

    public function update(Request $request): JsonResponse
    {
        $rules = [
            'company_id' => ['nullable', 'integer', 'exists:companies,id'],
        ];
        foreach (self::BOOLEAN_KEYS as $key) {
            $rules[$key] = ['required', 'boolean'];
        }

        $validated = $request->validate($rules);
        $companyId = isset($validated['company_id']) ? (int) $validated['company_id'] : null;

        $booleans = [];
        foreach (self::BOOLEAN_KEYS as $key) {
            $booleans[$key] = (bool) $validated[$key];
        }

        $settings = DB::transaction(function () use ($companyId, $booleans, $request): ExecomPayrollSetting {
            return ExecomPayrollSetting::query()->updateOrCreate(
                ['company_id' => $companyId],
                [
                    ...ExecomPayrollSetting::defaults($companyId),
                    ...$booleans,
                    'company_id' => $companyId,
                    'updated_by' => $request->user()?->id,
                ]
            );
        });

        PayrollCacheInvalidator::clearExecom('execom_payroll_settings_updated', [
            'updated_by' => $request->user()?->id,
        ], $companyId);
        $this->policyResolver->forget($companyId);

        return response()->json([
            'message' => 'EXECOM payroll settings updated.',
            'settings' => $this->payload($companyId, $settings),
        ]);
    }

    private function payload(?int $companyId, ?ExecomPayrollSetting $settings = null): array
    {
        $policy = $settings instanceof ExecomPayrollSetting
            ? array_merge(['company_id' => $companyId], $settings->toPolicyArray())
            : $this->policyResolver->resolve($companyId);

        return [
            'company_id' => $policy['company_id'] ?? $companyId,
            'apply_custom_deductions' => (bool) $policy['apply_custom_deductions'],
            'apply_allowances' => (bool) $policy['apply_allowances'],
            'allow_paid_leave' => (bool) $policy['allow_paid_leave'],
            'allow_overtime' => (bool) $policy['allow_overtime'],
            'allow_holiday_pay' => (bool) $policy['allow_holiday_pay'],
            'auto_present_attendance_reports' => (bool) $policy['auto_present_attendance_reports'],
        ];
    }
}
