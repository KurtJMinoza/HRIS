import {
  CheckCircle2,
  Clock3,
  UserX,
  Palmtree,
  AlertTriangle,
  Hourglass,
  XCircle,
  CalendarClock,
  CircleDot,
} from 'lucide-react'
import { cn } from '@/lib/utils'

function resolveVariant(statusRaw, labelRaw, presenceIssue) {
  if (presenceIssue === 'correction_pending') return 'pending'
  if (presenceIssue === 'approved_correction') return 'present'
  if (presenceIssue === 'incomplete_pair') return 'incomplete'

  const status = String(statusRaw || '').toLowerCase().replace(/_/g, '')
  const label = String(labelRaw || '').toLowerCase()

  if (label.includes('present (incomplete)') || label.includes('present(incomplete)')) {
    return 'present_incomplete'
  }

  if (status === 'presentwithot' || status === 'present_with_ot' || label.includes('present with ot')) return 'present'
  if (status === 'present' || label.includes('present')) return 'present'
  if (status === 'late' || label.includes('late')) return 'late'
  if (status === 'absent' || label.includes('absent')) return 'absent'
  if (status === 'undertime' || label.includes('undertime')) return 'undertime'
  if (status === 'leave' || label.includes('leave')) return 'leave'
  if (status === 'halfday' || label.includes('half')) return 'halfday'
  if (status === 'upcoming') return 'upcoming'
  if (status === 'incomplete') return 'incomplete'

  if (label.includes('pending')) return 'pending'
  if (label.includes('approved')) return 'approved'
  if (label.includes('rejected')) return 'rejected'

  return 'default'
}

function variantStyles(v) {
  switch (v) {
    case 'present':
    case 'approved':
      return 'border-emerald-200/80 bg-emerald-50 text-emerald-800 dark:border-emerald-500/35 dark:bg-emerald-500/15 dark:text-emerald-200'
    case 'late':
      return 'border-amber-200/80 bg-amber-50 text-amber-900 dark:border-amber-500/35 dark:bg-amber-500/12 dark:text-amber-100'
    case 'absent':
    case 'rejected':
      return 'border-red-200/80 bg-red-50 text-red-900 dark:border-red-500/35 dark:bg-red-500/12 dark:text-red-100'
    case 'undertime':
      return 'border-violet-200/80 bg-violet-50 text-violet-900 dark:border-violet-500/30 dark:bg-violet-500/12 dark:text-violet-100'
    case 'leave':
      return 'border-sky-200/80 bg-sky-50 text-sky-900 dark:border-sky-500/30 dark:bg-sky-500/12 dark:text-sky-100'
    case 'halfday':
      return 'border-orange-200/80 bg-orange-50 text-orange-900 dark:border-orange-500/30 dark:bg-orange-500/12 dark:text-orange-100'
    case 'upcoming':
      return 'border-border/60 bg-muted/40 text-muted-foreground'
    case 'present_incomplete':
      return 'border-amber-300/90 bg-amber-100/90 text-amber-950 dark:border-amber-500/40 dark:bg-amber-950/45 dark:text-amber-50'
    case 'incomplete':
      return 'border-orange-200/80 bg-orange-50/90 text-orange-900 dark:border-orange-500/30 dark:bg-orange-950/20 dark:text-orange-100'
    case 'pending':
      return 'border-amber-200/80 bg-amber-50 text-amber-900 dark:border-amber-500/35 dark:bg-amber-500/10 dark:text-amber-100'
    default:
      return 'border-border/60 bg-muted/40 text-foreground'
  }
}

function StatusIcon({ variant, className }) {
  const common = cn('size-3.5 shrink-0', className)
  switch (variant) {
    case 'present':
    case 'approved':
      return <CheckCircle2 className={common} aria-hidden />
    case 'late':
      return <Clock3 className={common} aria-hidden />
    case 'absent':
      return <UserX className={common} aria-hidden />
    case 'leave':
      return <Palmtree className={common} aria-hidden />
    case 'undertime':
      return <AlertTriangle className={common} aria-hidden />
    case 'halfday':
      return <CalendarClock className={common} aria-hidden />
    case 'upcoming':
      return <CircleDot className={common} aria-hidden />
    case 'present_incomplete':
      return <AlertTriangle className={common} aria-hidden />
    case 'incomplete':
      return <AlertTriangle className={common} aria-hidden />
    case 'pending':
      return <Hourglass className={common} aria-hidden />
    case 'rejected':
      return <XCircle className={common} aria-hidden />
    default:
      return <CircleDot className={common} aria-hidden />
  }
}

export function AttendanceStatusPill({ status, label, presenceIssue, className }) {
  const display = label && label !== '—' ? label : null
  if (!display) {
    return <span className="text-muted-foreground">—</span>
  }

  const variant = resolveVariant(status, display, presenceIssue)
  const styles = variantStyles(variant)

  return (
    <span
      className={cn(
        'inline-flex max-w-[min(100%,20rem)] items-center gap-1.5 rounded-full border px-2.5 py-1 text-xs font-semibold leading-tight shadow-sm transition-transform duration-200 hover:scale-[1.02]',
        styles,
        className,
      )}
    >
      <StatusIcon variant={variant} />
      <span className="whitespace-normal">{display}</span>
    </span>
  )
}
