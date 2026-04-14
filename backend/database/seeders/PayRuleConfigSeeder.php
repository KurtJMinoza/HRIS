<?php

namespace Database\Seeders;

use App\Models\PayRuleConfig;
use Illuminate\Database\Seeder;

class PayRuleConfigSeeder extends Seeder
{
    /**
     * Seed the global default pay rule config (company_id = null).
     * Per-company overrides can be created via Admin UI later.
     */
    public function run(): void
    {
        PayRuleConfig::updateOrCreate(
            ['company_id' => null],
            [
                'grace_period_minutes' => 5,
                'ot_multiplier_ordinary' => 1.25,
                'rest_day_premium' => 1.30,
                'special_holiday_premium' => 1.30,
                'regular_holiday_premium' => 2.00,
                'rest_on_special' => 1.50,
                'rest_on_regular' => 2.60,
                'nd_percentage' => 0.10,
                'night_start' => '22:00',
                'night_end' => '06:00',
                'is_active' => true,
            ]
        );
    }
}
