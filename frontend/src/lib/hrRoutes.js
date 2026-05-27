/**
 * HR panel routing (must stay aligned with `ProtectedRoute` and `HrPanelLayout`):
 * - `/admin/*` — ADMIN (HR)
 * - `/employee/*` — normal employees and org heads by default
 */

/** Full HR admin (legacy `users.role = admin` or resolved `admin_hr`). */
export function isAdminHrUser(userLike) {
  if (!userLike) return false
  const role = String(userLike.role || '').trim().toLowerCase()
  const hrRole = String(userLike.hr_role || '').trim().toLowerCase()
  if (role === 'admin' || role === 'super_admin') return true
  return hrRole === 'admin_hr' || hrRole === 'admin'
}

export function hasManagementPanelAccess(userLike) {
  if (!userLike || isAdminHrUser(userLike)) return false
  return Boolean(
    userLike.can_view_admin_dashboard ||
    userLike.can_view_employee_module ||
    userLike.can_view_subordinate_attendance ||
    userLike.can_view_subordinate_reports,
  )
}

/**
 * Org-head accounts are recognized for scoped data/labels, but they still use the employee shell.
 *
 * Prefer `is_assigned_organization_head` from the API (DB-backed org assignments). Some cached or
 * secondary payloads only expose `management_role`; use that before `hr_role` so badge / display
 * resolution cannot incorrectly block line employees from `/employee/qr` and face registration.
 */
export function isManagerialHrRole(userLike) {
  if (!userLike) return false
  if (!hasManagementPanelAccess(userLike)) return false
  if (typeof userLike.is_assigned_organization_head === 'boolean') {
    return userLike.is_assigned_organization_head
  }
  const mgmt = String(userLike.management_role || '').trim().toLowerCase()
  if (mgmt === 'company_head' || mgmt === 'branch_head' || mgmt === 'department_head' || mgmt === 'division_head' || mgmt === 'section_unit_head') {
    return true
  }
  const hr = String(userLike?.hr_role || '').trim().toLowerCase()
  return hr === 'company_head' || hr === 'branch_head' || hr === 'department_head' || hr === 'division_head' || hr === 'section_unit_head'
}

/** Base path for in-app navigation (not API). */
export function getHrPanelBasePath(userLike) {
  if (!userLike) return '/employee'
  if (isAdminHrUser(userLike)) return '/admin'
  return '/employee'
}

/**
 * Default route after login / home redirect.
 */
export function resolvePostLoginPath(userLike) {
  if (!userLike) return '/login'
  if (isAdminHrUser(userLike)) return '/admin/dashboard'
  return '/employee/dashboard'
}

/**
 * Join panel base with a segment like `employees` or `/employees`.
 */
export function hrPanelPath(basePath, segment) {
  const b = (basePath || '/employee').replace(/\/$/, '')
  const s = String(segment || '').startsWith('/') ? segment : `/${segment}`
  return `${b}${s}`
}
