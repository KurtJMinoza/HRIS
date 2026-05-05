import { useCallback, useEffect, useMemo, useState } from 'react'
import { CalendarDays, ChevronRight, Loader2, MoreHorizontal, Pencil, Search, Trash2, Wallet } from 'lucide-react'
import { Avatar, AvatarFallback, AvatarImage } from '@/components/ui/avatar'
import { Badge } from '@/components/ui/badge'
import { Button } from '@/components/ui/button'
import { Card, CardContent, CardDescription, CardHeader, CardTitle } from '@/components/ui/card'
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
import { Popover, PopoverContent, PopoverTrigger } from '@/components/ui/popover'
import { Select, SelectContent, SelectItem, SelectTrigger, SelectValue } from '@/components/ui/select'
import {
  DropdownMenu,
  DropdownMenuContent,
  DropdownMenuItem,
  DropdownMenuTrigger,
} from '@/components/ui/dropdown-menu'
import { Separator } from '@/components/ui/separator'
import { Skeleton } from '@/components/ui/skeleton'
import { Table, TableBody, TableCell, TableHead, TableHeader, TableRow } from '@/components/ui/table'
import { Tabs, TabsContent, TabsList, TabsTrigger } from '@/components/ui/tabs'
import { Textarea } from '@/components/ui/textarea'
import { Tooltip, TooltipContent, TooltipProvider, TooltipTrigger } from '@/components/ui/tooltip'
import { useToast } from '@/components/ui/use-toast'
import {
  approveAdminLoanRequest,
  createAdminDeductionType,
  deleteAdminDeductionType,
  createAdminEmployeePayDeduction,
  getEmployees,
  getAdminDeductionTypes,
  getAdminActiveEmployeeDeductionsInScope,
  getAdminLoanRequestDetail,
  getAdminLoanRequests,
  updateAdminDeductionType,
  postAdminEmployeeDeductionEarlyPayoff,
  patchAdminEmployeeDeductionBalance,
  rejectAdminLoanRequest,
  userProfileImageSrc,
} from '@/api'
import { EarlyLoanPayoffConfirmDialog } from '../components/loans/EarlyLoanPayoffConfirmDialog.jsx'
import { RequestLoanModal } from '@/components/loans/RequestLoanModal.jsx'
import { LoanRequestDetailsModal } from '@/components/loans/LoanRequestDetailsModal.jsx'
import { scheduleTypeFriendlyLabel } from '@/lib/loanRequestEstimate'
import { useAuth } from '@/contexts/AuthContext'
import { useHrBasePath } from '@/contexts/HrAppPathContext'
import { hrPanelPath } from '@/lib/hrRoutes'
import { cn } from '@/lib/utils'
import { Link } from 'react-router-dom'

/** Clean admin shell — white card, neutral depth */
const PREMIUM_CARD =
  'overflow-hidden rounded-2xl border border-border/60 bg-card shadow-sm transition-shadow dark:border-white/10 dark:bg-card'
const PREMIUM_ROW =
  'group border-border/30 transition-colors hover:bg-muted/40 dark:hover:bg-white/[0.04]'

/** Primary CTA — black surface, white label (strong actions: save, submit, approve) */
const BTN_PRIMARY =
  'bg-[#0A0A0A] text-white shadow-sm hover:bg-[#0A0A0A]/90 focus-visible:ring-2 focus-visible:ring-[#0A0A0A]/25 dark:bg-slate-100 dark:text-[#0A0A0A] dark:hover:bg-white dark:focus-visible:ring-slate-400/30'
/** Secondary — black text and icons on clean light surface */
const BTN_SECONDARY =
  'border border-border bg-background text-[#0A0A0A] shadow-sm hover:bg-muted/80 [&_svg]:text-[#0A0A0A] dark:border-white/15 dark:bg-card dark:text-slate-100 dark:[&_svg]:text-slate-100 dark:hover:bg-white/10'
/** Muted supporting copy (still readable on white) */
const TEXT_MUTED = 'text-[#0A0A0A]/65 dark:text-slate-300'

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

function formatPhp(n) {
  const v = Number(n)
  if (!Number.isFinite(v)) return '—'
  return `${v.toLocaleString('en-PH', { minimumFractionDigits: 2, maximumFractionDigits: 2 })}`
}

function formatPhpWithSymbol(n) {
  const s = formatPhp(n)
  return s === '—' ? s : `₱${s}`
}

const HERO_STORAGE_KEY = 'hr-admin-deductions-hero-v1'

function RepaymentRing({ pct, known = true }) {
  const r = 44
  const c = 2 * Math.PI * r
  const p = Math.min(100, Math.max(0, Number(pct) || 0))
  const offset = c - (p / 100) * c
  return (
    <div className="relative size-[7.5rem] shrink-0">
      <svg viewBox="0 0 120 120" className="size-full -rotate-90" aria-hidden>
        <circle cx="60" cy="60" r={r} fill="none" className="stroke-muted/40" strokeWidth="10" />
        <circle
          cx="60"
          cy="60"
          r={r}
          fill="none"
          className="stroke-[#0A0A0A] transition-[stroke-dashoffset] duration-1000 ease-out dark:stroke-slate-100"
          strokeWidth="10"
          strokeLinecap="round"
          strokeDasharray={c}
          strokeDashoffset={known ? offset : c}
        />
      </svg>
      <div className="pointer-events-none absolute inset-0 flex flex-col items-center justify-center text-center">
        <span className="text-2xl font-bold tabular-nums text-[#0A0A0A] dark:text-slate-50">{known ? `${p}%` : '—'}</span>
        <span className="text-[10px] font-medium uppercase tracking-wide text-muted-foreground">repaid</span>
      </div>
    </div>
  )
}

/** Product / deduction label for the Active table (loan vs other). */
function productTypeLabel(row) {
  const dt = row.deduction_type
  const name = dt?.name || row.pay_component?.name
  const isLoan = rowIsLoan(row)
  if (isLoan && name) return name
  if (isLoan) return 'Salary loan'
  return name || '—'
}

function rowIsLoan(row) {
  const dt = row.deduction_type
  return String(dt?.type || '').toLowerCase() === 'loan' || row.pay_component?.is_loan
}

/**
 * Principal repaid from balance: (total_loan_amount - remaining_balance) / total_loan_amount.
 * Matches “₱500 remaining of ₱2,000” → 75% repaid.
 */
function loanBalanceRepaymentProgress(row) {
  if (!rowIsLoan(row)) return null
  const rem = row.remaining_balance != null ? Number(row.remaining_balance) : null
  const total = row.total_loan_amount != null ? Number(row.total_loan_amount) : null
  if (rem == null || total == null || !Number.isFinite(rem) || !Number.isFinite(total) || total <= 0) return null
  const paid = Math.max(0, total - rem)
  const pct = Math.min(100, Math.round((paid / total) * 100))
  return { pct, paid, total, remaining: rem, mode: 'balance' }
}

/**
 * Sum of principal from schedule rows (for fallbacks when total_loan_amount is missing).
 */
function amortizationSchedulePrincipalTotals(sched) {
  if (!Array.isArray(sched) || sched.length === 0) return null
  let totalPrincipal = 0
  let paidPrincipal = 0
  for (const s of sched) {
    const p = Number(s.principal) || 0
    totalPrincipal += p
    if (String(s.status || '').toLowerCase() === 'paid') paidPrincipal += p
  }
  if (totalPrincipal <= 0) return null
  const pctPaid = Math.min(100, Math.round((paidPrincipal / totalPrincipal) * 100))
  return { totalPrincipal, paidPrincipal, pctPaid }
}

/**
 * Loan payoff UI for Active table: prefer API totals, then schedule principal vs remaining, then schedule paid rows.
 * Every loan row gets a pct for the progress bar (0 if unknown).
 */
function getLoanProgressForRow(row) {
  if (!rowIsLoan(row)) return null
  const sched = row.amortization_schedule || row.amortizationSchedule || []

  const balance = loanBalanceRepaymentProgress(row)
  if (balance) {
    return {
      pctPaid: balance.pct,
      total: balance.total,
      remaining: balance.remaining,
      mode: 'balance',
    }
  }

  const totals = amortizationSchedulePrincipalTotals(sched)
  const rem = row.remaining_balance != null ? Number(row.remaining_balance) : null
  if (totals && rem != null && Number.isFinite(rem) && totals.totalPrincipal > 0) {
    const paid = Math.max(0, totals.totalPrincipal - rem)
    const pctPaid = Math.min(100, Math.round((paid / totals.totalPrincipal) * 100))
    return {
      pctPaid,
      total: totals.totalPrincipal,
      remaining: rem,
      mode: 'schedule_vs_remaining',
    }
  }

  if (totals) {
    return {
      pctPaid: totals.pctPaid,
      total: totals.totalPrincipal,
      remaining: Math.max(0, totals.totalPrincipal - totals.paidPrincipal),
      mode: 'schedule',
    }
  }

  return {
    pctPaid: 0,
    total: null,
    remaining: rem,
    mode: 'unknown',
  }
}

function formatDisplayDate(value) {
  const raw = String(value || '').trim()
  if (!raw) return '—'
  const d = new Date(raw)
  if (Number.isNaN(d.getTime())) return raw
  return d.toLocaleDateString('en-PH', { year: 'numeric', month: 'long', day: 'numeric' })
}

function formatDateTime(value) {
  const raw = String(value || '').trim()
  if (!raw) return '—'
  const d = new Date(raw)
  if (Number.isNaN(d.getTime())) return raw
  return d.toLocaleString('en-PH', {
    year: 'numeric',
    month: 'short',
    day: 'numeric',
    hour: 'numeric',
    minute: '2-digit',
  })
}

function loanRequestStatusBadgeClass(status) {
  const s = String(status || '').toLowerCase()
  if (s === 'approved') return 'border-emerald-200 bg-emerald-50 text-emerald-900 dark:border-emerald-800/50 dark:bg-emerald-950/40 dark:text-emerald-100'
  if (s === 'rejected') return 'border-red-200 bg-red-50 text-red-900 dark:border-red-900/50 dark:bg-red-950/45 dark:text-red-100'
  return 'border-amber-200 bg-amber-50 text-amber-950 dark:border-amber-800/50 dark:bg-amber-950/40 dark:text-amber-100'
}

function approvalTimelineDotClass(status) {
  switch (status) {
    case 'completed':
      return 'border-[#0A0A0A] bg-[#0A0A0A] dark:border-slate-100 dark:bg-slate-100'
    case 'current':
      return 'border-[#0A0A0A] bg-background shadow-[0_0_0_3px_rgba(10,10,10,0.12)] dark:border-slate-100 dark:bg-card dark:shadow-[0_0_0_3px_rgba(255,255,255,0.12)]'
    case 'rejected':
      return 'border-destructive bg-destructive'
    default:
      return 'border-border bg-muted'
  }
}

/** Human-readable offset from today for next due dates */
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
  if (diffDays === -1) return 'Was yesterday'
  if (diffDays > 1) return `Due in ${diffDays} days`
  if (diffDays < -1) return `${Math.abs(diffDays)} days ago`
  return null
}

/** Kind key for Type column (loan / benefit / other). */
function deductionKindKey(row) {
  const t = row.deduction_type?.type
  if (t) return String(t).toLowerCase()
  if (row.pay_component?.is_loan) return 'loan'
  return 'other'
}

/** Status column: Amortized | Paid up (schedule complete) | Recurring | Active */
function rowDeductionStatusLabel(row) {
  if (row.is_amortized) {
    const sched = row.amortization_schedule || row.amortizationSchedule || []
    if (Array.isArray(sched) && sched.length > 0) {
      const pending = sched.filter((s) => String(s.status || '').toLowerCase() === 'pending').length
      if (pending === 0) return 'Paid up'
    }
    return 'Amortized'
  }
  if (rowIsLoan(row)) return 'Recurring'
  return 'Active'
}

function rowDeductionStatusBadgeClass(row) {
  if (row.is_amortized) {
    const sched = row.amortization_schedule || row.amortizationSchedule || []
    if (Array.isArray(sched) && sched.length > 0) {
      const pending = sched.filter((s) => String(s.status || '').toLowerCase() === 'pending').length
      if (pending === 0) {
        return 'border-emerald-600/35 bg-emerald-50 text-emerald-950 dark:border-emerald-500/45 dark:bg-emerald-950/35 dark:text-emerald-100'
      }
    }
    return 'border-[#0A0A0A]/20 bg-muted/60 text-[#0A0A0A] dark:border-white/15 dark:bg-white/[0.06] dark:text-slate-100'
  }
  if (rowIsLoan(row)) {
    return 'border-border bg-muted/50 text-[#0A0A0A] dark:border-white/10 dark:bg-white/[0.05] dark:text-slate-100'
  }
  return 'border-border bg-muted/60 text-[#0A0A0A] dark:border-white/10 dark:bg-white/5 dark:text-slate-100'
}

/** Next-due: neutral labels (black palette); red only when overdue. */
function nextDueUrgencyBadgeClass(dueDiff) {
  if (dueDiff == null) return 'border-border bg-muted/50 text-[#0A0A0A] dark:text-slate-200'
  if (dueDiff < 0) return 'border-red-200 bg-red-50 text-red-800 dark:border-red-800/60 dark:bg-red-950/45 dark:text-red-200'
  return 'border-border bg-muted/40 text-[#0A0A0A] dark:border-white/10 dark:bg-white/[0.05] dark:text-slate-200'
}

function initials(name) {
  const parts = String(name || '')
    .trim()
    .split(/\s+/)
    .filter(Boolean)
  if (parts.length === 0) return '?'
  if (parts.length === 1) return parts[0].slice(0, 2).toUpperCase()
  return (parts[0][0] + parts[parts.length - 1][0]).toUpperCase()
}

/** Match Admin → Employees list avatar fallback colors */
const AVATAR_COLORS = [
  'bg-neutral-200/90 text-[#0A0A0A] dark:bg-white/15 dark:text-slate-100',
  'bg-violet-500/20 text-violet-700 dark:bg-violet-400/25 dark:text-violet-200',
  'bg-zinc-400/20 text-zinc-800 dark:bg-zinc-500/25 dark:text-zinc-200',
  'bg-amber-500/20 text-amber-700 dark:bg-amber-400/25 dark:text-amber-200',
  'bg-rose-500/20 text-rose-700 dark:bg-rose-400/25 dark:text-rose-200',
  'bg-cyan-500/20 text-cyan-700 dark:bg-cyan-400/25 dark:text-cyan-200',
  'bg-orange-500/20 text-orange-700 dark:bg-orange-400/25 dark:text-orange-200',
  'bg-fuchsia-500/20 text-fuchsia-700 dark:bg-fuchsia-400/25 dark:text-fuchsia-200',
]
function getAvatarColor(id, name) {
  let h = typeof id === 'number' ? id : 0
  const s = `${id ?? ''}-${name ?? ''}`
  for (let i = 0; i < s.length; i++) h = (h * 31 + s.charCodeAt(i)) | 0
  return AVATAR_COLORS[Math.abs(h) % AVATAR_COLORS.length]
}

function typeKindMeta(kind) {
  const k = String(kind || '').toLowerCase()
  if (k === 'loan') {
    return {
      label: 'Loan',
      chip: 'border-border/80 bg-muted/55 text-[#0A0A0A] dark:border-border dark:bg-white/[0.06] dark:text-slate-100',
    }
  }
  if (k === 'benefit') {
    return {
      label: 'Benefit',
      chip: 'border-border/80 bg-muted/45 text-[#0A0A0A] dark:border-border dark:bg-white/[0.05] dark:text-slate-100',
    }
  }
  return {
    label: 'Other',
    chip: 'border-border/80 bg-muted/40 text-muted-foreground',
  }
}

function isDeductionTypeActive(type) {
  const value = type?.is_active
  if (value === false || value === 0 || value === '0') return false
  return true
}

function TableSkeletonRows({ cols = 8, rows = 6 }) {
  return (
    <>
      {Array.from({ length: rows }).map((_, i) => (
        <TableRow key={i} className="hover:bg-transparent">
          {Array.from({ length: cols }).map((__, j) => (
            <TableCell key={j} className="py-4">
              <Skeleton className="h-4 w-full max-w-[8rem]" />
            </TableCell>
          ))}
        </TableRow>
      ))}
    </>
  )
}

export default function AdminDeductionsLoansPage() {
  const { user } = useAuth()
  const hrBase = useHrBasePath()
  const { toast } = useToast()
  const [tab, setTab] = useState('overview')
  const [activeDeductions, setActiveDeductions] = useState([])
  const [assignForm, setAssignForm] = useState({
    user_id: '',
    deduction_type_id: '',
    pay_component_id: '',
    amount: '',
    remaining_balance: '',
    start_date: new Date().toISOString().slice(0, 10),
    deduction_schedule: 'both',
  })
  const [assigning, setAssigning] = useState(false)
  const [loading, setLoading] = useState(true)
  const [types, setTypes] = useState([])
  const [requests, setRequests] = useState([])
  const [typeDialog, setTypeDialog] = useState(false)
  const [typeForm, setTypeForm] = useState({
    name: '',
    type: 'loan',
    with_interest: false,
    interest_rate_percent: '0',
    interest_type: 'simple',
  })
  const [savingType, setSavingType] = useState(false)
  const [typeRenameDialog, setTypeRenameDialog] = useState(false)
  const [typeDeleteDialog, setTypeDeleteDialog] = useState(false)
  const [typeTarget, setTypeTarget] = useState(null)
  const [typeRenameValue, setTypeRenameValue] = useState('')
  const [savingTypeRename, setSavingTypeRename] = useState(false)
  const [deletingType, setDeletingType] = useState(false)
  const [detailOpen, setDetailOpen] = useState(false)
  const [detailLoading, setDetailLoading] = useState(false)
  const [selectedId, setSelectedId] = useState(null)
  const [detail, setDetail] = useState(null)
  const [installment, setInstallment] = useState('')
  const [approveNotes, setApproveNotes] = useState('')
  const [rejectNote, setRejectNote] = useState('')
  const [acting, setActing] = useState(false)
  const [amortOpen, setAmortOpen] = useState(false)
  const [amortRows, setAmortRows] = useState([])
  const [amortTitle, setAmortTitle] = useState('')
  const [amortTarget, setAmortTarget] = useState(null)
  const [adjustBalanceInput, setAdjustBalanceInput] = useState('')
  const [adjustingBalance, setAdjustingBalance] = useState(false)
  const [payoffLoading, setPayoffLoading] = useState(false)
  const [payoffConfirmOpen, setPayoffConfirmOpen] = useState(false)
  const [payoffTarget, setPayoffTarget] = useState(null)
  const [assignEmployees, setAssignEmployees] = useState([])
  const [assignEmployeesLoading, setAssignEmployeesLoading] = useState(false)
  const [employeePickerOpen, setEmployeePickerOpen] = useState(false)
  const [employeeSearchQuery, setEmployeeSearchQuery] = useState('')

  const fetchAssignEmployees = useCallback(async () => {
    setAssignEmployeesLoading(true)
    try {
      const data = await getEmployees({ per_page: 'all', for_schedule_assignment: true })
      setAssignEmployees(Array.isArray(data?.employees) ? data.employees : [])
    } catch (e) {
      setAssignEmployees([])
      toast({ title: 'Could not load employees', description: e.message || 'Try again.', variant: 'destructive' })
    } finally {
      setAssignEmployeesLoading(false)
    }
  }, [toast])

  const loadAll = useCallback(async (opts = {}) => {
    const silent = Boolean(opts.silent)
    if (!silent) setLoading(true)
    try {
      const [tRes, rRes, activeRes] = await Promise.allSettled([
        getAdminDeductionTypes(),
        getAdminLoanRequests(),
        getAdminActiveEmployeeDeductionsInScope(),
      ])
      if (tRes.status === 'fulfilled') {
        setTypes(tRes.value?.deduction_types || [])
      } else {
        setTypes([])
      }
      if (rRes.status === 'fulfilled') {
        setRequests(Array.isArray(rRes.value) ? rRes.value : [])
      } else {
        setRequests([])
      }
      if (activeRes.status === 'fulfilled') {
        setActiveDeductions(Array.isArray(activeRes.value) ? activeRes.value : [])
      } else {
        setActiveDeductions([])
      }
      if (tRes.status === 'rejected' || rRes.status === 'rejected' || activeRes.status === 'rejected') {
        throw new Error(
          tRes.status === 'rejected'
            ? (tRes.reason?.message || 'Failed to load deduction types.')
            : rRes.status === 'rejected'
              ? (rRes.reason?.message || 'Failed to load loan requests.')
              : (activeRes.reason?.message || 'Failed to load active deductions.')
        )
      }
    } catch (e) {
      toast({ title: 'Failed to load', description: e.message || 'Try again.', variant: 'destructive' })
    } finally {
      if (!silent) setLoading(false)
    }
  }, [toast])

  const pendingLoanRequests = useMemo(
    () => (requests || []).filter((row) => String(row.status || '').toLowerCase() === 'pending'),
    [requests],
  )
  const activeTypes = useMemo(() => (types || []).filter((t) => isDeductionTypeActive(t)), [types])

  const stats = useMemo(() => {
    const monthlyExposure = (activeDeductions || []).reduce((acc, row) => acc + (Number(row.amount) || 0), 0)
    return {
      active: activeDeductions.length,
      pending: pendingLoanRequests.length,
      monthlyExposure: monthlyExposure,
    }
  }, [activeDeductions, pendingLoanRequests.length])

  /** Org-wide aggregates for hero metrics (scoped to admin data). */
  const heroStats = useMemo(() => {
    const rows = activeDeductions || []
    let totalRemainingDebt = 0
    let sumPrincipal = 0
    let sumPaid = 0
    let monthlyLoans = 0
    let monthlyBenefits = 0
    let monthlyOther = 0
    for (const row of rows) {
      const amt = Number(row.amount) || 0
      const isLoan = rowIsLoan(row)
      const kind = String(row.deduction_type?.type || '').toLowerCase()
      if (isLoan) {
        monthlyLoans += amt
        const rem = row.remaining_balance != null ? Number(row.remaining_balance) : null
        if (rem != null && Number.isFinite(rem) && rem > 0) totalRemainingDebt += rem
        const prog = getLoanProgressForRow(row)
        if (prog?.total != null && Number.isFinite(prog.total) && prog.total > 0) {
          const paid = Math.max(0, prog.total - (prog.remaining ?? 0))
          sumPrincipal += prog.total
          sumPaid += paid
        }
      } else if (kind === 'benefit') {
        monthlyBenefits += amt
      } else {
        monthlyOther += amt
      }
    }
    const repaymentKnown = sumPrincipal > 0
    const overallRepaymentPct = repaymentKnown ? Math.min(100, Math.round((sumPaid / sumPrincipal) * 100)) : 0
    return {
      totalRemainingDebt,
      overallRepaymentPct,
      repaymentKnown,
      totalMonthlyDeductions: monthlyLoans + monthlyBenefits + monthlyOther,
      monthlyLoans,
      monthlyBenefits,
      monthlyOther,
      loanRowCount: rows.filter((r) => rowIsLoan(r)).length,
    }
  }, [activeDeductions])

  const [heroTrendPrev, setHeroTrendPrev] = useState(null)
  const [requestLoanOpen, setRequestLoanOpen] = useState(false)
  const [progressBarsReady, setProgressBarsReady] = useState(false)
  const permSet = useMemo(() => new Set(user?.permissions ?? []), [user?.permissions])
  const mayRequestLoan =
    permSet.has('request-loan') ||
    permSet.has('loans.request') ||
    permSet.has('loans.view_own') ||
    user?.is_super_admin ||
    String(user?.role || '').toLowerCase() === 'admin'

  useEffect(() => {
    if (loading) return
    try {
      const raw = localStorage.getItem(HERO_STORAGE_KEY)
      setHeroTrendPrev(raw ? JSON.parse(raw) : null)
    } catch {
      setHeroTrendPrev(null)
    }
    try {
      localStorage.setItem(
        HERO_STORAGE_KEY,
        JSON.stringify({
          remaining: heroStats.totalRemainingDebt,
          monthly: heroStats.totalMonthlyDeductions,
          ts: Date.now(),
        }),
      )
    } catch {
      /* ignore */
    }
  }, [loading, heroStats.totalRemainingDebt, heroStats.totalMonthlyDeductions])

  useEffect(() => {
    if (loading) return
    const t = requestAnimationFrame(() => setProgressBarsReady(true))
    return () => cancelAnimationFrame(t)
  }, [loading, activeDeductions])

  const remainingTrendLabel = useMemo(() => {
    if (heroTrendPrev == null || typeof heroTrendPrev.remaining !== 'number') {
      return { primary: 'Trend unlocks after your next visit', sub: 'We snapshot balances when you load this page.' }
    }
    const d = heroStats.totalRemainingDebt - heroTrendPrev.remaining
    if (Math.abs(d) < 1)
      return { primary: 'Principal remaining flat vs last snapshot', sub: 'Refresh data after payroll runs for movement.' }
    if (d < 0)
      return {
        primary: `↓ ${formatPhpWithSymbol(Math.abs(d))} less debt outstanding`,
        sub: 'Compared to your last snapshot on this device',
      }
    return {
      primary: `↑ ${formatPhpWithSymbol(d)} more debt outstanding`,
      sub: 'Compared to your last snapshot on this device',
    }
  }, [heroTrendPrev, heroStats.totalRemainingDebt])

  const monthlyTrendLabel = useMemo(() => {
    if (heroTrendPrev == null || typeof heroTrendPrev.monthly !== 'number') {
      return null
    }
    const d = heroStats.totalMonthlyDeductions - heroTrendPrev.monthly
    if (Math.abs(d) < 0.01) return 'Monthly load unchanged vs last snapshot'
    if (d < 0) return `↓ ${formatPhpWithSymbol(Math.abs(d))} less per month vs last snapshot`
    return `↑ ${formatPhpWithSymbol(d)} more per month vs last snapshot`
  }, [heroTrendPrev, heroStats.totalMonthlyDeductions])

  const requestsHistory = useMemo(() => {
    const list = Array.isArray(requests) ? [...requests] : []
    return list.sort((a, b) => {
      const ta = new Date(a.created_at || a.updated_at || 0).getTime()
      const tb = new Date(b.created_at || b.updated_at || 0).getTime()
      if (tb !== ta) return tb - ta
      return (b.id || 0) - (a.id || 0)
    })
  }, [requests])

  const filteredAssignEmployees = useMemo(() => {
    const q = employeeSearchQuery.trim().toLowerCase()
    const rows = assignEmployees || []
    if (!q) return rows
    return rows.filter((e) => {
      const id = String(e.id ?? '')
      const name = String(e.name ?? '').toLowerCase()
      const email = String(e.email ?? '').toLowerCase()
      const code = String(e.employee_code ?? e.employee_id ?? '').toLowerCase()
      return name.includes(q) || email.includes(q) || id.includes(q) || code.includes(q)
    })
  }, [assignEmployees, employeeSearchQuery])

  const selectedAssignEmployee = useMemo(() => {
    const uid = String(assignForm.user_id || '').trim()
    if (!uid) return null
    return assignEmployees.find((e) => String(e.id) === uid) || null
  }, [assignEmployees, assignForm.user_id])

  function openEarlyPayoffConfirm(userId, deductionId) {
    setPayoffTarget({ userId, deductionId })
    setPayoffConfirmOpen(true)
  }

  async function confirmEarlyPayoff() {
    if (!payoffTarget) return
    setPayoffLoading(true)
    try {
      await postAdminEmployeeDeductionEarlyPayoff(payoffTarget.userId, payoffTarget.deductionId)
      toast({ title: 'Loan closed successfully.' })
      setPayoffConfirmOpen(false)
      setPayoffTarget(null)
      await loadAll()
      window.dispatchEvent(new CustomEvent('hr:employee-deductions-changed'))
    } catch (e) {
      toast({ title: 'Early payoff failed', description: e.message, variant: 'destructive' })
    } finally {
      setPayoffLoading(false)
    }
  }

  async function handleAdjustBalance() {
    if (!amortTarget?.userId || !amortTarget?.deductionId) return
    const parsed = Number(adjustBalanceInput)
    if (!Number.isFinite(parsed) || parsed < 0) {
      toast({ title: 'Invalid amount', description: 'Enter a valid non-negative balance.', variant: 'destructive' })
      return
    }
    setAdjustingBalance(true)
    try {
      await patchAdminEmployeeDeductionBalance(amortTarget.userId, amortTarget.deductionId, parsed)
      toast({ title: 'Balance updated' })
      await loadAll()
      window.dispatchEvent(new CustomEvent('hr:employee-deductions-changed'))
    } catch (e) {
      toast({ title: 'Adjustment failed', description: e.message, variant: 'destructive' })
    } finally {
      setAdjustingBalance(false)
    }
  }

  useEffect(() => {
    loadAll()
  }, [loadAll])

  useEffect(() => {
    const onVisible = () => {
      if (document.visibilityState === 'visible') {
        loadAll({ silent: true })
      }
    }
    const onFocus = () => loadAll({ silent: true })
    const intervalId = window.setInterval(() => loadAll({ silent: true }), 30000)
    document.addEventListener('visibilitychange', onVisible)
    window.addEventListener('focus', onFocus)
    return () => {
      document.removeEventListener('visibilitychange', onVisible)
      window.removeEventListener('focus', onFocus)
      window.clearInterval(intervalId)
    }
  }, [loadAll])

  useEffect(() => {
    if (tab !== 'assign') return
    fetchAssignEmployees()
  }, [tab, fetchAssignEmployees])

  useEffect(() => {
    if (tab !== 'assign') return
    const onVis = () => {
      if (document.visibilityState === 'visible') fetchAssignEmployees()
    }
    document.addEventListener('visibilitychange', onVis)
    return () => document.removeEventListener('visibilitychange', onVis)
  }, [tab, fetchAssignEmployees])

  async function handleCreateType(e) {
    e.preventDefault()
    setSavingType(true)
    try {
      await createAdminDeductionType({
        name: typeForm.name.trim(),
        type: typeForm.type,
        with_interest: typeForm.type === 'loan' ? Boolean(typeForm.with_interest) : false,
        interest_rate_percent:
          typeForm.type === 'loan' && typeForm.with_interest
            ? (() => {
                const rate = Number(typeForm.interest_rate_percent || 0)
                return Number.isFinite(rate) ? Math.max(0, rate) : 0
              })()
            : null,
        interest_type: typeForm.type === 'loan' && typeForm.with_interest ? typeForm.interest_type || 'simple' : null,
      })
      toast({ title: 'Deduction type created' })
      setTypeDialog(false)
      setTypeForm({
        name: '',
        type: 'loan',
        with_interest: false,
        interest_rate_percent: '0',
        interest_type: 'simple',
      })
      await loadAll()
    } catch (err) {
      toast({ title: 'Error', description: err.message, variant: 'destructive' })
    } finally {
      setSavingType(false)
    }
  }

  function openRenameType(type) {
    if (!type) return
    setTypeTarget(type)
    setTypeRenameValue(String(type.name || ''))
    setTypeRenameDialog(true)
  }

  async function handleRenameType(e) {
    e.preventDefault()
    if (!typeTarget?.id) return
    const nextName = typeRenameValue.trim()
    if (!nextName) {
      toast({ title: 'Name is required', variant: 'destructive' })
      return
    }
    setSavingTypeRename(true)
    try {
      await updateAdminDeductionType(typeTarget.id, { name: nextName })
      toast({ title: 'Deduction type renamed' })
      setTypeRenameDialog(false)
      setTypeTarget(null)
      setTypeRenameValue('')
      await loadAll()
    } catch (err) {
      toast({ title: 'Rename failed', description: err.message, variant: 'destructive' })
    } finally {
      setSavingTypeRename(false)
    }
  }

  async function handleDeleteType() {
    if (!typeTarget?.id) return
    setDeletingType(true)
    try {
      await deleteAdminDeductionType(typeTarget.id)
      setTypes((prev) => prev.filter((t) => String(t.id) !== String(typeTarget.id)))
      toast({ title: 'Deduction type deleted' })
      setTypeDeleteDialog(false)
      setTypeTarget(null)
      await loadAll()
    } catch (err) {
      toast({ title: 'Delete failed', description: err.message, variant: 'destructive' })
    } finally {
      setDeletingType(false)
    }
  }

  async function openDetail(id) {
    setSelectedId(id)
    setDetailOpen(true)
    setDetailLoading(true)
    setInstallment('')
    setApproveNotes('')
    setRejectNote('')
    try {
      const data = await getAdminLoanRequestDetail(id)
      setDetail(data)
      const pref = data?.loan_request?.preferred_monthly_deduction
      if (pref != null && pref !== '') {
        setInstallment(String(pref))
      } else {
        const principal = Number(data?.loan_request?.requested_amount)
        const term = Number(data?.loan_request?.term_months)
        if (Number.isFinite(principal) && principal > 0 && Number.isFinite(term) && term > 0) {
          setInstallment(String(Math.round((principal / term) * 100) / 100))
        }
      }
    } catch (e) {
      toast({ title: 'Failed to load request', description: e.message, variant: 'destructive' })
    } finally {
      setDetailLoading(false)
    }
  }

  const stage = detail?.loan_request?.approval_stage
  const needsInstallment = stage === 'pending_second'

  async function handleApprove() {
    if (!selectedId) return
    setActing(true)
    try {
      await approveAdminLoanRequest(selectedId, {
        installment_amount: needsInstallment ? Number(installment) : undefined,
        notes: approveNotes.trim() || undefined,
      })
      toast({ title: 'Approval recorded' })
      setDetailOpen(false)
      await loadAll()
    } catch (e) {
      toast({ title: 'Approval failed', description: e.message, variant: 'destructive' })
    } finally {
      setActing(false)
    }
  }

  async function handleAssign(e) {
    e.preventDefault()
    const uid = Number(assignForm.user_id)
    if (!Number.isFinite(uid) || uid < 1) {
      toast({ title: 'Invalid employee ID', variant: 'destructive' })
      return
    }
    setAssigning(true)
    try {
      const body = {
        deduction_type_id: Number(assignForm.deduction_type_id),
        amount: Number(assignForm.amount),
        start_date: assignForm.start_date,
        remaining_balance:
          assignForm.remaining_balance === '' ? undefined : Number(assignForm.remaining_balance),
      }
      const pcRaw = String(assignForm.pay_component_id || '').trim()
      if (pcRaw) body.pay_component_id = Number(pcRaw)
      const ds = String(assignForm.deduction_schedule || '').trim()
      if (ds === '15th' || ds === '30th' || ds === 'both') body.deduction_schedule = ds

      await createAdminEmployeePayDeduction(uid, body)
      toast({ title: 'Deduction assigned' })
      setAssignForm((f) => ({
        ...f,
        amount: '',
        remaining_balance: '',
        pay_component_id: '',
        deduction_schedule: 'both',
      }))
      await loadAll()
      await fetchAssignEmployees()
    } catch (err) {
      toast({ title: 'Assign failed', description: err.message, variant: 'destructive' })
    } finally {
      setAssigning(false)
    }
  }

  async function handleReject() {
    if (!selectedId) return
    setActing(true)
    try {
      await rejectAdminLoanRequest(selectedId, { rejection_note: rejectNote.trim() || undefined })
      toast({ title: 'Request rejected' })
      setDetailOpen(false)
      await loadAll()
    } catch (e) {
      toast({ title: 'Reject failed', description: e.message, variant: 'destructive' })
    } finally {
      setActing(false)
    }
  }

  const selectedTypeName = types.find((t) => String(t.id) === String(assignForm.deduction_type_id))?.name

  const handleAdminLoanPlannerContinue = useCallback((payload) => {
    setAssignForm((f) => ({
      ...f,
      deduction_type_id: String(payload.deduction_type_id ?? ''),
      pay_component_id: payload.pay_component_id ? String(payload.pay_component_id) : '',
      amount: payload.amount != null ? String(payload.amount) : '',
      remaining_balance: payload.remaining_balance != null ? String(payload.remaining_balance) : '',
      deduction_schedule: payload.deduction_schedule || 'both',
    }))
    setTab('assign')
  }, [])

  const assignPreviewLive = useMemo(() => {
    const uid = String(assignForm.user_id || '').trim()
    const amtRaw = String(assignForm.amount || '').trim()
    const start = assignForm.start_date
    if (!uid || !amtRaw || !start) return { ready: false }
    const nAmt = Number(amtRaw)
    if (!Number.isFinite(nAmt) || nAmt <= 0) return { ready: false }
    let balanceLabel = null
    if (assignForm.remaining_balance !== '') {
      const nBal = Number(assignForm.remaining_balance)
      if (Number.isFinite(nBal) && nBal >= 0) balanceLabel = formatPhpWithSymbol(nBal)
    }
    const schedLabel = scheduleTypeFriendlyLabel(assignForm.deduction_schedule)
    return {
      ready: true,
      uid,
      amountLabel: formatPhpWithSymbol(nAmt),
      typeLabel: selectedTypeName || null,
      startLabel: formatDisplayDate(start),
      balanceLabel,
      scheduleLabel: schedLabel,
    }
  }, [
    assignForm.user_id,
    assignForm.amount,
    assignForm.remaining_balance,
    assignForm.start_date,
    assignForm.deduction_schedule,
    selectedTypeName,
  ])

  return (
    <TooltipProvider delayDuration={250}>
      <div className="min-h-screen w-full bg-background">
        <div className="w-full space-y-8 px-4 pb-20 pt-8 md:px-8 lg:px-10 md:pt-10">
          {/* Header */}
          <header className="flex flex-col gap-6 border-b border-border/60 pb-8 md:flex-row md:items-end md:justify-between">
            <div className="max-w-2xl space-y-3">
              <p className="text-xs font-semibold uppercase tracking-[0.2em] text-muted-foreground">Compensation</p>
              <h1 className="text-[1.75rem] font-semibold leading-tight tracking-tight text-[#0A0A0A] dark:text-slate-50 md:text-[2rem]">
                Deductions & loans
              </h1>
              <p className="text-[15px] leading-relaxed text-muted-foreground md:text-base">
                Manage payroll deductions, review loan requests, and sync with{' '}
                <Link
                  className="font-medium text-[#0A0A0A] underline decoration-border underline-offset-4 transition-colors hover:text-foreground dark:text-slate-100"
                  to={hrPanelPath(hrBase, 'compensation/employee-compensation')}
                >
                  employee compensation
                </Link>
                .
              </p>
            </div>
            <div className="flex shrink-0 flex-wrap items-center gap-2">
              {mayRequestLoan ? (
                <Button
                  type="button"
                  className={cn(BTN_PRIMARY, 'px-5')}
                  onClick={() => setRequestLoanOpen(true)}
                >
                  Request a loan
                </Button>
              ) : null}
              <Button
                type="button"
                variant="outline"
                size="sm"
                className={cn(BTN_SECONDARY, 'px-4')}
                onClick={loadAll}
                disabled={loading}
              >
                {loading ? <Loader2 className="size-4 animate-spin text-[#0A0A0A] dark:text-slate-200" aria-hidden /> : null}
                Refresh
              </Button>
            </div>
          </header>

          {/* Summary metrics */}
          <div className="grid gap-4 lg:grid-cols-2">
            <div className="rounded-2xl border border-border/60 bg-card p-6 shadow-sm dark:bg-card">
              <div className="flex flex-col gap-6 sm:flex-row sm:items-center sm:justify-between">
                <div className="min-w-0 space-y-2">
                  <p className="text-[11px] font-semibold uppercase tracking-[0.16em] text-muted-foreground">Total remaining debt</p>
                  <p className="text-4xl font-bold tabular-nums tracking-tight text-[#0A0A0A] dark:text-slate-50">
                    {loading ? '—' : formatPhpWithSymbol(heroStats.totalRemainingDebt)}
                  </p>
                  <p className="text-sm leading-relaxed text-muted-foreground">{remainingTrendLabel.primary}</p>
                  {remainingTrendLabel.sub ? <p className="text-xs text-muted-foreground">{remainingTrendLabel.sub}</p> : null}
                </div>
                <RepaymentRing pct={heroStats.overallRepaymentPct} known={heroStats.repaymentKnown && !loading} />
              </div>
              {!heroStats.repaymentKnown && !loading && heroStats.loanRowCount > 0 ? (
                <p className="mt-4 rounded-lg border border-dashed border-border bg-muted/30 px-3 py-2 text-xs text-muted-foreground">
                  Add total principal on loans to show org-wide repayment progress.
                </p>
              ) : null}
            </div>
            <div className="rounded-2xl border border-border/60 bg-card p-6 shadow-sm dark:bg-card">
              <div className="space-y-2">
                <p className="text-[11px] font-semibold uppercase tracking-[0.16em] text-muted-foreground">Total monthly deductions</p>
                <p className="text-4xl font-bold tabular-nums tracking-tight text-[#0A0A0A] dark:text-slate-50">
                  {loading ? '—' : formatPhpWithSymbol(heroStats.totalMonthlyDeductions)}
                </p>
                {monthlyTrendLabel ? (
                  <p className="text-sm text-muted-foreground">{monthlyTrendLabel}</p>
                ) : (
                  <p className="text-sm text-muted-foreground">Sum of monthly amounts across active deductions in scope.</p>
                )}
              </div>
              <div className="mt-5 flex flex-wrap gap-2">
                <span className="rounded-md border border-border bg-muted/40 px-3 py-1.5 text-xs font-medium text-[#0A0A0A] dark:text-slate-100">
                  Loans {loading ? '—' : formatPhpWithSymbol(heroStats.monthlyLoans)}
                </span>
                <span className="rounded-md border border-border bg-muted/40 px-3 py-1.5 text-xs font-medium text-[#0A0A0A] dark:text-slate-100">
                  Benefits {loading ? '—' : formatPhpWithSymbol(heroStats.monthlyBenefits)}
                </span>
                <span className="rounded-md border border-border bg-muted/40 px-3 py-1.5 text-xs font-medium text-[#0A0A0A] dark:text-slate-100">
                  Other {loading ? '—' : formatPhpWithSymbol(heroStats.monthlyOther)}
                </span>
              </div>
            </div>
          </div>

          <Tabs value={tab} onValueChange={setTab} className="space-y-8">
            <TabsList
              variant="line"
              className="h-auto w-full flex-wrap justify-start gap-0 rounded-none border-b border-border/70 bg-transparent p-0"
            >
              {[
                { id: 'overview', label: 'Overview' },
                { id: 'active', label: 'Active deductions' },
                { id: 'requests', label: 'Loan requests' },
                { id: 'assign', label: 'Manual assign' },
                { id: 'types', label: 'Deduction types' },
              ].map(({ id, label }) => (
                <TabsTrigger
                  key={id}
                  value={id}
                  className="relative rounded-none border-0 bg-transparent px-4 py-3.5 text-sm font-medium text-muted-foreground transition-colors focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-[#0A0A0A]/20 dark:focus-visible:ring-slate-400/30 data-[state=active]:font-semibold data-[state=active]:text-[#0A0A0A] data-[state=active]:dark:text-slate-50 data-[state=active]:after:absolute data-[state=active]:after:inset-x-2 data-[state=active]:after:bottom-0 data-[state=active]:after:h-0.5 data-[state=active]:after:rounded-full data-[state=active]:after:bg-[#0A0A0A] data-[state=active]:dark:after:bg-slate-100"
                >
                  {label}
                </TabsTrigger>
              ))}
            </TabsList>

            <TabsContent value="overview" className="mt-0 outline-none">
              <div className="flex flex-wrap items-center justify-between gap-4 rounded-2xl border border-border/60 bg-muted/30 px-5 py-4 text-[#0A0A0A] dark:text-slate-100">
                <div className="flex flex-wrap gap-3 text-sm font-medium">
                  <span>
                    {loading ? '—' : stats.active} active rows
                  </span>
                  <span className="text-muted-foreground">·</span>
                  <span>
                    {loading ? '—' : stats.pending} pending requests
                  </span>
                  <span className="text-muted-foreground">·</span>
                  <span>{loading ? '—' : formatPhpWithSymbol(stats.monthlyExposure)} / mo</span>
                </div>
                <p className="max-w-xl text-sm leading-relaxed text-muted-foreground">
                  Summary metrics above reflect org-wide totals. Use shortcuts and history below.
                </p>
              </div>
              <Card className={cn('mt-8', PREMIUM_CARD)}>
                <CardHeader className="border-b border-border/50 bg-muted/20 px-6 py-5">
                  <CardTitle className="text-lg font-semibold text-[#0A0A0A] dark:text-slate-50">Quick paths</CardTitle>
                  <CardDescription className="text-[15px]">Jump to the task you need most often.</CardDescription>
                </CardHeader>
                <CardContent className="grid gap-4 p-6 sm:grid-cols-2">
                  <button
                    type="button"
                    onClick={() => setTab('requests')}
                    className="rounded-2xl border border-border/60 bg-card p-5 text-left shadow-sm transition-colors hover:bg-muted/50 dark:bg-card"
                  >
                    <p className="text-base font-semibold text-[#0A0A0A] dark:text-slate-100">Review loan queue</p>
                    <p className="mt-1.5 text-sm leading-relaxed text-muted-foreground">Approve or reject employee submissions in one place.</p>
                  </button>
                  <button
                    type="button"
                    onClick={() => setTab('assign')}
                    className="rounded-2xl border border-border/60 bg-card p-5 text-left shadow-sm transition-colors hover:bg-muted/50 dark:bg-card"
                  >
                    <p className="text-base font-semibold text-[#0A0A0A] dark:text-slate-100">Assign a deduction</p>
                    <p className="mt-1.5 text-sm leading-relaxed text-muted-foreground">Add a manual recurring row for any employee.</p>
                  </button>
                </CardContent>
              </Card>

              <Card className={cn('mt-8', PREMIUM_CARD)}>
                <CardHeader className="border-b border-border/50 bg-muted/20 px-6 py-5">
                  <CardTitle className="text-lg font-semibold text-[#0A0A0A] dark:text-slate-50">Request history</CardTitle>
                  <CardDescription className="text-[15px]">Recent loan requests, newest first.</CardDescription>
                </CardHeader>
                <CardContent className="p-0">
                  {loading ? (
                    <div className="space-y-4 p-6">
                      {Array.from({ length: 4 }).map((_, i) => (
                        <Skeleton key={i} className="h-16 w-full rounded-xl" />
                      ))}
                    </div>
                  ) : requestsHistory.length === 0 ? (
                    <p className="p-6 text-sm text-muted-foreground">No loan requests recorded in your scope yet.</p>
                  ) : (
                    <div className="overflow-x-auto">
                      <Table className="min-w-[760px]">
                        <TableHeader className="border-b border-border/60 bg-muted/30">
                          <TableRow className="hover:bg-transparent">
                            <TableHead className="w-[72px] pl-6 text-xs font-semibold uppercase tracking-wide text-muted-foreground">
                              <span className="sr-only">Photo</span>
                            </TableHead>
                            <TableHead className="min-w-[200px] text-xs font-semibold uppercase tracking-wide text-muted-foreground">
                              Employee
                            </TableHead>
                            <TableHead className="text-right text-xs font-semibold uppercase tracking-wide text-muted-foreground">
                              Principal
                            </TableHead>
                            <TableHead className="min-w-[140px] text-xs font-semibold uppercase tracking-wide text-muted-foreground">
                              Product
                            </TableHead>
                            <TableHead className="text-xs font-semibold uppercase tracking-wide text-muted-foreground">Status</TableHead>
                            <TableHead className="pr-6 text-xs font-semibold uppercase tracking-wide text-muted-foreground">
                              Submitted
                            </TableHead>
                          </TableRow>
                        </TableHeader>
                        <TableBody>
                          {requestsHistory.slice(0, 12).map((r) => {
                            const st = String(r.status || '').toLowerCase()
                            const uid = r.user_id ?? r.user?.id
                            const name = r.user?.name || 'Employee'
                            const avatarSrc = userProfileImageSrc(r.user)
                            const when = r.created_at || r.updated_at
                            const empLink = uid != null ? hrPanelPath(hrBase, `employees/${uid}`) : null
                            const product = r.pay_component?.name || r.deduction_type?.name || 'Loan'
                            return (
                              <TableRow
                                key={r.id}
                                className={cn(PREMIUM_ROW, 'cursor-pointer')}
                                onClick={() => openDetail(r.id)}
                              >
                                <TableCell className="pl-6 align-middle">
                                  <Avatar className="size-10 shrink-0 rounded-full border border-border bg-muted">
                                    <AvatarImage src={avatarSrc || undefined} alt="" className="object-cover" />
                                    <AvatarFallback
                                      className={`rounded-full text-xs font-semibold ${getAvatarColor(uid, name)}`}
                                    >
                                      {initials(name)}
                                    </AvatarFallback>
                                  </Avatar>
                                </TableCell>
                                <TableCell className="align-middle">
                                  {empLink ? (
                                    <Link
                                      to={empLink}
                                      className="font-semibold text-[#0A0A0A] underline-offset-4 hover:underline dark:text-slate-100"
                                      onClick={(e) => e.stopPropagation()}
                                    >
                                      {name}
                                    </Link>
                                  ) : (
                                    <span className="font-semibold text-[#0A0A0A] dark:text-slate-100">{name}</span>
                                  )}
                                  {uid != null ? (
                                    <p className="mt-0.5 font-mono text-xs text-muted-foreground">User ID {uid}</p>
                                  ) : null}
                                </TableCell>
                                <TableCell className="text-right align-middle tabular-nums font-semibold text-[#0A0A0A] dark:text-slate-100">
                                  {formatPhpWithSymbol(r.requested_amount)}
                                </TableCell>
                                <TableCell className="align-middle text-sm text-[#0A0A0A] dark:text-slate-200">{product}</TableCell>
                                <TableCell className="align-middle">
                                  <span
                                    className={cn(
                                      'inline-flex rounded-full border px-2.5 py-0.5 text-[11px] font-semibold uppercase tracking-wide',
                                      'border-border bg-muted text-[#0A0A0A] dark:text-slate-100',
                                    )}
                                  >
                                    {st || '—'}
                                  </span>
                                </TableCell>
                                <TableCell className="pr-6 align-middle text-sm text-muted-foreground">
                                  {when ? formatDisplayDate(when) : '—'}
                                </TableCell>
                              </TableRow>
                            )
                          })}
                        </TableBody>
                      </Table>
                    </div>
                  )}
                </CardContent>
              </Card>
            </TabsContent>

            <TabsContent value="active" className="mt-0 outline-none">
              <Card className={PREMIUM_CARD}>
                <CardHeader className="space-y-1 border-b border-border/50 bg-muted/20 px-6 py-5">
                  <CardTitle className="text-xl font-semibold tracking-tight text-[#0A0A0A] dark:text-slate-50">
                    Active loans & deductions
                  </CardTitle>
                  <CardDescription className="text-base text-muted-foreground">
                    Scoped to your access. Principal progress uses loan balance and schedule data when available.
                  </CardDescription>
                </CardHeader>
                <CardContent className="p-0">
                  <div className="relative w-full min-w-0 max-w-full overflow-x-hidden">
                    <div className="relative max-h-[min(75vh,820px)] w-full min-w-0 overflow-y-auto rounded-b-2xl">
                      <Table className="w-full min-w-0 table-fixed">
                        <TableHeader className="sticky top-0 z-10 border-b border-border/50 bg-card/95 shadow-[0_8px_24px_-12px_rgba(15,23,42,0.08)] backdrop-blur-md dark:bg-card/90">
                          <TableRow className="border-0 hover:bg-transparent">
                            <TableHead className="w-[4%] min-w-0 pl-4 text-[11px] font-bold uppercase tracking-wider text-muted-foreground xl:pl-6">
                              <span className="sr-only">Avatar</span>
                            </TableHead>
                            <TableHead className="w-[13%] min-w-0 py-3.5 text-[11px] font-bold uppercase tracking-wider text-muted-foreground">
                              Employee
                            </TableHead>
                            <TableHead className="w-[12%] min-w-0 py-3.5 text-[11px] font-bold uppercase tracking-wider text-muted-foreground">
                              Loan / deduction
                            </TableHead>
                            <TableHead className="w-[6%] min-w-0 py-3.5 text-[11px] font-bold uppercase tracking-wider text-muted-foreground">
                              Type
                            </TableHead>
                            <TableHead className="w-[11%] min-w-0 py-3.5 text-[11px] font-bold uppercase tracking-wider text-muted-foreground">
                              Schedule type
                            </TableHead>
                            <TableHead className="w-[8%] min-w-0 py-3.5 text-right text-[11px] font-bold uppercase tracking-wider text-muted-foreground">
                              Monthly
                            </TableHead>
                            <TableHead className="w-[22%] min-w-0 py-3.5 text-[11px] font-bold uppercase tracking-wider text-muted-foreground">
                              Remaining
                            </TableHead>
                            <TableHead className="w-[10%] min-w-0 py-3.5 text-[11px] font-bold uppercase tracking-wider text-muted-foreground">
                              Next due
                            </TableHead>
                            <TableHead className="w-[8%] min-w-0 py-3.5 text-[11px] font-bold uppercase tracking-wider text-muted-foreground">
                              Status
                            </TableHead>
                            <TableHead className="w-[6%] min-w-0 py-3.5 pr-3 text-right text-[11px] font-bold uppercase tracking-wider text-muted-foreground">
                              <span className="sr-only">Actions</span>
                              <span className="hidden sm:inline">Actions</span>
                            </TableHead>
                          </TableRow>
                        </TableHeader>
                        <TableBody>
                          {loading ? (
                            <TableSkeletonRows cols={10} rows={6} />
                          ) : (
                            activeDeductions.map((row) => {
                              const sched = row.amortization_schedule || row.amortizationSchedule || []
                              const title = productTypeLabel(row)
                              const rem = row.remaining_balance != null ? Number(row.remaining_balance) : null
                              const name = row.user?.name || `User #${row.user_id}`
                              const avatarSrc = userProfileImageSrc(row.user)
                              const nextDue = row.next_due_date
                              const empPath = hrPanelPath(hrBase, `employees/${row.user_id}`)
                              const subtitle =
                                row.user?.position ||
                                row.user?.job_title ||
                                (typeof row.user?.department === 'string'
                                  ? row.user.department
                                  : row.user?.department?.name) ||
                                ''
                              const loanProg = getLoanProgressForRow(row)
                              const dueDiff = daysFromToday(nextDue)
                              const urgencyClass = nextDueUrgencyBadgeClass(dueDiff)
                              const pctW = progressBarsReady && loanProg ? loanProg.pctPaid : 0
                              const kind = deductionKindKey(row)
                              const kindMeta = typeKindMeta(kind)
                              const statusLabel = rowDeductionStatusLabel(row)
                              const statusBadgeClass = rowDeductionStatusBadgeClass(row)
                              const hasSchedule = Array.isArray(sched) && sched.length > 0

                              return (
                                <TableRow key={row.id} className={PREMIUM_ROW}>
                                  <TableCell className="min-w-0 pl-4 align-middle xl:pl-6">
                                    <Link to={empPath} className="inline-flex" aria-label={name}>
                                      <Avatar className="size-9 shrink-0 rounded-full border border-border bg-muted">
                                        <AvatarImage src={avatarSrc || undefined} alt="" className="object-cover" />
                                        <AvatarFallback
                                          className={`rounded-full text-[11px] font-semibold ${getAvatarColor(row.user_id, name)}`}
                                        >
                                          {initials(name)}
                                        </AvatarFallback>
                                      </Avatar>
                                    </Link>
                                  </TableCell>
                                  <TableCell className="min-w-0 align-middle">
                                    <Link to={empPath} className="block min-w-0 hover:opacity-90">
                                      <span className="block truncate font-semibold text-[#0A0A0A] dark:text-slate-100">{name}</span>
                                      {subtitle ? (
                                        <span className="mt-0.5 block truncate text-xs text-muted-foreground">{subtitle}</span>
                                      ) : null}
                                    </Link>
                                  </TableCell>
                                  <TableCell className="min-w-0 align-middle">
                                    <span className="line-clamp-2 break-words text-sm font-medium text-[#0A0A0A] dark:text-slate-100">{title}</span>
                                  </TableCell>
                                  <TableCell className="min-w-0 align-middle">
                                    <Badge variant="outline" className={cn('rounded-md text-[11px] font-semibold', kindMeta.chip)}>
                                      {kindMeta.label}
                                    </Badge>
                                  </TableCell>
                                  <TableCell className="min-w-0 align-middle">
                                    <span className="line-clamp-2 text-xs font-medium leading-snug text-[#0A0A0A] dark:text-slate-100 xl:text-sm">
                                      {scheduleTypeFriendlyLabel(row.deduction_schedule)}
                                    </span>
                                  </TableCell>
                                  <TableCell className="min-w-0 text-right align-middle tabular-nums text-sm font-semibold text-[#0A0A0A] dark:text-slate-100">
                                    {formatPhpWithSymbol(row.amount)}
                                  </TableCell>
                                  <TableCell className="min-w-0 align-middle">
                                    <div className="flex min-w-0 max-w-full flex-col gap-1">
                                      <span className="font-semibold tabular-nums text-[#0A0A0A] dark:text-slate-50">
                                        {rem != null ? formatPhpWithSymbol(rem) : '—'}
                                      </span>
                                      {rowIsLoan(row) && loanProg ? (
                                        <>
                                          <div className="h-1.5 w-full overflow-hidden rounded-full bg-muted/80">
                                            <div
                                              className="h-full rounded-full bg-[#0A0A0A] transition-[width] duration-700 ease-out dark:bg-slate-100"
                                              style={{ width: `${pctW}%` }}
                                            />
                                          </div>
                                          <span className="line-clamp-2 text-[10px] font-medium tabular-nums text-[#0A0A0A]/80 dark:text-slate-300 xl:text-[11px]">
                                            {loanProg.pctPaid}% repaid
                                            {loanProg.total != null ? ` · ${formatPhpWithSymbol(loanProg.total)} principal` : ''}
                                          </span>
                                        </>
                                      ) : (
                                        <span className="text-[11px] text-muted-foreground">—</span>
                                      )}
                                    </div>
                                  </TableCell>
                                  <TableCell className="min-w-0 align-middle">
                                    {nextDue ? (
                                      <div className="flex min-w-0 flex-col gap-1.5">
                                        <span className="truncate text-sm font-medium text-[#0A0A0A] dark:text-slate-100">
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
                                  <TableCell className="min-w-0 align-middle">
                                    <Badge variant="outline" className={cn('max-w-full truncate rounded-full text-[11px] font-semibold', statusBadgeClass)}>
                                      {statusLabel}
                                    </Badge>
                                  </TableCell>
                                  <TableCell className="min-w-0 pr-2 text-right align-middle sm:pr-4">
                                    <div className="flex justify-end">
                                      <DropdownMenu>
                                        <DropdownMenuTrigger asChild>
                                          <Button
                                            type="button"
                                            variant="outline"
                                            size="icon"
                                            className={cn(BTN_SECONDARY, 'size-8 shrink-0 text-[#0A0A0A] dark:text-slate-100')}
                                            aria-label={`Actions for ${title}`}
                                          >
                                            <MoreHorizontal className="size-4" aria-hidden />
                                          </Button>
                                        </DropdownMenuTrigger>
                                        <DropdownMenuContent align="end" className="min-w-[12rem]">
                                          {hasSchedule ? (
                                            <DropdownMenuItem
                                              className="cursor-pointer text-[#0A0A0A] dark:text-slate-100"
                                              onSelect={() => {
                                                setAmortTitle(title)
                                                setAmortRows(sched)
                                                setAmortTarget({ userId: row.user_id, deductionId: row.id })
                                                setAdjustBalanceInput(
                                                  row.remaining_balance != null ? String(Number(row.remaining_balance).toFixed(2)) : ''
                                                )
                                                setAmortOpen(true)
                                              }}
                                            >
                                              <CalendarDays className="size-4" aria-hidden />
                                              View schedule
                                            </DropdownMenuItem>
                                          ) : null}
                                          {row.is_amortized ? (
                                            <DropdownMenuItem
                                              variant="destructive"
                                              className="cursor-pointer"
                                              disabled={payoffLoading}
                                              onSelect={() => openEarlyPayoffConfirm(row.user_id, row.id)}
                                            >
                                              <Wallet className="size-4" aria-hidden />
                                              Pay off
                                            </DropdownMenuItem>
                                          ) : null}
                                          <DropdownMenuItem
                                            className="cursor-pointer text-[#0A0A0A] dark:text-slate-100"
                                            onSelect={() =>
                                              toast({
                                                title: 'Edit deduction',
                                                description:
                                                  'Schedule editing from this grid will be available in a future update.',
                                              })
                                            }
                                          >
                                            <Pencil className="size-4" aria-hidden />
                                            Edit
                                          </DropdownMenuItem>
                                        </DropdownMenuContent>
                                      </DropdownMenu>
                                    </div>
                                  </TableCell>
                                </TableRow>
                              )
                            })
                          )}
                        </TableBody>
                      </Table>
                    </div>
                  </div>
                  {!loading && activeDeductions.length === 0 ? (
                    <div className="flex flex-col items-center gap-4 border-t border-border/50 px-6 py-16 text-center">
                      <p className="text-lg font-semibold text-[#0A0A0A] dark:text-slate-50">No active deductions</p>
                      <p className="max-w-md text-sm text-muted-foreground">
                        Nothing in your scope yet. Use Request a loan to plan amounts, or assign a deduction manually.
                      </p>
                      <div className="flex flex-wrap items-center justify-center gap-2">
                        {mayRequestLoan ? (
                          <Button
                            type="button"
                            className={cn(BTN_PRIMARY, 'px-6')}
                            onClick={() => setRequestLoanOpen(true)}
                          >
                            Request a loan
                          </Button>
                        ) : null}
                        <Button
                          type="button"
                          variant="outline"
                          className={cn(BTN_SECONDARY, 'px-6')}
                          onClick={() => setTab('assign')}
                        >
                          Manual assign
                        </Button>
                      </div>
                    </div>
                  ) : null}
                </CardContent>
              </Card>
            </TabsContent>

            <TabsContent value="requests" className="mt-0 outline-none">
              <Card className={PREMIUM_CARD}>
                <CardHeader className="space-y-1 border-b border-border/50 bg-muted/20 px-6 py-6">
                  <div className="flex flex-wrap items-center gap-2">
                    <CardTitle className="text-xl font-semibold tracking-tight text-[#0A0A0A] dark:text-slate-50">
                      Loan requests
                    </CardTitle>
                    <Badge variant="secondary" className="rounded-md border border-border bg-muted font-medium text-[#0A0A0A] dark:text-slate-100">
                      {loading ? '—' : `${pendingLoanRequests.length} pending`}
                    </Badge>
                  </div>
                  <CardDescription className="text-base text-muted-foreground">
                    All requests in your scope, newest first. Open a row for full details, timeline, and approval actions when
                    applicable.
                  </CardDescription>
                </CardHeader>
                <CardContent className="p-0">
                  <div className="relative max-h-[min(72vh,760px)] w-full min-w-0 overflow-x-auto overflow-y-auto rounded-b-2xl">
                    <Table className="w-full min-w-[1120px]">
                      <TableHeader className="sticky top-0 z-10 border-b border-border/50 bg-card/95 shadow-[0_8px_24px_-12px_rgba(15,23,42,0.08)] backdrop-blur-md dark:bg-card/90">
                        <TableRow className="border-0 hover:bg-transparent">
                          <TableHead className="w-[56px] pl-6 text-[11px] font-bold uppercase tracking-wider text-muted-foreground">
                            <span className="sr-only">Avatar</span>
                          </TableHead>
                          <TableHead className="min-w-[180px] text-[11px] font-bold uppercase tracking-wider text-muted-foreground">
                            Employee
                          </TableHead>
                          <TableHead className="text-right text-[11px] font-bold uppercase tracking-wider text-muted-foreground">
                            Requested
                          </TableHead>
                          <TableHead className="text-right text-[11px] font-bold uppercase tracking-wider text-muted-foreground">
                            Pref. monthly
                          </TableHead>
                          <TableHead className="text-right text-[11px] font-bold uppercase tracking-wider text-muted-foreground">
                            Term
                          </TableHead>
                          <TableHead className="min-w-[140px] text-[11px] font-bold uppercase tracking-wider text-muted-foreground">
                            Schedule
                          </TableHead>
                          <TableHead className="min-w-[100px] text-[11px] font-bold uppercase tracking-wider text-muted-foreground">
                            Status
                          </TableHead>
                          <TableHead className="min-w-[140px] text-[11px] font-bold uppercase tracking-wider text-muted-foreground">
                            Requested date
                          </TableHead>
                          <TableHead className="min-w-[100px] pr-6 text-right text-[11px] font-bold uppercase tracking-wider text-muted-foreground">
                            Actions
                          </TableHead>
                        </TableRow>
                      </TableHeader>
                      <TableBody>
                        {loading ? (
                          <TableSkeletonRows cols={9} rows={5} />
                        ) : (
                          requestsHistory.map((r) => {
                            const uid = r.user_id ?? r.user?.id
                            const empLink = hrPanelPath(hrBase, `employees/${uid}`)
                            const name = r.user?.name || '—'
                            const reqAvatarSrc = userProfileImageSrc(r.user)
                            const pref =
                              r.preferred_monthly_deduction != null && r.preferred_monthly_deduction !== ''
                                ? formatPhpWithSymbol(r.preferred_monthly_deduction)
                                : '—'
                            const st = String(r.status || '').toLowerCase()
                            const statusLabel =
                              st === 'pending' ? 'Pending' : st === 'approved' ? 'Approved' : st === 'rejected' ? 'Rejected' : r.status || '—'
                            const when = r.created_at || r.updated_at
                            return (
                              <TableRow
                                key={r.id}
                                className={cn(PREMIUM_ROW, 'cursor-pointer')}
                                onClick={() => openDetail(r.id)}
                              >
                                <TableCell className="pl-6 align-middle">
                                  <Avatar className="size-10 shrink-0 rounded-full border border-border bg-muted">
                                    <AvatarImage src={reqAvatarSrc || undefined} alt="" className="object-cover" />
                                    <AvatarFallback className={`rounded-full text-xs font-bold ${getAvatarColor(uid, name)}`}>
                                      {initials(name)}
                                    </AvatarFallback>
                                  </Avatar>
                                </TableCell>
                                <TableCell className="min-w-0 align-middle">
                                  {empLink ? (
                                    <Link
                                      to={empLink}
                                      className="block min-w-0 font-semibold text-[#0A0A0A] underline-offset-4 hover:underline dark:text-slate-100"
                                      onClick={(e) => e.stopPropagation()}
                                    >
                                      <span className="block truncate">{name}</span>
                                    </Link>
                                  ) : (
                                    <span className="block truncate font-semibold text-[#0A0A0A] dark:text-slate-100">{name}</span>
                                  )}
                                  {uid != null ? (
                                    <p className="mt-0.5 font-mono text-[11px] text-muted-foreground">ID {uid}</p>
                                  ) : null}
                                </TableCell>
                                <TableCell className="text-right align-middle tabular-nums text-sm font-semibold text-[#0A0A0A] dark:text-slate-100">
                                  {formatPhpWithSymbol(r.requested_amount)}
                                </TableCell>
                                <TableCell className="text-right align-middle tabular-nums text-sm text-[#0A0A0A] dark:text-slate-100">
                                  {pref}
                                </TableCell>
                                <TableCell className="text-right align-middle tabular-nums text-sm text-[#0A0A0A] dark:text-slate-100">
                                  {r.term_months != null && r.term_months !== '' ? `${r.term_months} mo` : '—'}
                                </TableCell>
                                <TableCell className="align-middle">
                                  <span className="text-sm font-medium leading-snug text-[#0A0A0A] dark:text-slate-100">
                                    {scheduleTypeFriendlyLabel(r.deduction_schedule)}
                                  </span>
                                </TableCell>
                                <TableCell className="align-middle">
                                  <Badge
                                    variant="outline"
                                    className={cn(
                                      'rounded-full text-[11px] font-semibold capitalize',
                                      loanRequestStatusBadgeClass(st),
                                    )}
                                  >
                                    {statusLabel}
                                  </Badge>
                                </TableCell>
                                <TableCell className="align-middle text-sm tabular-nums text-[#0A0A0A]/90 dark:text-slate-200">
                                  {when ? formatDateTime(when) : '—'}
                                </TableCell>
                                <TableCell className="pr-6 text-right align-middle">
                                  <Button
                                    type="button"
                                    variant="outline"
                                    size="sm"
                                    className={cn(BTN_SECONDARY, 'h-8 rounded-md px-3 text-xs')}
                                    onClick={(e) => {
                                      e.stopPropagation()
                                      openDetail(r.id)
                                    }}
                                  >
                                    View
                                  </Button>
                                </TableCell>
                              </TableRow>
                            )
                          })
                        )}
                      </TableBody>
                    </Table>
                  </div>
                  {!loading && requestsHistory.length === 0 ? (
                    <div className="flex flex-col items-center gap-5 border-t border-border/40 px-6 py-20 text-center">
                      <div>
                        <p className="text-xl font-semibold text-[#0A0A0A] dark:text-slate-50">No loan requests yet</p>
                        <p className="mt-2 max-w-md text-sm leading-relaxed text-muted-foreground">
                          No loan requests in your scope. When employees submit requests, they will appear here.
                        </p>
                      </div>
                      <Button
                        type="button"
                        variant="outline"
                        className={cn(BTN_SECONDARY, 'rounded-md px-8')}
                        onClick={() => setTab('active')}
                      >
                        View active deductions
                      </Button>
                    </div>
                  ) : null}
                </CardContent>
              </Card>
            </TabsContent>

            <TabsContent value="assign" className="mt-0 outline-none">
              <div className="grid gap-8 lg:grid-cols-2 lg:items-start">
                <Card className={PREMIUM_CARD}>
                  <CardHeader className="border-b border-border/50 bg-muted/20 px-6 py-5">
                    <CardTitle className="text-lg font-semibold text-[#0A0A0A] dark:text-slate-50">Assignment details</CardTitle>
                    <CardDescription className="text-[15px]">Amounts in Philippine Peso (₱).</CardDescription>
                  </CardHeader>
                  <CardContent className="p-6">
                    <form className="space-y-8" onSubmit={handleAssign}>
                      <div className="space-y-4 rounded-2xl border border-border/60 bg-muted/20 p-5 dark:bg-white/[0.03]">
                        <div className="flex flex-wrap items-center justify-between gap-2">
                          <p className="text-[11px] font-semibold uppercase tracking-[0.12em] text-muted-foreground">Employee</p>
                          <Button
                            type="button"
                            variant="ghost"
                            size="sm"
                            className="h-8 text-xs text-[#0A0A0A] hover:bg-muted hover:text-[#0A0A0A] dark:text-slate-200 dark:hover:text-slate-100"
                            onClick={() => fetchAssignEmployees()}
                            disabled={assignEmployeesLoading}
                          >
                            {assignEmployeesLoading ? (
                              <Loader2 className="size-3.5 animate-spin text-[#0A0A0A] dark:text-slate-200" aria-hidden />
                            ) : null}
                            Refresh list
                          </Button>
                        </div>
                        <div className="grid gap-4 sm:grid-cols-2">
                          <div className="space-y-2 sm:col-span-2">
                            <Label className="text-foreground/90">Select employee</Label>
                            <Popover
                              open={employeePickerOpen}
                              onOpenChange={(open) => {
                                setEmployeePickerOpen(open)
                                if (!open) setEmployeeSearchQuery('')
                              }}
                            >
                              <PopoverTrigger asChild>
                                <button
                                  type="button"
                                  className={cn(
                                    BTN_SECONDARY,
                                    'flex min-h-12 w-full items-center justify-between gap-3 rounded-2xl px-4 py-2.5 text-left',
                                  )}
                                >
                                  <span className="flex min-w-0 flex-1 items-center gap-3">
                                    {selectedAssignEmployee ? (
                                      <>
                                        <Avatar className="size-10 shrink-0 rounded-full border border-border bg-muted">
                                          <AvatarImage
                                            src={userProfileImageSrc(selectedAssignEmployee) || undefined}
                                            alt=""
                                            className="object-cover"
                                          />
                                          <AvatarFallback
                                            className={`rounded-full text-xs font-semibold ${getAvatarColor(
                                              selectedAssignEmployee.id,
                                              selectedAssignEmployee.name,
                                            )}`}
                                          >
                                            {initials(selectedAssignEmployee.name)}
                                          </AvatarFallback>
                                        </Avatar>
                                        <span className="min-w-0">
                                          <span className="block truncate font-medium text-[#0A0A0A] dark:text-slate-100">
                                            {selectedAssignEmployee.name}
                                          </span>
                                          <span className="font-mono text-xs tabular-nums text-muted-foreground">
                                            User ID {selectedAssignEmployee.id}
                                          </span>
                                        </span>
                                      </>
                                    ) : assignForm.user_id ? (
                                      <span className="min-w-0">
                                        <span className="block font-medium text-[#0A0A0A] dark:text-slate-100">Manual User ID</span>
                                        <span className="font-mono text-sm tabular-nums text-muted-foreground">
                                          {assignForm.user_id}
                                        </span>
                                      </span>
                                    ) : (
                                      <span className="text-muted-foreground">Search by name, email, or ID…</span>
                                    )}
                                  </span>
                                  <ChevronRight
                                    className={cn(
                                      'size-4 shrink-0 text-[#0A0A0A]/70 transition-transform dark:text-slate-300',
                                      employeePickerOpen && 'rotate-90',
                                    )}
                                    aria-hidden
                                  />
                                </button>
                              </PopoverTrigger>
                              <PopoverContent className="w-[min(100vw-2rem,28rem)] p-0" align="start">
                                <div className="border-b border-border/60 p-3">
                                  <div className="relative">
                                    <Search
                                      className="absolute left-3 top-1/2 size-4 -translate-y-1/2 text-[#0A0A0A]/55 dark:text-slate-400"
                                      aria-hidden
                                    />
                                    <Input
                                      value={employeeSearchQuery}
                                      onChange={(e) => setEmployeeSearchQuery(e.target.value)}
                                      placeholder="Search name, email, or user ID…"
                                      className="h-10 border-border/60 bg-background pl-9"
                                      autoFocus
                                    />
                                  </div>
                                </div>
                                <div className="max-h-72 overflow-y-auto p-2">
                                  {assignEmployeesLoading ? (
                                    <div className="flex items-center justify-center gap-2 py-10 text-sm text-muted-foreground">
                                      <Loader2 className="size-4 animate-spin text-[#0A0A0A] dark:text-slate-200" aria-hidden />
                                      Loading employees…
                                    </div>
                                  ) : filteredAssignEmployees.length === 0 ? (
                                    <p className="px-3 py-8 text-center text-sm text-muted-foreground">
                                      {assignEmployees.length === 0 ? 'No employees in scope.' : 'No matches.'}
                                    </p>
                                  ) : (
                                    filteredAssignEmployees.map((emp) => {
                                      const picked = String(assignForm.user_id) === String(emp.id)
                                      return (
                                        <button
                                          key={emp.id}
                                          type="button"
                                          onClick={() => {
                                            setAssignForm((f) => ({ ...f, user_id: String(emp.id) }))
                                            setEmployeePickerOpen(false)
                                            setEmployeeSearchQuery('')
                                          }}
                                          className={cn(
                                            'flex w-full items-center gap-3 rounded-xl px-3 py-2.5 text-left transition-colors',
                                            picked ? 'bg-muted/80' : 'hover:bg-muted/50',
                                          )}
                                        >
                                          <Avatar className="size-9 shrink-0 rounded-full border border-border bg-muted">
                                            <AvatarImage src={userProfileImageSrc(emp) || undefined} alt="" className="object-cover" />
                                            <AvatarFallback
                                              className={`rounded-full text-[11px] font-semibold ${getAvatarColor(emp.id, emp.name)}`}
                                            >
                                              {initials(emp.name)}
                                            </AvatarFallback>
                                          </Avatar>
                                          <span className="min-w-0 flex-1">
                                            <span className="block truncate text-sm font-medium text-[#0A0A0A] dark:text-slate-100">
                                              {emp.name}
                                            </span>
                                            <span className="truncate text-xs text-muted-foreground">{emp.email || '—'}</span>
                                            <span className="mt-0.5 block font-mono text-[11px] text-muted-foreground">
                                              User ID {emp.id}
                                            </span>
                                          </span>
                                        </button>
                                      )
                                    })
                                  )}
                                </div>
                              </PopoverContent>
                            </Popover>
                            <div className="flex flex-wrap items-center justify-between gap-2 rounded-xl border border-dashed border-border/60 bg-background/80 px-3 py-2">
                              <span className="text-xs text-muted-foreground">User ID (live)</span>
                              <span className="font-mono text-sm font-semibold tabular-nums text-[#0A0A0A] dark:text-slate-100">
                                {assignForm.user_id ? assignForm.user_id : '—'}
                              </span>
                            </div>
                            <div className="space-y-1.5">
                              <Label htmlFor="as-uid-manual" className="text-xs text-muted-foreground">
                                Or type User ID manually
                              </Label>
                              <Input
                                id="as-uid-manual"
                                type="text"
                                inputMode="numeric"
                                autoComplete="off"
                                className="h-11 rounded-2xl border-border/80 bg-background font-mono text-base tabular-nums"
                                value={assignForm.user_id}
                                onChange={(e) => {
                                  const v = e.target.value.replace(/\D/g, '')
                                  setAssignForm((f) => ({ ...f, user_id: v }))
                                }}
                                placeholder="e.g. 42"
                              />
                            </div>
                          </div>
                          <div className="space-y-2 sm:col-span-2">
                            <Label className="text-foreground/90">Deduction type</Label>
                            <Select
                              value={assignForm.deduction_type_id}
                              onValueChange={(v) => setAssignForm((f) => ({ ...f, deduction_type_id: v }))}
                              required
                            >
                              <SelectTrigger className="h-12 rounded-2xl border-border/80 text-[15px] text-[#0A0A0A] dark:text-slate-100 [&_svg]:text-[#0A0A0A] dark:[&_svg]:text-slate-100">
                                <SelectValue placeholder="Select type" />
                              </SelectTrigger>
                              <SelectContent>
                                {activeTypes.map((t) => (
                                  <SelectItem key={t.id} value={String(t.id)}>
                                    {t.name} ({t.type})
                                  </SelectItem>
                                ))}
                              </SelectContent>
                            </Select>
                          </div>
                          {assignForm.pay_component_id ? (
                            <div className="flex flex-wrap items-center justify-between gap-2 rounded-xl border border-border/60 bg-background/80 px-3 py-2 sm:col-span-2">
                              <span className="text-xs text-muted-foreground">
                                Linked pay component{' '}
                                <span className="font-mono font-semibold text-[#0A0A0A] dark:text-slate-100">
                                  #{assignForm.pay_component_id}
                                </span>
                              </span>
                              <Button
                                type="button"
                                variant="ghost"
                                size="sm"
                                className="h-8 text-xs"
                                onClick={() => setAssignForm((f) => ({ ...f, pay_component_id: '' }))}
                              >
                                Clear
                              </Button>
                            </div>
                          ) : null}
                          <div className="space-y-2 sm:col-span-2">
                            <Label className="text-foreground/90">Schedule type</Label>
                            <Select
                              value={assignForm.deduction_schedule || 'both'}
                              onValueChange={(v) => setAssignForm((f) => ({ ...f, deduction_schedule: v }))}
                            >
                              <SelectTrigger className="h-12 rounded-2xl border-border/80 text-[15px] text-[#0A0A0A] dark:text-slate-100 [&_svg]:text-[#0A0A0A] dark:[&_svg]:text-slate-100">
                                <SelectValue placeholder="Schedule" />
                              </SelectTrigger>
                              <SelectContent>
                                <SelectItem value="15th">First semi-monthly run (15th)</SelectItem>
                                <SelectItem value="30th">End of month</SelectItem>
                                <SelectItem value="both">50/50 split (both runs)</SelectItem>
                              </SelectContent>
                            </Select>
                            <p className="text-xs text-muted-foreground">
                              Matches deduction schedule settings; split amounts apply to semi-monthly pay runs.
                            </p>
                          </div>
                        </div>
                      </div>
                      <Separator className="bg-border/60" />
                      <div className="space-y-4 rounded-2xl border border-border/60 bg-muted/20 p-5 dark:bg-white/[0.03]">
                        <p className="text-[11px] font-semibold uppercase tracking-[0.12em] text-muted-foreground">
                          Amounts
                        </p>
                        <div className="grid gap-4 sm:grid-cols-2">
                          <div className="space-y-2">
                            <Label htmlFor="as-amt" className="text-foreground/90">
                              Monthly amount
                            </Label>
                            <div className="relative flex items-stretch">
                              <span className="pointer-events-none absolute left-4 top-1/2 z-[1] -translate-y-1/2 text-[15px] font-semibold text-[#0A0A0A] dark:text-slate-200">
                                ₱
                              </span>
                              <Input
                                id="as-amt"
                                type="number"
                                min="0.01"
                                step="0.01"
                                required
                                className="h-12 rounded-2xl border-border/80 pl-11 text-base tabular-nums tracking-tight focus-visible:border-[#0A0A0A]/35 focus-visible:ring-[#0A0A0A]/15 dark:focus-visible:border-slate-400/40 dark:focus-visible:ring-slate-400/20"
                                value={assignForm.amount}
                                onChange={(e) => setAssignForm((f) => ({ ...f, amount: e.target.value }))}
                              />
                            </div>
                          </div>
                          <div className="space-y-2">
                            <div className="flex items-center gap-1">
                              <Label htmlFor="as-bal" className="text-foreground/90">
                                Remaining balance
                              </Label>
                              <Tooltip>
                                <TooltipTrigger asChild>
                                  <span className="cursor-help text-xs text-muted-foreground">(loans)</span>
                                </TooltipTrigger>
                                <TooltipContent side="top" className="max-w-xs">
                                  For loans, set principal remaining so payroll can stop at zero.
                                </TooltipContent>
                              </Tooltip>
                            </div>
                            <div className="relative">
                              <span className="pointer-events-none absolute left-4 top-1/2 z-[1] -translate-y-1/2 text-[15px] font-semibold text-[#0A0A0A] dark:text-slate-200">
                                ₱
                              </span>
                              <Input
                                id="as-bal"
                                type="number"
                                min="0"
                                step="0.01"
                                className="h-12 rounded-2xl border-border/80 pl-11 text-base tabular-nums focus-visible:border-[#0A0A0A]/35 focus-visible:ring-[#0A0A0A]/15 dark:focus-visible:border-slate-400/40 dark:focus-visible:ring-slate-400/20"
                                value={assignForm.remaining_balance}
                                onChange={(e) => setAssignForm((f) => ({ ...f, remaining_balance: e.target.value }))}
                                placeholder="Optional"
                              />
                            </div>
                          </div>
                          <div className="space-y-2 sm:col-span-2">
                            <Label htmlFor="as-start" className="text-foreground/90">
                              Start date
                            </Label>
                            <Input
                              id="as-start"
                              type="date"
                              required
                              className="h-12 max-w-full rounded-2xl border-border/80 sm:max-w-xs"
                              value={assignForm.start_date}
                              onChange={(e) => setAssignForm((f) => ({ ...f, start_date: e.target.value }))}
                            />
                            {assignForm.start_date ? (
                              <p className="text-xs text-muted-foreground">
                                First payroll alignment: <span className="font-medium text-foreground">{formatDisplayDate(assignForm.start_date)}</span>
                              </p>
                            ) : null}
                          </div>
                        </div>
                      </div>
                      <Button
                        type="submit"
                        disabled={assigning || types.length === 0}
                        className={cn(
                          BTN_PRIMARY,
                          'h-12 w-full rounded-md text-base font-semibold transition-all active:scale-[0.99] sm:w-auto sm:px-12',
                        )}
                      >
                        {assigning ? (
                          <Loader2 className="size-4 animate-spin text-white dark:text-[#0A0A0A]" aria-hidden />
                        ) : (
                          'Save assignment'
                        )}
                      </Button>
                    </form>
                  </CardContent>
                </Card>
                <Card className={cn(PREMIUM_CARD, 'sticky top-6')}>
                  <CardHeader className="border-b border-border/50 bg-muted/20 dark:bg-white/[0.03]">
                    <CardTitle className="text-lg font-semibold text-[#0A0A0A] dark:text-slate-50">Live preview</CardTitle>
                    <CardDescription className="text-[13px]">Updates as you type</CardDescription>
                  </CardHeader>
                  <CardContent className="space-y-4 p-6 text-sm leading-relaxed">
                    {assignPreviewLive.ready ? (
                      <div className="space-y-4 rounded-2xl border border-border/60 bg-background/90 p-5 dark:bg-white/[0.04]">
                        <p className="text-[15px] font-medium leading-snug text-[#0A0A0A] dark:text-slate-100">
                          This will deduct <span className="font-semibold text-[#0A0A0A] dark:text-slate-100">{assignPreviewLive.amountLabel}</span>{' '}
                          monthly from employee <span className="font-mono text-sm font-semibold">#{assignPreviewLive.uid}</span>
                          {assignPreviewLive.typeLabel ? (
                            <>
                              {' '}
                              (<span className="font-medium">{assignPreviewLive.typeLabel}</span>)
                            </>
                          ) : null}{' '}
                          starting <span className="font-medium">{assignPreviewLive.startLabel}</span>.
                        </p>
                        <p className="text-[13px] text-[#0A0A0A]/85 dark:text-slate-300">
                          Schedule type:{' '}
                          <span className="font-semibold text-[#0A0A0A] dark:text-slate-100">{assignPreviewLive.scheduleLabel}</span>
                        </p>
                        {assignPreviewLive.balanceLabel ? (
                          <p className="text-[15px] text-foreground/95">
                            Remaining balance: <span className="font-semibold tabular-nums">{assignPreviewLive.balanceLabel}</span>.
                          </p>
                        ) : (
                          <p className="text-[13px] text-muted-foreground">Add a remaining balance for loans to track payoff progress.</p>
                        )}
                        <p className="text-[13px] leading-relaxed text-muted-foreground">
                          Pay runs follow this schedule type and the employee&apos;s pay cycle (see Deduction schedule settings).
                        </p>
                      </div>
                    ) : (
                      <div className="rounded-2xl border border-dashed border-border bg-muted/30 px-5 py-10 text-center">
                        <p className="text-sm font-medium text-[#0A0A0A] dark:text-slate-200">Enter employee ID, amount, and start date</p>
                        <p className="mt-2 text-xs text-muted-foreground">A plain-language summary will appear here instantly.</p>
                      </div>
                    )}
                    <Separator className="bg-border/60" />
                    <p className="text-xs leading-relaxed text-muted-foreground">
                      Deductions follow the pay component&apos;s schedule from{' '}
                      <span className="font-medium text-[#0A0A0A] dark:text-slate-200">Deduction schedule settings</span> and your pay cycles.
                    </p>
                  </CardContent>
                </Card>
              </div>
            </TabsContent>

            <TabsContent value="types" className="mt-0 outline-none">
              <Card className={PREMIUM_CARD}>
                <CardHeader className="flex flex-col gap-4 border-b border-border/50 bg-muted/20 px-6 py-6 sm:flex-row sm:items-center sm:justify-between">
                  <div>
                    <CardTitle className="text-xl font-semibold tracking-tight text-[#0A0A0A] dark:text-slate-50">
                      Deduction types
                    </CardTitle>
                    <CardDescription className="mt-1 max-w-xl text-[15px]">
                      Types power loan requests and manual assignments.
                    </CardDescription>
                  </div>
                  <Button type="button" onClick={() => setTypeDialog(true)} size="lg" className={cn(BTN_PRIMARY, 'shrink-0 rounded-md px-6')}>
                    Add type
                  </Button>
                </CardHeader>
                <CardContent className="p-6">
                  {loading ? (
                    <div className="grid gap-4 sm:grid-cols-2 lg:grid-cols-3">
                      {Array.from({ length: 6 }).map((_, i) => (
                        <Skeleton key={i} className="h-44 rounded-2xl" />
                      ))}
                    </div>
                  ) : (
                    <div className="grid gap-5 sm:grid-cols-2 lg:grid-cols-3">
                      {activeTypes.map((t) => {
                        const meta = typeKindMeta(t.type)
                        return (
                          <div
                            key={t.id}
                            className="flex flex-col overflow-hidden rounded-2xl border border-border/60 bg-card p-5 shadow-sm transition-colors hover:bg-muted/30 dark:bg-card"
                          >
                            <div className="flex items-start justify-between gap-2">
                              <div className="flex items-center gap-2">
                                <span
                                  className={cn(
                                    'rounded-md border px-2.5 py-0.5 text-[11px] font-semibold uppercase tracking-wide',
                                    meta.chip,
                                  )}
                                >
                                  {meta.label}
                                </span>
                                {t.is_active ? (
                                  <span className="rounded-md border border-border bg-muted px-2 py-0.5 text-[10px] font-semibold uppercase tracking-wide text-[#0A0A0A] dark:text-slate-200">
                                    Active
                                  </span>
                                ) : (
                                  <span className="rounded-md bg-muted px-2 py-0.5 text-[10px] font-semibold uppercase tracking-wide text-muted-foreground">
                                    Inactive
                                  </span>
                                )}
                              </div>
                              <DropdownMenu>
                                <DropdownMenuTrigger asChild>
                                  <Button
                                    type="button"
                                    variant="outline"
                                    size="icon"
                                    className={cn(BTN_SECONDARY, 'size-8 shrink-0 text-[#0A0A0A] dark:text-slate-100')}
                                    aria-label={`Actions for ${t.name}`}
                                  >
                                    <MoreHorizontal className="size-4" aria-hidden />
                                  </Button>
                                </DropdownMenuTrigger>
                                <DropdownMenuContent align="end" className="min-w-[10rem]">
                                  <DropdownMenuItem
                                    className="cursor-pointer"
                                    onSelect={() => openRenameType(t)}
                                  >
                                    <Pencil className="size-4" aria-hidden />
                                    Rename
                                  </DropdownMenuItem>
                                  <DropdownMenuItem
                                    variant="destructive"
                                    className="cursor-pointer"
                                    onSelect={() => {
                                      setTypeTarget(t)
                                      setTypeDeleteDialog(true)
                                    }}
                                  >
                                    <Trash2 className="size-4" aria-hidden />
                                    Delete
                                  </DropdownMenuItem>
                                </DropdownMenuContent>
                              </DropdownMenu>
                            </div>
                            <p className="mt-4 text-lg font-semibold leading-snug text-[#0A0A0A] dark:text-slate-100">{t.name}</p>
                            <p className="mt-2 text-xs text-muted-foreground">Used in assignments and loan requests</p>
                          </div>
                        )
                      })}
                    </div>
                  )}
                  {!loading && activeTypes.length === 0 ? (
                    <div className="mt-2 flex flex-col items-center gap-4 rounded-2xl border border-dashed border-border bg-muted/20 px-6 py-14 text-center">
                      <p className="text-base font-semibold text-[#0A0A0A] dark:text-slate-50">No deduction types yet</p>
                      <p className="max-w-sm text-sm text-muted-foreground">
                        Create a type to power loans, benefits, and other payroll deductions.
                      </p>
                      <Button type="button" onClick={() => setTypeDialog(true)} className={cn(BTN_PRIMARY, 'mt-1 rounded-md px-8')}>
                        Add your first type
                      </Button>
                    </div>
                  ) : null}
                </CardContent>
              </Card>
            </TabsContent>
          </Tabs>

          <RequestLoanModal
            open={requestLoanOpen}
            onOpenChange={setRequestLoanOpen}
            mode="admin"
            initialPrincipal={50000}
            onAdminContinue={handleAdminLoanPlannerContinue}
            adminContinueLabel="Continue to manual assign"
          />

          <EarlyLoanPayoffConfirmDialog
            open={payoffConfirmOpen}
            onOpenChange={(open) => {
              setPayoffConfirmOpen(open)
              if (!open) setPayoffTarget(null)
            }}
            onConfirm={confirmEarlyPayoff}
            loading={payoffLoading}
          />

          <Dialog
            open={amortOpen}
            onOpenChange={(open) => {
              setAmortOpen(open)
              if (!open) {
                setAmortTarget(null)
                setAdjustBalanceInput('')
              }
            }}
          >
            <DialogContent
              className="w-full max-w-5xl sm:max-w-5xl"
              innerClassName="gap-0 px-0 pb-0 pt-0 sm:px-0"
            >
              <div className="flex flex-col gap-6 px-6 pb-6 pt-6 sm:px-8 sm:pb-8 sm:pt-7">
                <DialogHeader className="gap-2 space-y-0 pr-10 text-left">
                  <DialogTitle className="text-xl font-semibold tracking-tight text-[#0A0A0A] dark:text-slate-50">
                    Amortization schedule
                  </DialogTitle>
                  <DialogDescription className="text-[15px] leading-relaxed text-muted-foreground">{amortTitle}</DialogDescription>
                </DialogHeader>
                <div className="flex flex-col gap-3 rounded-xl border border-border/60 bg-muted/20 p-3 sm:flex-row sm:items-end">
                  <div className="w-full sm:max-w-xs">
                    <Label htmlFor="adjust-balance-input">Manual remaining balance</Label>
                    <Input
                      id="adjust-balance-input"
                      type="number"
                      min="0"
                      step="0.01"
                      value={adjustBalanceInput}
                      onChange={(e) => setAdjustBalanceInput(e.target.value)}
                      className="mt-1"
                    />
                  </div>
                  <Button
                    type="button"
                    onClick={handleAdjustBalance}
                    disabled={adjustingBalance || !amortTarget}
                    className={cn(BTN_PRIMARY, 'h-10 rounded-md px-5')}
                  >
                    {adjustingBalance ? (
                      <>
                        <Loader2 className="mr-2 size-4 animate-spin" />
                        Saving...
                      </>
                    ) : (
                      'Update balance'
                    )}
                  </Button>
                </div>

                <div className="max-h-[min(65vh,640px)] overflow-auto rounded-2xl bg-muted/25 px-2 py-2 dark:bg-white/[0.04]">
                  <Table>
                    <TableHeader className="sticky top-0 z-[1] bg-muted/25 backdrop-blur-sm dark:bg-muted/20 [&_tr]:border-0">
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
                      {amortRows.map((r) => (
                        <TableRow
                          key={r.id}
                          className="border-0 transition-colors hover:bg-background/80 dark:hover:bg-white/[0.06]"
                        >
                          <TableCell className="py-3.5 pl-4 text-sm font-medium tabular-nums text-[#0A0A0A] dark:text-slate-100">
                            {r.installment_number}
                          </TableCell>
                          <TableCell className="py-3.5 text-sm text-[#0A0A0A] dark:text-slate-100">
                            {formatDisplayDate(r.pay_date || r.due_date)}
                          </TableCell>
                          <TableCell className="py-3.5 text-right text-sm tabular-nums text-[#0A0A0A] dark:text-slate-100">
                            {formatPhpWithSymbol(r.principal)}
                          </TableCell>
                          <TableCell className="py-3.5 text-right text-sm tabular-nums text-[#0A0A0A] dark:text-slate-100">
                            {formatPhpWithSymbol(r.interest)}
                          </TableCell>
                          <TableCell className="py-3.5 text-right text-sm font-semibold tabular-nums text-[#0A0A0A] dark:text-slate-50">
                            {formatPhpWithSymbol(r.total_installment)}
                          </TableCell>
                          <TableCell className="py-3.5 pr-4">
                            <Badge
                              variant="secondary"
                              className={cn(
                                'rounded-full px-2.5 py-0.5 text-xs font-semibold capitalize',
                                String(r.status || '').toLowerCase() === 'paid' &&
                                  'border-emerald-600/40 bg-emerald-50 text-emerald-900 dark:border-emerald-500/50 dark:bg-emerald-950/40 dark:text-emerald-100',
                                String(r.status || '').toLowerCase() === 'pending' &&
                                  'border-border bg-muted/50 text-[#0A0A0A] dark:border-white/15 dark:bg-white/[0.06] dark:text-slate-100',
                                String(r.status || '').toLowerCase() === 'overdue' &&
                                  'border-red-300 bg-red-50 text-red-800 dark:border-red-700/60 dark:bg-red-950/40 dark:text-red-200',
                              )}
                            >
                              {String(r.status || 'pending')}
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
                    className={cn(BTN_SECONDARY, 'h-10 rounded-full px-6 [&_svg]:text-[#0A0A0A] dark:[&_svg]:text-slate-100')}
                    onClick={() => setAmortOpen(false)}
                  >
                    Close
                  </Button>
                </DialogFooter>
              </div>
            </DialogContent>
          </Dialog>

          <Dialog open={typeDialog} onOpenChange={setTypeDialog}>
            <DialogContent className="sm:max-w-md">
              <form onSubmit={handleCreateType}>
                <DialogHeader>
                  <DialogTitle className="text-[#0A0A0A] dark:text-slate-50">New deduction type</DialogTitle>
                  <DialogDescription>Create a reusable label for loans, benefits, or other payroll deductions.</DialogDescription>
                </DialogHeader>
                <div className="grid gap-5 py-2">
                  <div className="space-y-2">
                    <Label htmlFor="dt-name">Display name</Label>
                    <Input
                      id="dt-name"
                      value={typeForm.name}
                      onChange={(e) => setTypeForm((f) => ({ ...f, name: e.target.value }))}
                      required
                      className="h-11 rounded-xl"
                      placeholder="e.g. Salary loan"
                    />
                  </div>
                  <div className="space-y-2">
                    <Label>Kind</Label>
                    <Select
                      value={typeForm.type}
                      onValueChange={(v) =>
                        setTypeForm((f) => ({
                          ...f,
                          type: v,
                          with_interest: v === 'loan' ? f.with_interest : false,
                        }))
                      }
                    >
                      <SelectTrigger className="h-11 rounded-xl text-[#0A0A0A] dark:text-slate-100 [&_svg]:text-[#0A0A0A] dark:[&_svg]:text-slate-100">
                        <SelectValue />
                      </SelectTrigger>
                      <SelectContent>
                        <SelectItem value="loan">Loan</SelectItem>
                        <SelectItem value="benefit">Benefit</SelectItem>
                        <SelectItem value="other">Other</SelectItem>
                      </SelectContent>
                    </Select>
                  </div>
                  {typeForm.type === 'loan' ? (
                    <div className="space-y-4 rounded-xl border border-border/60 bg-muted/20 p-3.5">
                      <div className="flex items-center justify-between gap-3">
                        <div>
                          <p className="text-sm font-medium text-[#0A0A0A] dark:text-slate-100">With Interest</p>
                          <p className="text-xs text-muted-foreground">Enable to apply interest in amortization schedules.</p>
                        </div>
                        <button
                          type="button"
                          role="switch"
                          aria-checked={typeForm.with_interest}
                          onClick={() =>
                            setTypeForm((f) => ({
                              ...f,
                              with_interest: !f.with_interest,
                              interest_rate_percent: !f.with_interest ? (f.interest_rate_percent || '0') : '0',
                            }))
                          }
                          className={cn(
                            'inline-flex h-6 w-11 items-center rounded-full border transition-colors',
                            typeForm.with_interest
                              ? 'border-[#0A0A0A] bg-[#0A0A0A] dark:border-slate-200 dark:bg-slate-200'
                              : 'border-border bg-muted'
                          )}
                        >
                          <span
                            className={cn(
                              'mx-0.5 inline-block size-5 rounded-full bg-white transition-transform dark:bg-[#0A0A0A]',
                              typeForm.with_interest ? 'translate-x-5' : 'translate-x-0'
                            )}
                          />
                        </button>
                      </div>
                      {typeForm.with_interest ? (
                        <div className="grid gap-3 sm:grid-cols-2">
                          <div className="space-y-2">
                            <Label htmlFor="dt-interest-rate">Interest rate (%)</Label>
                            <Input
                              id="dt-interest-rate"
                              type="number"
                              min="0"
                              step="0.0001"
                              value={typeForm.interest_rate_percent}
                              onChange={(e) => setTypeForm((f) => ({ ...f, interest_rate_percent: e.target.value }))}
                              className="h-11 rounded-xl"
                            />
                          </div>
                          <div className="space-y-2">
                            <Label>Interest type</Label>
                            <Select
                              value={typeForm.interest_type}
                              onValueChange={(v) => setTypeForm((f) => ({ ...f, interest_type: v }))}
                            >
                              <SelectTrigger className="h-11 rounded-xl text-[#0A0A0A] dark:text-slate-100 [&_svg]:text-[#0A0A0A] dark:[&_svg]:text-slate-100">
                                <SelectValue />
                              </SelectTrigger>
                              <SelectContent>
                                <SelectItem value="simple">Simple</SelectItem>
                                <SelectItem value="compound">Compound</SelectItem>
                              </SelectContent>
                            </Select>
                          </div>
                        </div>
                      ) : null}
                    </div>
                  ) : null}
                </div>
                <DialogFooter className="gap-3 sm:gap-3">
                  <Button
                    type="button"
                    variant="ghost"
                    className="rounded-full text-[#0A0A0A] dark:text-slate-100"
                    onClick={() => setTypeDialog(false)}
                  >
                    Cancel
                  </Button>
                  <Button type="submit" disabled={savingType} className={cn(BTN_PRIMARY, 'px-8')}>
                    {savingType ? (
                      <Loader2 className="size-4 animate-spin text-white dark:text-[#0A0A0A]" aria-hidden />
                    ) : (
                      'Create type'
                    )}
                  </Button>
                </DialogFooter>
              </form>
            </DialogContent>
          </Dialog>

          <Dialog
            open={typeRenameDialog}
            onOpenChange={(open) => {
              setTypeRenameDialog(open)
              if (!open) {
                setTypeTarget(null)
                setTypeRenameValue('')
              }
            }}
          >
            <DialogContent className="sm:max-w-md">
              <form onSubmit={handleRenameType}>
                <DialogHeader>
                  <DialogTitle className="text-[#0A0A0A] dark:text-slate-50">Rename deduction type</DialogTitle>
                  <DialogDescription>
                    Update the display name used in assignments and requests.
                  </DialogDescription>
                </DialogHeader>
                <div className="py-3">
                  <Label htmlFor="rename-deduction-type">Display name</Label>
                  <Input
                    id="rename-deduction-type"
                    className="mt-2 h-11 rounded-xl"
                    value={typeRenameValue}
                    onChange={(e) => setTypeRenameValue(e.target.value)}
                    required
                    maxLength={120}
                  />
                </div>
                <DialogFooter className="gap-2 sm:gap-0">
                  <Button
                    type="button"
                    variant="outline"
                    onClick={() => setTypeRenameDialog(false)}
                    disabled={savingTypeRename}
                    className={cn(BTN_SECONDARY)}
                  >
                    Cancel
                  </Button>
                  <Button type="submit" disabled={savingTypeRename} className={cn(BTN_PRIMARY)}>
                    {savingTypeRename ? (
                      <>
                        <Loader2 className="mr-2 size-4 animate-spin" />
                        Saving...
                      </>
                    ) : (
                      'Save'
                    )}
                  </Button>
                </DialogFooter>
              </form>
            </DialogContent>
          </Dialog>

          <Dialog
            open={typeDeleteDialog}
            onOpenChange={(open) => {
              setTypeDeleteDialog(open)
              if (!open) setTypeTarget(null)
            }}
          >
            <DialogContent className="sm:max-w-md">
              <DialogHeader>
                <DialogTitle className="text-[#0A0A0A] dark:text-slate-50">Delete deduction type?</DialogTitle>
                <DialogDescription>
                  This will remove <span className="font-semibold text-foreground">{typeTarget?.name || 'this type'}</span>.
                  If it is used by existing records, deletion may be blocked.
                </DialogDescription>
              </DialogHeader>
              <DialogFooter className="gap-3 sm:gap-3">
                <Button
                  type="button"
                  variant="outline"
                  onClick={() => setTypeDeleteDialog(false)}
                  disabled={deletingType}
                  className={cn(BTN_SECONDARY)}
                >
                  Cancel
                </Button>
                <Button type="button" variant="destructive" onClick={handleDeleteType} disabled={deletingType} className="ml-1">
                  {deletingType ? (
                    <>
                      <Loader2 className="mr-2 size-4 animate-spin" />
                      Deleting...
                    </>
                  ) : (
                    'Delete'
                  )}
                </Button>
              </DialogFooter>
            </DialogContent>
          </Dialog>

          <LoanRequestDetailsModal
            open={detailOpen}
            onOpenChange={setDetailOpen}
            loading={detailLoading}
            loanRequest={detail?.loan_request ?? null}
            approvalProgress={Array.isArray(detail?.approval_progress) ? detail.approval_progress : []}
            payCyclePreview={detail?.pay_cycle_preview ?? null}
            nextDeductionDates={Array.isArray(detail?.next_deduction_dates) ? detail.next_deduction_dates : []}
            canApprove={Boolean(detail?.can_approve)}
            canReject={Boolean(detail?.can_reject)}
            actionsSlot={
              detail?.can_approve ? (
                <div className="flex items-center gap-2">
                  {needsInstallment ? (
                    <Input
                      type="number"
                      min="0"
                      step="0.01"
                      className="h-9 w-36 rounded-xl"
                      value={installment}
                      onChange={(e) => setInstallment(e.target.value)}
                      placeholder="Installment"
                    />
                  ) : null}
                  <Button type="button" className={cn(BTN_PRIMARY, 'px-4')} onClick={handleApprove} disabled={acting}>
                    Approve
                  </Button>
                  <Button type="button" variant="destructive" className="rounded-full px-4" onClick={handleReject} disabled={acting}>
                    Reject
                  </Button>
                </div>
              ) : null
            }
          />
        </div>
      </div>
    </TooltipProvider>
  )
}
