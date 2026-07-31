<?php

use App\Services\RbacService;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('role_permissions') || ! Schema::hasTable('permissions')) {
            return;
        }

        $now = now();
        $slugs = [
            'profile.view',
            'profile.picture.edit',
            'view-my-schedule',
            'request-schedule',
            'approve-schedule',
            'attendance.view',
            'can_view_subordinate_attendance',
            'attendance.corrections.create',
            'attendance.corrections.approve',
            'can_view_my_filings',
            'can_view_assigned_approvals',
            'can_view_team_filings',
            'leave.view',
            'leave.approve',
            'leave.notes',
            'loans.view_own',
            'request-loan',
            'loans.request',
            'loans.view',
            'loans.assign',
            'overtime.view',
            'overtime.approve',
            'government_deductions.view',
            'government_deductions.rates.view',
            'payslip.view',
            'payslip.download',
            'holidays.view',
            'can_access_reports_module',
            'can_view_own_reports',
            'evaluations.view',
            'evaluations.create',
        ];

        $permissionIds = DB::table('permissions')
            ->whereIn('slug', $slugs)
            ->pluck('id', 'slug');

        foreach ($slugs as $slug) {
            $permissionId = $permissionIds[$slug] ?? null;
            if (! $permissionId) {
                continue;
            }

            DB::table('role_permissions')->updateOrInsert(
                ['role_key' => 'officer_in_charge', 'permission_id' => $permissionId],
                ['created_at' => $now, 'updated_at' => $now],
            );
        }

        RbacService::forgetRoleCache('officer_in_charge');
    }

    public function down(): void
    {
        if (! Schema::hasTable('role_permissions')) {
            return;
        }

        DB::table('role_permissions')->where('role_key', 'officer_in_charge')->delete();
        RbacService::forgetRoleCache('officer_in_charge');
    }
};
