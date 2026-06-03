<?php

namespace App\Services\BulkApproval\Contracts;

use App\Models\User;

interface ApprovableBulkQuery
{
    /**
     * @param  array<string, mixed>  $filters
     */
    public function approvableCount(User $actor, array $filters): int;
}
