<?php

namespace Database\Seeders;

use App\Models\SssBracket;
use App\Models\StatutoryContribution;
use Illuminate\Database\Seeder;

class StatutoryContributionSeeder extends Seeder
{
    /**
     * SSS Circular No. 2024-006 (effective 2025): total 15% of MSC
     * - Employee SS: 5%
     * - Employer SS: 10%
     * - EC: 30 flat (employer-only)
     */
    public function run(): void
    {
        $effectiveDate = '2025-01-01';

        $sss = StatutoryContribution::updateOrCreate(
            ['code' => 'SSS', 'company_id' => null, 'effective_from' => $effectiveDate],
            [
                'name' => 'Social Security System',
                'employee_rate' => 0.05,
                'employer_rate' => 0.10,
                'min_salary' => 5000,
                'max_salary' => 35000,
                'salary_floor' => 5000,
                'salary_ceiling' => 35000,
                'monthly_cap' => 35000,
                'is_active' => true,
                'metadata' => ['law_reference' => 'RA 11199', 'ec_flat' => 30],
                'compliance_reference' => 'Circular No. 2024-006',
            ]
        );

        $brackets = $this->buildSss2025Schedule();

        $sss->update(['brackets' => $brackets]);
        foreach ($brackets as $b) {
            SssBracket::updateOrCreate(
                [
                    'range_label' => $b['range_label'],
                    'effective_from' => $effectiveDate,
                ],
                [
                    'statutory_contribution_id' => $sss->id,
                    'range_start' => $b['min'],
                    'range_end' => $b['max'],
                    'range_from' => $b['min'],
                    'range_to' => $b['max'],
                    'salary_min' => $b['min'],
                    'salary_max' => $b['max'],
                    'msc' => $b['msc'],
                    'ee_share' => $b['employee_ss'],
                    'er_share' => $b['employer_ss'],
                    'ec_amount' => $b['employer_ec'],
                    'total' => $b['overall_total'],
                    'employer_ss' => $b['employer_ss'],
                    'employer_ec' => $b['employer_ec'],
                    'employer_total' => $b['employer_total'],
                    'employee_ss' => $b['employee_ss'],
                    'employee_total' => $b['employee_total'],
                    'overall_total' => $b['overall_total'],
                    'is_active' => true,
                ]
            );
        }

        StatutoryContribution::updateOrCreate(
            ['code' => 'PHILHEALTH', 'company_id' => null, 'effective_from' => $effectiveDate],
            [
                'name' => 'PhilHealth',
                'employee_rate' => 0.025,
                'employer_rate' => 0.025,
                'salary_floor' => 10000,
                'salary_ceiling' => 100000,
                'is_active' => true,
                'metadata' => ['law_reference' => 'RA 11223', 'total_rate' => 0.05],
                'compliance_reference' => 'RA 11223',
            ]
        );

        StatutoryContribution::updateOrCreate(
            ['code' => 'PAGIBIG', 'company_id' => null, 'effective_from' => $effectiveDate],
            [
                'name' => 'Pag-IBIG',
                'employee_rate' => 0.02,
                'employer_rate' => 0.02,
                'tier_threshold' => 1500,
                'monthly_cap' => 10000,
                'is_active' => true,
                'metadata' => ['law_reference' => 'RA 9679', 'employee_rate_lower' => 0.01, 'employee_rate_upper' => 0.02],
                'compliance_reference' => 'RA 9679',
            ]
        );

        StatutoryContribution::updateOrCreate(
            ['code' => 'EC', 'company_id' => null, 'effective_from' => $effectiveDate],
            [
                'name' => 'Employees Compensation',
                'employee_rate' => 0.00,
                'employer_rate' => 0.00,
                'min_salary' => 10,
                'max_salary' => 30,
                'is_active' => true,
                'metadata' => ['law_reference' => 'ECC', 'low_amount' => 10, 'high_amount' => 30],
                'compliance_reference' => 'ECC',
            ]
        );
    }

    /**
     * Build SSS contribution schedule rows with totals for UI and audit transparency.
     *
     * @return array<int, array<string, float|string>>
     */
    private function buildSss2025Schedule(): array
    {
        $rows = [];

        // SSS Circular No. 2024-006 (effective January 2025):
        // - "Below 5,250" maps to MSC 5,000
        // - Then 500-step salary ranges starting at 5,250 map to MSC 5,500 ... 34,500
        // - Last bracket is 34,750 and above mapping to MSC 35,000 (salary cap for MSC).
        $rows[] = $this->sssRow(0.00, 5249.99, 5000.00, 'Below 5,250');

        for ($min = 5250.00, $msc = 5500.00; $msc <= 34500.00; $min += 500.00, $msc += 500.00) {
            $max = $min + 499.99;
            $label = number_format($min, 0).' - '.number_format($max, 2);
            $rows[] = $this->sssRow($min, $max, $msc, $label);
        }

        $rows[] = $this->sssRow(34750.00, 1000000.00, 35000.00, '34,750 and above');

        return $rows;
    }

    /**
     * @return array<string, float|string>
     */
    private function sssRow(float $rangeStart, float $rangeEnd, float $msc, string $label): array
    {
        $employee = round($msc * 0.05, 2);
        $employer = round($msc * 0.10, 2);
        $ec = 30.00;
        $employerTotal = round($employer + $ec, 2);
        $overall = round($employee + $employerTotal, 2);

        return [
            'min' => $rangeStart,
            'max' => $rangeEnd,
            'range_label' => $label,
            'msc' => $msc,
            'employer_ss' => $employer,
            'employer_ec' => $ec,
            'employer_total' => $employerTotal,
            'employee_ss' => $employee,
            'employee_total' => $employee,
            'overall_total' => $overall,
        ];
    }
}
