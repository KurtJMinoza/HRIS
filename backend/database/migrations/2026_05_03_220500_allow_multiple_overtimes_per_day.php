<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    private function indexExists(string $table, string $indexName): bool
    {
        $dbName = DB::getDatabaseName();
        $row = DB::selectOne(
            'SELECT 1 FROM information_schema.statistics WHERE table_schema = ? AND table_name = ? AND index_name = ? LIMIT 1',
            [$dbName, $table, $indexName]
        );

        return $row !== null;
    }

    public function up(): void
    {
        // MySQL requires an index on FK columns; add a dedicated user_id index first
        // so dropping the old unique(user_id, date) index does not break the FK.
        if (! $this->indexExists('overtimes', 'overtimes_user_id_idx')) {
            Schema::table('overtimes', function (Blueprint $table) {
                $table->index('user_id', 'overtimes_user_id_idx');
            });
        }

        Schema::table('overtimes', function (Blueprint $table) {
            if ($this->indexExists('overtimes', 'overtimes_user_id_date_unique')) {
                $table->dropUnique('overtimes_user_id_date_unique');
            }
            if (! $this->indexExists('overtimes', 'overtimes_user_date_idx')) {
                $table->index(['user_id', 'date'], 'overtimes_user_date_idx');
            }
        });
    }

    public function down(): void
    {
        Schema::table('overtimes', function (Blueprint $table) {
            if ($this->indexExists('overtimes', 'overtimes_user_date_idx')) {
                $table->dropIndex('overtimes_user_date_idx');
            }
            if (! $this->indexExists('overtimes', 'overtimes_user_id_date_unique')) {
                $table->unique(['user_id', 'date']);
            }
        });
    }
};
