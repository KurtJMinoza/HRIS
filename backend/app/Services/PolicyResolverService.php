<?php

namespace App\Services;

use App\Models\PayrollRule;
use App\Models\Policy;
use App\Models\PolicyMultiplier;

/**
 * Resolves active pay policy by scope (branch → company → global) and date.
 * Supplies multipliers and ND config; falls back to payroll_rules / config when no policy.
 */
class PolicyResolverService
{
    /**
     * Get the active policy for a given scope and date.
     * Precedence: branch → company → global. Within each scope, latest effective_date <= date wins.
     */
    public function getActivePolicy(?int $companyId, ?int $branchId, string $dateKey): ?Policy
    {
        $query = Policy::query()
            ->active()
            ->whereDate('effective_date', '<=', $dateKey)
            ->orderByDesc('effective_date');

        if ($branchId !== null) {
            $policy = (clone $query)->where('branch_id', $branchId)->first();
            if ($policy) {
                return $policy;
            }
        }

        if ($companyId !== null) {
            $policy = (clone $query)->where('company_id', $companyId)->whereNull('branch_id')->first();
            if ($policy) {
                return $policy;
            }
        }

        return $query->whereNull('company_id')->whereNull('branch_id')->first();
    }

    /**
     * Get multipliers for a rule code from policy, or fall back to payroll_rules / config.
     *
     * @return array{first_8: float, ot: float, nd_base: float, nd_addon: float}
     */
    public function getMultipliersForRule(?Policy $policy, string $ruleCode): array
    {
        if ($policy) {
            $multiplier = PolicyMultiplier::query()
                ->where('policy_id', $policy->id)
                ->where('condition_key', $ruleCode)
                ->first();

            if ($multiplier) {
                $base = (float) $multiplier->first8_multiplier;

                return [
                    'first_8' => $base,
                    'ot' => (float) $multiplier->ot_multiplier,
                    'nd_base' => $base,
                    'nd_addon' => (float) $multiplier->nd_addon_multiplier,
                ];
            }
        }

        return $this->fallbackMultipliersForRule($ruleCode);
    }

    /**
     * Fallback when no policy or missing multiplier row.
     */
    private function fallbackMultipliersForRule(string $ruleCode): array
    {
        $rule = PayrollRule::query()->where('code', $ruleCode)->first();

        if ($rule) {
            return [
                'first_8' => (float) $rule->first8_multiplier,
                'ot' => (float) $rule->ot_multiplier,
                'nd_base' => (float) $rule->nd_base_multiplier,
                'nd_addon' => (float) config('payroll.nd_premium', 0.10),
            ];
        }

        $rules = config('payroll.rules', []);
        $configRule = $rules[$ruleCode] ?? $rules['ORD'] ?? null;

        if (! $configRule) {
            return [
                'first_8' => 1.0,
                'ot' => 1.25,
                'nd_base' => 1.0,
                'nd_addon' => (float) config('payroll.nd_premium', 0.10),
            ];
        }

        return [
            'first_8' => (float) ($configRule['first_8'] ?? 1.0),
            'ot' => (float) ($configRule['ot'] ?? 1.25),
            'nd_base' => (float) ($configRule['nd_base'] ?? 1.0),
            'nd_addon' => (float) config('payroll.nd_premium', 0.10),
        ];
    }

    /**
     * Get ND config from policy, or fall back to config.
     *
     * @return array{start_hour: int, end_hour: int, premium_multiplier: float, apply_to_regular: bool, apply_to_ot: bool, apply_to_premium_days: bool}
     */
    public function getNdConfig(?Policy $policy): array
    {
        if ($policy && $policy->ndSetting) {
            return $policy->ndSetting->toConfigFormat();
        }

        $nd = config('payroll.night_differential', []);

        return [
            'start_hour' => (int) ($nd['start_hour'] ?? 22),
            'end_hour' => (int) ($nd['end_hour'] ?? 6),
            'premium_multiplier' => (float) ($nd['premium_multiplier'] ?? 0.10),
            'apply_to_regular' => true,
            'apply_to_ot' => true,
            'apply_to_premium_days' => true,
        ];
    }

    /**
     * Build a snapshot of policy multipliers and ND for audit storage.
     */
    public function buildPolicySnapshot(?Policy $policy, string $ruleCode): ?array
    {
        if (! $policy) {
            return null;
        }

        $multipliers = $this->getMultipliersForRule($policy, $ruleCode);
        $ndConfig = $this->getNdConfig($policy);
        $ns = $policy->ndSetting;
        if ($ns) {
            $ndConfig['start_time'] = $ns->start_time;
            $ndConfig['end_time'] = $ns->end_time;
        }

        return [
            'policy_id' => $policy->id,
            'policy_name' => $policy->name,
            'effective_date' => $policy->effective_date->toDateString(),
            'rule_code' => $ruleCode,
            'multipliers' => $multipliers,
            'nd' => $ndConfig,
        ];
    }
}
