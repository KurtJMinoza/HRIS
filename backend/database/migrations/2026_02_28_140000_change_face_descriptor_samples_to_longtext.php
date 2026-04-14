<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * face_descriptor_samples stores encrypted JSON; MySQL JSON type rejects non-JSON.
     * Change to longText to support encrypted payloads.
     */
    public function up(): void
    {
        $driver = DB::getDriverName();
        if ($driver === 'mysql') {
            DB::statement('ALTER TABLE users MODIFY face_descriptor_samples LONGTEXT NULL');
        }
    }

    public function down(): void
    {
        $driver = DB::getDriverName();
        if ($driver === 'mysql') {
            DB::statement('ALTER TABLE users MODIFY face_descriptor_samples JSON NULL');
        }
    }
};
