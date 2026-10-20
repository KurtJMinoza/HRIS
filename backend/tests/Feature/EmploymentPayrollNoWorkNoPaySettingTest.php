<?php

namespace Tests\Feature;

use App\Models\EmploymentPayrollSetting;
use App\Models\User;
use App\Services\EmploymentPayrollPolicyApplicator;
use App\Services\EmploymentPayrollPolicyResolver;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class EmploymentPayrollNoWorkNoPaySettingTest extends TestCase
{
    use RefreshDatabase;

    private function admin(): User
    {
        return User::factory()->create(['role' => User::ROLE_ADMIN, 'is_active' => true]);
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function settingsPayload(array $overrides = []): array
    {
        $rows = [];
        foreach (EmploymentPayrollSetting::EMPLOYMENT_TYPES as $type) {
            $rows[] = array_merge([
                'employment_type' => $type,
                'apply_custom_deductions' => true,
                'apply_allowances' => true,
                'allow_paid_leave' => ! in_array($type, ['probationary', 'project_based'], true),
                'allow_overtime' => false,
                'allow_holiday_pay' => false,
                'no_work_no_pay' => false,
            ], $overrides);
        }

        return $rows;
    }

    public function test_settings_expose_no_work_no_pay_for_consultants(): void
    {
        $admin = $this->admin();

        $response = $this->actingAs($admin, 'sanctum')
            ->getJson('/api/admin/payroll/employment-payroll-settings');

        $response->assertOk();
        $response->assertJsonPath('settings.consultant.no_work_no_pay', false);
        $response->assertJsonPath('settings.regular.no_work_no_pay', false);
    }

    public function test_admin_can_enable_no_work_no_pay_for_consultants(): void
    {
        $admin = $this->admin();

        $payload = $this->settingsPayload();
        foreach ($payload as $index => $row) {
            if ($row['employment_type'] === 'consultant') {
                $payload[$index]['no_work_no_pay'] = true;
            }
        }

        $response = $this->actingAs($admin, 'sanctum')
            ->putJson('/api/admin/payroll/employment-payroll-settings', ['settings' => $payload]);

        $response->assertOk();
        $response->assertJsonPath('settings.consultant.no_work_no_pay', true);

        $this->assertDatabaseHas('employment_payroll_settings', [
            'company_id' => null,
            'employment_type' => 'consultant',
            'no_work_no_pay' => true,
        ]);

        // The resolved policy must reflect the switch so payroll can honour it.
        $policy = app(EmploymentPayrollPolicyResolver::class)->resolve(null, 'consultant');
        $this->assertTrue(EmploymentPayrollPolicyApplicator::consultantNoWorkNoPayEnabled($policy));
        $this->assertFalse(EmploymentPayrollPolicyApplicator::consultantUsesAutoPresentPayroll($policy));
    }

    public function test_no_work_no_pay_is_forced_off_for_other_employment_types(): void
    {
        $admin = $this->admin();

        $payload = $this->settingsPayload(['no_work_no_pay' => true]);

        $response = $this->actingAs($admin, 'sanctum')
            ->putJson('/api/admin/payroll/employment-payroll-settings', ['settings' => $payload]);

        $response->assertOk();
        $response->assertJsonPath('settings.regular.no_work_no_pay', false);
        $response->assertJsonPath('settings.probationary.no_work_no_pay', false);
        $response->assertJsonPath('settings.project_based.no_work_no_pay', false);
        $response->assertJsonPath('settings.consultant.no_work_no_pay', true);
    }
}
