<?php

namespace App\Services;

use App\Models\EmployeeCompensationComponent;
use App\Models\PayComponent;
use App\Models\User;
use App\Support\PayComponentSchedule;
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
            ->payrollEmployees()
            ->active()
            ->orderBy('id');

        $query->chunkById(200, function ($employees) use ($component, &$processed) {
            foreach ($employees as $employee) {
                $assignment = EmployeeCompensationComponent::query()
                    ->where('user_id', $employee->id)
                    ->where('pay_component_id', $component->id)
                    ->first();

                $metadata = (array) ($assignment?->metadata ?? []);
                $assignmentSource = $metadata['assignment_source'] ?? null;
                if (in_array($assignmentSource, ['manual_override', 'manual'], true)) {
                    continue;
                }

                $payload = $this->buildAssignmentPayload($component, $metadata);
                if ($assignment) {
                    $payload['schedule_override'] = PayComponentSchedule::normalizeForStorage(
                        \is_string($assignment->schedule_override) ? $assignment->schedule_override : null
                    );
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
        $masterMeta = is_array($component->metadata ?? null) ? $component->metadata : [];
        $calc = (string) $component->calculation_type;
        $defaultValue = (float) $component->default_value;
        $assignmentValue = $defaultValue;
        $assignmentHourlyRate = null;
        $assignmentHours = null;

        if ($calc === PayComponent::CALC_HOURLY) {
            $assignmentHourlyRate = isset($masterMeta['default_hourly_rate'])
                ? (float) $masterMeta['default_hourly_rate']
                : $defaultValue;
            $assignmentHours = isset($masterMeta['default_hours'])
                ? (float) $masterMeta['default_hours']
                : null;
            $assignmentValue = $assignmentHourlyRate;
        } elseif ($calc === PayComponent::CALC_DAILY) {
            $assignmentHours = isset($masterMeta['default_days'])
                ? (float) $masterMeta['default_days']
                : null;
        } elseif ($calc === PayComponent::CALC_PERCENT_BASIC || $calc === PayComponent::CALC_PERCENT_GROSS) {
            $assignmentValue = isset($masterMeta['default_percent'])
                ? (float) $masterMeta['default_percent']
                : $defaultValue;
        }

        return [
            'structure_name' => null,
            'name' => $component->name,
            'code' => $component->code,
            'type' => $component->type,
            'category' => $component->category,
            'calculation_type' => $component->calculation_type,
            'value' => $assignmentValue,
            'hourly_rate' => $assignmentHourlyRate,
            'hours' => $assignmentHours,
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
