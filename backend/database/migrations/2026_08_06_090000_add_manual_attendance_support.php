<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    private const REVISIONS_INDEX = 'manual_att_rev_record_changed_idx';

    private const REVISIONS_FK = 'manual_att_rev_record_fk';

    public function up(): void
    {
        Schema::table('attendance_corrections', function (Blueprint $table) {
            if (! Schema::hasColumn('attendance_corrections', 'source_type')) {
                $table->string('source_type', 32)->nullable()->after('remarks');
            }
            if (! Schema::hasColumn('attendance_corrections', 'is_manual')) {
                $table->boolean('is_manual')->default(false)->after('source_type');
            }
            if (! Schema::hasColumn('attendance_corrections', 'manual_reason_code')) {
                $table->string('manual_reason_code', 64)->nullable()->after('is_manual');
            }
            if (! Schema::hasColumn('attendance_corrections', 'manual_remarks')) {
                $table->text('manual_remarks')->nullable()->after('manual_reason_code');
            }
            if (! Schema::hasColumn('attendance_corrections', 'created_by_admin_id')) {
                $table->unsignedBigInteger('created_by_admin_id')->nullable()->after('manual_remarks');
            }
            if (! Schema::hasColumn('attendance_corrections', 'approved_by_admin_id')) {
                $table->unsignedBigInteger('approved_by_admin_id')->nullable()->after('approved_by');
            }
            if (! Schema::hasColumn('attendance_corrections', 'reversed_at')) {
                $table->dateTime('reversed_at')->nullable()->after('approved_at');
            }
            if (! Schema::hasColumn('attendance_corrections', 'reversed_by_admin_id')) {
                $table->unsignedBigInteger('reversed_by_admin_id')->nullable()->after('reversed_at');
            }
            if (! Schema::hasColumn('attendance_corrections', 'reversal_reason')) {
                $table->text('reversal_reason')->nullable()->after('reversed_by_admin_id');
            }
            if (! Schema::hasColumn('attendance_corrections', 'work_segments')) {
                $table->json('work_segments')->nullable()->after('time_out');
            }
            if (! Schema::hasColumn('attendance_corrections', 'matched_schedule_option_id')) {
                $table->unsignedBigInteger('matched_schedule_option_id')->nullable()->after('work_segments');
            }
            if (! Schema::hasColumn('attendance_corrections', 'shift_match_mode')) {
                $table->string('shift_match_mode', 16)->nullable()->after('matched_schedule_option_id');
            }
        });

        if (! Schema::hasTable('manual_attendance_revisions')) {
            Schema::create('manual_attendance_revisions', function (Blueprint $table) {
                $table->id();
                $table->unsignedBigInteger('attendance_record_id');
                $table->json('previous_values')->nullable();
                $table->json('new_values')->nullable();
                $table->string('change_type', 32);
                $table->text('reason')->nullable();
                $table->unsignedBigInteger('changed_by')->nullable();
                $table->timestamp('changed_at');
                $table->timestamps();

                $table->foreign('attendance_record_id', self::REVISIONS_FK)
                    ->references('id')
                    ->on('attendance_corrections')
                    ->onDelete('cascade');
                $table->index(['attendance_record_id', 'changed_at'], self::REVISIONS_INDEX);
            });

            return;
        }

        // ponytail: recover from a prior failed run that created the table but died on MySQL's 64-char index name limit.
        Schema::table('manual_attendance_revisions', function (Blueprint $table) {
            if (! $this->foreignKeyExists('manual_attendance_revisions', self::REVISIONS_FK)) {
                $table->foreign('attendance_record_id', self::REVISIONS_FK)
                    ->references('id')
                    ->on('attendance_corrections')
                    ->onDelete('cascade');
            }
            if (! $this->indexExists('manual_attendance_revisions', self::REVISIONS_INDEX)) {
                $table->index(['attendance_record_id', 'changed_at'], self::REVISIONS_INDEX);
            }
        });
    }

    private function indexExists(string $table, string $indexName): bool
    {
        $db = Schema::getConnection()->getDatabaseName();

        return DB::table('information_schema.statistics')
            ->where('table_schema', $db)
            ->where('table_name', $table)
            ->where('index_name', $indexName)
            ->exists();
    }

    private function foreignKeyExists(string $table, string $constraintName): bool
    {
        $db = Schema::getConnection()->getDatabaseName();

        return DB::table('information_schema.table_constraints')
            ->where('table_schema', $db)
            ->where('table_name', $table)
            ->where('constraint_name', $constraintName)
            ->where('constraint_type', 'FOREIGN KEY')
            ->exists();
    }

    public function down(): void
    {
        Schema::dropIfExists('manual_attendance_revisions');

        Schema::table('attendance_corrections', function (Blueprint $table) {
            foreach ([
                'source_type',
                'is_manual',
                'manual_reason_code',
                'manual_remarks',
                'created_by_admin_id',
                'approved_by_admin_id',
                'reversed_at',
                'reversed_by_admin_id',
                'reversal_reason',
                'work_segments',
                'matched_schedule_option_id',
                'shift_match_mode',
            ] as $col) {
                if (Schema::hasColumn('attendance_corrections', $col)) {
                    $table->dropColumn($col);
                }
            }
        });
    }
};
