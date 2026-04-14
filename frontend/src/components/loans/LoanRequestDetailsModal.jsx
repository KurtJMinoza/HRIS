import { useMemo } from 'react'
import { Dialog, DialogContent, DialogDescription, DialogFooter, DialogHeader, DialogTitle } from '@/components/ui/dialog'
import { Badge } from '@/components/ui/badge'
import { Button } from '@/components/ui/button'
import { computeLoanEstimatePreview, normalizeInterestProfile } from '@/lib/loanRequestEstimate'
import { formatDeductionScheduleTypeShort } from '@/components/salary/EmployeeSalaryTab'

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
      <DialogContent className="max-h-[min(92vh,900px)] w-full max-w-5xl sm:max-w-5xl" innerClassName="gap-0 overflow-y-auto px-0 pb-0 pt-0">
        {loading || !loanRequest || !estimate ? (
          <div className="flex justify-center py-20 text-sm text-muted-foreground">Loading request details...</div>
        ) : (
          <>
            <DialogHeader className="border-b border-border/60 bg-muted/25 px-6 py-5 text-left sm:px-8">
              <div className="flex flex-wrap items-start justify-between gap-4">
                <div className="space-y-1">
                  <DialogTitle className="text-xl font-semibold tracking-tight text-[#0A0A0A] dark:text-slate-50 sm:text-2xl">
                    Loan request details
                  </DialogTitle>
                  <DialogDescription className="text-sm text-muted-foreground">
                    Request #{loanRequest.id} · {product}
                    {borrowerName ? ` · ${borrowerName}` : ''}
                    {borrowerCode ? ` (${borrowerCode})` : ''}
                  </DialogDescription>
                </div>
                <Badge
                  variant="outline"
                  className={`rounded-full px-3 py-1 text-xs font-semibold tracking-wide ${statusBadgeClass(status)}`}
                >
                  {statusBadgeLabel(status)}
                </Badge>
              </div>
            </DialogHeader>

            <div className="grid gap-6 px-6 py-6 sm:px-8 lg:grid-cols-2">
              <div className="space-y-5 rounded-2xl border border-border/50 bg-muted/20 p-5 sm:p-6">
                <p className="text-[11px] font-semibold uppercase tracking-[0.14em] text-muted-foreground">Request information</p>
                <dl className="grid gap-4 sm:grid-cols-2">
                  <div>
                    <dt className="text-sm font-medium text-muted-foreground">Requested amount</dt>
                    <dd className="mt-1 text-xl font-bold tabular-nums leading-tight text-[#0A0A0A]">
                      {formatPhpSym(loanRequest.requested_amount)}
                    </dd>
                  </div>
                  <div>
                    <dt className="text-sm font-medium text-muted-foreground">Preferred monthly</dt>
                    <dd className="mt-1 text-xl font-bold tabular-nums leading-tight text-[#0A0A0A]">
                      {loanRequest.preferred_monthly_deduction != null ? formatPhpSym(loanRequest.preferred_monthly_deduction) : '—'}
                    </dd>
                  </div>
                  <div>
                    <dt className="text-sm font-medium text-muted-foreground">Term (months)</dt>
                    <dd className="mt-1 text-xl font-bold leading-tight text-[#0A0A0A]">{loanRequest.term_months || '—'}</dd>
                  </div>
                  <div>
                    <dt className="text-sm font-medium text-muted-foreground">Schedule</dt>
                    <dd className="mt-1 text-xl font-bold leading-tight text-[#0A0A0A]">
                      {formatDeductionScheduleTypeShort(loanRequest.deduction_schedule || 'both')}
                    </dd>
                  </div>
                </dl>
                {reason ? (
                  <div className="border-t border-border/40 pt-4">
                    <dt className="text-sm font-medium text-muted-foreground">Reason</dt>
                    <dd className="mt-1 text-sm leading-relaxed text-[#0A0A0A]/85 dark:text-slate-200">{reason}</dd>
                  </div>
                ) : null}
              </div>

              <div className="space-y-4 rounded-2xl border border-[#0A0A0A]/10 bg-gradient-to-br from-muted/40 to-muted/20 p-5 shadow-sm dark:border-white/10 dark:bg-white/[0.03] sm:p-6">
                <p className="text-[11px] font-semibold uppercase tracking-[0.14em] text-muted-foreground">Estimated impact</p>
                <p className="text-2xl font-bold tabular-nums text-[#0A0A0A] dark:text-slate-50 sm:text-3xl">
                  {estimate.monthlyImpact != null && Number.isFinite(estimate.monthlyImpact)
                    ? `${formatPhpSym(estimate.monthlyImpact)} / month (total)`
                    : '—'}
                </p>
                {estimate.isAdjustedToTerm ? (
                  <p className="text-xs text-muted-foreground">
                    {estimate.adjustedReason === 'increased_to_match_term'
                      ? 'Adjusted up to fully repay principal within the selected term.'
                      : 'Adjusted down to match principal within the selected term.'}
                  </p>
                ) : null}
                {estimate.sched === 'both' && estimate.per15 != null && estimate.per30 != null ? (
                  <p className="text-sm tabular-nums text-[#0A0A0A]/90 dark:text-slate-200">
                    Per pay run (approx.): 15th — {formatPhpSym(estimate.per15)} · 30th — {formatPhpSym(estimate.per30)}
                  </p>
                ) : estimate.monthlyImpact != null ? (
                  <p className="text-sm text-[#0A0A0A]/90 dark:text-slate-200">
                    {estimate.sched === '15th'
                      ? `Full amount on the first semi-monthly pay run (~15th): ${formatPhpSym(estimate.monthlyImpact)}`
                      : `Full amount on the second semi-monthly run (end of month): ${formatPhpSym(estimate.monthlyImpact)}`}
                  </p>
                ) : null}
                {estimate.hasInterestRate ? (
                  <p className="text-sm font-medium text-[#0A0A0A] dark:text-slate-100">
                    Interest rate: {estimate.rateDisplayPercent}% p.a. ({estimate.interestType === 'compound' ? 'compound' : 'simple'})
                  </p>
                ) : null}
                <div className="border-t border-border/40 pt-3">
                  <p className="text-sm font-medium text-foreground/85">
                    {estimate.hasInterestRate && estimate.totalInterest != null
                      ? estimate.interestShortfall
                        ? 'Estimated payments (partial window)'
                        : 'Total repayment (principal + interest)'
                      : 'Total repayment (principal)'}
                  </p>
                  <p className="mt-1 text-lg font-bold tabular-nums text-[#0A0A0A] dark:text-slate-50">
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
                <div className="rounded-xl border border-border/40 bg-background/60 px-3 py-2.5 dark:bg-white/[0.04]">
                  <span className="text-xs font-medium text-foreground/80">Approx. deductions</span>
                  <p className="mt-0.5 text-base font-bold tabular-nums text-[#0A0A0A] dark:text-slate-50">
                    {estimate.deductionCount != null ? `${estimate.deductionCount}` : '—'}
                  </p>
                  <span className="text-[11px] text-muted-foreground">
                    {estimate.deductionCount != null ? `(${formatDeductionScheduleTypeShort(estimate.sched)})` : ''}
                  </span>
                </div>
                {previewLine ? (
                  <div className="border-t border-border/40 pt-3">
                    <p className="text-xs leading-relaxed text-muted-foreground">
                      <span className="font-medium text-foreground/80">Pay cycle (your account):</span>{' '}
                      {payCyclePreview?.name ? `${payCyclePreview.name} · ` : ''}
                      {previewLine}
                      {payDate ? (
                        <>
                          {' → '}
                          <span className="font-medium text-foreground/80">Pay Date:</span> {formatDateLong(payDate)}
                        </>
                      ) : null}
                    </p>
                  </div>
                ) : null}
                <p className="text-xs leading-relaxed text-muted-foreground">
                  <span className="font-medium text-foreground/80">
                    {Array.isArray(nextDeductionDates) && nextDeductionDates.length > 1
                      ? 'Next deduction dates: '
                      : 'Next deduction date: '}
                  </span>
                  {Array.isArray(nextDeductionDates) && nextDeductionDates.length > 1
                    ? nextDeductionDates.slice(0, 2).map(formatDateShort).join(' and ')
                    : nextDeductionDates?.[0] ? formatDateShort(nextDeductionDates[0]) : '—'}
                </p>
              </div>
            </div>

            <div className="border-t border-border/60 px-6 py-4 sm:px-8">
              <p className="text-[11px] font-semibold uppercase tracking-[0.14em] text-muted-foreground">Approval timeline</p>
              <div className="mt-3 space-y-2">
                {approvalProgress.length === 0 ? (
                  <p className="text-sm text-muted-foreground">No timeline data.</p>
                ) : (
                  approvalProgress.map((step, idx) => (
                    <div key={step.key || idx} className="rounded-lg border border-border/50 bg-muted/20 px-3 py-2 text-sm">
                      <p className="font-medium text-[#0A0A0A]">{step.label}</p>
                      <p className="text-xs text-muted-foreground">{step.acted_at ? formatDateShort(step.acted_at) : 'Pending'}</p>
                    </div>
                  ))
                )}
              </div>
            </div>

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
          </>
        )}
      </DialogContent>
    </Dialog>
  )
}
