<?php

namespace App\Services;

class GarnishmentService
{
    public function minimumDailyWage(): float
    {
        return (float) config('payroll.phase3.minimum_daily_wage', 645);
    }

    public function minimumWageProtectionAmount(int $periodDays): float
    {
        return round(max(0, $periodDays) * $this->minimumDailyWage(), 2);
    }

    /**
     * Legal cap for court-ordered garnishment based on disposable income for the period.
     */
    public function garnishmentCap(float $disposableIncome): float
    {
        $ratio = (float) config('payroll.phase3.garnishment_max_disposable_ratio', 0.25);

        return round(max(0, $disposableIncome) * max(0, min(1, $ratio)), 2);
    }
}
