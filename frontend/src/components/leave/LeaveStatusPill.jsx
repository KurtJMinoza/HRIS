import { CheckCircle2, Clock, XCircle } from 'lucide-react'
import { cn } from '@/lib/utils'

function normalizeLeaveStatus(status) {
  const s = String(status || '').trim().toLowerCase()
  if (s === 'pending' || s === 'approved' || s === 'rejected') return s
  return s || 'pending'
}

export default function LeaveStatusPill({ status, displayStatus, hrWaitMessage, className }) {
  const s = normalizeLeaveStatus(status)
  const label = displayStatus || status || '—'

  let pill = null
  if (s === 'rejected') {
    pill = (
      <span
        className={cn(
          'inline-flex w-fit max-w-full items-center gap-1.5 rounded-full border border-red-200/90 bg-gradient-to-br from-red-50 to-rose-50 px-2.5 py-1.5 text-xs font-semibold text-red-900 shadow-sm ring-1 ring-red-100 dark:border-red-900/50 dark:from-red-950/40 dark:to-rose-950/30 dark:text-red-100 dark:ring-red-900/30',
          className,
        )}
        title={label}
      >
        <XCircle className="size-3.5 shrink-0" aria-hidden />
        <span className="line-clamp-2 text-left leading-snug">Rejected</span>
      </span>
    )
  } else if (s === 'approved') {
    pill = (
      <span
        className={cn(
          'inline-flex w-fit max-w-full items-center gap-1.5 rounded-full border border-emerald-200/90 bg-gradient-to-br from-emerald-50 to-teal-50 px-2.5 py-1.5 text-xs font-semibold text-emerald-950 shadow-sm ring-1 ring-emerald-100 dark:border-emerald-900/40 dark:from-emerald-950/45 dark:to-teal-950/25 dark:text-emerald-50 dark:ring-emerald-900/30',
          className,
        )}
        title={label}
      >
        <CheckCircle2 className="size-3.5 shrink-0 text-emerald-600 dark:text-emerald-400" aria-hidden />
        <span className="line-clamp-2 text-left leading-snug">Approved</span>
      </span>
    )
  } else if (s === 'pending') {
    pill = (
      <span
        className={cn(
          'inline-flex w-fit max-w-full items-start gap-1.5 rounded-full border border-amber-200/90 bg-gradient-to-br from-amber-50 to-orange-50/80 px-2.5 py-1.5 text-xs font-semibold text-amber-950 shadow-sm ring-1 ring-amber-100 dark:border-amber-900/50 dark:from-amber-950/40 dark:to-orange-950/20 dark:text-amber-50 dark:ring-amber-900/40',
          className,
        )}
        title={label}
      >
        <Clock className="mt-0.5 size-3.5 shrink-0 text-amber-600 dark:text-amber-400" aria-hidden />
        <span className="line-clamp-2 text-left leading-snug">{label}</span>
      </span>
    )
  } else {
    pill = (
      <span
        className={cn(
          'inline-flex w-fit max-w-full items-start gap-1.5 rounded-full border border-slate-200/90 bg-slate-50 px-2.5 py-1.5 text-xs font-semibold text-slate-800 shadow-sm dark:border-slate-700 dark:bg-slate-900/50 dark:text-slate-100',
          className,
        )}
        title={label}
      >
        <span className="line-clamp-2 text-left leading-snug">{label}</span>
      </span>
    )
  }

  if (!hrWaitMessage) return pill

  return (
    <div className="flex min-w-0 flex-col gap-1.5">
      {pill}
      <p className="line-clamp-2 text-[11px] leading-snug text-muted-foreground" title={hrWaitMessage}>
        {hrWaitMessage}
      </p>
    </div>
  )
}
