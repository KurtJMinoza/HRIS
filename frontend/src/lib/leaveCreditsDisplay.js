/**
 * Leave credits display helpers — aligned with AdminEmployeeProfile `liveLeaveCreditsBlock`
 * and LeaveCreditService rules (Regular + 1 full year from employment_status_effective_date).
 *
 * Use for **read-only** admin list / preview rows so the table matches the profile card when
 * the API still returns 0 remaining for an eligible employee (stale balance until recharge runs).
 */

function parseIsoDateOnly(isoDate) {
  const raw = String(isoDate || '').trim()
  if (!raw) return null
  const [yy, mm, dd] = raw.split('-').map((part) => Number(part))
  if (!Number.isFinite(yy) || !Number.isFinite(mm) || !Number.isFinite(dd)) return null
  const dt = new Date(yy, mm - 1, dd)
  if (Number.isNaN(dt.getTime())) return null
  return new Date(dt.getFullYear(), dt.getMonth(), dt.getDate())
}

function addYearsDateOnly(date, years) {
  if (!(date instanceof Date) || Number.isNaN(date.getTime())) return null
  return new Date(date.getFullYear() + years, date.getMonth(), date.getDate())
}

/** Match backend EmploymentStatus::tryFromStored Regular aliases (e.g. active). */
export function isRegularEmploymentStatus(raw) {
  const s = String(raw || '')
    .trim()
    .toLowerCase()
    .replace(/[- ]/g, '_')
  return s === 'regular' || s === 'active'
}

/**
 * @param {Record<string, unknown>} emp — admin employee list / preview row (API employeeResponse)
 * @returns {{ remaining: number, annual: number, showEligibleBadge: boolean, fractionLabel: string, title: string }}
 */
export function deriveAdminEmployeeListLeaveCredits(emp) {
  if (!emp || typeof emp !== 'object') {
    return {
      remaining: 0,
      annual: 7,
      showEligibleBadge: false,
      fractionLabel: '—',
      title: '',
    }
  }

  const annual = Math.max(0, Number(emp.leave_credits_annual_allocation ?? 7)) || 7
  const serverRemaining = Number(emp.leave_credits ?? 0)

  const today = new Date()
  const todayDateOnly = new Date(today.getFullYear(), today.getMonth(), today.getDate())
  const effectiveDate = parseIsoDateOnly(emp.employment_status_effective_date)
  const isRegular = isRegularEmploymentStatus(emp.employment_status)
  const eligibilityDate = effectiveDate ? addYearsDateOnly(effectiveDate, 1) : null
  const eligibleNow = Boolean(isRegular && eligibilityDate && todayDateOnly.getTime() >= eligibilityDate.getTime())

  const apiTitle = [emp.leave_credits_display, emp.leave_credits_status_summary].filter(Boolean).join(' · ')

  // Same override as profile: eligible by employment dates but pool still reads 0 → show full annual pool.
  if (eligibleNow) {
    const remaining = serverRemaining > 0 ? serverRemaining : annual
    return {
      remaining,
      annual,
      showEligibleBadge: true,
      fractionLabel: `${remaining}/${annual}`,
      title: apiTitle || `${remaining}/${annual} credits (Eligible)`,
    }
  }

  // Regular but not yet 1 year from status effective date — mirror profile "not yet eligible" line.
  if (isRegular && effectiveDate && eligibilityDate && todayDateOnly.getTime() < eligibilityDate.getTime()) {
    return {
      remaining: 0,
      annual,
      showEligibleBadge: false,
      fractionLabel: `0/${annual}`,
      title: 'Not yet eligible (under 1 year regular service)',
    }
  }

  // Otherwise trust API (probationary, non-regular, etc.)
  const showEligibleBadge = Boolean(emp.leave_credits_eligible_for_paid_pool)
  return {
    remaining: serverRemaining,
    annual,
    showEligibleBadge,
    fractionLabel: `${serverRemaining}/${annual}`,
    title: apiTitle || 'Paid leave pool (server)',
  }
}
