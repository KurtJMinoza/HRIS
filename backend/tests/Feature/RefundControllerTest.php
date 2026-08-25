<?php

namespace Tests\Feature;

use App\Models\RefundRequest;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class RefundControllerTest extends TestCase
{
    use RefreshDatabase;

    public function test_admin_can_list_refunds_with_permission(): void
    {
        $admin = User::factory()->create([
            'role' => User::ROLE_ADMIN,
            'is_super_admin' => true,
        ]);

        $employee = User::factory()->create(['role' => User::ROLE_EMPLOYEE, 'is_active' => true]);
        RefundRequest::query()->create([
            'refund_number' => RefundRequest::generateRefundNumber(),
            'employee_id' => $employee->id,
            'direction' => RefundRequest::DIRECTION_UNDERPAYMENT,
            'category' => RefundRequest::CATEGORY_ATTENDANCE,
            'reason' => RefundRequest::REASON_MISSING_TIME_OUT,
            'affected_date' => now()->subDays(3)->toDateString(),
            'original_amount' => 100,
            'corrected_amount' => 500,
            'refund_amount' => 400,
            'status' => RefundRequest::STATUS_DRAFT,
            'created_by' => $admin->id,
            'calculation' => ['components' => []],
        ]);

        $res = $this->actingAs($admin)->getJson('/api/admin/refunds?status=requests');
        $res->assertOk();
        $this->assertGreaterThanOrEqual(1, count($res->json('data.data') ?? []));
    }

    public function test_employee_cannot_access_admin_refunds_index(): void
    {
        $employee = User::factory()->create(['role' => User::ROLE_EMPLOYEE, 'is_active' => true]);

        $this->actingAs($employee)->getJson('/api/admin/refunds')->assertForbidden();
    }

    public function test_employee_can_view_own_payroll_adjustments(): void
    {
        $employee = User::factory()->create(['role' => User::ROLE_EMPLOYEE, 'is_active' => true]);

        RefundRequest::query()->create([
            'refund_number' => RefundRequest::generateRefundNumber(),
            'employee_id' => $employee->id,
            'direction' => RefundRequest::DIRECTION_UNDERPAYMENT,
            'category' => RefundRequest::CATEGORY_OVERTIME,
            'reason' => RefundRequest::REASON_MISSING_OVERTIME,
            'affected_date' => now()->subDays(5)->toDateString(),
            'original_amount' => 0,
            'corrected_amount' => 500,
            'refund_amount' => 500,
            'status' => RefundRequest::STATUS_PROCESSED,
            'processed_at' => now(),
            'calculation' => ['components' => []],
        ]);

        $res = $this->actingAs($employee)->getJson('/api/employee/payroll-adjustments');
        $res->assertOk();
        $this->assertCount(1, $res->json('data'));
    }
}
