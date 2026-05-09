import { useCallback, useEffect, useMemo, useRef, useState } from 'react'
import {
  ArrowRight,
  CalendarDays,
  Check,
  Clock3,
  HandCoins,
  Info,
  Loader2,
  PiggyBank,
  PieChart,
  Shield,
  UserRound,
  Utensils,
} from 'lucide-react'
import { AgcBrandLogo } from '@/components/AgcBrandLogo'
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
import { formatDeductionScheduleTypeShort } from '@/components/salary/salaryTabFormatters'
import {
  computeLoanEstimatePreview,
  EMPTY_REQUEST_LOAN_FORM,
  PRINCIPAL_PRESETS,
  resolveInterestProfileFromProductKey,
  resolveManualAssignIdsFromProductKey,
} from '@/lib/loanRequestEstimate'
import { cn } from '@/lib/utils'

const REASON_MAX = 250

function formatPhp(n) {
  const v = Number(n)
  if (!Number.isFinite(v)) return '—'
  return v.toLocaleString('en-PH', { minimumFractionDigits: 2, maximumFractionDigits: 2 })
}

function formatPhpSym(n) {
  const s = formatPhp(n)
  return s === '—' ? s : `₱${s}`
}

function formatDateLong(value) {
  if (!value) return null
  const d = new Date(value)
  if (Number.isNaN(d.getTime())) return String(value)
  return d.toLocaleDateString('en-US', { month: 'long', day: 'numeric', year: 'numeric' })
}

function formatDeductionDates(dates) {
  if (!Array.isArray(dates) || dates.length === 0) return '—'
  return dates
    .slice(0, 2)
    .map((d) => new Date(d).toLocaleDateString('en-US', { month: 'short', day: 'numeric', year: 'numeric' }))
    .join(' and ')
}

function productCardIcon(label) {
  const l = String(label || '').toLowerCase()
  if (/(food|meal|pares|canteen|lunch)/.test(l)) return Utensils
  return HandCoins
}

const SCHEDULE_CARD_OPTIONS = [
  { value: '15th', label: 'First semi-monthly run', sub: 'Full deduction on first payroll run', Icon: CalendarDays },
  { value: '30th', label: 'End of month', sub: 'Full deduction on second payroll run', Icon: CalendarDays },
  { value: 'both', label: '50/50 split', sub: 'Split evenly across two payroll runs', Icon: PieChart },
]

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
      title: pc.name,
      sub: 'Pay component',
    }))
    const dts = (ctx.loan_types || []).map((t) => ({
      key: `dt:${t.id}`,
      title: t.name,
      sub: 'Deduction type',
    }))
    return [...pcs, ...dts]
  }, [ctx.loan_pay_components, ctx.loan_types])

  const payCycleSummaryLine = useMemo(() => {
    const p = ctx.pay_cycle_preview
    if (!p) return null
    return p.preview_line || p.cycle_label || p.name || null
  }, [ctx.pay_cycle_preview])

  const payCyclePayDate = useMemo(() => ctx.pay_cycle_preview?.pay_date ?? null, [ctx.pay_cycle_preview])

  const interestProfile = useMemo(
    () => resolveInterestProfileFromProductKey(form.product_key, ctx),
    [form.product_key, ctx],
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

  const parsedPrincipal = Number(form.requested_amount)
  const principalEffective =
    form.requested_amount !== '' && form.requested_amount != null && Number.isFinite(parsedPrincipal) && parsedPrincipal > 0
      ? parsedPrincipal
      : principalSlider

  return (
    <Dialog open={open} onOpenChange={handleDialogOpenChange}>
      <DialogContent
        className="max-h-[min(94vh,960px)] w-full max-w-6xl sm:max-w-6xl"
        innerClassName="gap-0 overflow-y-auto bg-card px-0 pb-0 pt-0"
        closeButtonClassName="right-4 top-4 size-10 rounded-xl bg-background shadow-sm"
      >
        <form onSubmit={handleSubmit} className="flex min-h-0 flex-1 flex-col">
          <DialogHeader className="border-b border-border/60 px-6 py-5 text-left sm:px-8">
            <div className="flex flex-col gap-4 pr-14 sm:flex-row sm:items-start sm:gap-6">
              <AgcBrandLogo className="h-9 w-28 shrink-0 object-contain sm:h-10 sm:w-32" />
              <div className="min-w-0 flex-1 space-y-1.5">
                <DialogTitle className="text-xl font-bold tracking-tight text-foreground sm:text-2xl">{title}</DialogTitle>
                <DialogDescription className="max-w-full text-base leading-relaxed text-muted-foreground sm:max-w-2xl">
                  {description}
                </DialogDescription>
              </div>
            </div>
          </DialogHeader>

          <div className="grid flex-1 gap-8 px-6 py-6 sm:px-8 lg:grid-cols-2 lg:gap-10 lg:py-8">
            <div className="flex flex-col gap-10">
              <section className="space-y-5">
                <h3 className="text-sm font-bold tracking-wide text-foreground">Loan details</h3>

                {mode === 'employee' ? (
                  <div className="space-y-2">
                    <Label htmlFor="rlm-borrower" className="text-foreground">
                      Loan for
                    </Label>
                    <div
                      id="rlm-borrower"
                      className="relative flex min-h-12 items-center rounded-xl border border-border/70 bg-muted/35 px-3 py-2.5 ps-11 text-sm text-foreground dark:bg-muted/20"
                      aria-readonly="true"
                    >
                      <span className="absolute left-3 flex size-7 items-center justify-center rounded-lg bg-muted/80 text-foreground dark:bg-muted/50">
                        <UserRound className="size-4" strokeWidth={1.85} aria-hidden />
                      </span>
                      {borrowerLabel || '—'}
                    </div>
                    {borrowerHelperText ? <p className="text-xs text-muted-foreground">{borrowerHelperText}</p> : null}
                  </div>
                ) : (
                  <div className="rounded-xl border border-dashed border-border/70 bg-muted/25 px-4 py-3 text-sm leading-relaxed text-muted-foreground">
                    Plan amounts here, then use <span className="font-medium text-foreground">Continue to manual assign</span> to pick the
                    employee on the Manual assign tab. This does not create an employee self-service request.
                  </div>
                )}

                <div className="space-y-3">
                  <Label className="text-foreground">Loan product</Label>
                  {productOptions.length === 0 ? (
                    <p className="rounded-xl border border-dashed border-border bg-muted/30 px-4 py-3 text-sm text-muted-foreground">
                      No loan products available. Ask HR to configure loan pay components or deduction types.
                    </p>
                  ) : (
                    <div className="grid gap-3 sm:grid-cols-2">
                      {productOptions.map((o) => {
                        const selected = form.product_key === o.key
                        const ProdIcon = productCardIcon(o.title)
                        return (
                          <button
                            key={o.key}
                            type="button"
                            onClick={() => setForm((f) => ({ ...f, product_key: o.key }))}
                            className={cn(
                              'relative rounded-xl border-2 bg-card p-4 text-left transition-all duration-200',
                              selected
                                ? 'border-brand bg-brand/6 shadow-sm dark:bg-brand/12'
                                : 'border-border/70 hover:border-border hover:bg-muted/30',
                            )}
                          >
                            {selected ? (
                              <span className="absolute right-3 top-3 flex size-6 items-center justify-center rounded-full bg-brand text-brand-foreground shadow-sm">
                                <Check className="size-3.5" strokeWidth={2.75} aria-hidden />
                              </span>
                            ) : null}
                            <span className="flex gap-4">
                              <span className="flex size-12 shrink-0 items-center justify-center rounded-xl bg-muted/60 text-brand dark:bg-muted/40">
                                <ProdIcon className="size-6" strokeWidth={1.7} aria-hidden />
                              </span>
                              <span className="min-w-0 pt-0.5">
                                <span className="block font-semibold leading-snug text-foreground">{o.title}</span>
                                <span className="mt-1 block text-xs text-muted-foreground">{o.sub}</span>
                              </span>
                            </span>
                          </button>
                        )
                      })}
                    </div>
                  )}
                </div>

                <div className="space-y-4 rounded-xl border border-border/70 bg-card p-4 shadow-sm sm:p-5">
                  <div className="flex items-start justify-between gap-3">
                    <Label htmlFor="rlm-principal-slider" className="font-semibold text-foreground">
                      Principal (₱)
                    </Label>
                    <span className="text-lg font-bold tabular-nums text-foreground sm:text-xl">{formatPhpSym(principalEffective)}</span>
                  </div>
                  <input
                    id="rlm-principal-slider"
                    type="range"
                    min={5000}
                    max={2000000}
                    step={1000}
                    value={principalEffective}
                    onChange={(e) => {
                      const v = Number(e.target.value)
                      setPrincipalSlider(v)
                      setForm((f) => ({ ...f, requested_amount: String(v) }))
                    }}
                    className="h-2 w-full cursor-pointer accent-brand dark:accent-brand"
                  />
                  <div className="flex flex-wrap gap-2">
                    {PRINCIPAL_PRESETS.map((p) => {
                      const isSelected = principalEffective === p
                      return (
                        <button
                          key={p}
                          type="button"
                          onClick={() => {
                            setPrincipalSlider(p)
                            setForm((f) => ({ ...f, requested_amount: String(p) }))
                          }}
                          className={cn(
                            'rounded-full border px-3 py-1.5 text-xs font-semibold transition-colors',
                            isSelected
                              ? 'border-brand bg-brand text-brand-foreground hover:bg-brand-strong'
                              : 'border-border/80 bg-background text-foreground hover:bg-muted dark:bg-card',
                          )}
                        >
                          ₱{(p / 1000).toLocaleString()}k
                        </button>
                      )
                    })}
                  </div>
                  <div className="relative">
                    <span className="pointer-events-none absolute left-3 top-1/2 -translate-y-1/2 text-sm text-muted-foreground">₱</span>
                    <Input
                      id="rlm-principal"
                      type="number"
                      min="1"
                      step="0.01"
                      required
                      className="h-11 rounded-xl border-border/70 pl-8 text-foreground"
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
                    <Label htmlFor="rlm-pref" className="text-foreground">
                      Preferred monthly (₱)
                    </Label>
                    <div className="relative">
                      <span className="pointer-events-none absolute left-3 top-1/2 -translate-y-1/2 text-sm text-muted-foreground">₱</span>
                      <Input
                        id="rlm-pref"
                        type="number"
                        min="0.01"
                        step="0.01"
                        className={cn('h-11 rounded-xl border-border/70 pl-8', validation.preferredTooHigh && 'border-destructive')}
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
                    <Label htmlFor="rlm-term" className="text-foreground">
                      Term (months)
                    </Label>
                    <Input
                      id="rlm-term"
                      type="number"
                      min="1"
                      max="600"
                      className={cn('h-11 rounded-xl border-border/70', validation.termInvalid && 'border-destructive')}
                      value={form.term_months}
                      onChange={(e) => setForm((f) => ({ ...f, term_months: e.target.value }))}
                      placeholder="Optional"
                    />
                    {validation.termInvalid ? <p className="text-xs text-destructive">Use 1–600 months.</p> : null}
                  </div>
                </div>
              </section>

              <section className="space-y-4">
                <div>
                  <h3 className="text-sm font-bold tracking-wide text-foreground">Deduction schedule</h3>
                  <div className="mt-2 flex flex-wrap items-center gap-2 text-xs text-muted-foreground">
                    <p className="min-w-56 flex-1 leading-relaxed">
                      This schedules payroll deductions (aligned with your pay cycle and HR deduction settings for this product).
                    </p>
                    <button
                      type="button"
                      className="inline-flex shrink-0 items-center gap-1 font-medium text-brand hover:underline"
                      onClick={() =>
                        toast({
                          title: 'Deduction schedules',
                          description:
                            'First run is typically aligned with mid-month payroll; end of month aligns with the second pay run; 50/50 splits the installment across both.',
                        })
                      }
                    >
                      <Info className="size-3.5" aria-hidden />
                      Learn more
                    </button>
                  </div>
                </div>
                <div className="grid gap-3 sm:grid-cols-3">
                  {SCHEDULE_CARD_OPTIONS.map((opt) => {
                    const selected = form.deduction_schedule === opt.value
                    const SchIcon = opt.Icon
                    return (
                      <button
                        key={opt.value}
                        type="button"
                        onClick={() => setForm((f) => ({ ...f, deduction_schedule: opt.value }))}
                        className={cn(
                          'relative rounded-xl border-2 bg-card p-3 text-left transition-all duration-200 sm:p-4',
                          selected ? 'border-brand bg-brand/6 shadow-sm dark:bg-brand/12' : 'border-border/70 hover:border-border hover:bg-muted/30',
                        )}
                      >
                        {selected ? (
                          <span className="absolute right-2 top-2 flex size-5 items-center justify-center rounded-full bg-brand text-brand-foreground sm:right-3 sm:top-3">
                            <Check className="size-3" strokeWidth={2.75} aria-hidden />
                          </span>
                        ) : null}
                        <SchIcon className="mb-2 size-5 text-brand sm:size-[1.35rem]" strokeWidth={1.75} aria-hidden />
                        <p className="text-sm font-semibold leading-snug text-foreground">{opt.label}</p>
                        <p className="mt-1 text-[11px] leading-relaxed text-muted-foreground">{opt.sub}</p>
                      </button>
                    )
                  })}
                </div>
              </section>
            </div>

            <div className="flex flex-col gap-6 lg:min-h-0">
              <div className="space-y-4 rounded-xl border border-border/70 bg-white p-5 shadow-sm dark:border-border dark:bg-card sm:p-6">
                <p className="text-[11px] font-semibold uppercase tracking-[0.12em] text-muted-foreground">Estimated impact</p>
                <p className="text-2xl font-bold tabular-nums tracking-tight text-foreground sm:text-3xl">
                  {loanEstimatePreview.monthlyImpact != null && Number.isFinite(loanEstimatePreview.monthlyImpact)
                    ? `${formatPhpSym(loanEstimatePreview.monthlyImpact)} / month (total)`
                    : '—'}
                </p>
                {loanEstimatePreview.isAdjustedToTerm ? (
                  <p className="text-sm text-muted-foreground">
                    {loanEstimatePreview.adjustedReason === 'increased_to_match_term'
                      ? 'Adjusted up to fully repay principal within the selected term.'
                      : 'Adjusted down to match principal within the selected term.'}
                  </p>
                ) : null}
                {loanEstimatePreview.sched === 'both' &&
                loanEstimatePreview.per15 != null &&
                loanEstimatePreview.per30 != null ? (
                  <p className="text-sm tabular-nums text-foreground/90">
                    Per pay run (approx.): 15th — {formatPhpSym(loanEstimatePreview.per15)} · 30th —{' '}
                    {formatPhpSym(loanEstimatePreview.per30)}
                  </p>
                ) : loanEstimatePreview.monthlyImpact != null && Number.isFinite(loanEstimatePreview.monthlyImpact) ? (
                  <p className="text-sm leading-relaxed text-foreground/90">
                    {loanEstimatePreview.sched === '15th'
                      ? `Full amount on the first semi-monthly pay run (~15th): ${formatPhpSym(loanEstimatePreview.monthlyImpact)}`
                      : `Full amount on the second semi-monthly run (end of month): ${formatPhpSym(loanEstimatePreview.monthlyImpact)}`}
                  </p>
                ) : null}
                {loanEstimatePreview.hasInterestRate ? (
                  <p className="text-sm font-medium text-foreground">
                    Interest rate: {loanEstimatePreview.rateDisplayPercent}% p.a. (
                    {loanEstimatePreview.interestType === 'compound' ? 'compound' : 'simple'})
                  </p>
                ) : null}
                <div className="border-t border-border/60 pt-4">
                  <p className="text-sm font-medium text-foreground/90">
                    {loanEstimatePreview.hasInterestRate && loanEstimatePreview.totalInterest != null
                      ? loanEstimatePreview.interestShortfall
                        ? 'Estimated payments (partial window)'
                        : 'Total repayment (principal + interest)'
                      : 'Total repayment (principal)'}
                  </p>
                  <p className="mt-1 text-lg font-bold tabular-nums text-foreground">
                    {loanEstimatePreview.totalRepayment != null ? formatPhpSym(loanEstimatePreview.totalRepayment) : '—'}
                  </p>
                  {loanEstimatePreview.interestRepaymentNote ? (
                    <p className="mt-1 text-xs leading-relaxed text-muted-foreground">({loanEstimatePreview.interestRepaymentNote})</p>
                  ) : null}
                  {loanEstimatePreview.interestShortfall ? (
                    <p className="mt-2 text-xs leading-relaxed text-amber-800 dark:text-amber-100/95">
                      At this installment the loan may not fully repay within the estimated window (remaining principal ≈{' '}
                      {formatPhpSym(loanEstimatePreview.principalRemainingAfterWindow)}). HR may adjust amounts on approval.
                    </p>
                  ) : null}
                </div>
                <div className="flex gap-4 rounded-xl border border-brand/20 bg-brand/8 px-4 py-3.5 dark:border-brand/35 dark:bg-brand/15">
                  <span className="flex size-12 shrink-0 items-center justify-center rounded-xl bg-card text-brand shadow-sm dark:bg-background/40">
                    <PiggyBank className="size-6" strokeWidth={1.6} aria-hidden />
                  </span>
                  <div className="min-w-0">
                    <span className="text-xs font-medium uppercase tracking-wide text-muted-foreground">Approx. deductions</span>
                    <p className="mt-0.5 text-2xl font-bold tabular-nums text-foreground">
                      {loanEstimatePreview.deductionCount != null ? loanEstimatePreview.deductionCount : '—'}
                    </p>
                    <span className="text-xs text-muted-foreground">
                      {loanEstimatePreview.deductionCount != null
                        ? `(${formatDeductionScheduleTypeShort(loanEstimatePreview.sched)})`
                        : ''}
                    </span>
                  </div>
                </div>
                {payCycleSummaryLine ? (
                  <div className="border-t border-border/60 pt-4">
                    <p className="flex flex-wrap items-start gap-2 text-sm text-muted-foreground">
                      <CalendarDays className="mt-0.5 size-4 shrink-0 text-foreground" aria-hidden />
                      <span>
                        <span className="font-medium text-foreground">
                          {mode === 'employee' ? 'Pay cycle (your account):' : 'Pay cycle preview:'}
                        </span>{' '}
                        {ctx.pay_cycle_preview?.name ? `${ctx.pay_cycle_preview.name} · ` : ''}
                        {payCycleSummaryLine}
                        {payCyclePayDate ? (
                          <>
                            {' · '}
                            <span className="font-medium text-brand">Pay Date: {formatDateLong(payCyclePayDate)}</span>
                          </>
                        ) : null}
                      </span>
                    </p>
                  </div>
                ) : null}
                <div className="flex flex-wrap items-start gap-2 border-t border-border/60 pt-3 text-xs text-muted-foreground">
                  <Clock3 className="mt-0.5 size-4 shrink-0 text-foreground" aria-hidden />
                  <p>
                    <span className="font-medium text-foreground">
                      {nextDeductionDates.length > 1 ? 'Next deduction dates: ' : 'Next deduction date: '}
                    </span>
                    <span className="font-medium text-brand">{formatDeductionDates(nextDeductionDates)}</span>
                  </p>
                </div>
              </div>

              <div className="space-y-2">
                <Label htmlFor="rlm-reason" className="text-foreground">
                  Reason <span className="font-normal text-muted-foreground">(optional)</span>
                </Label>
                <div className="relative">
                  <Textarea
                    id="rlm-reason"
                    rows={4}
                    maxLength={REASON_MAX}
                    placeholder="Share the reason for your loan request"
                    className="rounded-xl border-border/70 pb-8 text-foreground"
                    value={form.reason}
                    onChange={(e) => setForm((f) => ({ ...f, reason: e.target.value.slice(0, REASON_MAX) }))}
                  />
                  <span className="pointer-events-none absolute bottom-3 right-3 text-xs tabular-nums text-muted-foreground">
                    {(form.reason || '').length} / {REASON_MAX}
                  </span>
                </div>
              </div>

              <div className="flex gap-3 rounded-xl border border-border/60 bg-muted/30 p-4 text-sm leading-relaxed text-muted-foreground dark:bg-muted/20">
                <Shield className="mt-0.5 size-5 shrink-0 text-foreground" strokeWidth={1.6} aria-hidden />
                <p>
                  <span className="font-semibold text-foreground">Important.</span>{' '}
                  Submitted loan requests require HR approval. Payroll timing and withholding follow your employer&apos;s pay cycle,
                  deductions policy, and configuration. Estimates here are illustrative — final installments may differ after review.
                </p>
              </div>
            </div>
          </div>

          <DialogFooter className="justify-end gap-3 border-t border-border/60 px-6 py-4 sm:flex-row sm:px-8 sm:py-5">
            <Button
              type="button"
              variant="outline"
              className="rounded-full border-border/70 px-6 text-foreground"
              onClick={() => handleDialogOpenChange(false)}
            >
              Cancel
            </Button>
            {mode === 'employee' ? (
              <Button
                type="submit"
                disabled={submitting}
                className="gap-2 rounded-full bg-brand px-8 text-brand-foreground shadow-sm hover:bg-brand-strong disabled:opacity-60"
              >
                {submitting ? (
                  <Loader2 className="size-4 animate-spin" aria-hidden />
                ) : (
                  <>
                    Submit request <ArrowRight className="size-4" strokeWidth={2.25} aria-hidden />
                  </>
                )}
              </Button>
            ) : (
              <Button
                type="button"
                disabled={productOptions.length === 0}
                className="gap-2 rounded-full bg-brand px-8 text-brand-foreground hover:bg-brand-strong"
                onClick={handleAdminContinue}
              >
                {adminContinueLabel} <ArrowRight className="size-4" strokeWidth={2.25} aria-hidden />
              </Button>
            )}
          </DialogFooter>
        </form>
      </DialogContent>
    </Dialog>
  )
}
