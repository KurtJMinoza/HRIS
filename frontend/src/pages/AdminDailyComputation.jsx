import React, { useState, useMemo, useEffect, useLayoutEffect, useCallback } from 'react'
import { motion as Motion } from 'framer-motion'
import {
  Search,
  Calendar,
  Download,
  ChevronDown,
  ChevronUp,
  ChevronRight,
  AlertTriangle,
  Check,
  CheckCircle2,
  AlertCircle,
  Eye,
  Clock,
  Moon,
  RotateCcw,
} from 'lucide-react'
import { Button } from '@/components/ui/button'
import { Badge } from '@/components/ui/badge'
import { Input } from '@/components/ui/input'
import { Card, CardContent, CardDescription, CardHeader, CardTitle } from '@/components/ui/card'
import { useAuth } from '@/contexts/AuthContext'
import { cn } from '@/lib/utils'
import { FIELD_SELECT_CLASS_H10 } from '@/lib/fieldClasses'
import { AuditDetailDrawer } from '@/components/AuditDetailDrawer'
import { DailyComputationSubNav } from '@/components/DailyComputationSubNav'
import { Skeleton } from '@/components/ui/skeleton'
import { getAdminDailyComputationLogs, userProfileImageSrc } from '@/api'
import { formatScheduleLabel12h, formatShiftRange12h } from '@/lib/timeFormat'
import { toast } from 'sonner'


const DAY_TYPE_STYLES = {
  ORDINARY: 'bg-slate-100 text-slate-700 border-slate-200/80 dark:bg-slate-800 dark:text-slate-200 dark:border-slate-600',
  'REST DAY': 'bg-sky-50 text-sky-900 border-sky-200/60 dark:bg-sky-950/50 dark:text-sky-200 dark:border-sky-800',
  HOLIDAY: 'bg-amber-50 text-amber-900 border-amber-200/60 dark:bg-amber-950/40 dark:text-amber-200 dark:border-amber-800',
}

const STATUS_CONFIG = {
  valid: { label: 'Valid', icon: CheckCircle2, className: 'bg-emerald-50 text-emerald-800 border-emerald-200/70 dark:bg-emerald-950/40 dark:text-emerald-200 dark:border-emerald-800/50' },
  needs_review: { label: 'Needs review', icon: AlertTriangle, className: 'bg-amber-50 text-amber-900 border-amber-200/70 dark:bg-slate-800/80 dark:text-amber-200 dark:border-amber-500/35' },
  flagged: { label: 'Flagged', icon: AlertCircle, className: 'bg-red-50 text-red-800 border-red-200/70 dark:bg-slate-800/80 dark:text-red-200 dark:border-red-500/40' },
}

const FLAG_STYLES = {
  EXCESSIVE_OT: 'bg-rose-50 text-rose-800 border-rose-200/70 dark:bg-rose-950/40 dark:text-rose-200',
  MANUAL_PUNCH_ADJ: 'bg-sky-50 text-sky-800 border-sky-200/70 dark:bg-sky-950/40 dark:text-sky-200',
  OT_NOT_FILED: 'bg-slate-100 text-slate-800 border-slate-300/70 dark:bg-slate-900/50 dark:text-slate-200 dark:border-slate-600/50',
  ND_PREMIUM_BLOCKED: 'bg-orange-50 text-orange-900 border-orange-200/70 dark:bg-orange-950/40 dark:text-orange-200',
  UNAPPROVED_OT: 'bg-orange-50 text-orange-900 border-orange-200/70 dark:bg-orange-950/40 dark:text-orange-200',
  PENDING_OT_REVIEW: 'bg-indigo-50 text-indigo-900 border-indigo-200/70 dark:bg-indigo-950/40 dark:text-indigo-200',
  LATE_DEDUCTION: 'bg-violet-50 text-violet-800 border-violet-200/70 dark:bg-violet-950/40 dark:text-violet-200',
  MISSING_TIME: 'bg-amber-50 text-amber-800 border-amber-200/70 dark:bg-amber-950/40 dark:text-amber-200',
  INVALID_SCHEDULE: 'bg-slate-100 text-slate-600 border-slate-200/70 dark:bg-slate-800 dark:text-slate-400',
}

function parseHrsToMinutes(hrs) {
  if (!hrs) return 0
  const [h, m] = String(hrs).split(':').map(Number)
  return (h || 0) * 60 + (m || 0)
}

/** Local calendar date as YYYY-MM-DD (avoids UTC shift from toISOString). */
function formatYmdLocal(d) {
  const y = d.getFullYear()
  const m = String(d.getMonth() + 1).padStart(2, '0')
  const day = String(d.getDate()).padStart(2, '0')
  return `${y}-${m}-${day}`
}

function parseYmdLocal(ymd) {
  if (!ymd || typeof ymd !== 'string') return new Date(NaN)
  const parts = ymd.split('-').map(Number)
  if (parts.length !== 3 || parts.some((n) => Number.isNaN(n))) return new Date(NaN)
  return new Date(parts[0], parts[1] - 1, parts[2])
}

function formatRangeDisplay(fromYmd, toYmd) {
  const a = parseYmdLocal(fromYmd)
  const b = parseYmdLocal(toYmd)
  if (Number.isNaN(a.getTime()) || Number.isNaN(b.getTime())) return '—'
  const opts = { month: 'short', day: 'numeric', year: 'numeric' }
  return `${a.toLocaleDateString('en-PH', opts)} – ${b.toLocaleDateString('en-PH', opts)}`
}

/** Fallback when API omits pre-formatted totals (older backend). */
function formatDecimalHoursToHhMm(hours) {
  if (!Number.isFinite(hours) || hours <= 0) return '00:00'
  const totalM = Math.round(hours * 60)
  const mm = ((totalM % 60) + 60) % 60
  const hh = Math.floor(totalM / 60)
  return `${String(hh).padStart(2, '0')}:${String(mm).padStart(2, '0')}`
}

function formatPeso(value) {
  const n = Number(value || 0)
  if (!Number.isFinite(n)) return 'PHP 0.00'
  return `PHP ${n.toLocaleString(undefined, { minimumFractionDigits: 2, maximumFractionDigits: 2 })}`
}

/** Backend on_time tardiness labels (incl. grace window). */
function isPresentOnTimeTardinessLabel(label) {
  return label === 'Present' || label === 'Present – Within Grace'
}

const CLOCKED_TOTAL_TOOLTIP =
  'Paid regular + rendered OT for this day (same basis as the computation engine). ND hours are in the night-differential window and overlap regular/OT time—they are not added again on top of this total.'

const HOURS_COLUMN_TOOLTIP =
  'Breakdown by bucket: Regular, rendered OT, and ND. ND is a subset of time that also falls in the night window (not additive to Regular + OT).'

/** Short OT workflow label for inline table display (badge already shows status elsewhere when needed). */
function otInlineStatusLabel(otStatus) {
  const s = otStatus ?? 'none'
  if (s === 'approved') return 'approved'
  if (s === 'not_filed') return 'no OT filed'
  if (s === 'pending_review') return 'pending review'
  if (s === 'partial_pending') return 'partially pending'
  if (s === 'unapproved') return 'unapproved'
  return ''
}

/** One-line tardiness for the data table (detail stays in Computation modal). */
function formatTardinessTableLine(row) {
  if (!row.tardiness_label) return null
  if (row.late_deduction_minutes > 0) {
    return `${row.tardiness_label} (−${(row.late_deduction_minutes / 60).toFixed(2)} hrs reg.)`
  }
  if (isPresentOnTimeTardinessLabel(row.tardiness_label)) {
    return `${row.tardiness_label} (no deduction)`
  }
  return row.tardiness_label
}

function ComputationLogsTableSkeleton({ rows = 10 }) {
  return (
    <>
      {Array.from({ length: rows }).map((_, i) => (
        <tr key={i} className={cn('border-b border-border/20 dark:border-border/40', i % 2 === 1 ? 'bg-white dark:bg-card' : 'bg-[#f8fafc] dark:bg-muted/15')}>
          <td className="w-10 px-2 py-2.5 pl-3"><Skeleton className="size-4 rounded" /></td>
          <td className="px-2 py-2.5">
            <div className="flex items-center gap-2.5">
              <Skeleton className="size-10 shrink-0 rounded-full" />
              <div className="space-y-1.5">
                <Skeleton className="h-4 w-36" />
                <Skeleton className="h-3 w-24" />
              </div>
            </div>
          </td>
          <td className="px-2 py-2.5"><Skeleton className="h-4 w-32" /></td>
          <td className="px-2 py-2.5"><Skeleton className="h-4 w-40" /></td>
          <td className="px-2 py-2.5"><Skeleton className="ml-auto h-4 w-12" /></td>
          <td className="px-2 py-2.5"><Skeleton className="h-7 w-36" /></td>
          <td className="px-2 py-2.5"><Skeleton className="mx-auto h-7 w-20" /></td>
          <td className="px-2 py-2.5 pr-3"><Skeleton className="ml-auto h-7 w-32" /></td>
        </tr>
      ))}
    </>
  )
}

export default function AdminDailyComputation() {
  useAuth()

  const now = useMemo(() => new Date(), [])
  const firstOfMonth = useMemo(
    () => formatYmdLocal(new Date(now.getFullYear(), now.getMonth(), 1)),
    [now]
  )
  const lastOfMonth = useMemo(
    () => formatYmdLocal(new Date(now.getFullYear(), now.getMonth() + 1, 0)),
    [now]
  )
  const defaultLast7From = useMemo(() => {
    const t = new Date()
    const start = new Date(t)
    start.setDate(start.getDate() - 6)
    return formatYmdLocal(start)
  }, [])
  const defaultToday = useMemo(() => formatYmdLocal(new Date()), [])

  const [searchEmployee, setSearchEmployee] = useState('')
  const [dateFrom, setDateFrom] = useState(defaultLast7From)
  const [dateTo, setDateTo] = useState(defaultToday)
  const [datePreset, setDatePreset] = useState('last7')
  const [statusFilter, setStatusFilter] = useState('all')
  /** Snapshot when user last clicked Apply (server query params) */
  const [appliedFilters, setAppliedFilters] = useState({
    dateFrom: defaultLast7From,
    dateTo: defaultToday,
    search: '',
    status: 'all',
  })
  const [expandedIds, setExpandedIds] = useState(new Set())
  const [drawerRecord, setDrawerRecord] = useState(null)
  const [drawerRefreshing, setDrawerRefreshing] = useState(false)
  const [loading, setLoading] = useState(false)
  const [loadError, setLoadError] = useState(null)
  const [records, setRecords] = useState([])
  const [meta, setMeta] = useState({ current_page: 1, last_page: 1, per_page: 10, total: 0 })
  const [summary, setSummary] = useState({
    anomaly_count: 0,
    total_ot_hours: 0,
    total_nd_hours: 0,
    total_logs: 0,
    total_rendered_display: null,
    total_regular_display: null,
    unique_employees: 0,
  })
  const [page, setPage] = useState(1)
  const [hideValidRows, setHideValidRows] = useState(false)
  const [showOnlyPremiums, setShowOnlyPremiums] = useState(false)
  const [refreshTick, setRefreshTick] = useState(0)
  const perPage = 10
  const totalRecords = meta.total ?? 0

  const filtersDirty = useMemo(
    () =>
      dateFrom !== appliedFilters.dateFrom ||
      dateTo !== appliedFilters.dateTo ||
      searchEmployee.trim() !== appliedFilters.search ||
      statusFilter !== appliedFilters.status,
    [dateFrom, dateTo, searchEmployee, statusFilter, appliedFilters],
  )

  const totalOt = summary.total_ot_hours ?? 0
  const totalNd = summary.total_nd_hours ?? 0
  const anomalyCount = summary.anomaly_count ?? 0
  const displayRecords = useMemo(() => {
    let rows = records
    if (hideValidRows) {
      rows = rows.filter((r) => r.status !== 'valid')
    }
    if (showOnlyPremiums) {
      rows = rows.filter((r) => parseHrsToMinutes(r.ot) > 0 || parseHrsToMinutes(r.nd) > 0)
    }
    return rows
  }, [records, hideValidRows, showOnlyPremiums])

  const heroRenderedDisplay = useMemo(() => {
    if (summary.total_rendered_display) return summary.total_rendered_display
    const sumH = records.reduce((acc, r) => acc + parseHrsToMinutes(r.totalHrs) / 60, 0)
    return formatDecimalHoursToHhMm(sumH)
  }, [summary.total_rendered_display, records])

  const regularDisplayFallback = useMemo(() => {
    if (summary.total_regular_display) return summary.total_regular_display
    const sum = records.reduce((acc, r) => acc + parseHrsToMinutes(r.regular) / 60, 0)
    return formatDecimalHoursToHhMm(sum)
  }, [summary.total_regular_display, records])

  const totalRenderedMinutes = useMemo(
    () => Math.max(0, parseHrsToMinutes(heroRenderedDisplay)),
    [heroRenderedDisplay]
  )

  /** First row with schedule metadata (API: schedule_rate_basis); used for the rate explainer strip. */
  const scheduleRateBasisSample = useMemo(() => {
    const r = records.find((x) => x.schedule_rate_basis)
    return r?.schedule_rate_basis ?? null
  }, [records])

  const pctOfRenderedTotal = (minutes) => {
    if (!totalRenderedMinutes || totalRenderedMinutes <= 0) return 0
    return Math.min(100, (minutes / totalRenderedMinutes) * 100)
  }

  const formatPct1 = (minutes) => {
    if (!totalRenderedMinutes || totalRenderedMinutes <= 0) return '0.0'
    return ((minutes / totalRenderedMinutes) * 100).toFixed(1)
  }

  const applyDatePreset = (preset) => {
    const t = new Date()
    const ymd = (d) => formatYmdLocal(d)

    if (preset === 'today') {
      const d = ymd(t)
      setDateFrom(d); setDateTo(d); setDatePreset('today')
    } else if (preset === 'yesterday') {
      const y = new Date(t); y.setDate(y.getDate() - 1)
      const d = ymd(y)
      setDateFrom(d); setDateTo(d); setDatePreset('yesterday')
    } else if (preset === 'last7') {
      const end = new Date(t)
      const start = new Date(t); start.setDate(start.getDate() - 6)
      setDateFrom(ymd(start)); setDateTo(ymd(end)); setDatePreset('last7')
    } else if (preset === 'week') {
      const start = new Date(t); start.setDate(t.getDate() - t.getDay())
      const end = new Date(start); end.setDate(start.getDate() + 6)
      setDateFrom(ymd(start)); setDateTo(ymd(end)); setDatePreset('week')
    } else if (preset === 'month') {
      setDateFrom(firstOfMonth); setDateTo(lastOfMonth); setDatePreset('month')
    } else if (preset === 'last30') {
      const end = new Date(t)
      const start = new Date(t); start.setDate(start.getDate() - 29)
      setDateFrom(ymd(start)); setDateTo(ymd(end)); setDatePreset('last30')
    }
  }

  const totalPages = Math.max(1, meta.last_page ?? Math.ceil(totalRecords / perPage))

  const toggleExpand = (id) => {
    setExpandedIds((prev) => {
      const next = new Set(prev)
      if (next.has(id)) next.delete(id)
      else next.add(id)
      return next
    })
  }

  const handleApplyFilters = () => {
    setAppliedFilters({
      dateFrom,
      dateTo,
      search: searchEmployee.trim(),
      status: statusFilter,
    })
    setPage(1)
  }

  const handleResetFilters = () => {
    setDateFrom(defaultLast7From)
    setDateTo(defaultToday)
    setDatePreset('last7')
    setSearchEmployee('')
    setStatusFilter('all')
    setHideValidRows(false)
    setShowOnlyPremiums(false)
    setAppliedFilters({
      dateFrom: defaultLast7From,
      dateTo: defaultToday,
      search: '',
      status: 'all',
    })
    setPage(1)
  }

  /** Keep pagination aligned with filter range — avoids empty/wrong page after narrowing dates (server clamps page; state must reset). */
  useLayoutEffect(() => {
    setPage(1)
  }, [dateFrom, dateTo, searchEmployee, statusFilter])

  const refreshDrawerRecord = useCallback(async () => {
    if (!drawerRecord?.id) return
    setDrawerRefreshing(true)
    setLoadError(null)
    try {
      const res = await getAdminDailyComputationLogs({
        from_date: appliedFilters.dateFrom,
        to_date: appliedFilters.dateTo,
        start_date: appliedFilters.dateFrom,
        end_date: appliedFilters.dateTo,
        search: appliedFilters.search || undefined,
        status: appliedFilters.status,
        page,
        per_page: perPage,
      })
      const list = Array.isArray(res.data) ? res.data : []
      const next = list.find((r) => r.id === drawerRecord.id)
      if (next) {
        setDrawerRecord(next)
        setRecords(list)
        const nextMeta = res.meta || { current_page: 1, last_page: 1, per_page: perPage, total: 0 }
        setMeta(nextMeta)
        if (typeof nextMeta.current_page === 'number' && nextMeta.current_page >= 1) {
          setPage(nextMeta.current_page)
        }
        setSummary(
          res.summary || {
            anomaly_count: 0,
            total_ot_hours: 0,
            total_nd_hours: 0,
            total_logs: 0,
            total_rendered_display: null,
            total_regular_display: null,
            unique_employees: 0,
          }
        )
      } else {
        toast.info('Row not on this page', {
          description: 'Close the panel, change page or filters, then open the row again.',
        })
      }
    } catch (e) {
      toast.error('Refresh failed', { description: e instanceof Error ? e.message : 'Could not reload' })
    } finally {
      setDrawerRefreshing(false)
    }
  }, [
    drawerRecord?.id,
    appliedFilters.dateFrom,
    appliedFilters.dateTo,
    appliedFilters.search,
    appliedFilters.status,
    page,
    perPage,
  ])

  useEffect(() => {
    let cancelled = false
    async function load() {
      setLoading(true)
      setLoadError(null)
      try {
        const res = await getAdminDailyComputationLogs({
          from_date: appliedFilters.dateFrom,
          to_date: appliedFilters.dateTo,
          start_date: appliedFilters.dateFrom,
          end_date: appliedFilters.dateTo,
          search: appliedFilters.search || undefined,
          status: appliedFilters.status,
          page,
          per_page: perPage,
        })
        if (cancelled) return
        setRecords(Array.isArray(res.data) ? res.data : [])
        const nextMeta = res.meta || { current_page: 1, last_page: 1, per_page: perPage, total: 0 }
        setMeta(nextMeta)
        if (typeof nextMeta.current_page === 'number' && nextMeta.current_page >= 1) {
          setPage(nextMeta.current_page)
        }
        setSummary(
          res.summary || {
            anomaly_count: 0,
            total_ot_hours: 0,
            total_nd_hours: 0,
            total_logs: 0,
            total_rendered_display: null,
            total_regular_display: null,
            unique_employees: 0,
          }
        )
      } catch (e) {
        if (!cancelled) {
          setLoadError(e instanceof Error ? e.message : 'Failed to load logs')
          setRecords([])
          setMeta({ current_page: 1, last_page: 1, per_page: perPage, total: 0 })
        }
      } finally {
        if (!cancelled) setLoading(false)
      }
    }
    load()
    return () => { cancelled = true }
  }, [appliedFilters.dateFrom, appliedFilters.dateTo, appliedFilters.search, appliedFilters.status, page, refreshTick])

  useEffect(() => {
    const timer = window.setInterval(() => {
      if (document.hidden) return
      setRefreshTick((n) => n + 1)
    }, 30000)
    return () => window.clearInterval(timer)
  }, [])

  const formatHrs = (hrs, type = 'default') => {
    const mins = parseHrsToMinutes(hrs)
    const longClockedDay = type === 'total' && hrs && mins > 12 * 60
    const hasOt = type === 'ot' && mins > 0
    const hasNd = type === 'nd' && mins > 0
    const totalTitle =
      hrs && type === 'total'
        ? `${CLOCKED_TOTAL_TOOLTIP}${
            longClockedDay
              ? ' This row shows a longer clocked day (>12h); that is valid when OT and rules align — not an error state.'
              : ''
          }`
        : null
    return (
      <span
        className={cn(
          'font-mono text-sm tabular-nums leading-none',
          'text-foreground',
          type === 'ot' && hasOt && 'text-amber-700 dark:text-amber-400',
          type === 'ot' && !hasOt && 'text-muted-foreground/65',
          type === 'nd' && hasNd && 'text-violet-700 dark:text-violet-400',
          type === 'nd' && !hasNd && 'text-muted-foreground/65',
          type === 'default' && !hrs && 'text-muted-foreground'
        )}
        title={
          hrs
            ? totalTitle ??
              `${type === 'ot' ? 'Rendered OT' : type === 'nd' ? 'Night diff' : 'Regular'}: ${hrs}`
            : '—'
        }
      >
        {hrs || '—'}
      </span>
    )
  }

  return (
    <Motion.div
      className="mx-auto w-full max-w-[1600px] space-y-5 px-1 @sm:px-0 @md:space-y-6"
      initial={{ opacity: 0, y: 10 }}
      animate={{ opacity: 1, y: 0 }}
      transition={{ duration: 0.25, ease: [0.23, 1, 0.32, 1] }}
    >
      <div className="flex flex-col gap-3 @sm:flex-row @sm:items-center @sm:justify-between">
        <div>
          <h1 className="hr-page-title">Daily computation</h1>
          <CardDescription className="text-xs leading-relaxed text-muted-foreground @sm:text-sm">
            PH rules engine: schedules, holidays, rendered OT (after shift end), ND from active pay policy (or DOLE
            22:00–06:00 default).
          </CardDescription>
        </div>
      </div>

      <DailyComputationSubNav />

      {loadError && (
        <div className="rounded-md border border-destructive/50 bg-destructive/10 px-4 py-2 text-sm text-destructive">
          {loadError}
        </div>
      )}

      {/* Period summary — aligned with Admin Overview: neutral Card, muted chrome, thin bars */}
      <Card className="overflow-hidden border-border/70 bg-card/95 shadow-sm dark:border-white/10">
        <CardContent className="p-5 @md:p-6">
        {loading && records.length === 0 ? (
          <div className="flex flex-col gap-5">
            <div className="flex flex-col gap-3 @md:flex-row @md:items-start @md:justify-between">
              <div className="space-y-3">
                <Skeleton className="h-6 w-48 rounded-full" />
                <Skeleton className="h-10 w-32" />
                <Skeleton className="h-3 w-64" />
              </div>
              <Skeleton className="size-9 shrink-0 rounded-lg" />
            </div>
            <div>
              <Skeleton className="mb-2 h-2.5 w-28" />
              <div className="grid grid-cols-2 gap-3 @lg:grid-cols-4">
                {Array.from({ length: 4 }).map((_, i) => (
                  <Skeleton key={i} className="h-36 rounded-lg" />
                ))}
              </div>
            </div>
          </div>
        ) : (
          <div className="flex flex-col gap-6 @md:gap-8">
            {/* Hero: tertiary (range) → primary (total hrs) → secondary (export) */}
            <div className="flex flex-col gap-6 @lg:flex-row @lg:items-stretch @lg:justify-between @lg:gap-8">
              <div className="min-w-0 flex-1 space-y-2">
                <p className="text-[11px] font-semibold uppercase tracking-wider text-muted-foreground">Selected range</p>
                <div className="inline-flex max-w-full items-center gap-2 rounded-md border border-border/70 bg-muted/25 px-3 py-1.5 text-sm tabular-nums text-foreground dark:border-white/10">
                  <Calendar className="size-4 shrink-0 text-muted-foreground" aria-hidden />
                  <span className="truncate font-medium">{formatRangeDisplay(dateFrom, dateTo)}</span>
                </div>
              </div>

              <div className="flex flex-col items-center justify-center rounded-lg border border-border/60 bg-muted/20 px-6 py-5 dark:border-white/10 dark:bg-muted/15 @lg:min-w-[min(100%,280px)] @lg:flex-1 @lg:px-8">
                <p
                  className="text-center font-mono text-4xl font-bold leading-none tracking-tight text-foreground tabular-nums @md:text-5xl"
                  title="Sum of rendered hours in this date range"
                >
                  {heroRenderedDisplay}
                </p>
                <p className="mt-2 text-center text-[11px] font-semibold uppercase tracking-wider text-muted-foreground">
                  Total rendered hours
                </p>
              </div>

              <div className="flex flex-col justify-center gap-1 @lg:min-w-30 @lg:items-end">
                <span className="text-[11px] font-semibold uppercase tracking-wider text-muted-foreground @lg:text-right">
                  Reports
                </span>
                <Button
                  type="button"
                  variant="outline"
                  size="sm"
                  className="h-9 w-full gap-2 border-border/70 @lg:w-auto"
                  title="Export CSV / PDF (coming soon)"
                  disabled
                  aria-label="Export CSV or PDF, coming soon"
                >
                  <Download className="size-4 shrink-0 text-muted-foreground" aria-hidden />
                  Export
                </Button>
              </div>
            </div>

            {/* Period breakdown — hierarchy: section label → metric (title + caption) → value + % → bar (color = data) */}
            <div className="border-t border-border/40 pt-6 dark:border-white/10">
              <div className="mb-4">
                <h3 className="text-[11px] font-semibold uppercase tracking-wider text-muted-foreground">Period breakdown</h3>
                <p className="mt-1 text-xs text-muted-foreground/90">
                  Share of total rendered hours (Regular, OT, ND). Review shows % of day-rows flagged.
                </p>
              </div>
              {(() => {
                const regM = parseHrsToMinutes(regularDisplayFallback)
                const otM = Math.max(0, totalOt * 60)
                const ndM = Math.max(0, totalNd * 60)
                const pctReg = pctOfRenderedTotal(regM)
                const pctOt = pctOfRenderedTotal(otM)
                const pctNd = pctOfRenderedTotal(ndM)
                const otStrong = totalOt >= 0.01
                const ndStrong = totalNd >= 0.01
                const ndDisplay =
                  summary.total_nd_display ?? (totalNd < 0.01 ? '00:00' : formatDecimalHoursToHhMm(totalNd))
                const reviewRowPct =
                  totalRecords > 0 ? Math.min(100, (anomalyCount / totalRecords) * 100) : 0
                /** OT visual weight by share of rendered hours */
                const otTier = !otStrong ? 'none' : pctOt >= 15 ? 'high' : pctOt >= 5 ? 'med' : 'low'
                /** Review: payroll-safe vs needs triage */
                const reviewTone =
                  totalRecords === 0 ? 'empty' : anomalyCount > 0 ? 'alert' : 'ok'
                const barTrack =
                  'relative mt-3 h-2 w-full min-w-0 overflow-hidden rounded-sm bg-black/[0.06] dark:bg-white/10'
                const barAbs = 'absolute left-0 top-0 h-full rounded-sm transition-[width] duration-300 ease-out'

                const iconWrapBase =
                  'flex h-8 w-8 shrink-0 items-center justify-center rounded-full ring-1 ring-inset'

                return (
                  <div className="grid grid-cols-1 gap-3 @sm:grid-cols-2 @lg:grid-cols-4">
                    {/* Regular — baseline “on track” (emerald) */}
                    <div
                      className="relative flex min-w-0 flex-col overflow-hidden rounded-lg border border-emerald-200/60 bg-emerald-50/40 p-4 shadow-sm dark:border-emerald-900/40 dark:bg-emerald-950/25"
                      title="Regular hours (net of breaks). Bar = share of total rendered hours."
                    >
                      <div className="flex items-start justify-between gap-2">
                        <div className="min-w-0">
                          <p className="text-[11px] font-semibold uppercase tracking-wide text-emerald-800 dark:text-emerald-400">
                            Regular
                          </p>
                          <p className="mt-1 text-xs text-emerald-900/75 dark:text-emerald-300/80">On schedule · base hours</p>
                        </div>
                        <div className={cn(iconWrapBase, 'bg-emerald-500/15 ring-emerald-500/25')}>
                          <Check className="size-4 text-emerald-700 dark:text-emerald-400" aria-hidden />
                        </div>
                      </div>
                      <div className="mt-4 flex items-end justify-between gap-2 border-b border-emerald-200/50 pb-3 dark:border-emerald-800/40">
                        <span className="text-2xl font-bold tabular-nums text-emerald-950 dark:text-emerald-50 @md:text-[1.75rem]">
                          {regularDisplayFallback}
                        </span>
                        <span className="text-sm font-semibold tabular-nums text-emerald-800/80 dark:text-emerald-400/90">
                          {formatPct1(regM)}%
                        </span>
                      </div>
                      <div className={barTrack}>
                        <div className={cn(barAbs, 'bg-emerald-600 dark:bg-emerald-500')} style={{ width: `${pctReg}%` }} />
                      </div>
                    </div>

                    {/* OT — amber intensity scales with share */}
                    <div
                      className={cn(
                        'relative flex min-w-0 flex-col overflow-hidden rounded-lg border p-4 shadow-sm',
                        otTier === 'none' && 'border-border/70 bg-card dark:border-white/10',
                        otTier === 'low' && 'border-amber-200/70 bg-amber-50/50 dark:border-amber-900/45 dark:bg-amber-950/20',
                        otTier === 'med' &&
                          'border-amber-300/90 bg-amber-50/70 dark:border-amber-700/50 dark:bg-amber-950/30',
                        otTier === 'high' &&
                          'border-amber-400 bg-amber-100/60 ring-1 ring-amber-500/20 dark:border-amber-600/60 dark:bg-amber-950/40 dark:ring-amber-500/15',
                      )}
                      title="Overtime after shift end. Bar = share of total rendered hours."
                    >
                      <div className="flex items-start justify-between gap-2">
                        <div className="min-w-0">
                          <p
                            className={cn(
                              'text-[11px] font-semibold uppercase tracking-wide',
                              otTier === 'none' ? 'text-muted-foreground' : 'text-amber-900 dark:text-amber-300',
                            )}
                          >
                            Overtime
                          </p>
                          <p className="mt-1 text-xs text-muted-foreground">
                            Premium · cost driver
                            {otTier === 'high' ? (
                              <span className="ml-1 font-medium text-amber-800 dark:text-amber-400"> · High share</span>
                            ) : null}
                          </p>
                        </div>
                        <div
                          className={cn(
                            iconWrapBase,
                            otTier === 'none' && 'bg-muted/80 ring-border/60',
                            otTier !== 'none' && 'bg-amber-500/15 ring-amber-500/30',
                          )}
                        >
                          <Clock
                            className={cn('size-4', otTier === 'none' ? 'text-muted-foreground' : 'text-amber-700 dark:text-amber-400')}
                            aria-hidden
                          />
                        </div>
                      </div>
                      <div className="mt-4 flex items-end justify-between gap-2 border-b border-border/30 pb-3 dark:border-white/10">
                        <span
                          className={cn(
                            'text-2xl font-bold tabular-nums @md:text-[1.75rem]',
                            otTier === 'none' && 'text-muted-foreground',
                            otTier === 'low' && 'text-amber-900 dark:text-amber-100',
                            otTier === 'med' && 'text-amber-950 dark:text-amber-50',
                            otTier === 'high' && 'text-amber-950 dark:text-amber-50',
                          )}
                        >
                          {totalOt < 0.01 ? '0.0h' : `${totalOt.toFixed(1)}h`}
                        </span>
                        <span
                          className={cn(
                            'text-sm font-semibold tabular-nums',
                            otTier === 'none' ? 'text-muted-foreground' : 'text-amber-800/90 dark:text-amber-400/90',
                          )}
                        >
                          {formatPct1(otM)}%
                        </span>
                      </div>
                      <div className={barTrack}>
                        <div
                          className={cn(
                            barAbs,
                            otStrong ? 'bg-amber-500 dark:bg-amber-500' : 'bg-muted-foreground/25 dark:bg-muted-foreground/30',
                          )}
                          style={{ width: `${pctOt}%` }}
                        />
                      </div>
                    </div>

                    {/* ND — violet when active, neutral when zero */}
                    <div
                      className={cn(
                        'relative flex min-w-0 flex-col overflow-hidden rounded-lg border p-4 shadow-sm',
                        ndStrong
                          ? 'border-violet-200/80 bg-violet-50/45 dark:border-violet-900/45 dark:bg-violet-950/25'
                          : 'border-border/70 bg-card dark:border-white/10',
                      )}
                      title="Night differential. Bar = share of total rendered hours."
                    >
                      <div className="flex items-start justify-between gap-2">
                        <div className="min-w-0">
                          <p
                            className={cn(
                              'text-[11px] font-semibold uppercase tracking-wide',
                              ndStrong ? 'text-violet-900 dark:text-violet-300' : 'text-muted-foreground',
                            )}
                          >
                            Night diff.
                          </p>
                          <p className="mt-1 text-xs text-muted-foreground">Policy window</p>
                        </div>
                        <div
                          className={cn(
                            iconWrapBase,
                            ndStrong ? 'bg-violet-500/15 ring-violet-500/30' : 'bg-muted/80 ring-border/60',
                          )}
                        >
                          <Moon
                            className={cn('size-4', ndStrong ? 'text-violet-700 dark:text-violet-400' : 'text-muted-foreground')}
                            aria-hidden
                          />
                        </div>
                      </div>
                      <div
                        className={cn(
                          'mt-4 flex items-end justify-between gap-2 border-b pb-3 dark:border-white/10',
                          ndStrong ? 'border-violet-200/50 dark:border-violet-800/40' : 'border-border/30',
                        )}
                      >
                        <span
                          className={cn(
                            'text-2xl font-bold tabular-nums @md:text-[1.75rem]',
                            ndStrong ? 'text-violet-950 dark:text-violet-50' : 'text-muted-foreground',
                          )}
                        >
                          {ndDisplay}
                        </span>
                        <span
                          className={cn(
                            'text-sm font-semibold tabular-nums',
                            ndStrong ? 'text-violet-800/85 dark:text-violet-400/90' : 'text-muted-foreground',
                          )}
                        >
                          {formatPct1(ndM)}%
                        </span>
                      </div>
                      <div className={barTrack}>
                        <div
                          className={cn(barAbs, ndStrong ? 'bg-violet-600 dark:bg-violet-500' : 'bg-muted-foreground/20')}
                          style={{ width: `${pctNd}%` }}
                        />
                      </div>
                    </div>

                    {/* Review — success vs warning vs empty */}
                    <div
                      className={cn(
                        'relative flex min-w-0 flex-col overflow-hidden rounded-lg border p-4 shadow-sm',
                        reviewTone === 'ok' &&
                          'border-emerald-300/70 bg-emerald-50/35 dark:border-emerald-800/50 dark:bg-emerald-950/25',
                        reviewTone === 'alert' &&
                          'border-orange-300/90 bg-orange-50/50 ring-1 ring-orange-500/15 dark:border-orange-800/55 dark:bg-orange-950/25 dark:ring-orange-500/10',
                        reviewTone === 'empty' && 'border-border/70 bg-card dark:border-white/10',
                      )}
                      title="Rows needing review. Bar = % of day-rows flagged."
                    >
                      <div className="flex items-start justify-between gap-2">
                        <div className="min-w-0">
                          <p
                            className={cn(
                              'text-[11px] font-semibold uppercase tracking-wide',
                              reviewTone === 'ok' && 'text-emerald-900 dark:text-emerald-400',
                              reviewTone === 'alert' && 'text-orange-900 dark:text-orange-400',
                              reviewTone === 'empty' && 'text-muted-foreground',
                            )}
                          >
                            Review
                          </p>
                          <p className="mt-1 text-xs text-muted-foreground">
                            {reviewTone === 'ok' && 'All clear · ready for payroll'}
                            {reviewTone === 'alert' && 'Needs attention'}
                            {reviewTone === 'empty' && '—'}
                          </p>
                        </div>
                        <div
                          className={cn(
                            iconWrapBase,
                            reviewTone === 'ok' && 'bg-emerald-500/15 ring-emerald-500/25',
                            reviewTone === 'alert' && 'bg-orange-500/15 ring-orange-500/30',
                            reviewTone === 'empty' && 'bg-muted/80 ring-border/60',
                          )}
                        >
                          <CheckCircle2
                            className={cn(
                              'size-4',
                              reviewTone === 'ok' && 'text-emerald-700 dark:text-emerald-400',
                              reviewTone === 'alert' && 'text-orange-700 dark:text-orange-400',
                              reviewTone === 'empty' && 'text-muted-foreground',
                            )}
                            aria-hidden
                          />
                        </div>
                      </div>
                      <div
                        className={cn(
                          'mt-4 flex items-end justify-between gap-2 border-b pb-3 dark:border-white/10',
                          reviewTone === 'ok' && 'border-emerald-200/60 dark:border-emerald-800/40',
                          reviewTone === 'alert' && 'border-orange-200/70 dark:border-orange-900/45',
                          reviewTone === 'empty' && 'border-border/30',
                        )}
                      >
                        <span
                          className={cn(
                            'text-2xl font-bold tabular-nums @md:text-[1.75rem]',
                            reviewTone === 'alert' && 'text-orange-950 dark:text-orange-50',
                            reviewTone === 'ok' && 'text-emerald-950 dark:text-emerald-50',
                            reviewTone === 'empty' && 'text-muted-foreground',
                          )}
                        >
                          {anomalyCount}
                        </span>
                        <span className="text-sm font-semibold tabular-nums text-muted-foreground">
                          {totalRecords > 0 ? `${reviewRowPct.toFixed(1)}` : '—'}%
                        </span>
                      </div>
                      <div className={barTrack}>
                        <div
                          className={cn(
                            barAbs,
                            reviewTone === 'alert' && 'bg-orange-500 dark:bg-orange-500',
                            reviewTone === 'ok' && 'bg-emerald-500/40 dark:bg-emerald-500/50',
                            reviewTone === 'empty' && 'bg-muted-foreground/20',
                          )}
                          style={{ width: `${reviewRowPct}%` }}
                        />
                      </div>
                      {reviewTone === 'alert' ? (
                        <button
                          type="button"
                          className="mt-3 w-full rounded-md border border-orange-300/80 bg-orange-500/10 py-2 text-xs font-medium text-orange-950 hover:bg-orange-500/15 dark:border-orange-800/60 dark:text-orange-100 dark:hover:bg-orange-950/40"
                          onClick={() => {
                            setStatusFilter('needs_review')
                            setPage(1)
                          }}
                        >
                          Open review queue
                        </button>
                      ) : reviewTone === 'empty' ? (
                        <p className="mt-2 text-[11px] text-muted-foreground">No rows in range</p>
                      ) : null}
                    </div>
                  </div>
                )
              })()}
            </div>
          </div>
        )}
        </CardContent>
      </Card>

        {/* Single card + filter bar — matches Admin Employees directory pattern */}
        <Card className="border-0 bg-card shadow-sm overflow-hidden">
          <CardHeader className="border-b border-border/40 bg-muted/20 px-4 py-3 dark:border-border/50 dark:bg-muted/30 @md:px-5">
            <div className="flex flex-col gap-0.5">
              <CardTitle className="text-lg font-semibold @md:text-xl">Computation</CardTitle>
              <CardDescription className="text-sm text-muted-foreground">
                {totalRecords} row{totalRecords !== 1 ? 's' : ''} in selected range
              </CardDescription>
            </div>
          </CardHeader>
          <CardContent className="p-0">
            {/* Row 1 — quick range presets (scroll on narrow viewports) */}
            <div className="border-b border-border/40 bg-muted/20 px-3 py-3 dark:border-white/10 @sm:px-4">
              <div className="mb-2 flex items-center justify-between gap-2">
                <span className="text-[11px] font-semibold uppercase tracking-wider text-muted-foreground">Quick range</span>
                {!datePreset ? (
                  <span className="text-[10px] text-muted-foreground">Custom dates</span>
                ) : null}
              </div>
              <div className="-mx-1 overflow-x-auto overscroll-x-contain px-1 [scrollbar-width:thin]">
                <div
                  className="inline-flex w-max gap-1 rounded-lg border border-border/60 bg-muted/40 p-1 dark:border-white/10 dark:bg-black/25"
                  role="group"
                  aria-label="Date range presets"
                >
                  {[
                    { id: 'today', label: 'Today' },
                    { id: 'yesterday', label: 'Yesterday' },
                    { id: 'last7', label: '7d' },
                    { id: 'week', label: 'Week' },
                    { id: 'month', label: 'Month' },
                    { id: 'last30', label: '30d' },
                  ].map(({ id, label }) => (
                    <button
                      key={id}
                      type="button"
                      onClick={() => applyDatePreset(id)}
                      className={cn(
                        'shrink-0 rounded-md px-3 py-2 text-sm font-medium transition-colors',
                        datePreset === id
                          ? 'bg-background text-foreground shadow-sm ring-1 ring-border/60 dark:bg-card dark:text-foreground'
                          : 'text-muted-foreground hover:bg-background/60 hover:text-foreground',
                      )}
                    >
                      {label}
                    </button>
                  ))}
                </div>
              </div>
            </div>

            {/* Row 2 — search, dates, status, table toggles, Apply / Reset */}
            <div className="border-b border-border/30 bg-card px-3 py-3 dark:border-white/10 @sm:px-4">
              <span className="mb-3 block text-[11px] font-semibold uppercase tracking-wider text-muted-foreground">
                Refine &amp; load
              </span>
              <div className="flex flex-col gap-4 @xl:flex-row @xl:items-end @xl:justify-between @xl:gap-6">
                <div className="flex min-w-0 flex-1 flex-col gap-3 @lg:flex-row @lg:flex-wrap @lg:items-end @lg:gap-3">
                  <div className="relative min-w-0 flex-1 @lg:max-w-[220px] @xl:max-w-xs">
                    <Search className="absolute left-3 top-1/2 size-4 -translate-y-1/2 text-muted-foreground pointer-events-none" aria-hidden />
                    <Input
                      type="search"
                      placeholder="Search name or ID"
                      value={searchEmployee}
                      onChange={(e) => setSearchEmployee(e.target.value)}
                      className="h-10 w-full pl-9 text-sm"
                      aria-label="Search employees"
                    />
                  </div>
                  <div
                    className={cn(
                      'flex min-w-0 flex-wrap items-center gap-2 rounded-lg border border-border/70 bg-muted/30 px-2 py-1.5 dark:border-white/10 dark:bg-muted/20',
                      'dark:scheme-dark',
                    )}
                  >
                    <Calendar className="size-4 shrink-0 text-muted-foreground" aria-hidden />
                    <label className="sr-only" htmlFor="dc-from-date">
                      From date
                    </label>
                    <input
                      id="dc-from-date"
                      type="date"
                      max={defaultToday}
                      value={dateFrom}
                      onChange={(e) => {
                        setDateFrom(e.target.value)
                        setDatePreset('')
                      }}
                      className="h-9 min-w-32 flex-1 bg-transparent text-sm tabular-nums text-foreground outline-none focus-visible:ring-0 sm:min-w-38"
                    />
                    <span className="text-muted-foreground">–</span>
                    <label className="sr-only" htmlFor="dc-to-date">
                      To date
                    </label>
                    <input
                      id="dc-to-date"
                      type="date"
                      max={defaultToday}
                      value={dateTo}
                      onChange={(e) => {
                        setDateTo(e.target.value)
                        setDatePreset('')
                      }}
                      className="h-9 min-w-32 flex-1 bg-transparent text-sm tabular-nums text-foreground outline-none focus-visible:ring-0 sm:min-w-38"
                    />
                  </div>
                  <div className="relative min-w-[min(100%,11rem)] @lg:w-44">
                    <select
                      value={statusFilter}
                      onChange={(e) => setStatusFilter(e.target.value)}
                      className={cn(FIELD_SELECT_CLASS_H10, 'cursor-pointer appearance-none pr-10')}
                      aria-label="Status filter"
                    >
                      <option value="all">All statuses</option>
                      <option value="valid">Valid only</option>
                      <option value="needs_review">Needs review</option>
                      <option value="flagged">Flagged</option>
                    </select>
                    <ChevronDown className="pointer-events-none absolute right-3 top-1/2 size-4 -translate-y-1/2 text-muted-foreground" aria-hidden />
                  </div>
                </div>

                <div className="flex flex-col gap-3 border-t border-border/40 pt-3 @lg:min-w-0 @lg:border-t-0 @lg:border-l @lg:border-border/40 @lg:pt-0 @lg:pl-4 @xl:flex-row @xl:items-center @xl:gap-4 @xl:border-t-0 @xl:border-l-0 @xl:pl-0">
                  <div className="flex flex-col gap-2 @sm:flex-row @sm:flex-wrap @sm:items-center">
                    <label className="flex min-h-10 cursor-pointer items-center gap-2.5 rounded-md border border-border/60 bg-background px-3 py-2 text-sm text-foreground dark:border-white/10 dark:bg-card/80">
                      <input
                        type="checkbox"
                        checked={hideValidRows}
                        onChange={(e) => setHideValidRows(e.target.checked)}
                        className="size-4 rounded border-border"
                      />
                      Hide valid rows
                    </label>
                    <label className="flex min-h-10 cursor-pointer items-center gap-2.5 rounded-md border border-border/60 bg-background px-3 py-2 text-sm text-foreground dark:border-white/10 dark:bg-card/80">
                      <input
                        type="checkbox"
                        checked={showOnlyPremiums}
                        onChange={(e) => setShowOnlyPremiums(e.target.checked)}
                        className="size-4 rounded border-border"
                      />
                      Premiums only
                    </label>
                  </div>
                  <div className="flex flex-wrap items-center gap-2 @xl:ml-auto">
                    <Button
                      type="button"
                      variant="ghost"
                      size="sm"
                      className="h-10 w-full gap-1.5 text-muted-foreground @sm:w-auto"
                      onClick={handleResetFilters}
                    >
                      <RotateCcw className="size-4" aria-hidden />
                      Reset
                    </Button>
                    <Button
                      type="button"
                      variant="default"
                      size="default"
                      className="h-10 min-w-28 shrink-0 px-5 font-semibold disabled:opacity-50"
                      disabled={!filtersDirty}
                      onClick={handleApplyFilters}
                    >
                      Apply filters
                    </Button>
                  </div>
                </div>
              </div>
              {(hideValidRows || showOnlyPremiums) && records.length > 0 && (
                <p className="mt-3 text-xs text-muted-foreground">
                  Showing{' '}
                  <span className="font-medium text-foreground">{displayRecords.length}</span> of{' '}
                  <span className="tabular-nums">{records.length}</span> rows on this page (client filter). Change range or
                  search above, then <span className="font-medium">Apply filters</span> to reload from the server.
                </p>
              )}
            </div>

          <div className="-mx-1 overflow-x-auto bg-card @sm:mx-0">
            <table className="w-full min-w-[1200px] text-sm leading-normal text-foreground">
              <thead className="sticky top-0 z-10 border-b border-border bg-[#f1f5f9] dark:border-border/50 dark:bg-card shadow-[0_1px_0_0_var(--border)]">
                <tr>
                  <th scope="col" className="w-10 py-2.5 pl-3 pr-0" aria-label="Select row" />
                  <th scope="col" className="py-2.5 pr-2 pl-1 text-left text-xs font-bold uppercase tracking-wider text-muted-foreground">
                    Employee
                  </th>
                  <th scope="col" className="min-w-44 py-2.5 px-3 text-left text-xs font-bold uppercase tracking-wider text-muted-foreground">
                    Dept / Position
                  </th>
                  <th scope="col" className="py-2.5 px-3 text-left text-xs font-bold uppercase tracking-wider text-muted-foreground">
                    Date &amp; schedule
                  </th>
                  <th scope="col" className="min-w-44 py-2.5 px-3 text-left text-xs font-bold uppercase tracking-wider text-muted-foreground">
                    Attendance / Leave
                  </th>
                  <th
                    scope="col"
                    className="w-28 py-2.5 px-3 text-right text-xs font-bold uppercase tracking-wider text-muted-foreground"
                    title={CLOCKED_TOTAL_TOOLTIP}
                  >
                    <span className="block leading-tight text-foreground">Clocked</span>
                    <span className="mt-0.5 block text-[10px] font-normal normal-case tracking-normal text-muted-foreground">
                      total
                    </span>
                  </th>
                  <th
                    scope="col"
                    className="min-w-[13rem] py-2.5 px-3 text-left text-xs font-bold uppercase tracking-wider text-muted-foreground"
                    title={HOURS_COLUMN_TOOLTIP}
                  >
                    <span className="block leading-tight text-foreground">Hours</span>
                    <span className="mt-0.5 block text-[10px] font-normal normal-case tracking-normal text-muted-foreground">
                      breakdown
                    </span>
                  </th>
                  <th
                    scope="col"
                    className="w-36 py-2.5 px-3 text-center text-xs font-bold uppercase tracking-wider text-muted-foreground"
                    title="PH Labor Code rule and premium multipliers (regular first 8h / overtime)"
                  >
                    <span className="block leading-tight text-foreground">Rule</span>
                    <span className="mt-0.5 block text-[10px] font-normal normal-case tracking-normal text-muted-foreground">
                      Reg / OT mult.
                    </span>
                  </th>
                  <th scope="col" className="min-w-44 py-2.5 pr-3 pl-3 text-right text-xs font-bold uppercase tracking-wider text-muted-foreground">
                    Daily pay
                  </th>
                  <th scope="col" className="min-w-44 py-2.5 pr-3 pl-3 text-right text-xs font-bold uppercase tracking-wider text-muted-foreground">
                    Status &amp; actions
                  </th>
                </tr>
              </thead>
              <tbody className="bg-card">
                {loading ? (
                  <ComputationLogsTableSkeleton rows={perPage} />
                ) : records.length === 0 ? (
                  <tr>
                    <td colSpan={10} className="bg-card px-6 py-14 text-center">
                      <div className="mx-auto flex max-w-md flex-col items-center gap-2">
                        <div className="flex size-12 items-center justify-center rounded-2xl border border-dashed border-border/60 bg-muted/20 dark:border-white/10">
                          <Calendar className="size-5 text-muted-foreground/40" aria-hidden />
                        </div>
                        <p className="text-sm font-medium text-foreground">No employees found for the selected date</p>
                        <p className="text-xs leading-relaxed text-muted-foreground">
                          No attendance records yet for the selected range. Widen dates, clear filters, or verify employee scope and attendance capture.
                        </p>
                      </div>
                    </td>
                  </tr>
                ) : displayRecords.length === 0 ? (
                  <tr>
                    <td colSpan={10} className="bg-card px-4 py-8 text-center text-sm text-muted-foreground @sm:px-6">
                      {records.length === 0
                        ? 'No rows on this page.'
                        : 'No rows match your table filters (e.g. hide valid, premiums only). Adjust toggles or switch pages.'}
                    </td>
                  </tr>
                ) : (
                  displayRecords.map((row, rowIdx) => {
                    const isExpanded = expandedIds.has(row.id)
                    const statusCfg = STATUS_CONFIG[row.status] ?? STATUS_CONFIG.valid
                    const StatusIcon = statusCfg.icon
                    const unapproved = Number(row.unapproved_ot_hours) > 0.01
                    const otM = parseHrsToMinutes(row.ot)
                    const ndM = parseHrsToMinutes(row.nd)
                    const premiumsZero = otM === 0 && ndM === 0
                    const tardinessLine = formatTardinessTableLine(row)
                    const hasOtAccent = otM > 0
                    const isEven = rowIdx % 2 === 1
                    const baseStripe = isEven
                      ? 'bg-white dark:bg-card hover:bg-slate-50 dark:hover:bg-muted/20'
                      : 'bg-[#f8fafc] dark:bg-muted/15 hover:bg-slate-50 dark:hover:bg-muted/25'
                    const avatarSrc = userProfileImageSrc(row)

                    return (
                      <React.Fragment key={row.id}>
                        <tr
                          className={cn(
                            'group cursor-pointer border-b border-border/20 transition-all duration-150 dark:border-border/40',
                            baseStripe,
                            row.status === 'flagged'
                              ? '[&>td:nth-child(2)]:shadow-[inset_3px_0_0_rgba(239,68,68,0.65)]'
                              : row.status === 'needs_review'
                                ? '[&>td:nth-child(2)]:shadow-[inset_3px_0_0_rgba(245,158,11,0.55)]'
                                : hasOtAccent
                                  ? '[&>td:first-child]:shadow-[inset_4px_0_0_0_rgba(245,158,11,0.55)]'
                                  : '[&:hover>td:nth-child(2)]:shadow-[inset_3px_0_0_rgba(20,184,166,0.55)]',
                          )}
                          onClick={() => setDrawerRecord(row)}
                        >
                          <td className="w-10 px-2 py-2.5 pl-3 align-middle" onClick={(e) => e.stopPropagation()}>
                            <input type="checkbox" disabled className="size-4 rounded border-border" aria-label="Row select (coming soon)" />
                          </td>
                          <td className="px-2 py-2.5 align-middle">
                            <div className="flex min-w-0 items-center gap-2.5">
                              {avatarSrc ? (
                                <img
                                  src={avatarSrc}
                                  alt=""
                                  className="size-10 shrink-0 rounded-full object-cover shadow-sm ring-2 ring-border/20"
                                  width={40}
                                  height={40}
                                />
                              ) : (
                                <div
                                  className={cn(
                                    'flex size-10 shrink-0 items-center justify-center rounded-full text-xs font-bold text-white ring-2 ring-border/20',
                                    row.avatarColor
                                  )}
                                  aria-hidden
                                >
                                  {row.initials}
                                </div>
                              )}
                              <div className="min-w-0 leading-tight">
                                <p className="truncate text-sm font-semibold leading-tight text-foreground">{row.name}</p>
                                <p className="truncate text-xs tabular-nums text-muted-foreground">{row.employeeId}</p>
                              </div>
                            </div>
                          </td>
                          <td className="max-w-52 min-w-44 px-3 py-2.5 align-middle text-foreground">
                            <p className="truncate text-sm font-medium text-foreground" title={row.department || ''}>
                              {row.department || '—'}
                            </p>
                            <p className="truncate text-xs text-muted-foreground">{row.position || '—'}</p>
                          </td>
                          <td className="max-w-52 px-3 py-2.5 align-middle text-foreground">
                            <div className="text-sm font-semibold tabular-nums text-foreground">
                              {parseYmdLocal(row.date).toLocaleDateString('en-PH', { month: 'short', day: 'numeric', year: 'numeric' })}
                            </div>
                            <div className="mt-1 flex flex-wrap items-center gap-1.5">
                              {row.schedule_label ? (
                                <span
                                  className="max-w-[200px] truncate text-xs text-muted-foreground"
                                  title={formatScheduleLabel12h(row.schedule_label)}
                                >
                                  {formatScheduleLabel12h(row.schedule_label)}
                                </span>
                              ) : null}
                              <Badge variant="outline" title={row.ruleTooltip || ''} className={cn('border px-1.5 py-px text-xs font-medium leading-tight', DAY_TYPE_STYLES[row.dayType] ?? DAY_TYPE_STYLES.ORDINARY)}>
                                {row.dayType === 'ORDINARY' ? 'Ordinary' : row.dayType}
                              </Badge>
                              {row.schedule_source === 'default_office' && (
                                <Badge
                                  variant="outline"
                                  title="No working schedule assigned on the employee profile; using Mon–Fri 08:00–17:00 with lunch for this computation."
                                  className="border-amber-200/80 px-1.5 py-px text-xs font-medium leading-tight text-amber-900 dark:border-amber-800/50 dark:text-amber-200"
                                >
                                  Default shift
                                </Badge>
                              )}
                            </div>
                            {row.holiday_name ? (
                              <p className="mt-0.5 truncate text-xs text-amber-800 dark:text-amber-300/90" title={row.holiday_name}>
                                {row.holiday_name}
                              </p>
                            ) : row.is_rest_day ? (
                              <p className="mt-0.5 text-xs text-sky-700 dark:text-sky-300/80">Rest</p>
                            ) : null}
                          </td>
                          <td className="max-w-52 px-3 py-2.5 align-middle">
                            <div className="space-y-1">
                              <Badge variant="outline" className="text-xs">
                                {row.attendance_status || '—'}
                              </Badge>
                              {row.leave_status ? (
                                <p className="text-xs text-amber-800 dark:text-amber-300">{row.leave_status}</p>
                              ) : (
                                <p className="text-xs text-muted-foreground">No approved leave</p>
                              )}
                              {Number(row.attendance_corrections?.count || 0) > 0 ? (
                                <p className="text-xs text-sky-700 dark:text-sky-300">
                                  Corrections: {Number(row.attendance_corrections?.approved_count || 0)} approved /{' '}
                                  {Number(row.attendance_corrections?.pending_count || 0)} pending
                                  {Number(row.attendance_corrections?.rejected_count || 0) > 0
                                    ? ` / ${Number(row.attendance_corrections?.rejected_count || 0)} rejected`
                                    : ''}
                                </p>
                              ) : null}
                            </div>
                          </td>
                          <td className="px-3 py-2.5 text-right align-middle font-semibold tabular-nums text-foreground">
                            {formatHrs(row.totalHrs, 'total')}
                          </td>
                          <td className="px-3 py-2.5 align-middle">
                            <div className="max-w-[min(100%,22rem)] space-y-2" title={HOURS_COLUMN_TOOLTIP}>
                              <ul className="space-y-1.5 text-xs leading-snug">
                                <li className="flex flex-wrap items-baseline gap-x-2 gap-y-0.5">
                                  <span className="w-[4.25rem] shrink-0 font-medium text-muted-foreground">Regular</span>
                                  <Badge
                                    variant="secondary"
                                    className="font-mono text-xs font-semibold tabular-nums"
                                    title="Paid regular (first-8 bucket)"
                                  >
                                    {row.regular ?? '—'}
                                  </Badge>
                                </li>
                                {(otM > 0 || unapproved) && (
                                  <li className="flex flex-wrap items-baseline gap-x-2 gap-y-0.5">
                                    <span className="w-[4.25rem] shrink-0 font-medium text-muted-foreground">OT</span>
                                    <span className="inline-flex flex-wrap items-center gap-1.5 font-mono tabular-nums text-amber-950 dark:text-amber-100">
                                      <Badge
                                        variant="outline"
                                        className="border-amber-300/80 bg-amber-500/10 px-1.5 py-px text-xs text-amber-900 dark:border-amber-700/50 dark:text-amber-100"
                                        title="Rendered overtime (after scheduled end)"
                                      >
                                        {row.ot}
                                        {unapproved ? (
                                          <AlertTriangle className="ml-0.5 inline size-3 align-text-bottom text-amber-600" aria-hidden />
                                        ) : null}
                                      </Badge>
                                      {row.ot_status && row.ot_status !== 'none' && otInlineStatusLabel(row.ot_status) ? (
                                        <span className="text-[11px] font-sans font-normal text-muted-foreground">
                                          ({otInlineStatusLabel(row.ot_status)})
                                        </span>
                                      ) : null}
                                      <span className="text-[11px] font-sans font-normal text-muted-foreground">
                                        mult: {Number(row.overtime_record?.rendered_multiplier?.ot ?? row.ot_multiplier ?? 1.25).toFixed(2)}x
                                      </span>
                                      {row.overtime_record?.approved_ot_end_time ? (
                                        <span className="text-[10px] font-sans font-normal text-emerald-700 dark:text-emerald-400">
                                          until {row.overtime_record.approved_ot_end_time}
                                        </span>
                                      ) : null}
                                    </span>
                                  </li>
                                )}
                                {ndM > 0 && (
                                  <li className="flex flex-wrap items-start gap-x-2 gap-y-0.5">
                                    <span className="w-[4.25rem] shrink-0 font-medium text-muted-foreground">ND</span>
                                    <div className="min-w-0">
                                      <Badge
                                        variant="outline"
                                        className="border-violet-300/80 bg-violet-500/10 px-1.5 py-px font-mono text-xs tabular-nums text-violet-900 dark:border-violet-700/50 dark:text-violet-100"
                                        title="Night differential — hours in ND window (overlap with regular/OT; not added to Clocked total)"
                                      >
                                        {row.nd}
                                      </Badge>
                                      <span className="mt-0.5 block text-[10px] text-muted-foreground">overlap, not additive</span>
                                    </div>
                                  </li>
                                )}
                                {premiumsZero && (
                                  <li className="text-muted-foreground">No OT / ND premiums</li>
                                )}
                              </ul>
                              {tardinessLine ? (
                                <p
                                  className={cn(
                                    'border-t border-border/60 pt-2 text-[11px] font-medium leading-snug dark:border-border/40',
                                    isPresentOnTimeTardinessLabel(row.tardiness_label) &&
                                      'text-emerald-800 dark:text-emerald-200/90',
                                    row.tardiness_label === 'Half Day' && 'text-amber-900 dark:text-amber-200/85',
                                    !isPresentOnTimeTardinessLabel(row.tardiness_label) &&
                                      row.tardiness_label !== 'Half Day' &&
                                      'text-violet-800 dark:text-violet-200/90'
                                  )}
                                  title="Open Computation panel for full tardiness / grace detail"
                                >
                                  <span className="font-normal text-muted-foreground">Tardiness: </span>
                                  {tardinessLine}
                                </p>
                              ) : null}
                            </div>
                          </td>
                          <td className="px-3 py-2.5 text-center align-middle">
                            <div
                              className="flex flex-col items-center gap-0.5"
                              title={row.ruleTooltip || 'PH rule code and multipliers'}
                            >
                              <Badge variant="outline" className="border px-1.5 py-px font-mono text-xs font-semibold leading-tight">
                                {row.rule ?? '—'}
                              </Badge>
                              <span className="font-mono text-xs tabular-nums text-muted-foreground" title="First 8h multiplier / OT multiplier">
                                {row.first_8_multiplier != null && row.ot_multiplier != null
                                  ? `${Number(row.first_8_multiplier).toFixed(2)}× / ${Number(row.ot_multiplier).toFixed(2)}×`
                                  : '—'}
                              </span>
                            </div>
                          </td>
                          <td className="px-3 py-2.5 text-right align-middle">
                            <p
                              className={cn(
                                'font-semibold tabular-nums',
                                Number(row.daily_pay_preview ?? row.total_pay ?? 0) > 0.0001
                                  ? 'text-emerald-700 dark:text-emerald-400'
                                  : 'text-muted-foreground',
                              )}
                            >
                              {formatPeso(row.daily_pay_preview ?? row.total_pay ?? 0)}
                            </p>
                            {row.pay_status === 'no_pay' && row.pay_note ? (
                              <p className="mt-0.5 max-w-[14rem] text-right text-[11px] leading-snug text-amber-900 dark:text-amber-200/90">
                                {row.pay_note}
                              </p>
                            ) : null}
                            <p className="text-[11px] text-muted-foreground">
                              Govt: {formatPeso(row.government_deductions_preview || 0)}
                            </p>
                          </td>
                          <td className="px-3 py-2.5 pr-3 align-middle" onClick={(e) => e.stopPropagation()}>
                            <div className="flex flex-wrap items-center justify-end gap-1.5">
                              <Badge variant="outline" className={cn('justify-center border px-2 py-0.5 text-xs font-medium', statusCfg.className)}>
                                <StatusIcon className="size-3.5 shrink-0" aria-hidden />
                                {statusCfg.label}
                              </Badge>
                              <Button variant="ghost" size="icon" className="size-9 text-muted-foreground" onClick={() => setDrawerRecord(row)} title="Open breakdown">
                                <Eye className="size-4" />
                              </Button>
                              <Button variant="ghost" size="icon" className="size-9" onClick={() => toggleExpand(row.id)} aria-expanded={isExpanded} aria-label={isExpanded ? 'Collapse row' : 'Expand row'}>
                                {isExpanded ? <ChevronUp className="size-4" /> : <ChevronDown className="size-4" />}
                              </Button>
                            </div>
                          </td>
                        </tr>
                        {isExpanded && row.expanded && (
                          <tr className="border-b border-border/20 bg-slate-50 dark:border-border/40 dark:bg-muted/25">
                            <td colSpan={10} className="p-0">
                              <div className="border-l-4 border-border px-4 py-3 @sm:px-5">
                                <div className="mb-3 grid grid-cols-1 gap-x-6 gap-y-1.5 border-b border-border/40 pb-3 text-xs @sm:grid-cols-2">
                                  <div className="flex justify-between gap-4"><span className="text-muted-foreground">Scheduled shift</span><span className="text-xs text-right">{row.schedule_label ? formatScheduleLabel12h(row.schedule_label) : '—'}</span></div>
                                  <div className="flex justify-between gap-4"><span className="text-muted-foreground">Time in / out</span><span>{formatShiftRange12h(row.time_in, row.time_out)}</span></div>
                                  <div className="flex justify-between gap-4 sm:col-span-2 text-muted-foreground italic">
                                    Tardiness is summarized in the main row — open <span className="font-medium not-italic text-foreground">Computation</span> for full policy detail.
                                  </div>
                                  <div className="flex justify-between gap-4"><span className="text-muted-foreground">PH rule code</span><span className="font-mono font-semibold">{row.rule ?? '—'}</span></div>
                                  <div className="flex justify-between gap-4"><span className="text-muted-foreground">First 8h / OT mult.</span><span className="font-mono tabular-nums">{row.first_8_multiplier != null && row.ot_multiplier != null ? `${Number(row.first_8_multiplier).toFixed(2)}× / ${Number(row.ot_multiplier).toFixed(2)}×` : '—'}</span></div>
                                  <div className="flex justify-between gap-4"><span className="text-muted-foreground">Holiday (calendar)</span><span className="text-right truncate max-w-[200px]" title={row.holiday_name || ''}>{row.holiday_name || '—'}</span></div>
                                  <div className="flex justify-between gap-4"><span className="text-muted-foreground">Holiday type (DB)</span><span className="font-mono text-xs">{row.holiday_type || '—'}</span></div>
                                  <div className="flex justify-between gap-4"><span className="text-muted-foreground">Rules engine holiday</span><span className="font-mono text-xs">{row.rules_engine_holiday_type ?? '—'}</span></div>
                                  <div className="flex justify-between gap-4"><span className="text-muted-foreground">Rest day</span><span>{row.is_rest_day ? 'Yes' : 'No'}</span></div>
                                  <div className="flex justify-between gap-4"><span className="text-muted-foreground">OT approved / pending / gap</span><span className="font-mono tabular-nums">{Number(row.approved_ot_hours ?? 0).toFixed(2)}h / {Number(row.pending_ot_hours ?? 0).toFixed(2)}h / {Number(row.unapproved_ot_hours ?? 0).toFixed(2)}h</span></div>
                                </div>
                                {row.expanded.flags?.length > 0 && (
                                  <div className="flex flex-wrap items-center gap-2 mb-3">
                                    <span className="text-xs font-semibold uppercase tracking-wide text-muted-foreground">Flags</span>
                                    {row.expanded.flags.map((flag) => (
                                      <Badge key={flag} variant="outline" className={cn('text-xs border', FLAG_STYLES[flag] ?? FLAG_STYLES.INVALID_SCHEDULE)}>
                                        {flag.replace(/_/g, ' ')}
                                      </Badge>
                                    ))}
                                  </div>
                                )}
                                <Button variant="outline" size="sm" className="gap-2" onClick={() => setDrawerRecord(row)}>
                                  View full breakdown <ChevronRight className="size-4" />
                                </Button>
                              </div>
                            </td>
                          </tr>
                        )}
                      </React.Fragment>
                    )
                  })
                )}
              </tbody>
            </table>
          </div>
        </CardContent>
        {totalRecords > 0 && (
          <div className="flex flex-wrap items-center justify-between gap-2 border-t border-border/40 px-5 py-3 text-xs text-muted-foreground">
            <div>
              {(() => {
                const first = totalRecords === 0 ? 0 : (page - 1) * perPage + 1
                const last = Math.min(page * perPage, totalRecords)
                return (
                  <span>
                    {first}–{last} of {totalRecords} row{totalRecords !== 1 ? 's' : ''}
                  </span>
                )
              })()}
            </div>
            <div className="flex items-center gap-2">
              <Button
                type="button"
                variant="outline"
                size="sm"
                className="h-7 px-2"
                disabled={page <= 1}
                onClick={() => setPage((p) => Math.max(1, p - 1))}
              >
                Previous
              </Button>
              <span className="text-muted-foreground">
                Page {page} of {totalPages}
              </span>
              <Button
                type="button"
                variant="outline"
                size="sm"
                className="h-7 px-2"
                disabled={page >= totalPages}
                onClick={() => setPage((p) => Math.min(totalPages, p + 1))}
              >
                Next
              </Button>
            </div>
          </div>
        )}
        </Card>

      <AuditDetailDrawer
        open={!!drawerRecord}
        onOpenChange={(open) => !open && setDrawerRecord(null)}
        record={drawerRecord}
        onRefreshRecord={refreshDrawerRecord}
        isRefreshing={drawerRefreshing}
      />
    </Motion.div>
  )
}
