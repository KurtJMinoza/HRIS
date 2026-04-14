<?php

namespace App\Services;

use App\Models\EmployeeCompensationComponent;
use App\Models\PayComponent;
use App\Models\User;
use Illuminate\Support\Facades\Schema;

class PayComponentAssignmentService
{
    public function syncForAllEmployees(PayComponent $component): int
    {
        if (! $this->shouldApplyToAll($component)) {
            return 0;
        }

        $processed = 0;

        $query = User::query()
            ->whereIn('role', User::ROSTER_ELIGIBLE_ROLES)
            ->orderBy('id');

        if (Schema::hasColumn('users', 'is_active')) {
            $query->where('is_active', true);
        }

        $query->chunkById(200, function ($employees) use ($component, &$processed) {
            foreach ($employees as $employee) {
                $assignment = EmployeeCompensationComponent::query()
                    ->where('user_id', $employee->id)
                    ->where('pay_component_id', $component->id)
                    ->first();

                $metadata = (array) ($assignment?->metadata ?? []);
                if (($metadata['assignment_source'] ?? null) === 'manual_override') {
                    continue;
                }

                $payload = $this->buildAssignmentPayload($component, $metadata);

                if ($assignment) {
                    $assignment->fill($payload);
                    $assignment->save();
                } else {
                    EmployeeCompensationComponent::query()->create([
                        'user_id' => $employee->id,
                        'pay_component_id' => $component->id,
                        ...$payload,
                    ]);
                }

                $processed++;
            }
        });

        return $processed;
    }

    public function shouldApplyToAll(PayComponent $component): bool
    {
        if (! Schema::hasColumn('pay_components', 'apply_to_all')) {
            return false;
        }

        if (! $component->apply_to_all) {
            return false;
        }

        return ! $this->isBasicSalaryComponent($component);
    }

    private function buildAssignmentPayload(PayComponent $component, array $metadata = []): array
    {
        return [
            'structure_name' => null,
            'name' => $component->name,
            'code' => $component->code,
            'type' => $component->type,
            'category' => $component->category,
            'calculation_type' => $component->calculation_type,
            'value' => (float) $component->default_value,
            'hourly_rate' => null,
            'hours' => null,
            'formula' => $component->formula,
            'is_taxable' => (bool) $component->is_taxable,
            'contributes_sss' => (bool) $component->contributes_sss,
            'contributes_philhealth' => (bool) $component->contributes_philhealth,
            'contributes_pagibig' => (bool) $component->contributes_pagibig,
            'is_proratable' => (bool) $component->is_proratable,
            'is_custom' => false,
            'effective_from' => $component->effective_from?->toDateString(),
            'effective_to' => $component->effective_to?->toDateString(),
            'is_active' => (bool) $component->is_active,
            'metadata' => array_merge($metadata, [
                'assignment_source' => 'auto_apply_all',
                'auto_applied' => true,
            ]),
        ];
    }

    private function isBasicSalaryComponent(PayComponent $component): bool
    {
        return strtoupper((string) $component->code) === 'BASIC_SALARY'
            || strcasecmp((string) $component->category, 'Basic Salary') === 0
            || strcasecmp((string) $component->name, 'Basic Salary') === 0;
    }
}
