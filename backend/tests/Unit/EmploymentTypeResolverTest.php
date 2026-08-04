<?php

namespace Tests\Unit;

use App\Models\User;
use App\Services\EmploymentTypeResolver;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class EmploymentTypeResolverTest extends TestCase
{
    #[DataProvider('employeeTypes')]
    public function test_it_resolves_current_hris_employee_type(array $attributes, string $expected): void
    {
        $employee = new User($attributes);

        $this->assertSame($expected, (new EmploymentTypeResolver)->resolveForEmployee($employee));
    }

    public function test_labor_employment_type_ignores_execom_flag(): void
    {
        $employee = new User([
            'employment_type' => 'full_time',
            'employment_status' => 'regular',
            'is_execom' => true,
        ]);

        $resolver = new EmploymentTypeResolver;
        $this->assertSame('execom', $resolver->resolveForEmployee($employee));
        $this->assertSame('regular', $resolver->resolveLaborEmploymentType($employee));
    }

    public static function employeeTypes(): array
    {
        return [
            'regular status' => [['employment_type' => 'full_time', 'employment_status' => 'regular'], 'regular'],
            'probationary status' => [['employment_type' => 'full_time', 'employment_status' => 'probationary'], 'probationary'],
            'consultant type' => [['employment_type' => 'consultant', 'employment_status' => 'regular'], 'consultant'],
            'consultant status' => [['employment_type' => 'full_time', 'employment_status' => 'consultant'], 'consultant'],
            'contractual type' => [['employment_type' => 'contract', 'employment_status' => 'active'], 'contractual'],
            'execom flag' => [['employment_type' => 'full_time', 'employment_status' => 'regular', 'is_execom' => true], 'execom'],
        ];
    }
}
