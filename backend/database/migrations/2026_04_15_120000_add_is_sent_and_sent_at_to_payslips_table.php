<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('payslips')) {
            return;
        }

        Schema::table('payslips', function (Blueprint $table) {
            if (! Schema::hasColumn('payslips', 'is_sent')) {
                $table->boolean('is_sent')->default(false)->after('delivered_at');
            }
            if (! Schema::hasColumn('payslips', 'sent_at')) {
                $table->timestamp('sent_at')->nullable()->after('is_sent');
            }
        });

        if (Schema::hasColumn('payslips', 'is_sent') && Schema::hasColumn('payslips', 'delivered_at')) {
            DB::table('payslips')
                ->whereNotNull('delivered_at')
                ->update([
                    'is_sent' => true,
                    'sent_at' => DB::raw('COALESCE(sent_at, delivered_at)'),
                ]);
        }
    }

    public function down(): void
    {
        if (! Schema::hasTable('payslips')) {
            return;
        }

        Schema::table('payslips', function (Blueprint $table) {
            if (Schema::hasColumn('payslips', 'sent_at')) {
                $table->dropColumn('sent_at');
            }
            if (Schema::hasColumn('payslips', 'is_sent')) {
                $table->dropColumn('is_sent');
            }
        });
    }
};
