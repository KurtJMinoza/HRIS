<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('attendance_logs') && ! $this->indexExists('attendance_logs', 'attendance_logs_user_id_created_at_index')) {
            Schema::table('attendance_logs', function (Blueprint $table): void {
                $table->index(['user_id', 'created_at'], 'attendance_logs_user_id_created_at_index');
            });
        }

        if (Schema::hasTable('email_logs') && ! $this->indexExists('email_logs', 'email_logs_status_created_at_index')) {
            Schema::table('email_logs', function (Blueprint $table): void {
                $table->index(['status', 'created_at'], 'email_logs_status_created_at_index');
            });
        }
    }

    private function indexExists(string $table, string $indexName): bool
    {
        $indexes = DB::select("SHOW INDEX FROM `{$table}` WHERE Key_name = ?", [$indexName]);

        return count($indexes) > 0;
    }
};
