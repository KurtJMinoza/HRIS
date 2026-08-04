<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\EmploymentPayrollSetting;
use App\Services\EmploymentPayrollPolicyResolver;
use App\Support\PayrollCacheInvalidator;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

class EmploymentPayrollSettingsController extends Controller
{
    /** @var list<string> */
    private const BOOLEAN_KEYS = [
        'apply_custom_deductions',
        'apply_allowances',
        'allow_paid_leave',
        'allow_overtime',
        'allow_holiday_pay',
    ];

    public function __construct(
        private readonly EmploymentPayrollPolicyResolver $policyResolver,
    ) {}

    public function show(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'company_id' => ['nullable', 'integer', 'exists:companies,id'],
        ]);
        $companyId = isset($validated['company_id']) ? (int) $validated['company_id'] : null;

        return response()->json([
            'company_id' => $companyId,
            'settings' => $this->payload($companyId),
        ]);
    }

    public function update(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'company_id' => ['nullable', 'integer', 'exists:companies,id'],
            'settings' => ['required', 'array'],
            'settings.*.employment_type' => [
                'required',
                'string',
                Rule::in(EmploymentPayrollSetting::EMPLOYMENT_TYPES),
            ],
            ...collect(self::BOOLEAN_KEYS)->flatMap(function (string $key): array {
                return ["settings.*.{$key}" => ['required', 'boolean']];
            })->all(),
        ]);

        $companyId = isset($validated['company_id']) ? (int) $validated['company_id'] : null;

        DB::transaction(function () use ($companyId, $validated, $request): void {
            foreach ($validated['settings'] as $row) {
                $employmentType = EmploymentPayrollSetting::normalizeEmploymentType((string) $row['employment_type']);
                $booleans = [];
                foreach (self::BOOLEAN_KEYS as $key) {
                    $booleans[$key] = (bool) $row[$key];
                }
                if (EmploymentPayrollSetting::isUnpaidLeaveEmploymentType($employmentType)) {
                    $booleans['allow_paid_leave'] = false;
                }

                EmploymentPayrollSetting::query()->updateOrCreate(
                    [
                        'company_id' => $companyId,
                        'employment_type' => $employmentType,
                    ],
                    [
                        ...EmploymentPayrollSetting::defaults($employmentType),
                        ...$booleans,
                        'company_id' => $companyId,
                        'employment_type' => $employmentType,
                        'updated_by' => $request->user()?->id,
                    ]
                );
            }
        });

        PayrollCacheInvalidator::clear('employment_payroll_settings_updated', [
            'updated_by' => $request->user()?->id,
            'company_id' => $companyId,
        ]);
        $this->policyResolver->forget($companyId);

        return response()->json([
            'message' => 'Employment payroll settings updated.',
            'company_id' => $companyId,
            'settings' => $this->payload($companyId),
        ]);
    }

    /**
     * @return array<string, array<string, mixed>>
     */
    private function payload(?int $companyId): array
    {
        return $this->policyResolver->resolveAllForCompany($companyId);
    }
}
