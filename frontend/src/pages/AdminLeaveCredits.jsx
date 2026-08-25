import { createElement, useCallback, useEffect, useMemo, useState } from 'react'
import {
  AlertTriangle,
  ArrowDownToLine,
  ArrowUpFromLine,
  Building2,
  CalendarClock,
  CalendarDays,
  Hash,
  CheckCircle2,
  ChevronLeft,
  ChevronRight,
  Clock3,
  Download,
  Filter,
  History,
  Loader2,
  Minus,
  Plus,
  RefreshCw,
  Search,
  Settings2,
  SlidersHorizontal,
  Users,
  Wallet,
  XCircle,
} from 'lucide-react'

import {
  adjustEmployeeLeaveCredits,
  getLeaveCreditHistory,
  getLeaveCreditsReport,
  updateLeaveCreditSettings,
  userProfileImageSrc,
} from '@/api'
import { useToast } from '@/components/ui/use-toast'
import { Badge } from '@/components/ui/badge'
import { Button } from '@/components/ui/button'
import { Card, CardContent, CardDescription, CardHeader, CardTitle } from '@/components/ui/card'
import {
  Dialog,
  DialogContent,
} from '@/components/ui/dialog'
import { Input } from '@/components/ui/input'
import { Label } from '@/components/ui/label'
import { Select, SelectContent, SelectItem, SelectTrigger, SelectValue } from '@/components/ui/select'
import { Table, TableBody, TableCell, TableHead, TableHeader, TableRow } from '@/components/ui/table'
import { Textarea } from '@/components/ui/textarea'
import { Tooltip, TooltipContent, TooltipProvider, TooltipTrigger } from '@/components/ui/tooltip'
import { useAuth } from '@/contexts/AuthContext'
import { AppModalDescription, AppModalFooter, AppModalHeader, AppModalTitle } from '@/components/ui/app-modal-shell'
import {
  APP_MODAL_FORM_BODY,
  APP_MODAL_FOOTER_ACTIONS,
  APP_MODAL_INNER_FLUSH,
  APP_MODAL_OUTLINE_BUTTON_CLASS,
  APP_MODAL_PRIMARY_BUTTON_CLASS,
  appModalDialogContentClass,
} from '@/lib/appModalStyles'
import { isAdminHrUser } from '@/lib/hrRoutes'
import { attendanceFilterInputClass, attendanceSelectContentClass, attendanceSelectItemClass, attendanceSelectTriggerClass } from '@/lib/attendanceUiClasses'
import { cn } from '@/lib/utils'

const PAGE_SIZE = 20

const RECHARGE_MONTHS = [
  'January',
  'February',
  'March',
  'April',
  'May',
  'June',
  'July',
  'August',
  'September',
  'October',
  'November',
  'December',
]

function daysInMonth(month) {
  return new Date(2001, Number(month), 0).getDate()
}

function rechargeDateLabel(month, day) {
  const date = new Date(2001, Number(month) - 1, Number(day))
  if (Number.isNaN(date.getTime())) return 'January 1'
  return date.toLocaleDateString(undefined, { month: 'long', day: 'numeric' })
}

function formatNumber(value) {
  return Number(value || 0).toLocaleString()
}

function formatDate(value) {
  if (!value) return 'Not set'
  const date = new Date(`${String(value).slice(0, 10)}T00:00:00`)
  return Number.isNaN(date.getTime())
    ? String(value)
    : date.toLocaleDateString(undefined, { month: 'short', day: 'numeric', year: 'numeric' })
}

function formatDateTime(value) {
  if (!value) return 'Not recorded'
  const date = new Date(value)
  return Number.isNaN(date.getTime())
    ? String(value)
    : date.toLocaleString(undefined, {
        month: 'short',
        day: 'numeric',
        year: 'numeric',
        hour: 'numeric',
        minute: '2-digit',
      })
}

function humanEmploymentStatus(value) {
  if (!value) return 'Not set'
  return String(value)
    .replace(/_/g, ' ')
    .replace(/\b\w/g, (letter) => letter.toUpperCase())
}

function rowStatus(row) {
  if (!row?.eligible_for_paid_leave_pool) return 'not_eligible'
  const available = Number(row.effective_available ?? row.leave_credits_remaining ?? 0)
  if (available <= 0) return 'no_balance'
  if (available <= 3) return 'low_balance'
  return 'healthy'
}

const STATUS_META = {
  healthy: {
    label: 'Healthy balance',
    icon: CheckCircle2,
    className: 'border-emerald-500/35 bg-emerald-500/10 text-emerald-800 dark:text-emerald-200',
  },
  low_balance: {
    label: 'Low balance',
    icon: AlertTriangle,
    className: 'border-amber-500/35 bg-amber-500/10 text-amber-900 dark:text-amber-100',
  },
  no_balance: {
    label: 'No balance',
    icon: XCircle,
    className: 'border-red-500/35 bg-red-500/10 text-red-800 dark:text-red-200',
  },
  not_eligible: {
    label: 'Not eligible',
    icon: Clock3,
    className: 'border-slate-400/40 bg-slate-500/10 text-slate-700 dark:text-slate-200',
  },
}

function StatusBadge({ row }) {
  const meta = STATUS_META[rowStatus(row)] || STATUS_META.not_eligible
  const Icon = meta.icon
  return (
    <Badge variant="outline" className={cn('gap-1 whitespace-nowrap text-xs font-semibold', meta.className)}>
      <Icon className="size-3" aria-hidden />
      {meta.label}
    </Badge>
  )
}

function balancePercent(row) {
  const annual = Math.max(0, Number(row?.annual_allocation || 0))
  if (!annual) return 0
  return Math.min(100, Math.max(0, (Number(row?.leave_credits_remaining || 0) / annual) * 100))
}

function BalanceCell({ row, compact = false }) {
  const remaining = Number(row?.leave_credits_remaining || 0)
  const annual = Number(row?.annual_allocation || 0)
  const usable = Number(row?.effective_available ?? remaining)
  const pending = Number(row?.pending_reserved_days || 0)
  const pct = balancePercent(row)
  const meterClass = rowStatus(row) === 'no_balance'
    ? 'bg-red-500'
    : rowStatus(row) === 'low_balance'
      ? 'bg-amber-500'
      : 'bg-emerald-500'

  return (
    <div className={cn('min-w-36', compact && 'min-w-0')}>
      <div className="flex items-baseline justify-between gap-2 tabular-nums">
        <span className="text-lg font-bold tracking-tight text-foreground">{formatNumber(remaining)}</span>
        <span className="text-xs text-muted-foreground">of {formatNumber(annual)}</span>
      </div>
      <div className="mt-2 h-1.5 overflow-hidden rounded-full bg-muted dark:bg-white/10" aria-hidden>
        <div className={cn('h-full rounded-full transition-[width]', meterClass)} style={{ width: `${pct}%` }} />
      </div>
      <div className="mt-1.5 flex flex-wrap gap-x-2 gap-y-0.5 text-xs text-muted-foreground">
        <span>Usable {formatNumber(usable)}</span>
        {pending > 0 ? <span>Reserved {formatNumber(pending)}</span> : null}
      </div>
    </div>
  )
}

function ActionIconButton({ label, icon: Icon, onClick, disabled = false, tone = 'neutral' }) {
  const toneClass = tone === 'brand'
    ? 'text-brand hover:bg-brand/10 hover:text-brand'
    : 'text-muted-foreground hover:bg-muted hover:text-foreground'

  return (
    <Tooltip>
      <TooltipTrigger asChild>
        <Button
          type="button"
          variant="ghost"
          size="icon-sm"
          className={cn('rounded-md border border-transparent', toneClass)}
          onClick={onClick}
          disabled={disabled}
          aria-label={label}
        >
          {createElement(Icon, { className: 'size-4', 'aria-hidden': true })}
        </Button>
      </TooltipTrigger>
      <TooltipContent side="top" className="text-xs">{label}</TooltipContent>
    </Tooltip>
  )
}

function EmployeeAvatar({ row, className = 'size-9 text-[11px]' }) {
  const [failed, setFailed] = useState(false)
  const avatarUrl = userProfileImageSrc(row)

  if (!avatarUrl || failed) {
    return (
      <span className={cn('flex shrink-0 items-center justify-center rounded-lg bg-brand/10 font-bold text-brand ring-1 ring-brand/15', className)}>
        {String(row?.name || '?').trim().charAt(0).toUpperCase()}
      </span>
    )
  }

  return (
    <img
      src={avatarUrl}
      alt=""
      loading="lazy"
      onError={() => setFailed(true)}
      className={cn('shrink-0 rounded-lg bg-muted object-cover ring-1 ring-border/70', className)}
    />
  )
}

function OrgLogo({ option, className = 'size-5 rounded-md' }) {
  const [failed, setFailed] = useState(false)
  const url = option?.logo_url

  if (!url || failed) {
    return (
      <span className={cn('flex shrink-0 items-center justify-center bg-muted/70 text-[10px] font-bold text-muted-foreground ring-1 ring-border/50', className)}>
        {String(option?.name || '?').trim().charAt(0).toUpperCase()}
      </span>
    )
  }

  return (
    <img
      src={url}
      alt=""
      loading="lazy"
      onError={() => setFailed(true)}
      className={cn('shrink-0 bg-card object-contain ring-1 ring-border/50', className)}
    />
  )
}

function StatCard({ label, value, detail, icon: Icon, tone = 'brand' }) {
  const toneClasses = {
    brand: 'bg-brand/10 text-brand',
    emerald: 'bg-emerald-500/10 text-emerald-600 dark:text-emerald-400',
    amber: 'bg-amber-500/10 text-amber-700 dark:text-amber-300',
    red: 'bg-red-500/10 text-red-600 dark:text-red-400',
  }
  return (
    <Card className="border border-border/65 shadow-sm dark:border-white/10">
      <CardContent className="p-5">
        <div className="flex items-start justify-between gap-3">
          <div className="min-w-0">
            <p className="text-xs font-medium text-muted-foreground">{label}</p>
            <p className="mt-1 text-3xl font-black tracking-tight tabular-nums text-foreground">{formatNumber(value)}</p>
            <p className="mt-1 text-xs text-muted-foreground">{detail}</p>
          </div>
          <div className={cn('flex size-10 shrink-0 items-center justify-center rounded-xl', toneClasses[tone] || toneClasses.brand)}>
            {createElement(Icon, { className: 'size-5', 'aria-hidden': true })}
          </div>
        </div>
      </CardContent>
    </Card>
  )
}

function organizationLabel(row) {
  return [row?.department_name, row?.branch_name, row?.company_name].filter(Boolean).join(' / ') || 'Organization not assigned'
}

function transactionLabel(type) {
  const labels = {
    addition: 'Added',
    deduction: 'Deducted',
    adjustment: 'Manual adjustment',
    annual_reset: 'Annual reset',
  }
  return labels[String(type || '').toLowerCase()] || 'Balance update'
}

function transactionTone(type) {
  const value = String(type || '').toLowerCase()
  if (value === 'addition' || value === 'annual_reset') return 'text-emerald-600 dark:text-emerald-400'
  if (value === 'deduction') return 'text-red-600 dark:text-red-400'
  return 'text-amber-700 dark:text-amber-300'
}

function csvCell(value) {
  const text = value == null ? '' : String(value)
  return `"${text.replace(/"/g, '""')}"`
}

function downloadCsv(rows) {
  const headers = [
    'Employee',
    'Employee code',
    'Employment status',
    'Department',
    'Branch',
    'Company',
    'Annual allocation',
    'Remaining credits',
    'Pending reserved days',
    'Effective available',
    'Eligibility',
    'Status',
  ]
  const values = rows.map((row) => [
    row.name,
    row.employee_code,
    humanEmploymentStatus(row.employment_status),
    row.department_name,
    row.branch_name,
    row.company_name,
    row.annual_allocation,
    row.leave_credits_remaining,
    row.pending_reserved_days,
    row.effective_available,
    row.eligible_for_paid_leave_pool ? 'Eligible' : 'Not eligible',
    STATUS_META[rowStatus(row)]?.label || 'Not eligible',
  ])
  const csv = [headers, ...values].map((line) => line.map(csvCell).join(',')).join('\n')
  const blob = new Blob([csv], { type: 'text/csv;charset=utf-8;' })
  const url = URL.createObjectURL(blob)
  const anchor = document.createElement('a')
  anchor.href = url
  anchor.download = `leave-credits-${new Date().toISOString().slice(0, 10)}.csv`
  document.body.appendChild(anchor)
  anchor.click()
  anchor.remove()
  URL.revokeObjectURL(url)
}

export default function AdminLeaveCredits() {
  const { toast } = useToast()
  const { user } = useAuth()
  const permissions = useMemo(() => new Set(user?.permissions ?? []), [user?.permissions])
  const canAdjust = isAdminHrUser(user) || permissions.has('employees.edit')
  const canConfigureSchedule = isAdminHrUser(user) || permissions.has('settings.manage')

  const [rows, setRows] = useState([])
  const [annualAllocation, setAnnualAllocation] = useState(14)
  const [schedule, setSchedule] = useState(null)
  const [loading, setLoading] = useState(true)
  const [scheduleLoading, setScheduleLoading] = useState(true)
  const [error, setError] = useState('')
  const [search, setSearch] = useState('')
  const [statusFilter, setStatusFilter] = useState('all')
  const [companyId, setCompanyId] = useState('')
  const [branchId, setBranchId] = useState('')
  const [departmentId, setDepartmentId] = useState('')
  const [filterOptions, setFilterOptions] = useState({ companies: [], branches: [], departments: [] })
  const [page, setPage] = useState(1)
  const [adjustEmployee, setAdjustEmployee] = useState(null)
  const [adjustmentMode, setAdjustmentMode] = useState('add')
  const [adjustmentAmount, setAdjustmentAmount] = useState('')
  const [adjustmentReason, setAdjustmentReason] = useState('')
  const [adjustmentSaving, setAdjustmentSaving] = useState(false)
  const [historyEmployee, setHistoryEmployee] = useState(null)
  const [historyData, setHistoryData] = useState(null)
  const [historyLoading, setHistoryLoading] = useState(false)
  const [scheduleOpen, setScheduleOpen] = useState(false)
  const [scheduleMonth, setScheduleMonth] = useState('1')
  const [scheduleDay, setScheduleDay] = useState('1')
  const [scheduleSaving, setScheduleSaving] = useState(false)

  const loadRows = useCallback(async () => {
    setLoading(true)
    setScheduleLoading(true)
    setError('')
    try {
      const data = await getLeaveCreditsReport({ companyId, branchId, departmentId })
      setRows(Array.isArray(data?.employees) ? data.employees : [])
      setAnnualAllocation(Number(data?.annual_allocation ?? 14))
      if (data?.filters) {
        setFilterOptions({
          companies: Array.isArray(data.filters.companies) ? data.filters.companies : [],
          branches: Array.isArray(data.filters.branches) ? data.filters.branches : [],
          departments: Array.isArray(data.filters.departments) ? data.filters.departments : [],
        })
      }
      if (data?.recharge_schedule) {
        setSchedule(data.recharge_schedule)
        setScheduleMonth(String(data.recharge_schedule.reset_month ?? 1))
        setScheduleDay(String(data.recharge_schedule.reset_day ?? 1))
      }
    } catch (requestError) {
      setError(requestError?.message || 'Failed to load leave credits.')
    } finally {
      setLoading(false)
      setScheduleLoading(false)
    }
  }, [companyId, branchId, departmentId])

  useEffect(() => {
    loadRows()
  }, [loadRows])

  // Keep dependent org filters valid when a parent scope narrows the available options.
  useEffect(() => {
    setBranchId((current) => {
      if (!current) return current
      const allowed = (filterOptions.branches || []).filter((b) => !companyId || String(b.company_id) === String(companyId))
      return allowed.some((b) => String(b.id) === String(current)) ? current : ''
    })
  }, [companyId, filterOptions.branches])

  useEffect(() => {
    setDepartmentId((current) => {
      if (!current) return current
      const allowed = (filterOptions.departments || []).filter((d) =>
        (!companyId || String(d.company_id) === String(companyId)) &&
        (!branchId || String(d.branch_id) === String(branchId)))
      return allowed.some((d) => String(d.id) === String(current)) ? current : ''
    })
  }, [companyId, branchId, filterOptions.departments])

  const filteredRows = useMemo(() => {
    const needle = search.trim().toLowerCase()
    return rows.filter((row) => {
      if (statusFilter !== 'all' && rowStatus(row) !== statusFilter) return false
      if (!needle) return true
      return [row.name, row.employee_code, row.department_name, row.branch_name, row.company_name]
        .filter(Boolean)
        .some((value) => String(value).toLowerCase().includes(needle))
    })
  }, [rows, search, statusFilter])

  useEffect(() => {
    setPage(1)
  }, [search, statusFilter, companyId, branchId, departmentId])

  const pageCount = Math.max(1, Math.ceil(filteredRows.length / PAGE_SIZE))
  const visibleRows = filteredRows.slice((page - 1) * PAGE_SIZE, page * PAGE_SIZE)

  const companyOptions = filterOptions.companies || []
  const branchOptions = (filterOptions.branches || []).filter((b) => !companyId || String(b.company_id) === String(companyId))
  const departmentOptions = (filterOptions.departments || []).filter((d) =>
    (!companyId || String(d.company_id) === String(companyId)) &&
    (!branchId || String(d.branch_id) === String(branchId)))

  const stats = useMemo(() => {
    const eligible = rows.filter((row) => row.eligible_for_paid_leave_pool)
    return {
      employees: rows.length,
      eligible: eligible.length,
      remaining: rows.reduce((sum, row) => sum + Number(row.leave_credits_remaining || 0), 0),
      pending: rows.reduce((sum, row) => sum + Number(row.pending_reserved_days || 0), 0),
      attention: eligible.filter((row) => Number(row.effective_available ?? row.leave_credits_remaining ?? 0) <= 3).length,
    }
  }, [rows])

  function openAdjust(row) {
    setAdjustEmployee(row)
    setAdjustmentMode('add')
    setAdjustmentAmount('')
    setAdjustmentReason('')
  }

  function closeAdjust() {
    if (adjustmentSaving) return
    setAdjustEmployee(null)
  }

  const parsedAmount = Math.max(0, Number.parseInt(String(adjustmentAmount || ''), 10) || 0)
  const signedDelta = adjustmentMode === 'add' ? parsedAmount : -parsedAmount
  const projectedBalance = Math.max(0, Number(adjustEmployee?.leave_credits_remaining || 0) + signedDelta)

  async function submitAdjustment() {
    if (!adjustEmployee || !canAdjust) return
    if (!parsedAmount) {
      toast({ title: 'Enter a whole number greater than zero.', variant: 'error' })
      return
    }
    const reason = adjustmentReason.trim()
    if (!reason) {
      toast({ title: 'A reason is required for the audit trail.', variant: 'error' })
      return
    }
    setAdjustmentSaving(true)
    try {
      await adjustEmployeeLeaveCredits(adjustEmployee.employee_id, { delta: signedDelta, reason })
      setAdjustEmployee(null)
      await loadRows()
      toast({ title: 'Leave credits updated.', description: `${adjustEmployee?.name || 'Employee'} balance adjusted to ${formatNumber(projectedBalance)} credits.`, variant: 'success' })
    } catch (requestError) {
      toast({ title: requestError?.message || 'Failed to update leave credits.', variant: 'error' })
    } finally {
      setAdjustmentSaving(false)
    }
  }

  async function openHistory(row) {
    setHistoryEmployee(row)
    setHistoryData(null)
    setHistoryLoading(true)
    try {
      const data = await getLeaveCreditHistory(row.employee_id)
      setHistoryData(data)
    } catch (requestError) {
      toast({ title: requestError?.message || 'Failed to load leave credit history.', variant: 'error' })
      setHistoryEmployee(null)
    } finally {
      setHistoryLoading(false)
    }
  }

  function openSchedule() {
    setScheduleMonth(String(schedule?.reset_month ?? 1))
    setScheduleDay(String(schedule?.reset_day ?? 1))
    setScheduleOpen(true)
  }

  function handleScheduleMonthChange(value) {
    const maxDay = daysInMonth(value)
    setScheduleMonth(value)
    setScheduleDay((current) => String(Math.min(Number(current) || 1, maxDay)))
  }

  async function submitSchedule() {
    if (!canConfigureSchedule) return
    setScheduleSaving(true)
    try {
      const data = await updateLeaveCreditSettings({
        reset_month: Number(scheduleMonth),
        reset_day: Number(scheduleDay),
      })
      if (data?.settings) {
        setSchedule(data.settings)
        setScheduleMonth(String(data.settings.reset_month ?? scheduleMonth))
        setScheduleDay(String(data.settings.reset_day ?? scheduleDay))
      }
      setScheduleOpen(false)
      const recharged = Number(data?.recharged_employees || 0)
      toast({
        title: recharged > 0
          ? `Recharge schedule saved. ${recharged} employee${recharged === 1 ? '' : 's'} recharged.`
          : 'Recharge schedule saved.',
        variant: 'success',
      })
      await loadRows()
    } catch (requestError) {
      toast({ title: requestError?.message || 'Failed to update the recharge schedule.', variant: 'error' })
    } finally {
      setScheduleSaving(false)
    }
  }

  const historySummary = historyData?.leave_credits || historyEmployee || {}
  const historyRows = Array.isArray(historyData?.history) ? historyData.history : []

  return (
    <TooltipProvider delayDuration={250}>
    <div className="flex w-full min-w-0 flex-col gap-6 @md:gap-8">
      <div className="flex w-full flex-col gap-5 pb-1 @lg:flex-row @lg:items-end @lg:justify-between">
        <div className="min-w-0 flex-1">
          <p className="text-[11px] font-bold uppercase tracking-[0.18em] text-brand">Time & attendance</p>
          <h1 className="mt-3 text-3xl font-bold tracking-tight text-foreground @sm:text-4xl">Leave credits</h1>
          <p className="mt-2 max-w-3xl text-[15px] leading-relaxed text-muted-foreground">
            Review employee leave balances, pending reservations, eligibility, and every manual credit change.
          </p>
        </div>
        <div className="flex w-full flex-wrap items-center gap-3 @lg:w-auto @lg:justify-end">
          <Tooltip>
            <TooltipTrigger asChild>
              <Button
                type="button"
                variant="outline"
                size="icon"
                className="size-10 rounded-lg border-border/70 bg-card shadow-sm hover:border-brand/40 hover:bg-brand/5 hover:text-brand"
                onClick={loadRows}
                disabled={loading}
                aria-label="Refresh leave credit balances"
              >
                {loading ? <Loader2 className="size-4 animate-spin" /> : <RefreshCw className="size-4" />}
              </Button>
            </TooltipTrigger>
            <TooltipContent side="bottom">Refresh balances</TooltipContent>
          </Tooltip>
          <Button type="button" variant="outline" onClick={() => downloadCsv(filteredRows)} disabled={!filteredRows.length} className="h-10 gap-2 rounded-lg border-border/70 bg-card px-3 shadow-sm hover:border-brand/40 hover:bg-brand/5 hover:text-brand">
            <Download className="size-4" />
            <span className="hidden @sm:inline">Export CSV</span>
          </Button>
        </div>
      </div>

      <div className="grid w-full gap-3 @sm:grid-cols-2 @lg:grid-cols-4">
        <StatCard label="Employees in scope" value={stats.employees} detail="Active reportable employees" icon={Users} />
        <StatCard label="Eligible employees" value={stats.eligible} detail={`Annual pool: ${formatNumber(annualAllocation)} credits`} icon={CheckCircle2} tone="emerald" />
        <StatCard label="Credits remaining" value={stats.remaining} detail={`${formatNumber(stats.pending)} days reserved by pending leave`} icon={Wallet} tone="brand" />
        <StatCard label="Needs attention" value={stats.attention} detail="Eligible employees at 3 or fewer usable credits" icon={AlertTriangle} tone="amber" />
      </div>

      <Card className="overflow-hidden rounded-xl border border-brand/20 bg-brand/[0.025] shadow-sm dark:border-brand/25 dark:bg-brand/[0.045]">
        <CardContent className="p-0">
          <div className="flex flex-col gap-4 p-4 @md:flex-row @md:items-center @md:justify-between @md:px-5 @md:py-4">
            <div className="flex min-w-0 items-start gap-3">
              <span className="flex size-10 shrink-0 items-center justify-center rounded-xl bg-brand/10 text-brand ring-1 ring-brand/15">
                <CalendarClock className="size-5" aria-hidden />
              </span>
              <div className="min-w-0">
                <p className="text-[10px] font-bold uppercase tracking-[0.14em] text-brand">Recharge rule</p>
                {scheduleLoading ? (
                  <div className="mt-2 h-5 w-72 max-w-full animate-pulse rounded bg-muted" aria-label="Loading recharge rule" />
                ) : (
                  <p className="mt-1 text-sm font-semibold text-foreground">
                    {schedule?.policy || 'Recharges on January 1st every year (full reset; unused credits do not carry over).'}
                  </p>
                )}
                <div className="mt-2 flex flex-wrap items-center gap-x-3 gap-y-1 text-xs text-muted-foreground">
                  <span className="font-medium text-foreground/80">{schedule?.next_reset_display || 'Next recharge date is being calculated'}</span>
                  {schedule?.timezone ? <span>Timezone: {schedule.timezone}</span> : null}
                </div>
              </div>
            </div>
            {canConfigureSchedule ? (
              <Button type="button" variant="outline" onClick={openSchedule} className="h-10 shrink-0 gap-2 rounded-lg border-brand/25 bg-card px-3 shadow-sm hover:border-brand/45 hover:bg-brand/5 hover:text-brand">
                <Settings2 className="size-4" />
                Configure schedule
              </Button>
            ) : null}
          </div>
        </CardContent>
      </Card>

      <Card className="overflow-hidden rounded-xl border border-border/65 shadow-sm dark:border-white/10">
        <CardHeader className="gap-4 border-b border-border/60 bg-card px-4 py-4 @md:px-5 dark:border-white/10">
          <div className="flex flex-col gap-1 @lg:flex-row @lg:items-end @lg:justify-between">
            <div>
              <CardTitle className="text-lg font-semibold tracking-tight">Employee leave balances</CardTitle>
              <CardDescription className="mt-1 text-xs @sm:text-sm">Balances, reservations, and eligibility across your reporting scope.</CardDescription>
            </div>
            <p className="text-xs tabular-nums text-muted-foreground">
              {filteredRows.length.toLocaleString()} of {rows.length.toLocaleString()} employees
            </p>
          </div>
          <div className="flex flex-col gap-2 rounded-lg border border-border/60 bg-muted/20 p-2 @2xl:flex-row @2xl:items-center dark:border-white/10 dark:bg-white/[0.025]">
            <div className="relative min-w-0 flex-1">
              <Search className="pointer-events-none absolute left-3 top-1/2 size-4 -translate-y-1/2 text-muted-foreground" aria-hidden />
              <Input
                value={search}
                onChange={(event) => setSearch(event.target.value)}
                placeholder="Search employee, code, department, branch, or company"
                className={attendanceFilterInputClass}
              />
            </div>
            <div className="flex flex-wrap items-center gap-2">
              <Filter className="hidden size-4 shrink-0 text-muted-foreground @lg:block" aria-hidden />
              <Select value={companyId} onValueChange={setCompanyId}>
                <SelectTrigger className={cn(attendanceSelectTriggerClass, 'h-9 w-full @2xl:w-44')}>
                  <SelectValue placeholder="All companies" />
                </SelectTrigger>
                <SelectContent className={attendanceSelectContentClass}>
                  {companyOptions.map((option) => (
                    <SelectItem key={option.id} value={String(option.id)} className={cn(attendanceSelectItemClass, 'gap-2.5 py-2 pl-2 pr-8')}>
                      <OrgLogo option={option} />
                      <span className="truncate">{option.name}</span>
                    </SelectItem>
                  ))}
                </SelectContent>
              </Select>
              <Select value={branchId} onValueChange={setBranchId} disabled={!branchOptions.length}>
                <SelectTrigger className={cn(attendanceSelectTriggerClass, 'h-9 w-full @2xl:w-44')}>
                  <SelectValue placeholder="All branches" />
                </SelectTrigger>
                <SelectContent className={attendanceSelectContentClass}>
                  {branchOptions.map((option) => (
                    <SelectItem key={option.id} value={String(option.id)} className={cn(attendanceSelectItemClass, 'gap-2.5 py-2 pl-2 pr-8')}>
                      <OrgLogo option={option} />
                      <span className="truncate">{option.name}</span>
                    </SelectItem>
                  ))}
                </SelectContent>
              </Select>
              <Select value={departmentId} onValueChange={setDepartmentId} disabled={!departmentOptions.length}>
                <SelectTrigger className={cn(attendanceSelectTriggerClass, 'h-9 w-full @2xl:w-48')}>
                  <SelectValue placeholder="All departments" />
                </SelectTrigger>
                <SelectContent className={attendanceSelectContentClass}>
                  {departmentOptions.map((option) => (
                    <SelectItem key={option.id} value={String(option.id)} className={cn(attendanceSelectItemClass, 'gap-2.5 py-2 pl-2 pr-8')}>
                      <OrgLogo option={option} />
                      <span className="truncate">{option.name}</span>
                    </SelectItem>
                  ))}
                </SelectContent>
              </Select>
              <Select value={statusFilter} onValueChange={setStatusFilter}>
                <SelectTrigger className={cn(attendanceSelectTriggerClass, 'h-9 w-full @2xl:w-40')}>
                  <SelectValue placeholder="All balances" />
                </SelectTrigger>
                <SelectContent className={attendanceSelectContentClass}>
                  <SelectItem value="all" className={attendanceSelectItemClass}>All balances</SelectItem>
                  <SelectItem value="healthy" className={attendanceSelectItemClass}>Healthy balance</SelectItem>
                  <SelectItem value="low_balance" className={attendanceSelectItemClass}>Low balance</SelectItem>
                  <SelectItem value="no_balance" className={attendanceSelectItemClass}>No balance</SelectItem>
                  <SelectItem value="not_eligible" className={attendanceSelectItemClass}>Not eligible</SelectItem>
                </SelectContent>
              </Select>
            </div>
          </div>
        </CardHeader>

        {error ? (
          <div className="m-5 flex flex-col items-start gap-3 rounded-lg border border-red-500/30 bg-red-500/5 p-4 text-sm text-red-800 dark:text-red-200 @sm:flex-row @sm:items-center @sm:justify-between">
            <span>{error}</span>
            <Button type="button" variant="outline" size="sm" onClick={loadRows}>Try again</Button>
          </div>
        ) : null}

        <CardContent className="p-0">
          <div className="hidden overflow-x-auto md:block">
            <Table className="min-w-[1200px] table-fixed text-sm">
              <colgroup>
                <col className="w-[22%]" />
                <col className="w-[20%]" />
                <col className="w-[16%]" />
                <col className="w-[17%]" />
                <col className="w-[8%]" />
                <col className="w-[11%]" />
                <col className="w-[6%]" />
              </colgroup>
              <TableHeader>
                <TableRow className="border-b border-border/70 bg-muted/30 hover:bg-muted/30 dark:border-white/10 dark:bg-white/[0.035]">
                  <TableHead className="h-10 px-3 text-[10px] font-bold uppercase tracking-[0.12em] text-muted-foreground">Employee</TableHead>
                  <TableHead className="h-10 px-3 text-[10px] font-bold uppercase tracking-[0.12em] text-muted-foreground">Organization</TableHead>
                  <TableHead className="h-10 px-3 text-[10px] font-bold uppercase tracking-[0.12em] text-muted-foreground">Eligibility</TableHead>
                  <TableHead className="h-10 px-3 text-[10px] font-bold uppercase tracking-[0.12em] text-muted-foreground">Balance</TableHead>
                  <TableHead className="h-10 px-3 text-right text-[10px] font-bold uppercase tracking-[0.12em] text-muted-foreground">Reserved</TableHead>
                  <TableHead className="h-10 px-3 text-[10px] font-bold uppercase tracking-[0.12em] text-muted-foreground">Status</TableHead>
                  <TableHead className="h-10 px-3 text-right text-[10px] font-bold uppercase tracking-[0.12em] text-muted-foreground">Actions</TableHead>
                </TableRow>
              </TableHeader>
              <TableBody>
                {loading ? (
                  <TableRow>
                    <TableCell colSpan={7} className="h-44 text-center text-muted-foreground">
                      <Loader2 className="mx-auto mb-2 size-5 animate-spin text-brand" />
                      Loading leave credits...
                    </TableCell>
                  </TableRow>
                ) : visibleRows.length ? (
                  visibleRows.map((row, rowIndex) => (
                    <TableRow key={row.employee_id} className={cn('border-b border-border/55 transition-colors hover:bg-brand/[0.035] dark:border-white/8 dark:hover:bg-white/[0.035]', rowIndex % 2 ? 'bg-muted/[0.18] dark:bg-white/[0.012]' : 'bg-card')}>
                      <TableCell className="px-3 py-3.5 align-middle">
                        <div className="flex min-w-0 items-center gap-3">
                          <EmployeeAvatar row={row} className="size-9 text-[11px]" />
                          <div className="min-w-0">
                            <p className="truncate font-semibold text-foreground">{row.name || 'Unnamed employee'}</p>
                            <p className="mt-0.5 truncate text-xs text-muted-foreground">{row.employee_code || `Employee #${row.employee_id}`}</p>
                          </div>
                        </div>
                      </TableCell>
                      <TableCell className="px-3 py-3.5 align-middle">
                        <div className="flex min-w-0 items-start gap-2">
                          <Building2 className="mt-0.5 size-4 shrink-0 text-muted-foreground" aria-hidden />
                          <div className="min-w-0">
                            <p className="truncate text-foreground">{row.department_name || 'No department'}</p>
                            <p className="mt-0.5 truncate text-xs text-muted-foreground">{[row.branch_name, row.company_name].filter(Boolean).join(' / ') || 'No branch or company'}</p>
                          </div>
                        </div>
                      </TableCell>
                      <TableCell className="px-3 py-3.5 align-middle">
                        <Badge variant={row.eligible_for_paid_leave_pool ? 'outline' : 'secondary'} className={cn('text-[10px] font-semibold', row.eligible_for_paid_leave_pool && 'border-emerald-500/30 bg-emerald-500/5 text-emerald-700 dark:text-emerald-300')}>
                          {row.eligible_for_paid_leave_pool ? 'Eligible' : 'Not eligible'}
                        </Badge>
                        <p className="mt-1 max-w-48 truncate text-xs text-muted-foreground" title={row.status_summary || undefined}>
                          {row.eligible_for_paid_leave_pool ? `Service date: ${formatDate(row.service_anchor_date || row.hire_date)}` : 'Regular status + 1 year required'}
                        </p>
                      </TableCell>
                      <TableCell className="px-3 py-3.5 align-middle"><BalanceCell row={row} /></TableCell>
                      <TableCell className="px-3 py-3.5 text-right align-middle tabular-nums">
                        <span className={cn('font-semibold', Number(row.pending_reserved_days) > 0 ? 'text-amber-700 dark:text-amber-300' : 'text-muted-foreground')}>
                          {formatNumber(row.pending_reserved_days)}
                        </span>
                        <p className="mt-0.5 text-xs text-muted-foreground">days</p>
                      </TableCell>
                      <TableCell className="px-3 py-3.5 align-middle"><StatusBadge row={row} /></TableCell>
                      <TableCell className="px-3 py-3.5 text-right align-middle">
                        <div className="inline-flex items-center gap-0.5 rounded-lg border border-border/60 bg-card p-0.5 shadow-sm dark:border-white/10">
                          <ActionIconButton label="View credit history" icon={History} onClick={() => openHistory(row)} />
                          {canAdjust ? <ActionIconButton label="Adjust credits" icon={SlidersHorizontal} tone="brand" onClick={() => openAdjust(row)} /> : null}
                        </div>
                      </TableCell>
                    </TableRow>
                  ))
                ) : (
                  <TableRow>
                    <TableCell colSpan={7} className="h-44 text-center text-muted-foreground">
                      <Wallet className="mx-auto mb-2 size-6 opacity-50" />
                      No employees match the selected filters.
                    </TableCell>
                  </TableRow>
                )}
              </TableBody>
            </Table>
          </div>

          <div className="divide-y divide-border/60 md:hidden dark:divide-white/10">
            {loading ? (
              <div className="flex h-40 items-center justify-center text-sm text-muted-foreground">
                <Loader2 className="mr-2 size-5 animate-spin text-brand" /> Loading leave credits...
              </div>
            ) : visibleRows.length ? (
              visibleRows.map((row) => (
                <div key={row.employee_id} className="space-y-4 p-4 transition-colors active:bg-muted/30">
                  <div className="flex items-start justify-between gap-3">
                    <div className="flex min-w-0 items-center gap-3">
                      <EmployeeAvatar row={row} className="size-10 text-xs" />
                      <div className="min-w-0">
                        <p className="truncate font-semibold text-foreground">{row.name || 'Unnamed employee'}</p>
                        <p className="mt-0.5 truncate text-xs text-muted-foreground">{row.employee_code || `Employee #${row.employee_id}`}</p>
                      </div>
                    </div>
                    <StatusBadge row={row} />
                  </div>
                  <div className="flex items-end justify-between gap-4">
                    <BalanceCell row={row} compact />
                    <div className="flex shrink-0 items-center gap-0.5 rounded-lg border border-border/60 bg-card p-0.5 shadow-sm dark:border-white/10">
                      <ActionIconButton label="View credit history" icon={History} onClick={() => openHistory(row)} />
                      {canAdjust ? <ActionIconButton label="Adjust credits" icon={SlidersHorizontal} tone="brand" onClick={() => openAdjust(row)} /> : null}
                    </div>
                  </div>
                  <div className="flex flex-wrap gap-x-4 gap-y-1 text-xs text-muted-foreground">
                    <span className="inline-flex items-center gap-1"><Building2 className="size-3.5" aria-hidden />{row.department_name || 'No department'}</span>
                    <span>Reserved: <strong className="font-semibold text-foreground">{formatNumber(row.pending_reserved_days)}</strong> days</span>
                  </div>
                </div>
              ))
            ) : (
              <div className="flex h-40 items-center justify-center px-5 text-center text-sm text-muted-foreground">No employees match the selected filters.</div>
            )}
          </div>

          {!loading && filteredRows.length > 0 ? (
            <div className="flex flex-col gap-3 border-t border-border/60 bg-muted/[0.16] px-4 py-3 text-xs dark:border-white/10 dark:bg-white/[0.02] @sm:flex-row @sm:items-center @sm:justify-between">
              <p className="text-muted-foreground">
                Showing <span className="font-semibold text-foreground">{(page - 1) * PAGE_SIZE + 1}-{Math.min(page * PAGE_SIZE, filteredRows.length)}</span> of {filteredRows.length}
              </p>
              <div className="flex items-center gap-2">
                <Button type="button" variant="outline" size="icon-xs" aria-label="Previous page" disabled={page <= 1} onClick={() => setPage((current) => current - 1)}><ChevronLeft className="size-3.5" /></Button>
                <span className="min-w-20 text-center tabular-nums text-muted-foreground">Page {page} of {pageCount}</span>
                <Button type="button" variant="outline" size="icon-xs" aria-label="Next page" disabled={page >= pageCount} onClick={() => setPage((current) => current + 1)}><ChevronRight className="size-3.5" /></Button>
              </div>
            </div>
          ) : null}
        </CardContent>
      </Card>

      <Dialog open={scheduleOpen} onOpenChange={(open) => !open && !scheduleSaving && setScheduleOpen(false)}>
        <DialogContent className={appModalDialogContentClass({ size: 'sm', className: 'max-w-[min(100vw-1.25rem,40rem)] sm:max-w-[min(100vw-2rem,40rem)]' })} innerClassName={APP_MODAL_INNER_FLUSH}>
          <AppModalHeader className="bg-brand/[0.035]">
            <div className="flex items-start gap-3">
              <span className="flex size-10 shrink-0 items-center justify-center rounded-xl bg-brand/10 text-brand ring-1 ring-brand/15">
                <CalendarClock className="size-5" aria-hidden />
              </span>
              <div className="min-w-0">
                <AppModalTitle>Recharge schedule</AppModalTitle>
                <AppModalDescription className="mt-1">
                  Choose the annual date when eligible leave balances are fully recharged.
                </AppModalDescription>
              </div>
            </div>
          </AppModalHeader>

          <div className={cn(APP_MODAL_FORM_BODY, 'gap-5')}>
            <div className="rounded-lg border border-brand/20 bg-brand/[0.045] px-3.5 py-3 dark:border-brand/25 dark:bg-brand/[0.07]">
              <p className="text-[10px] font-bold uppercase tracking-[0.12em] text-brand">Current rule</p>
              <p className="mt-1 text-sm font-semibold leading-relaxed text-foreground">
                {schedule?.policy || 'Recharges on January 1st every year (full reset; unused credits do not carry over).'}
              </p>
            </div>

            <div className="grid gap-4 @sm:grid-cols-2">
              <div className="space-y-2">
                <Label htmlFor="leave-recharge-month" className="text-sm font-semibold">Month</Label>
                <Select value={scheduleMonth} onValueChange={handleScheduleMonthChange}>
                  <SelectTrigger
                    id="leave-recharge-month"
                    className="data-[size=default]:h-12 h-12 w-full rounded-xl border-border/70 bg-card px-4 text-sm font-semibold shadow-sm transition-colors hover:border-brand/40 hover:bg-muted/40 focus-visible:border-brand focus-visible:ring-brand/15 dark:bg-input/30 dark:hover:bg-input/50 [&_svg:not([class*='text-'])]:text-brand/70"
                  >
                    <CalendarDays className="size-4" aria-hidden />
                    <SelectValue placeholder="Select month" />
                  </SelectTrigger>
                  <SelectContent className="rounded-xl border-brand/10 p-1.5 shadow-lg">
                    {RECHARGE_MONTHS.map((month, index) => (
                      <SelectItem key={month} value={String(index + 1)} className="rounded-lg py-2.5 pl-3 pr-8 text-sm font-medium focus:bg-brand/[0.08] focus:text-foreground">
                        {month}
                      </SelectItem>
                    ))}
                  </SelectContent>
                </Select>
              </div>

              <div className="space-y-2">
                <Label htmlFor="leave-recharge-day" className="text-sm font-semibold">Day</Label>
                <Select value={scheduleDay} onValueChange={setScheduleDay}>
                  <SelectTrigger
                    id="leave-recharge-day"
                    className="data-[size=default]:h-12 h-12 w-full rounded-xl border-border/70 bg-card px-4 text-sm font-semibold shadow-sm transition-colors hover:border-brand/40 hover:bg-muted/40 focus-visible:border-brand focus-visible:ring-brand/15 dark:bg-input/30 dark:hover:bg-input/50 [&_svg:not([class*='text-'])]:text-brand/70"
                  >
                    <Hash className="size-4" aria-hidden />
                    <SelectValue placeholder="Select day" />
                  </SelectTrigger>
                  <SelectContent className="rounded-xl border-brand/10 p-1.5 shadow-lg">
                    {Array.from({ length: daysInMonth(scheduleMonth) }, (_, index) => index + 1).map((day) => (
                      <SelectItem key={day} value={String(day)} className="rounded-lg py-2.5 pl-3 pr-8 text-sm font-semibold tabular-nums focus:bg-brand/[0.08] focus:text-foreground">
                        {String(day).padStart(2, '0')}
                      </SelectItem>
                    ))}
                  </SelectContent>
                </Select>
              </div>
            </div>

            <div className="flex items-start gap-3 rounded-lg border border-border/60 bg-muted/20 px-3.5 py-3 dark:border-white/10 dark:bg-white/[0.025]">
              <CalendarClock className="mt-0.5 size-4 shrink-0 text-brand" aria-hidden />
              <div className="min-w-0 text-sm leading-relaxed">
                <p className="font-semibold text-foreground">
                  Recharges every year on {rechargeDateLabel(scheduleMonth, scheduleDay)}.
                </p>
                <p className="mt-1 text-xs text-muted-foreground">
                  Eligible balances reset to {formatNumber(annualAllocation)} credits. Unused credits do not carry over.
                </p>
              </div>
            </div>
          </div>

          <AppModalFooter>
            <p className="text-xs leading-relaxed text-muted-foreground">A date already reached this year will recharge eligible employees immediately.</p>
            <div className={APP_MODAL_FOOTER_ACTIONS}>
              <Button type="button" variant="outline" className={APP_MODAL_OUTLINE_BUTTON_CLASS} onClick={() => setScheduleOpen(false)} disabled={scheduleSaving}>Cancel</Button>
              <Button type="button" className={APP_MODAL_PRIMARY_BUTTON_CLASS} onClick={submitSchedule} disabled={scheduleSaving}>
                {scheduleSaving ? <Loader2 className="size-4 animate-spin" /> : <CheckCircle2 className="size-4" />}
                Save schedule
              </Button>
            </div>
          </AppModalFooter>
        </DialogContent>
      </Dialog>

      <Dialog open={!!adjustEmployee} onOpenChange={(open) => !open && closeAdjust()}>
        <DialogContent className={appModalDialogContentClass({ size: 'sm' })} innerClassName={APP_MODAL_INNER_FLUSH}>
          <AppModalHeader className="bg-brand/[0.035]">
            <div className="flex items-start gap-3">
              <span className="flex size-10 shrink-0 items-center justify-center rounded-xl bg-brand/10 text-brand ring-1 ring-brand/15">
                <SlidersHorizontal className="size-5" aria-hidden />
              </span>
              <div className="min-w-0">
                <AppModalTitle>Adjust leave credits</AppModalTitle>
                <AppModalDescription className="mt-1">
                  {adjustEmployee?.name || 'Employee'} · {organizationLabel(adjustEmployee)}
                </AppModalDescription>
              </div>
            </div>
          </AppModalHeader>

          <div className={cn(APP_MODAL_FORM_BODY, 'gap-5')}>
            <div className="grid grid-cols-2 gap-2">
              <div className="rounded-lg border border-border/60 bg-muted/20 px-3.5 py-3 dark:border-white/10 dark:bg-white/[0.025]">
                <p className="text-[10px] font-bold uppercase tracking-[0.12em] text-muted-foreground">Current</p>
                <p className="mt-1 text-2xl font-bold tracking-tight tabular-nums text-foreground">{formatNumber(adjustEmployee?.leave_credits_remaining)}</p>
                <p className="text-xs text-muted-foreground">available credits</p>
              </div>
              <div className="rounded-lg border border-brand/25 bg-brand/[0.06] px-3.5 py-3 dark:border-brand/30 dark:bg-brand/[0.08]">
                <p className="text-[10px] font-bold uppercase tracking-[0.12em] text-brand">New balance</p>
                <p className="mt-1 text-2xl font-bold tracking-tight tabular-nums text-foreground">{formatNumber(projectedBalance)}</p>
                <p className="text-xs text-muted-foreground">after this change</p>
              </div>
            </div>

            <div className="space-y-2">
              <Label className="text-sm font-semibold">Change type</Label>
              <div className="grid grid-cols-2 gap-2 rounded-lg border border-border/60 bg-muted/20 p-1.5 dark:border-white/10 dark:bg-white/[0.025]" role="radiogroup" aria-label="Change type">
                <button
                  type="button"
                  role="radio"
                  aria-checked={adjustmentMode === 'add'}
                  className={cn('flex min-h-11 items-center gap-2 rounded-md px-3 text-left text-sm font-semibold transition-colors', adjustmentMode === 'add' ? 'bg-emerald-600 text-white shadow-sm' : 'text-muted-foreground hover:bg-card hover:text-foreground')}
                  onClick={() => setAdjustmentMode('add')}
                >
                  <Plus className="size-4" aria-hidden />
                  Add credits
                </button>
                <button
                  type="button"
                  role="radio"
                  aria-checked={adjustmentMode === 'reduce'}
                  className={cn('flex min-h-11 items-center gap-2 rounded-md px-3 text-left text-sm font-semibold transition-colors', adjustmentMode === 'reduce' ? 'bg-amber-600 text-white shadow-sm' : 'text-muted-foreground hover:bg-card hover:text-foreground')}
                  onClick={() => setAdjustmentMode('reduce')}
                >
                  <Minus className="size-4" aria-hidden />
                  Reduce credits
                </button>
              </div>
            </div>

            <div className="space-y-2">
              <Label htmlFor="leave-credit-amount" className="text-sm font-semibold">Number of credits</Label>
              <Input
                id="leave-credit-amount"
                type="number"
                min="1"
                step="1"
                inputMode="numeric"
                value={adjustmentAmount}
                onChange={(event) => setAdjustmentAmount(event.target.value)}
                placeholder="Enter a whole number"
                className="h-11 border-border/70 bg-card text-base shadow-none"
              />
              <p className="text-xs text-muted-foreground">A reduction cannot make the balance negative.</p>
            </div>

            <div className="space-y-2">
              <div className="flex items-center justify-between gap-3">
                <Label htmlFor="leave-credit-reason" className="text-sm font-semibold">Reason for change</Label>
                <span className="text-[11px] tabular-nums text-muted-foreground">{adjustmentReason.length}/2000</span>
              </div>
              <Textarea
                id="leave-credit-reason"
                value={adjustmentReason}
                onChange={(event) => setAdjustmentReason(event.target.value)}
                placeholder="Explain why this balance is being changed"
                rows={4}
                maxLength={2000}
                className="resize-y border-border/70 bg-card shadow-none"
              />
            </div>
          </div>

          <AppModalFooter>
            <p className="text-xs leading-relaxed text-muted-foreground">This change will appear in the employee's credit history.</p>
            <div className={APP_MODAL_FOOTER_ACTIONS}>
              <Button type="button" variant="outline" className={APP_MODAL_OUTLINE_BUTTON_CLASS} onClick={closeAdjust} disabled={adjustmentSaving}>Cancel</Button>
              <Button type="button" className={APP_MODAL_PRIMARY_BUTTON_CLASS} onClick={submitAdjustment} disabled={adjustmentSaving || !parsedAmount || !adjustmentReason.trim()}>
                {adjustmentSaving ? <Loader2 className="size-4 animate-spin" /> : <CheckCircle2 className="size-4" />}
                Save adjustment
              </Button>
            </div>
          </AppModalFooter>
        </DialogContent>
      </Dialog>

      <Dialog open={!!historyEmployee} onOpenChange={(open) => !open && setHistoryEmployee(null)}>
        <DialogContent className={appModalDialogContentClass({ size: 'md' })} innerClassName={APP_MODAL_INNER_FLUSH}>
          <AppModalHeader>
            <div className="flex items-start gap-3">
              <span className="flex size-10 shrink-0 items-center justify-center rounded-xl bg-sky-500/10 text-sky-600 ring-1 ring-sky-500/15 dark:text-sky-300">
                <History className="size-5" aria-hidden />
              </span>
              <div className="min-w-0">
                <AppModalTitle>Leave credit history</AppModalTitle>
                <AppModalDescription className="mt-1">
                  {historyEmployee?.name || 'Employee'} · {organizationLabel(historyEmployee)}
                </AppModalDescription>
              </div>
            </div>
          </AppModalHeader>

          <div className={cn(APP_MODAL_FORM_BODY, 'gap-5')}>
            <div className="grid gap-2 @sm:grid-cols-3">
              <div className="rounded-lg border border-border/60 bg-muted/20 px-3.5 py-3 dark:border-white/10 dark:bg-white/[0.025]">
                <p className="text-[10px] font-bold uppercase tracking-[0.12em] text-muted-foreground">Remaining</p>
                <p className="mt-1 text-xl font-bold tabular-nums text-foreground">{formatNumber(historySummary.remaining ?? historySummary.leave_credits_remaining)}</p>
                <p className="text-xs text-muted-foreground">credits in pool</p>
              </div>
              <div className="rounded-lg border border-border/60 bg-muted/20 px-3.5 py-3 dark:border-white/10 dark:bg-white/[0.025]">
                <p className="text-[10px] font-bold uppercase tracking-[0.12em] text-muted-foreground">Usable now</p>
                <p className="mt-1 text-xl font-bold tabular-nums text-foreground">{formatNumber(historySummary.effective_available)}</p>
                <p className="text-xs text-muted-foreground">after reservations</p>
              </div>
              <div className="rounded-lg border border-border/60 bg-muted/20 px-3.5 py-3 dark:border-white/10 dark:bg-white/[0.025]">
                <p className="text-[10px] font-bold uppercase tracking-[0.12em] text-muted-foreground">Annual pool</p>
                <p className="mt-1 text-xl font-bold tabular-nums text-foreground">{formatNumber(historySummary.annual_allocation ?? annualAllocation)}</p>
                <p className="text-xs text-muted-foreground">current allocation</p>
              </div>
            </div>

            <div className="overflow-hidden rounded-lg border border-border/70 dark:border-white/10">
              <div className="flex items-center justify-between gap-3 border-b border-border/60 bg-muted/25 px-4 py-3 dark:border-white/10 dark:bg-white/[0.025]">
                <div>
                  <p className="text-sm font-semibold text-foreground">Activity log</p>
                  <p className="mt-0.5 text-xs text-muted-foreground">Newest changes appear first.</p>
                </div>
                <Badge variant="secondary" className="tabular-nums">{historyRows.length} entries</Badge>
              </div>
              <div className="max-h-[min(45vh,28rem)] overflow-y-auto">
                {historyLoading ? (
                  <div className="flex h-36 items-center justify-center text-sm text-muted-foreground">
                    <Loader2 className="mr-2 size-4 animate-spin text-brand" /> Loading history...
                  </div>
                ) : historyRows.length ? (
                  <div className="divide-y divide-border/60 dark:divide-white/10">
                    {historyRows.map((entry) => {
                      const positive = Number(entry.delta) > 0
                      const ChangeIcon = positive ? ArrowUpFromLine : ArrowDownToLine
                      return (
                        <div key={entry.id} className="flex gap-3 px-4 py-3.5 transition-colors hover:bg-muted/20 dark:hover:bg-white/[0.025]">
                          <span className={cn('mt-0.5 flex size-8 shrink-0 items-center justify-center rounded-lg', positive ? 'bg-emerald-500/10 text-emerald-600 dark:text-emerald-300' : 'bg-amber-500/10 text-amber-700 dark:text-amber-300')}>
                            {createElement(ChangeIcon, { className: 'size-4', 'aria-hidden': true })}
                          </span>
                          <div className="min-w-0 flex-1">
                            <div className="flex flex-wrap items-center gap-x-2 gap-y-1">
                              <span className="text-sm font-semibold text-foreground">{transactionLabel(entry.change_type)}</span>
                              <span className={cn('text-sm font-bold tabular-nums', transactionTone(entry.change_type))}>
                                {Number(entry.delta) > 0 ? '+' : ''}{formatNumber(entry.delta)} credits
                              </span>
                            </div>
                            <p className="mt-1 break-words text-sm leading-relaxed text-muted-foreground">{entry.reason || 'No reason recorded'}</p>
                            <p className="mt-1.5 text-[11px] text-muted-foreground">
                              {formatDateTime(entry.created_at)}{entry.actor_name ? ` · by ${entry.actor_name}` : ''}
                            </p>
                          </div>
                          <div className="shrink-0 text-right">
                            <p className="text-[10px] font-semibold uppercase tracking-[0.1em] text-muted-foreground">Balance</p>
                            <p className="mt-1 text-sm font-bold tabular-nums text-foreground">{formatNumber(entry.balance_after)}</p>
                          </div>
                        </div>
                      )
                    })}
                  </div>
                ) : (
                  <div className="flex h-36 flex-col items-center justify-center px-5 text-center text-sm text-muted-foreground">
                    <History className="mb-2 size-6 opacity-45" />
                    No credit transactions have been recorded for this employee.
                  </div>
                )}
              </div>
            </div>
          </div>

          <AppModalFooter>
            <p className="text-xs text-muted-foreground">Audit history is read-only.</p>
            <div className={APP_MODAL_FOOTER_ACTIONS}>
              <Button type="button" variant="outline" className={APP_MODAL_OUTLINE_BUTTON_CLASS} onClick={() => setHistoryEmployee(null)}>Close</Button>
            </div>
          </AppModalFooter>
        </DialogContent>
      </Dialog>
    </div>
    </TooltipProvider>
  )
}
