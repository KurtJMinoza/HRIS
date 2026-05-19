<?php

namespace Tests\Unit;

use App\Models\User;
use Tests\TestCase;

class EmployeeDisplayNameTest extends TestCase
{
    public function test_formats_first_middle_last_as_last_first_middle(): void
    {
        $employee = new User([
            'first_name' => 'Manilyn',
            'middle_name' => 'Arcala',
            'last_name' => 'Edquila',
        ]);

        $this->assertSame('Edquila, Manilyn Arcala', $employee->display_name);
        $this->assertSame('Edquila, Manilyn Arcala', $employee->formatted_name);
        $this->assertSame('Edquila, Manilyn Arcala', $employee->name);
    }

    public function test_omits_missing_middle_name(): void
    {
        $employee = new User([
            'first_name' => 'Juan',
            'last_name' => 'Dela Cruz',
        ]);

        $this->assertSame('Dela Cruz, Juan', $employee->display_name);
    }

    public function test_appends_suffix_after_given_names(): void
    {
        $employee = new User([
            'first_name' => 'Juan',
            'middle_name' => 'Santos',
            'last_name' => 'Dela Cruz',
            'suffix' => 'Jr.',
        ]);

        $this->assertSame('Dela Cruz, Juan Santos Jr.', $employee->display_name);
    }

    public function test_legacy_name_is_used_only_when_separated_fields_are_missing(): void
    {
        $employee = new User([
            'name' => 'Legacy Full Name',
        ]);

        $this->assertSame('Legacy Full Name', $employee->display_name);
    }
}
