<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('pay_cycles')) {
            return;
        }

        Schema::table('pay_cycles', function (Blueprint $table) {
            if (! Schema::hasColumn('pay_cycles', 'company_id')) {
                $table->foreignId('company_id')->nullable()->after('id')->constrained()->nullOnDelete();
            }
            if (! Schema::hasColumn('pay_cycles', 'name')) {
                $table->string('name')->after('company_id');
            }
            if (! Schema::hasColumn('pay_cycles', 'code')) {
                $table->string('code', 50)->after('name');
            }
            if (! Schema::hasColumn('pay_cycles', 'cut_off_type')) {
                $table->string('cut_off_type', 30)->default('fixed_day')->after('code');
            }
            if (! Schema::hasColumn('pay_cycles', 'cut_off_value')) {
                $table->json('cut_off_value')->nullable()->after('cut_off_type');
            }
            if (! Schema::hasColumn('pay_cycles', 'pay_day_type')) {
                $table->string('pay_day_type', 30)->default('offset')->after('cut_off_value');
            }
            if (! Schema::hasColumn('pay_cycles', 'pay_day_value')) {
                $table->json('pay_day_value')->nullable()->after('pay_day_type');
            }
            if (! Schema::hasColumn('pay_cycles', 'pay_day_offset')) {
                $table->unsignedSmallInteger('pay_day_offset')->nullable()->after('pay_day_value');
            }
            if (! Schema::hasColumn('pay_cycles', 'pro_ration_type')) {
                $table->string('pro_ration_type', 20)->default('none')->after('pay_day_offset');
            }
            if (! Schema::hasColumn('pay_cycles', 'is_active')) {
                $table->boolean('is_active')->default(true)->after('pro_ration_type');
            }
            if (! Schema::hasColumn('pay_cycles', 'is_default')) {
                $table->boolean('is_default')->default(false)->after('is_active');
            }
            if (! Schema::hasColumn('pay_cycles', 'metadata')) {
                $table->json('metadata')->nullable()->after('is_default');
            }
        });
    }

    public function down(): void
    {
        // Reconciliation migration is intentionally non-destructive.
    }
};
