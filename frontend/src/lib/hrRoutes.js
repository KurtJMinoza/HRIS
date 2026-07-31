/**
 * HR panel routing (must stay aligned with `ProtectedRoute` and `HrPanelLayout`):
 * - `/admin/*` — ADMIN (HR)
 * - `/employee/*` — normal employees and org heads by default
 */

/** Full HR admin (legacy `users.role = admin` or resolved `admin_hr`). */
export function isAdminHrUser(userLike) {
  if (!userLike) return false
  if (userLike.is_hr_admin === true) return true
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
  if (mgmt === 'company_head' || mgmt === 'officer_in_charge' || mgmt === 'area_head' || mgmt === 'branch_head' || mgmt === 'department_head' || mgmt === 'division_head' || mgmt === 'section_unit_head') {
    return true
  }
  const hr = String(userLike?.hr_role || '').trim().toLowerCase()
  return hr === 'company_head' || hr === 'officer_in_charge' || hr === 'area_head' || hr === 'branch_head' || hr === 'department_head' || hr === 'division_head' || hr === 'section_unit_head'
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

/** Employee portal paths → My Workspace aliases under /admin (and other HR panels). */
const EMPLOYEE_SELF_SEGMENT_ALIASES = {
  dashboard: 'my-dashboard',
  schedule: 'my-schedule',
  qr: 'qr',
  requests: 'my-leave',
  overtime: 'my-overtime',
  attendance: 'my-attendance',
  'correction-requests': 'my-corrections',
  holidays: 'my-holidays',
  evaluations: 'evaluations',
  payslips: 'compensation/payslips',
  'loans-deductions': 'loans-deductions',
  profile: 'profile',
}

/**
 * Build a self-service href that works under `/employee` or admin My Workspace.
 * @param {string} basePath e.g. `/admin` or `/employee`
 * @param {string} employeePath e.g. `/employee/overtime?date=2026-01-01` or `overtime?date=...`
 */
export function employeeSelfHref(basePath, employeePath) {
  const raw = String(employeePath || '').replace(/^\/employee\/?/, '').replace(/^\//, '')
  const qIndex = raw.indexOf('?')
  const pathPart = qIndex >= 0 ? raw.slice(0, qIndex) : raw
  const query = qIndex >= 0 ? raw.slice(qIndex) : ''
  const segment = pathPart.split('/')[0] || 'dashboard'
  const rest = pathPart.includes('/') ? pathPart.slice(pathPart.indexOf('/')) : ''
  const base = (basePath || '/employee').replace(/\/$/, '') || '/employee'
  if (base === '/employee') {
    return `/employee/${pathPart}${query}`
  }
  const alias = EMPLOYEE_SELF_SEGMENT_ALIASES[segment] || segment
  return `${hrPanelPath(base, alias)}${rest}${query}`
}
