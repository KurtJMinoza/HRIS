<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class HeadAssignmentEmployeeSearchTest extends TestCase
{
    use RefreshDatabase;

    public function test_token_search_matches_first_and_last_name_independently(): void
    {
        $actor = User::factory()->create([
            'role' => User::ROLE_ADMIN,
            'is_active' => true,
            'is_system_user' => false,
            'is_hidden' => false,
        ]);

        $target = User::factory()->create([
            'role' => 'employee',
            'is_active' => true,
            'is_system_user' => false,
            'is_hidden' => false,
            'first_name' => 'Nouie',
            'last_name' => 'Siason',
            'middle_name' => 'Villanueva',
            'employee_code' => 'EMP-TEST-1778',
            'email' => 'nouie.siason@example.test',
        ]);

        $inactive = User::factory()->create([
            'role' => 'employee',
            'is_active' => false,
            'is_system_user' => false,
            'is_hidden' => false,
            'first_name' => 'Inactive',
            'last_name' => 'Siason',
        ]);

        $this->actingAs($actor, 'sanctum');

        foreach (['Siason', 'Nouie', 'siason nouie', 'EMP-TEST'] as $query) {
            $response = $this->getJson('/api/employees/search-for-head-assignment?q='.urlencode($query));
            $response->assertOk();
            $ids = collect($response->json('employees'))->pluck('employee_id')->all();
            $this->assertContains($target->id, $ids, "Query [{$query}] should match target employee");
            $this->assertNotContains($inactive->id, $ids, "Query [{$query}] should exclude inactive employee");
        }
    }
}
