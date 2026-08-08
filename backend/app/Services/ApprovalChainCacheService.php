<?php

namespace App\Services;

use Illuminate\Support\Facades\Cache;

class ApprovalChainCacheService
{
    public function forgetForEmployee(int $employeeId): void
    {
        if ($employeeId <= 0) {
            return;
        }

        Cache::forget('approval_chain_cache:'.$employeeId);
        Cache::forget('org_path:'.$employeeId);
    }

    public function forgetForCompany(int $companyId): void
    {
        if ($companyId <= 0) {
            return;
        }

        Cache::forget('company_heads:'.$companyId);
    }

    public function forgetForArea(int $areaId): void
    {
        if ($areaId <= 0) {
            return;
        }

        Cache::forget('area_heads:'.$areaId);
    }

    public function forgetForBranch(int $branchId): void
    {
        if ($branchId <= 0) {
            return;
        }

        Cache::forget('branch_heads:'.$branchId);
    }

    public function forgetForDivision(int $divisionId): void
    {
        if ($divisionId <= 0) {
            return;
        }

        Cache::forget('division_heads:'.$divisionId);
    }

    public function forgetForLegacyUnit(string $legacyType, int $legacyId): void
    {
        match ($legacyType) {
            'company' => $this->forgetForCompany($legacyId),
            'area' => $this->forgetForArea($legacyId),
            'branch' => $this->forgetForBranch($legacyId),
            'division' => $this->forgetForDivision($legacyId),
            default => null,
        };
    }

    public function forgetWorkflowSettings(): void
    {
        Cache::forget('approval_workflow_settings');
        Cache::store('array')->forget('approval_workflow_settings:defaults_ensured');
        Cache::store('array')->forget('approval_workflow_settings:payload:_null');
        foreach ([
            'attendance_correction',
            'leave',
            'overtime',
            'change_schedule',
            'reports_request',
        ] as $requestType) {
            Cache::store('array')->forget('approval_workflow_settings:payload:'.$requestType);
        }
    }
}
