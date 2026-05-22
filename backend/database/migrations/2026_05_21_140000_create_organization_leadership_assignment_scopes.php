<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    private const LEADERSHIP_ASSIGNMENT_FK = 'ol_scope_pos_assign_fk';

    public function up(): void
    {
        if (! Schema::hasTable('organization_leadership_assignment_scopes')) {
            Schema::create('organization_leadership_assignment_scopes', function (Blueprint $table) {
                $table->id();
                $table->unsignedBigInteger('leadership_assignment_id');
                $table->string('scope_type', 40);
                $table->unsignedBigInteger('scope_id')->nullable();
                $table->string('request_type', 40)->default('all');
                $table->string('requester_level', 40)->default('department_head');
                $table->boolean('is_active')->default(true);
                $table->timestamps();

                $table->foreign('leadership_assignment_id', self::LEADERSHIP_ASSIGNMENT_FK)
                    ->references('id')
                    ->on('organization_position_assignments')
                    ->cascadeOnDelete();

                $table->index(['leadership_assignment_id', 'scope_type'], 'ol_assignment_scopes_assignment_idx');
                $table->index(['scope_type', 'scope_id'], 'ol_assignment_scopes_scope_idx');
            });

            return;
        }

        // Recover from a prior failed run where the table was created but the FK name was too long.
        if (! $this->foreignKeyExists(self::LEADERSHIP_ASSIGNMENT_FK)) {
            Schema::table('organization_leadership_assignment_scopes', function (Blueprint $table) {
                $table->foreign('leadership_assignment_id', self::LEADERSHIP_ASSIGNMENT_FK)
                    ->references('id')
                    ->on('organization_position_assignments')
                    ->cascadeOnDelete();
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('organization_leadership_assignment_scopes');
    }

    private function foreignKeyExists(string $constraintName): bool
    {
        $database = Schema::getConnection()->getDatabaseName();
        $result = Schema::getConnection()->selectOne(
            'SELECT CONSTRAINT_NAME
             FROM information_schema.TABLE_CONSTRAINTS
             WHERE CONSTRAINT_SCHEMA = ?
               AND TABLE_NAME = ?
               AND CONSTRAINT_NAME = ?
               AND CONSTRAINT_TYPE = ?',
            [$database, 'organization_leadership_assignment_scopes', $constraintName, 'FOREIGN KEY'],
        );

        return $result !== null;
    }
};
