<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('organization_position_types')) {
            return;
        }

        $retired = ['Co-Company Head', 'Co Company Head', 'Assistant Company Head'];

        DB::table('organization_position_types')
            ->where('organization_level', 'company')
            ->whereIn('position_name', $retired)
            ->update(['is_active' => false]);
    }

    public function down(): void
    {
        if (! Schema::hasTable('organization_position_types')) {
            return;
        }

        DB::table('organization_position_types')
            ->where('organization_level', 'company')
            ->whereIn('position_name', ['Co-Company Head', 'Co Company Head', 'Assistant Company Head'])
            ->update(['is_active' => true]);
    }
};
