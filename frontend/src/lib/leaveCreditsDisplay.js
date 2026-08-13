/**
 * Leave credits display helpers — aligned with AdminEmployeeProfile `liveLeaveCreditsBlock`
 * and LeaveCreditService rules (Regular + 1 full year from Hire Date).
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

/** Leave types that draw from the paid leave-credit pool. */
export function formConsumesLeaveCredits(type) {
  return ['vacation', 'sick', 'emergency', 'other', 'half_day'].includes(String(type || '').toLowerCase())
}

function pluralCreditLabel(value) {
  return `credit${Number(value) === 1 ? '' : 's'}`
}

function inferredCreditDaysFromLeave(leave) {
  const type = String(leave?.type || '').toLowerCase()
  if (type === 'half_day') return 0.5

  const durationMatch = String(leave?.duration || '').match(/(\d+(?:\.\d+)?)/)
  if (durationMatch) {
    const parsed = Number(durationMatch[1])
    if (Number.isFinite(parsed) && parsed > 0) return parsed
  }

  const start = parseIsoDateOnly(leave?.start_date)
  const end = parseIsoDateOnly(leave?.end_date)
  if (start && end && end.getTime() >= start.getTime()) {
    const dayMs = 24 * 60 * 60 * 1000
    return Math.floor((end.getTime() - start.getTime()) / dayMs) + 1
  }

  return null
}

export function deriveLeaveCreditUsage(leave, context = {}) {
  const consumes =
    leave?.leave_credit_consumes == null ? formConsumesLeaveCredits(leave?.type) : Boolean(leave.leave_credit_consumes)
  const liveEligibility = context?.leaveCreditInfo?.eligible_for_paid_leave_pool
  const eligible =
    liveEligibility == null
      ? leave?.leave_credit_eligible == null
        ? null
        : Boolean(leave.leave_credit_eligible)
      : Boolean(liveEligibility)
  const chargedRaw = leave?.leave_credits_charged
  const unpaidRaw = leave?.leave_unpaid_credit_days
  const billableRaw = leave?.leave_credit_billable_days
  const charged = chargedRaw == null || chargedRaw === '' ? null : Number(chargedRaw)
  const unpaid = unpaidRaw == null || unpaidRaw === '' ? null : Number(unpaidRaw)
  const billable = billableRaw == null || billableRaw === '' ? null : Number(billableRaw)
  const inferredDays = inferredCreditDaysFromLeave(leave)
  const status = String(leave?.status || '').toLowerCase()

  if (!consumes) {
    return {
      usesCredits: false,
      label: 'No',
      detail: 'Does not use leave credits',
      tone: 'muted',
    }
  }

  if (Number.isFinite(charged) && charged > 0) {
    const hasUnpaidRemainder = Number.isFinite(unpaid) && unpaid > 0
    return {
      usesCredits: true,
      label: `Uses ${charged} ${pluralCreditLabel(charged)}`,
      detail: hasUnpaidRemainder
        ? `${charged} paid, ${unpaid} unpaid day${unpaid === 1 ? '' : 's'}`
        : 'Deducted from leave credits',
      tone: 'paid',
    }
  }

  if (eligible === false) {
    return {
      usesCredits: false,
      label: 'Not eligible',
      detail: 'Leave will be unpaid',
      tone: 'unpaid',
    }
  }

  if (Number.isFinite(unpaid) && unpaid > 0) {
    return {
      usesCredits: false,
      label: 'No',
      detail: `${unpaid} unpaid day${unpaid === 1 ? '' : 's'}`,
      tone: 'unpaid',
    }
  }

  if (eligible === true && Number.isFinite(billable) && billable > 0) {
    return {
      usesCredits: true,
      label: `Uses ${billable} ${pluralCreditLabel(billable)}`,
      detail: status === 'pending' ? 'Will deduct on approval' : 'Deducted from leave credits',
      tone: status === 'pending' ? 'pending' : 'paid',
    }
  }

  if ((eligible !== false || status === 'approved') && Number.isFinite(inferredDays) && inferredDays > 0) {
    return {
      usesCredits: true,
      label: `Uses ${inferredDays} ${pluralCreditLabel(inferredDays)}`,
      detail: status === 'pending' ? 'Will deduct on approval' : 'Deducted from leave credits',
      tone: status === 'pending' ? 'pending' : 'paid',
    }
  }

  if (status === 'pending') {
    return {
      usesCredits: true,
      label: 'Uses credits',
      detail: 'Pending approval',
      tone: 'pending',
    }
  }

  return {
    usesCredits: true,
    label: 'Eligible type',
    detail: 'No credits charged',
    tone: 'pending',
  }
}

/**
 * @param {Record<string, unknown>} emp — admin employee list / preview row (API employeeResponse)
 * @returns {{ remaining: number, annual: number, showEligibleBadge: boolean, fractionLabel: string, title: string }}
 */
export function deriveAdminEmployeeListLeaveCredits(emp) {
  if (!emp || typeof emp !== 'object') {
    return {
      remaining: 0,
      annual: 14,
      showEligibleBadge: false,
      fractionLabel: '—',
      title: '',
    }
  }

  const annual = Math.max(0, Number(emp.leave_credits_annual_allocation ?? 14)) || 14
  const serverRemaining = Number(emp.leave_credits ?? 0)

  const today = new Date()
  const todayDateOnly = new Date(today.getFullYear(), today.getMonth(), today.getDate())
  const hireDate = parseIsoDateOnly(emp.hire_date)
  const isRegular = isRegularEmploymentStatus(emp.employment_status)
  const eligibilityDate = hireDate ? addYearsDateOnly(hireDate, 1) : null
  const eligibleNow = Boolean(isRegular && eligibilityDate && todayDateOnly.getTime() >= eligibilityDate.getTime())

  const apiTitle = [emp.leave_credits_display, emp.leave_credits_status_summary].filter(Boolean).join(' · ')

  // Same override as profile: eligible by Hire Date but pool still reads 0 → show full annual pool.
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

  // Regular but not yet one year from Hire Date: mirror the profile eligibility line.
  if (isRegular && hireDate && eligibilityDate && todayDateOnly.getTime() < eligibilityDate.getTime()) {
    return {
      remaining: 0,
      annual,
      showEligibleBadge: false,
      fractionLabel: `0/${annual}`,
      title: 'Not yet eligible (under 1 year since Hire Date)',
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
