import { createElement, useMemo } from 'react'
import { Dialog, DialogContent, DialogDescription, DialogFooter, DialogHeader, DialogTitle } from '@/components/ui/dialog'
import { Badge } from '@/components/ui/badge'
import { Button } from '@/components/ui/button'
import {
  BarChart3,
  CalendarDays,
  ClipboardList,
  Clock3,
  Hourglass,
  MessageSquareText,
  RefreshCcw,
  Send,
  UsersRound,
  WalletCards,
} from 'lucide-react'
import { AgcBrandLogo } from '@/components/AgcBrandLogo'
import { computeLoanEstimatePreview, normalizeInterestProfile } from '@/lib/loanRequestEstimate'
import { formatDeductionScheduleTypeShort } from '@/components/salary/salaryTabFormatters'
import { cn } from '@/lib/utils'

function formatPhp(n) {
  const v = Number(n)
  if (!Number.isFinite(v)) return '—'
  return v.toLocaleString('en-PH', { minimumFractionDigits: 2, maximumFractionDigits: 2 })
}

function formatPhpSym(n) {
  const s = formatPhp(n)
  return s === '—' ? s : `₱${s}`
}

function formatDateShort(value) {
  if (!value) return '—'
  const d = new Date(value)
  if (Number.isNaN(d.getTime())) return String(value)
  return d.toLocaleDateString('en-US', { month: 'short', day: 'numeric', year: 'numeric' })
}

function formatDateLong(value) {
  if (!value) return '—'
  const d = new Date(value)
  if (Number.isNaN(d.getTime())) return String(value)
  return d.toLocaleDateString('en-US', { month: 'long', day: 'numeric', year: 'numeric' })
}

function statusBadgeClass(status) {
  const s = String(status || '').toLowerCase()
  if (s === 'approved') return 'border-emerald-200 bg-emerald-50 text-emerald-900 dark:border-emerald-800/50 dark:bg-emerald-950/40 dark:text-emerald-100'
  if (s === 'rejected') return 'border-red-200 bg-red-50 text-red-900 dark:border-red-900/50 dark:bg-red-950/45 dark:text-red-100'
  if (s === 'pending') {
    return 'border-yellow-300 bg-yellow-50 text-yellow-950 dark:border-yellow-600/55 dark:bg-yellow-950/40 dark:text-yellow-100'
  }
  return 'border-border bg-muted/50 text-foreground dark:border-border'
}

function statusBadgeLabel(status) {
  const s = String(status || '').toLowerCase()
  if (s === 'approved') return 'Approved'
  if (s === 'rejected') return 'Rejected'
  if (s === 'pending') return 'Pending'
  return status ? String(status) : '—'
}

function SectionHeading({ icon, children, className }) {
  return (
    <div className={cn('flex items-center gap-4', className)}>
      <span className="flex size-10 shrink-0 items-center justify-center rounded-xl bg-brand/8 text-brand dark:bg-brand/15">
        {createElement(icon, { className: 'size-5', strokeWidth: 1.8, 'aria-hidden': true })}
      </span>
      <p className="text-[11px] font-bold uppercase tracking-[0.22em] text-foreground/80 dark:text-foreground/90">{children}</p>
    </div>
  )
}

function DetailItem({ icon, label, value, className }) {
  return (
    <div className={cn('flex min-w-0 gap-4 py-5', className)}>
      <span className="mt-0.5 flex size-10 shrink-0 items-center justify-center rounded-xl bg-muted/45 text-foreground dark:bg-muted/35">
        {createElement(icon, { className: 'size-5', strokeWidth: 1.75, 'aria-hidden': true })}
      </span>
      <div className="min-w-0">
        <dt className="text-sm text-muted-foreground">{label}</dt>
        <dd className="mt-1 wrap-break-word text-lg font-bold leading-snug text-foreground">{value}</dd>
      </div>
    </div>
  )
}

function timelineIconClass(step, idx) {
  const status = String(step?.status || step?.state || '').toLowerCase()
  if (idx === 0 || status === 'completed' || status === 'approved') return 'bg-foreground text-background'
  if (status === 'rejected') return 'bg-red-100 text-red-700 dark:bg-red-950/50 dark:text-red-200'
  return 'bg-muted text-foreground/70 dark:bg-muted/50'
}

export function LoanRequestDetailsModal({
  open,
  onOpenChange,
  loading = false,
  loanRequest = null,
  payCyclePreview = null,
  nextDeductionDates = [],
  approvalProgress = [],
  canApprove = false,
  canReject = false,
  actionsSlot = null,
}) {
  const estimate = useMemo(() => {
    if (!loanRequest) return null
    const interestProfile = normalizeInterestProfile(loanRequest.deduction_type)
    return computeLoanEstimatePreview(
      {
        requested_amount: loanRequest.requested_amount != null ? String(loanRequest.requested_amount) : '',
        preferred_monthly_deduction:
          loanRequest.preferred_monthly_deduction != null ? String(loanRequest.preferred_monthly_deduction) : '',
        term_months: loanRequest.term_months != null ? String(loanRequest.term_months) : '',
        deduction_schedule: loanRequest.deduction_schedule || 'both',
      },
      Number(loanRequest.requested_amount || 0),
      interestProfile,
    )
  }, [loanRequest])

  const status = String(loanRequest?.status || '').toLowerCase()
  const product = loanRequest?.pay_component?.name || loanRequest?.deduction_type?.name || 'Loan'
  const previewLine = payCyclePreview?.preview_line || payCyclePreview?.cycle_label || payCyclePreview?.name || null
  const borrowerName = loanRequest?.user?.name || null
  const borrowerCode = loanRequest?.user?.employee_code || null
  const reason = loanRequest?.reason || null
  const payDate = payCyclePreview?.pay_date || null

  return (
    <Dialog open={open} onOpenChange={onOpenChange}>
      <DialogContent
        className="max-h-[min(94vh,920px)] w-full max-w-6xl sm:max-w-6xl"
        innerClassName="gap-0 overflow-y-auto bg-card px-0 pb-0 pt-0"
        closeButtonClassName="right-5 top-5 size-11 rounded-xl bg-background text-foreground shadow-sm"
      >
        {loading || !loanRequest || !estimate ? (
          <div className="flex justify-center py-20 text-sm text-muted-foreground">Loading request details...</div>
        ) : (
          <>
            <DialogHeader className="border-b border-border/60 bg-card px-6 py-5 text-left sm:px-8">
              <div className="flex items-start gap-5 pr-16">
                <AgcBrandLogo variant="light" className="mt-1 h-10 w-20 object-left" />
                <div className="min-w-0 flex-1 space-y-1">
                  <DialogTitle className="text-xl font-bold tracking-tight text-foreground sm:text-2xl">Loan request details</DialogTitle>
                  <DialogDescription className="truncate text-sm text-muted-foreground">
                    Request #{loanRequest.id} · {product}
                    {borrowerName ? ` · ${borrowerName}` : ''}
                    {borrowerCode ? ` (${borrowerCode})` : ''}
                  </DialogDescription>
                </div>
                <Badge
                  variant="outline"
                  className={`mt-1 rounded-full px-3.5 py-1.5 text-xs font-semibold tracking-wide ${statusBadgeClass(status)}`}
                >
                  {statusBadgeLabel(status)}
                </Badge>
              </div>
            </DialogHeader>

            <div className="grid gap-6 px-6 py-6 sm:px-8 lg:grid-cols-2">
              <div className="rounded-2xl border border-border/70 bg-card p-6 shadow-sm dark:border-border/60">
                <SectionHeading icon={ClipboardList}>Request information</SectionHeading>
                <dl className="mt-6 grid sm:grid-cols-2">
                  <DetailItem
                    icon={WalletCards}
                    label="Requested amount"
                    value={formatPhpSym(loanRequest.requested_amount)}
                    className="border-b border-border/50 sm:pr-6"
                  />
                  <DetailItem
                    icon={CalendarDays}
                    label="Preferred monthly"
                    value={loanRequest.preferred_monthly_deduction != null ? formatPhpSym(loanRequest.preferred_monthly_deduction) : '—'}
                    className="border-b border-border/50 sm:border-l sm:pl-6"
                  />
                  <DetailItem
                    icon={CalendarDays}
                    label="Term (months)"
                    value={loanRequest.term_months || '—'}
                    className="border-b border-border/50 sm:pr-6"
                  />
                  <DetailItem
                    icon={RefreshCcw}
                    label="Schedule"
                    value={formatDeductionScheduleTypeShort(loanRequest.deduction_schedule || 'both')}
                    className="border-b border-border/50 sm:border-l sm:pl-6"
                  />
                  {reason ? (
                    <DetailItem
                      icon={MessageSquareText}
                      label="Reason"
                      value={reason}
                      className="sm:col-span-2"
                    />
                  ) : null}
                </dl>
              </div>

              <div className="rounded-2xl border border-border/70 bg-card p-6 shadow-sm dark:border-border/60">
                <SectionHeading icon={BarChart3}>Estimated impact</SectionHeading>
                <p className="mt-6 text-3xl font-bold tabular-nums tracking-tight text-foreground sm:text-4xl">
                  {estimate.monthlyImpact != null && Number.isFinite(estimate.monthlyImpact)
                    ? `${formatPhpSym(estimate.monthlyImpact)} / month (total)`
                    : '—'}
                </p>
                {estimate.isAdjustedToTerm ? (
                  <p className="mt-2 text-sm text-muted-foreground">
                    {estimate.adjustedReason === 'increased_to_match_term'
                      ? 'Adjusted up to fully repay principal within the selected term.'
                      : 'Adjusted down to match principal within the selected term.'}
                  </p>
                ) : null}
                {estimate.sched === 'both' && estimate.per15 != null && estimate.per30 != null ? (
                  <div className="mt-6 text-sm leading-relaxed text-foreground/90">
                    <p>Per pay run (approx.):</p>
                    <p className="font-bold tabular-nums text-foreground">
                      15th — {formatPhpSym(estimate.per15)} · 30th — {formatPhpSym(estimate.per30)}
                    </p>
                  </div>
                ) : estimate.monthlyImpact != null ? (
                  <div className="mt-6 text-sm leading-relaxed text-foreground/90">
                    <p>
                      {estimate.sched === '15th'
                        ? 'Full amount on the first semi-monthly pay run (~15th):'
                        : 'Full amount on the second semi-monthly run (end of month):'}
                    </p>
                    <p className="font-bold tabular-nums text-foreground">{formatPhpSym(estimate.monthlyImpact)}</p>
                  </div>
                ) : null}
                {estimate.hasInterestRate ? (
                  <p className="mt-3 text-sm font-medium text-foreground">
                    Interest rate: {estimate.rateDisplayPercent}% p.a. ({estimate.interestType === 'compound' ? 'compound' : 'simple'})
                  </p>
                ) : null}
                <div className="mt-6 border-t border-border/60 pt-4">
                  <p className="text-sm font-medium text-foreground/85">
                    {estimate.hasInterestRate && estimate.totalInterest != null
                      ? estimate.interestShortfall
                        ? 'Estimated payments (partial window)'
                        : 'Total repayment (principal + interest)'
                      : 'Total repayment (principal)'}
                  </p>
                  <p className="mt-1 text-lg font-bold tabular-nums text-foreground">
                    {estimate.totalRepayment != null ? formatPhpSym(estimate.totalRepayment) : '—'}
                  </p>
                  {estimate.interestRepaymentNote ? (
                    <p className="mt-1 text-xs text-muted-foreground">({estimate.interestRepaymentNote})</p>
                  ) : null}
                  {estimate.interestShortfall ? (
                    <p className="mt-2 text-xs text-amber-900 dark:text-amber-100/90">
                      Remaining principal after the projected window ≈ {formatPhpSym(estimate.principalRemainingAfterWindow)}. HR may
                      adjust the schedule on approval.
                    </p>
                  ) : null}
                </div>
                <div className="mt-4 rounded-xl bg-muted/35 px-4 py-3 dark:bg-muted/25">
                  <span className="text-xs font-medium text-foreground/90">Approx. deductions</span>
                  <p className="mt-0.5 text-base font-bold tabular-nums text-foreground">
                    {estimate.deductionCount != null ? `${estimate.deductionCount}` : '—'}
                  </p>
                  <span className="text-[11px] text-muted-foreground">
                    {estimate.deductionCount != null ? `(${formatDeductionScheduleTypeShort(estimate.sched)})` : ''}
                  </span>
                </div>
                {previewLine ? (
                  <div className="mt-4 border-t border-border/60 pt-4">
                    <div className="flex gap-3 text-xs leading-relaxed text-muted-foreground">
                      <CalendarDays className="mt-0.5 size-4 shrink-0 text-foreground" aria-hidden />
                      <p>
                        <span className="font-medium text-foreground">Pay cycle (your account):</span>{' '}
                        {payCyclePreview?.name ? `${payCyclePreview.name} · ` : ''}
                        {previewLine}
                        {payDate ? (
                          <>
                            {' → '}
                            <span className="font-medium text-brand">Pay Date: {formatDateLong(payDate)}</span>
                          </>
                        ) : null}
                      </p>
                    </div>
                  </div>
                ) : null}
                <div className="mt-3 flex gap-3 text-xs leading-relaxed text-muted-foreground">
                  <Clock3 className="mt-0.5 size-4 shrink-0 text-foreground" aria-hidden />
                  <p>
                    <span className="font-medium text-foreground">
                      {Array.isArray(nextDeductionDates) && nextDeductionDates.length > 1
                        ? 'Next deduction dates: '
                        : 'Next deduction date: '}
                    </span>
                    <span className="font-medium text-brand">
                      {Array.isArray(nextDeductionDates) && nextDeductionDates.length > 1
                        ? nextDeductionDates.slice(0, 2).map(formatDateShort).join(' and ')
                        : nextDeductionDates?.[0] ? formatDateShort(nextDeductionDates[0]) : '—'}
                    </span>
                  </p>
                </div>
              </div>
            </div>

            <div className="px-6 pb-6 sm:px-8">
              <div className="rounded-2xl border border-border/70 bg-card p-6 shadow-sm dark:border-border/60">
                <SectionHeading icon={Hourglass}>Approval timeline</SectionHeading>
                <div className="relative mt-6 space-y-3">
                {approvalProgress.length === 0 ? (
                  <p className="text-sm text-muted-foreground">No timeline data.</p>
                ) : (
                  approvalProgress.map((step, idx) => {
                    const stepStatus = String(step.status || step.state || '').toLowerCase()
                    const showPending = stepStatus === 'pending' || (!step.acted_at && idx > 0)
                    return (
                      <div key={step.key || idx} className="relative flex gap-5">
                        {idx + 1 < approvalProgress.length ? (
                          <span className="absolute -bottom-3 left-5 top-11 w-px bg-border" aria-hidden />
                        ) : null}
                        <span className={cn('relative z-1 flex size-10 shrink-0 items-center justify-center rounded-full', timelineIconClass(step, idx))}>
                          {idx === 0 ? (
                            <Send className="size-5" strokeWidth={1.8} aria-hidden />
                          ) : (
                            <UsersRound className="size-5" strokeWidth={1.8} aria-hidden />
                          )}
                        </span>
                        <div className="min-w-0 flex-1 rounded-2xl border border-border/60 bg-card px-4 py-3 text-sm shadow-sm dark:bg-muted/10">
                          <div className="flex flex-wrap items-center gap-2">
                            <p className="font-medium text-foreground">{step.label}</p>
                            {showPending ? (
                              <Badge variant="outline" className={statusBadgeClass('pending')}>
                                Pending
                              </Badge>
                            ) : null}
                          </div>
                          <p className="mt-1 text-xs text-muted-foreground">{step.acted_at ? formatDateShort(step.acted_at) : '—'}</p>
                        </div>
                      </div>
                    )
                  })
                )}
                </div>
              </div>
            </div>

            {actionsSlot ? (
              <DialogFooter className="flex items-center justify-between border-t border-border/60 px-6 py-4 sm:px-8">
                <div className="text-xs text-muted-foreground">
                  {canApprove || canReject ? 'You can take action on this request.' : 'This request is read-only.'}
                </div>
                <div className="flex items-center gap-2">
                  {actionsSlot}
                  <Button type="button" variant="outline" className="rounded-full px-6" onClick={() => onOpenChange(false)}>
                    Close
                  </Button>
                </div>
              </DialogFooter>
            ) : null}
          </>
        )}
      </DialogContent>
    </Dialog>
  )
}
