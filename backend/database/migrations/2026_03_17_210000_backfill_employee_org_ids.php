<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * Sync employee company_id and branch_id from their department assignment.
     * Ensures Employee Profile → Employment Tab shows correct hierarchy.
     */
    public function up(): void
    {
        // Employees with department_id: set company_id, branch_id from department's branch
        DB::statement("
            UPDATE users u
            INNER JOIN departments d ON u.department_id = d.id
            INNER JOIN branches b ON d.branch_id = b.id
            SET u.company_id = b.company_id, u.branch_id = b.id
            WHERE u.role = 'employee'
            AND u.department_id IS NOT NULL
        ");

        // Branch managers without department: set company_id, branch_id from their managed branch
        DB::statement("
            UPDATE users u
            INNER JOIN branches b ON b.branch_manager_id = u.id
            SET u.company_id = b.company_id, u.branch_id = b.id
            WHERE u.role = 'employee'
            AND u.department_id IS NULL
        ");
    }

    public function down(): void
    {
        // No-op — data fix cannot be safely reverted.
    }
};
