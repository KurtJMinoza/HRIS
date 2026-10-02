<?php

namespace Tests\Unit;

use App\Models\User;
use App\Services\HrRoleResolver;
use App\Support\NotificationActionUrl;
use Mockery;
use Tests\TestCase;

class NotificationActionUrlTest extends TestCase
{
    protected function tearDown(): void
    {
        Mockery::close();
        parent::tearDown();
    }

    public function test_org_head_gets_employee_shell_paths(): void
    {
        $approver = new User(['id' => 42]);
        $resolver = Mockery::mock(HrRoleResolver::class);
        $resolver->shouldReceive('isAdminHrAccount')->with($approver)->andReturn(false);
        $this->app->instance(HrRoleResolver::class, $resolver);

        $this->assertSame(
            '/employee/requests?review_id=7',
            NotificationActionUrl::forApprover($approver, 'leave', 7),
        );
        $this->assertSame(
            '/employee/overtime?review_id=8',
            NotificationActionUrl::forApprover($approver, 'overtime', 8),
        );
        $this->assertSame(
            '/employee/correction-requests?review_id=9',
            NotificationActionUrl::forApprover($approver, 'attendance_correction', 9),
        );
    }

    public function test_hr_admin_gets_admin_shell_paths(): void
    {
        $approver = new User(['id' => 1]);
        $resolver = Mockery::mock(HrRoleResolver::class);
        $resolver->shouldReceive('isAdminHrAccount')->with($approver)->andReturn(true);
        $this->app->instance(HrRoleResolver::class, $resolver);

        $this->assertSame(
            '/admin/corrections?review_id=3',
            NotificationActionUrl::forApprover($approver, 'attendance_correction', 3),
        );
    }
}
