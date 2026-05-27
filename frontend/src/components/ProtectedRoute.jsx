import { Navigate, useLocation } from 'react-router-dom'
import { useAuth } from '@/contexts/AuthContext'
import { isAdminHrUser, isManagerialHrRole, resolvePostLoginPath } from '@/lib/hrRoutes'

/**
 * Protects routes by authentication and role variant.
 *
 * - variant="adminHr" — only ADMIN (HR); `users.role === admin` or `hr_role === admin_hr`
 * - variant="manager" — only company_head, branch_head, department_head
 * - variant="employee" — `users.role === employee` and not an assigned org head (`is_assigned_organization_head`);
 *   line employees stay on `/employee/*`; company/branch/department heads go to their scoped panel.
 *
 * Legacy: `role="admin"` → adminHr, `role="employee"` → employee.
 */
export function ProtectedRoute({ children, variant, role, permissions = [] }) {
  const resolvedVariant =
    variant ??
    (role === 'admin' ? 'adminHr' : role === 'employee' ? 'employee' : undefined) ??
    'employee'
  const { user, loading } = useAuth()
  const location = useLocation()

  if (loading) {
    return (
      <div className="flex min-h-screen items-center justify-center bg-background">
        <div className="text-center">
          <div className="inline-block size-8 animate-spin rounded-full border-2 border-primary border-t-transparent" aria-hidden />
          <p className="mt-3 text-sm text-muted-foreground">Loading…</p>
        </div>
      </div>
    )
  }

  if (!user) {
    return <Navigate to="/login" state={{ from: location }} replace />
  }

  if (resolvedVariant === 'adminHr') {
    if (!isAdminHrUser(user)) {
      // Managerial org-head accounts are expected to use scoped HR panels.
      // Redirect silently to avoid showing a misleading "no access" bug toast.
      if (isManagerialHrRole(user)) {
        return <Navigate to={resolvePostLoginPath(user)} replace />
      }
      try {
        sessionStorage.setItem('hr_access_denied', '1')
      } catch {
        // ignore
      }
      return <Navigate to={resolvePostLoginPath(user)} replace />
    }
    try {
      sessionStorage.removeItem('hr_access_denied')
    } catch {
      // ignore
    }
    if (permissions.length) {
      const userPermissions = new Set(user.permissions ?? [])
      if (!permissions.some((permission) => userPermissions.has(permission))) {
        return <Navigate to={resolvePostLoginPath(user)} replace />
      }
    }
    return children
  }

  if (resolvedVariant === 'manager') {
    if (!isManagerialHrRole(user)) {
      return <Navigate to={resolvePostLoginPath(user)} replace />
    }
    if (permissions.length) {
      const userPermissions = new Set(user.permissions ?? [])
      if (!permissions.some((permission) => userPermissions.has(permission))) {
        return <Navigate to={resolvePostLoginPath(user)} replace />
      }
    }
    return children
  }

  if (resolvedVariant === 'employee') {
    if (isAdminHrUser(user)) {
      return <Navigate to="/admin/dashboard" replace />
    }
    if (permissions.length) {
      const userPermissions = new Set(user.permissions ?? [])
      if (!permissions.some((permission) => userPermissions.has(permission))) {
        return <Navigate to={resolvePostLoginPath(user)} replace />
      }
    }
    return children
  }

  return children
}
