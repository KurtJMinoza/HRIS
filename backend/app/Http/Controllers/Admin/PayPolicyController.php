<?php

namespace App\Http\Controllers\Admin;

use App\Enums\PolicyConditionKey;
use App\Http\Controllers\Controller;
use App\Models\Company;
use App\Models\Policy;
use App\Models\PolicyMultiplier;
use App\Models\PolicyNdSetting;
use App\Services\PolicyResolverService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class PayPolicyController extends Controller
{
    public function __construct(
        private readonly PolicyResolverService $policyResolver,
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

        return response()->json($policy);
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

        $policy = Policy::create([
            'name' => $validated['name'],
            'company_id' => $validated['company_id'] ?? null,
            'branch_id' => $validated['branch_id'] ?? null,
            'effective_date' => $validated['effective_date'],
            'status' => $validated['status'] ?? Policy::STATUS_ACTIVE,
            'version' => 1,
            'version_label' => $validated['version_label'] ?? null,
            'priority_order_json' => $validated['priority_order_json'] ?? null,
        ]);

        $this->syncMultipliers($policy, $validated['multipliers'] ?? []);
        $this->syncNdSettings($policy, $validated['nd_settings'] ?? []);

        $policy->load(['multipliers', 'ndSetting']);

        return response()->json($policy, 201);
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

        $policy->fill(array_filter([
            'name' => $validated['name'] ?? null,
            'effective_date' => $validated['effective_date'] ?? null,
            'status' => $validated['status'] ?? null,
            'version_label' => $validated['version_label'] ?? null,
            'priority_order_json' => $validated['priority_order_json'] ?? null,
        ], fn ($v) => $v !== null));
        $policy->save();

        if (isset($validated['multipliers'])) {
            $this->syncMultipliers($policy, $validated['multipliers']);
        }
        if (isset($validated['nd_settings'])) {
            $this->syncNdSettings($policy, $validated['nd_settings']);
        }

        $policy->load(['multipliers', 'ndSetting']);

        return response()->json($policy);
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

        $policy->load(['multipliers', 'ndSetting']);

        return response()->json($policy, 201);
    }

    /**
     * Delete a policy. Child multipliers and ND settings cascade; payroll daily rows null policy_id.
     */
    public function destroy(int $id): JsonResponse
    {
        $policy = Policy::query()->findOrFail($id);
        $policy->delete();

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
}
