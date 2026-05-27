<?php

use App\Enums\EmploymentStatus;
use Carbon\Carbon;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            if (! Schema::hasColumn('users', 'regularization_date')) {
                $table->date('regularization_date')->nullable()->after('hire_date');
            }
            if (! Schema::hasColumn('users', 'status_override')) {
                $table->boolean('status_override')->default(false)->after('employment_status_effective_date');
            }
            if (! Schema::hasColumn('users', 'leave_credits_initialized_at')) {
                $table->timestamp('leave_credits_initialized_at')->nullable()->after('leave_credits_reset_date');
            }
        });

        if (! Schema::hasColumn('users', 'hire_date')) {
            return;
        }

        $today = Carbon::now(config('attendance.timezone', config('app.timezone', 'Asia/Manila')))->startOfDay();
        DB::table('users')
            ->whereNotNull('hire_date')
            ->where(function ($query): void {
                $query->whereNull('status_override')->orWhere('status_override', false);
            })
            ->orderBy('id')
            ->chunkById(200, function ($users) use ($today): void {
                foreach ($users as $user) {
                    $regularizationDate = Carbon::parse($user->hire_date)->addMonths(6)->toDateString();
                    $resolvedStatus = Carbon::parse($regularizationDate)->startOfDay()->lessThanOrEqualTo($today)
                        ? EmploymentStatus::Regular->value
                        : EmploymentStatus::Probationary->value;

                    DB::table('users')->where('id', $user->id)->update([
                        'regularization_date' => $regularizationDate,
                        'employment_status' => $resolvedStatus,
                        'employment_status_effective_date' => $resolvedStatus === EmploymentStatus::Regular->value
                            ? $regularizationDate
                            : ($user->employment_status_effective_date ?? $user->hire_date),
                        'updated_at' => now(),
                    ]);
                }
            });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            if (Schema::hasColumn('users', 'leave_credits_initialized_at')) {
                $table->dropColumn('leave_credits_initialized_at');
            }
            if (Schema::hasColumn('users', 'status_override')) {
                $table->dropColumn('status_override');
            }
            if (Schema::hasColumn('users', 'regularization_date')) {
                $table->dropColumn('regularization_date');
            }
        });
    }
};
