<?php

namespace Tests\Unit;

use App\Http\Controllers\Admin\DashboardController;
use App\Models\User;
use ReflectionClass;
use Tests\TestCase;

class AdminDashboardHolidayVisibilityTest extends TestCase
{
    public function test_admin_upcoming_holidays_are_not_filtered_by_admin_org_assignment(): void
    {
        $admin = new User;
        $admin->forceFill([
            'id' => 99,
            'role' => User::ROLE_ADMIN,
            'company_id' => 7,
            'branch_id' => 12,
        ]);

        $reflection = new ReflectionClass(DashboardController::class);
        $controller = $reflection->newInstanceWithoutConstructor();
        $method = $reflection->getMethod('resolveHolidayScopeContext');

        $this->assertNull($method->invoke($controller, $admin));
    }
}
