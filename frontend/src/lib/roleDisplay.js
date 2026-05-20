/**
 * Canonical HR role keys from API (`hr_role`) and shared badge styling.
 * Align with `App\Enums\HrRole` and `AuthController::userResponse`.
 */

export const HR_ROLE_BADGE_CLASS = {
  admin_hr:
    'border-blue-600/45 bg-blue-600/12 text-blue-950 shadow-sm dark:border-blue-400/40 dark:bg-blue-600/20 dark:text-blue-50',
  company_head:
    'border-violet-800/50 bg-violet-950/25 text-violet-950 dark:border-violet-400/35 dark:bg-violet-950/45 dark:text-violet-100',
  branch_head:
    'border-indigo-600/45 bg-indigo-600/12 text-indigo-950 dark:border-indigo-400/40 dark:bg-indigo-600/18 dark:text-indigo-50',
  department_head:
    'border-teal-600/45 bg-teal-600/12 text-teal-950 dark:border-teal-400/40 dark:bg-teal-600/18 dark:text-teal-50',
  division_head:
    'border-emerald-600/45 bg-emerald-600/12 text-emerald-950 dark:border-emerald-400/40 dark:bg-emerald-600/18 dark:text-emerald-50',
  section_unit_head:
    'border-amber-600/45 bg-amber-600/12 text-amber-950 dark:border-amber-400/40 dark:bg-amber-600/18 dark:text-amber-50',
  employee:
    'border-border bg-muted/90 text-muted-foreground dark:border-border/70 dark:bg-muted/50 dark:text-muted-foreground',
}

const FALLBACK_LABELS = {
  admin_hr: 'Admin (HR)',
  company_head: 'Company Head',
  branch_head: 'Branch Head',
  department_head: 'Department Head',
  division_head: 'Division Head',
  section_unit_head: 'Section/Unit Head',
  employee: 'Employee',
}

/** @param {{ hr_role?: string, role?: string } | null | undefined} userLike */
export function normalizeHrRoleKey(userLike) {
  if (!userLike) return 'employee'
  const hr = userLike.hr_role
  if (hr && Object.prototype.hasOwnProperty.call(HR_ROLE_BADGE_CLASS, hr)) {
    return hr
  }
  if (String(userLike.role || '').toLowerCase() === 'admin') {
    return 'admin_hr'
  }
  return 'employee'
}

/**
 * Map legacy `management_role` from employee payloads when `hr_role` was not yet loaded.
 * @param {string | null | undefined} m
 * @returns {'company_head'|'branch_head'|'department_head'|'division_head'|'section_unit_head'|null}
 */
function managementRoleToHrKey(m) {
  if (!m || typeof m !== 'string') return null
  if (m === 'company_head' || m === 'branch_head' || m === 'department_head' || m === 'division_head' || m === 'section_unit_head') return m
  return null
}

/**
 * @param {{ user?: object, hr_role?: string, hr_role_label?: string, management_role?: string | null }} input
 * @returns {{ hrKey: string, label: string, className: string, warning: boolean }}
 */
export function resolveRoleBadgeProps(input = {}) {
  const base = input.user && typeof input.user === 'object' ? input.user : {}
  const hr_role = input.hr_role ?? base.hr_role
  const hr_role_label = input.hr_role_label ?? base.hr_role_label
  const management_role = input.management_role ?? base.management_role
  const accountRole = base.role

  let key = hr_role
  if (!key || !HR_ROLE_BADGE_CLASS[key]) {
    const m = managementRoleToHrKey(management_role)
    if (m) key = m
  }
  if (!key || !HR_ROLE_BADGE_CLASS[key]) {
    key = normalizeHrRoleKey({ hr_role, role: accountRole })
  }

  const label =
    hr_role_label && String(hr_role_label).trim() !== ''
      ? String(hr_role_label).trim()
      : FALLBACK_LABELS[key] ?? 'Employee'

  /** Stale client cache: `/user` payload without `hr_role` before refresh. */
  const staleRolePayload =
    Object.keys(base).length > 0 &&
    typeof base === 'object' &&
    String(accountRole || '').toLowerCase() === 'admin' &&
    hr_role === undefined &&
    management_role == null &&
    (hr_role_label === undefined || hr_role_label === null || String(hr_role_label).trim() === '')

  return {
    hrKey: key,
    label,
    className: HR_ROLE_BADGE_CLASS[key] ?? HR_ROLE_BADGE_CLASS.employee,
    warning: Boolean(staleRolePayload),
  }
}

/** @deprecated Use HR_ROLE_BADGE_CLASS — kept for pages that still import ROLE_BADGE_CLASS */
export const ROLE_BADGE_CLASS = HR_ROLE_BADGE_CLASS
