import { adminNavItems, employeeNavItems } from '@/config/dashboardNav'
import { isAdminHrUser } from '@/lib/hrRoutes'

/**
 * Routes only for ADMIN (HR) — non-admin HR panel roles never see these, even if a permission slug matches.
 * Prevents org heads from accessing Users & RBAC matrix and global pay policy screens.
 */
const PATHS_ADMIN_HR_ONLY = new Set([
  '/admin/users-permissions',
  '/admin/daily-computation/policy-settings',
  '/admin/approval-workflow-settings',
])

/** Minimum permission (any) required to show a nav entry; super admin sees all. */
const pathToPermissions = {
  '/admin': ['can_view_admin_dashboard'],
  '/admin/employees': ['can_view_employee_module'],
  '/admin/regularization': ['can_view_employee_module'],
  '/admin/users-permissions': ['users.view'],
  '/admin/companies': ['org.company.view'],
  '/admin/branches': ['org.branch.view'],
  '/admin/departments': ['org.department.view'],
  '/admin/divisions': ['org.division.view'],
  '/admin/sections-units': ['org.section_unit.view'],
  '/admin/holiday': ['holidays.view', 'holiday.view'],
  '/admin/leave': ['leave.view'],
  '/admin/attendance': ['can_view_subordinate_attendance'],
  /** Show nav if user can approve corrections OR at least view attendance (API still enforces approve on actions). */
  '/admin/attendance-corrections': ['attendance.corrections.approve', 'attendance.view'],
  '/admin/corrections': ['attendance.corrections.approve', 'attendance.view'],
  '/admin/overtime': ['overtime.view'],
  '/admin/my-schedule': ['view-my-schedule', 'request-schedule'],
  '/admin/schedule-requests': ['approve-schedule', 'manage-schedules'],
  '/admin/daily-computation': ['payroll.view'],
  '/admin/daily-computation/policy-settings': ['payroll.policies'],
  '/admin/approval-workflow-settings': ['approval.workflow.manage'],
  '/admin/compensation/pay-cycles': ['compensation.view'],
  '/admin/compensation/pay-components': ['compensation.view'],
  '/admin/compensation/deduction-schedule-settings': ['compensation.view'],
  '/admin/compensation/employee-compensation': ['compensation.view'],
  '/admin/compensation/government-deduction': ['compensation.view'],
  '/admin/compensation/deductions-loans': ['compensation.view'],
  '/admin/compensation/generate-payslips': ['payslip.generate'],
  '/admin/compensation/finalize-payroll': ['payslip.finalize'],
  '/admin/execom/employees': ['execom.view', 'execom.manage'],
  '/admin/execom/payroll/finalize': ['execom.payroll.generate', 'execom.payroll.finalize', 'execom.view'],
  /** Any of these — org heads may lack `payroll.view` but have `employees.view` / org scope. */
  '/admin/compensation/payslips': ['payslip.view'],
  '/admin/government-contributions': ['government_deductions.view', 'government_deductions.rates.view'],
  '/admin/reports': [
    'can_access_reports_module',
    'can_view_own_reports',
    'can_view_subordinate_reports',
    'can_view_all_reports',
  ],
  '/admin/loans-deductions': ['loans.view_own', 'loans.request', 'request-loan'],
  '/admin/schedules': ['manage-schedules', 'schedule.view'],
  /** Self-service: same visibility rule as Profile (no extra permission slug). */
  '/admin/qr': [],
  '/admin/profile': [],
}

function canSee(user, permissionLists) {
  if (!user) return false
  if (user.is_super_admin) return true
  // Admin (HR) super-role: full sidebar (matches backend permission bypass).
  if (isAdminHrUser(user)) return true
  if (permissionLists.length === 0) return true
  const set = new Set(user.permissions ?? [])
  return permissionLists.some((list) => list.length === 0 || list.some((p) => set.has(p)))
}

/** Map manager panel URLs to the same permission keys as `/admin/...`. */
function normalizePathForPermission(path) {
  if (!path) return path
  return path.replace(/^\/(company|branch|department|division|section-unit)(?=\/|$)/, '/admin')
}

function permissionsForPath(path) {
  const p = normalizePathForPermission(path)
  if (!p) return [['dashboard.view']]
  if (Object.prototype.hasOwnProperty.call(pathToPermissions, p)) {
    return pathToPermissions[p]
  }
  const keys = Object.keys(pathToPermissions).sort((a, b) => b.length - a.length)
  for (const k of keys) {
    if (p === k || p.startsWith(`${k}/`)) {
      return pathToPermissions[k]
    }
  }
  return ['dashboard.view']
}

/**
 * Employee app sidebar (`/employee/*`).
 * All non-admin roles, including organization heads, use this same module set.
 */
function navItemVisibleForUser(user, item) {
  const flags = item.requiredFlags
  const requiredPermissions = item.requiredPermissions
  if (!user) {
    return !flags?.length && !requiredPermissions?.length
  }
  if (!flags?.length && !requiredPermissions?.length) {
    return true
  }
  if (user.is_super_admin || isAdminHrUser(user)) {
    return true
  }
  const permissionSet = new Set(user.permissions ?? [])
  const flagOk = flags?.length ? flags.some((flag) => Boolean(user[flag])) : true
  const permissionOk = requiredPermissions?.length
    ? requiredPermissions.some((permission) => permissionSet.has(permission))
    : true
  return flagOk && permissionOk
}

export function buildEmployeeNav(user = null) {
  return employeeNavItems
    .filter((item) => navItemVisibleForUser(user, item))
    .map((item) => ({ ...item }))
}

/**
 * Sidebar for organization heads: same modules as admin where permitted, scoped URL prefix.
 */
export function buildManagerNav(user, basePath) {
  const prefix = (basePath || '/company').replace(/\/$/, '') || '/company'

  function walk(items) {
    if (!Array.isArray(items)) return []
    const out = []
    for (const item of items) {
      if (item.children?.length) {
        const children = walk(item.children)
        if (children.length === 0) continue
        out.push({ ...item, children })
        continue
      }
      const to = item.to
      if (!to) continue
      const mappedTo = to.replace(/^\/admin/, prefix)
      const hr = String(user?.hr_role || '').trim()
      if (mappedTo === `${prefix}/companies` && (hr === 'branch_head' || hr === 'department_head' || hr === 'division_head' || hr === 'section_unit_head')) {
        continue
      }
      if (PATHS_ADMIN_HR_ONLY.has(to) && !isAdminHrUser(user)) continue
      const need = permissionsForPath(normalizePathForPermission(mappedTo))
      if (!canSee(user, [need])) continue
      const navItem = { ...item, to: mappedTo }
      if (mappedTo === `${prefix}/companies` && String(user?.hr_role || '').trim() === 'company_head') {
        navItem.label = 'My Company'
      }
      if (mappedTo === `${prefix}/employees`) {
        if (hr === 'branch_head') navItem.label = 'Branch employees'
        else if (hr === 'department_head') navItem.label = 'Department employees'
        else if (hr === 'division_head') navItem.label = 'Division employees'
        else if (hr === 'section_unit_head') navItem.label = 'Section/Unit employees'
        else if (hr === 'company_head') navItem.label = 'Company employees'
      }
      out.push(navItem)
    }
    return out
  }
  return walk(adminNavItems)
}

export function buildAdminNav(user) {
  function walk(items) {
    if (!Array.isArray(items)) return []
    const out = []
    for (const item of items) {
      if (item.children?.length) {
        const children = walk(item.children)
        if (children.length === 0) continue
        out.push({ ...item, children })
        continue
      }
      const to = item.to
      if (!to) continue
      if (PATHS_ADMIN_HR_ONLY.has(to) && !isAdminHrUser(user)) continue
      const need = permissionsForPath(normalizePathForPermission(to))
      if (!canSee(user, [need])) continue
      out.push(item)
    }
    return out
  }
  return walk(adminNavItems)
}

