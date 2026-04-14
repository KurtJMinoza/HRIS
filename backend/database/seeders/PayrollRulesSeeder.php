<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

class PayrollRulesSeeder extends Seeder
{
    public function run(): void
    {
        $rules = [
            ['code' => 'ORD',   'condition' => 'Normal',                  'first8_multiplier' => 1.00, 'ot_multiplier' => 1.25, 'nd_base_multiplier' => 1.00],
            ['code' => 'RD',    'condition' => 'Rest Day',                'first8_multiplier' => 1.30, 'ot_multiplier' => 1.69, 'nd_base_multiplier' => 1.30],
            ['code' => 'RH',    'condition' => 'Regular Holiday',         'first8_multiplier' => 2.00, 'ot_multiplier' => 2.60, 'nd_base_multiplier' => 2.00],
            ['code' => 'RHRD',  'condition' => 'Holiday + Rest Day',       'first8_multiplier' => 2.60, 'ot_multiplier' => 3.38, 'nd_base_multiplier' => 2.60],
            ['code' => 'SH',    'condition' => 'Special Holiday',         'first8_multiplier' => 1.30, 'ot_multiplier' => 1.69, 'nd_base_multiplier' => 1.30],
            ['code' => 'SHRD',  'condition' => 'Special Holiday + RD',     'first8_multiplier' => 1.50, 'ot_multiplier' => 1.95, 'nd_base_multiplier' => 1.50],
            ['code' => 'DH',    'condition' => 'Double Holiday',           'first8_multiplier' => 3.00, 'ot_multiplier' => 3.90, 'nd_base_multiplier' => 3.00],
            ['code' => 'DHRD',  'condition' => 'Double Holiday + Rest Day', 'first8_multiplier' => 3.00, 'ot_multiplier' => 3.90, 'nd_base_multiplier' => 3.00],
        ];

        foreach ($rules as $rule) {
            DB::table('payroll_rules')->updateOrInsert(
                ['code' => $rule['code']],
                array_merge($rule, ['is_active' => true, 'created_at' => now(), 'updated_at' => now()])
            );
        }
    }
}
