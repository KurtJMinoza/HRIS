<?php

namespace App\Http\Controllers\Admin;

use App\Enums\PolicyConditionKey;
use App\Http\Controllers\Controller;
use App\Models\Company;
use App\Models\Policy;
use App\Models\PolicyMultiplier;
use App\Models\PolicyNdSetting;
use App\Models\HolidayPayPolicySetting;
use App\Services\HolidayPayPolicyService;
use App\Services\HolidayPolicyCache;
use App\Services\AttendanceCacheService;
use App\Services\EmployeeDashboardCacheService;
use App\Services\EmploymentTypeResolver;
use App\Services\PolicyResolverService;
use App\Support\PayrollCacheInvalidator;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

class PayPolicyController extends Controller
{
    public function __construct(
        private readonly PolicyResolverService $policyResolver,
        private readonly HolidayPayPolicyService $holidayPayPolicy,
        private readonly ?EmploymentTypeResolver $employmentTypeResolver = null,
    ) {}

    /**
     * List policies with optional company filter.
     */
    public function index(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'company_id' => ['nullable', 'integer', 'exists:companies,id'],
            'status' => ['nullable', 'string', 'in:all,active,archived'],
            'per_page' => ['nullable', 'integer', 'min:1', 'max:50'],
        ]);

        $query = Policy::query()->with(['company:id,name', 'branch:id,name']);

        if (isset($validated['company_id'])) {
            $query->where(function ($q) use ($validated) {
                $q->where('company_id', $validated['company_id'])
                    ->orWhereNull('company_id');
            });
        }
        if (($validated['status'] ?? 'all') !== 'all') {
            $query->where('status', $validated['status']);
        }

        $policies = $query->orderByDesc('effective_date')->paginate((int) ($validated['per_page'] ?? 20));

        return response()->json($policies);
    }

    /**
     * Get a single policy with multipliers and ND settings.
     */
    public function show(int $id): JsonResponse
    {
        $policy = Policy::query()
            ->with(['multipliers', 'ndSetting', 'company:id,name', 'branch:id,name'])
            ->findOrFail($id);

        return response()->json($this->policyPayload($policy));
    }

    /**
     * Create a new policy.
     */
    public function store(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'company_id' => ['nullable', 'integer', 'exists:companies,id'],
            'branch_id' => ['nullable', 'integer', 'exists:branches,id'],
            'effective_date' => ['required', 'date'],
            'status' => ['nullable', 'string', 'in:active,archived'],
            'version_label' => ['nullable', 'string', 'max:50'],
            'priority_order_json' => ['nullable', 'array'],
            ...$this->holidayPolicyRules(),
            'multipliers' => ['nullable', 'array'],
            'multipliers.*.condition_key' => ['required', 'string', 'in:ORD,RD,RH,RHRD,SH,SHRD,DH,DHRD'],
            'multipliers.*.first8_multiplier' => ['required', 'numeric', 'min:0'],
            'multipliers.*.ot_multiplier' => ['required', 'numeric', 'min:0'],
            'multipliers.*.nd_addon_multiplier' => ['nullable', 'numeric', 'min:0', 'max:1'],
            'nd_settings' => ['nullable', 'array'],
            'nd_settings.start_time' => ['nullable', 'string', 'regex:/^\d{1,2}:\d{2}$/'],
            'nd_settings.end_time' => ['nullable', 'string', 'regex:/^\d{1,2}:\d{2}$/'],
            'nd_settings.nd_addon_multiplier' => ['nullable', 'numeric', 'min:0', 'max:1'],
            'nd_settings.apply_to_regular' => ['nullable', 'boolean'],
            'nd_settings.apply_to_ot' => ['nullable', 'boolean'],
            'nd_settings.apply_to_premium_days' => ['nullable', 'boolean'],
        ]);
        $this->validateDoleMinimums($validated);

        $policy = Policy::create([
            'name' => $validated['name'],
            'company_id' => $validated['company_id'] ?? null,
            'branch_id' => $validated['branch_id'] ?? null,
            'effective_date' => $validated['effective_date'],
            'status' => $validated['status'] ?? Policy::STATUS_ACTIVE,
            'version' => 1,
            'version_label' => $validated['version_label'] ?? null,
            'priority_order_json' => $validated['priority_order_json'] ?? null,
            'holiday_policy' => $this->mergeHolidayPolicyForStorage(
                Policy::DEFAULT_HOLIDAY_POLICY,
                (array) ($validated['holiday_policy'] ?? [])
            ),
        ]);

        $this->syncMultipliers($policy, $validated['multipliers'] ?? []);
        $this->syncNdSettings($policy, $validated['nd_settings'] ?? []);
        $this->syncHolidayPayPolicySettings($policy);

        $policy->load(['multipliers', 'ndSetting']);
        $this->invalidateHolidayPolicy($policy);

        return response()->json($this->policyPayload($policy), 201);
    }

    /**
     * Update a policy.
     */
    public function update(Request $request, int $id): JsonResponse
    {
        $policy = Policy::query()->findOrFail($id);

        $validated = $request->validate([
            'name' => ['sometimes', 'string', 'max:255'],
            'effective_date' => ['sometimes', 'date'],
            'status' => ['sometimes', 'string', 'in:active,archived'],
            'version_label' => ['nullable', 'string', 'max:50'],
            'priority_order_json' => ['nullable', 'array'],
            ...$this->holidayPolicyRules(),
            'multipliers' => ['nullable', 'array'],
            'multipliers.*.condition_key' => ['required', 'string', 'in:ORD,RD,RH,RHRD,SH,SHRD,DH,DHRD'],
            'multipliers.*.first8_multiplier' => ['required', 'numeric', 'min:0'],
            'multipliers.*.ot_multiplier' => ['required', 'numeric', 'min:0'],
            'multipliers.*.nd_addon_multiplier' => ['nullable', 'numeric', 'min:0', 'max:1'],
            'nd_settings' => ['nullable', 'array'],
            'nd_settings.start_time' => ['nullable', 'string', 'regex:/^\d{1,2}:\d{2}$/'],
            'nd_settings.end_time' => ['nullable', 'string', 'regex:/^\d{1,2}:\d{2}$/'],
            'nd_settings.nd_addon_multiplier' => ['nullable', 'numeric', 'min:0', 'max:1'],
            'nd_settings.apply_to_regular' => ['nullable', 'boolean'],
            'nd_settings.apply_to_ot' => ['nullable', 'boolean'],
            'nd_settings.apply_to_premium_days' => ['nullable', 'boolean'],
        ]);
        $this->validateDoleMinimums($validated);

        $policy->fill(array_filter([
            'name' => $validated['name'] ?? null,
            'effective_date' => $validated['effective_date'] ?? null,
            'status' => $validated['status'] ?? null,
            'version_label' => $validated['version_label'] ?? null,
            'priority_order_json' => $validated['priority_order_json'] ?? null,
        ], fn ($v) => $v !== null));
        $policy->save();

        if (isset($validated['holiday_policy'])) {
            $policy->holiday_policy = $this->mergeHolidayPolicyForStorage(
                $policy->resolvedHolidayPolicy(),
                $validated['holiday_policy']
            );
            $policy->save();
        }

        if (isset($validated['multipliers'])) {
            $this->syncMultipliers($policy, $validated['multipliers']);
        }
        if (isset($validated['nd_settings'])) {
            $this->syncNdSettings($policy, $validated['nd_settings']);
        }
        $this->syncHolidayPayPolicySettings($policy);

        $policy->load(['multipliers', 'ndSetting']);
        $this->invalidateHolidayPolicy($policy);

        return response()->json($this->policyPayload($policy));
    }

    /** @return array<string, mixed> */
    private function policyPayload(Policy $policy): array
    {
        $payload = $policy->toArray();
        $payload['holiday_policy'] = $policy->resolvedHolidayPolicy();

        return $payload;
    }

    /**
     * Duplicate a policy as a new version.
     */
    public function duplicate(Request $request, int $id): JsonResponse
    {
        $source = Policy::query()->with(['multipliers', 'ndSetting'])->findOrFail($id);

        $validated = $request->validate([
            'name' => ['nullable', 'string', 'max:255'],
            'effective_date' => ['required', 'date'],
            'version_label' => ['nullable', 'string', 'max:50'],
        ]);

        $policy = Policy::create([
            'name' => $validated['name'] ?? $source->name.' (copy)',
            'company_id' => $source->company_id,
            'branch_id' => $source->branch_id,
            'effective_date' => $validated['effective_date'],
            'status' => Policy::STATUS_ACTIVE,
            'version' => $source->version + 1,
            'version_label' => $validated['version_label'] ?? null,
            'priority_order_json' => $source->priority_order_json,
            'holiday_policy' => $source->resolvedHolidayPolicy(),
        ]);

        foreach ($source->multipliers as $m) {
            PolicyMultiplier::create([
                'policy_id' => $policy->id,
                'condition_key' => $m->condition_key,
                'first8_multiplier' => $m->first8_multiplier,
                'ot_multiplier' => $m->ot_multiplier,
                'nd_addon_multiplier' => $m->nd_addon_multiplier,
            ]);
        }

        if ($source->ndSetting) {
            PolicyNdSetting::create([
                'policy_id' => $policy->id,
                'start_time' => $source->ndSetting->start_time,
                'end_time' => $source->ndSetting->end_time,
                'nd_addon_multiplier' => $source->ndSetting->nd_addon_multiplier,
                'apply_to_regular' => $source->ndSetting->apply_to_regular,
                'apply_to_ot' => $source->ndSetting->apply_to_ot,
                'apply_to_premium_days' => $source->ndSetting->apply_to_premium_days,
            ]);
        }

        $this->syncHolidayPayPolicySettings($policy);

        $policy->load(['multipliers', 'ndSetting']);
        $this->invalidateHolidayPolicy($policy);

        return response()->json($policy, 201);
    }

    /**
     * Delete a policy. Child multipliers and ND settings cascade; payroll daily rows null policy_id.
     */
    public function destroy(int $id): JsonResponse
    {
        $policy = Policy::query()->findOrFail($id);
        $companyId = $policy->company_id;
        $policy->delete();
        $this->invalidateHolidayPolicyForCompany($companyId);

        return response()->json(['message' => 'Policy deleted']);
    }

    /**
     * Preview formula strings for a rule code (and optionally policy).
     */
    public function preview(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'rule_code' => ['required', 'string', 'in:ORD,RD,RH,RHRD,SH,SHRD,DH,DHRD'],
            'hourly_rate' => ['nullable', 'numeric', 'min:0'],
            'policy_id' => ['nullable', 'integer', 'exists:policies,id'],
            'company_id' => ['nullable', 'integer', 'exists:companies,id'],
            'date' => ['nullable', 'date'],
        ]);

        $policy = null;
        if (! empty($validated['policy_id'])) {
            $policy = Policy::query()->with(['multipliers'])->find($validated['policy_id']);
        } elseif (! empty($validated['company_id']) && ! empty($validated['date'])) {
            $policy = $this->policyResolver->getActivePolicy(
                (int) $validated['company_id'],
                null,
                $validated['date']
            );
        }

        $multipliers = $this->policyResolver->getMultipliersForRule($policy, $validated['rule_code']);
        $first8 = $multipliers['first_8'];
        $ot = $multipliers['ot'];
        $ndAddon = $multipliers['nd_addon'] ?? 0.10;

        $key = PolicyConditionKey::tryFrom($validated['rule_code']);
        $conditionLabel = $key ? $key->label() : $validated['rule_code'];

        $formulas = [
            'condition' => $conditionLabel,
            'rule_code' => $validated['rule_code'],
            'first8_formula' => "HWR × {$first8}",
            'ot_formula' => "HWR × {$ot}",
            'nd_formula' => "HWR × base × {$ndAddon} (ND add-on on applicable hours)",
        ];

        $hourlyRate = (float) ($validated['hourly_rate'] ?? 0);
        if ($hourlyRate > 0) {
            $formulas['example_first8_8h'] = round(8 * $hourlyRate * $first8, 2);
            $formulas['example_ot_2h'] = round(2 * $hourlyRate * $ot, 2);
        }

        return response()->json($formulas);
    }

    /**
     * List companies for policy selector.
     */
    public function companies(): JsonResponse
    {
        $companies = Company::query()->orderBy('name')->get(['id', 'name']);

        return response()->json($companies);
    }

    public function employmentTypes(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'company_id' => ['nullable', 'integer', 'exists:companies,id'],
        ]);

        $resolver = $this->employmentTypeResolver ?? app(EmploymentTypeResolver::class);

        return response()->json($resolver->available(
            isset($validated['company_id']) ? (int) $validated['company_id'] : null
        ));
    }

    /**
     * Condition keys for multiplier table.
     */
    public function conditionKeys(): JsonResponse
    {
        return response()->json(PolicyConditionKey::all());
    }

    private function syncMultipliers(Policy $policy, array $multipliers): void
    {
        if ($multipliers === []) {
            $rules = config('payroll.rules', []);
            $ndAddon = (float) config('payroll.nd_premium', 0.10);
            foreach (PolicyConditionKey::cases() as $key) {
                $r = $rules[$key->value] ?? [];
                $multipliers[] = [
                    'condition_key' => $key->value,
                    'first8_multiplier' => (float) ($r['first_8'] ?? 1.0),
                    'ot_multiplier' => (float) ($r['ot'] ?? 1.25),
                    'nd_addon_multiplier' => $ndAddon,
                ];
            }
        }

        $keys = array_column($multipliers, 'condition_key');
        $policy->multipliers()->whereNotIn('condition_key', $keys)->delete();

        foreach ($multipliers as $row) {
            PolicyMultiplier::updateOrCreate(
                [
                    'policy_id' => $policy->id,
                    'condition_key' => $row['condition_key'],
                ],
                [
                    'first8_multiplier' => (float) $row['first8_multiplier'],
                    'ot_multiplier' => (float) $row['ot_multiplier'],
                    'nd_addon_multiplier' => (float) ($row['nd_addon_multiplier'] ?? 0.10),
                ]
            );
        }
    }

    private function syncNdSettings(Policy $policy, array $nd): void
    {
        PolicyNdSetting::updateOrCreate(
            ['policy_id' => $policy->id],
            [
                'start_time' => $nd['start_time'] ?? '22:00',
                'end_time' => $nd['end_time'] ?? '06:00',
                'nd_addon_multiplier' => (float) ($nd['nd_addon_multiplier'] ?? 0.10),
                'apply_to_regular' => $nd['apply_to_regular'] ?? true,
                'apply_to_ot' => $nd['apply_to_ot'] ?? true,
                'apply_to_premium_days' => $nd['apply_to_premium_days'] ?? true,
            ]
        );
    }

    private function syncHolidayPayPolicySettings(Policy $policy): void
    {
        $holidayPolicy = $policy->resolvedHolidayPolicy();
        $regular = (array) ($holidayPolicy['regular_unworked'] ?? []);
        $special = (array) ($holidayPolicy['special_unworked'] ?? []);
        $attendance = (array) ($holidayPolicy['attendance'] ?? []);

        HolidayPayPolicySetting::query()->updateOrCreate(
            [
                'policy_id' => (int) $policy->id,
                'holiday_key' => 'policy-defaults',
            ],
            [
                'company_id' => $policy->company_id,
                'branch_id' => $policy->branch_id,
                'regular_unworked_policy' => $regular['unworked_pay_policy'] ?? 'dole_default',
                'regular_unworked_employment_types' => array_values((array) ($regular['eligible_employment_types'] ?? [])),
                'special_unworked_policy' => $special['unworked_pay_policy'] ?? 'no_work_no_pay',
                'special_unworked_employment_types' => array_values((array) ($special['eligible_employment_types'] ?? [])),
                'require_previous_workday_attendance' => (bool) ($attendance['require_previous_workday_presence'] ?? true),
                'require_following_workday_attendance' => (bool) ($attendance['require_following_workday_presence'] ?? false),
                'allow_paid_leave' => (bool) ($attendance['paid_leave_qualifies'] ?? true),
                'paid_leave_qualifies' => (bool) ($attendance['paid_leave_qualifies'] ?? true),
                'rest_day_lookup_enabled' => (bool) ($attendance['skip_rest_days'] ?? true),
                'successive_holiday_rule_enabled' => (bool) ($regular['successive_holiday_rule'] ?? true),
                'is_active' => $policy->status === Policy::STATUS_ACTIVE,
                'status' => $policy->status === Policy::STATUS_ACTIVE
                    ? HolidayPayPolicySetting::STATUS_ACTIVE
                    : HolidayPayPolicySetting::STATUS_INACTIVE,
            ]
        );
    }

    /** @return array<string, array<int, mixed>> */
    private function holidayPolicyRules(): array
    {
        return [
            'holiday_policy' => ['nullable', 'array'],
            'holiday_policy.pay_unworked_regular' => ['sometimes', 'boolean'],
            'holiday_policy.pay_unworked_special' => ['sometimes', 'boolean'],
            'holiday_policy.unworked_special_multiplier' => ['prohibited'],
            'holiday_policy.replace_regular_with_leave_credits' => ['prohibited'],
            'holiday_policy.force_leave_credits' => ['prohibited'],
            'holiday_policy.attendance' => ['sometimes', 'array'],
            'holiday_policy.attendance.require_previous_workday_presence' => ['sometimes', 'boolean'],
            'holiday_policy.attendance.require_following_workday_presence' => ['sometimes', 'boolean'],
            'holiday_policy.attendance.paid_leave_qualifies' => ['sometimes', 'boolean'],
            'holiday_policy.attendance.official_business_qualifies' => ['sometimes', 'boolean'],
            'holiday_policy.attendance.training_qualifies' => ['sometimes', 'boolean'],
            'holiday_policy.attendance.paid_suspension_qualifies' => ['sometimes', 'boolean'],
            'holiday_policy.attendance.skip_rest_days' => ['sometimes', 'boolean'],
            'holiday_policy.attendance.skip_company_non_working_days' => ['sometimes', 'boolean'],
            'holiday_policy.eligibility' => ['sometimes', 'array'],
            'holiday_policy.regular_unworked' => ['sometimes', 'array'],
            'holiday_policy.regular_unworked.unworked_pay_policy' => ['sometimes', 'string', 'in:dole_default,covered_employees,selected_employment_types,all_employment_types,all_employees,disabled'],
            'holiday_policy.regular_unworked.eligible_employment_types' => ['sometimes', 'array'],
            'holiday_policy.regular_unworked.eligible_employment_types.*' => ['string', 'max:80'],
            'holiday_policy.regular_unworked.always_pay' => ['sometimes', 'boolean'],
            'holiday_policy.regular_unworked.successive_holiday_rule' => ['sometimes', 'boolean'],
            'holiday_policy.regular_unworked.coverage_behaviour' => ['sometimes', 'string', 'in:respect_coverage,ignore_coverage'],
            'holiday_policy.regular_worked' => ['sometimes', 'array'],
            'holiday_policy.regular_worked.coverage_behaviour' => ['sometimes', 'string', 'in:respect_coverage,ignore_coverage'],
            'holiday_policy.regular_worked.employment_type_rule' => ['sometimes', 'string', 'in:all_employment_types,selected_employment_types'],
            'holiday_policy.regular_worked.eligible_employment_types' => ['sometimes', 'array'],
            'holiday_policy.regular_worked.eligible_employment_types.*' => ['string', 'max:80'],
            'holiday_policy.special_unworked' => ['sometimes', 'array'],
            'holiday_policy.special_unworked.unworked_pay_policy' => ['sometimes', 'string', 'in:no_work_no_pay,selected_employment_types,all_employment_types,all_employees,paid_leave,paid_leave_only'],
            'holiday_policy.special_unworked.eligible_employment_types' => ['sometimes', 'array'],
            'holiday_policy.special_unworked.eligible_employment_types.*' => ['string', 'max:80'],
            'holiday_policy.special_unworked.coverage_behaviour' => ['sometimes', 'string', 'in:respect_coverage,ignore_coverage'],
            'holiday_policy.special_worked' => ['sometimes', 'array'],
            'holiday_policy.special_worked.coverage_behaviour' => ['sometimes', 'string', 'in:respect_coverage,ignore_coverage'],
            'holiday_policy.special_worked.employment_type_rule' => ['sometimes', 'string', 'in:all_employment_types,selected_employment_types'],
            'holiday_policy.special_worked.eligible_employment_types' => ['sometimes', 'array'],
            'holiday_policy.special_worked.eligible_employment_types.*' => ['string', 'max:80'],
            'holiday_policy.non_statutory' => ['sometimes', 'array'],
            'holiday_policy.non_statutory.special_working' => ['sometimes', 'array'],
            'holiday_policy.non_statutory.special_working.pay_as_ordinary_day' => ['sometimes', 'boolean'],
            'holiday_policy.non_statutory.company' => ['sometimes', 'array'],
            'holiday_policy.non_statutory.company.pay_as_ordinary_day' => ['sometimes', 'boolean'],
        ];
    }

    /** @param array<string, mixed> $validated */
    private function validateDoleMinimums(array $validated): void
    {
        $errors = [];
        $holidayPolicy = (array) ($validated['holiday_policy'] ?? []);
        foreach (['paid_leave_qualifies', 'skip_rest_days', 'skip_company_non_working_days'] as $mandatory) {
            if (array_key_exists($mandatory, (array) ($holidayPolicy['attendance'] ?? []))
                && ! $holidayPolicy['attendance'][$mandatory]) {
                $errors['holiday_policy.attendance.'.$mandatory] = ['This DOLE attendance qualification rule must remain enabled.'];
            }
        }

        foreach ((array) ($validated['multipliers'] ?? []) as $index => $row) {
            $code = (string) ($row['condition_key'] ?? '');
            $minimum = Policy::HOLIDAY_MULTIPLIER_MINIMUMS[$code] ?? null;
            if ($minimum === null) {
                continue;
            }
            foreach ($minimum as $field => $floor) {
                if ((float) ($row[$field] ?? 0) + 0.00001 < $floor) {
                    $errors["multipliers.{$index}.{$field}"] = [
                        'This configuration is below the minimum standard required by DOLE.',
                    ];
                }
            }
            if ((float) ($row['nd_addon_multiplier'] ?? 0.10) + 0.00001 < 0.10) {
                $errors["multipliers.{$index}.nd_addon_multiplier"] = [
                    'Holiday night differential cannot be lower than 10%.',
                ];
            }
        }

        if ($errors !== []) {
            throw ValidationException::withMessages($errors);
        }
    }

    /** @param  array<string, mixed>  $base */
    private function mergeHolidayPolicyForStorage(array $base, array $incoming): array
    {
        $merged = array_replace_recursive($base, $incoming);

        foreach (['regular_unworked', 'regular_worked', 'special_unworked', 'special_worked'] as $block) {
            if (! isset($incoming[$block]) || ! is_array($incoming[$block])) {
                continue;
            }

            $merged[$block] = array_replace_recursive(
                Policy::DEFAULT_HOLIDAY_POLICY[$block] ?? [],
                $incoming[$block]
            );

            if ($block === 'regular_unworked' || $block === 'special_unworked') {
                if (array_key_exists('eligible_employment_types', $incoming[$block])) {
                    $merged[$block]['eligible_employment_types'] = array_values(array_unique(
                        (array) $incoming[$block]['eligible_employment_types']
                    ));
                }
            }
        }

        if (isset($incoming['non_statutory']) && is_array($incoming['non_statutory'])) {
            $merged['non_statutory'] = array_replace_recursive(
                Policy::DEFAULT_HOLIDAY_POLICY['non_statutory'] ?? [],
                $incoming['non_statutory']
            );
        }
        $merged['non_statutory']['special_working']['pay_as_ordinary_day'] = true;

        if (isset($incoming['attendance']) && is_array($incoming['attendance'])) {
            $merged['attendance'] = array_replace_recursive(
                Policy::DEFAULT_HOLIDAY_POLICY['attendance'] ?? [],
                $incoming['attendance']
            );
            foreach (['require_previous_workday_presence', 'require_following_workday_presence'] as $attendanceFlag) {
                if (array_key_exists($attendanceFlag, $incoming['attendance'])) {
                    $merged['attendance'][$attendanceFlag] = (bool) $incoming['attendance'][$attendanceFlag];
                }
            }
        }

        $specialPolicy = (string) ($merged['special_unworked']['unworked_pay_policy'] ?? 'no_work_no_pay');
        $regularPolicy = (string) ($merged['regular_unworked']['unworked_pay_policy'] ?? 'dole_default');
        $merged['pay_unworked_regular'] = $regularPolicy !== 'disabled';
        $merged['pay_unworked_special'] = $specialPolicy !== 'no_work_no_pay';
        $merged['eligibility'] = array_replace_recursive(
            Policy::DEFAULT_HOLIDAY_POLICY['eligibility'] ?? [],
            (array) ($merged['eligibility'] ?? [])
        );
        $merged['eligibility']['pay_unworked_regular'] = $merged['pay_unworked_regular'];
        $merged['eligibility']['company_may_pay_unworked_special'] = $merged['pay_unworked_special'];
        $merged['eligibility']['special_no_work_no_pay'] = ! $merged['pay_unworked_special'];

        foreach (['paid_leave_qualifies', 'skip_rest_days', 'skip_company_non_working_days'] as $mandatory) {
            $merged['attendance'][$mandatory] = true;
        }

        return $merged;
    }

    private function invalidateHolidayPolicy(Policy $policy): void
    {
        $this->policyResolver->flushRuntimeCaches();
        $this->invalidateHolidayPolicyForCompany($policy->company_id);
    }

    private function invalidateHolidayPolicyForCompany(?int $companyId): void
    {
        if ($companyId === null) {
            HolidayPolicyCache::forgetAll();
        } else {
            HolidayPolicyCache::forgetPolicy($companyId);
        }
        AttendanceCacheService::invalidate();
        EmployeeDashboardCacheService::invalidateAll();
        PayrollCacheInvalidator::clear('pay_policy_changed', ['company_id' => $companyId]);
    }
}
