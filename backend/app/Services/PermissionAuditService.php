<?php

namespace App\Services;

use App\Models\Permission;
use App\Models\PermissionAuditLog;
use App\Models\User;
use Illuminate\Http\Request;

class PermissionAuditService
{
    public function log(
        User $actor,
        string $targetRoleKey,
        Permission $permission,
        string $action,
        ?Request $request = null,
        ?array $context = null,
    ): void {
        PermissionAuditLog::query()->create([
            'actor_user_id' => $actor->id,
            'target_role_key' => $targetRoleKey,
            'permission_id' => $permission->id,
            'permission_slug' => $permission->slug,
            'action' => $action,
            'context' => $context,
            'ip_address' => $request?->ip(),
            'user_agent' => $request?->userAgent(),
        ]);
    }
}
