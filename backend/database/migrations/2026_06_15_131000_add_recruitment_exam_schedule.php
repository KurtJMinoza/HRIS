<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('recruitment_exam_assignments', function (Blueprint $table): void {
            if (! Schema::hasColumn('recruitment_exam_assignments', 'scheduled_at')) {
                $table->dateTime('scheduled_at')->nullable()->after('exam_link_token')->index();
            }
        });
    }

    public function down(): void
    {
        Schema::table('recruitment_exam_assignments', function (Blueprint $table): void {
            if (Schema::hasColumn('recruitment_exam_assignments', 'scheduled_at')) {
                $table->dropColumn('scheduled_at');
            }
        });
    }
};
