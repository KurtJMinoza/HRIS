<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('tax_tables')) {
            return;
        }

        Schema::create('tax_tables', function (Blueprint $table) {
            $table->id();
            $table->unsignedSmallInteger('calendar_year');
            $table->string('code', 64)->index();
            $table->string('label', 255);
            $table->date('effective_from');
            $table->date('effective_to')->nullable();
            $table->json('payload');
            $table->boolean('is_active')->default(true);
            $table->string('source_reference', 512)->nullable();
            $table->timestamps();
            $table->unique(['calendar_year', 'code', 'effective_from']);
        });

        DB::table('tax_tables')->insert([
            'calendar_year' => 2026,
            'code' => 'train_annual',
            'label' => 'TRAIN (RA 10963) annual income tax — graduated schedule',
            'effective_from' => '2018-01-01',
            'effective_to' => null,
            'payload' => json_encode([
                'type' => 'train_annual',
                'description' => 'NIRC Sec. 24(A) as amended; verify against current BIR issuances.',
                'brackets' => [
                    ['up_to' => 250000, 'base_tax' => 0, 'rate' => 0, 'over' => 0],
                    ['up_to' => 400000, 'base_tax' => 0, 'rate' => 0.15, 'over' => 250000],
                    ['up_to' => 800000, 'base_tax' => 22500, 'rate' => 0.20, 'over' => 400000],
                    ['up_to' => 2000000, 'base_tax' => 102500, 'rate' => 0.25, 'over' => 800000],
                    ['up_to' => 8000000, 'base_tax' => 402500, 'rate' => 0.30, 'over' => 2000000],
                    ['up_to' => null, 'base_tax' => 2202500, 'rate' => 0.35, 'over' => 8000000],
                ],
            ]),
            'is_active' => true,
            'source_reference' => 'RA 10963 (TRAIN); BIR RR 8-2018 / withholding RR 11-2018 family',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    public function down(): void
    {
        Schema::dropIfExists('tax_tables');
    }
};
