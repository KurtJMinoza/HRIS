import { cn } from '@/lib/utils'

/**
 * Compact count badge for “For My Approval” tabs.
 * @param {{ count?: number|null, className?: string }} props
 */
export function ApprovalQueueTabBadge({ count, className }) {
  const n = Math.max(0, Number(count) || 0)
  if (n <= 0) return null

  return (
    <span
      className={cn(
        'ml-2 inline-flex h-5 min-w-5 shrink-0 items-center justify-center rounded-full bg-brand px-1.5 text-[11px] font-bold tabular-nums text-brand-foreground shadow-[0_0_16px_rgba(234,88,12,0.28)]',
        className,
      )}
      aria-label={`${n > 99 ? '99 or more' : n} pending for your approval`}
    >
      {n > 99 ? '99+' : n}
    </span>
  )
}

export default ApprovalQueueTabBadge
