import { CheckCircle2, Clock, XCircle } from 'lucide-react'
import { cn } from '@/lib/utils'
import { reviewStatusBadgeClass } from '@/lib/presenceFilingTable'

/**
 * Maps schedule request API rows to the same visual keys as correction-request badges.
 */
export function scheduleRequestStatusKey(item) {
  if (!item) return 'pending'
  if (item.status === 'rejected') return 'rejected'
  if (item.status === 'approved') return 'hr_approved'
  if (item.approval_stage === 'pending_second') return 'department_approved'
  return 'pending'
}

/** Status pill matching Correction Requests (pending / dept / HR / rejected). */
export function ScheduleRequestStatusBadge({ item }) {
  const key = scheduleRequestStatusKey(item)
  const label =
    item?.display_status && String(item.display_status).trim()
      ? String(item.display_status).trim()
      : fallbackScheduleStatusLabel(key)
  const Icon = key === 'rejected' ? XCircle : key === 'hr_approved' ? CheckCircle2 : Clock
  return (
    <span
      className={cn(
        'inline-flex max-w-[min(100%,16rem)] items-center gap-1.5 rounded-full border px-2.5 py-1 text-xs font-semibold leading-tight shadow-sm',
        reviewStatusBadgeClass(key),
      )}
    >
      <Icon className="size-3.5 shrink-0 opacity-90" aria-hidden />
      <span className="line-clamp-2 text-left">{label}</span>
    </span>
  )
}

function fallbackScheduleStatusLabel(key) {
  switch (key) {
    case 'rejected':
      return 'Rejected'
    case 'hr_approved':
      return 'Approved'
    case 'department_approved':
      return 'Pending HR (final)'
    default:
      return 'Pending'
  }
}
