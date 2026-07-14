<?php

namespace App\Services;

use App\Models\ExecomEmployeeProfile;
use App\Models\User;
use Carbon\CarbonInterface;

class EmployeeClassificationService
{
    public function isExecom(User $employee, ?CarbonInterface $periodStart = null, ?CarbonInterface $periodEnd = null): bool
    {
        if ((bool) ($employee->is_execom ?? false)) {
            return true;
        }

        if ($employee->relationLoaded('execomProfiles')) {
            return $employee->execomProfiles->contains(
                fn (ExecomEmployeeProfile $profile): bool => $this->profileIsActiveForPeriod($profile, $periodStart, $periodEnd)
            );
        }

        return $employee->activeExecomProfileForPeriod($periodStart, $periodEnd) !== null;
    }

    public function label(User $employee, ?CarbonInterface $periodStart = null, ?CarbonInterface $periodEnd = null): string
    {
        return $this->isExecom($employee, $periodStart, $periodEnd) ? 'EXECom' : 'Regular';
    }

    private function profileIsActiveForPeriod(
        ExecomEmployeeProfile $profile,
        ?CarbonInterface $periodStart,
        ?CarbonInterface $periodEnd,
    ): bool {
        if (! (bool) $profile->is_active) {
            return false;
        }

        $start = $periodStart?->toDateString() ?? now()->toDateString();
        $end = $periodEnd?->toDateString() ?? $start;
        $effectiveFrom = $profile->effective_from?->toDateString();
        $effectiveTo = $profile->effective_to?->toDateString();

        return ($effectiveFrom === null || $effectiveFrom <= $end)
            && ($effectiveTo === null || $effectiveTo >= $start);
    }
}
