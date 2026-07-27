import { Info, Scale } from 'lucide-react'
import { cn } from '@/lib/utils'

const leaveCreditsCardClass =
  'rounded-[18px] border border-border/70 bg-card shadow-[0_12px_34px_-24px_rgba(15,23,42,0.55),0_2px_10px_-7px_rgba(15,23,42,0.25)] dark:border-white/10 dark:bg-card/95 dark:shadow-[0_18px_44px_-24px_rgba(0,0,0,0.75)]'

/**
 * Compact “Available credits” summary used on employee and head Leave pages.
 * @param {{ leaveCreditInfo?: object|null, className?: string }} props
 */
export function LeaveCreditsSummaryPanel({ leaveCreditInfo, className }) {
  if (!leaveCreditInfo) return null

  const remaining = Number(leaveCreditInfo.remaining ?? 0)
  const annual = Number(leaveCreditInfo.annual_allocation ?? 0)
  const effective = Number(leaveCreditInfo.effective_available ?? 0)
  const pending = Number(leaveCreditInfo.pending_reserved_days ?? 0)
  const unpaidNotice =
    !leaveCreditInfo.eligible_for_paid_leave_pool && leaveCreditInfo.unpaid_leave_notice
      ? leaveCreditInfo.unpaid_leave_notice
      : null

  return (
    <section className={cn(leaveCreditsCardClass, 'relative overflow-hidden px-4 py-4 @md:px-5 @md:py-5', className)}>
      <div className="pointer-events-none absolute -bottom-7 right-1 hidden h-28 w-40 opacity-[0.055] dark:opacity-[0.075] @lg:block" aria-hidden>
        <div className="absolute bottom-0 right-4 h-24 w-24 rounded-lg border-[3px] border-foreground" />
        <div className="absolute bottom-14 right-6 h-2 w-20 rounded-full bg-foreground" />
        <div className="absolute bottom-4 right-11 grid h-14 w-14 grid-cols-2 gap-2">
          <span className="rounded-sm border-2 border-foreground" />
          <span className="rounded-sm border-2 border-foreground" />
          <span className="rounded-sm border-2 border-foreground" />
          <span className="rounded-sm border-2 border-foreground" />
        </div>
        <div className="absolute bottom-7 right-28 h-16 w-7 rounded-full border-2 border-foreground" />
      </div>

      <div className="relative flex flex-col gap-4 @lg:flex-row @lg:items-start @lg:justify-between">
        <div className="min-w-0 flex-1">
          <div className="flex items-center gap-2">
            <span className="flex size-6 shrink-0 items-center justify-center rounded-md bg-brand/12 text-brand ring-1 ring-brand/20 dark:bg-brand/15">
              <Scale className="size-4" strokeWidth={2.15} aria-hidden />
            </span>
            <h2 className="text-[15px] font-semibold tracking-tight text-foreground">Available credits</h2>
          </div>

          <div className="mt-4 space-y-2 text-sm leading-relaxed text-muted-foreground">
            {leaveCreditInfo.display ? (
              <p className="text-[15px] font-semibold text-foreground">{leaveCreditInfo.display}</p>
            ) : null}
            {leaveCreditInfo.status_summary ? <p>{leaveCreditInfo.status_summary}</p> : null}
            <p>{leaveCreditInfo.recharge_policy || 'Recharge on January 1st every year (full reset; unused credits do not carry over).'}</p>
            <p>
              {pending > 0 ? (
                <>
                  <span className="font-semibold tabular-nums text-foreground">{pending}</span> day{pending === 1 ? '' : 's'} reserved by pending requests.{' '}
                </>
              ) : null}
              Usable for new requests:{' '}
              <span className="font-semibold tabular-nums text-foreground">{Number.isFinite(effective) ? effective : 0}</span>
            </p>
          </div>
        </div>

        <div className="shrink-0 self-start text-left @lg:text-right">
          <p className="text-3xl font-bold tracking-tight text-foreground tabular-nums @md:text-4xl">
            {Number.isFinite(remaining) ? remaining : 0}
            <span className="px-1 text-2xl font-semibold text-muted-foreground @md:text-3xl">/</span>
            <span className="text-xl font-semibold text-foreground @md:text-2xl">{Number.isFinite(annual) ? annual : 0}</span>
          </p>
        </div>
      </div>

      {unpaidNotice ? (
        <div className="relative mt-4 flex items-start gap-2 rounded-lg border border-brand/35 bg-brand/10 px-3.5 py-2.5 text-sm font-medium leading-snug text-foreground dark:bg-brand/12">
          <Info className="mt-0.5 size-4 shrink-0 text-brand" aria-hidden />
          <span>{unpaidNotice}</span>
        </div>
      ) : null}
    </section>
  )
}

export default LeaveCreditsSummaryPanel
