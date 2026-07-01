<?php

namespace App\Services;

use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

/** Resolves only employment types that are actually used by employees in this HRIS. */
class EmploymentTypeResolver
{
    /** @return list<array{value: string, label: string, employee_count: int}> */
    public function available(?int $companyId = null): array
    {
        $employees = User::query()
            ->where('is_active', true)
            ->when($companyId, fn ($query, int $id) => $query->where('company_id', $id))
            ->get(['employment_type', 'employment_status', 'is_execom']);

        $counts = [];
        foreach ($employees as $employee) {
            $value = $this->resolveForEmployee($employee);
            if ($value !== '') {
                $counts[$value] = ($counts[$value] ?? 0) + 1;
            }
        }

        $catalogLabels = $this->employmentTypeTableLabels();
        $options = [];
        foreach ($counts as $value => $count) {
            $options[] = [
                'value' => $value,
                'label' => $catalogLabels[$value] ?? $this->label($value),
                'employee_count' => $count,
            ];
        }

        usort($options, fn (array $a, array $b): int => strcasecmp($a['label'], $b['label']));

        return $options;
    }

    public function resolveForEmployee(User $employee): string
    {
        if ((bool) ($employee->is_execom ?? false)) {
            return 'execom';
        }

        $type = $this->normalize($employee->employment_type);
        $status = $this->normalize($employee->employment_status);

        if (in_array($type, ['consultant', 'consultancy'], true)) {
            return 'consultant';
        }
        if (str_contains($type, 'contract') || str_contains($status, 'contract')) {
            return 'contractual';
        }
        if (str_contains($type, 'project') || str_contains($status, 'project')) {
            return 'project_based';
        }
        if (str_contains($type, 'part_time')) {
            return 'part_time';
        }
        if (str_contains($status, 'probation') || str_contains($type, 'probation')) {
            return 'probationary';
        }
        if (str_contains($status, 'regular')) {
            return 'regular';
        }

        return $type !== '' ? $type : $status;
    }

    public function label(string $value): string
    {
        return match ($value) {
            'execom' => 'EXECom',
            default => Str::headline($value),
        };
    }

    /** @return array<string, string> */
    private function employmentTypeTableLabels(): array
    {
        if (! Schema::hasTable('employment_types')) {
            return [];
        }

        $columns = Schema::getColumnListing('employment_types');
        $valueColumn = collect(['code', 'slug', 'value', 'name'])->first(fn (string $column): bool => in_array($column, $columns, true));
        if ($valueColumn === null) {
            return [];
        }
        $labelColumn = collect(['label', 'name', 'display_name', $valueColumn])->first(fn (string $column): bool => in_array($column, $columns, true));

        $query = DB::table('employment_types');
        if (in_array('is_active', $columns, true)) {
            $query->where('is_active', true);
        } elseif (in_array('status', $columns, true)) {
            $query->where('status', 'active');
        }

        return $query->get([$valueColumn, $labelColumn])
            ->mapWithKeys(function ($row) use ($valueColumn, $labelColumn): array {
                $value = $this->normalize($row->{$valueColumn} ?? '');

                return $value === '' ? [] : [$value => (string) ($row->{$labelColumn} ?? $this->label($value))];
            })
            ->all();
    }

    private function normalize(mixed $value): string
    {
        return strtolower(trim((string) preg_replace('/[^a-z0-9]+/i', '_', (string) $value), '_'));
    }
}
