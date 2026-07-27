import { Calendar } from 'lucide-react'
import { cn } from '@/lib/utils'
import { formConsumesLeaveCredits } from '@/lib/leaveCreditsDisplay'

/**
 * Credits block inside the File new leave dialog.
 * @param {{
 *   leaveCreditInfo?: object|null,
 *   leaveType?: string,
 *   billableCreditDays?: number,
 *   paidLeavePreview?: object|null,
 *   paidLeavePreviewLoading?: boolean,
 *   leaveWillBeFullyPaid?: boolean,
 *   leaveWillBeUnpaidNoPool?: boolean,
 *   leaveWillBeUnpaidPartial?: boolean,
 *   effAvail?: number,
 * }} props
 */
export function LeaveModalCreditsCard({
  leaveCreditInfo,
  leaveType,
  billableCreditDays = 0,
  paidLeavePreview = null,
  paidLeavePreviewLoading = false,
  leaveWillBeFullyPaid = false,
  leaveWillBeUnpaidNoPool = false,
  leaveWillBeUnpaidPartial = false,
  effAvail = 0,
}) {
  if (!leaveCreditInfo) return null

  const consumesCredits = formConsumesLeaveCredits(leaveType)
  const remaining = Number(leaveCreditInfo.remaining ?? 0)
  const annual = Number(leaveCreditInfo.annual_allocation ?? 0)
  const effective = Number(leaveCreditInfo.effective_available ?? 0)

  return (
    <section className="rounded-xl border border-brand/25 bg-brand/[0.045] px-5 py-5 shadow-sm dark:border-brand/25 dark:bg-brand/10">
      <div className="flex items-start gap-4">
        <span className="flex size-12 shrink-0 items-center justify-center rounded-xl border border-brand/30 bg-brand/10 text-brand dark:bg-brand/15">
          <Calendar className="size-5" aria-hidden />
        </span>
        <div className="min-w-0 flex-1 space-y-2">
          <h3 className="text-lg font-semibold tracking-tight text-foreground">Available credits</h3>
          {leaveCreditInfo.display ? (
            <p className="text-[15px] font-semibold text-foreground">{leaveCreditInfo.display}</p>
          ) : null}
          {leaveCreditInfo.status_summary ? (
            <p className="text-[15px] leading-relaxed text-muted-foreground">{leaveCreditInfo.status_summary}</p>
          ) : null}
          <p className="text-[15px] leading-relaxed text-muted-foreground">
            <span className="font-semibold tabular-nums text-foreground">
              {Number.isFinite(remaining) ? remaining : 0} / {Number.isFinite(annual) ? annual : 0}
            </span>{' '}
            · This request uses <span className="font-semibold tabular-nums text-foreground">{billableCreditDays}</span>{' '}
            billable credit day{billableCreditDays === 1 ? '' : 's'}
            {paidLeavePreview || paidLeavePreviewLoading ? ' (schedule-based)' : ''}.
            {paidLeavePreviewLoading ? <span> Updating...</span> : null}
            <br />
            You have <span className="font-semibold tabular-nums text-foreground">{Number.isFinite(effective) ? effective : 0}</span>{' '}
            credits remaining this year (after pending).
          </p>
          {consumesCredits && paidLeavePreview && !paidLeavePreviewLoading ? (
            <>
              {paidLeavePreview.message ? (
                <p
                  className={cn(
                    'text-[15px] font-medium leading-relaxed',
                    leaveWillBeFullyPaid ? 'text-emerald-700 dark:text-emerald-300' : 'text-foreground'
                  )}
                >
                  {paidLeavePreview.message}
                </p>
              ) : null}
              {paidLeavePreview.message_detail ? (
                <p className="text-[13px] leading-relaxed text-muted-foreground">{paidLeavePreview.message_detail}</p>
              ) : null}
            </>
          ) : null}
          {leaveCreditInfo.warning && !(leaveWillBeUnpaidNoPool && consumesCredits) ? (
            <p className="text-[13px] leading-relaxed text-muted-foreground">{leaveCreditInfo.warning}</p>
          ) : null}
          {leaveWillBeUnpaidNoPool && consumesCredits ? (
            <p className="font-semibold leading-relaxed text-brand">
              {leaveCreditInfo.unpaid_leave_notice ||
                leaveCreditInfo.warning ||
                'This leave will be unpaid because you are not yet eligible for paid leave credits.'}
            </p>
          ) : null}
          {leaveWillBeUnpaidPartial && !paidLeavePreview ? (
            <p className="font-semibold leading-relaxed text-brand">
              Only {effAvail} day{effAvail === 1 ? '' : 's'} can be paid from your pool. Extra days will be unpaid if
              approved.
            </p>
          ) : null}
        </div>
      </div>
    </section>
  )
}

export default LeaveModalCreditsCard
