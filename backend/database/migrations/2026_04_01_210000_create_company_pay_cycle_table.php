<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('company_pay_cycle')) {
            Schema::create('company_pay_cycle', function (Blueprint $table) {
                $table->id();
                $table->foreignId('company_id')->constrained()->cascadeOnDelete();
                $table->foreignId('pay_cycle_id')->constrained()->cascadeOnDelete();
                $table->timestamps();

                $table->unique(['company_id', 'pay_cycle_id']);
                $table->index(['pay_cycle_id', 'company_id']);
            });
        }

        if (Schema::hasTable('pay_cycles') && Schema::hasTable('companies')) {
            DB::table('pay_cycles')
                ->select(['id', 'company_id', 'created_at', 'updated_at'])
                ->whereNotNull('company_id')
                ->orderBy('id')
                ->chunkById(200, function ($cycles): void {
                    $rows = [];
                    foreach ($cycles as $cycle) {
                        $exists = DB::table('company_pay_cycle')
                            ->where('company_id', $cycle->company_id)
                            ->where('pay_cycle_id', $cycle->id)
                            ->exists();

                        if (! $exists) {
                            $rows[] = [
                                'company_id' => $cycle->company_id,
                                'pay_cycle_id' => $cycle->id,
                                'created_at' => $cycle->created_at,
                                'updated_at' => $cycle->updated_at,
                            ];
                        }
                    }

                    if ($rows !== []) {
                        DB::table('company_pay_cycle')->insert($rows);
                    }
                });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('company_pay_cycle');
    }
};
