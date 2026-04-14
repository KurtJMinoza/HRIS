import { useCallback, useEffect, useMemo, useRef, useState } from 'react'
import { Loader2 } from 'lucide-react'
import { Button } from '@/components/ui/button'
import {
  Dialog,
  DialogContent,
  DialogDescription,
  DialogFooter,
  DialogHeader,
  DialogTitle,
} from '@/components/ui/dialog'
import { Input } from '@/components/ui/input'
import { Label } from '@/components/ui/label'
import { Textarea } from '@/components/ui/textarea'
import { useToast } from '@/components/ui/use-toast'
import { createEmployeeLoanRequest, getEmployeeLoanRequestContext, getEmployeeNextDeductionDates } from '@/api'
import { formatDeductionScheduleTypeShort } from '@/components/salary/EmployeeSalaryTab'
import {
  computeLoanEstimatePreview,
  EMPTY_REQUEST_LOAN_FORM,
  PRINCIPAL_PRESETS,
  resolveInterestProfileFromProductKey,
  resolveManualAssignIdsFromProductKey,
  TEXT,
} from '@/lib/loanRequestEstimate'
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

/**
 * Unified “Request a loan” UI for employee self-service and admin HR planning (prefills Manual assign).
 */
export function RequestLoanModal({
  open,
  onOpenChange,
  mode = 'employee',
  /** When set, loan catalog + pay cycle come from parent (employee page after load). */
  contextFromParent = null,
  borrowerLabel,
  borrowerHelperText,
  onEmployeeSuccess,
  onAdminContinue,
  adminContinueLabel = 'Continue to manual assign',
  /** When the dialog opens, seed principal (employee default 50k). */
  initialPrincipal = 50000,
}) {
  const { toast } = useToast()
  const [form, setForm] = useState(EMPTY_REQUEST_LOAN_FORM)
  const [principalSlider, setPrincipalSlider] = useState(initialPrincipal)
  const [submitting, setSubmitting] = useState(false)
  const [ctx, setCtx] = useState({
    loan_types: [],
    loan_pay_components: [],
    pay_cycle_preview: null,
  })
  const [nextDeductionDates, setNextDeductionDates] = useState([])
  const lastLoanProductKeyRef = useRef('')

  useEffect(() => {
    if (!open) return
    const p = initialPrincipal ?? 50000
    setPrincipalSlider(p)
    setForm({ ...EMPTY_REQUEST_LOAN_FORM, requested_amount: String(p) })
  }, [open, initialPrincipal])

  const syncContext = useCallback(async () => {
    try {
      const c = await getEmployeeLoanRequestContext()
      setCtx(c || {})
    } catch (e) {
      setCtx({ loan_types: [], loan_pay_components: [], pay_cycle_preview: null })
      toast({
        title: 'Loan context unavailable',
        description: e?.message || 'Could not load loan products right now.',
        variant: 'destructive',
      })
    }
  }, [toast])

  useEffect(() => {
    if (!open) return
    if (contextFromParent) {
      setCtx(contextFromParent)
      return
    }
    syncContext()
  }, [open, contextFromParent, syncContext])

  useEffect(() => {
    if (!open) {
      lastLoanProductKeyRef.current = ''
      return
    }
    const pk = form.product_key || ''
    const [kind, idStr] = pk.split(':')
    if (kind !== 'pc' || !idStr) {
      lastLoanProductKeyRef.current = ''
      return
    }
    if (lastLoanProductKeyRef.current === pk) return
    lastLoanProductKeyRef.current = pk
    const pc = (ctx.loan_pay_components || []).find((p) => String(p.id) === String(idStr))
    const next = pc?.deduction_schedule
    if (next) {
      setForm((f) => ({ ...f, deduction_schedule: next }))
    }
  }, [open, form.product_key, ctx.loan_pay_components])

  useEffect(() => {
    let cancelled = false
    if (!open || !form.deduction_schedule) {
      setNextDeductionDates([])
      return () => {}
    }
    ;(async () => {
      try {
        const resp = await getEmployeeNextDeductionDates({ schedule_type: form.deduction_schedule })
        if (!cancelled) {
          setNextDeductionDates(Array.isArray(resp?.next_dates) ? resp.next_dates : [])
        }
      } catch {
        if (!cancelled) setNextDeductionDates([])
      }
    })()
    return () => {
      cancelled = true
    }
  }, [open, form.deduction_schedule])

  const productOptions = useMemo(() => {
    const pcs = (ctx.loan_pay_components || []).map((pc) => ({
      key: `pc:${pc.id}`,
      label: `${pc.name} (${pc.code})`,
      sub: 'Pay component',
    }))
    const dts = (ctx.loan_types || []).map((t) => ({
      key: `dt:${t.id}`,
      label: t.name,
      sub: 'Deduction type',
    }))
    return [...pcs, ...dts]
  }, [ctx.loan_pay_components, ctx.loan_types])

  const payCycleSummaryLine = useMemo(() => {
    const p = ctx.pay_cycle_preview
    if (!p) return null
    return p.preview_line || p.cycle_label || p.name || null
  }, [ctx.pay_cycle_preview])

  const interestProfile = useMemo(
    () => resolveInterestProfileFromProductKey(form.product_key, ctx),
    [form.product_key, ctx.loan_types],
  )

  const loanEstimatePreview = useMemo(
    () => computeLoanEstimatePreview(form, principalSlider, interestProfile),
    [form, principalSlider, interestProfile],
  )

  const validation = useMemo(() => {
    const rawPrincipal =
      form.requested_amount !== '' && form.requested_amount != null ? Number(form.requested_amount) : Number(principalSlider)
    const principal = Number.isFinite(rawPrincipal) && rawPrincipal > 0 ? rawPrincipal : null
    const prefStr = form.preferred_monthly_deduction
    const pref = prefStr === '' || prefStr == null ? NaN : Number(prefStr)
    const termStr = form.term_months
    const term = termStr === '' || termStr == null ? NaN : Number(termStr)

    let preferredTooHigh = false
    if (principal != null && Number.isFinite(pref) && pref > 0 && pref > principal) {
      preferredTooHigh = true
    }
    let termInvalid = false
    if (form.term_months !== '' && form.term_months != null) {
      if (!Number.isFinite(term) || term < 1 || term > 600) termInvalid = true
    }
    return { preferredTooHigh, termInvalid }
  }, [form.requested_amount, form.preferred_monthly_deduction, form.term_months, principalSlider])

  function handleDialogOpenChange(next) {
    if (!next) {
      setForm(EMPTY_REQUEST_LOAN_FORM)
      setPrincipalSlider(50000)
      lastLoanProductKeyRef.current = ''
    }
    onOpenChange(next)
  }

  async function handleSubmit(e) {
    e.preventDefault()
    if (mode !== 'employee') return
    if (!form.product_key) {
      toast({ title: 'Select a loan product', variant: 'destructive' })
      return
    }
    if (validation.preferredTooHigh) {
      toast({ title: 'Preferred monthly cannot exceed principal', variant: 'destructive' })
      return
    }
    if (validation.termInvalid) {
      toast({ title: 'Term must be between 1 and 600 months', variant: 'destructive' })
      return
    }
    const [kind, idStr] = form.product_key.split(':')
    const rawAmt = form.requested_amount !== '' ? Number(form.requested_amount) : Number(principalSlider)
    if (!Number.isFinite(rawAmt) || rawAmt <= 0) {
      toast({ title: 'Enter a valid principal amount', variant: 'destructive' })
      return
    }
    const payload = {
      requested_amount: rawAmt,
      reason: form.reason.trim() || undefined,
      deduction_schedule: form.deduction_schedule || 'both',
    }
    if (form.preferred_monthly_deduction !== '') {
      const preferred = Number(form.preferred_monthly_deduction)
      if (Number.isFinite(preferred) && preferred > 0) {
        payload.preferred_monthly_deduction = preferred
      }
    }
    if (form.term_months !== '') {
      const term = Number(form.term_months)
      if (Number.isFinite(term) && term > 0) {
        payload.term_months = term
      }
    }
    if (kind === 'pc') {
      payload.pay_component_id = Number(idStr)
    } else {
      payload.deduction_type_id = Number(idStr)
    }

    setSubmitting(true)
    try {
      await createEmployeeLoanRequest(payload)
      toast({ title: 'Loan request submitted' })
      handleDialogOpenChange(false)
      await onEmployeeSuccess?.()
    } catch (err) {
      toast({ title: 'Submit failed', description: err.message, variant: 'destructive' })
    } finally {
      setSubmitting(false)
    }
  }

  function handleAdminContinue() {
    if (!form.product_key) {
      toast({ title: 'Select a loan product', variant: 'destructive' })
      return
    }
    if (validation.preferredTooHigh) {
      toast({ title: 'Preferred monthly cannot exceed principal', variant: 'destructive' })
      return
    }
    if (validation.termInvalid) {
      toast({ title: 'Term must be between 1 and 600 months', variant: 'destructive' })
      return
    }
    const rawAmt = form.requested_amount !== '' ? Number(form.requested_amount) : Number(principalSlider)
    if (!Number.isFinite(rawAmt) || rawAmt <= 0) {
      toast({ title: 'Enter a valid principal amount', variant: 'destructive' })
      return
    }
    const ids = resolveManualAssignIdsFromProductKey(form.product_key, ctx)
    if (!ids.deduction_type_id) {
      toast({
        title: 'Could not resolve deduction type',
        description: 'Pick a loan product linked to a deduction type, or create types under Deduction types.',
        variant: 'destructive',
      })
      return
    }
    const termNum = form.term_months === '' || form.term_months == null ? NaN : Number(form.term_months)
    let monthly = null
    if (loanEstimatePreview.monthlyImpact != null && Number.isFinite(loanEstimatePreview.monthlyImpact)) {
      monthly = loanEstimatePreview.monthlyImpact
    } else if (Number.isFinite(termNum) && termNum > 0) {
      monthly = rawAmt / termNum
    }
    if (monthly == null || !Number.isFinite(monthly) || monthly <= 0) {
      toast({
        title: 'Add term or preferred monthly',
        description: 'We need a monthly installment estimate to prefill the assignment.',
        variant: 'destructive',
      })
      return
    }
    onAdminContinue?.({
      product_key: form.product_key,
      deduction_type_id: ids.deduction_type_id,
      pay_component_id: ids.pay_component_id || '',
      requested_amount: rawAmt,
      preferred_monthly_deduction: form.preferred_monthly_deduction,
      term_months: form.term_months,
      deduction_schedule: form.deduction_schedule || 'both',
      amount: String(Math.round(Math.max(0.01, monthly) * 100) / 100),
      remaining_balance: String(rawAmt),
      monthlyImpact: loanEstimatePreview.monthlyImpact,
    })
    handleDialogOpenChange(false)
    toast({
      title: 'Prefilled manual assign',
      description: 'Choose an employee and save the assignment.',
    })
  }

  const title = 'Request a loan'
  const description =
    mode === 'employee'
      ? "Choose a product, set principal and term — we'll show estimated payroll impact. HR reviews every request."
      : 'Same planner as employee self-service. Prefills Manual assign — choose an employee, then save. This does not submit an employee loan request.'

  return (
    <Dialog open={open} onOpenChange={handleDialogOpenChange}>
      <DialogContent className="max-h-[min(92vh,820px)] overflow-hidden overflow-y-auto rounded-2xl border-border/60 p-0 sm:max-w-2xl">
        <div className="h-px w-full bg-border" aria-hidden />
        <form onSubmit={handleSubmit} className="px-6 pb-6 pt-2 sm:px-6">
          <DialogHeader className="pt-4">
            <DialogTitle className="text-xl" style={{ color: TEXT }}>
              {title}
            </DialogTitle>
            <DialogDescription className="text-base">{description}</DialogDescription>
          </DialogHeader>
          <div className="grid gap-6 py-2">
            {mode === 'employee' ? (
              <div className="space-y-2">
                <Label className="text-[#0A0A0A] dark:text-slate-100">Loan for</Label>
                <div
                  className="flex min-h-11 items-center rounded-xl border border-border bg-muted/40 px-3 py-2 text-sm text-foreground"
                  aria-readonly="true"
                >
                  {borrowerLabel}
                </div>
                {borrowerHelperText ? <p className="text-xs text-muted-foreground">{borrowerHelperText}</p> : null}
              </div>
            ) : (
              <div className="rounded-xl border border-dashed border-border/70 bg-muted/30 px-4 py-3 text-sm leading-relaxed text-muted-foreground">
                Plan amounts here, then use <span className="font-medium text-[#0A0A0A] dark:text-slate-200">Continue to manual assign</span>{' '}
                to pick the employee on the Manual assign tab. This does not create an employee self-service request.
              </div>
            )}

            <div className="space-y-3">
              <Label className="text-[#0A0A0A] dark:text-slate-100">Loan product</Label>
              {productOptions.length === 0 ? (
                <p className="rounded-xl border border-dashed border-border bg-muted/30 px-4 py-3 text-sm text-muted-foreground">
                  No loan products available. Ask HR to configure loan pay components or deduction types.
                </p>
              ) : (
                <div className="grid gap-2 sm:grid-cols-2">
                  {productOptions.map((o) => {
                    const selected = form.product_key === o.key
                    return (
                      <button
                        key={o.key}
                        type="button"
                        onClick={() => setForm((f) => ({ ...f, product_key: o.key }))}
                        className={cn(
                          'rounded-xl border-2 p-4 text-left transition-all duration-200',
                          selected
                            ? 'border-[#0A0A0A] bg-muted/60 shadow-sm dark:border-slate-100 dark:bg-white/10'
                            : 'border-border/60 bg-card hover:border-muted-foreground/30 hover:bg-muted/30',
                        )}
                      >
                        <p className="font-semibold leading-snug" style={{ color: TEXT }}>
                          {o.label}
                        </p>
                        <p className="mt-1 text-xs text-muted-foreground">{o.sub}</p>
                      </button>
                    )
                  })}
                </div>
              )}
            </div>

            <div className="space-y-3 rounded-2xl border border-border/60 bg-muted/20 p-4 dark:bg-white/[0.03]">
              <div className="flex items-center justify-between gap-2">
                <Label htmlFor="rlm-principal-slider" className="text-[#0A0A0A] dark:text-slate-100">
                  Principal (₱)
                </Label>
                <span className="text-lg font-bold tabular-nums text-[#0A0A0A] dark:text-slate-50">
                  {formatPhpSym(form.requested_amount || principalSlider)}
                </span>
              </div>
              <input
                id="rlm-principal-slider"
                type="range"
                min={5000}
                max={2000000}
                step={1000}
                value={Number(form.requested_amount) || principalSlider}
                onChange={(e) => {
                  const v = Number(e.target.value)
                  setPrincipalSlider(v)
                  setForm((f) => ({ ...f, requested_amount: String(v) }))
                }}
                className="h-2 w-full cursor-pointer accent-[#0A0A0A] dark:accent-slate-200"
              />
              <div className="flex flex-wrap gap-2">
                {PRINCIPAL_PRESETS.map((p) => (
                  <button
                    key={p}
                    type="button"
                    onClick={() => {
                      setPrincipalSlider(p)
                      setForm((f) => ({ ...f, requested_amount: String(p) }))
                    }}
                    className="rounded-full border border-border bg-background px-3 py-1 text-xs font-semibold text-[#0A0A0A] transition-colors hover:bg-muted dark:text-slate-100"
                  >
                    ₱{(p / 1000).toLocaleString()}k
                  </button>
                ))}
              </div>
              <div className="relative">
                <span className="pointer-events-none absolute left-3 top-1/2 -translate-y-1/2 text-sm text-muted-foreground">₱</span>
                <Input
                  id="rlm-principal"
                  type="number"
                  min="1"
                  step="0.01"
                  required
                  className="h-11 rounded-xl border-border/80 pl-8"
                  value={form.requested_amount}
                  onChange={(e) => {
                    const v = e.target.value
                    setForm((f) => ({ ...f, requested_amount: v }))
                    const n = Number(v)
                    if (Number.isFinite(n)) setPrincipalSlider(n)
                  }}
                />
              </div>
            </div>

            <div className="grid gap-4 sm:grid-cols-2">
              <div className="space-y-2">
                <Label htmlFor="rlm-pref">Preferred monthly (₱)</Label>
                <div className="relative">
                  <span className="pointer-events-none absolute left-3 top-1/2 -translate-y-1/2 text-sm text-muted-foreground">₱</span>
                  <Input
                    id="rlm-pref"
                    type="number"
                    min="0.01"
                    step="0.01"
                    className={cn('h-11 rounded-xl pl-8', validation.preferredTooHigh && 'border-destructive')}
                    value={form.preferred_monthly_deduction}
                    onChange={(e) => setForm((f) => ({ ...f, preferred_monthly_deduction: e.target.value }))}
                    placeholder="Optional"
                  />
                </div>
                {validation.preferredTooHigh ? (
                  <p className="text-xs text-destructive">Must not exceed principal.</p>
                ) : null}
              </div>
              <div className="space-y-2">
                <Label htmlFor="rlm-term">Term (months)</Label>
                <Input
                  id="rlm-term"
                  type="number"
                  min="1"
                  max="600"
                  className={cn('h-11 rounded-xl', validation.termInvalid && 'border-destructive')}
                  value={form.term_months}
                  onChange={(e) => setForm((f) => ({ ...f, term_months: e.target.value }))}
                  placeholder="Optional"
                />
                {validation.termInvalid ? (
                  <p className="text-xs text-destructive">Use 1–600 months.</p>
                ) : null}
              </div>
            </div>

            <div className="space-y-3">
              <Label className="text-[#0A0A0A] dark:text-slate-100">Deduction schedule</Label>
              <p className="text-xs text-muted-foreground">
                This will deduct from salary on the selected schedule (aligned with the employee&apos;s pay cycle and HR Deduction
                Schedule Settings for this product).
              </p>
              <div className="grid gap-2 sm:grid-cols-3">
                {[
                  { value: '15th', label: 'First semi-monthly run', sub: 'Full deduction on first payroll run' },
                  { value: '30th', label: 'End of month', sub: 'Full deduction on second payroll run' },
                  { value: 'both', label: '50/50 split', sub: 'Split evenly across two payroll runs' },
                ].map((opt) => {
                  const selected = form.deduction_schedule === opt.value
                  return (
                    <button
                      key={opt.value}
                      type="button"
                      onClick={() => setForm((f) => ({ ...f, deduction_schedule: opt.value }))}
                      className={cn(
                        'rounded-xl border-2 p-3 text-left transition-all duration-200 sm:p-4',
                        selected
                          ? 'border-[#0A0A0A] bg-muted/60 shadow-sm dark:border-slate-100 dark:bg-white/10'
                          : 'border-border/60 bg-card hover:border-muted-foreground/30 hover:bg-muted/30',
                      )}
                    >
                      <p className="text-sm font-semibold leading-snug" style={{ color: TEXT }}>
                        {opt.label}
                      </p>
                      <p className="mt-1 text-[11px] text-muted-foreground">{opt.sub}</p>
                    </button>
                  )
                })}
              </div>
            </div>

            <div className="rounded-2xl border-2 border-[#0A0A0A]/10 bg-gradient-to-br from-muted/45 via-muted/25 to-muted/15 p-5 shadow-sm dark:border-white/10 dark:from-white/[0.06] dark:via-white/[0.03] dark:to-transparent sm:p-5">
              <p className="text-[11px] font-semibold uppercase tracking-wide text-muted-foreground">Estimated impact</p>
              <p className="mt-1 text-2xl font-bold tabular-nums text-[#0A0A0A] dark:text-slate-50">
                {loanEstimatePreview.monthlyImpact != null && Number.isFinite(loanEstimatePreview.monthlyImpact)
                  ? `${formatPhpSym(loanEstimatePreview.monthlyImpact)} / month (total)`
                  : '—'}
              </p>
              {loanEstimatePreview.isAdjustedToTerm ? (
                <p className="mt-1 text-xs text-muted-foreground">
                  {loanEstimatePreview.adjustedReason === 'increased_to_match_term'
                    ? 'Adjusted up to fully repay principal within the selected term.'
                    : 'Adjusted down to match principal within the selected term.'}
                </p>
              ) : null}
              {loanEstimatePreview.sched === 'both' && loanEstimatePreview.per15 != null && loanEstimatePreview.per30 != null ? (
                <p className="mt-2 text-sm tabular-nums text-[#0A0A0A]/90 dark:text-slate-200">
                  Per pay run (approx.): 15th — {formatPhpSym(loanEstimatePreview.per15)} · 30th —{' '}
                  {formatPhpSym(loanEstimatePreview.per30)}
                </p>
              ) : loanEstimatePreview.monthlyImpact != null && Number.isFinite(loanEstimatePreview.monthlyImpact) ? (
                <p className="mt-2 text-sm text-[#0A0A0A]/90 dark:text-slate-200">
                  {loanEstimatePreview.sched === '15th'
                    ? `Full amount on the first semi-monthly pay run (~15th): ${formatPhpSym(loanEstimatePreview.monthlyImpact)}`
                    : `Full amount on the second semi-monthly run (end of month): ${formatPhpSym(loanEstimatePreview.monthlyImpact)}`}
                </p>
              ) : null}
              {loanEstimatePreview.hasInterestRate ? (
                <p className="mt-3 text-sm font-medium text-[#0A0A0A] dark:text-slate-100">
                  Interest rate: {loanEstimatePreview.rateDisplayPercent}% p.a. (
                  {loanEstimatePreview.interestType === 'compound' ? 'compound' : 'simple'})
                </p>
              ) : null}
              <div className="mt-3 border-t border-border/60 pt-3">
                <p className="text-sm font-medium text-foreground/85">
                  {loanEstimatePreview.hasInterestRate && loanEstimatePreview.totalInterest != null
                    ? loanEstimatePreview.interestShortfall
                      ? 'Estimated payments (partial window)'
                      : 'Total repayment (principal + interest)'
                    : 'Total repayment (principal)'}
                </p>
                <p className="mt-1 text-lg font-bold tabular-nums text-[#0A0A0A] dark:text-slate-50">
                  {loanEstimatePreview.totalRepayment != null ? formatPhpSym(loanEstimatePreview.totalRepayment) : '—'}
                </p>
                {loanEstimatePreview.interestRepaymentNote ? (
                  <p className="mt-1 text-xs leading-relaxed text-muted-foreground">({loanEstimatePreview.interestRepaymentNote})</p>
                ) : null}
                {loanEstimatePreview.interestShortfall ? (
                  <p className="mt-2 text-xs leading-relaxed text-amber-900 dark:text-amber-100/90">
                    At this installment the loan may not fully repay within the estimated window (remaining principal ≈{' '}
                    {formatPhpSym(loanEstimatePreview.principalRemainingAfterWindow)}). HR may adjust amounts on approval.
                  </p>
                ) : null}
              </div>
              <div className="mt-3 text-sm text-muted-foreground">
                <div className="rounded-xl border border-border/50 bg-background/50 px-3 py-2.5 dark:bg-white/[0.04]">
                  <span className="text-xs font-medium text-foreground/80">Approx. deductions</span>
                  <p className="mt-0.5 text-base font-bold tabular-nums text-[#0A0A0A] dark:text-slate-50">
                    {loanEstimatePreview.deductionCount != null ? loanEstimatePreview.deductionCount : '—'}
                  </p>
                  <span className="text-[11px]">
                    {loanEstimatePreview.deductionCount != null
                      ? `(${formatDeductionScheduleTypeShort(loanEstimatePreview.sched)})`
                      : ''}
                  </span>
                </div>
              </div>
              {payCycleSummaryLine ? (
                <p className="mt-3 border-t border-border/60 pt-3 text-xs leading-relaxed text-muted-foreground">
                  {mode === 'employee' ? 'Pay cycle (your account): ' : 'Pay cycle preview (timing reference): '}
                  {ctx.pay_cycle_preview?.name ? `${ctx.pay_cycle_preview.name} · ` : ''}
                  {payCycleSummaryLine}
                </p>
              ) : null}
              <p className="mt-2 text-xs leading-relaxed text-muted-foreground">
                {nextDeductionDates.length > 1
                  ? `Next deduction dates: ${nextDeductionDates
                      .slice(0, 2)
                      .map((d) => new Date(d).toLocaleDateString('en-US', { month: 'short', day: 'numeric', year: 'numeric' }))
                      .join(' and ')}`
                  : `Next deduction date: ${
                      nextDeductionDates[0]
                        ? new Date(nextDeductionDates[0]).toLocaleDateString('en-US', { month: 'short', day: 'numeric', year: 'numeric' })
                        : '—'
                    }`}
              </p>
            </div>

            <div className="space-y-2">
              <Label htmlFor="rlm-reason">Reason (optional)</Label>
              <Textarea
                id="rlm-reason"
                rows={3}
                className="rounded-xl"
                value={form.reason}
                onChange={(e) => setForm((f) => ({ ...f, reason: e.target.value }))}
              />
            </div>
          </div>
          <DialogFooter className="gap-2 sm:gap-3">
            <Button
              type="button"
              variant="ghost"
              className="rounded-full text-[#0A0A0A] dark:text-slate-100"
              onClick={() => handleDialogOpenChange(false)}
            >
              Cancel
            </Button>
            {mode === 'employee' ? (
              <Button
                type="submit"
                disabled={submitting}
                className="rounded-full border border-[#0A0A0A]/15 bg-[#0A0A0A] px-8 text-white shadow-sm hover:bg-[#0A0A0A]/90 dark:bg-slate-100 dark:text-[#0A0A0A] dark:hover:bg-white"
              >
                {submitting ? (
                  <Loader2 className="size-4 animate-spin text-white dark:text-[#0A0A0A]" aria-hidden />
                ) : (
                  'Submit request'
                )}
              </Button>
            ) : (
              <Button
                type="button"
                disabled={productOptions.length === 0}
                className="rounded-full border border-[#0A0A0A]/15 bg-[#0A0A0A] px-8 text-white shadow-sm hover:bg-[#0A0A0A]/90 dark:bg-slate-100 dark:text-[#0A0A0A] dark:hover:bg-white"
                onClick={handleAdminContinue}
              >
                {adminContinueLabel}
              </Button>
            )}
          </DialogFooter>
        </form>
      </DialogContent>
    </Dialog>
  )
}
