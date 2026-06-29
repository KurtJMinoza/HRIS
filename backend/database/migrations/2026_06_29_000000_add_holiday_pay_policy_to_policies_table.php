<?php

use App\Models\Policy;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasColumn('policies', 'holiday_policy')) {
            Schema::table('policies', function (Blueprint $table): void {
                $table->json('holiday_policy')->nullable()->after('priority_order_json');
            });
        }

        DB::table('policies')
            ->whereNull('holiday_policy')
            ->update(['holiday_policy' => json_encode(Policy::DEFAULT_HOLIDAY_POLICY, JSON_THROW_ON_ERROR)]);
    }

    public function down(): void
    {
        if (Schema::hasColumn('policies', 'holiday_policy')) {
            Schema::table('policies', function (Blueprint $table): void {
                $table->dropColumn('holiday_policy');
            });
        }
    }
};
