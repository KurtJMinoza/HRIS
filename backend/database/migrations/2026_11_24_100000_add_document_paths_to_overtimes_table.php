<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('overtimes', function (Blueprint $table) {
            $table->json('document_paths')->nullable()->after('attachment_path');
        });

        if (Schema::hasColumn('overtimes', 'attachment_path')) {
            foreach (
                DB::table('overtimes')->whereNotNull('attachment_path')->get(['id', 'attachment_path']) as $row
            ) {
                DB::table('overtimes')->where('id', $row->id)->update([
                    'document_paths' => json_encode([$row->attachment_path]),
                ]);
            }
        }
    }

    public function down(): void
    {
        Schema::table('overtimes', function (Blueprint $table) {
            $table->dropColumn('document_paths');
        });
    }
};