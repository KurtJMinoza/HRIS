import { Coins } from 'lucide-react'
import { cn } from '@/lib/utils'
import { deriveLeaveCreditUsage } from '@/lib/leaveCreditsDisplay'

export default function LeaveCreditUsageBadge({ leave, compact = false, leaveCreditInfo = null }) {
  const usage = deriveLeaveCreditUsage(leave, { leaveCreditInfo })
  const toneClass =
    usage.tone === 'paid'
      ? 'border-emerald-200 bg-emerald-50 text-emerald-800 dark:border-emerald-800/50 dark:bg-emerald-950/30 dark:text-emerald-200'
      : usage.tone === 'unpaid'
        ? 'border-amber-200 bg-amber-50 text-amber-800 dark:border-amber-800/50 dark:bg-amber-950/30 dark:text-amber-200'
        : usage.tone === 'pending'
          ? 'border-sky-200 bg-sky-50 text-sky-800 dark:border-sky-800/50 dark:bg-sky-950/30 dark:text-sky-200'
          : 'border-border/70 bg-muted/40 text-muted-foreground dark:border-white/10 dark:bg-white/5'

  return (
    <span className="inline-flex min-w-0 flex-col items-start gap-1">
      <span
        className={cn(
          'inline-flex max-w-full items-center gap-1.5 rounded-full border px-2.5 py-1 text-[11px] font-bold leading-none',
          toneClass,
        )}
        title={usage.detail}
      >
        <Coins className="size-3.5 shrink-0" aria-hidden />
        <span className="truncate">{usage.label}</span>
      </span>
      {!compact ? (
        <span className="max-w-[8.5rem] truncate text-[11px] leading-tight text-muted-foreground" title={usage.detail}>
          {usage.detail}
        </span>
      ) : null}
    </span>
  )
}
