<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('attendance_logs')) {
            return;
        }

        $this->withUtcMysqlSession(function (): void {
            DB::statement('ALTER TABLE attendance_logs
                MODIFY verified_at DATETIME NULL,
                MODIFY created_at DATETIME NULL,
                MODIFY updated_at DATETIME NULL');
        });
    }

    public function down(): void
    {
        if (! Schema::hasTable('attendance_logs')) {
            return;
        }

        $this->withUtcMysqlSession(function (): void {
            DB::statement('ALTER TABLE attendance_logs
                MODIFY verified_at TIMESTAMP NULL DEFAULT NULL,
                MODIFY created_at TIMESTAMP NULL DEFAULT NULL,
                MODIFY updated_at TIMESTAMP NULL DEFAULT NULL');
        });
    }

    private function withUtcMysqlSession(callable $callback): void
    {
        $previous = DB::selectOne('SELECT @@session.time_zone AS time_zone')?->time_zone ?? '+00:00';

        DB::statement("SET time_zone = '+00:00'");

        try {
            $callback();
        } finally {
            DB::statement("SET time_zone = '{$previous}'");
        }
    }
};
