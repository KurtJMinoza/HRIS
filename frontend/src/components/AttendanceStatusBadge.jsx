/**
 * Shared attendance status badge with color-coded indicators.
 * Consistent across Employee Attendance, Employee Reports, and Admin modules.
 * Supports light and dark themes for accessibility.
 */
function resolveStatusVariant(statusRaw, labelRaw, presenceIssue) {
  if (presenceIssue === 'correction_pending') return 'late'
  if (presenceIssue === 'approved_correction') return 'present'
  if (presenceIssue === 'incomplete_pair') return 'incomplete'

  const status = String(statusRaw || '').toLowerCase().replace(/_/g, '')
  const label = String(labelRaw || '').toLowerCase()

  if (status === 'present' || label.includes('present')) return 'present'
  if (status === 'late' || label.includes('late')) return 'late'
  if (status === 'absent' || label.includes('absent')) return 'absent'
  if (status === 'undertime' || label.includes('undertime')) return 'undertime'
  if (status === 'overtime' || label.includes('overtime')) return 'overtime'
  if (status === 'leave' || label.includes('leave')) return 'leave'
  if (status === 'halfday' || label.includes('half') || label.includes('halfday')) return 'halfday'
  if (status === 'rest' || status === 'restday' || label.includes('rest day') || label === 'restday') return 'restday'
  if (status === 'upcoming' || label.includes('upcoming')) return 'upcoming'
  if (status === 'clockedin' || status === 'clocked_in' || label.includes('clocked in')) return 'clocked_in'
  if (status === 'incomplete' || label.includes('incomplete')) return 'incomplete'

  return 'default'
}

function getStatusBadgeClasses(variant) {
  switch (variant) {
    case 'present':
      return 'bg-emerald-50 text-emerald-700 border-emerald-200 dark:bg-emerald-500/20 dark:text-emerald-300 dark:border-emerald-500/30'
    case 'late':
      return 'bg-amber-50 text-amber-700 border-amber-200 dark:bg-amber-500/20 dark:text-amber-300 dark:border-amber-500/30'
    case 'absent':
      return 'bg-red-50 text-red-700 border-red-200 dark:bg-red-500/20 dark:text-red-300 dark:border-red-500/30'
    case 'undertime':
      return 'bg-violet-50 text-violet-700 border-violet-200 dark:bg-violet-500/20 dark:text-violet-300 dark:border-violet-500/30'
    case 'overtime':
      return 'bg-blue-50 text-blue-700 border-blue-200 dark:bg-blue-500/20 dark:text-blue-300 dark:border-blue-500/30'
    case 'leave':
      return 'bg-sky-50 text-sky-700 border-sky-200 dark:bg-sky-500/20 dark:text-sky-300 dark:border-sky-500/30'
    case 'halfday':
      return 'bg-orange-50 text-orange-700 border-orange-200 dark:bg-orange-500/20 dark:text-orange-300 dark:border-orange-500/30'
    case 'restday':
      return 'bg-slate-50 text-slate-600 border-slate-200 dark:bg-slate-500/20 dark:text-slate-400 dark:border-slate-500/30'
    case 'upcoming':
      return 'bg-muted/50 text-muted-foreground border-border dark:bg-muted/30 dark:text-muted-foreground dark:border-border/60'
    case 'clocked_in':
      return 'bg-sky-50 text-sky-800 border-sky-200 dark:bg-sky-500/15 dark:text-sky-200 dark:border-sky-500/35'
    case 'incomplete':
      return 'bg-orange-50 text-orange-800 border-orange-200 dark:bg-orange-500/15 dark:text-orange-200 dark:border-orange-500/35'
    default:
      return 'bg-muted/10 text-foreground border-border/40 dark:bg-muted/20 dark:text-foreground dark:border-border/50'
  }
}

function formatLabel(status, label) {
  if (label && label !== '—') return label
  if (!status || status === '—') return '—'
  const s = String(status).toLowerCase()
  const map = {
    present: 'Present',
    late: 'Late',
    absent: 'Absent',
    undertime: 'Undertime',
    overtime: 'Overtime',
    leave: 'Leave',
    halfday: 'Half Day',
    half_day: 'Half Day',
    restday: 'Rest Day',
    upcoming: 'Upcoming',
    clocked_in: 'Clocked In',
    incomplete: 'Incomplete',
  }
  return map[s] || String(status).charAt(0).toUpperCase() + String(status).slice(1)
}

export function AttendanceStatusBadge({ status, label, presenceIssue }) {
  const variant = resolveStatusVariant(status, label, presenceIssue)
  const displayLabel = formatLabel(status, label)
  const tone = getStatusBadgeClasses(variant)

  if (!displayLabel || displayLabel === '—') {
    return <span className="text-muted-foreground">—</span>
  }

  return (
    <span
      className={`inline-flex max-w-[min(100%,22rem)] whitespace-normal rounded-full border px-2.5 py-0.5 text-[11px] font-semibold leading-snug tracking-wide ${tone}`}
    >
      {displayLabel}
    </span>
  )
}
