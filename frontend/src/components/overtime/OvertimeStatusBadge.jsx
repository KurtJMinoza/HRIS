import { CheckCircle2, Clock, XCircle, ArrowLeftCircle, Ban } from 'lucide-react'
import { cn } from '@/lib/utils'
import { normalizeApprovalHeadTitle } from '@/lib/approvalText'

const STATUS_STYLES = {
  green: {
    badge: 'border-emerald-200/90 bg-gradient-to-br from-emerald-50 to-teal-50 text-emerald-950 ring-1 ring-emerald-100 dark:border-emerald-900/40 dark:from-emerald-950/45 dark:to-teal-950/25 dark:text-emerald-50 dark:ring-emerald-900/30',
    icon: CheckCircle2,
    iconColor: 'text-emerald-600 dark:text-emerald-400',
  },
  red: {
    badge: 'border-red-200/90 bg-gradient-to-br from-red-50 to-rose-50 text-red-900 ring-1 ring-red-100 dark:border-red-900/50 dark:from-red-950/40 dark:to-rose-950/30 dark:text-red-100 dark:ring-red-900/30',
    icon: XCircle,
    iconColor: 'text-red-600 dark:text-red-400',
  },
  orange: {
    badge: 'border-amber-200/90 bg-gradient-to-br from-amber-50 to-orange-50/80 text-amber-950 ring-1 ring-amber-100 dark:border-amber-900/50 dark:from-amber-950/40 dark:to-orange-950/20 dark:text-amber-50 dark:ring-amber-900/40',
    icon: Clock,
    iconColor: 'text-amber-600 dark:text-amber-400',
  },
  purple: {
    badge: 'border-purple-200/90 bg-gradient-to-br from-purple-50 to-violet-50 text-purple-900 ring-1 ring-purple-100 dark:border-purple-900/50 dark:from-purple-950/40 dark:to-violet-950/20 dark:text-purple-100 dark:ring-purple-900/30',
    icon: ArrowLeftCircle,
    iconColor: 'text-purple-600 dark:text-purple-400',
  },
  gray: {
    badge: 'border-slate-200/90 bg-gradient-to-br from-slate-50 to-gray-50 text-slate-800 ring-1 ring-slate-100 dark:border-slate-700 dark:from-slate-900/40 dark:to-gray-900/20 dark:text-slate-100 dark:ring-slate-800',
    icon: Ban,
    iconColor: 'text-slate-500 dark:text-slate-400',
  },
}

function statusLabel(row) {
  const s = String(row.status || '').toLowerCase()

  if (s === 'approved') return 'Approved'
  if (s === 'rejected') return 'Rejected'

  const step = normalizeApprovalHeadTitle(row.current_stage || row.current_step_name)
  if (step) return `Waiting for ${step}`

  return 'Pending'
}

function captionLine(row) {
  const s = String(row.status || '').toLowerCase()
  const currentApprover = row.current_approver_name || row.current_approver

  if (s === 'approved' && currentApprover) {
    return `Approved by ${currentApprover}`
  }

  if (s === 'rejected') {
    if (row.rejection_note) return `Rejected: ${row.rejection_note}`
    return 'Rejected'
  }

  if (currentApprover) {
    return currentApprover
  }

  return null
}

function badgeColor(row) {
  if (row.display_badge_color && STATUS_STYLES[row.display_badge_color]) {
    return row.display_badge_color
  }

  const s = String(row.status || '').toLowerCase()
  if (s === 'approved') return 'green'
  if (s === 'rejected') return 'red'
  return 'orange'
}

export default function OvertimeStatusBadge({ row, className }) {
  const color = badgeColor(row)
  const style = STATUS_STYLES[color] || STATUS_STYLES.gray
  const Icon = style.icon
  const label = statusLabel(row)
  const caption = captionLine(row)

  return (
    <div className={cn('flex min-w-0 flex-col gap-1', className)}>
      <span
        className={cn(
          'inline-flex w-fit max-w-full items-center gap-1.5 rounded-full border px-2.5 py-1.5 text-xs font-semibold shadow-sm',
          style.badge,
        )}
        title={label}
      >
        <Icon className={cn('size-3.5 shrink-0', style.iconColor)} aria-hidden />
        <span className="line-clamp-2 text-left leading-snug">{label}</span>
      </span>
      {caption ? (
        <p
          className="line-clamp-1 text-[11px] leading-snug text-muted-foreground"
          title={caption}
        >
          {caption}
        </p>
      ) : null}
    </div>
  )
}
