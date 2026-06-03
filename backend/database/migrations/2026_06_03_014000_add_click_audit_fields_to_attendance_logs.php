<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('attendance_logs')) {
            return;
        }

        Schema::table('attendance_logs', function (Blueprint $table): void {
            if (! Schema::hasColumn('attendance_logs', 'time_in_clicked_at')) {
                $table->dateTime('time_in_clicked_at')->nullable()->after('verified_at');
            }
            if (! Schema::hasColumn('attendance_logs', 'time_out_clicked_at')) {
                $table->dateTime('time_out_clicked_at')->nullable()->after('time_in_clicked_at');
            }
            if (! Schema::hasColumn('attendance_logs', 'server_received_at')) {
                $table->dateTime('server_received_at')->nullable()->after('time_out_clicked_at');
            }
            if (! Schema::hasColumn('attendance_logs', 'validation_completed_at')) {
                $table->dateTime('validation_completed_at')->nullable()->after('server_received_at');
            }
            if (! Schema::hasColumn('attendance_logs', 'method')) {
                $table->string('method', 40)->nullable()->after('authentication_method');
            }
            if (! Schema::hasColumn('attendance_logs', 'processing_delay_seconds')) {
                $table->unsignedInteger('processing_delay_seconds')->nullable()->after('method');
            }
            if (! Schema::hasColumn('attendance_logs', 'client_attempt_id')) {
                $table->string('client_attempt_id', 80)->nullable()->after('processing_delay_seconds');
                $table->unique('client_attempt_id', 'attendance_logs_client_attempt_id_unique');
            }
        });
    }

    public function down(): void
    {
        if (! Schema::hasTable('attendance_logs')) {
            return;
        }

        Schema::table('attendance_logs', function (Blueprint $table): void {
            if (Schema::hasColumn('attendance_logs', 'client_attempt_id')) {
                $table->dropUnique('attendance_logs_client_attempt_id_unique');
            }

            foreach ([
                'time_in_clicked_at',
                'time_out_clicked_at',
                'server_received_at',
                'validation_completed_at',
                'method',
                'processing_delay_seconds',
                'client_attempt_id',
            ] as $column) {
                if (Schema::hasColumn('attendance_logs', $column)) {
                    $table->dropColumn($column);
                }
            }
        });
    }
};
