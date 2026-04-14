<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('pay_components')) {
            return;
        }

        Schema::table('pay_components', function (Blueprint $table) {
            if (! Schema::hasColumn('pay_components', 'code')) {
                $table->string('code')->nullable()->after('name');
            }
            if (! Schema::hasColumn('pay_components', 'category')) {
                $table->string('category', 64)->nullable()->after('type');
            }
            if (! Schema::hasColumn('pay_components', 'contributes_sss')) {
                $table->boolean('contributes_sss')->default(false)->after('is_taxable');
            }
            if (! Schema::hasColumn('pay_components', 'contributes_philhealth')) {
                $table->boolean('contributes_philhealth')->default(false)->after('contributes_sss');
            }
            if (! Schema::hasColumn('pay_components', 'contributes_pagibig')) {
                $table->boolean('contributes_pagibig')->default(false)->after('contributes_philhealth');
            }
            if (! Schema::hasColumn('pay_components', 'is_proratable')) {
                $table->boolean('is_proratable')->default(false)->after('contributes_pagibig');
            }
            if (! Schema::hasColumn('pay_components', 'effective_from')) {
                $table->date('effective_from')->nullable()->after('is_proratable');
            }
            if (! Schema::hasColumn('pay_components', 'effective_to')) {
                $table->date('effective_to')->nullable()->after('effective_from');
            }
            if (! Schema::hasColumn('pay_components', 'metadata')) {
                $table->json('metadata')->nullable()->after('is_active');
            }
        });

        $rows = DB::table('pay_components')->select('id', 'name', 'code')->orderBy('id')->get();
        foreach ($rows as $row) {
            if (filled($row->code)) {
                continue;
            }

            $base = Str::upper(Str::slug((string) ($row->name ?: 'COMPONENT'), '_'));
            $base = $base !== '' ? $base : 'COMPONENT';
            $candidate = $base;
            $suffix = 1;

            while (
                DB::table('pay_components')
                    ->where('code', $candidate)
                    ->where('id', '!=', $row->id)
                    ->exists()
            ) {
                $candidate = $base.'_'.$suffix;
                $suffix++;
            }

            DB::table('pay_components')
                ->where('id', $row->id)
                ->update(['code' => $candidate]);
        }

        if (! $this->indexExists('pay_components', 'pay_components_code_unique')) {
            Schema::table('pay_components', function (Blueprint $table) {
                $table->unique('code');
            });
        }

        if (! $this->indexExists('pay_components', 'pay_components_type_is_active_index')) {
            Schema::table('pay_components', function (Blueprint $table) {
                $table->index(['type', 'is_active']);
            });
        }

        if (! $this->indexExists('pay_components', 'pay_components_effective_from_effective_to_index')) {
            Schema::table('pay_components', function (Blueprint $table) {
                $table->index(['effective_from', 'effective_to']);
            });
        }
    }

    public function down(): void
    {
        // Intentionally left non-destructive because this migration reconciles an existing live table.
    }

    private function indexExists(string $table, string $indexName): bool
    {
        $result = DB::select('SHOW INDEX FROM `'.$table.'` WHERE Key_name = ?', [$indexName]);

        return ! empty($result);
    }
};
