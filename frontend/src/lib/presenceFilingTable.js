/** Shared helpers for presence filing / correction request tables (admin + employee). */

export const ISSUE_LABELS = {
  missing_in: 'Missing Clock In',
  missing_out: 'Missing Clock Out',
  both: 'Both (Clock In and Clock Out)',
  complete: 'Complete',
}

const REASON_LABELS = {
  forgot_punch: 'Forgot punch',
  system_issue: 'System issue',
  field_work: 'Field work',
  manual_override: 'Manual override',
  others: 'Others',
}

export function issueLabel(issueType) {
  return ISSUE_LABELS[issueType] || issueType || '—'
}

export function reasonLabel(reasonCode) {
  if (!reasonCode) return ''
  return REASON_LABELS[reasonCode] || String(reasonCode).replace(/_/g, ' ')
}

/** Strip server prefix like "[Missing Clock In] user text" for previews. */
export function remarksUserText(remarks) {
  if (!remarks) return ''
  const m = remarks.match(/^\[[^\]]+\]\s*(.*)$/s)
  return (m ? m[1] : remarks).trim() || remarks
}

/**
 * Workflow status for table badges: Pending | Department Approved | HR Approved | Rejected
 */
export function reviewStatusKey(item) {
  if (!item || item.status === 'rejected') return 'rejected'
  if (item.status === 'approved' || item.display_status === 'HR Approved') return 'hr_approved'
  if (item.approval_stage === 'pending_second') return 'department_approved'
  return 'pending'
}

export function reviewStatusLabel(item) {
  switch (reviewStatusKey(item)) {
    case 'rejected':
      return 'Rejected'
    case 'hr_approved':
      return 'HR Approved'
    case 'department_approved':
      return 'Department Approved'
    default:
      return 'Pending'
  }
}

/** For sorting: rejected < pending < dept < hr */
export function reviewStatusSortValue(item) {
  switch (reviewStatusKey(item)) {
    case 'rejected':
      return 0
    case 'pending':
      return 1
    case 'department_approved':
      return 2
    case 'hr_approved':
      return 3
    default:
      return 1
  }
}

export function reviewStatusBadgeClass(key) {
  switch (key) {
    case 'rejected':
      return 'border-red-200/90 bg-gradient-to-br from-red-100 to-rose-50 text-red-950 shadow-sm ring-1 ring-red-200/70 dark:from-red-950/50 dark:to-red-950/30 dark:text-red-50 dark:ring-red-900/40'
    case 'hr_approved':
      return 'border-emerald-200/90 bg-gradient-to-br from-emerald-100 to-teal-50 text-emerald-950 shadow-sm ring-1 ring-emerald-200/80 dark:from-emerald-950/50 dark:to-emerald-950/30 dark:text-emerald-50 dark:ring-emerald-900/35'
    case 'department_approved':
      return 'border-sky-200/90 bg-gradient-to-br from-sky-100 to-indigo-50 text-sky-950 shadow-sm ring-1 ring-sky-200/70 dark:from-sky-950/45 dark:to-indigo-950/30 dark:text-sky-50 dark:ring-sky-900/35'
    default:
      return 'border-amber-200/90 bg-gradient-to-br from-amber-100 to-orange-50 text-amber-950 shadow-sm ring-1 ring-amber-200/75 dark:from-amber-950/45 dark:to-orange-950/25 dark:text-amber-50 dark:ring-amber-900/40'
  }
}

export function formatTimeOnly(iso) {
  if (!iso) return '—'
  try {
    return new Date(iso).toLocaleTimeString('en-PH', {
      hour: 'numeric',
      minute: '2-digit',
      hour12: true,
      timeZone: 'Asia/Manila',
    })
  } catch {
    return '—'
  }
}

export function formatDateTimeFull(iso) {
  if (!iso) return '—'
  const d = new Date(iso)
  return d.toLocaleString('en-PH', { dateStyle: 'medium', timeStyle: 'short' })
}

/** Total hours from requested or stored times (employee-submitted window). */
export function formatRenderedHours(item) {
  const tIn = item?.requested_time_in ?? item?.time_in
  const tOut = item?.requested_time_out ?? item?.time_out
  if (!tIn || !tOut) return '—'
  const a = new Date(tIn).getTime()
  const b = new Date(tOut).getTime()
  if (!Number.isFinite(a) || !Number.isFinite(b) || b <= a) return '—'
  const ms = b - a
  const h = Math.floor(ms / 3600000)
  const m = Math.floor((ms % 3600000) / 60000)
  if (h <= 0 && m <= 0) return '—'
  return `${h}h ${String(m).padStart(2, '0')}m`
}

/** Milliseconds between in/out for sorting (0 if incomplete). */
export function renderedMsForSort(item) {
  const tIn = item?.requested_time_in ?? item?.time_in
  const tOut = item?.requested_time_out ?? item?.time_out
  if (!tIn || !tOut) return 0
  const a = new Date(tIn).getTime()
  const b = new Date(tOut).getTime()
  if (!Number.isFinite(a) || !Number.isFinite(b) || b <= a) return 0
  return b - a
}

export function attachmentCount(item) {
  const n = item?.attachment_count ?? item?.documents_count
  if (typeof n === 'number' && n >= 0) return n
  return 0
}
