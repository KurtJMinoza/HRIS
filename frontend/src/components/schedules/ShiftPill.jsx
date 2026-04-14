import { cn } from '@/lib/utils'

const VARIANTS = {
  regular: 'bg-primary/10 text-foreground border-primary/20',
  ot: 'bg-amber-500/15 text-amber-800 dark:text-amber-200 border-amber-500/30',
  nd: 'bg-purple-600/15 text-purple-800 dark:text-purple-200 border-purple-600/30',
  rest: 'bg-slate-500/10 text-slate-600 dark:text-slate-300 border-slate-500/20',
  holiday: 'bg-sky-500/15 text-sky-800 dark:text-sky-200 border-sky-500/25',
  muted: 'bg-muted text-muted-foreground border-border',
}

/**
 * Small label for shift segments (regular, OT preview, night diff).
 */
export function ShiftPill({ children, variant = 'regular', className, title }) {
  return (
    <span
      title={title}
      className={cn(
        'inline-flex max-w-full items-center rounded-md border px-2 py-0.5 text-[10px] font-semibold uppercase tracking-wide',
        VARIANTS[variant] || VARIANTS.muted,
        className
      )}
    >
      {children}
    </span>
  )
}
