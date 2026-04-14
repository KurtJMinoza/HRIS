<?php

namespace Tests\Unit;

use App\Enums\PolicyConditionKey;
use App\Models\Company;
use App\Models\Policy;
use App\Models\PolicyMultiplier;
use App\Models\PolicyNdSetting;
use App\Models\User;
use App\Services\PayrollComputationService;
use App\Services\PolicyResolverService;
use Carbon\Carbon;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * Policy Configuration Engine tests.
 * DB-dependent tests are skipped when using sqlite :memory: (CI). Run with MySQL for full coverage.
 */
class PolicyEngineTest extends TestCase
{
    public function test_policy_condition_key_enum_has_all_codes(): void
    {
        $all = PolicyConditionKey::all();
        $this->assertCount(8, $all);
        $codes = array_column($all, 'value');
        $this->assertContains('ORD', $codes);
        $this->assertContains('RD', $codes);
        $this->assertContains('RH', $codes);
        $this->assertContains('RHRD', $codes);
        $this->assertContains('SH', $codes);
        $this->assertContains('SHRD', $codes);
        $this->assertContains('DH', $codes);
        $this->assertContains('DHRD', $codes);
    }

    public function test_config_fallback_structure(): void
    {
        $rules = Config::get('payroll.rules', []);
        $this->assertArrayHasKey('ORD', $rules);
        $ord = $rules['ORD'];
        $this->assertArrayHasKey('first_8', $ord);
        $this->assertArrayHasKey('ot', $ord);
        $this->assertSame(1.0, (float) ($ord['first_8'] ?? 0));
        $this->assertSame(1.25, (float) ($ord['ot'] ?? 0));
    }

    public function test_nd_config_structure(): void
    {
        $nd = Config::get('payroll.night_differential', []);
        $this->assertArrayHasKey('start_hour', $nd);
        $this->assertArrayHasKey('end_hour', $nd);
        $this->assertSame(22, (int) ($nd['start_hour'] ?? 0));
        $this->assertSame(6, (int) ($nd['end_hour'] ?? 0));
    }

    public function test_global_policy_resolves_when_no_company(): void
    {
        if (! $this->tablesExist()) {
            $this->markTestSkipped('Database tables not available');
        }
        $policy = Policy::create([
            'name' => 'Global Default',
            'company_id' => null,
            'branch_id' => null,
            'effective_date' => '2025-01-01',
            'status' => Policy::STATUS_ACTIVE,
            'version' => 1,
        ]);

        PolicyMultiplier::create([
            'policy_id' => $policy->id,
            'condition_key' => 'ORD',
            'first8_multiplier' => 1.0,
            'ot_multiplier' => 1.25,
            'nd_addon_multiplier' => 0.10,
        ]);

        $resolver = app(PolicyResolverService::class);
        $active = $resolver->getActivePolicy(null, null, '2026-03-20');

        $this->assertNotNull($active);
        $this->assertSame($policy->id, $active->id);
    }

    public function test_company_policy_overrides_global(): void
    {
        if (! $this->tablesExist()) {
            $this->markTestSkipped('Database tables not available');
        }
        $company = Company::create(['name' => 'Acme Corp']);

        $globalPolicy = Policy::create([
            'name' => 'Global',
            'company_id' => null,
            'branch_id' => null,
            'effective_date' => '2025-01-01',
            'status' => Policy::STATUS_ACTIVE,
            'version' => 1,
        ]);
        PolicyMultiplier::create([
            'policy_id' => $globalPolicy->id,
            'condition_key' => 'ORD',
            'first8_multiplier' => 1.0,
            'ot_multiplier' => 1.25,
            'nd_addon_multiplier' => 0.10,
        ]);

        $companyPolicy = Policy::create([
            'name' => 'Acme Policy',
            'company_id' => $company->id,
            'branch_id' => null,
            'effective_date' => '2025-06-01',
            'status' => Policy::STATUS_ACTIVE,
            'version' => 1,
        ]);
        PolicyMultiplier::create([
            'policy_id' => $companyPolicy->id,
            'condition_key' => 'ORD',
            'first8_multiplier' => 1.50,
            'ot_multiplier' => 2.0,
            'nd_addon_multiplier' => 0.15,
        ]);

        $resolver = app(PolicyResolverService::class);
        $active = $resolver->getActivePolicy($company->id, null, '2026-03-20');

        $this->assertNotNull($active);
        $this->assertSame($companyPolicy->id, $active->id);

        $mult = $resolver->getMultipliersForRule($active, 'ORD');
        $this->assertSame(1.5, $mult['first_8']);
        $this->assertSame(2.0, $mult['ot']);
        $this->assertSame(0.15, $mult['nd_addon']);
    }

    public function test_multiplier_change_reflects_in_computation(): void
    {
        if (! $this->tablesExist()) {
            $this->markTestSkipped('Database tables not available (run migrations)');
        }

        $policy = Policy::create([
            'name' => 'Test',
            'company_id' => null,
            'branch_id' => null,
            'effective_date' => '2025-01-01',
            'status' => Policy::STATUS_ACTIVE,
            'version' => 1,
        ]);
        PolicyMultiplier::create([
            'policy_id' => $policy->id,
            'condition_key' => 'RD',
            'first8_multiplier' => 1.30,
            'ot_multiplier' => 1.69,
            'nd_addon_multiplier' => 0.10,
        ]);

        $user = User::factory()->create([
            'company_id' => null,
            'daily_rate' => 1000,
        ]);
        $effectiveSchedule = [
            'mon' => ['in' => '09:00', 'out' => '18:00', 'break_start' => '12:00', 'break_end' => '13:00'],
            'tue' => ['in' => '09:00', 'out' => '18:00', 'break_start' => '12:00', 'break_end' => '13:00'],
            'wed' => null,
            'thu' => ['in' => '09:00', 'out' => '18:00', 'break_start' => '12:00', 'break_end' => '13:00'],
            'fri' => ['in' => '09:00', 'out' => '18:00', 'break_start' => '12:00', 'break_end' => '13:00'],
            'sat' => ['in' => '09:00', 'out' => '18:00', 'break_start' => '12:00', 'break_end' => '13:00'],
            'sun' => null,
        ];
        $user->update(['schedule' => $effectiveSchedule]);

        $dateKey = '2026-03-18'; // Wednesday = rest day
        $timeIn = Carbon::parse("{$dateKey} 09:00", 'Asia/Manila');
        $timeOut = Carbon::parse("{$dateKey} 18:00", 'Asia/Manila');

        $payroll = app(PayrollComputationService::class);
        $result = $payroll->computeDayPayroll($user, $dateKey, $timeIn, $timeOut, $effectiveSchedule, 1000);

        $this->assertSame(1.30, $result['conditions']['first_8']);
        $this->assertSame(1.69, $result['conditions']['ot']);

        PolicyMultiplier::where('policy_id', $policy->id)->where('condition_key', 'RD')->update([
            'first8_multiplier' => 1.50,
            'ot_multiplier' => 1.95,
        ]);

        $result2 = $payroll->computeDayPayroll($user, $dateKey, $timeIn, $timeOut, $effectiveSchedule, 1000);
        $this->assertSame(1.50, $result2['conditions']['first_8']);
        $this->assertSame(1.95, $result2['conditions']['ot']);
    }

    public function test_nd_config_from_policy(): void
    {
        if (! $this->tablesExist()) {
            $this->markTestSkipped('Database tables not available');
        }
        $policy = Policy::create([
            'name' => 'ND Policy',
            'company_id' => null,
            'branch_id' => null,
            'effective_date' => '2025-01-01',
            'status' => Policy::STATUS_ACTIVE,
            'version' => 1,
        ]);
        PolicyNdSetting::create([
            'policy_id' => $policy->id,
            'start_time' => '22:00',
            'end_time' => '06:00',
            'nd_addon_multiplier' => 0.12,
            'apply_to_regular' => true,
            'apply_to_ot' => true,
            'apply_to_premium_days' => true,
        ]);

        $resolver = app(PolicyResolverService::class);
        $nd = $resolver->getNdConfig($policy);

        $this->assertSame(22, $nd['start_hour']);
        $this->assertSame(6, $nd['end_hour']);
        $this->assertSame(0.12, $nd['premium_multiplier']);
    }

    public function test_version_isolation_effective_date(): void
    {
        if (! $this->tablesExist()) {
            $this->markTestSkipped('Database tables not available');
        }
        $oldPolicy = Policy::create([
            'name' => 'Old',
            'company_id' => null,
            'branch_id' => null,
            'effective_date' => '2025-01-01',
            'status' => Policy::STATUS_ACTIVE,
            'version' => 1,
        ]);
        PolicyMultiplier::create([
            'policy_id' => $oldPolicy->id,
            'condition_key' => 'ORD',
            'first8_multiplier' => 1.0,
            'ot_multiplier' => 1.20,
            'nd_addon_multiplier' => 0.10,
        ]);

        $newPolicy = Policy::create([
            'name' => 'New',
            'company_id' => null,
            'branch_id' => null,
            'effective_date' => '2026-06-01',
            'status' => Policy::STATUS_ACTIVE,
            'version' => 2,
        ]);
        PolicyMultiplier::create([
            'policy_id' => $newPolicy->id,
            'condition_key' => 'ORD',
            'first8_multiplier' => 1.0,
            'ot_multiplier' => 1.30,
            'nd_addon_multiplier' => 0.10,
        ]);

        $resolver = app(PolicyResolverService::class);

        $beforeCutover = $resolver->getActivePolicy(null, null, '2026-05-15');
        $this->assertNotNull($beforeCutover);
        $this->assertSame($oldPolicy->id, $beforeCutover->id);
        $multBefore = $resolver->getMultipliersForRule($beforeCutover, 'ORD');
        $this->assertSame(1.20, $multBefore['ot']);

        $afterCutover = $resolver->getActivePolicy(null, null, '2026-06-15');
        $this->assertNotNull($afterCutover);
        $this->assertSame($newPolicy->id, $afterCutover->id);
        $multAfter = $resolver->getMultipliersForRule($afterCutover, 'ORD');
        $this->assertSame(1.30, $multAfter['ot']);
    }

    public function test_cross_company_policies(): void
    {
        if (! $this->tablesExist()) {
            $this->markTestSkipped('Database tables not available');
        }
        $companyA = Company::create(['name' => 'Company A']);
        $companyB = Company::create(['name' => 'Company B']);

        $policyA = Policy::create([
            'name' => 'Policy A',
            'company_id' => $companyA->id,
            'branch_id' => null,
            'effective_date' => '2025-01-01',
            'status' => Policy::STATUS_ACTIVE,
            'version' => 1,
        ]);
        PolicyMultiplier::create([
            'policy_id' => $policyA->id,
            'condition_key' => 'ORD',
            'first8_multiplier' => 1.0,
            'ot_multiplier' => 1.25,
            'nd_addon_multiplier' => 0.10,
        ]);

        $policyB = Policy::create([
            'name' => 'Policy B',
            'company_id' => $companyB->id,
            'branch_id' => null,
            'effective_date' => '2025-01-01',
            'status' => Policy::STATUS_ACTIVE,
            'version' => 1,
        ]);
        PolicyMultiplier::create([
            'policy_id' => $policyB->id,
            'condition_key' => 'ORD',
            'first8_multiplier' => 1.20,
            'ot_multiplier' => 1.50,
            'nd_addon_multiplier' => 0.12,
        ]);

        $resolver = app(PolicyResolverService::class);

        $activeA = $resolver->getActivePolicy($companyA->id, null, '2026-03-20');
        $this->assertNotNull($activeA);
        $this->assertSame($policyA->id, $activeA->id);
        $multA = $resolver->getMultipliersForRule($activeA, 'ORD');
        $this->assertSame(1.25, $multA['ot']);

        $activeB = $resolver->getActivePolicy($companyB->id, null, '2026-03-20');
        $this->assertNotNull($activeB);
        $this->assertSame($policyB->id, $activeB->id);
        $multB = $resolver->getMultipliersForRule($activeB, 'ORD');
        $this->assertSame(1.50, $multB['ot']);
    }

    private function tablesExist(): bool
    {
        try {
            DB::select('SELECT 1 FROM policies LIMIT 1');

            return true;
        } catch (\Throwable) {
            return false;
        }
    }
}
