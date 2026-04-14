<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        // type was enum('regular','special_non_working','company_event') but app uses 'regular','special','company'
        // Change to varchar(50) to accept app values
        DB::statement("ALTER TABLE holidays MODIFY COLUMN type VARCHAR(50) NOT NULL DEFAULT 'regular'");
    }

    public function down(): void
    {
        DB::statement("ALTER TABLE holidays MODIFY COLUMN type ENUM('regular','special_non_working','company_event') NOT NULL DEFAULT 'regular'");
    }
};
