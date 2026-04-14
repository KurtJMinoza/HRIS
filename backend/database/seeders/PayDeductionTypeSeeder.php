<?php

namespace Database\Seeders;

use App\Models\DeductionType;
use Illuminate\Database\Seeder;

class PayDeductionTypeSeeder extends Seeder
{
    public function run(): void
    {
        DeductionType::query()->firstOrCreate(
            [
                'company_id' => null,
                'slug' => 'salary-loan',
            ],
            [
                'name' => 'Salary loan',
                'type' => DeductionType::TYPE_LOAN,
                'is_government' => false,
                'pay_component_id' => null,
                'is_active' => true,
            ]
        );

        DeductionType::query()->firstOrCreate(
            [
                'company_id' => null,
                'slug' => 'company-benefit-deduction',
            ],
            [
                'name' => 'Company benefit (deduction)',
                'type' => DeductionType::TYPE_BENEFIT,
                'is_government' => false,
                'pay_component_id' => null,
                'is_active' => true,
            ]
        );
    }
}
