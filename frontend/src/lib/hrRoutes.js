/**
 * HR panel routing (must stay aligned with `ProtectedRoute` and `HrPanelLayout`):
 * - `/admin/*` — ADMIN (HR)
 * - `/company/*`, `/branch/*`, `/department/*` — org heads (scoped manager UI, not full admin)
 * - `/employee/*` — accounts with `users.role === employee` only (not org heads)
 */

/** Full HR admin (legacy `users.role = admin` or resolved `admin_hr`). */
export function isAdminHrUser(userLike) {
  if (!userLike) return false
  const role = String(userLike.role || '').trim().toLowerCase()
  const hrRole = String(userLike.hr_role || '').trim().toLowerCase()
  if (role === 'admin') return true
  return hrRole === 'admin_hr' || hrRole === 'admin'
}

export function isManagerialHrRole(userLike) {
  const hr = String(userLike?.hr_role || '').trim().toLowerCase()
  return hr === 'company_head' || hr === 'branch_head' || hr === 'department_head'
}

/** Base path for in-app navigation (not API). */
export function getHrPanelBasePath(userLike) {
  if (!userLike) return '/employee'
  if (isAdminHrUser(userLike)) return '/admin'
  const hr = String(userLike.hr_role || '').trim().toLowerCase()
  if (hr === 'company_head') return '/company'
  if (hr === 'branch_head') return '/branch'
  if (hr === 'department_head') return '/department'
  return '/employee'
}

/**
 * Default route after login / home redirect.
 */
export function resolvePostLoginPath(userLike) {
  if (!userLike) return '/login'
  if (isAdminHrUser(userLike)) return '/admin/dashboard'
  const hr = String(userLike.hr_role || '').trim().toLowerCase()
  if (hr === 'company_head') return '/company/dashboard'
  if (hr === 'branch_head') return '/branch/dashboard'
  if (hr === 'department_head') return '/department/dashboard'
  if (String(userLike.role || '').trim().toLowerCase() === 'employee') return '/employee/dashboard'
  return '/login'
}

/**
 * Join panel base with a segment like `employees` or `/employees`.
 */
export function hrPanelPath(basePath, segment) {
  const b = (basePath || '/employee').replace(/\/$/, '')
  const s = String(segment || '').startsWith('/') ? segment : `/${segment}`
  return `${b}${s}`
}
