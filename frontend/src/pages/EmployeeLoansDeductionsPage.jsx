import { useCallback, useEffect, useMemo, useState } from 'react'
import { motion } from 'framer-motion'
import {
  CheckCircle2,
  ChevronRight,
  Clock,
  FileText,
  HandCoins,
  PiggyBank,
  Plus,
  TrendingDown,
  Wallet,
  XCircle,
} from 'lucide-react'
import { Badge } from '@/components/ui/badge'
import { Select, SelectContent, SelectItem, SelectTrigger, SelectValue } from '@/components/ui/select'
import { Button } from '@/components/ui/button'
import { Card, CardContent, CardDescription, CardHeader, CardTitle } from '@/components/ui/card'
import { Dialog, DialogContent, DialogDescription, DialogFooter, DialogHeader, DialogTitle } from '@/components/ui/dialog'
import { RequestLoanModal } from '@/components/loans/RequestLoanModal.jsx'
import { LoanRequestDetailsModal } from '@/components/loans/LoanRequestDetailsModal.jsx'
import { Progress } from '@/components/ui/progress'
import { Skeleton } from '@/components/ui/skeleton'
import { Table, TableBody, TableCell, TableHead, TableHeader, TableRow } from '@/components/ui/table'
import { useToast } from '@/components/ui/use-toast'
import { getEmployeeLoanRequestContext, getEmployeeLoanRequestDetail, getEmployeeMyDeductions, getEmployeeNextDeductionDates } from '@/api'
import { formatDeductionScheduleTypeShort } from '@/components/salary/salaryTabFormatters'
import { useAuth } from '@/contexts/AuthContext'
import { useHrBasePath } from '@/contexts/HrAppPathContext'
import { cn } from '@/lib/utils'

const MotionDiv = motion.div
const MotionLi = motion.li

const HERO_SNAPSHOT_KEY = 'hr-employee-loans-hero-snapshot-v1'

const cardShadow =
  'shadow-[0_10px_40px_-12px_rgba(15,23,42,0.1),0_4px_16px_-4px_rgba(15,23,42,0.05)] dark:shadow-[0_10px_40px_-12px_rgba(0,0,0,0.45)]'
const cardShadowHover =
  'hover:-translate-y-0.5 hover:shadow-[0_16px_40px_-12px_rgba(15,23,42,0.12),0_8px_24px_-8px_rgba(15,23,42,0.08)]'

/** Active deductions table — borderless rows, hover-only separation */
const TABLE_ROW_HOVER =
  'border-0 transition-colors hover:bg-muted/45 dark:hover:bg-white/[0.06]'

function formatPhp(n) {
  const v = Number(n)
  if (!Number.isFinite(v)) return '—'
  return v.toLocaleString('en-PH', { minimumFractionDigits: 2, maximumFractionDigits: 2 })
}

function formatPhpSym(n) {
  const s = formatPhp(n)
  return s === '—' ? s : `₱${s}`
}

function formatLongDate(value) {
  const raw = String(value || '').trim()
  if (!raw) return '—'
  const d = new Date(raw)
  if (Number.isNaN(d.getTime())) return raw
  return d.toLocaleDateString('en-PH', { year: 'numeric', month: 'long', day: 'numeric' })
}

function formatDisplayDate(value) {
  const raw = String(value || '').trim()
  if (!raw) return '—'
  const d = new Date(raw)
  if (Number.isNaN(d.getTime())) return raw
  return d.toLocaleDateString('en-PH', { year: 'numeric', month: 'short', day: 'numeric' })
}

function daysFromToday(value) {
  const raw = String(value || '').trim()
  if (!raw) return null
  const d = new Date(raw)
  if (Number.isNaN(d.getTime())) return null
  const today = new Date()
  today.setHours(0, 0, 0, 0)
  const target = new Date(d)
  target.setHours(0, 0, 0, 0)
  return Math.round((target - today) / 86400000)
}

function relativeTimeFromDate(value) {
  const raw = String(value || '').trim()
  if (!raw) return null
  const d = new Date(raw)
  if (Number.isNaN(d.getTime())) return null
  const today = new Date()
  today.setHours(0, 0, 0, 0)
  const target = new Date(d)
  target.setHours(0, 0, 0, 0)
  const diffDays = Math.round((target - today) / 86400000)
  if (diffDays === 0) return 'Due today'
  if (diffDays === 1) return 'Due tomorrow'
  if (diffDays === -1) return '1 day overdue'
  if (diffDays > 1) return `Due in ${diffDays} days`
  if (diffDays < -1) return `${Math.abs(diffDays)} days overdue`
  return null
}

function rowIsLoan(row) {
  const dt = row.deduction_type
  return String(dt?.type || '').toLowerCase() === 'loan' || row.pay_component?.is_loan
}

/** Type column: Amortized | Recurring | Loan request */
function rowTypeLabel(row) {
  if (row.is_amortized) return 'Amortized'
  const src = String(row.source || '').toLowerCase()
  if (src.includes('request')) return 'Loan request'
  if (rowIsLoan(row)) return 'Recurring'
  return 'Recurring'
}

function nextDueUrgencyBadgeClass(dueDiff) {
  if (dueDiff == null) return 'border-border bg-muted/50 text-foreground'
  if (dueDiff < 0) return 'border-red-200 bg-red-50 text-red-800 dark:border-red-800/60 dark:bg-red-950/45 dark:text-red-200'
  return 'border-border bg-muted/40 text-foreground dark:border-white/10 dark:bg-white/[0.05]'
}

function loanStageLabel(stage, status) {
  const s = String(status || '').toLowerCase()
  if (s === 'approved') return 'Approved'
  if (s === 'rejected') return 'Rejected'
  if (s === 'pending') {
    if (stage === 'pending_second') return 'Awaiting HR'
    if (stage === 'pending_first') return 'Awaiting HR'
    return 'Pending'
  }
  return stage || '—'
}

/** Terminal decision from DB `status` only — do not infer from `approval_stage` (e.g. pending_second still contains "pending" after reject). */
function loanRequestDecision(status) {
  const s = String(status || '').toLowerCase().trim()
  if (s === 'approved') return 'approved'
  if (s === 'rejected') return 'rejected'
  if (s === 'pending') return 'pending'
  return 'other'
}


/** Donut metric — AGCTEK hero cards; optional center icon (reference v2) or % + caption */
function MetricRing({
  pct,
  size = 120,
  label,
  subLabel = 'paid',
  strokeClassName = 'text-brand',
  trackClassName = 'text-muted/35 dark:text-muted/25',
  centerIcon = null,
}) {
  const p = pct == null || Number.isNaN(pct) ? 0 : Math.min(100, Math.max(0, Number(pct)))
  const stroke = 7
  const r = (size - stroke) / 2
  const c = 2 * Math.PI * r
  const offset = c - (p / 100) * c

  return (
    <div className="relative flex shrink-0 items-center justify-center" style={{ width: size, height: size }}>
      <svg width={size} height={size} className="-rotate-90" viewBox={`0 0 ${size} ${size}`} aria-hidden>
        <circle
          cx={size / 2}
          cy={size / 2}
          r={r}
          fill="none"
          stroke="currentColor"
          strokeWidth={stroke}
          className={trackClassName}
        />
        <circle
          cx={size / 2}
          cy={size / 2}
          r={r}
          fill="none"
          stroke="currentColor"
          strokeWidth={stroke}
          strokeDasharray={c}
          strokeDashoffset={offset}
          strokeLinecap="round"
          className={cn('transition-[stroke-dashoffset] duration-1000 ease-out', strokeClassName)}
        />
      </svg>
      <div className="pointer-events-none absolute inset-0 flex flex-col items-center justify-center px-1 text-center">
        {centerIcon ? (
          <span className="flex items-center justify-center text-foreground [&_svg]:text-foreground">{centerIcon}</span>
        ) : (
          <>
            <span className="text-xl font-bold tabular-nums leading-none text-foreground">{label}</span>
            <span className="mt-0.5 text-[10px] font-medium uppercase tracking-wide text-muted-foreground">{subLabel}</span>
          </>
        )}
      </div>
    </div>
  )
}

/** Staggered progress fill — brand accent */
function AnimatedLoanProgress({
  value,
  isLarge,
  delayMs = 0,
  indicatorClassName = 'bg-brand',
}) {
  const pct = Math.min(100, Math.max(0, Number(value) || 0))
  const [shown, setShown] = useState(0)
  useEffect(() => {
    const t = window.setTimeout(() => setShown(pct), delayMs)
    return () => window.clearTimeout(t)
  }, [pct, delayMs])
  return (
    <Progress
      value={shown}
      className={cn('h-1.5 overflow-hidden rounded-full bg-muted/80', isLarge ? 'h-2' : '')}
      indicatorClassName={indicatorClassName}
    />
  )
}

/** Friendly empty-state mascot (piggy + coin — no external assets) */
function PiggyBankIllustration() {
  return (
    <div className="relative flex size-28 items-center justify-center" aria-hidden>
      <div className="absolute inset-0 rounded-[2rem] bg-orange-50/95 shadow-inner ring-1 ring-orange-200/70 dark:bg-brand/12 dark:ring-brand/25" />
      <PiggyBank className="relative size-[4.5rem] text-orange-600 dark:text-brand" strokeWidth={1.35} />
      <span className="absolute -right-1 -top-1 flex size-9 items-center justify-center rounded-full border border-white bg-white text-sm text-orange-700 shadow-md ring-2 ring-orange-100/90 dark:border-card dark:bg-card dark:text-brand dark:ring-brand/30">
        ✦
      </span>
    </div>
  )
}

export default function EmployeeLoansDeductionsPage() {
  const { user } = useAuth()
  const hrBase = useHrBasePath()
  const { toast } = useToast()
  const [loading, setLoading] = useState(true)
  const [ctx, setCtx] = useState({
    loan_types: [],
    loan_pay_components: [],
    pay_cycle_preview: null,
  })
  const [data, setData] = useState({ employee_deductions: [], loan_requests: [] })
  const [dialogOpen, setDialogOpen] = useState(false)
  const [scheduleOpen, setScheduleOpen] = useState(false)
  const [scheduleRows, setScheduleRows] = useState([])
  const [scheduleTitle, setScheduleTitle] = useState('')
  const [auditOpen, setAuditOpen] = useState(false)
  const [auditRows, setAuditRows] = useState([])
  const [auditTitle, setAuditTitle] = useState('')
  const [requestDetailsOpen, setRequestDetailsOpen] = useState(false)
  const [selectedRequest, setSelectedRequest] = useState(null)
  const [requestDetail, setRequestDetail] = useState(null)
  const [nextDeductionDates, setNextDeductionDates] = useState([])
  const [requestDetailsLoading, setRequestDetailsLoading] = useState(false)
  const [progressBarsReady, setProgressBarsReady] = useState(false)
  const [debtSinceVisit, setDebtSinceVisit] = useState(null)
  const [monthlySinceVisit, setMonthlySinceVisit] = useState(null)
  const [activityFilter, setActivityFilter] = useState('all')

  const filteredLoanRequests = useMemo(() => {
    const list = Array.isArray(data.loan_requests) ? data.loan_requests : []
    if (activityFilter === 'all') return list
    return list.filter((r) => loanRequestDecision(r.status) === activityFilter)
  }, [data.loan_requests, activityFilter])

  const load = useCallback(async (opts = {}) => {
    const silent = Boolean(opts.silent)
    if (!silent) {
      setLoading(true)
    }
    try {
      const [cRes, mRes] = await Promise.allSettled([getEmployeeLoanRequestContext(), getEmployeeMyDeductions()])
      if (cRes.status === 'fulfilled') {
        setCtx(cRes.value || {})
      } else {
        setCtx({ loan_types: [], loan_pay_components: [], pay_cycle_preview: null })
      }
      if (mRes.status === 'fulfilled') {
        setData(mRes.value || { employee_deductions: [], loan_requests: [] })
      } else {
        setData({ employee_deductions: [], loan_requests: [] })
      }
      if (cRes.status === 'rejected' || mRes.status === 'rejected') {
        throw new Error(
          cRes.status === 'rejected'
            ? (cRes.reason?.message || 'Failed to load loan request context.')
            : (mRes.reason?.message || 'Failed to load deductions and loan history.')
        )
      }
    } catch (e) {
      toast({ title: 'Could not load', description: e.message, variant: 'destructive' })
    } finally {
      if (!silent) {
        setLoading(false)
      }
    }
  }, [toast])

  useEffect(() => {
    load()
  }, [load])

  useEffect(() => {
    const onVisible = () => {
      if (document.visibilityState === 'visible') {
        load({ silent: true })
      }
    }
    const onFocus = () => load({ silent: true })
    const intervalId = window.setInterval(() => load({ silent: true }), 30000)
    document.addEventListener('visibilitychange', onVisible)
    window.addEventListener('focus', onFocus)
    return () => {
      document.removeEventListener('visibilitychange', onVisible)
      window.removeEventListener('focus', onFocus)
      window.clearInterval(intervalId)
    }
  }, [load])

  const handleRequestDetailsOpenChange = useCallback(
    (open) => {
      setRequestDetailsOpen(open)
      if (!open) {
        load({ silent: true })
      }
    },
    [load],
  )

  const openRequestDetails = useCallback(
    async (request) => {
      setSelectedRequest(request || null)
      setRequestDetail(null)
      setRequestDetailsOpen(true)
      setRequestDetailsLoading(true)
      try {
        const detail = await getEmployeeLoanRequestDetail(request?.id)
        setRequestDetail(detail)
        setSelectedRequest(detail?.loan_request || request)
        setNextDeductionDates(Array.isArray(detail?.next_deduction_dates) ? detail.next_deduction_dates : [])
      } catch {
        // Fallback: use inline request data and just fetch deduction dates
        try {
          const next = await getEmployeeNextDeductionDates({
            schedule_type: request?.deduction_schedule || 'both',
          })
          setNextDeductionDates(Array.isArray(next?.next_dates) ? next.next_dates : [])
        } catch {
          setNextDeductionDates([])
        }
      } finally {
        setRequestDetailsLoading(false)
      }
    },
    [],
  )

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

  const permSet = useMemo(() => new Set(user?.permissions ?? []), [user?.permissions])
  const maySubmitLoanRequest =
    !user?.is_system_user &&
    (permSet.has('request-loan') ||
      permSet.has('loans.request') ||
      permSet.has('loans.view_own') ||
      String(user?.role || '').toLowerCase() === 'admin')
  const hasLoanProducts = productOptions.length > 0
  const canOpenRequestDialog = maySubmitLoanRequest

  const selfLoanDisplay = useMemo(() => {
    const name = (user?.name || 'You').trim() || 'You'
    const raw = String(user?.employee_code || '').trim()
    const code = raw
      ? raw.toUpperCase().startsWith('EMP-')
        ? raw
        : `EMP-${raw}`
      : user?.id != null
        ? `EMP-${String(user.id).padStart(6, '0')}`
        : '—'
    return `Myself (${name} - ${code})`
  }, [user?.name, user?.employee_code, user?.id])

  const heroStats = useMemo(() => {
    const rows = data.employee_deductions || []
    let totalDebt = 0
    let monthly = 0
    let totalPrincipal = 0
    let principalKnown = false
    let monthlyLoans = 0
    let monthlyOther = 0

    for (const row of rows) {
      if (row.remaining_balance != null) totalDebt += Number(row.remaining_balance) || 0
      const amt = Number(row.amount) || 0
      monthly += amt
      const isLoanLike = row.is_amortized || String(row.deduction_type?.type || '').toLowerCase() === 'loan'
      if (isLoanLike) monthlyLoans += amt
      else monthlyOther += amt

      if (row.total_loan_amount != null && Number(row.total_loan_amount) > 0) {
        totalPrincipal += Number(row.total_loan_amount) || 0
        principalKnown = true
      }
    }

    let repaymentPct = null
    if (principalKnown && totalPrincipal > 0) {
      const paid = Math.max(0, totalPrincipal - totalDebt)
      repaymentPct = Math.min(100, Math.round((paid / totalPrincipal) * 100))
    }

    return {
      totalDebt,
      monthly,
      monthlyLoans,
      monthlyOther,
      totalPrincipal,
      repaymentPct,
      principalKnown,
    }
  }, [data.employee_deductions])

  const debtRingPct = useMemo(() => {
    if (heroStats.principalKnown && heroStats.repaymentPct != null) return heroStats.repaymentPct
    return 0
  }, [heroStats.principalKnown, heroStats.repaymentPct])

  const monthlyLoanSharePct = useMemo(() => {
    const m = heroStats.monthly
    if (!m || m <= 0) return 0
    return Math.min(100, Math.round((heroStats.monthlyLoans / m) * 100))
  }, [heroStats.monthly, heroStats.monthlyLoans])

  useEffect(() => {
    if (loading) return
    try {
      const raw = localStorage.getItem(HERO_SNAPSHOT_KEY)
      if (raw) {
        const prev = JSON.parse(raw)
        if (prev && typeof prev.totalDebt === 'number') {
          const deltaDebt = prev.totalDebt - heroStats.totalDebt
          const deltaMo = prev.monthly - heroStats.monthly
          setDebtSinceVisit(Number.isFinite(deltaDebt) ? deltaDebt : null)
          setMonthlySinceVisit(Number.isFinite(deltaMo) ? deltaMo : null)
        }
      }
    } catch {
      setDebtSinceVisit(null)
      setMonthlySinceVisit(null)
    }
    try {
      localStorage.setItem(
        HERO_SNAPSHOT_KEY,
        JSON.stringify({
          totalDebt: heroStats.totalDebt,
          monthly: heroStats.monthly,
          at: Date.now(),
        }),
      )
    } catch {
      /* ignore quota */
    }
  }, [loading, heroStats.totalDebt, heroStats.monthly])

  useEffect(() => {
    if (loading) return
    const id = requestAnimationFrame(() => setProgressBarsReady(true))
    return () => cancelAnimationFrame(id)
  }, [loading, data.employee_deductions])

  const workspaceLabel =
    hrBase === '/company' ? 'Company' : hrBase === '/branch' ? 'Branch' : hrBase === '/department' ? 'Department' : 'Employee'

  return (
    <div className="min-h-screen w-full bg-muted/35 bg-gradient-to-b from-muted/40 via-muted/25 to-background dark:from-background dark:via-background dark:to-background">
      <div className="w-full space-y-9 px-4 pb-24 pt-8 md:space-y-10 md:px-8 lg:px-10 md:pt-10">
        <header className="space-y-4">
          <p className="text-xs font-semibold uppercase tracking-[0.2em] text-brand">{workspaceLabel}</p>
          <div className="flex flex-col gap-6 md:flex-row md:items-end md:justify-between">
            <div className="max-w-2xl">
              <div className="mb-2 inline-flex items-center gap-2 rounded-full border border-brand/25 bg-brand/8 px-3 py-1 text-xs font-medium text-brand dark:bg-brand/12">
                Personal finance hub
              </div>
              <h1 className="text-3xl font-bold tracking-tight text-foreground md:text-4xl lg:text-[2.5rem] lg:leading-tight">
                My loans & deductions
              </h1>
              <p className="mt-3 max-w-xl text-base leading-relaxed text-muted-foreground md:text-[17px]">
                Track balances, schedules, and requests in one place. HR approves new loans; deductions follow your pay cycle.
              </p>
            </div>
            {maySubmitLoanRequest ? (
              <Button
                type="button"
                size="lg"
                disabled={!canOpenRequestDialog}
                onClick={() => setDialogOpen(true)}
                className="h-12 shrink-0 gap-2 rounded-2xl bg-brand px-8 text-[15px] font-semibold text-brand-foreground shadow-sm transition-colors hover:bg-brand-strong disabled:opacity-50"
              >
                <Plus className="size-5" strokeWidth={2.5} aria-hidden />
                Request new loan
              </Button>
            ) : (
              <p className="max-w-xs text-sm text-muted-foreground">You can view deductions here; loan requests are not enabled for your role.</p>
            )}
          </div>
        </header>

        {loading ? (
          <div className="space-y-6">
            <div className="grid gap-4 md:grid-cols-2">
              <Skeleton className="h-44 rounded-2xl shadow-md" />
              <Skeleton className="h-44 rounded-2xl shadow-md" />
            </div>
            <Skeleton className="h-72 rounded-2xl shadow-md" />
          </div>
        ) : (
          <>
            {/* Hero metrics — premium metric cards */}
            <section className="grid gap-5 md:grid-cols-2 lg:gap-7">
              <MotionDiv
                className="h-full"
                initial={{ opacity: 0, y: 16 }}
                animate={{ opacity: 1, y: 0 }}
                transition={{ duration: 0.45, ease: [0.22, 1, 0.36, 1] }}
              >
                <Card
                  className={cn(
                    'h-full overflow-hidden rounded-2xl border-0 bg-white dark:bg-card',
                    cardShadow,
                    cardShadowHover,
                  )}
                  style={{ borderRadius: 16 }}
                >
                  <CardContent className="flex h-full flex-col gap-6 p-6 sm:flex-row sm:items-center sm:justify-between md:p-8">
                    <div className="min-w-0 flex-1 space-y-2">
                      <p className="flex items-center gap-3 text-[11px] font-semibold uppercase tracking-[0.14em] text-brand">
                        <span className="flex size-9 shrink-0 items-center justify-center rounded-full bg-brand text-white shadow-sm">
                          <Wallet className="size-4" aria-hidden strokeWidth={2.25} />
                        </span>
                        Total remaining debt
                      </p>
                      <p className="text-4xl font-bold tabular-nums tracking-tight text-brand md:text-[2.85rem] md:leading-none">
                        {formatPhpSym(heroStats.totalDebt)}
                      </p>
                      <p className="text-sm text-foreground/90">Across your active loan deductions</p>
                      {debtSinceVisit != null && Math.abs(debtSinceVisit) > 0.01 ? (
                        <p className="inline-flex items-center gap-1.5 text-xs font-medium text-muted-foreground">
                          <TrendingDown className="size-3.5 shrink-0 text-brand" aria-hidden />
                          {debtSinceVisit > 0 ? (
                            <>↓ {formatPhpSym(debtSinceVisit)} less debt since your last visit</>
                          ) : (
                            <>↑ {formatPhpSym(Math.abs(debtSinceVisit))} more debt since your last visit</>
                          )}
                        </p>
                      ) : null}
                      {heroStats.principalKnown && heroStats.repaymentPct != null ? (
                        <p className="text-xs font-medium text-muted-foreground">
                          {heroStats.repaymentPct}% of principal repaid across your active loans
                        </p>
                      ) : (
                        <span className="inline-flex items-center gap-0.5 text-xs font-medium text-brand">
                          Add principal on file to unlock repayment progress
                          <ChevronRight className="size-3.5 shrink-0" strokeWidth={2.25} aria-hidden />
                        </span>
                      )}
                    </div>
                    <MetricRing
                      pct={debtRingPct}
                      strokeClassName="text-brand"
                      trackClassName="text-brand/25 dark:text-brand/20"
                      centerIcon={<HandCoins className="size-7" strokeWidth={1.75} aria-hidden />}
                    />
                  </CardContent>
                </Card>
              </MotionDiv>

              <MotionDiv
                className="h-full"
                initial={{ opacity: 0, y: 16 }}
                animate={{ opacity: 1, y: 0 }}
                transition={{ duration: 0.45, delay: 0.06, ease: [0.22, 1, 0.36, 1] }}
              >
                <Card
                  className={cn(
                    'h-full overflow-hidden rounded-2xl border-0 bg-white dark:bg-card',
                    cardShadow,
                    cardShadowHover,
                  )}
                  style={{ borderRadius: 16 }}
                >
                  <CardContent className="flex h-full flex-col gap-6 p-6 sm:flex-row sm:items-center sm:justify-between md:p-8">
                    <div className="min-w-0 flex-1 space-y-4">
                      <div>
                        <p className="flex items-center gap-3 text-[11px] font-semibold uppercase tracking-[0.14em] text-emerald-800 dark:text-emerald-200">
                          <span className="flex size-9 shrink-0 items-center justify-center rounded-full bg-emerald-600 text-white shadow-sm dark:bg-emerald-500">
                            <FileText className="size-4" aria-hidden strokeWidth={2.25} />
                          </span>
                          Total monthly deductions
                        </p>
                        <p className="mt-1 text-4xl font-bold tabular-nums tracking-tight text-emerald-800 dark:text-emerald-300 md:text-[2.85rem] md:leading-none">
                          {formatPhpSym(heroStats.monthly)}
                        </p>
                        <p className="mt-2 text-sm text-foreground/85">Combined recurring payroll amounts</p>
                        {monthlySinceVisit != null && Math.abs(monthlySinceVisit) > 0.01 ? (
                          <p className="mt-2 text-xs font-medium text-muted-foreground">
                            {monthlySinceVisit > 0 ? (
                              <>↓ {formatPhpSym(monthlySinceVisit)} lower per month vs last visit</>
                            ) : (
                              <>↑ {formatPhpSym(Math.abs(monthlySinceVisit))} higher per month vs last visit</>
                            )}
                          </p>
                        ) : null}
                      </div>
                      <div className="flex flex-wrap gap-2">
                        <span className="inline-flex items-center gap-1 rounded-full border border-border/80 bg-background/70 px-3 py-1.5 text-xs font-medium text-foreground shadow-none dark:bg-background/40">
                          <span className="text-muted-foreground">Loans</span>
                          <span className="text-muted-foreground">•</span>
                          <span className="tabular-nums font-semibold">{formatPhpSym(heroStats.monthlyLoans)}</span>
                        </span>
                        <span className="inline-flex items-center gap-1 rounded-full border border-border/80 bg-background/70 px-3 py-1.5 text-xs font-medium text-foreground shadow-none dark:bg-background/40">
                          <span className="text-muted-foreground">Other</span>
                          <span className="text-muted-foreground">•</span>
                          <span className="tabular-nums font-semibold">{formatPhpSym(heroStats.monthlyOther)}</span>
                        </span>
                      </div>
                      <p className="text-xs text-muted-foreground">Snapshot · Updates when payroll runs</p>
                    </div>
                    <MetricRing
                      pct={monthlyLoanSharePct}
                      strokeClassName="text-emerald-600 dark:text-emerald-400"
                      trackClassName="text-emerald-400/35 dark:text-emerald-500/25"
                      centerIcon={<Wallet className="size-7" strokeWidth={1.75} aria-hidden />}
                    />
                  </CardContent>
                </Card>
              </MotionDiv>
            </section>

            {/* CTA card when no products */}
            {maySubmitLoanRequest && !hasLoanProducts ? (
              <Card className="rounded-2xl border-dashed border-border bg-muted/30 shadow-sm">
                <CardContent className="flex flex-col items-center gap-3 py-10 text-center">
                  <p className="font-semibold text-foreground">No loan products available yet</p>
                  <p className="max-w-md text-sm text-muted-foreground">
                    Ask HR to configure Pay Components (Loan category) or deduction types for your company.
                  </p>
                </CardContent>
              </Card>
            ) : null}

            {/* Active loans */}
            <section className="space-y-4">
              <div className="space-y-1.5 border-l-4 border-brand pl-4">
                <h2 className="text-xl font-bold tracking-tight text-foreground md:text-2xl">Active loans & deductions</h2>
                <p className="text-sm text-muted-foreground md:text-[15px]">
                  Amounts follow your pay cycle and deduction schedule.
                </p>
              </div>
              {(data.employee_deductions || []).length === 0 ? (
                <MotionDiv
                  initial={{ opacity: 0, y: 12 }}
                  animate={{ opacity: 1, y: 0 }}
                  transition={{ duration: 0.4 }}
                >
                  <Card
                    className={cn('overflow-hidden rounded-2xl border border-border/70 bg-card shadow-sm', cardShadow)}
                    style={{ borderRadius: 16 }}
                  >
                    <CardContent className="flex flex-col items-center gap-5 px-6 py-14 text-center md:py-16">
                      <PiggyBankIllustration />
                      <div className="max-w-md space-y-2">
                        <p className="text-xl font-bold tracking-tight text-foreground">Nothing due right now</p>
                        <p className="text-sm leading-relaxed text-muted-foreground">
                          You currently have no active loans or deductions. Request one below — when HR assigns a loan, balances and payoff
                          progress show up here automatically.
                        </p>
                      </div>
                      {maySubmitLoanRequest && canOpenRequestDialog ? (
                        <Button
                          type="button"
                          onClick={() => setDialogOpen(true)}
                          className="h-10 gap-2 rounded-xl border border-brand bg-card px-8 text-sm font-semibold text-brand shadow-sm transition-colors hover:bg-brand/10"
                        >
                          <Plus className="size-5" strokeWidth={2.5} aria-hidden />
                          Request new loan
                        </Button>
                      ) : null}
                    </CardContent>
                  </Card>
                </MotionDiv>
              ) : (
                <Card
                  className={cn('overflow-hidden rounded-2xl border border-border/70 bg-card shadow-sm', cardShadow)}
                  style={{ borderRadius: 16 }}
                >
                  <div className="relative w-full min-w-0 overflow-x-auto rounded-2xl bg-card dark:bg-card">
                    <div className="relative max-h-[min(72vh,800px)] w-full overflow-y-auto rounded-b-2xl">
                      <Table className="w-full min-w-[960px] border-0 [&_td]:border-0 [&_th]:border-0">
                        <TableHeader className="sticky top-0 z-10 [&_tr]:border-b-0 bg-muted/30 backdrop-blur-md dark:bg-muted/20">
                          <TableRow className="border-0 shadow-none hover:bg-transparent">
                            <TableHead className="w-[52px] py-4 pl-6 text-[11px] font-bold uppercase tracking-wider text-muted-foreground">
                              <span className="sr-only">Item</span>
                            </TableHead>
                            <TableHead className="min-w-[160px] py-4 text-[11px] font-bold uppercase tracking-wider text-muted-foreground">
                              Loan / deduction
                            </TableHead>
                            <TableHead className="min-w-[120px] py-4 text-[11px] font-bold uppercase tracking-wider text-muted-foreground">
                              Type
                            </TableHead>
                            <TableHead className="min-w-[100px] py-4 text-right text-[11px] font-bold uppercase tracking-wider text-muted-foreground">
                              Monthly
                            </TableHead>
                            <TableHead className="min-w-[200px] py-4 text-[11px] font-bold uppercase tracking-wider text-muted-foreground">
                              Remaining
                            </TableHead>
                            <TableHead className="min-w-[150px] py-4 text-[11px] font-bold uppercase tracking-wider text-muted-foreground">
                              Next due
                            </TableHead>
                            <TableHead className="min-w-[200px] py-4 pr-6 text-right text-[11px] font-bold uppercase tracking-wider text-muted-foreground">
                              Actions
                            </TableHead>
                          </TableRow>
                        </TableHeader>
                        <TableBody className="[&_tr]:border-0">
                          {(data.employee_deductions || []).map((row, rowIndex) => {
                            const productName = row.pay_component?.name || row.deduction_type?.name || '—'
                            const total = row.total_loan_amount != null ? Number(row.total_loan_amount) : null
                            const rem = row.remaining_balance != null ? Number(row.remaining_balance) : null
                            const paid =
                              total != null && rem != null && Number.isFinite(total) && Number.isFinite(rem)
                                ? Math.max(0, total - rem)
                                : null
                            const pct =
                              total && paid != null && total > 0 ? Math.min(100, Math.round((paid / total) * 100)) : 0
                            const sched = row.amortization_schedule || row.amortizationSchedule || []
                            const nextDue = row.next_due_date
                            const dueDiff = daysFromToday(nextDue)
                            const urgencyClass = nextDueUrgencyBadgeClass(dueDiff)
                            const showBar = row.is_amortized && paid != null && total != null && total > 0
                            const pctW = progressBarsReady && showBar ? pct : 0
                            const isLargeLoan = total != null && total >= 100000

                            return (
                              <TableRow key={row.id} className={TABLE_ROW_HOVER}>
                                <TableCell className="py-5 pl-6 align-middle">
                                  <span
                                    className="flex size-9 shrink-0 items-center justify-center rounded-lg bg-brand/15 text-brand ring-1 ring-brand/15"
                                    aria-hidden
                                  >
                                    <HandCoins className="size-4" strokeWidth={1.85} />
                                  </span>
                                </TableCell>
                                <TableCell className="py-5 align-middle">
                                  <span className="font-semibold text-foreground">{productName}</span>
                                  {row.source ? (
                                    <p className="mt-0.5 text-xs text-muted-foreground">{row.source}</p>
                                  ) : null}
                                </TableCell>
                                <TableCell className="py-5 align-middle">
                                  <Badge
                                    variant="outline"
                                    className="rounded-md border-border bg-muted/40 text-[11px] font-semibold text-foreground"
                                  >
                                    {rowTypeLabel(row)}
                                  </Badge>
                                  {row.is_legally_allowed === false ? (
                                    <p className="mt-1 text-[11px] text-red-700 dark:text-red-300">
                                      Legal hold: deduction currently blocked.
                                    </p>
                                  ) : null}
                                </TableCell>
                                <TableCell className="py-5 text-right align-middle tabular-nums text-sm font-semibold text-foreground">
                                  {formatPhpSym(row.amount)}
                                </TableCell>
                                <TableCell className="py-5 align-middle">
                                  <div className="flex max-w-[220px] flex-col gap-1.5">
                                    <span className="font-semibold tabular-nums text-foreground">
                                      {rem != null ? formatPhpSym(rem) : '—'}
                                    </span>
                                    {showBar ? (
                                      <>
                                        <AnimatedLoanProgress
                                          value={pctW}
                                          isLarge={isLargeLoan}
                                          delayMs={100 + rowIndex * 60}
                                          indicatorClassName="bg-brand"
                                        />
                                        <span className="text-[11px] tabular-nums text-muted-foreground">
                                          {pct}% paid · {formatPhpSym(paid)} / {formatPhpSym(total)}
                                        </span>
                                      </>
                                    ) : (
                                      <span className="text-[11px] text-muted-foreground">—</span>
                                    )}
                                  </div>
                                </TableCell>
                                <TableCell className="py-5 align-middle">
                                  {nextDue ? (
                                    <div className="flex flex-col gap-1.5">
                                      <span className="text-sm font-medium text-foreground">
                                        {formatDisplayDate(nextDue)}
                                      </span>
                                      {relativeTimeFromDate(nextDue) ? (
                                        <Badge variant="outline" className={cn('w-fit text-[10px] font-semibold', urgencyClass)}>
                                          {relativeTimeFromDate(nextDue)}
                                        </Badge>
                                      ) : null}
                                    </div>
                                  ) : (
                                    <span className="text-muted-foreground">—</span>
                                  )}
                                </TableCell>
                                <TableCell className="py-5 pr-6 text-right align-middle">
                                  <div className="flex flex-col items-end gap-1.5 sm:flex-row sm:flex-wrap sm:justify-end">
                                    {row.is_amortized && Array.isArray(sched) && sched.length > 0 ? (
                                      <Button
                                        type="button"
                                        variant="outline"
                                        size="sm"
                                        className="h-8 border-border bg-background text-foreground hover:bg-muted"
                                        onClick={() => {
                                          setScheduleTitle(productName)
                                          setScheduleRows(sched)
                                          setScheduleOpen(true)
                                        }}
                                      >
                                        View schedule
                                      </Button>
                                    ) : null}
                                    {Array.isArray(row.audit_logs) && row.audit_logs.length > 0 ? (
                                      <Button
                                        type="button"
                                        variant="outline"
                                        size="sm"
                                        className="h-8 border-border bg-background text-foreground hover:bg-muted"
                                        onClick={() => {
                                          setAuditTitle(productName)
                                          setAuditRows(row.audit_logs)
                                          setAuditOpen(true)
                                        }}
                                      >
                                        View audit
                                      </Button>
                                    ) : null}
                                    {row.is_amortized ? (
                                      <Button
                                        type="button"
                                        variant="ghost"
                                        size="sm"
                                        className="h-8 text-foreground/80 hover:bg-muted"
                                        onClick={() =>
                                          toast({
                                            title: 'Extra payments',
                                            description: 'Contact HR to record an ad-hoc or extra payment toward this loan.',
                                          })
                                        }
                                      >
                                        Make extra payment
                                      </Button>
                                    ) : null}
                                  </div>
                                </TableCell>
                              </TableRow>
                            )
                          })}
                        </TableBody>
                      </Table>
                    </div>
                  </div>
                </Card>
              )}
            </section>

            {/* Requests — timeline */}
            <section className="space-y-4">
              <div className="space-y-1.5 border-l-4 border-brand pl-4">
                <h2 className="text-xl font-bold tracking-tight text-foreground md:text-2xl">Your requests & history</h2>
                <p className="text-sm text-muted-foreground md:text-[15px]">Track what you&apos;ve submitted and what HR decided.</p>
              </div>
              <Card className={cn('overflow-hidden rounded-2xl border border-border/70 bg-card shadow-sm', cardShadow)} style={{ borderRadius: 16 }}>
                <CardHeader className="flex flex-col gap-4 border-b border-border/50 px-6 py-5 @sm:flex-row @sm:items-start @sm:justify-between">
                  <div className="space-y-1">
                    <CardTitle className="text-base font-semibold text-foreground">Activity</CardTitle>
                    <CardDescription className="text-sm">Latest submissions and outcomes, newest first.</CardDescription>
                  </div>
                  <Select value={activityFilter} onValueChange={setActivityFilter}>
                    <SelectTrigger className="h-10 w-full min-w-[160px] rounded-xl border-border bg-background shadow-sm @sm:w-[190px]">
                      <SelectValue placeholder="All status" />
                    </SelectTrigger>
                    <SelectContent align="end">
                      <SelectItem value="all">All status</SelectItem>
                      <SelectItem value="pending">Pending</SelectItem>
                      <SelectItem value="approved">Approved</SelectItem>
                      <SelectItem value="rejected">Rejected</SelectItem>
                    </SelectContent>
                  </Select>
                </CardHeader>
                <CardContent className="pt-6">
                  {filteredLoanRequests.length === 0 ? (
                    <div className="flex flex-col items-center gap-3 px-6 py-14 text-center md:py-16">
                      <span className="flex size-14 items-center justify-center rounded-2xl bg-brand/10 text-brand dark:bg-brand/15">
                        <FileText className="size-7" strokeWidth={1.5} aria-hidden />
                      </span>
                      <div className="max-w-sm space-y-1.5">
                        <p className="text-base font-semibold text-foreground">
                          {(data.loan_requests || []).length === 0 ? 'No requests filed yet' : 'No matching activity'}
                        </p>
                        <p className="text-sm leading-relaxed text-muted-foreground">
                          {(data.loan_requests || []).length === 0
                            ? 'Your submissions will appear here once you request a loan or any changes.'
                            : 'No activity matches this filter.'}
                        </p>
                      </div>
                    </div>
                  ) : (
                    <div className="relative">
                      <div className="absolute bottom-6 left-[19px] top-6 w-px bg-border/40" aria-hidden />
                      <ul className="space-y-7">
                        {filteredLoanRequests.map((r, ri) => {
                          const decision = loanRequestDecision(r.status)
                          const approved = decision === 'approved'
                          const rejected = decision === 'rejected'
                          const pending = decision === 'pending'
                          const productName = r.pay_component?.name || r.deduction_type?.name || 'Loan'

                          return (
                            <MotionLi
                              key={r.id}
                              initial={{ opacity: 0, x: -8 }}
                              animate={{ opacity: 1, x: 0 }}
                              transition={{ duration: 0.35, delay: Math.min(ri * 0.06, 0.4) }}
                              className="relative flex gap-5 pl-1"
                            >
                              <div className="relative z-[1] flex shrink-0 flex-col items-center pt-1">
                                <span className="absolute left-1/2 top-10 h-[calc(100%+1.75rem)] w-px -translate-x-1/2 bg-transparent sm:hidden" />
                                <span
                                  className={cn(
                                    'flex size-10 items-center justify-center rounded-full border-2 bg-card shadow-sm transition-transform',
                                    approved &&
                                      'border-emerald-200 text-emerald-700 dark:border-emerald-700/60 dark:text-emerald-300',
                                    rejected && 'border-red-200 text-red-600 dark:border-red-800/60 dark:text-red-300',
                                    pending &&
                                      'border-yellow-400 text-yellow-800 dark:border-yellow-600/70 dark:text-yellow-200',
                                    !approved && !rejected && !pending && 'border-border text-muted-foreground dark:text-slate-400',
                                  )}
                                >
                                  {approved ? (
                                    <CheckCircle2 className="size-5" aria-hidden />
                                  ) : rejected ? (
                                    <XCircle className="size-5" aria-hidden />
                                  ) : pending ? (
                                    <Clock className="size-5" aria-hidden />
                                  ) : (
                                    <span className="text-xs font-bold">•</span>
                                  )}
                                </span>
                              </div>
                              <button
                                type="button"
                                className="min-w-0 flex-1 rounded-2xl border-0 bg-card/90 p-5 shadow-[0_8px_24px_-12px_rgba(15,23,42,0.12)] transition-all duration-300 hover:-translate-y-0.5 hover:shadow-md dark:bg-card/60 dark:shadow-[0_8px_28px_-12px_rgba(0,0,0,0.5)]"
                                onClick={() => openRequestDetails(r)}
                                style={{ borderRadius: 16 }}
                              >
                                <div className="flex flex-col gap-4 sm:flex-row sm:items-start sm:justify-between">
                                  <div className="space-y-2">
                                    <p className="font-semibold text-foreground">{productName}</p>
                                    {r.user && user?.id != null && Number(r.user_id ?? r.user?.id) !== Number(user.id) ? (
                                      <p className="text-xs text-muted-foreground">Borrower: {r.user.name}</p>
                                    ) : null}
                                    <p className="text-3xl font-bold tabular-nums tracking-tight text-brand md:text-[2rem]">
                                      {formatPhpSym(r.requested_amount)}
                                    </p>
                                    <p className="text-sm text-muted-foreground">
                                      <span className="font-medium text-foreground/90">
                                        {r.term_months != null ? `${r.term_months} months` : 'Term TBD'}
                                      </span>
                                      {r.deduction_schedule ? (
                                        <>
                                          {' · '}
                                          <span className="text-muted-foreground">
                                            {formatDeductionScheduleTypeShort(r.deduction_schedule)}
                                          </span>
                                        </>
                                      ) : null}
                                      {' · '}
                                      {loanStageLabel(r.approval_stage, r.status)}
                                    </p>
                                  </div>
                                  <div className="flex flex-wrap items-center gap-2 sm:pt-1">
                                    {approved ? (
                                      <span className="inline-flex items-center gap-1.5 rounded-full border border-emerald-200 bg-emerald-50 px-3.5 py-1.5 text-xs font-semibold text-emerald-900 dark:border-emerald-800/50 dark:bg-emerald-950/40 dark:text-emerald-100">
                                        <CheckCircle2 className="size-3.5 opacity-80" aria-hidden />
                                        Approved
                                      </span>
                                    ) : rejected ? (
                                      <span className="inline-flex items-center gap-1.5 rounded-full border border-red-200 bg-red-50 px-3.5 py-1.5 text-xs font-semibold text-red-900 dark:border-red-900/50 dark:bg-red-950/45 dark:text-red-100">
                                        <XCircle className="size-3.5 opacity-80" aria-hidden />
                                        Rejected
                                      </span>
                                    ) : pending ? (
                                      <span className="inline-flex items-center gap-1.5 rounded-full border border-yellow-400 bg-yellow-50 px-3.5 py-1.5 text-xs font-semibold text-yellow-950 dark:border-yellow-600/60 dark:bg-yellow-950/45 dark:text-yellow-100">
                                        <Clock className="size-3.5 opacity-80" aria-hidden />
                                        Pending
                                      </span>
                                    ) : (
                                      <Badge variant="secondary" className="rounded-full capitalize text-foreground">
                                        {r.status || '—'}
                                      </Badge>
                                    )}
                                  </div>
                                </div>
                              </button>
                            </MotionLi>
                          )
                        })}
                      </ul>
                    </div>
                  )}
                </CardContent>
              </Card>
            </section>
          </>
        )}

        <Dialog open={scheduleOpen} onOpenChange={setScheduleOpen}>
          <DialogContent
            className="w-full max-w-5xl sm:max-w-5xl"
            innerClassName="gap-0 px-0 pb-0 pt-0 sm:px-0"
          >
            <div className="flex flex-col gap-6 px-6 pb-6 pt-6 sm:px-8 sm:pb-8 sm:pt-7">
              <DialogHeader className="gap-2 space-y-0 pr-10 text-left">
                <DialogTitle className="text-xl font-semibold tracking-tight text-foreground">
                  Payment schedule
                </DialogTitle>
                <DialogDescription className="text-[15px] leading-relaxed text-muted-foreground">{scheduleTitle}</DialogDescription>
              </DialogHeader>

              <div className="max-h-[min(65vh,640px)] overflow-auto rounded-2xl bg-muted/25 px-2 py-2 dark:bg-white/[0.04]">
                <Table className="border-0 [&_td]:border-0 [&_th]:border-0">
                  <TableHeader className="sticky top-0 z-[1] [&_tr]:border-b-0 bg-muted/25 backdrop-blur-sm dark:bg-muted/20">
                    <TableRow className="border-0 hover:bg-transparent">
                      <TableHead className="h-11 min-w-[3rem] pl-4 text-[11px] font-semibold uppercase tracking-wider text-muted-foreground">
                        #
                      </TableHead>
                      <TableHead className="min-w-[11rem] text-[11px] font-semibold uppercase tracking-wider text-muted-foreground">
                        Pay date
                      </TableHead>
                      <TableHead className="min-w-[7.5rem] text-right text-[11px] font-semibold uppercase tracking-wider text-muted-foreground">
                        Principal
                      </TableHead>
                      <TableHead className="min-w-[7.5rem] text-right text-[11px] font-semibold uppercase tracking-wider text-muted-foreground">
                        Interest
                      </TableHead>
                      <TableHead className="min-w-[7.5rem] text-right text-[11px] font-semibold uppercase tracking-wider text-muted-foreground">
                        Payment
                      </TableHead>
                      <TableHead className="min-w-[6rem] pr-4 text-[11px] font-semibold uppercase tracking-wider text-muted-foreground">
                        Status
                      </TableHead>
                    </TableRow>
                  </TableHeader>
                  <TableBody className="[&_tr]:border-0">
                    {scheduleRows.map((row) => (
                      <TableRow
                        key={row.id}
                        className="border-0 transition-colors hover:bg-background/80 dark:hover:bg-white/[0.06]"
                      >
                        <TableCell className="py-3.5 pl-4 text-sm font-medium tabular-nums text-foreground">
                          {row.installment_number}
                        </TableCell>
                        <TableCell className="py-3.5 text-sm text-foreground">
                          {formatLongDate(row.pay_date || row.due_date)}
                        </TableCell>
                        <TableCell className="py-3.5 text-right text-sm tabular-nums text-foreground">
                          {formatPhpSym(row.principal)}
                        </TableCell>
                        <TableCell className="py-3.5 text-right text-sm tabular-nums text-foreground">
                          {formatPhpSym(row.interest)}
                        </TableCell>
                        <TableCell className="py-3.5 text-right text-sm font-semibold tabular-nums text-foreground">
                          {formatPhpSym(row.total_installment)}
                        </TableCell>
                        <TableCell className="py-3.5 pr-4">
                          <Badge
                            variant="secondary"
                            className={cn(
                              'rounded-full px-2.5 py-0.5 text-xs font-semibold capitalize',
                              String(row.status || '').toLowerCase() === 'paid' &&
                                'border-emerald-600/40 bg-emerald-50 text-emerald-900 dark:border-emerald-500/50 dark:bg-emerald-950/40 dark:text-emerald-100',
                            )}
                          >
                            {row.status}
                          </Badge>
                        </TableCell>
                      </TableRow>
                    ))}
                  </TableBody>
                </Table>
              </div>

              <DialogFooter className="gap-3 border-0 pt-0 sm:justify-end">
                <Button
                  type="button"
                  variant="outline"
                  className="h-10 rounded-full border-border px-6 text-foreground hover:bg-muted"
                  onClick={() => setScheduleOpen(false)}
                >
                  Close
                </Button>
              </DialogFooter>
            </div>
          </DialogContent>
        </Dialog>

        <LoanRequestDetailsModal
          open={requestDetailsOpen}
          onOpenChange={handleRequestDetailsOpenChange}
          loading={requestDetailsLoading}
          loanRequest={requestDetail?.loan_request ?? selectedRequest}
          approvalProgress={Array.isArray(requestDetail?.approval_progress) ? requestDetail.approval_progress : []}
          payCyclePreview={requestDetail?.pay_cycle_preview ?? ctx?.pay_cycle_preview ?? null}
          nextDeductionDates={nextDeductionDates}
          canApprove={false}
          canReject={false}
        />

        <Dialog open={auditOpen} onOpenChange={setAuditOpen}>
          <DialogContent className="w-full max-w-4xl sm:max-w-4xl">
            <div className="flex flex-col gap-6 px-1 pb-1 pt-1">
              <DialogHeader className="gap-2 space-y-0 pr-10 text-left">
                <DialogTitle className="text-xl font-semibold tracking-tight text-foreground">
                  Audit trail
                </DialogTitle>
                <DialogDescription className="text-[15px] leading-relaxed text-muted-foreground">{auditTitle}</DialogDescription>
              </DialogHeader>
              <div className="max-h-[60vh] overflow-auto rounded-xl border border-border/50">
                <Table>
                  <TableHeader>
                    <TableRow>
                      <TableHead>When</TableHead>
                      <TableHead>Action</TableHead>
                      <TableHead>Actor</TableHead>
                      <TableHead className="text-right">Amount</TableHead>
                      <TableHead className="text-right">Remaining</TableHead>
                      <TableHead>Notes</TableHead>
                    </TableRow>
                  </TableHeader>
                  <TableBody>
                    {auditRows.length === 0 ? (
                      <TableRow>
                        <TableCell colSpan={6} className="py-8 text-center text-sm text-muted-foreground">
                          No audit rows available.
                        </TableCell>
                      </TableRow>
                    ) : (
                      auditRows.map((row) => (
                        <TableRow key={row.id}>
                          <TableCell>{formatLongDate(row.created_at)}</TableCell>
                          <TableCell className="capitalize">{String(row.action || '').replace(/_/g, ' ')}</TableCell>
                          <TableCell>{row.actor?.name || 'System'}</TableCell>
                          <TableCell className="text-right tabular-nums">{formatPhpSym(row.amount)}</TableCell>
                          <TableCell className="text-right tabular-nums">{formatPhpSym(row.remaining_balance_after)}</TableCell>
                          <TableCell className="max-w-[24rem] text-xs text-muted-foreground">{row.notes || '—'}</TableCell>
                        </TableRow>
                      ))
                    )}
                  </TableBody>
                </Table>
              </div>
            </div>
          </DialogContent>
        </Dialog>

        <RequestLoanModal
          open={dialogOpen}
          onOpenChange={setDialogOpen}
          mode="employee"
          contextFromParent={ctx}
          initialPrincipal={50000}
          borrowerLabel={selfLoanDisplay}
          borrowerHelperText="Requests apply to your account only."
          onEmployeeSuccess={load}
        />
      </div>
    </div>
  )
}
