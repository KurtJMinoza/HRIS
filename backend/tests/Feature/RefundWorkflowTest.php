<?php

namespace Tests\Feature;

use App\Models\RefundRequest;
use App\Models\User;
use App\Services\RefundWorkflowService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use InvalidArgumentException;
use Tests\TestCase;

class RefundWorkflowTest extends TestCase
{
    use RefreshDatabase;

    public function test_submit_transition_moves_draft_to_submitted(): void
    {
        $admin = User::factory()->create([
            'role' => User::ROLE_ADMIN,
            'is_super_admin' => true,
        ]);
        $employee = User::factory()->create(['role' => User::ROLE_EMPLOYEE, 'is_active' => true]);

        $refund = RefundRequest::query()->create([
            'refund_number' => RefundRequest::generateRefundNumber(),
            'employee_id' => $employee->id,
            'direction' => RefundRequest::DIRECTION_UNDERPAYMENT,
            'category' => RefundRequest::CATEGORY_ATTENDANCE,
            'reason' => RefundRequest::REASON_MISSING_TIME_OUT,
            'affected_date' => now()->subDays(2)->toDateString(),
            'original_amount' => 100,
            'corrected_amount' => 500,
            'refund_amount' => 400,
            'status' => RefundRequest::STATUS_DRAFT,
            'created_by' => $admin->id,
            'calculation' => ['components' => []],
        ]);

        $updated = app(RefundWorkflowService::class)->transition($admin, $refund, 'submit', []);

        $this->assertSame(RefundRequest::STATUS_SUBMITTED, $updated->status);
        $this->assertNotNull($updated->submitted_at);
    }

    public function test_reject_requires_reason(): void
    {
        $admin = User::factory()->create([
            'role' => User::ROLE_ADMIN,
            'is_super_admin' => true,
        ]);
        $employee = User::factory()->create(['role' => User::ROLE_EMPLOYEE, 'is_active' => true]);

        $refund = RefundRequest::query()->create([
            'refund_number' => RefundRequest::generateRefundNumber(),
            'employee_id' => $employee->id,
            'direction' => RefundRequest::DIRECTION_UNDERPAYMENT,
            'category' => RefundRequest::CATEGORY_ATTENDANCE,
            'reason' => RefundRequest::REASON_MISSING_TIME_OUT,
            'affected_date' => now()->subDays(2)->toDateString(),
            'original_amount' => 100,
            'corrected_amount' => 500,
            'refund_amount' => 400,
            'status' => RefundRequest::STATUS_SUBMITTED,
            'created_by' => $admin->id,
            'calculation' => ['components' => []],
        ]);

        $this->expectException(InvalidArgumentException::class);
        app(RefundWorkflowService::class)->transition($admin, $refund, 'reject', []);
    }

    public function test_void_from_approved_requires_reason(): void
    {
        $admin = User::factory()->create([
            'role' => User::ROLE_ADMIN,
            'is_super_admin' => true,
        ]);
        $employee = User::factory()->create(['role' => User::ROLE_EMPLOYEE, 'is_active' => true]);

        $refund = RefundRequest::query()->create([
            'refund_number' => RefundRequest::generateRefundNumber(),
            'employee_id' => $employee->id,
            'direction' => RefundRequest::DIRECTION_UNDERPAYMENT,
            'category' => RefundRequest::CATEGORY_ATTENDANCE,
            'reason' => RefundRequest::REASON_MISSING_TIME_OUT,
            'affected_date' => now()->subDays(2)->toDateString(),
            'original_amount' => 100,
            'corrected_amount' => 500,
            'refund_amount' => 400,
            'status' => RefundRequest::STATUS_APPROVED,
            'created_by' => $admin->id,
            'calculation' => ['components' => []],
        ]);

        $this->expectException(InvalidArgumentException::class);
        app(RefundWorkflowService::class)->transition($admin, $refund, 'void', []);
    }
}
