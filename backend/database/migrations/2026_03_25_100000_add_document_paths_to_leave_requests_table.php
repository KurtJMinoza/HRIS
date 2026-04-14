<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('leave_requests', function (Blueprint $table) {
            $table->json('document_paths')->nullable()->after('document_path');
        });

        if (Schema::hasColumn('leave_requests', 'document_path')) {
            foreach (
                DB::table('leave_requests')->whereNotNull('document_path')->get(['id', 'document_path']) as $row
            ) {
                DB::table('leave_requests')->where('id', $row->id)->update([
                    'document_paths' => json_encode([$row->document_path]),
                ]);
            }
        }
    }

    public function down(): void
    {
        Schema::table('leave_requests', function (Blueprint $table) {
            $table->dropColumn('document_paths');
        });
    }
};
