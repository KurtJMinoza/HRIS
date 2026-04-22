import { useState, useEffect, useCallback, useRef, useMemo } from 'react'
import { useNavigate } from 'react-router-dom'
import { useQuery } from '@tanstack/react-query'
import { motion as Motion } from 'framer-motion'
import {
  BarChart3,
  Clock,
  Users,
  UserX,
  CalendarOff,
  Coffee,
  Timer,
  AlertCircle,
  RefreshCw,
  LogIn,
  LogOut,
  ChevronLeft,
  ChevronRight,
  Table2,
  ArrowUpRight,
  ArrowDownRight,
  Minus,
  Zap,
  CalendarDays,
  FileBarChart2,
  ClipboardCheck,
  UserPlus,
  ArrowUpDown,
  ArrowUp,
  ArrowDown,
  Filter,
  Sun,
  Moon,
  Monitor,
  Download,
  LayoutList,
  Building2,
  X,
} from 'lucide-react'
import { Card, CardContent, CardDescription, CardHeader, CardTitle } from '@/components/ui/card'
import { Button } from '@/components/ui/button'
import { Avatar, AvatarFallback, AvatarImage } from '@/components/ui/avatar'
import {
  Dialog,
  DialogContent,
  DialogDescription,
  DialogHeader,
  DialogTitle,
} from '@/components/ui/dialog'
import { DashboardSkeleton } from '@/components/skeletons'
import { BarChart, Bar, XAxis, YAxis, CartesianGrid, Tooltip, ResponsiveContainer, AreaChart, Area, Cell, ReferenceLine, ReferenceDot } from 'recharts'
import {
  getDashboardData,
  getDashboardCompanyAttendance,
  getCompanies,
  getHalfDayList,
  profileImageUrl,
  companyLogoUrl,
  submitRegularizationRecommendation,
  approveRegularizationRecommendation,
  rejectRegularizationRecommendation,
} from '@/api'
import { useAuth } from '@/contexts/AuthContext'
import { RoleBadge } from '@/components/RoleBadge'
import { useHrBasePath } from '@/contexts/HrAppPathContext'
import { hrPanelPath } from '@/lib/hrRoutes'
import { cn } from '@/lib/utils'
import { DIALOG_CONTENT_CLASS } from '@/lib/fieldClasses'
import { ExpiringContractsCard } from '@/components/dashboard/ExpiringContractsCard'

const CARD_ICONS = {
  total: Users,
  present: Clock,
  late: AlertCircle,
  absent: UserX,
  on_leave: CalendarOff,
}

const CHART = {
  weeklyBar: 'hsl(160 84% 39%)',
  lateLine: 'hsl(25 95% 53%)',
  lateArea: 'hsl(25 95% 53% / 0.2)',
  deptBars: ['#3b82f6', '#8b5cf6', '#ec4899', '#f59e0b', '#10b981'],
}

const CARD_META = {
  total_employees: {
    border: 'border-t-2 border-sky-500/70',
    gradient: 'from-sky-500/10 via-sky-500/0 to-transparent',
    sentiment: 'neutral',
    hoverShadow: 'hover:shadow-sky-500/15',
  },
  present_today: {
    border: 'border-t-2 border-emerald-500/70',
    gradient: 'from-emerald-500/10 via-emerald-500/0 to-transparent',
    sentiment: 'up_good',
    hoverShadow: 'hover:shadow-emerald-500/20',
  },
  late_today: {
    border: 'border-t-2 border-amber-500/70',
    gradient: 'from-amber-500/10 via-amber-500/0 to-transparent',
    sentiment: 'down_good',
    hoverShadow: 'hover:shadow-amber-500/20',
  },
  absent_today: {
    border: 'border-t-2 border-red-500/70',
    gradient: 'from-red-500/10 via-red-500/0 to-transparent',
    sentiment: 'down_good',
    hoverShadow: 'hover:shadow-red-500/20',
  },
  on_leave: {
    border: 'border-t-2 border-violet-500/70',
    gradient: 'from-violet-500/10 via-violet-500/0 to-transparent',
    sentiment: 'neutral',
    hoverShadow: 'hover:shadow-violet-500/15',
  },
  default: {
    border: 'border-t-2 border-primary/70',
    gradient: 'from-primary/10 via-primary/0 to-transparent',
    sentiment: 'neutral',
    hoverShadow: 'hover:shadow-primary/15',
  },
}

const containerVariants = {
  hidden: { opacity: 0 },
  visible: {
    opacity: 1,
    transition: { staggerChildren: 0.05, delayChildren: 0.05 },
  },
}
const itemVariants = {
  hidden: { opacity: 0, y: 12 },
  visible: { opacity: 1, y: 0 },
}
const chartCardVariants = {
  hidden: { opacity: 0, y: 16 },
  visible: { opacity: 1, y: 0 },
}

const scrollViewport = { once: true, amount: 0.12 }
const scrollRevealTransition = { duration: 0.5, ease: [0.25, 0.1, 0.25, 1] }

const TOOLTIP_STYLES = {
  wrapperStyle: {
    outline: 'none',
    border: 'none',
    backgroundColor: 'transparent',
    boxShadow: 'none',
    padding: 0,
  },
  contentStyle: {
    backgroundColor: 'var(--card)',
    border: '1px solid var(--border)',
    borderRadius: '0.5rem',
    padding: '0.5rem 0.75rem',
    boxShadow: '0 10px 15px -3px rgba(0,0,0,0.08), 0 4px 6px -4px rgba(0,0,0,0.05)',
  },
  // Use transparent or very light fill so Recharts never shows a black cursor rectangle
  cursorFill: 'rgba(0, 0, 0, 0.04)',
  cursorStroke: 'var(--border)',
}

function KpiValue({ value }) {
  const [display, setDisplay] = useState(0)
  const previousRef = useRef(0)

  useEffect(() => {
    const start = previousRef.current
    const end = typeof value === 'number' && Number.isFinite(value) ? value : 0
    if (start === end) return

    const duration = 2400
    const startTime = performance.now()

    let frame
    const step = (now) => {
      const elapsed = now - startTime
      const t = Math.min(1, elapsed / duration)
      const eased = 1 - Math.pow(1 - t, 3) // ease-out
      const next = Math.round(start + (end - start) * eased)
      setDisplay(next)
      if (t < 1) {
        frame = requestAnimationFrame(step)
      } else {
        previousRef.current = end
      }
    }

    frame = requestAnimationFrame(step)

    return () => {
      if (frame) cancelAnimationFrame(frame)
    }
  }, [value])

  return <span>{display}</span>
}

function ChartTooltip({ active, payload, label, labelPrefix = '', valueSuffix = '' }) {
  if (!active || !payload?.length) return null
  const value = payload[0]?.value
  return (
    <div
      className="rounded-lg border px-3 py-2.5 text-sm shadow-lg"
      style={{
        backgroundColor: 'var(--card)',
        borderColor: 'var(--border)',
        color: 'var(--foreground)',
        borderWidth: '1px',
        borderStyle: 'solid',
      }}
      data-chart-tooltip
    >
      <p className="text-sm font-semibold" style={{ color: 'var(--foreground)' }}>
        {labelPrefix}{label}
      </p>
      <p className="mt-1 text-xs tabular-nums leading-snug" style={{ color: 'var(--muted-foreground)' }}>
        {payload[0]?.name}: <span className="font-semibold text-foreground">{value}{valueSuffix}</span>
      </p>
    </div>
  )
}

const LOGS_PER_PAGE = 10

function exportTableCsv(logs, formatTimeFn) {
  const headers = ['Employee', 'Company', 'Time In', 'Time Out', 'Late Status', 'Session']
  const rows = logs.map((l) => [
    `"${l.employee_name || ''}"`,
    `"${l.company_name ?? ''}"`,
    l.time_in ? formatTimeFn(l.time_in) : '',
    l.time_out ? formatTimeFn(l.time_out) : '',
    l.is_late ? (l.late_label || 'Late') : l.time_in ? 'On time' : '',
    l.time_in && !l.time_out ? 'Clocked in' : l.time_in && l.time_out ? 'Completed' : 'No activity',
  ].join(','))
  const csv = [headers.join(','), ...rows].join('\n')
  const blob = new Blob([csv], { type: 'text/csv;charset=utf-8;' })
  const url = URL.createObjectURL(blob)
  const a = document.createElement('a')
  a.href = url
  a.download = `attendance-${new Date().toISOString().split('T')[0]}.csv`
  document.body.appendChild(a)
  a.click()
  document.body.removeChild(a)
  URL.revokeObjectURL(url)
}

function getGreeting() {
  const hour = new Date().getHours()
  if (hour < 12) return 'Good morning'
  if (hour < 18) return 'Good afternoon'
  return 'Good evening'
}

function formatTime(iso) {
  if (!iso) return '—'
  try {
    const d = new Date(iso)
    return d.toLocaleTimeString(undefined, { hour: '2-digit', minute: '2-digit', second: '2-digit' })
  } catch {
    return iso
  }
}

function formatDate(value) {
  if (!value) return '—'
  try {
    const d = new Date(value)
    return d.toLocaleDateString(undefined, { year: 'numeric', month: 'short', day: 'numeric' })
  } catch {
    return value
  }
}

function formatDaysLabel(label) {
  if (!label) return '—'
  const s = String(label)
  return s.replace(/(\d+)(?:\.\d+)?\s+days\b/g, (_, n) => `${Number(n)} days`)
}

function formatEmploymentTypeLabel(raw) {
  if (!raw) return '—'
  const words = String(raw)
    .replace(/_/g, ' ')
    .trim()
    .split(/\s+/)
    .filter(Boolean)
  return words.map((w) => w.slice(0, 1).toUpperCase() + w.slice(1)).join(' ')
}

export default function AdminDashboard() {
  const { user, loading: authLoading } = useAuth()
  const perms = useMemo(() => new Set(user?.permissions ?? []), [user?.permissions])
  const canViewCompanyDirectory = useMemo(() => perms.has('org.company.view'), [perms])
  const hrRole = String(user?.hr_role || '').trim()
  const isHrAdmin = hrRole === 'admin_hr' || String(user?.role || '').toLowerCase() === 'admin'
  const dashboardScopeLabel =
    hrRole === 'company_head'
      ? 'Company'
      : hrRole === 'branch_head'
        ? 'Branch'
        : hrRole === 'department_head'
          ? 'Department'
          : null

  const [data, setData] = useState(null)
  const [error, setError] = useState(null)
  const [logsPage, setLogsPage] = useState(1)
  const [lastUpdatedAt, setLastUpdatedAt] = useState(null)
  const [expandedRowId, setExpandedRowId] = useState(null)
  const [sortConfig, setSortConfig] = useState({ key: null, dir: 'asc' })
  const [showOnlyLate, setShowOnlyLate] = useState(false)
  const [compact, setCompact] = useState(false)
  const toLocalDateString = useCallback((d) => {
    const y = d.getFullYear()
    const m = String(d.getMonth() + 1).padStart(2, '0')
    const day = String(d.getDate()).padStart(2, '0')
    return `${y}-${m}-${day}`
  }, [])
  const [companyDateFrom, setCompanyDateFrom] = useState(() => {
    const d = new Date()
    return `${d.getFullYear()}-${String(d.getMonth() + 1).padStart(2, '0')}-${String(d.getDate()).padStart(2, '0')}`
  })
  const [companyDateTo, setCompanyDateTo] = useState(() => {
    const d = new Date()
    return `${d.getFullYear()}-${String(d.getMonth() + 1).padStart(2, '0')}-${String(d.getDate()).padStart(2, '0')}`
  })
  const [selectedCompanyIds, setSelectedCompanyIds] = useState([])
  const [companyAttendanceData, setCompanyAttendanceData] = useState(null)
  const [companiesList, setCompaniesList] = useState([])
  const [companyChartLoading, setCompanyChartLoading] = useState(false)
  const [halfDayModalOpen, setHalfDayModalOpen] = useState(false)
  const [halfDayList, setHalfDayList] = useState(null)
  const [halfDayListLoading, setHalfDayListLoading] = useState(false)
  const [regularizationActionById, setRegularizationActionById] = useState({})
  const navigate = useNavigate()
  const hrBase = useHrBasePath()

  const dashboardQuery = useQuery({
    queryKey: ['admin-dashboard'],
    queryFn: getDashboardData,
    enabled: !authLoading,
    refetchInterval: 15000,
    refetchOnWindowFocus: true,
  })

  const loading = authLoading || dashboardQuery.isLoading

  const fetchDashboard = useCallback(async () => {
    const result = await dashboardQuery.refetch()
    if (result.error) throw result.error
  }, [dashboardQuery])

  useEffect(() => {
    if (dashboardQuery.data) {
      setData(dashboardQuery.data)
      setLastUpdatedAt(new Date())
      setError(null)
    } else if (dashboardQuery.error) {
      setData(null)
      setError(dashboardQuery.error?.message || 'Failed to load dashboard')
    }
  }, [dashboardQuery.data, dashboardQuery.error])

  useEffect(() => {
    if (authLoading || !canViewCompanyDirectory) {
      setCompaniesList([])
      return
    }
    let cancelled = false
    getCompanies()
      .then((companiesRes) => {
        if (cancelled) return
        setCompaniesList(Array.isArray(companiesRes?.companies) ? companiesRes.companies : [])
      })
      .catch(() => {
        if (!cancelled) setCompaniesList([])
      })
    return () => {
      cancelled = true
    }
  }, [authLoading, canViewCompanyDirectory])

  // Use main dashboard's company_distribution when viewing "today" + "all companies" (no separate fetch).
  const isDefaultFilters =
    companyDateFrom === toLocalDateString(new Date()) &&
    companyDateTo === toLocalDateString(new Date()) &&
    selectedCompanyIds.length === 0

  // Fetch company attendance only when user changes date or company filter (non-default).
  const fetchCompanyAttendance = useCallback(async () => {
    if (isDefaultFilters) return
    setCompanyChartLoading(true)
    try {
      const params = {
        from_date: companyDateFrom,
        to_date: companyDateTo,
        ...(selectedCompanyIds.length > 0 ? { company_ids: selectedCompanyIds } : {}),
      }
      const res = await getDashboardCompanyAttendance(params)
      setCompanyAttendanceData(res)
    } catch {
      setCompanyAttendanceData(null)
    } finally {
      setCompanyChartLoading(false)
    }
  }, [companyDateFrom, companyDateTo, selectedCompanyIds, isDefaultFilters])
  useEffect(() => {
    if (isDefaultFilters) {
      setCompanyAttendanceData(null)
      setCompanyChartLoading(false)
    } else {
      fetchCompanyAttendance()
    }
  }, [fetchCompanyAttendance, isDefaultFilters])

  const openHalfDayModal = useCallback(async () => {
    setHalfDayModalOpen(true)
    setHalfDayListLoading(true)
    setHalfDayList(null)
    try {
      const today = toLocalDateString(new Date())
      const res = await getHalfDayList({ date: today })
      setHalfDayList(res ?? { date: today, employees: [], am_count: 0, pm_count: 0, total: 0 })
    } catch {
      setHalfDayList({ date: toLocalDateString(new Date()), employees: [], am_count: 0, pm_count: 0, total: 0 })
    } finally {
      setHalfDayListLoading(false)
    }
  }, [toLocalDateString])

  // Polling and focus refresh are handled by React Query config.

  const updatedAgoLabel = (() => {
    if (!lastUpdatedAt) return 'Just now'
    const diffSeconds = Math.max(0, Math.floor((Date.now() - lastUpdatedAt.getTime()) / 1000))
    if (diffSeconds < 5) return 'Just now'
    if (diffSeconds < 60) return `${diffSeconds}s ago`
    const minutes = Math.floor(diffSeconds / 60)
    if (minutes === 1) return '1 min ago'
    if (minutes < 60) return `${minutes} mins ago`
    const hours = Math.floor(minutes / 60)
    return `${hours}h ago`
  })()

  // Keep logs page in valid range when today_logs length changes
  useEffect(() => {
    const total = data?.today_logs?.length ?? 0
    const maxPage = Math.max(1, Math.ceil(total / LOGS_PER_PAGE))
    setLogsPage((p) => Math.min(p, maxPage))
  }, [data?.today_logs?.length])

  // All useMemo hooks MUST live before any early returns (Rules of Hooks).
  const todayLogs = useMemo(() => data?.today_logs ?? [], [data?.today_logs])

  const filteredSortedLogs = useMemo(() => {
    let logs = showOnlyLate ? todayLogs.filter((l) => l.is_late) : todayLogs
    if (sortConfig.key) {
      logs = [...logs].sort((a, b) => {
        let aVal, bVal
        if (sortConfig.key === 'time_in') {
          aVal = a.time_in ? new Date(a.time_in).getTime() : 0
          bVal = b.time_in ? new Date(b.time_in).getTime() : 0
        } else if (sortConfig.key === 'time_out') {
          aVal = a.time_out ? new Date(a.time_out).getTime() : 0
          bVal = b.time_out ? new Date(b.time_out).getTime() : 0
        } else if (sortConfig.key === 'is_late') {
          aVal = a.is_late ? 1 : 0
          bVal = b.is_late ? 1 : 0
        } else if (sortConfig.key === 'employee_name') {
          aVal = (a.employee_name || '').toLowerCase()
          bVal = (b.employee_name || '').toLowerCase()
        }
        if (aVal < bVal) return sortConfig.dir === 'asc' ? -1 : 1
        if (aVal > bVal) return sortConfig.dir === 'asc' ? 1 : -1
        return 0
      })
    }
    return logs
  }, [todayLogs, showOnlyLate, sortConfig])

  const companyLogoMap = useMemo(() => {
    const map = {}
    const list = companiesList
    if (Array.isArray(list)) {
      list.forEach((co) => {
        if (co?.id != null) map[co.id] = companyLogoUrl(co)
      })
    }
    return map
  }, [companiesList])

  if (authLoading || (loading && !data)) {
    return <DashboardSkeleton />
  }

  if (error && !data) {
    const isPermError = /missing permission|forbidden/i.test(String(error))
    return (
      <div className="space-y-6">
        <h2 className="text-2xl font-bold tracking-tight">Overview</h2>
        <Card className="border-destructive/50">
          <CardContent className="pt-6">
            <p className="text-destructive">{error}</p>
            <p className="mt-1 text-sm text-muted-foreground">
              {isPermError
                ? 'If you just signed in, try Retry. If this persists, contact HR — your account may need the correct role permissions.'
                : 'Check that the backend is running and you are signed in with an account that has dashboard access.'}
            </p>
            <Button
              type="button"
              variant="outline"
              className="mt-4"
              onClick={() => fetchDashboard(true)}
            >
              Retry
            </Button>
          </CardContent>
        </Card>
      </div>
    )
  }

  const stats = data?.stats ?? {}
  const prevStats = data?.stats_prev ?? {}
  const weeklyData = data?.weekly_overview ?? []
  const rawMonthlyLate = data?.monthly_late ?? []
  const monthlyLateData = rawMonthlyLate.map((d) => {
    const numericLate = typeof d?.late_count === 'number' ? d.late_count : 0
    const hasData =
      typeof d?.has_data === 'boolean'
        ? d.has_data
        : typeof d?.late_count === 'number'
    return {
      ...d,
      late_count: numericLate,
      has_data: hasData,
      clock_in_samples: typeof d?.clock_in_samples === 'number' ? d.clock_in_samples : null,
    }
  })
  const weeklyMax = weeklyData.reduce(
    (max, d) => Math.max(max, typeof d?.present_count === 'number' ? d.present_count : 0),
    0
  )
  const monthlyLateMax = monthlyLateData.reduce(
    (max, d) => Math.max(max, typeof d?.late_count === 'number' ? d.late_count : 0),
    0
  )
  const monthlyLateBaseline = monthlyLateData.slice(0, -1).filter((d) => typeof d?.late_count === 'number')
  const monthlyLateBaselineAvg =
    monthlyLateBaseline.length > 0
      ? monthlyLateBaseline.reduce((sum, d) => sum + d.late_count, 0) / monthlyLateBaseline.length
      : 0
  const latestMonthlyLate = monthlyLateData.length > 0 ? monthlyLateData[monthlyLateData.length - 1] : null
  const spikeDetected =
    typeof latestMonthlyLate?.late_count === 'number' &&
    monthlyLateBaseline.length >= 3 &&
    ((monthlyLateBaselineAvg > 0 &&
      latestMonthlyLate.late_count > monthlyLateBaselineAvg * 2 &&
      latestMonthlyLate.late_count - monthlyLateBaselineAvg >= 3) ||
      (monthlyLateBaselineAvg === 0 && latestMonthlyLate.late_count >= 5))
  const showMonthlyAvgLine = monthlyLateBaseline.length >= 3 && monthlyLateBaselineAvg > 0
  const halfDaySummary = data?.half_day_summary ?? { am_today: 0, pm_today: 0, total_today: 0, total_workforce: 0 }
  const todayLeaves = Array.isArray(data?.today_leaves) ? data.today_leaves : []
  const cards = [
    {
      key: 'total_employees',
      label: 'Total Employees',
      value: stats.total_employees ?? 0,
      icon: 'total',
      trendType: 'weekly',
    },
    {
      key: 'present_today',
      label: 'Present Today',
      value: stats.present_today ?? 0,
      icon: 'present',
      trendType: 'weekly',
    },
    {
      key: 'late_today',
      label: 'Late Today',
      value: stats.late_today ?? 0,
      icon: 'late',
      trendType: 'late',
    },
    {
      key: 'absent_today',
      label: 'Absent Today',
      value: stats.absent_today ?? 0,
      icon: 'absent',
      trendType: 'weekly',
    },
    {
      key: 'on_leave',
      label: 'On Leave',
      value: stats.on_leave ?? 0,
      icon: 'on_leave',
      trendType: 'weekly',
    },
  ]
  const companyRows =
    isDefaultFilters && Array.isArray(data?.company_distribution)
      ? data.company_distribution
      : (companyAttendanceData?.companies ?? [])
  const companyData = companyRows.map((c, idx) => ({
    company: c.company ?? 'Unassigned',
    company_id: c.company_id,
    present: c.present ?? 0,
    late: c.late ?? 0,
    absent: c.absent ?? 0,
    on_leave: c.on_leave ?? 0,
    headcount: c.headcount ?? 0,
    present_pct: c.present_pct ?? 0,
    color: CHART.deptBars[idx % CHART.deptBars.length],
    logo_url: c.logo_url ?? (c.company_id != null ? companyLogoMap[c.company_id] : null),
  }))
  const totalCompanyPresent = companyData.reduce((sum, d) => sum + (d.present ?? 0), 0)
  const totalCompanyHeadcount = companyData.reduce((sum, d) => sum + (d.headcount ?? 0), 0)
  const topCompany = companyData.reduce(
    (best, d) => (d.present > (best?.present ?? -1) ? d : best),
    null
  )
  const isSingleCompany = companyData.length === 1
  const todaysHeadcount = typeof stats.total_employees === 'number' ? stats.total_employees : null
  const todaysPresent = typeof stats.present_today === 'number' ? stats.present_today : null
  const todaysAttendanceRate =
    todaysHeadcount && todaysHeadcount > 0
      ? (todaysPresent ?? 0) / todaysHeadcount
      : null
  const lateTodayCount = typeof stats.late_today === 'number' ? stats.late_today : 0
  const upcomingRegularizations = Array.isArray(data?.upcoming_regularizations)
    ? data.upcoming_regularizations
    : []
  const expiringContracts = Array.isArray(data?.expiring_contracts) ? data.expiring_contracts : []
  const requiredConfirmationActions = Array.isArray(data?.required_confirmation_actions)
    ? data.required_confirmation_actions
    : []
  // Used by other dashboard sections; keep available for future copy changes.
  // eslint-disable-next-line no-unused-vars
  const autoRegularizationMonths = Number(data?.employment_settings?.auto_regularization_months || 6)
  // eslint-disable-next-line no-unused-vars
  const earlyRegularizationMonths = Number(data?.employment_settings?.early_regularization_months || 3)

  // Workforce status breakdown
  const currentlyWorking = todayLogs.filter((l) => l.time_in && !l.time_out).length
  const completedShift = todayLogs.filter((l) => l.time_in && l.time_out).length

  const totalLogs = filteredSortedLogs.length
  const totalLogsPages = Math.max(1, Math.ceil(totalLogs / LOGS_PER_PAGE))
  const effectiveLogsPage = Math.min(logsPage, totalLogsPages)
  const paginatedLogs = filteredSortedLogs.slice(
    (effectiveLogsPage - 1) * LOGS_PER_PAGE,
    effectiveLogsPage * LOGS_PER_PAGE
  )
  const logsStart = totalLogs === 0 ? 0 : (effectiveLogsPage - 1) * LOGS_PER_PAGE + 1
  const logsEnd = Math.min(effectiveLogsPage * LOGS_PER_PAGE, totalLogs)

  function handleSort(key) {
    setSortConfig((prev) =>
      prev.key === key ? { key, dir: prev.dir === 'asc' ? 'desc' : 'asc' } : { key, dir: 'asc' }
    )
    setLogsPage(1)
  }

  async function handleRecommendEarly(employee) {
    const notes = window.prompt(
      `Recommend early regularization for ${employee?.name || 'this employee'}.\n\nEnter recommendation notes:`
    )
    if (notes == null) return
    const trimmed = notes.trim()
    if (!trimmed) {
      window.alert('Recommendation notes are required.')
      return
    }

    setRegularizationActionById((prev) => ({ ...prev, [employee.id]: true }))
    try {
      await submitRegularizationRecommendation({
        user_id: employee.id,
        recommendation_type: 'probation_to_regular',
        recommendation_notes: trimmed,
      })
      await fetchDashboard()
      window.alert('Early regularization recommendation submitted.')
    } catch (e) {
      window.alert(e?.message || 'Failed to submit recommendation.')
    } finally {
      setRegularizationActionById((prev) => ({ ...prev, [employee.id]: false }))
    }
  }

  async function handleHrApprove(employee) {
    const notes = window.prompt(
      `Approve regularization for ${employee?.name || 'this employee'}.\n\nOptional HR notes:`
    )
    const recommendationId = employee?.recommendation?.id
    if (!recommendationId) {
      window.alert('No pending recommendation found.')
      return
    }
    setRegularizationActionById((prev) => ({ ...prev, [employee.id]: true }))
    try {
      await approveRegularizationRecommendation(recommendationId, notes ?? '')
      await fetchDashboard()
      window.alert('Regularization recommendation approved.')
    } catch (e) {
      window.alert(e?.message || 'Failed to approve recommendation.')
    } finally {
      setRegularizationActionById((prev) => ({ ...prev, [employee.id]: false }))
    }
  }

  async function handleHrReject(employee) {
    const reason = window.prompt(
      `Reject regularization for ${employee?.name || 'this employee'}.\n\nEnter rejection reason:`
    )
    if (reason == null) return
    const trimmed = reason.trim()
    if (!trimmed) {
      window.alert('Rejection reason is required.')
      return
    }
    const recommendationId = employee?.recommendation?.id
    if (!recommendationId) {
      window.alert('No pending recommendation found.')
      return
    }
    setRegularizationActionById((prev) => ({ ...prev, [employee.id]: true }))
    try {
      await rejectRegularizationRecommendation(recommendationId, trimmed)
      await fetchDashboard()
      window.alert('Regularization recommendation rejected.')
    } catch (e) {
      window.alert(e?.message || 'Failed to reject recommendation.')
    } finally {
      setRegularizationActionById((prev) => ({ ...prev, [employee.id]: false }))
    }
  }

  function handleReviewContract(employee) {
    navigate(hrPanelPath(hrBase, `regularization?submit_for=${employee.id}`))
  }

  return (
    <Motion.div
      className="space-y-8 text-foreground dark:text-zinc-50"
      initial="hidden"
      animate="visible"
      variants={{ hidden: { opacity: 0 }, visible: { opacity: 1, transition: { staggerChildren: 0.04 } } }}
    >
      <Motion.div
        className="mb-2 flex flex-col gap-4 pt-2 @md:mb-4 @md:flex-row @md:items-start @md:justify-between"
        variants={itemVariants}
      >
        <div className="space-y-2">
          <p className="text-xs font-semibold uppercase tracking-[0.18em] text-muted-foreground">
            {getGreeting()}
            {isHrAdmin ? ', Admin' : dashboardScopeLabel ? `, ${dashboardScopeLabel} lead` : ''}
          </p>
          <div className="flex flex-wrap items-center gap-2.5">
            <h2 className="text-[32px] font-bold leading-tight tracking-tight @md:text-[36px]">
              {isHrAdmin
                ? 'Admin Dashboard'
                : hrRole === 'department_head'
                  ? 'Department Dashboard'
                  : hrRole === 'branch_head'
                    ? 'Branch Dashboard'
                    : hrRole === 'company_head'
                      ? 'Company Dashboard'
                      : 'Dashboard'}
            </h2>
            {!isHrAdmin && dashboardScopeLabel ? (
              <RoleBadge user={user} size="md" className="shrink-0" />
            ) : null}
          </div>
          <p className="max-w-2xl pb-1 text-[15px] font-normal leading-[1.55] text-muted-foreground">
            {isHrAdmin
              ? 'Real-time insight into employees, attendance, and daily workforce activity.'
              : hrRole === 'department_head'
                ? 'Department attendance, team metrics, and late statistics for your scope.'
                : 'Workforce metrics and attendance for your organization scope.'}
          </p>
        </div>
        <div className="flex flex-wrap items-center gap-2">
          <div className="inline-flex items-center gap-1.5 rounded-full border border-emerald-500/30 bg-emerald-50/80 px-2.5 py-1 text-[11px] text-emerald-800 shadow-sm dark:border-emerald-500/40 dark:bg-emerald-900/30 dark:text-emerald-300">
            <span className="relative inline-flex size-2 shrink-0">
              <span className="absolute inline-flex size-full animate-ping rounded-full bg-emerald-400/70" />
              <span className="relative inline-flex size-2 rounded-full bg-emerald-500" />
            </span>
            <span className="font-extrabold uppercase tracking-[0.14em] text-emerald-700 dark:text-emerald-200">Live</span>
            <span className="text-[10px] font-normal opacity-75">Auto-refresh 15s</span>
          </div>
          <div className="inline-flex items-center gap-2 rounded-lg border border-border/70 bg-card/80 px-2.5 py-1.5 text-xs shadow-sm">
            <span className="text-[11px] font-normal uppercase tracking-wide text-muted-foreground">Range</span>
            <span className="rounded-md bg-muted px-2 py-0.5 text-xs font-semibold text-foreground">
              Today
            </span>
          </div>
        </div>
      </Motion.div>

      {stats.total_employees === 0 && (
        <Motion.div variants={itemVariants}>
          <Card className="border-dashed border-border bg-muted/30">
            <CardContent className="flex flex-col items-center justify-center gap-2 py-10 text-center">
              <Users className="size-10 text-muted-foreground" />
              <p className="font-medium text-foreground">No employees yet</p>
              <p className="text-sm text-muted-foreground">
                {perms.has('employees.create')
                  ? 'Add employees from the Employees page to see overview counts and attendance data here.'
                  : 'No employees in your scope yet, or you may only have view access.'}
              </p>
            </CardContent>
          </Card>
        </Motion.div>
      )}

      {todaysAttendanceRate !== null && todaysAttendanceRate < 0.5 && (
        <Motion.div variants={itemVariants}>
          <div className="flex items-center gap-3 rounded-lg border border-amber-500/20 border-l-[3px] border-l-amber-500 bg-amber-500/8 px-3.5 py-2.5 dark:bg-amber-500/10">
            <AlertCircle className="size-4 shrink-0 text-amber-600 dark:text-amber-400" />
            <p className="flex-1 text-sm text-foreground">
              <span className="font-semibold text-amber-700 dark:text-amber-300">Low attendance — </span>
              Only{' '}
              <span className="font-bold">{Math.round(todaysAttendanceRate * 100)}%</span>{' '}
              of employees have clocked in. Follow up with managers.
            </p>
            <span className="shrink-0 rounded-full bg-amber-500/15 px-2 py-0.5 text-[11px] font-bold text-amber-700 dark:text-amber-300">
              {Math.round(todaysAttendanceRate * 100)}%
            </span>
          </div>
        </Motion.div>
      )}

      {lateTodayCount >= 3 && (
        <Motion.div variants={itemVariants}>
          <div className="flex items-center gap-3 rounded-lg border border-rose-500/20 border-l-[3px] border-l-rose-500 bg-rose-500/8 px-3.5 py-2.5 dark:bg-rose-500/10">
            <span className="relative inline-flex shrink-0 size-4">
              <span className="absolute inline-flex size-full animate-ping rounded-full bg-rose-400/50" />
              <AlertCircle className="relative size-4 text-rose-600 dark:text-rose-400" />
            </span>
            <p className="flex-1 text-sm text-foreground">
              <span className="font-semibold text-rose-700 dark:text-rose-300">High late activity — </span>
              <span className="font-bold">{lateTodayCount} employees</span>{' '}
              marked late today. Consider sending reminders.
            </p>
            <span className="shrink-0 rounded-full bg-rose-500/15 px-2 py-0.5 text-[11px] font-bold text-rose-700 dark:text-rose-300">
              {lateTodayCount} late
            </span>
          </div>
        </Motion.div>
      )}

      {/* Top stats cards */}
      <Motion.div
        className="mt-2 grid gap-4 @sm:mt-3 @sm:grid-cols-2 @lg:grid-cols-3 @xl:grid-cols-5"
        variants={containerVariants}
        initial="hidden"
        whileInView="visible"
        viewport={scrollViewport}
        transition={scrollRevealTransition}
      >
        {cards.map(({ key, label, value, icon, trendType }) => {
          const Icon = CARD_ICONS[icon] || BarChart3
          const isLateAlert = key === 'late_today' && value >= 3

          let deltaPct = 0
          let deltaCount = 0
          let direction = 'flat'
          let hasDelta = false
          let labelKind = 'none' // 'percent' | 'count' | 'none'

          const prevValueRaw = typeof prevStats[key] === 'number' ? prevStats[key] : null
          if (prevValueRaw != null) {
            const current = value ?? 0
            const prev = prevValueRaw
            if (prev === 0 && current !== 0) {
              deltaCount = current - prev
              hasDelta = true
              labelKind = 'count'
              direction = deltaCount > 0 ? 'up' : 'down'
            } else if (prev !== 0) {
              const raw = ((current - prev) / prev) * 100
              deltaPct = raw
              deltaCount = current - prev
              hasDelta = true
              labelKind = 'percent'
              if (raw > 0.5) direction = 'up'
              else if (raw < -0.5) direction = 'down'
              else direction = 'flat'
            }
          }

          const meta = CARD_META[key] || CARD_META.default
          let deltaColorClass = 'text-muted-foreground'
          if (hasDelta && direction !== 'flat') {
            const good =
              meta.sentiment === 'up_good'
                ? direction === 'up'
                : meta.sentiment === 'down_good'
                  ? direction === 'down'
                  : false
            deltaColorClass = good ? 'text-emerald-600 dark:text-emerald-400' : 'text-red-500 dark:text-red-400'
          }

          const formattedDelta = Math.abs(deltaPct).toFixed(1)
          const formattedCount = Math.abs(deltaCount).toString()

          const periodLabel = hasDelta ? 'vs yesterday' : 'no previous data'

          // Mini chart data for this card
          let miniSeries = []
          if (trendType === 'weekly') {
            miniSeries = weeklyData.map((d) => ({
              label: d.label,
              value: typeof d.present_count === 'number' ? d.present_count : 0,
            }))
          } else if (trendType === 'late') {
            miniSeries = monthlyLateData.map((d) => ({
              label: d.label,
              value: typeof d.late_count === 'number' ? d.late_count : 0,
            }))
          }

          return (
            <Motion.div key={key} variants={itemVariants} whileHover={{ y: -3, scale: 1.02, transition: { duration: 0.15, ease: 'easeOut' } }}>
              <Card
                className={[
                  'relative gap-0 overflow-hidden shadow-sm transition-all duration-200',
                  'dark:bg-white/4 dark:backdrop-blur-sm',
                  isLateAlert
                    ? 'bg-card/95 border-2 border-border/80 ring-0 hover:border-border hover:shadow-md dark:bg-card/90 dark:border-white/20 dark:hover:border-white/30'
                    : `bg-card/95 border-2 border-border/80 ring-0 hover:border-border hover:shadow-md dark:bg-card/90 dark:border-white/20 dark:hover:border-white/30 ${meta.hoverShadow ?? ''}`,
                ].join(' ')}
              >
              <CardHeader className="relative z-10 flex flex-row items-center justify-between space-y-0 px-5 pb-1 pt-5">
                <CardTitle className={`mb-0 text-sm font-medium uppercase tracking-[0.08em] ${isLateAlert ? 'text-red-700 dark:text-red-400' : 'text-muted-foreground'}`}>
                  {label}
                </CardTitle>
                <div className={`flex h-7 w-7 items-center justify-center rounded-full shadow-sm ring-1 ${isLateAlert ? 'animate-pulse bg-red-500/20 ring-red-500/50' : 'bg-muted/80 ring-border/60'}`}>
                  <Icon className={`size-4 ${isLateAlert ? 'text-red-600 dark:text-red-400' : 'text-muted-foreground/90'}`} />
                </div>
              </CardHeader>
              <CardContent className="relative z-10 px-5 pb-5 pt-1">
                <div className="flex items-baseline justify-between gap-3">
                  <div className={`text-5xl font-bold tabular-nums leading-none tracking-tight @md:text-[56px] ${isLateAlert ? 'text-red-600 dark:text-red-400' : ''}`}>
                    <KpiValue value={value} />
                  </div>
                  <div className="flex flex-col items-end gap-0.5">
                    <div className={`flex items-center text-[13px] font-semibold tabular-nums ${deltaColorClass}`}>
                      {direction === 'up' ? (
                        <ArrowUpRight className="mr-0.5 size-4" />
                      ) : direction === 'down' ? (
                        <ArrowDownRight className="mr-0.5 size-4" />
                      ) : (
                        <Minus className="mr-0.5 size-3.5" />
                      )}
                      {hasDelta ? (
                        labelKind === 'percent' ? (
                          deltaPct > 0 ? (
                            <span>+{formattedDelta}%</span>
                          ) : deltaPct < 0 ? (
                            <span>-{formattedDelta}%</span>
                          ) : (
                            <span>—</span>
                          )
                        ) : labelKind === 'count' ? (
                          deltaCount > 0 ? (
                            <span>+{formattedCount}</span>
                          ) : deltaCount < 0 ? (
                            <span>{formattedCount}</span>
                          ) : (
                            <span>—</span>
                          )
                        ) : (
                          <span>—</span>  
                        )
                      ) : (
                        <span className="text-[11px] font-normal">—</span>
                      )}
                    </div>
                    <span className="text-[12px] font-normal text-muted-foreground">
                      {periodLabel}
                    </span>
                  </div>
                </div>
                <div className="mt-6 h-8 w-full">
                  {miniSeries.length > 0 ? (
                    <div className="h-full w-full text-slate-500 dark:text-slate-300">
                      <ResponsiveContainer width="100%" height="100%">
                      <AreaChart data={miniSeries} margin={{ top: 2, right: 0, left: 0, bottom: 0 }}>
                        <defs>
                          <linearGradient id={`miniArea-${key}`} x1="0" y1="0" x2="0" y2="1">
                            <stop offset="0%" stopColor="currentColor" stopOpacity={0.12} />
                            <stop offset="100%" stopColor="currentColor" stopOpacity={0.01} />
                          </linearGradient>
                        </defs>
                        <Area
                          type="monotone"
                          dataKey="value"
                          stroke="currentColor"
                          strokeWidth={1.1}
                          fill={`url(#miniArea-${key})`}
                          isAnimationActive
                          animationDuration={500}
                          animationEasing="ease-out"
                        />
                      </AreaChart>
                    </ResponsiveContainer>
                    </div>
                  ) : (
                    <div className="h-full rounded-md bg-muted/40" />
                  )}
                </div>
              </CardContent>
            </Card>
            </Motion.div>
          )
        })}
      </Motion.div>

      {/* ── Insight row: Today's Leaves · Half-Day Summary · Quick Actions ── */}
      <Motion.div
        className="grid gap-6 gap-y-8 @sm:grid-cols-2 @xl:grid-cols-3"
        variants={containerVariants}
        initial="hidden"
        whileInView="visible"
        viewport={scrollViewport}
        transition={scrollRevealTransition}
      >
        {/* 1. Today's Leaves */}
        <Motion.div variants={itemVariants} whileHover={{ y: -2, transition: { duration: 0.15 } }}>
          <Card className="h-full gap-0 overflow-hidden rounded-2xl border border-border/70 bg-card/95 py-0 shadow-[0_1px_0_rgba(15,23,42,0.04),0_14px_34px_rgba(15,23,42,0.08)] transition-[transform,box-shadow] duration-300 hover:-translate-y-px hover:shadow-[0_1px_0_rgba(15,23,42,0.05),0_20px_50px_rgba(15,23,42,0.12)] dark:bg-card/90 dark:shadow-[0_1px_0_rgba(255,255,255,0.03),0_22px_60px_rgba(0,0,0,0.38)]">
            <CardHeader className="px-7 pb-6 pt-7">
              <div className="flex items-start justify-between gap-4">
                <div>
                  <CardTitle className="mb-5 text-xl font-semibold leading-snug tracking-tight text-foreground">
                    Today&apos;s Leaves
                  </CardTitle>
                  <CardDescription className="mt-0 text-sm font-normal leading-[1.55] text-muted-foreground">
                    {todayLeaves.length > 0
                      ? `${todayLeaves.length} employee${todayLeaves.length !== 1 ? 's are' : ' is'} on leave today`
                      : 'No approved leaves scheduled for today'}
                  </CardDescription>
                </div>
                <CalendarOff className="mt-1 size-4 shrink-0 text-muted-foreground" />
              </div>
            </CardHeader>
            <CardContent className="space-y-4 px-7 pb-7 pt-0">
              {todayLeaves.length === 0 ? (
                <div className="rounded-xl border border-dashed border-emerald-300/45 bg-emerald-500/5 p-5 text-center dark:border-emerald-500/40 dark:bg-emerald-900/20">
                  <p className="text-base font-normal leading-[1.55] text-foreground">No leaves today. Everyone is present.</p>
                  <p className="mt-3 text-sm font-normal leading-[1.55] text-muted-foreground">
                    This section updates automatically from approved leave requests.
                  </p>
                </div>
              ) : (
                <div className="flex gap-3 overflow-x-auto pb-1 pr-1 @xl:grid @xl:grid-cols-2 @xl:overflow-visible">
                  {todayLeaves.map((leave) => {
                    const profileSrc = leave.profile_image_url || profileImageUrl(leave.profile_image) || undefined
                    const secondary = [leave.department, leave.position].filter(Boolean).join(' / ') || 'Unassigned'
                    return (
                      <div
                        key={`${leave.leave_request_id}-${leave.user_id}`}
                        className="min-w-[260px] rounded-xl border border-border/70 bg-background/55 p-3.5 shadow-sm transition-[transform,box-shadow,border-color] duration-200 hover:-translate-y-px hover:border-border hover:shadow-md @xl:min-w-0"
                      >
                        <div className="flex items-start gap-3">
                          <Avatar className="size-10 shrink-0 rounded-full border border-border/70 ring-2 ring-primary/10">
                            <AvatarImage src={profileSrc} alt={leave.employee_name || 'Employee'} />
                            <AvatarFallback>{(leave.employee_name || '—').slice(0, 2).toUpperCase()}</AvatarFallback>
                          </Avatar>
                          <div className="min-w-0 flex-1">
                            <p className="truncate text-sm font-semibold text-foreground">{leave.employee_name || '—'}</p>
                            <p className="mt-0.5 truncate text-xs text-muted-foreground">{secondary}</p>
                            <div className="mt-2 flex items-center gap-2">
                              <span className="inline-flex items-center rounded-full border border-violet-500/35 bg-violet-500/10 px-2 py-0.5 text-[10px] font-semibold uppercase tracking-wide text-violet-700 dark:text-violet-300">
                                {leave.leave_type || 'Leave'}
                              </span>
                              <span className="text-[11px] font-medium text-muted-foreground">{leave.duration_label || 'Full day'}</span>
                            </div>
                          </div>
                        </div>
                      </div>
                    )
                  })}
                </div>
              )}
            </CardContent>
          </Card>
        </Motion.div>

        {/* 2. Half-Day Summary – clickable drill-down */}
        <Motion.div
          variants={itemVariants}
          whileHover={{ y: -2, scale: 1.02, transition: { duration: 0.15 } }}
          className="cursor-pointer"
          style={{ display: 'none' }}
          aria-hidden="true"
        >
          <Card 
            role="button"
            tabIndex={0}
            onClick={openHalfDayModal}
            onKeyDown={(e) => { if (e.key === 'Enter' || e.key === ' ') { e.preventDefault(); openHalfDayModal() } }}
            className="h-full overflow-hidden border-t-2 border-t-violet-500/70 border border-border/70 bg-card/95 shadow-md transition-all duration-200 dark:bg-white/4 dark:backdrop-blur-sm hover:shadow-lg hover:shadow-violet-500/20 hover:border-violet-500/50 dark:hover:border-white/20 hover:scale-[1.02] focus:outline-none focus:ring-2 focus:ring-violet-500/50 focus:ring-offset-2"
            aria-label="View half-day leave report"
          >
            <div className="pointer-events-none absolute inset-0 bg-linear-to-br from-violet-500/10 via-violet-500/0 to-transparent opacity-80" aria-hidden="true" />
            <CardHeader className="relative z-10 flex flex-row items-center justify-between space-y-0 pb-1.5">
              <CardTitle className="text-[11px] font-semibold uppercase tracking-wide text-muted-foreground">Half-Day Summary</CardTitle>
              <div className="flex h-8 w-8 items-center justify-center rounded-full bg-muted/80 shadow-sm ring-1 ring-border/60">
                <Clock className="size-4 text-muted-foreground/90" />
              </div>
            </CardHeader>
            <CardContent className="relative z-10">
              <div className="space-y-2">
                <div className="flex items-center justify-between text-sm">
                  <span className="flex items-center gap-1.5 text-muted-foreground">
                    <Sun className="size-3.5 text-amber-500" />
                    AM Half-Day
                  </span>
                  <span className="font-bold tabular-nums">
                    {halfDaySummary.am_today ?? 0}
                    {halfDaySummary.total_workforce > 0 && (
                      <span className="ml-1 text-[11px] font-normal text-muted-foreground">
                        ({((halfDaySummary.am_today / halfDaySummary.total_workforce) * 100).toFixed(1)}%)
                      </span>
                    )}
                  </span>
                </div>
                <div className="flex items-center justify-between text-sm">
                  <span className="flex items-center gap-1.5 text-muted-foreground">
                    <Moon className="size-3.5 text-indigo-500" />
                    PM Half-Day
                  </span>
                  <span className="font-bold tabular-nums">
                    {halfDaySummary.pm_today ?? 0}
                    {halfDaySummary.total_workforce > 0 && (
                      <span className="ml-1 text-[11px] font-normal text-muted-foreground">
                        ({((halfDaySummary.pm_today / halfDaySummary.total_workforce) * 100).toFixed(1)}%)
                      </span>
                    )}
                  </span>
                </div>
                <div className="border-t border-border/60 pt-2 flex items-center justify-between text-sm font-semibold">
                  <span>Total</span>
                  <span className="tabular-nums">
                    {halfDaySummary.total_today ?? 0}
                    {halfDaySummary.total_workforce > 0 && (
                      <span className="ml-1 text-[11px] font-normal text-muted-foreground">
                        ({((halfDaySummary.total_today / halfDaySummary.total_workforce) * 100).toFixed(1)}%)
                      </span>
                    )}
                  </span>
                </div>
              </div>
              {(halfDaySummary.total_today ?? 0) === 0 && (
                <p className="mt-2 text-[11px] text-muted-foreground">0 AM / 0 PM today</p>
              )}
            </CardContent>
          </Card>
        </Motion.div>

        {/* 3. Quick Actions + Workforce Status */}
        <Motion.div
          variants={itemVariants}
          whileHover={{ y: -2, transition: { duration: 0.15 } }}
          className="@sm:col-span-2 @xl:col-span-1"
          style={{ display: 'none' }}
          aria-hidden="true"
        >
          <Card className="h-full overflow-hidden border border-border/70 bg-card/95 shadow-md transition-all duration-200 hover:shadow-xl hover:shadow-primary/10">
            <CardHeader className="pb-3">
              <div className="flex items-center justify-between gap-2">
                <div>
                  <CardTitle className="text-base font-semibold">Quick Actions</CardTitle>
                  <CardDescription className="text-xs">Common admin shortcuts</CardDescription>
                </div>
                <Zap className="size-4 shrink-0 text-muted-foreground" />
              </div>
            </CardHeader>
            <CardContent className="space-y-2 pb-4">
              {[
                { label: 'Assign Schedule', icon: CalendarDays, path: hrPanelPath(hrBase, 'schedules'), show: perms.has('schedule.view') || perms.has('schedule.assign') },
                { label: 'Attendance Report', icon: FileBarChart2, path: hrPanelPath(hrBase, 'reports'), show: perms.has('reports.view') },
                { label: 'Approve Leave', icon: ClipboardCheck, path: hrPanelPath(hrBase, 'leave'), show: perms.has('leave.view') },
                { label: 'Manage Employees', icon: UserPlus, path: hrPanelPath(hrBase, 'employees'), show: perms.has('employees.view') },
              ]
                .filter((a) => a.show)
                .map((action) => {
                const ActionIcon = action.icon
                return (
                  <button
                    key={action.path}
                    type="button"
                    onClick={() => navigate(action.path)}
                    className="flex w-full cursor-pointer items-center gap-3 rounded-lg border border-border/50 bg-muted/15 px-3 py-2.5 text-left text-sm font-medium text-foreground transition-all duration-100 hover:border-primary/40 hover:bg-primary/5 hover:text-primary hover:shadow-sm active:scale-[0.98]"
                  >
                    <div className="flex size-7 shrink-0 items-center justify-center rounded-md bg-muted/60 text-muted-foreground">
                      <ActionIcon className="size-3.5" />
                    </div>
                    {action.label}
                  </button>
                )
              })}
              {/* Workforce status mini-strip */}
              <div className="mt-1 grid grid-cols-3 divide-x divide-border/50 rounded-lg border border-border/50 bg-muted/10 text-center text-[11px]">
                <div className="px-2 py-2">
                  <p className="font-bold text-emerald-600 dark:text-emerald-400 tabular-nums">{currentlyWorking}</p>
                  <p className="text-muted-foreground leading-tight">Working</p>
                </div>
                <div className="px-2 py-2">
                  <p className="font-bold text-slate-500 tabular-nums">{completedShift}</p>
                  <p className="text-muted-foreground leading-tight">Done</p>
                </div>
                <div className="px-2 py-2">
                  <p className="font-bold text-rose-500 tabular-nums">{stats.absent_today ?? 0}</p>
                  <p className="text-muted-foreground leading-tight">Absent</p>
                </div>
              </div>
            </CardContent>
          </Card>
        </Motion.div>

        {/* 3. Expiring Contracts */}
        <Motion.div variants={itemVariants}>
          <ExpiringContractsCard
            loading={loading && !data}
            contracts={expiringContracts}
            profileImageUrl={profileImageUrl}
            onViewAll={() => navigate(hrPanelPath(hrBase, 'employees'))}
            onRenewContract={(emp) => handleReviewContract(emp)}
          />
        </Motion.div>

        {/* 4. Required Actions Before Confirmation */}
        <Motion.div variants={itemVariants}>
          <Card className="h-full w-full max-w-full gap-0 overflow-hidden rounded-2xl border border-border/70 bg-card/95 py-0 shadow-[0_1px_0_rgba(15,23,42,0.04),0_14px_34px_rgba(15,23,42,0.08)] transition-[transform,box-shadow] duration-300 hover:-translate-y-px hover:shadow-[0_1px_0_rgba(15,23,42,0.05),0_20px_50px_rgba(15,23,42,0.12)] dark:bg-card/90 dark:shadow-[0_1px_0_rgba(255,255,255,0.03),0_22px_60px_rgba(0,0,0,0.38)]">
            <CardHeader className="px-4 pb-4 pt-4 sm:px-5 sm:pb-5 sm:pt-5 lg:px-7 lg:pb-6 lg:pt-7">
              <div className="flex flex-col gap-3 sm:flex-row sm:items-start sm:justify-between sm:gap-4">
                <div className="min-w-0">
                  <CardTitle className="mb-2 text-base font-semibold leading-snug tracking-tight text-foreground sm:text-lg lg:mb-3 lg:text-xl">
                    Required Actions Before Confirmation
                  </CardTitle>
                  <CardDescription className="mt-0 text-xs font-normal leading-relaxed text-muted-foreground sm:text-sm sm:leading-[1.55]">
                    Pending performance reviews and checklist items.
                  </CardDescription>
                </div>
                <Button
                  type="button"
                  variant="outline"
                  size="sm"
                  className="h-9 w-full rounded-full border-border/70 bg-background/70 px-3 text-xs font-medium shadow-sm shadow-black/5 transition-[background-color,box-shadow,color] duration-200 hover:bg-accent/55 hover:shadow-black/10 sm:mt-1 sm:h-auto sm:w-auto sm:shrink-0 sm:px-3.5 sm:text-sm"
                  onClick={() => navigate(hrPanelPath(hrBase, 'regularization'))}
                >
                  View All
                </Button>
              </div>
            </CardHeader>
            <CardContent className="flex flex-1 flex-col gap-3 px-4 pb-4 pt-0 sm:gap-4 sm:px-5 sm:pb-5 lg:px-7 lg:pb-7">
              {loading && !data ? (
                <div className="rounded-xl border border-border/70 bg-muted/15 p-4 text-xs font-normal leading-relaxed text-muted-foreground sm:rounded-2xl sm:p-5 sm:text-sm sm:leading-[1.55]">
                  Loading required actions...
                </div>
              ) : requiredConfirmationActions.length === 0 ? (
                <div className="rounded-xl border border-border/70 bg-muted/15 p-4 text-sm font-normal leading-relaxed text-muted-foreground sm:rounded-2xl sm:p-5 sm:text-base sm:leading-[1.55]">
                  No required actions pending.
                </div>
              ) : (
                <div className="space-y-3 sm:space-y-4">
                  {requiredConfirmationActions.map((emp) => (
                    <div
                      key={emp.id}
                      className="group rounded-xl border border-border/70 bg-background/45 p-3 shadow-[inset_0_1px_0_rgba(255,255,255,0.03),0_1px_2px_rgba(15,23,42,0.05)] transition-[transform,box-shadow,background-color,border-color] duration-250 hover:-translate-y-px hover:border-border hover:bg-accent/30 hover:shadow-[0_1px_0_rgba(15,23,42,0.03),0_16px_34px_rgba(15,23,42,0.08)] sm:rounded-2xl sm:p-4"
                    >
                      <div className="flex flex-col gap-2 sm:flex-row sm:items-start sm:justify-between sm:gap-3">
                        <div className="flex min-w-0 items-start gap-2.5 sm:gap-3">
                        <Avatar className="h-10 w-10 border border-border/70 shadow-md shadow-black/10 ring-1 ring-border/60 sm:h-11 sm:w-11">
                          <AvatarImage src={profileImageUrl(emp.profile_image_url)} alt="" className="object-cover" />
                          <AvatarFallback>{String(emp.name || 'U').slice(0, 1)}</AvatarFallback>
                        </Avatar>
                        <div className="min-w-0 flex-1">
                          <p className="wrap-break-word text-sm font-semibold tracking-[-0.012em] text-foreground">{emp.name}</p>
                          <p className="mt-1 text-xs leading-relaxed text-muted-foreground">
                            Department: {emp.department || 'Unassigned'}{emp.branch ? ` / ${emp.branch}` : ''}
                          </p>
                          <p className="mt-1 text-xs leading-relaxed text-muted-foreground">
                            Hired: {formatDate(emp.hire_date)} • Probation end: {formatDate(emp.probation_end_date)}
                          </p>
                          <div className="mt-2 flex flex-wrap gap-1.5">
                            <span
                              className={cn(
                                'inline-flex rounded-full border border-amber-500/20 bg-amber-500/12 px-2.5 py-0.5 text-[11px] font-medium text-amber-800 dark:border-amber-400/20 dark:bg-amber-400/10 dark:text-amber-200',
                                emp.performance_review_completed
                                  ? 'bg-emerald-500/15 text-emerald-700 dark:text-emerald-300 border-emerald-500/20 dark:border-emerald-400/20'
                                  : ''
                              )}
                            >
                              Performance Review: {emp.performance_review_completed ? 'Completed' : 'Pending'}
                            </span>
                            <span
                              className={cn(
                                'inline-flex rounded-full border border-amber-500/20 bg-amber-500/12 px-2.5 py-0.5 text-[11px] font-medium text-amber-800 dark:border-amber-400/20 dark:bg-amber-400/10 dark:text-amber-200',
                                emp.checklist_completed
                                  ? 'bg-emerald-500/15 text-emerald-700 dark:text-emerald-300 border-emerald-500/20 dark:border-emerald-400/20'
                                  : ''
                              )}
                            >
                              Checklist: {emp.checklist_completed ? 'Completed' : 'Pending'}
                            </span>
                          </div>
                        </div>
                        </div>
                        <span className="w-fit self-start rounded-full border border-rose-500/20 bg-rose-500/10 px-2.5 py-1 text-[11px] font-semibold tracking-[-0.01em] text-rose-800 shadow-[inset_0_1px_0_rgba(255,255,255,0.25)] dark:border-rose-400/20 dark:bg-rose-400/10 dark:text-rose-200 sm:shrink-0 sm:self-auto">
                          {formatDaysLabel(emp.days_remaining_label) || 'Pending timeline'}
                        </span>
                      </div>
                      <div className="mt-3 flex flex-col gap-2 sm:mt-4 sm:flex-row sm:items-center sm:justify-between">
                        <span className="text-xs leading-relaxed text-muted-foreground">Pending performance reviews and checklist items.</span>
                        <Button
                          type="button"
                          size="sm"
                          variant="outline"
                          className="h-8 w-full rounded-xl border-border/70 bg-background/80 px-3 text-xs font-medium tracking-[-0.01em] text-foreground/90 shadow-sm shadow-black/5 transition-[background-color,box-shadow,transform] duration-200 hover:bg-accent/50 hover:shadow-black/10 active:translate-y-px sm:h-9 sm:w-auto sm:px-4"
                          onClick={() => navigate(hrPanelPath(hrBase, 'regularization'))}
                        >
                          Review Actions
                        </Button>
                      </div>
                    </div>
                  ))}
                </div>
              )}
            </CardContent>
          </Card>
        </Motion.div>
      </Motion.div>

      <Motion.div
        className="grid items-stretch gap-5 @xl:grid-cols-3"
        variants={containerVariants}
        style={{ display: 'none' }}
        aria-hidden="true"
        initial="hidden"
        whileInView="visible"
        viewport={scrollViewport}
        transition={scrollRevealTransition}
      >
        <Motion.div variants={itemVariants} style={{ display: 'none' }} aria-hidden="true">
          <Card className="h-full overflow-hidden border border-border/80 bg-card/95 shadow-md transition-all duration-150 hover:shadow-xl">
          <CardHeader className="pb-3">
            <div className="flex items-start justify-between gap-3">
              <div className="min-w-0">
                <CardTitle className="truncate text-lg font-semibold">Upcoming Milestones</CardTitle>
                <CardDescription className="mt-0.5 text-xs leading-relaxed">
                  Automatic confirmation after 6 months, or early confirmation after 3 months with head recommendation and HR approval. 
                </CardDescription>
              </div>
              <Button
                type="button"
                variant="outline"
                size="sm"
                className="shrink-0"
                onClick={() => navigate(hrPanelPath(hrBase, 'regularization'))}
              >
                View All
              </Button>
            </div>
          </CardHeader>
          <CardContent className="flex flex-1 flex-col gap-3">
            {loading && !data ? (
              <div className="rounded-lg border border-border/70 bg-muted/20 px-4 py-6 text-sm text-muted-foreground">
                Loading upcoming milestones...
              </div>
            ) : upcomingRegularizations.length === 0 ? (
              <div className="rounded-lg border border-border/70 bg-muted/20 px-4 py-6 text-sm text-muted-foreground">
                No upcoming milestones.
              </div>
            ) : (
              <div className="space-y-3">
              {upcomingRegularizations.map((emp) => {
                const rowBusy = !!regularizationActionById[emp.id]
                const canRecommend = !!emp?.actions?.can_recommend_early
                const canReview = !!emp?.actions?.can_review_approve
                const badgeClass =
                  emp.indicator === 'red'
                    ? 'border border-rose-500/30 bg-rose-500/10 text-rose-800 dark:text-rose-300'
                    : emp.indicator === 'green'
                      ? 'border border-emerald-500/30 bg-emerald-500/10 text-emerald-800 dark:text-emerald-300'
                      : 'border border-amber-500/30 bg-amber-500/10 text-amber-800 dark:text-amber-300'
                const employmentTypeLabel = formatEmploymentTypeLabel(emp.employment_type)
                const deptBranchLabel = `${emp.department || 'Unassigned'}${emp.branch ? ` / ${emp.branch}` : ''}`
                const statusBadgeText = `${emp.status_label || emp.indicator_label || 'Status'} • ${formatDaysLabel(emp.days_remaining_label)}`

                return (
                  <div
                    key={emp.id}
                    className="rounded-2xl border border-border/80 bg-card/95 p-4 shadow-sm transition-all duration-150 hover:shadow-md"
                  >
                    <div className="flex items-start gap-4">
                      <Avatar className="h-14 w-14 shrink-0 ring-1 ring-border/70">
                        <AvatarImage src={profileImageUrl(emp.profile_image_url)} alt="" className="object-cover" />
                        <AvatarFallback>{String(emp.name || 'U').slice(0, 1)}</AvatarFallback>
                      </Avatar>

                      <div className="min-w-0 flex-1">
                        <div className="flex items-start justify-between gap-3">
                          <button
                            type="button"
                            onClick={() => navigate(hrPanelPath(hrBase, `employees/${emp.id}`))}
                            className="min-w-0 text-left"
                          >
                            <p className="truncate text-base font-semibold leading-tight text-foreground">
                              {emp.name}
                            </p>
                            <p className="mt-0.5 truncate text-xs text-muted-foreground">
                              {deptBranchLabel}
                            </p>
                          </button>
                          <span
                            className={cn(
                              'inline-flex items-center rounded-full px-3 py-1 text-[11px] font-semibold',
                              'bg-background/70 backdrop-blur-sm',
                              badgeClass
                            )}
                          >
                            {statusBadgeText}
                          </span>
                        </div>

                        <div className="mt-2 flex flex-wrap items-center gap-2 text-xs text-muted-foreground">
                          <span className="rounded-full border border-border/70 bg-muted/20 px-2 py-0.5 font-medium text-foreground/80">
                            {emp.employee_code || '—'}
                          </span>
                          <span className="rounded-full border border-border/70 bg-muted/20 px-2 py-0.5 capitalize">
                            {employmentTypeLabel}
                          </span>
                          <span className="text-muted-foreground/60">•</span>
                          <span>
                            Hired <span className="font-medium text-foreground/85">{formatDate(emp.hire_date)}</span>
                          </span>
                        </div>

                        <div className="mt-2 grid gap-1.5 text-xs @sm:grid-cols-2">
                          <p className="text-muted-foreground">
                            Service length:{' '}
                            <span className="font-medium text-foreground/85">{emp.service_length_label || '—'}</span>
                          </p>
                          <p className="text-muted-foreground">
                            Next milestone:{' '}
                            <span className="font-medium text-foreground/85">
                              {emp.next_milestone || '—'} ({formatDate(emp.next_milestone_date)})
                            </span>
                          </p>
                        </div>

                        <div className="mt-3 rounded-xl border border-border/60 bg-muted/10 px-3 py-2.5">
                          <p className="text-[11px] font-semibold uppercase tracking-wide text-muted-foreground">
                            Recommended action
                          </p>
                          <p className="mt-1 text-sm leading-snug text-foreground/90">
                            {emp.recommended_action || '—'}
                          </p>
                        </div>

                        <div className="mt-3 flex flex-wrap gap-2">
                          {canRecommend && (
                            <Button
                              type="button"
                              size="sm"
                              disabled={rowBusy}
                              onClick={() => handleRecommendEarly(emp)}
                              className="shadow-sm"
                            >
                              Recommend Early Regularization
                            </Button>
                          )}
                          {canReview && (
                            <>
                              <Button
                                type="button"
                                size="sm"
                                disabled={rowBusy}
                                onClick={() => handleHrApprove(emp)}
                                className="shadow-sm"
                              >
                                Review & Approve
                              </Button>
                              <Button
                                type="button"
                                variant="destructive"
                                size="sm"
                                disabled={rowBusy}
                                onClick={() => handleHrReject(emp)}
                              >
                                Reject
                              </Button>
                            </>
                          )}
                        </div>
                      </div>
                    </div>

                  </div>
                )
              })}
              </div>
            )}
          </CardContent>
        </Card>
        </Motion.div>

        <Motion.div variants={itemVariants}>
          <Card className="h-full overflow-hidden border border-border/80 bg-card/95 shadow-md transition-all duration-150 hover:shadow-xl">
            <CardHeader className="pb-3">
              <div className="flex items-start justify-between gap-3">
                <div className="min-w-0">
                  <CardTitle className="truncate text-lg font-semibold">Expiring Contracts</CardTitle>
                  <CardDescription className="mt-0.5 text-xs leading-relaxed">Contracts ending soon in your scope.</CardDescription>
                </div>
                <Button
                  type="button"
                  variant="outline"
                  size="sm"
                  className="shrink-0"
                  onClick={() => navigate(hrPanelPath(hrBase, 'employees'))}
                >
                  View All
                </Button>
              </div>
            </CardHeader>
            <CardContent className="flex flex-1 flex-col gap-3">
              {loading && !data ? (
                <div className="rounded-lg border border-border/70 bg-muted/20 px-4 py-6 text-sm text-muted-foreground">
                  Loading expiring contracts...
                </div>
              ) : expiringContracts.length === 0 ? (
                <div className="rounded-lg border border-border/70 bg-muted/20 px-4 py-6 text-sm text-muted-foreground">
                  No expiring contracts.
                </div>
              ) : (
                <div className="space-y-3">
                {expiringContracts.map((emp) => {
                  const tone = emp.days_tone || 'neutral'
                  const urgentClass =
                    tone === 'red'
                      ? 'border-rose-500/40 bg-rose-500/8'
                      : tone === 'orange'
                        ? 'border-amber-500/30 bg-amber-500/10'
                        : 'border-border/70 bg-card/70'
                  const urgentBadgeClass =
                    tone === 'red'
                      ? 'bg-rose-500/15 text-rose-800 dark:text-rose-300'
                      : tone === 'orange'
                        ? 'bg-amber-500/20 text-amber-800 dark:text-amber-300'
                        : 'bg-muted text-muted-foreground'

                  return (
                    <div key={emp.id} className={cn('rounded-xl border px-3.5 py-3 transition-all hover:shadow-sm', urgentClass)}>
                      <div className="flex flex-col gap-3 @md:flex-row @md:items-start @md:justify-between">
                        <button
                          type="button"
                          onClick={() => navigate(hrPanelPath(hrBase, `employees/${emp.id}`))}
                          className="flex items-start gap-3 text-left"
                        >
                          <Avatar className="h-10 w-10 border border-border/60">
                            <AvatarImage src={profileImageUrl(emp.profile_image_url)} alt="" className="object-cover" />
                            <AvatarFallback>{String(emp.name || 'U').slice(0, 1)}</AvatarFallback>
                          </Avatar>
                          <div className="space-y-0.5">
                            <p className="text-sm font-semibold leading-tight text-foreground">{emp.name}</p>
                            <p className="text-xs text-muted-foreground">
                              {emp.contract_type || 'Contractual'}
                            </p>
                            <p className="text-xs text-muted-foreground">
                              {emp.department || 'Unassigned'}
                              {emp.branch ? ` / ${emp.branch}` : ''}
                            </p>
                            <p className="text-xs text-muted-foreground">
                              Start: {formatDate(emp.contract_start_date)} • End: {formatDate(emp.contract_end_date)}
                            </p>
                          </div>
                        </button>
                        <div className="flex flex-col items-start gap-2 @md:items-end">
                          <span className={cn('inline-flex rounded-full px-2.5 py-1 text-[11px] font-semibold', urgentBadgeClass)}>
                            {formatDaysLabel(emp.days_remaining_label)}
                          </span>
                          {emp.recommended_action ? (
                            <span className="max-w-104 text-[11px] leading-snug text-muted-foreground @md:text-right">
                              {emp.recommended_action}
                            </span>
                          ) : null}
                        </div>
                      </div>
                      {!!emp?.actions?.can_review_contract && (
                        <div className="mt-3">
                          <Button type="button" size="sm" onClick={() => handleReviewContract(emp)}>
                            {emp.actions.review_button_label || 'Review Contract'}
                          </Button>
                        </div>
                      )}
                    </div>
                  )
                })}
                </div>
              )}
            </CardContent>
          </Card>
        </Motion.div>

        <Motion.div variants={itemVariants}>
          <Card className="h-full overflow-hidden border border-border/80 bg-card/95 shadow-md transition-all duration-150 hover:shadow-xl">
            <CardHeader className="pb-3">
              <div className="flex items-start justify-between gap-3">
                <div className="min-w-0">
                  <CardTitle className="truncate text-lg font-semibold">Required Actions Before Confirmation</CardTitle>
                  <CardDescription className="mt-0.5 text-xs leading-relaxed">Pending performance reviews and checklist items.</CardDescription>
                </div>
                <Button
                  type="button"
                  variant="outline"
                  size="sm"
                  className="shrink-0"
                  onClick={() => navigate(hrPanelPath(hrBase, 'regularization'))}
                >
                  View All
                </Button>
              </div>
            </CardHeader>
            <CardContent className="flex flex-1 flex-col gap-3">
              {loading && !data ? (
                <div className="rounded-lg border border-border/70 bg-muted/20 px-4 py-6 text-sm text-muted-foreground">
                  Loading required actions...
                </div>
              ) : requiredConfirmationActions.length === 0 ? (
                <div className="rounded-lg border border-border/70 bg-muted/20 px-4 py-6 text-sm text-muted-foreground">
                  No required actions pending.
                </div>
              ) : (
                <div className="space-y-3">
                {requiredConfirmationActions.map((emp) => (
                  <div key={emp.id} className="rounded-xl border border-border/70 bg-card/70 px-3.5 py-3 transition-all hover:shadow-sm">
                    <div className="flex items-start gap-3">
                      <Avatar className="h-10 w-10 border border-border/60">
                        <AvatarImage src={profileImageUrl(emp.profile_image_url)} alt="" className="object-cover" />
                        <AvatarFallback>{String(emp.name || 'U').slice(0, 1)}</AvatarFallback>
                      </Avatar>
                      <div className="min-w-0 flex-1">
                        <p className="truncate text-sm font-semibold text-foreground">{emp.name}</p>
                        <p className="text-xs text-muted-foreground">
                          {emp.department || 'Unassigned'}{emp.branch ? ` / ${emp.branch}` : ''}
                        </p>
                        <p className="text-xs text-muted-foreground">
                          Hired: {formatDate(emp.hire_date)} • Probation end: {formatDate(emp.probation_end_date)}
                        </p>
                        <div className="mt-2 flex flex-wrap gap-1.5">
                          <span className={cn(
                            'inline-flex rounded-full px-2 py-0.5 text-[11px] font-medium',
                            emp.performance_review_completed
                              ? 'bg-emerald-500/15 text-emerald-700 dark:text-emerald-300'
                              : 'bg-amber-500/20 text-amber-700 dark:text-amber-300'
                          )}>
                            Performance Review: {emp.performance_review_completed ? 'Completed' : 'Pending'}
                          </span>
                          <span className={cn(
                            'inline-flex rounded-full px-2 py-0.5 text-[11px] font-medium',
                            emp.checklist_completed
                              ? 'bg-emerald-500/15 text-emerald-700 dark:text-emerald-300'
                              : 'bg-amber-500/20 text-amber-700 dark:text-amber-300'
                          )}>
                            Checklist: {emp.checklist_completed ? 'Completed' : 'Pending'}
                          </span>
                          <span className={cn(
                            'inline-flex rounded-full px-2 py-0.5 text-[11px] font-medium',
                            emp.training_completed
                              ? 'bg-emerald-500/15 text-emerald-700 dark:text-emerald-300'
                              : 'bg-amber-500/20 text-amber-700 dark:text-amber-300'
                          )}>
                            Training: {emp.training_completed ? 'Completed' : 'Pending'}
                          </span>
                          <span className={cn(
                            'inline-flex rounded-full px-2 py-0.5 text-[11px] font-medium',
                            emp.documents_submitted
                              ? 'bg-emerald-500/15 text-emerald-700 dark:text-emerald-300'
                              : 'bg-amber-500/20 text-amber-700 dark:text-amber-300'
                          )}>
                            Documents: {emp.documents_submitted ? 'Submitted' : 'Pending'}
                          </span>
                          <span className={cn(
                            'inline-flex rounded-full px-2 py-0.5 text-[11px] font-medium',
                            emp.manager_recommendation_received
                              ? 'bg-emerald-500/15 text-emerald-700 dark:text-emerald-300'
                              : 'bg-amber-500/20 text-amber-700 dark:text-amber-300'
                          )}>
                            Manager Recommendation: {emp.manager_recommendation_received ? 'Received' : 'Pending'}
                          </span>
                        </div>
                      </div>
                    </div>
                    <div className="mt-3 flex flex-wrap items-center justify-between gap-2">
                      <span className="text-xs text-muted-foreground">{formatDaysLabel(emp.days_remaining_label) || 'Pending timeline'}</span>
                      <Button
                        type="button"
                        size="sm"
                        variant="outline"
                        onClick={() => navigate(hrPanelPath(hrBase, 'regularization'))}
                      >
                        Review Actions
                      </Button>
                    </div>
                  </div>
                ))}
                </div>
              )}
            </CardContent>
          </Card>
        </Motion.div>
      </Motion.div>

      {/* Charts row – redesigned UI */}
      <Motion.div
        className="grid gap-8 @lg:grid-cols-2"
        variants={containerVariants}
        initial="hidden"
        whileInView="visible"
        viewport={scrollViewport}
        transition={scrollRevealTransition}
      >
        {/* Weekly Attendance – vertical bars, stronger contrast */}
        <Motion.div variants={chartCardVariants} whileHover={{ y: -2, transition: { duration: 0.15 } }}>
          <Card className="overflow-hidden border border-border/80 bg-card/95 shadow-md transition-all duration-150 hover:shadow-xl">
          <CardHeader className="pb-5">
            <CardTitle className="mb-3 text-xl font-semibold leading-snug tracking-tight text-foreground">Weekly Attendance</CardTitle>
            <CardDescription className="mt-0 text-xs font-normal leading-[1.55] text-muted-foreground">
              Employees who clocked in per day (last 7 days)
            </CardDescription>
          </CardHeader>
          <CardContent className="pt-0">
            <div className="h-[300px] w-full rounded-xl bg-linear-to-b from-emerald-500/10 via-card to-card px-2 pt-2">
              {weeklyData.length > 0 ? (
                <ResponsiveContainer width="100%" height="100%">
                  <BarChart
                    data={weeklyData}
                    margin={{ top: 12, right: 12, left: 4, bottom: 8 }}
                    barCategoryGap={16}
                    barSize={42}
                  >
                    <defs>
                      <linearGradient id="weeklyBarGradient" x1="0" y1="0" x2="0" y2="1">
                        <stop offset="0%" stopColor={CHART.weeklyBar} stopOpacity={1} />
                        <stop offset="60%" stopColor={CHART.weeklyBar} stopOpacity={0.85} />
                        <stop offset="100%" stopColor={CHART.weeklyBar} stopOpacity={0.6} />
                      </linearGradient>
                    </defs>
                    <CartesianGrid
                      strokeDasharray="2 4"
                      stroke="rgba(148, 163, 184, 0.32)"
                      vertical
                    />
                    <XAxis
                      dataKey="label"
                      tick={{ fontSize: 12, fill: 'var(--muted-foreground)', fontWeight: 400 }}
                      axisLine={{ stroke: 'var(--border)' }}
                      tickLine={false}
                    />
                    <YAxis
                      tick={{ fontSize: 12, fill: 'var(--muted-foreground)', fontWeight: 400 }}
                      axisLine={false}
                      tickLine={false}
                      allowDecimals={false}
                      domain={[0, Math.max(5, weeklyMax + 1)]}
                      width={32}
                    />
                    <Tooltip
                      content={({ active, payload, label }) => (
                        <ChartTooltip active={active} payload={payload} label={label} labelPrefix="Day: " />
                      )}
                      wrapperStyle={TOOLTIP_STYLES.wrapperStyle}
                      contentStyle={TOOLTIP_STYLES.contentStyle}
                      cursor={{ fill: TOOLTIP_STYLES.cursorFill, stroke: TOOLTIP_STYLES.cursorStroke, radius: 4 }}
                    />
                    <Bar
                      dataKey="present_count"
                      name="Present"
                      fill="url(#weeklyBarGradient)"
                      radius={[12, 12, 6, 6]}
                      background={{ fill: 'rgba(16, 185, 129, 0.08)', radius: [12, 12, 6, 6] }}
                      minPointSize={2}
                      isAnimationActive
                      animationDuration={600}
                      animationEasing="ease-out"
                      activeBar={{
                        fill: CHART.weeklyBar,
                        stroke: 'hsl(160 84% 24%)',
                        strokeWidth: 2,
                        radius: [14, 14, 8, 8],
                      }}
                    />
                  </BarChart>
                </ResponsiveContainer>
              ) : (
                <div className="flex h-full items-center justify-center rounded-lg bg-muted/30 text-sm text-muted-foreground">
                  No data yet
                </div>
              )}
            </div>
          </CardContent>
        </Card>
        </Motion.div>

        {/* Monthly Late – area + line trend */}
        <Motion.div variants={chartCardVariants} whileHover={{ y: -2, transition: { duration: 0.15 } }}>
          <Card className="overflow-hidden border-border/80 bg-card/95 shadow-md transition-all duration-150 hover:shadow-xl">
          <CardHeader className="pb-5">
            <div className="flex flex-wrap items-start justify-between gap-4">
              <div>
                <CardTitle className="mb-3 text-xl font-semibold leading-snug tracking-tight text-foreground">
                  Monthly Late Statistics
                </CardTitle>
                <CardDescription className="mt-0 text-xs font-normal leading-[1.55] text-muted-foreground">
                  Late arrivals per month (last 12 months)
                </CardDescription>
              </div>
              <div className="flex flex-wrap items-center gap-2">
                {showMonthlyAvgLine && (
                  <span className="inline-flex items-center rounded-full border border-amber-500/30 bg-amber-500/10 px-2.5 py-1 text-[11px] font-medium text-amber-700 dark:text-amber-200">
                    Avg {monthlyLateBaselineAvg.toFixed(1)}
                  </span>
                )}
                {spikeDetected && (
                  <span className="inline-flex items-center gap-1.5 rounded-full border border-rose-500/40 bg-rose-500/15 px-2.5 py-1 text-[11px] font-semibold text-rose-700 dark:text-rose-200">
                    <span className="relative inline-flex size-2 shrink-0">
                      <span className="absolute inline-flex size-full animate-ping rounded-full bg-rose-400/70" />
                      <span className="relative inline-flex size-2 rounded-full bg-rose-500" />
                    </span>
                    Spike detected
                  </span>
                )}
              </div>
            </div>
          </CardHeader>
          <CardContent className="pt-0">
            <div className="h-[300px] w-full rounded-xl bg-linear-to-b from-amber-500/5 via-card to-card px-2 pt-2">
              {monthlyLateData.length > 0 ? (
                <ResponsiveContainer width="100%" height="100%">
                  <AreaChart
                    data={monthlyLateData}
                    margin={{ top: 16, right: 16, left: 8, bottom: 8 }}
                  >
                    <defs>
                      <linearGradient id="lateAreaGradient" x1="0" y1="0" x2="0" y2="1">
                        <stop offset="0%" stopColor={CHART.lateLine} stopOpacity={0.55} />
                        <stop offset="100%" stopColor={CHART.lateLine} stopOpacity={0} />
                      </linearGradient>
                    </defs>
                    <CartesianGrid
                      strokeDasharray="2 4"
                      stroke="rgba(148, 163, 184, 0.28)"
                      vertical
                    />
                    <XAxis
                      dataKey="label"
                      tick={{ fontSize: 12, fill: 'var(--muted-foreground)', fontWeight: 400 }}
                      axisLine={{ stroke: 'var(--border)' }}
                      tickLine={false}
                    />
                    <YAxis
                      tick={{ fontSize: 12, fill: 'var(--muted-foreground)', fontWeight: 400 }}
                      axisLine={false}
                      tickLine={false}
                      allowDecimals={false}
                      domain={[0, Math.max(1, monthlyLateMax + 1)]}
                      width={28}
                    />
                    <Tooltip
                      content={({ active, payload, label }) => (
                        <ChartTooltip active={active} payload={payload} label={label} labelPrefix="Month: " />
                      )}
                      wrapperStyle={TOOLTIP_STYLES.wrapperStyle}
                      contentStyle={TOOLTIP_STYLES.contentStyle}
                      cursor={{ fill: TOOLTIP_STYLES.cursorFill, stroke: TOOLTIP_STYLES.cursorStroke, strokeDasharray: '4 4' }}
                    />
                    {showMonthlyAvgLine && (
                      <ReferenceLine
                        y={monthlyLateBaselineAvg}
                        stroke="rgba(245, 158, 11, 0.65)"
                        strokeDasharray="6 6"
                        ifOverflow="extendDomain"
                      />
                    )}
                    <Area
                      type="monotone"
                      dataKey="late_count"
                      name="Late count"
                      stroke={CHART.lateLine}
                      strokeWidth={2.5}
                      fill="url(#lateAreaGradient)"
                      isAnimationActive
                      animationDuration={600}
                      animationEasing="ease-out"
                      dot={{ fill: CHART.lateLine, stroke: CHART.lateLine, strokeWidth: 2, r: 4 }}
                      activeDot={{ r: 6, strokeWidth: 2, fill: CHART.lateLine, stroke: CHART.lateLine }}
                    />
                    {spikeDetected && latestMonthlyLate?.label && typeof latestMonthlyLate?.late_count === 'number' && (
                      <ReferenceDot
                        x={latestMonthlyLate.label}
                        y={latestMonthlyLate.late_count}
                        r={6}
                        fill="hsl(0 84% 60%)"
                        stroke="var(--card)"
                        strokeWidth={2}
                        isFront
                      />
                    )}
                  </AreaChart>
                </ResponsiveContainer>
              ) : (
                <div className="flex h-full items-center justify-center rounded-lg bg-muted/30 text-sm text-muted-foreground">
                  No data yet
                </div>
              )}
            </div>
          </CardContent>
        </Card>
        </Motion.div>
      </Motion.div>

      {/* Company Attendance Comparison – horizontal bars with date + company filters */}
      <Motion.div
        variants={chartCardVariants}
        initial="hidden"
        whileInView="visible"
        viewport={scrollViewport}
        transition={{ ...scrollRevealTransition, duration: 0.9 }}
        whileHover={{ y: -2, transition: { duration: 0.15 } }}
      >
        <Card className="overflow-hidden border border-border/80 bg-card/95 shadow-md transition-all duration-150 hover:shadow-xl">
        <CardHeader className="pb-5">
          <div className="flex flex-wrap items-start justify-between gap-4">
            <div>
              <CardTitle className="mb-3 text-xl font-semibold leading-snug tracking-tight text-foreground">
                {isSingleCompany ? 'Company Attendance Overview' : 'Company Attendance Comparison'}
              </CardTitle>
              <CardDescription className="mt-0 text-xs font-normal leading-[1.55] text-muted-foreground">
                {isSingleCompany
                  ? `Attendance summary for ${companyData[0]?.company ?? 'company'}`
                  : 'Present employees by company · Attendance metrics per company'}
              </CardDescription>
            </div>
            {topCompany && topCompany.present > 0 && !isSingleCompany && (
              <div className="inline-flex items-center gap-1.5 rounded-full border border-emerald-500/40 bg-emerald-500/5 px-2.5 py-1 text-[11px] font-medium text-emerald-700 shadow-sm dark:border-emerald-400/40 dark:bg-emerald-500/15 dark:text-emerald-100">
                <span className="inline-flex size-1.5 rounded-full bg-emerald-500" />
                <span className="uppercase tracking-[0.12em] text-[10px] text-emerald-700/80 dark:text-emerald-100/80">
                  Top company
                </span>
                <span className="text-[11px] font-semibold text-foreground">
                  {topCompany.company} · {topCompany.present} present
                </span>
              </div>
            )}
          </div>
          {/* Filters: Date range + Company multi-select */}
          <div className="mt-5 flex flex-wrap items-center gap-3">
            <div className="flex items-center gap-2">
              <span className="text-[11px] font-normal uppercase tracking-wide text-muted-foreground">Date</span>
              <input
                type="date"
                value={companyDateFrom}
                onChange={(e) => setCompanyDateFrom(e.target.value)}
                className="rounded-md border border-border bg-background px-2 py-1.5 text-sm font-semibold text-foreground"
              />
              <span className="text-xs text-muted-foreground">→</span>
              <input
                type="date"
                value={companyDateTo}
                onChange={(e) => setCompanyDateTo(e.target.value)}
                className="rounded-md border border-border bg-background px-2 py-1.5 text-sm font-semibold text-foreground"
              />
              <Button
                variant="ghost"
                size="sm"
                className="h-7 px-2 text-xs font-semibold text-foreground"
                onClick={() => {
                  const today = toLocalDateString(new Date())
                  setCompanyDateFrom(today)
                  setCompanyDateTo(today)
                }}
              >
                Today
              </Button>
            </div>
            <div className="flex items-center gap-2">
              <span className="text-[11px] font-normal uppercase tracking-wide text-muted-foreground">Companies</span>
              <div className="flex flex-wrap gap-1.5">
                <button
                  type="button"
                  onClick={() => setSelectedCompanyIds([])}
                  className={`rounded-full px-3 py-1 text-xs transition-colors ${
                    selectedCompanyIds.length === 0
                      ? 'border-2 border-primary/70 bg-primary font-bold text-primary-foreground shadow-sm dark:bg-primary dark:text-primary-foreground'
                      : 'border border-border bg-muted/50 font-normal text-muted-foreground hover:bg-muted'
                  }`}
                >
                  All
                </button>
                {companiesList.map((c) => {
                  const isSelected = selectedCompanyIds.includes(c.id)
                  return (
                    <button
                      key={c.id}
                      type="button"
                      onClick={() => {
                        if (selectedCompanyIds.includes(c.id)) {
                          setSelectedCompanyIds(selectedCompanyIds.filter((id) => id !== c.id))
                        } else {
                          setSelectedCompanyIds([...selectedCompanyIds, c.id])
                        }
                      }}
                      className={`rounded-full px-3 py-1 text-xs transition-colors ${
                        isSelected
                          ? 'border-2 border-primary/70 bg-primary font-bold text-primary-foreground shadow-sm dark:bg-primary dark:text-primary-foreground'
                          : 'border border-border bg-muted/50 font-normal text-muted-foreground hover:bg-muted'
                      }`}
                    >
                      {c.name}
                    </button>
                  )
                })}
                {companiesList.length === 0 && (
                  <span className="text-[11px] text-muted-foreground">Loading…</span>
                )}
              </div>
            </div>
          </div>
          {totalCompanyPresent > 0 && !isSingleCompany && (
            <p className="mt-1.5 text-[11px] text-muted-foreground">
              {totalCompanyPresent} employees present across {companyData.length}{' '}
              {companyData.length === 1 ? 'company' : 'companies'}
              {totalCompanyHeadcount > 0
                ? ` (${Math.round((totalCompanyPresent / totalCompanyHeadcount) * 100)}% of headcount)`
                : ''}
            </p>
          )}
          {isSingleCompany && companyData[0] && (
            <p className="mt-1.5 text-[11px] text-muted-foreground">
              {companyData[0].company}: {companyData[0].present ?? 0} present ({companyData[0].present_pct ?? 0}%)
              {(companyData[0].headcount ?? 0) > 0 && ` · ${companyData[0].headcount} total staff`}
            </p>
          )}
          {/* Company legend – plain text, scrollable when many */}
          {companyData.length > 0 && !isSingleCompany && (
            <div className="mt-3 overflow-x-auto overflow-y-hidden scrollbar-thin">
              <div className="flex flex-nowrap gap-3 pb-1 min-w-0">
                {companyData.map((cd) => {
                  const isUnassigned = (cd.company || '').toLowerCase() === 'unassigned'
                  return (
                    <div
                      key={cd.company_id ?? cd.company}
                      className={`inline-flex shrink-0 items-center rounded-lg border border-border/50 px-2 py-1.5 ${isUnassigned ? 'bg-muted/10' : 'bg-muted/20'}`}
                    >
                      <span
                        className={`text-[11px] font-medium whitespace-nowrap ${isUnassigned ? 'text-muted-foreground italic' : ''}`}
                      >
                        {cd.company} · {cd.headcount ?? 0} staff
                      </span>
                    </div>
                  )
                })}
              </div>
            </div>
          )}
        </CardHeader>
        <CardContent className="pt-0">
          <div className="h-[300px] w-full rounded-xl bg-linear-to-r from-emerald-500/8 via-card to-card px-2 pt-2">
            {companyChartLoading ? (
              <div className="flex h-full items-center justify-center rounded-lg bg-muted/30 text-sm text-muted-foreground">
                Loading…
              </div>
            ) : companyData.length > 0 ? (
              <ResponsiveContainer width="100%" height="100%">
                <BarChart
                  layout="vertical"
                  data={companyData}
                  margin={{ top: 8, right: 16, left: 8, bottom: 8 }}
                >
                  <CartesianGrid
                    strokeDasharray="2 4"
                    stroke="rgba(148, 163, 184, 0.25)"
                    horizontal={false}
                  />
                  <XAxis
                    type="number"
                    tick={{ fontSize: 12, fill: 'var(--muted-foreground)', fontWeight: 400 }}
                    axisLine={{ stroke: 'var(--border)' }}
                    tickLine={false}
                    allowDecimals={false}
                  />
                  <YAxis
                    type="category"
                    dataKey="company"
                    tick={{ fontSize: 12, fill: 'var(--muted-foreground)', fontWeight: 400 }}
                    axisLine={false}
                    tickLine={false}
                    width={140}
                    tickFormatter={(value, index) => {
                      const item = companyData[index]
                      if (item?.headcount) {
                        return `${value} · ${item.headcount} staff`
                      }
                      return value
                    }}
                  />
                  <Tooltip
                    content={({ active, payload }) => {
                      if (!active || !payload?.length) return null
                      const row = payload[0]?.payload ?? {}
                      const present = row.present ?? 0
                      const headcount = row.headcount ?? null
                      const shareOfToday =
                        totalCompanyPresent > 0 ? Math.round((present / totalCompanyPresent) * 100) : 0
                      return (
                        <div
                          className="rounded-lg border px-3 py-2.5 text-sm shadow-lg"
                          style={{
                            backgroundColor: 'var(--card)',
                            borderColor: 'var(--border)',
                            color: 'var(--foreground)',
                            borderWidth: '1px',
                            borderStyle: 'solid',
                          }}
                          data-chart-tooltip
                        >
                          <p className="font-medium" style={{ color: 'var(--foreground)' }}>
                            {row.company}
                          </p>
                          <p className="mt-0.5 tabular-nums" style={{ color: 'var(--muted-foreground)' }}>
                            Present: <span className="font-semibold" style={{ color: 'var(--foreground)' }}>{present}</span>
                            {headcount > 0 && (
                              <span className="ml-1 text-xs">({row.present_pct}%)</span>
                            )}
                          </p>
                          {headcount !== null && headcount > 0 && (
                            <>
                              <p className="mt-0.5 tabular-nums text-xs" style={{ color: 'var(--muted-foreground)' }}>
                                Late: {row.late} · Absent: {row.absent} · On leave: {row.on_leave}
                              </p>
                              <p className="mt-0.5 tabular-nums" style={{ color: 'var(--muted-foreground)' }}>
                                Share: <span className="font-semibold">{shareOfToday}%</span>
                              </p>
                            </>
                          )}
                        </div>
                      )
                    }}
                    wrapperStyle={TOOLTIP_STYLES.wrapperStyle}
                    contentStyle={TOOLTIP_STYLES.contentStyle}
                    cursor={{ fill: TOOLTIP_STYLES.cursorFill, stroke: TOOLTIP_STYLES.cursorStroke, radius: 4 }}
                  />
                  <Bar
                    dataKey="present"
                    name="Present"
                    radius={[0, 10, 10, 0]}
                    maxBarSize={32}
                    isAnimationActive
                    animationDuration={1400}
                    animationEasing="ease-out"
                    label={{
                      position: 'right',
                      fill: 'var(--foreground)',
                      fontSize: 12,
                      formatter: (v) => {
                        if (!totalCompanyPresent) return `${v}`
                        const pct = Math.round((v / totalCompanyPresent) * 100)
                        return `${v} (${pct}%)`
                      },
                    }}
                  >
                    {companyData.map((entry, index) => (
                      <Cell key={`${entry.company}-${index}`} fill={entry.color} stroke={entry.color} strokeWidth={1.6} />
                    ))}
                  </Bar>
                </BarChart>
              </ResponsiveContainer>
            ) : (
              <div className="flex h-full items-center justify-center rounded-lg bg-muted/30 text-sm text-muted-foreground">
                No attendance data for selected date
              </div>
            )}
          </div>
        </CardContent>
      </Card>
      </Motion.div>

      {/* Data tables – Today's Attendance Logs (real-time) */}
      <Motion.div
        className="space-y-5"
        variants={itemVariants}
        initial="hidden"
        whileInView="visible"
        viewport={scrollViewport}
        transition={scrollRevealTransition}
      >
        <div className="flex items-center gap-2 text-muted-foreground">
          <Table2 className="size-5 shrink-0 opacity-80" aria-hidden />
          <h3 className="text-xs font-semibold uppercase tracking-[0.12em] text-muted-foreground">Data tables</h3>
        </div>
        <Card className="overflow-hidden border-0 bg-card shadow-sm">
          <CardHeader className="border-b border-border/40 bg-muted/25 px-7 pb-5 pt-6">
            <div className="flex flex-wrap items-start justify-between gap-4">
              <div>
                <CardTitle className="mb-3 text-xl font-semibold leading-snug tracking-tight text-foreground">
                  Today&apos;s Attendance
                </CardTitle>
                <CardDescription className="mt-0 text-sm font-normal leading-[1.55] text-muted-foreground">
                  Live clock in / out activity · Auto-refresh 15s
                </CardDescription>
              </div>
              <div className="flex flex-wrap items-center gap-2">
                <div className="hidden items-center gap-2 text-xs md:inline-flex">
                  <span className="relative inline-flex h-2.5 w-2.5 items-center justify-center">
                    <span className="absolute inline-flex h-full w-full animate-ping rounded-full bg-emerald-400/70" />
                    <span className="relative inline-flex h-2 w-2 rounded-full bg-emerald-500" />
                  </span>
                  <span className="font-extrabold tracking-wide text-emerald-700 dark:text-emerald-400">Live</span>
                  <span className="text-[11px] font-medium text-muted-foreground">· {updatedAgoLabel}</span>
                </div>
                <Button
                  variant="outline"
                  size="icon"
                  onClick={() => fetchDashboard(true)}
                  className="shrink-0 size-8 rounded-full"
                  title="Refresh now"
                >
                  <RefreshCw className="size-3.5" />
                </Button>
              </div>
            </div>
            {/* Quick filter + action chips row */}
            <div className="mt-5 flex flex-wrap items-center gap-2">
              <span className="text-[11px] font-normal uppercase tracking-wide text-muted-foreground">Filter</span>
              <button
                type="button"
                onClick={() => { setShowOnlyLate(false); setLogsPage(1) }}
                className={[
                  'inline-flex h-8 items-center gap-1.5 rounded-full border px-3.5 text-xs transition-all',
                  !showOnlyLate
                    ? 'border-2 border-primary/70 bg-primary font-bold text-primary-foreground shadow-md'
                    : 'border-border/60 bg-transparent font-normal text-muted-foreground hover:border-border hover:text-foreground',
                ].join(' ')}
              >All {!showOnlyLate && `(${todayLogs.length})`}</button>
              <button
                type="button"
                onClick={() => { setShowOnlyLate(true); setLogsPage(1) }}
                className={[
                  'inline-flex h-8 items-center gap-1.5 rounded-full border px-3.5 text-xs transition-all',
                  showOnlyLate
                    ? 'border-2 border-rose-500/60 bg-rose-500/20 font-bold text-rose-800 shadow-md dark:text-rose-200'
                    : 'border-border/60 bg-transparent font-normal text-muted-foreground hover:border-rose-500/40 hover:text-rose-600 dark:hover:text-rose-400',
                ].join(' ')}
              >
                <span className="inline-flex size-1.5 rounded-full bg-rose-500" />
                Late only {lateTodayCount > 0 && `(${lateTodayCount})`}
              </button>
              <div className="ml-auto flex items-center gap-1.5">
                <Button
                  type="button"
                  variant="ghost"
                  size="sm"
                  className="h-7 gap-1.5 px-2.5 text-[11px]"
                  title={compact ? 'Comfortable view' : 'Compact view'}
                  onClick={() => setCompact((v) => !v)}
                >
                  <LayoutList className="size-3.5" />
                  {compact ? 'Comfortable' : 'Compact'}
                </Button>
                <Button
                  type="button"
                  variant="ghost"
                  size="sm"
                  className="h-7 gap-1.5 px-2.5 text-[11px]"
                  onClick={() => exportTableCsv(filteredSortedLogs, formatTime)}
                  title="Export to CSV"
                >
                  <Download className="size-3.5" />
                  Export
                </Button>
              </div>
            </div>
          </CardHeader>
          <CardContent className="p-0">
            {/* Mobile: card list (no horizontal scrolling) */}
            <div className="md:hidden">
              {paginatedLogs.length === 0 ? (
                <div className="px-5 py-10 text-center text-base font-normal leading-relaxed text-muted-foreground">
                  No attendance logs today yet.
                </div>
              ) : (
                <ul className="divide-y divide-border/40">
                  {paginatedLogs.map((log, rowIndex) => {
                    const initials = (log.employee_name || '?')
                      .trim()
                      .split(/\s+/)
                      .map((n) => n[0])
                      .join('')
                      .toUpperCase()
                      .slice(0, 2) || '?'

                    const statusPill = log.time_in && !log.time_out
                      ? { icon: LogIn, label: 'Clock In', cls: 'text-emerald-700 dark:text-emerald-300 border-emerald-500/30 bg-emerald-500/10' }
                      : log.time_in && log.time_out
                        ? { icon: LogOut, label: 'Completed', cls: 'text-amber-700 dark:text-amber-300 border-amber-500/30 bg-amber-500/10' }
                        : { icon: Clock, label: 'No activity', cls: 'text-muted-foreground border-border/60 bg-muted/30' }
                    const StatusIcon = statusPill.icon

                    return (
                      <li key={log.id ?? `${log.employee_name}-${rowIndex}`} className="px-4 py-4">
                        <div className="flex items-start justify-between gap-3">
                          <div className="flex min-w-0 items-center gap-3">
                            <Avatar className="size-10 shrink-0 rounded-full border border-white/10 shadow-sm ring-2 ring-primary/10">
                              <AvatarImage src={profileImageUrl(log.profile_image)} alt="" className="object-cover" />
                              <AvatarFallback className="rounded-full bg-primary/10 text-xs font-semibold text-primary">
                                {initials}
                              </AvatarFallback>
                            </Avatar>
                            <div className="min-w-0">
                              <p className="truncate text-sm font-semibold text-foreground">
                                {log.employee_name}
                              </p>
                              <p className="truncate text-xs text-muted-foreground">
                                {log.company_name ?? '—'}
                              </p>
                            </div>
                          </div>
                          <span className={`inline-flex shrink-0 items-center gap-1.5 rounded-full border px-2.5 py-1 text-[11px] font-semibold ${statusPill.cls}`}>
                            <StatusIcon className="size-3.5" aria-hidden />
                            {statusPill.label}
                          </span>
                        </div>

                        <div className="mt-3 grid grid-cols-2 gap-2 text-xs">
                          <div className="rounded-lg border border-border/50 bg-muted/20 p-2">
                            <p className="text-[10px] font-semibold uppercase tracking-[0.12em] text-muted-foreground">
                              Time in
                            </p>
                            <p className="mt-1 font-mono text-[12px] tabular-nums text-foreground">
                              {log.time_in ? formatTime(log.time_in) : '—'}
                            </p>
                          </div>
                          <div className="rounded-lg border border-border/50 bg-muted/20 p-2">
                            <p className="text-[10px] font-semibold uppercase tracking-[0.12em] text-muted-foreground">
                              Time out
                            </p>
                            <p className="mt-1 font-mono text-[12px] tabular-nums text-foreground">
                              {log.time_out ? formatTime(log.time_out) : '—'}
                            </p>
                          </div>
                        </div>

                        <div className="mt-2">
                          {log.time_in ? (
                            log.is_half_day ? (
                              <span className="inline-flex items-center rounded-full border border-sky-400/60 bg-linear-to-r from-sky-500/20 via-sky-500/10 to-sky-500/0 px-2.5 py-1 text-xs font-medium text-sky-800 shadow-sm dark:border-sky-500/70 dark:bg-sky-500/20 dark:text-sky-100">
                                Half Day
                              </span>
                            ) : log.is_late ? (
                              <span className="inline-flex items-center rounded-full border border-red-400/60 bg-linear-to-r from-red-500/20 via-red-500/10 to-red-500/0 px-2.5 py-1 text-xs font-medium text-red-800 shadow-sm dark:border-red-500/70 dark:bg-red-500/20 dark:text-red-100">
                                {log.late_label || 'Late'}
                              </span>
                            ) : (
                              <span className="inline-flex items-center rounded-full border border-emerald-400/60 bg-linear-to-r from-emerald-500/20 via-emerald-500/10 to-emerald-500/0 px-2.5 py-1 text-xs font-medium text-emerald-800 shadow-sm dark:border-emerald-500/70 dark:bg-emerald-500/20 dark:text-emerald-100">
                                Present
                              </span>
                            )
                          ) : (
                            <span className="text-xs text-muted-foreground">—</span>
                          )}
                        </div>
                      </li>
                    )
                  })}
                </ul>
              )}
            </div>

            {/* Desktop: table */}
            <div className="hidden overflow-x-auto md:block">
              <table className="w-full border-separate border-spacing-0 text-sm">
                <thead className="sticky top-0 z-20 bg-card/95 backdrop-blur-sm shadow-sm">
                  <tr className="border-b border-border/50">
                    <th className="sticky left-0 z-30 bg-card/95 backdrop-blur-sm px-5 py-3 text-left text-sm font-semibold uppercase tracking-[0.06em] text-foreground/80">
                      <button type="button" onClick={() => handleSort('employee_name')} className="inline-flex items-center gap-1 hover:text-foreground transition-colors">
                        Employee
                        {sortConfig.key === 'employee_name' ? (sortConfig.dir === 'asc' ? <ArrowUp className="size-3" /> : <ArrowDown className="size-3" />) : <ArrowUpDown className="size-3 opacity-40" />}
                      </button>
                    </th>
                    <th className="px-5 py-3 text-left text-sm font-semibold uppercase tracking-[0.06em] text-foreground/80">
                      Company
                    </th>
                    <th className="px-5 py-3 text-left text-sm font-semibold uppercase tracking-[0.06em] text-foreground/80">
                      <button type="button" onClick={() => handleSort('time_in')} className="inline-flex items-center gap-1 hover:text-foreground transition-colors">
                        Time In
                        {sortConfig.key === 'time_in' ? (sortConfig.dir === 'asc' ? <ArrowUp className="size-3" /> : <ArrowDown className="size-3" />) : <ArrowUpDown className="size-3 opacity-40" />}
                      </button>
                    </th>
                    <th className="px-5 py-3 text-left text-sm font-semibold uppercase tracking-[0.06em] text-foreground/80">
                      <button type="button" onClick={() => handleSort('is_late')} className="inline-flex items-center gap-1 hover:text-foreground transition-colors">
                        Late
                        {sortConfig.key === 'is_late' ? (sortConfig.dir === 'asc' ? <ArrowUp className="size-3" /> : <ArrowDown className="size-3" />) : <ArrowUpDown className="size-3 opacity-40" />}
                      </button>
                    </th>
                    <th className="px-5 py-3 text-left text-sm font-semibold uppercase tracking-[0.06em] text-foreground/80">
                      Status
                    </th>
                    <th className="px-5 py-3 text-left text-sm font-semibold uppercase tracking-[0.06em] text-foreground/80">
                      <button type="button" onClick={() => handleSort('time_out')} className="inline-flex items-center gap-1 hover:text-foreground transition-colors">
                        Time Out
                        {sortConfig.key === 'time_out' ? (sortConfig.dir === 'asc' ? <ArrowUp className="size-3" /> : <ArrowDown className="size-3" />) : <ArrowUpDown className="size-3 opacity-40" />}
                      </button>
                    </th>
                  </tr>
                </thead>
                <tbody>
                  {paginatedLogs.length === 0 ? (
                    <tr>
                      <td colSpan={6} className="px-5 py-10 text-center text-base font-normal leading-relaxed text-muted-foreground">
                        No attendance logs today yet.
                      </td>
                    </tr>
                  ) : (
                    paginatedLogs.map((log, rowIndex) => {
                      const initials = (log.employee_name || '?')
                        .trim()
                        .split(/\s+/)
                        .map((n) => n[0])
                        .join('')
                        .toUpperCase()
                        .slice(0, 2) || '?'
                      const isEven = rowIndex % 2 === 1
                      const isExpanded = expandedRowId === log.id
                      const rowBase = log.is_late
                        ? 'bg-amber-50/60 dark:bg-amber-950/50 hover:bg-amber-100/70 dark:hover:bg-amber-950/70'
                        : log.time_in
                          ? isEven
                            ? 'bg-emerald-50/30 dark:bg-emerald-950/30 hover:bg-emerald-100/40 dark:hover:bg-emerald-950/50'
                            : 'bg-background dark:bg-transparent hover:bg-emerald-50/25 dark:hover:bg-emerald-950/25'
                          : isEven
                            ? 'bg-muted/20 dark:bg-white/2 hover:bg-muted/50 dark:hover:bg-white/5'
                            : 'bg-background dark:bg-transparent hover:bg-muted/40 dark:hover:bg-white/4'
                      return [
                        (
                          <tr
                            key={log.id}
                            className={`group live-row-anim cursor-pointer border-b border-border/25 transition-all duration-150 ${rowBase} hover:-translate-y-0.5 hover:shadow-md hover:shadow-black/10 dark:hover:shadow-black/30 ${
                              isExpanded ? 'ring-1 ring-primary/40' : ''
                            }`}
                            style={{ animationDelay: `${rowIndex * 40}ms` }}
                            onClick={() => setExpandedRowId((current) => (current === log.id ? null : log.id))}
                          >
                            <td className={`sticky left-0 z-10 bg-inherit ${compact ? 'px-4 py-2' : 'px-5 py-3'}`}>
                              <div className="flex items-center gap-2.5">
                                {!compact && (
                                  <Avatar className="size-8 shrink-0 rounded-full border border-white/10 shadow-sm ring-2 ring-primary/10 transition-all duration-150 group-hover:ring-primary/40 group-hover:shadow-md">
                                    <AvatarImage src={profileImageUrl(log.profile_image)} alt="" className="object-cover" />
                                    <AvatarFallback className="rounded-full bg-primary/10 text-xs font-semibold text-primary">
                                      {initials}
                                    </AvatarFallback>
                                  </Avatar>
                                )}
                                <div className="flex flex-col">
                                  <button
                                    type="button"
                                    className="text-left text-sm font-medium text-foreground transition-colors hover:text-primary hover:underline"
                                    onClick={(e) => {
                                      e.stopPropagation()
                                      navigate(
                                        `${hrPanelPath(hrBase, 'employees')}?q=${encodeURIComponent(log.employee_name || '')}`
                                      )
                                    }}
                                  >
                                    {log.employee_name}
                                  </button>
                                  {!compact && (
                                    <span className="text-[11px] text-muted-foreground">
                                      {log.company_name ?? '—'}
                                    </span>
                                  )}
                                </div>
                              </div>
                            </td>
                            <td className={`${compact ? 'px-4 py-2' : 'px-5 py-3'} align-middle`}>
                              <div className="flex items-center gap-2">
                                {log.company_logo_url ? (
                                  <img
                                    src={log.company_logo_url}
                                    alt=""
                                    className="size-8 shrink-0 rounded object-contain border border-border/50 bg-background"
                                  />
                                ) : (
                                  <div className="flex size-8 shrink-0 items-center justify-center rounded border border-border/50 bg-muted/50 text-muted-foreground">
                                    <Building2 className="size-4" />
                                  </div>
                                )}
                                <span className="text-xs text-foreground truncate max-w-[140px]" title={log.company_name ?? '—'}>
                                  {log.company_name ?? '—'}
                                </span>
                              </div>
                            </td>
                            <td className={`${compact ? 'px-4 py-2' : 'px-5 py-3'} align-middle font-mono text-xs text-muted-foreground tabular-nums`}>
                              {log.time_in ? formatTime(log.time_in) : '—'}
                            </td>
                            <td className={`${compact ? 'px-4 py-2' : 'px-5 py-3'} align-middle`}>
                              {log.time_in ? (
                                log.is_half_day ? (
                                  <span className="inline-flex items-center rounded-full border border-sky-400/60 bg-linear-to-r from-sky-500/20 via-sky-500/10 to-sky-500/0 px-2.5 py-0.5 text-[11px] font-medium text-sky-800 shadow-sm dark:border-sky-500/70 dark:bg-sky-500/20 dark:text-sky-100">
                                    Half Day
                                  </span>
                                ) : log.is_late ? (
                                  <span className="inline-flex items-center rounded-full border border-red-400/60 bg-linear-to-r from-red-500/20 via-red-500/10 to-red-500/0 px-2.5 py-0.5 text-[11px] font-medium text-red-800 shadow-sm dark:border-red-500/70 dark:bg-red-500/20 dark:text-red-100">
                                    {log.late_label || 'Late'}
                                  </span>
                                ) : (
                                  <span className="inline-flex items-center rounded-full border border-emerald-400/60 bg-linear-to-r from-emerald-500/20 via-emerald-500/10 to-emerald-500/0 px-2.5 py-0.5 text-[11px] font-medium text-emerald-800 shadow-sm dark:border-emerald-500/70 dark:bg-emerald-500/20 dark:text-emerald-100">
                                    Present
                                  </span>
                                )
                              ) : (
                                <span className="text-xs text-muted-foreground">—</span>
                              )}
                            </td>
                            <td className={`${compact ? 'px-4 py-2' : 'px-5 py-3'} align-middle`}>
                              <span className="inline-flex items-center gap-1.5 rounded-full border border-border/60 bg-muted/40 dark:bg-white/6 px-2.5 py-1 text-[11px] font-medium shadow-sm">
                                {log.time_in && !log.time_out ? (
                                  <>
                                    <LogIn className="size-3.5 text-emerald-600 dark:text-emerald-400" />
                                    <span className="text-emerald-700 dark:text-emerald-300">Clocked In</span>
                                  </>
                                ) : log.time_in && log.time_out ? (
                                  <>
                                    <LogOut className="size-3.5 text-sky-600 dark:text-sky-400" />
                                    <span className="text-sky-700 dark:text-sky-300">Completed</span>
                                  </>
                                ) : (
                                  <>
                                    <Clock className="size-3.5 text-muted-foreground" />
                                    <span className="text-muted-foreground">No activity</span>
                                  </>
                                )}
                              </span>
                            </td>
                            <td className={`${compact ? 'px-4 py-2' : 'px-5 py-3'} align-middle font-mono text-xs text-muted-foreground tabular-nums`}>
                              {log.time_out ? formatTime(log.time_out) : '—'}
                            </td>
                          </tr>
                        ),
                        isExpanded && (
                          <tr
                            key={`${log.id}-expanded`}
                            className={`${isEven ? 'bg-muted/30' : 'bg-muted/20'} border-b border-border/30`}
                          >
                            <td colSpan={6} className="px-5 pb-4 pt-0 align-top">
                              <div className="flex flex-wrap items-center justify-between gap-3 border-l-2 border-primary/50 pl-3 pt-2 text-xs text-muted-foreground">
                                <div className="space-y-0.5">
                                  <p className="font-semibold text-foreground">
                                    {log.employee_name}{' '}
                                    {log.company_name && (
                                      <span className="text-xs text-muted-foreground">· {log.company_name}</span>
                                    )}
                                  </p>
                                  <p>
                                    First clock-in:{' '}
                                    <span className="font-mono text-[11px] text-foreground">
                                      {log.time_in ? formatTime(log.time_in) : '—'}
                                    </span>
                                  </p>
                                  <p>
                                    Last clock-out:{' '}
                                    <span className="font-mono text-[11px] text-foreground">
                                      {log.time_out ? formatTime(log.time_out) : '—'}
                                    </span>
                                  </p>
                                  {log.late_label && (
                                    <p>
                                      Late status:{' '}
                                      <span className="font-medium text-rose-600 dark:text-rose-300">
                                        {log.late_label}
                                      </span>
                                    </p>
                                  )}
                                </div>
                                <div className="flex flex-wrap items-center gap-2">
                                  <Button
                                    variant="outline"
                                    size="sm"
                                    className="text-xs"
                                    onClick={(e) => {
                                      e.stopPropagation()
                                      navigate(
                                        `${hrPanelPath(hrBase, 'employees')}?q=${encodeURIComponent(log.employee_name || '')}`
                                      )
                                    }}
                                  >
                                    View profile
                                  </Button>
                                </div>
                              </div>
                            </td>
                          </tr>
                        ),
                      ]
                    })
                  )}
                </tbody>
              </table>
            </div>
            {totalLogs > 0 && (
              <div className="flex flex-wrap items-center justify-between gap-3 border-t border-border/40 bg-muted/20 px-5 py-3">
                <p className="text-xs text-muted-foreground @sm:text-sm">
                  Showing{' '}
                  <span className="font-medium text-foreground">
                    {logsStart}–{logsEnd}
                  </span>{' '}
                  of{' '}
                  <span className="font-medium text-foreground">
                    {totalLogs}
                  </span>
                </p>
                <div className="flex items-center gap-1">
                  <Button
                    variant="outline"
                    size="sm"
                    onClick={() => setLogsPage((p) => Math.max(1, p - 1))}
                    disabled={effectiveLogsPage <= 1}
                  >
                    <ChevronLeft className="size-4" />
                    <span className="hidden sm:inline">Previous</span>
                  </Button>
                  <span className="mx-1 text-xs font-medium text-muted-foreground sm:hidden">
                    Page {effectiveLogsPage} / {totalLogsPages}
                  </span>
                  <div className="hidden items-center gap-1 sm:flex">
                    {Array.from({ length: totalLogsPages }, (_, i) => i + 1).map((p) => (
                      <Button
                        key={p}
                        variant={p === effectiveLogsPage ? 'default' : 'outline'}
                        size="sm"
                        className="min-w-9"
                        onClick={() => setLogsPage(p)}
                      >
                        {p}
                      </Button>
                    ))}
                  </div>
                  <Button
                    variant="outline"
                    size="sm"
                    onClick={() => setLogsPage((p) => Math.min(totalLogsPages, p + 1))}
                    disabled={effectiveLogsPage >= totalLogsPages}
                  >
                    <span className="hidden sm:inline">Next</span>
                    <ChevronRight className="size-4" />
                  </Button>
                </div>
              </div>
            )}
          </CardContent>
        </Card>
      </Motion.div>
      <style>{`
        /* ── Recharts tooltip: transparent wrapper, themed inner ── */
        .recharts-tooltip-wrapper,
        .recharts-tooltip-wrapper *,
        [class*="recharts-tooltip"] {
          background: transparent !important;
          background-color: transparent !important;
        }
        .recharts-tooltip-wrapper [data-chart-tooltip],
        .recharts-default-tooltip,
        .recharts-tooltip-wrapper > div {
          background: var(--card) !important;
          background-color: var(--card) !important;
          border: 1px solid var(--border) !important;
          border-radius: 0.625rem;
          padding: 0.5rem 0.75rem;
          box-shadow: 0 20px 40px -8px rgba(0,0,0,0.18), 0 4px 8px -2px rgba(0,0,0,0.08);
          color: var(--foreground) !important;
          backdrop-filter: blur(8px);
        }
        .recharts-tooltip-wrapper .recharts-default-tooltip {
          background: var(--card) !important;
          background-color: var(--card) !important;
        }
        /* Prevent cursor rect black fill */
        .recharts-tooltip-cursor,
        .recharts-active-dot,
        rect.recharts-tooltip-cursor {
          fill: rgba(148, 163, 184, 0.06) !important;
          stroke: var(--border) !important;
        }
        /* Dark mode: richer tooltip shadow + deeper bg */
        .dark .recharts-tooltip-wrapper [data-chart-tooltip],
        .dark .recharts-default-tooltip,
        .dark .recharts-tooltip-wrapper > div {
          background: #263147 !important;
          background-color: #263147 !important;
          border-color: rgba(148, 163, 184, 0.20) !important;
          box-shadow: 0 24px 48px -8px rgba(0,0,0,0.5), 0 0 0 1px rgba(148,163,184,0.12) !important;
        }
        /* Chart axis labels: slightly brighter in dark mode */
        .dark .recharts-cartesian-axis-tick-value {
          fill: #94a3b8 !important;
        }
        /* Live table row entrance */
        .live-row-anim {
          animation: liveRowIn 0.35s ease-out both;
        }
        @keyframes liveRowIn {
          from { opacity: 0; transform: translateY(4px); }
          to   { opacity: 1; transform: translateY(0); }
        }
        /* Subtle pulse on late rows */
        .late-row-highlight {
          animation: lateHighlight 2s ease-in-out 1;
        }
        @keyframes lateHighlight {
          0%   { background-color: rgba(245, 158, 11, 0.25); }
          100% { background-color: transparent; }
        }
      `}</style>

      {/* Half-Day Report Modal */}
      <Dialog open={halfDayModalOpen} onOpenChange={setHalfDayModalOpen}>
        <DialogContent className={cn(DIALOG_CONTENT_CLASS, 'max-w-2xl max-h-[85vh] flex flex-col gap-0 p-0 overflow-hidden')}>
          <DialogHeader className="relative px-5 pt-5 pb-3 pr-12 border-b border-border">
            <DialogTitle className="text-lg">Half-Day Leave Report</DialogTitle>
            <DialogDescription>
              Employees on half-day leave today (AM / PM breakdown)
            </DialogDescription>
            <Button
              type="button"
              variant="ghost"
              size="icon"
              className="absolute top-3 right-3 size-8 rounded-full hover:bg-muted"
              onClick={() => setHalfDayModalOpen(false)}
              aria-label="Close"
            >
              <X className="size-4" />
            </Button>
          </DialogHeader>
          <div className="flex-1 overflow-auto min-h-0 px-5 py-4">
            {halfDayListLoading ? (
              <div className="flex items-center justify-center py-12 text-sm text-muted-foreground">
                Loading…
              </div>
            ) : halfDayList?.employees?.length === 0 ? (
              <div className="flex flex-col items-center justify-center py-12 text-center">
                <CalendarOff className="size-12 text-muted-foreground/50 mb-3" />
                <p className="text-sm font-medium text-foreground">No half-day leaves today</p>
                <p className="text-xs text-muted-foreground mt-1">
                  {halfDayList?.date ? `As of ${halfDayList.date}` : ''}
                </p>
              </div>
            ) : halfDayList?.employees?.length > 0 ? (
              <div className="overflow-x-auto">
                <table className="w-full text-sm border-collapse">
                  <thead>
                    <tr className="border-b border-border">
                      <th className="text-left py-2 px-2 font-semibold text-muted-foreground">Employee</th>
                      <th className="text-left py-2 px-2 font-semibold text-muted-foreground">Branch</th>
                      <th className="text-left py-2 px-2 font-semibold text-muted-foreground">Time In</th>
                      <th className="text-left py-2 px-2 font-semibold text-muted-foreground">Half-Day Type</th>
                      <th className="text-left py-2 px-2 font-semibold text-muted-foreground">Reason</th>
                    </tr>
                  </thead>
                  <tbody>
                    {halfDayList.employees.map((emp) => (
                      <tr key={emp.user_id} className="border-b border-border/60 hover:bg-muted/30">
                        <td className="py-2.5 px-2 font-medium">{emp.employee_name}</td>
                        <td className="py-2.5 px-2 text-muted-foreground">{emp.branch ?? '—'}</td>
                        <td className="py-2.5 px-2 tabular-nums text-muted-foreground">
                          {emp.time_in ? formatTime(`2000-01-01T${emp.time_in}`) : '—'}
                        </td>
                        <td className="py-2.5 px-2">
                          <span className={`inline-flex items-center gap-1 rounded-full px-2 py-0.5 text-xs font-medium ${
                            emp.half_type === 'am' ? 'bg-amber-500/15 text-amber-700 dark:text-amber-200' : 'bg-indigo-500/15 text-indigo-700 dark:text-indigo-200'
                          }`}>
                            {emp.half_type === 'am' ? <Sun className="size-3" /> : <Moon className="size-3" />}
                            {emp.half_type === 'am' ? 'AM' : 'PM'}
                          </span>
                        </td>
                        <td className="py-2.5 px-2 text-muted-foreground max-w-[180px] truncate" title={emp.notes ?? undefined}>
                          {emp.notes ?? '—'}
                        </td>
                      </tr>
                    ))}
                  </tbody>
                </table>
                <p className="text-xs text-muted-foreground mt-3">
                  {halfDayList.am_count ?? 0} AM · {halfDayList.pm_count ?? 0} PM · {halfDayList.total ?? 0} total
                </p>
              </div>
            ) : (
              <div className="flex items-center justify-center py-12 text-sm text-muted-foreground">
                Unable to load data
              </div>
            )}
          </div>
        </DialogContent>
      </Dialog>
    </Motion.div>
  )
}
