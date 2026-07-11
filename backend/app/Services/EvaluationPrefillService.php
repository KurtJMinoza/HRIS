<?php

namespace App\Services;

use App\Models\User;

/**
 * Auto-fills standard employee-info survey fields (name, position, department, period, evaluator).
 * Only fills empty values so evaluators can still edit before submit.
 */
class EvaluationPrefillService
{
    /** @var list<string> */
    private const PREFILL_KEYS = [
        'employee_name',
        'position',
        'department',
        'evaluation_period',
        'evaluator_name',
        'relationship',
    ];

    /** @var list<string> */
    private const SUPERVISOR_HR_ROLES = [
        'admin_hr',
        'company_head',
        'area_head',
        'branch_head',
        'department_head',
        'division_head',
        'section_unit_head',
    ];

    public function defaultEvaluationPeriod(?\DateTimeInterface $at = null): string
    {
        $at ??= now();
        $month = (int) $at->format('n');
        $quarter = (int) ceil($month / 3);

        return "Q{$quarter} ".$at->format('Y');
    }

    /**
     * @return array<string, string|null>
     */
    public function buildPrefillValues(User $employee, User $evaluator, ?string $hrRole = null): array
    {
        $employee->loadMissing(['departmentRelation:id,name', 'branch:id,name', 'company:id,name']);

        $department = $employee->departmentRelation?->name
            ?? $employee->branch?->name
            ?? $employee->company?->name
            ?? '';

        return [
            'employee_name' => trim((string) ($employee->name ?? '')),
            'position' => trim((string) ($employee->position ?? '')),
            'department' => trim((string) $department),
            'evaluation_period' => $this->defaultEvaluationPeriod(),
            'evaluator_name' => trim((string) ($evaluator->name ?? '')),
            'relationship' => $this->defaultRelationshipForHrRole($hrRole),
        ];
    }

    /**
     * @param  array<string, mixed>|null  $scores
     * @param  array<string, mixed>|null  $surveyJson
     * @return array<string, mixed>
     */
    public function mergePrefill(
        ?array $scores,
        ?array $surveyJson,
        User $employee,
        User $evaluator,
        ?string $hrRole = null,
    ): array {
        $scores ??= [];
        $prefill = $this->buildPrefillValues($employee, $evaluator, $hrRole);
        $presentNames = $this->collectQuestionNames($surveyJson);
        $surveyData = is_array($scores['survey_data'] ?? null) ? $scores['survey_data'] : [];

        foreach (self::PREFILL_KEYS as $key) {
            if (! in_array($key, $presentNames, true)) {
                continue;
            }

            $value = $prefill[$key] ?? null;
            if ($value === null || $value === '') {
                continue;
            }

            $current = $surveyData[$key] ?? null;
            if ($current === null || $current === '') {
                $surveyData[$key] = $value;
            }
        }

        $scores['survey_data'] = $surveyData;

        return $scores;
    }

    private function defaultRelationshipForHrRole(?string $hrRole): ?string
    {
        if ($hrRole === null || $hrRole === '') {
            return null;
        }

        return in_array($hrRole, self::SUPERVISOR_HR_ROLES, true)
            ? 'Immediate Supervisor'
            : null;
    }

    /**
     * @param  array<string, mixed>|null  $surveyJson
     * @return list<string>
     */
    private function collectQuestionNames(?array $surveyJson): array
    {
        $names = [];
        if (! is_array($surveyJson) || ! is_array($surveyJson['pages'] ?? null)) {
            return $names;
        }

        foreach ($surveyJson['pages'] as $page) {
            if (! is_array($page)) {
                continue;
            }
            $this->walkElements($page['elements'] ?? [], $names);
        }

        return $names;
    }

    /**
     * @param  list<mixed>  $elements
     * @param  list<string>  $names
     */
    private function walkElements(array $elements, array &$names): void
    {
        foreach ($elements as $el) {
            if (! is_array($el)) {
                continue;
            }

            $type = $el['type'] ?? '';
            if ($type === 'panel' && is_array($el['elements'] ?? null)) {
                $this->walkElements($el['elements'], $names);
                continue;
            }

            if ($type === 'html') {
                continue;
            }

            if (isset($el['name']) && is_string($el['name']) && $el['name'] !== '') {
                $names[] = $el['name'];
            }
        }
    }
}
