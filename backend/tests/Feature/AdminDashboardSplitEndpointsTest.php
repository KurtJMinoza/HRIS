<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class AdminDashboardSplitEndpointsTest extends TestCase
{
    use RefreshDatabase;

    public function test_split_dashboard_endpoints_return_expected_keys(): void
    {
        $admin = User::factory()->create([
            'role' => User::ROLE_ADMIN,
            'is_active' => true,
        ]);

        $this->actingAs($admin, 'sanctum')
            ->getJson('/api/admin/dashboard/summary')
            ->assertOk()
            ->assertJsonStructure([
                'stats',
                'stats_prev',
                'half_day_summary',
                'upcoming_holidays',
            ])
            ->assertJsonMissing(['today_logs', 'weekly_overview']);

        $this->actingAs($admin, 'sanctum')
            ->getJson('/api/admin/dashboard/requests')
            ->assertOk()
            ->assertJsonStructure([
                'pending_counts',
                'pending_overtime_request',
                'today_leaves',
            ]);

        $this->actingAs($admin, 'sanctum')
            ->getJson('/api/admin/dashboard/attendance-today')
            ->assertOk()
            ->assertJsonStructure(['today_logs'])
            ->assertJsonMissing(['weekly_overview', 'company_distribution']);

        $this->actingAs($admin, 'sanctum')
            ->getJson('/api/admin/dashboard/payroll')
            ->assertOk()
            ->assertJsonStructure(['payroll_summary']);

        $this->actingAs($admin, 'sanctum')
            ->getJson('/api/admin/dashboard/charts')
            ->assertOk()
            ->assertJsonStructure([
                'weekly_overview',
                'department_distribution',
                'company_distribution',
            ]);

        $this->actingAs($admin, 'sanctum')
            ->getJson('/api/admin/dashboard/recent-activity')
            ->assertOk()
            ->assertJsonStructure(['recent_activity']);
    }
}
