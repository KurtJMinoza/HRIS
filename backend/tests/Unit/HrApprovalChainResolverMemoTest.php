<?php

namespace Tests\Unit;

use App\Services\ApprovalWorkflowSettingService;
use App\Services\HrApprovalChainResolver;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class HrApprovalChainResolverMemoTest extends TestCase
{
    private bool $transactionStarted = false;

    protected function setUp(): void
    {
        parent::setUp();

        if (! Schema::hasTable('approval_workflow_settings') || ! Schema::hasTable('users')) {
            $this->markTestSkipped('Required tables are not available.');
        }

        DB::beginTransaction();
        $this->transactionStarted = true;
        Cache::store('array')->flush();
        app(ApprovalWorkflowSettingService::class)->ensureDefaults();
    }

    protected function tearDown(): void
    {
        if ($this->transactionStarted) {
            DB::rollBack();
            $this->transactionStarted = false;
        }

        parent::tearDown();
    }

    public function test_resolve_setting_is_memoized_within_request(): void
    {
        $service = app(ApprovalWorkflowSettingService::class);
        $first = $service->resolveSetting('leave');
        $second = $service->resolveSetting('leave');

        $this->assertSame($first, $second);
        $this->assertTrue(Cache::store('array')->has('approval_workflow_settings:payload:leave'));
    }

    public function test_resolve_approval_chain_reuses_request_cache(): void
    {
        $employee = \App\Models\User::query()->approvableEmployees()->active()->orderBy('id')->first();
        if (! $employee) {
            $this->markTestSkipped('No active employee available.');
        }

        $resolver = app(HrApprovalChainResolver::class);
        $first = $resolver->resolveApprovalChain($employee, 'leave', $employee);
        $second = $resolver->resolveApprovalChain($employee, 'leave', $employee, [
            'request_id' => 999999,
            'module_type' => 'leave',
        ]);

        $this->assertSame(
            array_map(static fn (array $step): int => (int) $step['approver_id'], $first),
            array_map(static fn (array $step): int => (int) $step['approver_id'], $second),
        );
    }
}
