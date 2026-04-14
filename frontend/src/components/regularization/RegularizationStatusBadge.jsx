import { Clock, CheckCircle2, XCircle } from 'lucide-react'
import { cn } from '@/lib/utils'

export function RegularizationStatusBadge({ status, processed }) {
  const s = (status || '').toLowerCase()
  const label = s ? `${s.charAt(0).toUpperCase()}${s.slice(1)}` : '—'
  const Icon = s === 'rejected' ? XCircle : s === 'approved' ? CheckCircle2 : Clock
  const cls =
    s === 'approved'
      ? 'border-emerald-200/90 bg-gradient-to-br from-emerald-100 to-teal-50 text-emerald-950 shadow-emerald-500/25 ring-1 ring-emerald-200/90 dark:from-emerald-950/50 dark:to-emerald-950/30 dark:text-emerald-50 dark:ring-emerald-500/15'
      : s === 'rejected'
        ? 'border-red-200/90 bg-gradient-to-br from-red-100 to-rose-50 text-red-950 shadow-red-500/20 ring-1 ring-red-200/80 dark:from-red-950/45 dark:to-red-950/25 dark:text-red-100 dark:ring-red-500/30'
        : s === 'pending'
          ? 'border-amber-200/90 bg-gradient-to-br from-amber-100 to-amber-50 text-amber-950 shadow-amber-500/15 ring-1 ring-amber-200/80 dark:from-amber-950/45 dark:to-amber-950/25 dark:text-amber-100'
          : 'border-border/80 bg-muted/60 text-muted-foreground shadow-sm'
  return (
    <div className="flex min-w-0 flex-col items-start gap-1">
      <span
        className={cn(
          'inline-flex max-w-full items-center gap-1.5 rounded-full border px-2.5 py-1 text-xs font-semibold leading-tight shadow-sm',
          cls,
        )}
      >
        <Icon className="size-3.5 shrink-0 opacity-90" aria-hidden />
        <span>{label}</span>
      </span>
      {processed ? (
        <span className="text-[10px] font-medium text-muted-foreground">Processed by automation</span>
      ) : null}
    </div>
  )
}
