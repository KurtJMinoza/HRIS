<?php

use App\Services\RbacService;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Tracks who filed the loan (manager on behalf of employee). Canonical submit permission: {@see Permission slug `request-loan`}.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('pay_loan_requests') && ! Schema::hasColumn('pay_loan_requests', 'requested_by_user_id')) {
            Schema::table('pay_loan_requests', function (Blueprint $table) {
                $table->foreignId('requested_by_user_id')
                    ->nullable()
                    ->after('user_id')
                    ->constrained('users')
                    ->nullOnDelete();
            });

            DB::table('pay_loan_requests')->whereNull('requested_by_user_id')->update(['requested_by_user_id' => DB::raw('user_id')]);
        }

        $slug = 'request-loan';
        $exists = DB::table('permissions')->where('slug', $slug)->exists();
        if (! $exists) {
            DB::table('permissions')->insert([
                'slug' => $slug,
                'module' => 'loans',
                'label' => 'Request loan (self or scoped employee)',
                'description' => 'Submit a loan request for yourself or, for org heads, an employee in your scope',
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }

        $pid = DB::table('permissions')->where('slug', $slug)->value('id');
        if ($pid) {
            $roles = ['employee', 'company_head', 'branch_head', 'department_head'];
            foreach ($roles as $roleKey) {
                $exists = DB::table('role_permissions')
                    ->where('role_key', $roleKey)
                    ->where('permission_id', $pid)
                    ->exists();
                if ($exists) {
                    continue;
                }
                DB::table('role_permissions')->insert([
                    'role_key' => $roleKey,
                    'permission_id' => $pid,
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);
                RbacService::forgetRoleCache($roleKey);
            }
        }
    }

    public function down(): void
    {
        $pid = DB::table('permissions')->where('slug', 'request-loan')->value('id');
        if ($pid) {
            DB::table('role_permissions')->where('permission_id', $pid)->delete();
            foreach (['employee', 'company_head', 'branch_head', 'department_head'] as $roleKey) {
                RbacService::forgetRoleCache($roleKey);
            }
            DB::table('permissions')->where('id', $pid)->delete();
        }

        if (Schema::hasTable('pay_loan_requests') && Schema::hasColumn('pay_loan_requests', 'requested_by_user_id')) {
            Schema::table('pay_loan_requests', function (Blueprint $table) {
                $table->dropConstrainedForeignId('requested_by_user_id');
            });
        }
    }
};
