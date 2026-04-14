<?php

namespace Database\Seeders;

use App\Enums\PolicyConditionKey;
use App\Models\Policy;
use App\Models\PolicyMultiplier;
use App\Models\PolicyNdSetting;
use Illuminate\Database\Seeder;

class PolicySeeder extends Seeder
{
    public function run(): void
    {
        $rules = config('payroll.rules', []);
        $nd = config('payroll.night_differential', []);

        $policy = Policy::updateOrCreate(
            [
                'company_id' => null,
                'branch_id' => null,
                'effective_date' => '2025-01-01',
            ],
            [
                'name' => 'Default DOLE Policy',
                'status' => Policy::STATUS_ACTIVE,
                'version' => 1,
                'version_label' => 'Jan 2025',
                'priority_order_json' => ['holiday_type', 'rest_day', 'worked_flag', 'hour_type'],
            ]
        );

        foreach (PolicyConditionKey::cases() as $key) {
            $configRule = $rules[$key->value] ?? null;
            $first8 = $configRule ? (float) ($configRule['first_8'] ?? 1.0) : 1.0;
            $ot = $configRule ? (float) ($configRule['ot'] ?? 1.25) : 1.25;
            $ndAddon = (float) config('payroll.nd_premium', 0.10);

            PolicyMultiplier::updateOrCreate(
                [
                    'policy_id' => $policy->id,
                    'condition_key' => $key->value,
                ],
                [
                    'first8_multiplier' => $first8,
                    'ot_multiplier' => $ot,
                    'nd_addon_multiplier' => $ndAddon,
                ]
            );
        }

        PolicyNdSetting::updateOrCreate(
            ['policy_id' => $policy->id],
            [
                'start_time' => sprintf('%02d:00', $nd['start_hour'] ?? 22),
                'end_time' => sprintf('%02d:00', $nd['end_hour'] ?? 6),
                'nd_addon_multiplier' => (float) ($nd['premium_multiplier'] ?? 0.10),
                'apply_to_regular' => true,
                'apply_to_ot' => true,
                'apply_to_premium_days' => true,
            ]
        );
    }
}
