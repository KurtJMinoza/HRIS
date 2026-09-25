import { leaveTypeMinCreditsHint } from '@/lib/leaveCreditsDisplay'
import { getLeaveTypeIcon } from '@/lib/leaveTypeIcon'

/** Leave type row — icon + title left, minimum credits flush right. */
export default function LeaveTypeSelectItemLabel({ title, type }) {
  const hint = leaveTypeMinCreditsHint(type)
  const Icon = getLeaveTypeIcon(type)
  return (
    <span className="grid w-full grid-cols-[minmax(0,1fr)_auto] items-center gap-3 py-0.5 pr-6">
      <span className="flex min-w-0 items-center gap-2.5 text-left">
        <Icon className="size-4 shrink-0 text-brand sm:size-[1.125rem]" strokeWidth={2.1} aria-hidden />
        <span className="truncate">{title}</span>
      </span>
      {hint ? (
        <span className="justify-self-end text-right text-xs font-normal leading-snug text-muted-foreground whitespace-nowrap">
          {hint}
        </span>
      ) : null}
    </span>
  )
}
