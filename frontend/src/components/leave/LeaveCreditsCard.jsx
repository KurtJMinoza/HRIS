import { useId } from 'react'
import { Link } from 'react-router-dom'
import { motion } from 'framer-motion'
import {
  Palmtree,
  CalendarClock,
  TrendingDown,
  PlusCircle,
  RefreshCw,
  SlidersHorizontal,
  CheckCircle2,
  AlertTriangle,
  ArrowRight,
  History,
  CalendarPlus,
  Inbox,
} from 'lucide-react'

const MotionCircle = motion.circle
const MotionLi = motion.li

import { Card, CardContent } from '@/components/ui/card'
import { Button } from '@/components/ui/button'
import { Badge } from '@/components/ui/badge'
import { Separator } from '@/components/ui/separator'
import { cn } from '@/lib/utils'

/**
 * @typedef {object} LeaveCreditsData
 * @property {number} remaining
 * @property {number} annual_allocation
 * @property {number} [pending_reserved_days]
 * @property {number} [effective_available]
 * @property {string} [recharge_policy]
 * @property {string} [last_recharged_display]
 * @property {string} [reset_date]
 * @property {string} [display]
 * @property {string} [status_summary]
 * @property {string|null} [warning]
 * @property {string} [unpaid_leave_notice]
 * @property {boolean} [eligible_for_paid_leave_pool]
 * @property {boolean} [probationary]
 * @property {Array<{ id: number, change_type: string, delta: number, balance_after: number, reason?: string, created_at?: string }>} [history]
 */

function balancePercent(remaining, annual) {
  const total = Math.max(0, Number(annual) || 0)
  if (total <= 0) return 0
  return Math.min(100, (Math.max(0, Number(remaining) || 0) / total) * 100)
}

function toneFromPercent(pct) {
  if (pct >= 70) return 'healthy'
  if (pct >= 30) return 'moderate'
  return 'low'
}

function ringGradientStops(tone) {
  if (tone === 'healthy') return { from: '#22c55e', to: '#16a34a' }
  if (tone === 'moderate') return { from: '#f59e0b', to: '#ea580c' }
  return { from: '#ef4444', to: '#dc2626' }
}

function statusBadge(tone, eligible) {
  if (eligible === false) {
    return (
      <span className="inline-flex items-center gap-1 rounded-full border border-slate-400/30 bg-slate-500/10 px-2 py-0.5 text-[10px] font-bold uppercase tracking-wider text-slate-700 dark:border-slate-500/30 dark:bg-slate-500/10 dark:text-slate-200">
        <AlertTriangle className="size-2.5 shrink-0 text-slate-600 dark:text-slate-300" aria-hidden />
        Not eligible yet
      </span>
    )
  }
  if (tone === 'healthy') {
    return (
      <span className="inline-flex items-center gap-1 rounded-full border border-emerald-500/40 bg-emerald-500/12 px-2 py-0.5 text-[10px] font-bold uppercase tracking-wider text-emerald-800 dark:border-emerald-500/35 dark:bg-emerald-500/15 dark:text-emerald-200">
        <CheckCircle2 className="size-2.5 shrink-0 text-emerald-600 dark:text-emerald-400" aria-hidden />
        Good balance
      </span>
    )
  }
  if (tone === 'moderate') {
    return (
      <span className="inline-flex items-center gap-1 rounded-full border border-amber-500/45 bg-amber-500/12 px-2 py-0.5 text-[10px] font-bold uppercase tracking-wider text-amber-950 dark:border-amber-500/35 dark:bg-amber-500/15 dark:text-amber-50">
        <AlertTriangle className="size-2.5 shrink-0 text-amber-600 dark:text-amber-400" aria-hidden />
        Running low
      </span>
    )
  }
  return (
    <span className="inline-flex items-center gap-1 rounded-full border border-red-500/45 bg-red-500/12 px-2 py-0.5 text-[10px] font-bold uppercase tracking-wider text-red-950 dark:border-red-500/35 dark:bg-red-500/15 dark:text-red-100">
      <AlertTriangle className="size-2.5 shrink-0 text-red-600 dark:text-red-400" aria-hidden />
      Very low
    </span>
  )
}

function formatChangeType(row) {
  const t = String(row.change_type || '').toLowerCase()
  if (t === 'deduction') return 'Deduction'
  if (t === 'addition') return 'Addition'
  if (t === 'adjustment') return 'Adjustment'
  if (t === 'annual_reset') return 'Annual reset'
  return t ? t.replace(/_/g, ' ') : 'Update'
}

function transactionIcon(row) {
  const t = String(row.change_type || '').toLowerCase()
  if (t === 'annual_reset') return RefreshCw
  if (t === 'addition') return PlusCircle
  if (t === 'deduction') return TrendingDown
  return CalendarClock
}

/** Compact circular ring — smaller footprint, aligned beside the credit figure. */
function BalanceRing({ pct, tone, size = 78 }) {
  const uid = useId()
  const gradId = `leave-ring-grad-${uid.replace(/:/g, '')}`
  const stroke = 6
  const r = (size - stroke * 2) / 2
  const circ = 2 * Math.PI * r
  const offset = circ * (1 - Math.min(100, Math.max(0, pct)) / 100)
  const { from, to } = ringGradientStops(tone)

  return (
    <div className="relative flex shrink-0 items-center justify-center" style={{ width: size, height: size }}>
      <svg width={size} height={size} className="-rotate-90" aria-hidden>
        <defs>
          <linearGradient id={gradId} x1="0%" y1="0%" x2="100%" y2="100%">
            <stop offset="0%" stopColor={from} />
            <stop offset="100%" stopColor={to} />
          </linearGradient>
        </defs>
        <circle
          cx={size / 2}
          cy={size / 2}
          r={r}
          fill="none"
          stroke="currentColor"
          strokeWidth={stroke}
          className="text-border/40 dark:text-white/8"
        />
        <MotionCircle
          cx={size / 2}
          cy={size / 2}
          r={r}
          fill="none"
          stroke={`url(#${gradId})`}
          strokeWidth={stroke}
          strokeLinecap="round"
          strokeDasharray={circ}
          initial={{ strokeDashoffset: circ }}
          animate={{ strokeDashoffset: offset }}
          transition={{ duration: 0.75, ease: [0.23, 1, 0.32, 1] }}
        />
      </svg>
      <div className="pointer-events-none absolute inset-0 flex flex-col items-center justify-center text-center">
        <span className="text-lg font-bold tabular-nums leading-none text-foreground">{Math.round(pct)}%</span>
        <span className="mt-px text-[9px] font-semibold uppercase tracking-wide text-muted-foreground">pool</span>
      </div>
    </div>
  )
}

/**
 * Compact leave balance (Employee Profile + HR). Minimal vertical space, clear hierarchy.
 */
export function LeaveCreditsCard({
  data,
  variant = 'employee',
  showAdjustButton = false,
  onAdjustCredits,
  requestLeaveTo,
  viewAllActivityTo,
  className,
}) {
  if (!data || typeof data !== 'object') return null

  const remaining = Number(data.remaining ?? 0)
  const annual = Math.max(0, Number(data.annual_allocation ?? 0))
  const pool = annual > 0 ? annual : 7
  const pending = Number(data.pending_reserved_days ?? 0)
  const effective =
    data.effective_available != null ? Number(data.effective_available) : Math.max(0, remaining - pending)
  const pct = balancePercent(remaining, pool)
  const tone = toneFromPercent(pct)
  const fullHistory = Array.isArray(data.history) ? data.history : []
  const previewRows = fullHistory.slice(0, 3)
  const hasMoreActivity = fullHistory.length > 3

  const rechargeLine = data.recharge_policy || 'Recharges on January 1st every year.'
  const subtitleOneLine = 'Paid leave pool (vacation, sick, emergency, half-day, etc.).'
  const statusSummary =
    typeof data.status_summary === 'string' && data.status_summary.trim() !== '' ? data.status_summary.trim() : null
  const displayCaption = typeof data.display === 'string' && data.display.trim() !== '' ? data.display.trim() : null
  const warningLine = typeof data.warning === 'string' && data.warning.trim() !== '' ? data.warning.trim() : null
  const showEmployeeActions = variant === 'employee' && (requestLeaveTo || viewAllActivityTo)

  return (
    <Card
      className={cn(
        'overflow-hidden border border-border/60 shadow-sm dark:border-white/8 dark:bg-[#111827]',
        '@container',
        className
      )}
    >
      <CardContent className="space-y-0 p-0">
        {/* Top: title + description + optional alerts */}
        <div className="border-b border-border/50 px-3.5 py-3 @sm:px-4">
          <div className="flex flex-col gap-2 @sm:flex-row @sm:items-start @sm:justify-between">
            <div className="flex min-w-0 flex-1 items-start gap-2.5">
              <div
                className="flex size-9 shrink-0 items-center justify-center rounded-lg border border-emerald-200/80 bg-emerald-50 dark:border-emerald-800/50 dark:bg-emerald-950/50"
                aria-hidden
              >
                <Palmtree className="size-4.5 text-emerald-600 dark:text-emerald-400" strokeWidth={1.75} />
              </div>
              <div className="min-w-0 flex-1 space-y-0.5">
                <div className="flex flex-wrap items-center gap-1.5">
                  <h3 className="text-sm font-semibold tracking-tight text-foreground @sm:text-[15px]">Leave Balance</h3>
                  {statusBadge(tone, data.eligible_for_paid_leave_pool)}
                </div>
                <p className="text-[11px] leading-snug text-muted-foreground">{subtitleOneLine}</p>
                {statusSummary ? (
                  <p className="text-[11px] font-semibold text-foreground">{statusSummary}</p>
                ) : null}
                {displayCaption && displayCaption !== statusSummary ? (
                  <p className="text-[11px] text-muted-foreground">{displayCaption}</p>
                ) : null}
                {warningLine ? (
                  <p className="rounded-md border border-amber-200/90 bg-amber-50/95 px-2 py-1 text-[11px] leading-snug text-amber-950 dark:border-amber-800/50 dark:bg-amber-950/35 dark:text-amber-50">
                    {warningLine}
                  </p>
                ) : null}
              </div>
            </div>
            {variant === 'hr' && showAdjustButton && typeof onAdjustCredits === 'function' ? (
              <Button
                type="button"
                variant="outline"
                size="sm"
                className="h-7.5 shrink-0 gap-1.5 rounded-md px-2 text-[11px] font-medium"
                onClick={onAdjustCredits}
              >
                <SlidersHorizontal className="size-3 shrink-0" aria-hidden />
                Adjust credits
              </Button>
            ) : null}
          </div>

          {/* Hero: dominant credits + compact ring */}
          <div className="mt-2.5 flex items-center justify-between gap-2.5 @sm:mt-3 @sm:gap-4">
            <div className="min-w-0 flex-1">
              <div className="flex flex-wrap items-baseline gap-x-1 gap-y-0">
                <span className="text-4xl font-bold tabular-nums tracking-tight text-foreground @sm:text-5xl @md:text-6xl">
                  {remaining}
                </span>
                <span className="text-2xl font-light text-muted-foreground/80 @sm:text-3xl @md:text-4xl">/</span>
                <span className="text-3xl font-semibold tabular-nums text-muted-foreground @sm:text-4xl @md:text-5xl">
                  {pool}
                </span>
                <span className="ml-0.5 self-end text-[11px] font-medium text-muted-foreground @sm:text-xs">credits</span>
              </div>
            </div>
            <div className="shrink-0">
              <BalanceRing pct={pct} tone={tone} size={78} />
            </div>
          </div>

          {/* Recharge — compact */}
          <div className="mt-2 space-y-0.5 text-[11px] text-muted-foreground">
            <p className="flex flex-wrap items-center gap-1.5 leading-snug">
              <CalendarClock className="size-3 shrink-0 text-emerald-600 dark:text-emerald-400" aria-hidden />
              <span>{rechargeLine}</span>
            </p>
            {data.last_recharged_display ? (
              <p className="pl-4.5 text-[10px]">{data.last_recharged_display}</p>
            ) : data.reset_date ? (
              <p className="pl-4.5 text-[10px]">Last reset: {data.reset_date}</p>
            ) : null}
          </div>

          {pending > 0 ? (
            <div className="mt-2 rounded-lg border border-amber-200/70 bg-amber-50/90 px-2 py-1 text-[11px] dark:border-amber-800/50 dark:bg-amber-950/30">
              <span className="font-semibold text-amber-950 dark:text-amber-100">{pending} pending</span>
              <span className="text-muted-foreground"> · usable: </span>
              <span className="font-bold tabular-nums text-foreground">{effective}</span>
            </div>
          ) : null}
        </div>

        {/* Recent activity */}
        <div className="px-3.5 py-2.5 @sm:px-4">
          <div className="mb-1.5 flex flex-wrap items-center justify-between gap-2">
            <h4 className="text-xs font-semibold uppercase tracking-wide text-muted-foreground">Recent activity</h4>
            {hasMoreActivity && viewAllActivityTo ? (
              <Link
                to={viewAllActivityTo}
                className="inline-flex items-center gap-0.5 text-[10px] font-semibold text-emerald-700 hover:underline dark:text-emerald-400"
              >
                View all
                <ArrowRight className="size-3" aria-hidden />
              </Link>
            ) : null}
          </div>

          {previewRows.length === 0 ? (
            <div className="flex flex-col items-center justify-center rounded-lg border border-dashed border-border/60 bg-muted/15 py-4 dark:bg-white/2">
              <Inbox className="mb-1 size-6 text-muted-foreground/45" aria-hidden />
              <p className="text-center text-xs text-muted-foreground">No credit movements recorded yet.</p>
            </div>
          ) : (
            <ul className="space-y-1">
              {previewRows.map((row) => {
                const Icon = transactionIcon(row)
                const dt = row.created_at ? new Date(row.created_at) : null
                const dateLabel =
                  dt && !Number.isNaN(dt.getTime())
                    ? dt.toLocaleString(undefined, { dateStyle: 'short', timeStyle: 'short' })
                    : '—'
                const delta = Number(row.delta)
                const deltaLabel = `${delta > 0 ? '+' : ''}${delta}`

                return (
                  <MotionLi
                    key={row.id}
                    initial={{ opacity: 0, y: 2 }}
                    animate={{ opacity: 1, y: 0 }}
                    transition={{ duration: 0.15 }}
                    className="flex items-start gap-2 rounded-lg border border-border/45 bg-muted/10 px-2 py-1.5 dark:bg-white/3 @sm:items-center @sm:justify-between"
                  >
                    <div className="flex min-w-0 flex-1 items-start gap-2">
                      <Icon className="mt-0.5 size-3 shrink-0 text-muted-foreground" aria-hidden />
                      <div className="min-w-0">
                        <div className="flex flex-wrap items-center gap-1">
                          <span className="text-[11px] font-medium text-foreground">{formatChangeType(row)}</span>
                          <Badge variant="secondary" className="h-4.5 px-1.25 font-mono text-[9px] font-bold tabular-nums">
                            {deltaLabel}
                          </Badge>
                        </div>
                        <p className="line-clamp-1 text-[10px] text-muted-foreground">{row.reason || '—'}</p>
                      </div>
                    </div>
                    <div className="flex shrink-0 flex-col items-end gap-0 text-[9px] tabular-nums text-muted-foreground @sm:text-right">
                      <time dateTime={row.created_at}>{dateLabel}</time>
                      <span className="font-medium text-foreground">Bal. {row.balance_after}</span>
                    </div>
                  </MotionLi>
                )
              })}
            </ul>
          )}
        </div>

        {showEmployeeActions ? (
          <>
            <Separator className="bg-border/50" />
            <div className="flex flex-col gap-2 px-3.5 py-2.5 @sm:flex-row @sm:items-center @sm:justify-between @sm:gap-3 @sm:px-4">
              <div className="flex flex-col gap-2 @sm:flex-row">
                {requestLeaveTo ? (
                  <Button asChild size="sm" className="h-8 w-full rounded-lg font-semibold @sm:w-auto">
                    <Link to={requestLeaveTo}>
                      <CalendarPlus className="mr-1.5 size-3.5" aria-hidden />
                      Request leave
                    </Link>
                  </Button>
                ) : null}
                {viewAllActivityTo ? (
                  <Button variant="outline" asChild size="sm" className="h-8 w-full rounded-lg @sm:w-auto">
                    <Link to={viewAllActivityTo}>
                      <History className="mr-1.5 size-3.5" aria-hidden />
                      History
                    </Link>
                  </Button>
                ) : null}
              </div>
              <p className="text-center text-[10px] text-muted-foreground @sm:max-w-52 @sm:text-right">
                Pool resets every January 1.
              </p>
            </div>
          </>
        ) : variant === 'employee' ? (
          <p className="px-3.5 pb-2.5 text-center text-[10px] text-muted-foreground @sm:px-4">
            Your leave pool refreshes each calendar year.
          </p>
        ) : null}
      </CardContent>
    </Card>
  )
}

export default LeaveCreditsCard
