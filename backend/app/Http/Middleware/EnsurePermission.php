<?php

namespace App\Http\Middleware;

use App\Enums\HrRole;
use App\Models\Permission;
use App\Services\HrRoleResolver;
use App\Services\PermissionAuditService;
use App\Services\RbacService;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;
use Throwable;

/**
 * Require at least one permission from a pipe-separated list (OR).
 * Example: middleware('permission:employees.view|employees.edit')
 */
class EnsurePermission
{
    public function __construct(
        private readonly RbacService $rbacService,
        private readonly HrRoleResolver $hrRoleResolver,
        private readonly PermissionAuditService $auditService,
    ) {}

    public function handle(Request $request, Closure $next, string $permissionList): Response
    {
        $user = $request->user();
        if (! $user) {
            return response()->json(['message' => 'Unauthenticated.'], 401);
        }

        $slugs = array_filter(array_map('trim', explode('|', $permissionList)));
        if ($slugs === []) {
            return $next($request);
        }

        // Admin (HR) super-role: full access (matches RbacService::can for admin accounts).
        if ($this->hrRoleResolver->resolve($user) === HrRole::AdminHr) {
            return $next($request);
        }

        if ($this->rbacService->canAny($user, $slugs)) {
            $this->auditDecision($request, $slugs, 'permission_granted');
            return $next($request);
        }

        $this->auditDecision($request, $slugs, 'permission_denied');

        return response()->json([
            'message' => 'Forbidden. Missing permission.',
            'required' => $slugs,
        ], 403);
    }

    /**
     * @param  list<string>  $slugs
     */
    private function auditDecision(Request $request, array $slugs, string $action): void
    {
        try {
            $actor = $request->user();
            if (! $actor) {
                return;
            }

            $permission = Permission::query()->whereIn('slug', $slugs)->first();
            if (! $permission) {
                return;
            }

            $this->auditService->log(
                $actor,
                $this->hrRoleResolver->resolve($actor)->value,
                $permission,
                $action,
                $request,
                [
                    'route' => $request->path(),
                    'method' => $request->method(),
                    'required' => $slugs,
                ],
            );
        } catch (Throwable) {
            // Permission checks should never fail because audit persistence is unavailable.
        }
    }
}
