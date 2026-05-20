import { HOLIDAY_TYPE_OPTIONS } from '@/lib/holidayConstants'

/** Years before/after the current year in the holiday year dropdown. */
export const HOLIDAY_YEAR_SPAN = 1

/** Max holidays shown in the dashboard list after month filter. */
export const UPCOMING_HOLIDAYS_DISPLAY_LIMIT = 10

const MONTH_NAMES = [
  'January',
  'February',
  'March',
  'April',
  'May',
  'June',
  'July',
  'August',
  'September',
  'October',
  'November',
  'December',
]

export function holidayRowDate(holiday) {
  return holiday?.date || holiday?.holiday_date || ''
}

/** Badge classes for holiday type on dashboard / list UIs. */
export function holidayTypeBadgeClass(type) {
  switch (type) {
    case 'regular':
      return 'border-teal-500/35 bg-teal-500/12 text-teal-800 dark:text-teal-200'
    case 'special':
    case 'special_non_working':
      return 'border-amber-500/35 bg-amber-500/12 text-amber-800 dark:text-amber-200'
    case 'special_working':
      return 'border-slate-500/35 bg-slate-500/12 text-slate-700 dark:text-slate-200'
    case 'company':
      return 'border-violet-500/35 bg-violet-500/12 text-violet-800 dark:text-violet-200'
    default:
      return 'border-border/70 bg-muted/40 text-muted-foreground'
  }
}

export function holidayTypeLabel(type, fallback) {
  const key = String(type || '').toLowerCase()
  const match = HOLIDAY_TYPE_OPTIONS.find((o) => o.value === key)
  if (match) return match.label
  if (fallback) return fallback
  if (key === 'special_non_working') return 'Special Non-Working Holiday'
  return key ? key.replace(/_/g, ' ').replace(/\b\w/g, (c) => c.toUpperCase()) : 'Holiday'
}

export function holidayScopeBadgeClass(scopeType) {
  const key = String(scopeType || '').toLowerCase()
  if (key === 'nationwide' || key === 'regional') {
    return 'border-orange-500/35 bg-orange-500/12 text-orange-800 dark:text-orange-200'
  }
  return 'border-brand/30 bg-brand/10 text-orange-700 dark:text-orange-200'
}

export function parseHolidayYmd(s) {
  if (!s || typeof s !== 'string') return null
  const [y, m, d] = s.split('-').map(Number)
  if (!y || !m || !d) return null
  const dt = new Date(y, m - 1, d)
  if (Number.isNaN(dt.getTime())) return null
  dt.setHours(0, 0, 0, 0)
  return dt
}

function startOfToday() {
  const today = new Date()
  today.setHours(0, 0, 0, 0)
  return today
}

function endOfDay(d) {
  const x = new Date(d)
  x.setHours(23, 59, 59, 999)
  return x
}

export function holidayMonthKey({ year, month }) {
  return `${year}-${String(month).padStart(2, '0')}`
}

export function parseHolidayMonthKey(key) {
  const [year, month] = String(key || '').split('-').map(Number)
  if (!year || !month || month < 1 || month > 12) return null
  return { year, month }
}

export function shiftHolidayMonth({ year, month }, delta) {
  const d = new Date(year, month - 1 + delta, 1)
  return { year: d.getFullYear(), month: d.getMonth() + 1 }
}

export function getDefaultHolidayMonthKey() {
  const today = startOfToday()
  return holidayMonthKey({ year: today.getFullYear(), month: today.getMonth() + 1 })
}

export function formatHolidayMonthLabel(monthKey) {
  const parsed = parseHolidayMonthKey(monthKey)
  if (!parsed) return 'Select month'
  return `${MONTH_NAMES[parsed.month - 1]} ${parsed.year}`
}

/** @returns {number[]} */
export function buildHolidayYearOptions(yearSpan = HOLIDAY_YEAR_SPAN) {
  const currentYear = startOfToday().getFullYear()
  const years = []
  for (let y = currentYear - yearSpan; y <= currentYear + yearSpan; y++) {
    years.push(y)
  }
  return years
}

/** @returns {Array<{ value: number, label: string }>} */
export function buildHolidayMonthOptions() {
  return MONTH_NAMES.map((label, index) => ({
    value: index + 1,
    label,
  }))
}

/**
 * Inclusive bounds for all holidays in a calendar month (local time).
 * Past dates within the month are included.
 */
export function getHolidayMonthBounds(monthKey) {
  const parsed = parseHolidayMonthKey(monthKey)
  if (!parsed) {
    const today = startOfToday()
    const fallback = new Date(today)
    fallback.setDate(fallback.getDate() + 30)
    return { from: today, to: endOfDay(fallback), year: today.getFullYear(), month: today.getMonth() + 1 }
  }

  const { year, month } = parsed
  const monthStart = new Date(year, month - 1, 1)
  monthStart.setHours(0, 0, 0, 0)
  const monthEnd = endOfDay(new Date(year, month, 0))

  return { from: monthStart, to: monthEnd, year, month }
}

/** Filter holidays to a selected calendar month (includes past dates in that month). */
export function filterUpcomingHolidaysByMonth(holidays, monthKey) {
  const list = Array.isArray(holidays) ? holidays : []
  if (list.length === 0) return []

  const { from, to } = getHolidayMonthBounds(monthKey)

  return list.filter((h) => {
    const d = parseHolidayYmd(holidayRowDate(h))
    if (!d) return false
    return d >= from && d <= to
  })
}

/** Upcoming-only filter: holiday_date >= today. */
export function filterUpcomingHolidaysFromToday(holidays) {
  const today = startOfToday()
  return (Array.isArray(holidays) ? holidays : []).filter((h) => {
    const d = parseHolidayYmd(holidayRowDate(h))
    return d && d >= today
  })
}

export function formatHolidayDateLine(holiday) {
  const dateKey = holidayRowDate(holiday)
  if (!dateKey) return ''
  const d = new Date(`${dateKey}T12:00:00`)
  if (Number.isNaN(d.getTime())) return dateKey
  const datePart = d.toLocaleDateString(undefined, { month: 'short', day: 'numeric', year: 'numeric' })
  const dayPart = holiday.day_name || d.toLocaleDateString(undefined, { weekday: 'long' })
  return `${datePart} · ${dayPart}`
}

/** Scope line for dashboard (e.g. "Scope: ACI Company"). */
export function formatHolidayScopeLine(holiday) {
  const scopeType = String(holiday?.scope_type || '').toLowerCase()
  const scopeLabel = String(holiday?.scope_label || '').trim()
  const companyName = String(holiday?.company_name || holiday?.company || '').trim()
  const branchName = String(holiday?.branch_name || holiday?.branch || holiday?.location_name || holiday?.location || '').trim()
  const departmentName = String(holiday?.department_name || holiday?.department || '').trim()

  if (scopeType === 'nationwide' || String(holiday?.scope || '').toLowerCase() === 'nationwide') {
    return 'Scope: Nationwide'
  }
  if (scopeType === 'regional' || String(holiday?.scope || '').toLowerCase() === 'regional') {
    return 'Scope: Regional'
  }
  if (companyName) {
    const suffix = /company$/i.test(companyName) ? '' : ' Company'
    return `Scope: ${companyName}${suffix}`
  }
  if (branchName) {
    return `Scope: ${branchName}`
  }
  if (departmentName) {
    return `Scope: ${departmentName}`
  }
  if (scopeLabel && scopeLabel !== scopeType) {
    return `Scope: ${scopeLabel}`
  }
  if (scopeType) {
    return `Scope: ${holiday.scope_type}`
  }
  return 'Scope: All employees'
}

/** @deprecated Use formatHolidayScopeLine */
export function holidayAudienceLabel(holiday) {
  return formatHolidayScopeLine(holiday)
}
