import { useState, useEffect, useCallback, useRef, useMemo } from 'react'
import { useNavigate } from 'react-router-dom'
import { useQuery, useQueryClient } from '@tanstack/react-query'
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
  ArrowRight,
  Filter,
  Send,
  Sun,
  Moon,
  Monitor,
  Download,
  LayoutList,
  Building2,
  X,
  Cake,
  Calendar,
  Search,
  Flag,
} from 'lucide-react'
import { Card, CardContent, CardDescription, CardHeader, CardTitle } from '@/components/ui/card'
import { Button } from '@/components/ui/button'
import { Avatar, AvatarFallback, AvatarImage } from '@/components/ui/avatar'
import { Tabs, TabsContent, TabsList, TabsTrigger } from '@/components/ui/tabs'
import { Select, SelectContent, SelectItem, SelectTrigger, SelectValue } from '@/components/ui/select'
import {
  Dialog,
  DialogContent,
  DialogDescription,
  DialogHeader,
  DialogTitle,
} from '@/components/ui/dialog'
import { DashboardSkeleton } from '@/components/skeletons'
import { BarChart, Bar, XAxis, YAxis, CartesianGrid, Tooltip, ResponsiveContainer, AreaChart, Area, Cell } from 'recharts'
import {
  getDashboardData,
  getAdminDashboardBirthdays,
  getDashboardCompanyAttendance,
  getCompanies,
  getHalfDayList,
  getAdminOvertime,
  getAdminPresenceFilings,
  profileImageUrl,
  userProfileImageSrc,
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
import { EMPTY_PLACEHOLDER, formatEmpty } from '@/lib/formatEmpty'
import { OvertimeRequestsCard } from '@/components/dashboard/OvertimeRequestsCard'
import { AttendanceCorrectionsCard } from '@/components/dashboard/AttendanceCorrectionsCard'
import { HR_PENDING_APPROVALS_CHANGED } from '@/lib/hrPendingApprovalsEvents'
import {
  buildHolidayMonthOptions,
  buildHolidayYearOptions,
  filterUpcomingHolidaysByMonth,
  formatHolidayDateLine,
  formatHolidayMonthLabel,
  formatHolidayScopeLine,
  getDefaultHolidayMonthKey,
  holidayMonthKey,
  holidayScopeBadgeClass,
  holidayTypeBadgeClass,
  holidayTypeLabel,
  parseHolidayMonthKey,
  shiftHolidayMonth,
  UPCOMING_HOLIDAYS_DISPLAY_LIMIT,
} from '@/lib/holidayDisplay'
import {
  formatHolidayMultiplierLabel,
  holidayMultiplierBadgeClass,
} from '@/lib/holidayMultiplierDisplay'
import { upcomingHolidayUniqueKey } from '@/lib/holidayUniqueKey'

const CARD_ICONS = {
  total: Users,
  present: Clock,
  late: AlertCircle,
  absent: UserX,
  on_leave: CalendarOff,
}

const CHART = {
  weeklyBar: 'var(--brand)',
  lateLine: 'hsl(351 95% 58%)',
  lateArea: 'hsl(351 95% 58% / 0.2)',
  deptBars: ['#ff6b00', '#ff4d5a', '#f6c453', '#35b768', '#5c9ded'],
}

const CARD_META = {
  total_employees: {
    accent: 'text-orange-500',
    iconBg: 'bg-orange-500/12 ring-orange-500/15',
    sentiment: 'neutral',
    hoverShadow: 'hover:shadow-orange-500/15',
  },
  present_today: {
    accent: 'text-emerald-500',
    iconBg: 'bg-emerald-500/12 ring-emerald-500/15',
    sentiment: 'up_good',
    hoverShadow: 'hover:shadow-emerald-500/20',
  },
  late_today: {
    accent: 'text-amber-500',
    iconBg: 'bg-amber-500/14 ring-amber-500/18',
    sentiment: 'down_good',
    hoverShadow: 'hover:shadow-amber-500/20',
  },
  absent_today: {
    accent: 'text-rose-500',
    iconBg: 'bg-rose-500/12 ring-rose-500/15',
    sentiment: 'down_good',
    hoverShadow: 'hover:shadow-red-500/20',
  },
  on_leave: {
    accent: 'text-blue-500',
    iconBg: 'bg-blue-500/12 ring-blue-500/15',
    sentiment: 'neutral',
    hoverShadow: 'hover:shadow-blue-500/15',
  },
  default: {
    accent: 'text-brand',
    iconBg: 'bg-brand/12 ring-brand/15',
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
    l.is_absent ? 'Absent' : l.is_late ? (l.late_label || 'Late') : l.time_in ? 'On time' : '',
    l.is_absent ? 'Absent' : l.time_in && !l.time_out ? 'Clocked in' : l.time_in && l.time_out ? 'Completed' : 'No activity',
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
  if (!iso) return EMPTY_PLACEHOLDER
  try {
    const d = new Date(iso)
    return d.toLocaleTimeString(undefined, { hour: '2-digit', minute: '2-digit', second: '2-digit' })
  } catch {
    return iso
  }
}

function formatDate(value) {
  if (!value) return EMPTY_PLACEHOLDER
  try {
    const d = new Date(value)
    return d.toLocaleDateString(undefined, { year: 'numeric', month: 'short', day: 'numeric' })
  } catch {
    return value
  }
}

function formatDaysLabel(label) {
  if (!label) return EMPTY_PLACEHOLDER
  const s = String(label)
  return s.replace(/(\d+)(?:\.\d+)?\s+days\b/g, (_, n) => `${Number(n)} days`)
}

function formatEmploymentTypeLabel(raw) {
  if (!raw) return EMPTY_PLACEHOLDER
  const words = String(raw)
    .replace(/_/g, ' ')
    .trim()
    .split(/\s+/)
    .filter(Boolean)
  return words.map((w) => w.slice(0, 1).toUpperCase() + w.slice(1)).join(' ')
}

function manilaCalendarParts(date = new Date()) {
  const parts = new Intl.DateTimeFormat('en-US', {
    timeZone: 'Asia/Manila',
    year: 'numeric',
    month: 'numeric',
  }).formatToParts(date)
  return {
    year: Number(parts.find((p) => p.type === 'year')?.value ?? date.getFullYear()),
    month: Number(parts.find((p) => p.type === 'month')?.value ?? date.getMonth() + 1),
  }
}

function shiftCalendarMonth({ year, month }, delta) {
  const d = new Date(year, month - 1 + delta, 1)
  return { year: d.getFullYear(), month: d.getMonth() + 1 }
}

function compareCalendarMonth(a, b) {
  if (a.year !== b.year) return a.year - b.year
  return a.month - b.month
}

function isCalendarMonthBefore(a, b) {
  return compareCalendarMonth(a, b) < 0
}

function isCalendarMonthAfter(a, b) {
  return compareCalendarMonth(a, b) > 0
}

function calendarMonthKey({ year, month }) {
  return `${year}-${String(month).padStart(2, '0')}`
}

function parseCalendarMonthKey(key) {
  const [year, month] = String(key || '').split('-')
  return { year: Number(year), month: Number(month) }
}

function formatCalendarMonthOptionLabel({ year, month }) {
  const date = new Date(year, month - 1, 1)
  return date.toLocaleDateString('en-US', { month: 'long', year: 'numeric' })
}

const BIRTHDAY_CALENDAR_MONTHS_BACK = 24
const BIRTHDAY_CALENDAR_MONTHS_AHEAD = 12

function buildBirthdayMonthSelectOptions(earliest, latest, current) {
  const options = []
  let cursor = { ...earliest }
  while (compareCalendarMonth(cursor, latest) <= 0) {
    const isCurrent = cursor.year === current.year && cursor.month === current.month
    const isFuture = compareCalendarMonth(cursor, current) > 0
    options.push({
      value: calendarMonthKey(cursor),
      label: formatCalendarMonthOptionLabel(cursor),
      isCurrent,
      isFuture,
    })
    if (compareCalendarMonth(cursor, latest) === 0) break
    cursor = shiftCalendarMonth(cursor, 1)
  }
  return options.reverse()
}

const BIRTHDAY_CAKE = '\u{1F382}'

function birthdayBadgeLabel(days, monthView = false, passedInView = false) {
  if (passedInView) return 'Celebrated'
  const n = Number(days)
  if (!Number.isFinite(n) || n <= 0) return `${BIRTHDAY_CAKE} Today`
  if (monthView && n > 31) return 'Birthday passed'
  if (n === 1) return `${BIRTHDAY_CAKE} Tomorrow`
  if (n === 7) return 'In 1 week'
  if (n > 7 && n % 7 === 0) return `In ${n / 7} weeks`
  return `In ${n} days`
}

function birthdayAgeCountdownParts(person, { monthView = false, passedInView = false } = {}) {
  const nextAge = Number(person?.next_age)
  const days = Number(person?.days_until_birthday ?? 0)
  const status = String(person?.birthday_status || '')
  const hasAge = Number.isFinite(nextAge) && nextAge > 0

  if (passedInView || status === 'passed') {
    return hasAge ? { showCake: true, text: `Turned ${nextAge}` } : null
  }
  if (!hasAge) return null
  if (status === 'today' || person?.is_today || days === 0) {
    return { showCake: true, text: `${BIRTHDAY_CAKE} Turns ${nextAge} Today` }
  }
  if (status === 'tomorrow' || person?.is_tomorrow || days === 1) {
    return { showCake: true, text: `${BIRTHDAY_CAKE} Turns ${nextAge} Tomorrow` }
  }
  if (days > 1 && (!monthView || days <= 366)) {
    return { showCake: true, text: `${BIRTHDAY_CAKE} Turns ${nextAge} in ${days} days` }
  }
  if (monthView && days > 31) {
    return hasAge ? { showCake: true, text: `${BIRTHDAY_CAKE} Turning ${nextAge}` } : null
  }
  return { showCake: true, text: `${BIRTHDAY_CAKE} Turning ${nextAge}` }
}

function birthdayMonthShortLabel(monthLabel) {
  const trimmed = String(monthLabel || '').trim()
  if (!trimmed) return 'Month'
  const first = trimmed.split(/\s+/)[0]
  return first || 'Month'
}

function resolveCompanyLogoSrc(logoUrl, company = null) {
  if (typeof logoUrl === 'string' && logoUrl.trim() !== '') return logoUrl.trim()
  return companyLogoUrl(company) || undefined
}

const BIRTHDAY_TAB_LIST_CLASS =
  'admin-birthday-tabs flex h-auto w-full gap-1.5 overflow-x-auto rounded-xl border border-border/35 bg-muted/45 p-1.5 shadow-inner [-ms-overflow-style:none] [scrollbar-width:none] [&::-webkit-scrollbar]:hidden @md:grid @md:grid-cols-3 @md:overflow-visible'

const BIRTHDAY_TAB_TRIGGER_CLASS = cn(
  'group relative flex h-auto min-h-[3.25rem] min-w-[5.75rem] flex-1 shrink-0 flex-col items-center justify-center gap-1 rounded-lg border-0 px-2 py-2 transition-all @md:min-h-12 @md:min-w-0 @md:flex-row @md:gap-2.5 @md:px-4',
  'shadow-none outline-none focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-brand/25',
  'after:hidden',
  'data-[state=active]:border-transparent data-[state=active]:bg-background data-[state=active]:text-brand data-[state=active]:shadow-sm',
  'dark:data-[state=active]:border-transparent dark:data-[state=active]:bg-card dark:data-[state=active]:shadow-md dark:data-[state=active]:shadow-black/20',
  'data-[state=inactive]:bg-transparent data-[state=inactive]:text-muted-foreground',
  'hover:data-[state=inactive]:text-foreground'
)

function BirthdayPersonRow({ person, tone = 'upcoming', monthView = false, futureMonthView = false, onOpen }) {
  const name = person?.full_name || 'Employee'
  const days = Number(person?.days_until_birthday ?? 0)
  const birthdayAlreadyPassed =
    Boolean(person?.birthday_passed_in_view) || (monthView && days > 31 && !futureMonthView)
  const ageCountdownParts = birthdayAgeCountdownParts(person, {
    monthView,
    passedInView: birthdayAlreadyPassed,
  })
  const occurrenceLabel = person?.next_birthday_formatted || person?.birth_date_formatted || '-'
  const badgeClass =
    person?.is_today || tone === 'today'
      ? 'border-brand/35 bg-brand/10 text-brand'
      : birthdayAlreadyPassed
        ? 'border-border/80 bg-muted/30 text-muted-foreground'
        : days <= 7
        ? 'border-brand/25 bg-brand/8 text-brand'
        : 'border-brand/20 bg-background/80 text-brand'

  return (
    <button
      type="button"
      onClick={onOpen}
      className="admin-birthday-person group flex min-h-30 w-full items-start gap-3 rounded-lg border border-border/70 bg-background/70 px-3.5 py-3 text-left shadow-sm transition-all duration-200 hover:-translate-y-0.5 hover:border-brand/35 hover:bg-card hover:shadow-md @md:min-h-32 @md:gap-4 @md:px-4"
    >
      <Avatar className="h-11 w-11 shrink-0 border border-brand/10 bg-brand/8 text-brand @md:h-12 @md:w-12">
        <AvatarImage src={userProfileImageSrc(person)} alt="" className="object-cover" />
        <AvatarFallback className="bg-brand/8 text-sm font-extrabold text-brand @md:text-base">
          {String(name).slice(0, 1).toUpperCase()}
        </AvatarFallback>
      </Avatar>
      <div className="flex min-w-0 flex-1 flex-col self-stretch">
        <div className="flex items-start justify-between gap-3">
          <div className="min-w-0">
            <p className="line-clamp-1 text-sm font-extrabold leading-snug text-foreground group-hover:text-brand @md:text-[15px]">
              {name}
            </p>
            <p className="mt-1 line-clamp-2 text-[11px] font-medium uppercase leading-relaxed tracking-wide text-muted-foreground @md:text-xs">
              {person?.department || 'Unassigned'} · {person?.position || 'Unassigned'}
            </p>
          </div>
          <span className={cn('shrink-0 rounded-md border px-2.5 py-1 text-[11px] font-bold @md:px-3 @md:text-xs', badgeClass)}>
            {birthdayBadgeLabel(days, monthView, birthdayAlreadyPassed)}
          </span>
        </div>
        <div className="mt-auto space-y-1.5 pt-4 text-[11px] font-medium text-muted-foreground @md:text-xs">
          <p className="text-foreground/90">
            {occurrenceLabel}
            {person?.day_name ? (
              <>
                {' '}
                <span className="text-muted-foreground">·</span>
                {' '}
                {person.day_name}
              </>
            ) : null}
          </p>
          {ageCountdownParts ? (
            <p className="font-semibold text-brand">{ageCountdownParts.text}</p>
          ) : null}
        </div>
      </div>
    </button>
  )
}

export default function AdminDashboard() {
  const { user, loading: authLoading } = useAuth()
  const perms = useMemo(() => new Set(user?.permissions ?? []), [user?.permissions])
  const canViewCompanyDirectory = useMemo(() => perms.has('org.company.view'), [perms])
  const canViewHolidays = useMemo(() => perms.has('holiday.view'), [perms])
  const canViewOvertime = useMemo(() => perms.has('overtime.view'), [perms])
  const canViewLeave = useMemo(() => perms.has('leave.view'), [perms])
  const canApproveAttendanceCorrections = useMemo(() => perms.has('attendance.corrections.approve'), [perms])
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
  const dashboardCompanyLogo = resolveCompanyLogoSrc(user?.company_logo_url)

  const [data, setData] = useState(null)
  const [error, setError] = useState(null)
  const [logsPage, setLogsPage] = useState(1)
  const [lastUpdatedAt, setLastUpdatedAt] = useState(null)
  const [expandedRowId, setExpandedRowId] = useState(null)
  const [sortConfig, setSortConfig] = useState({ key: null, dir: 'asc' })
  const [attendanceFilter, setAttendanceFilter] = useState('all')
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
  const [holidayMonth, setHolidayMonth] = useState(getDefaultHolidayMonthKey)
  const holidayYearOptions = useMemo(() => buildHolidayYearOptions(), [])
  const holidayMonthOptions = useMemo(() => buildHolidayMonthOptions(), [])
  const holidayBrowse = useMemo(() => parseHolidayMonthKey(holidayMonth) ?? parseHolidayMonthKey(getDefaultHolidayMonthKey()), [holidayMonth])
  const holidayYearMin = holidayYearOptions[0] ?? holidayBrowse.year
  const holidayYearMax = holidayYearOptions[holidayYearOptions.length - 1] ?? holidayBrowse.year
  const canHolidayMonthPrev =
    holidayBrowse.year > holidayYearMin ||
    (holidayBrowse.year === holidayYearMin && holidayBrowse.month > 1)
  const canHolidayMonthNext =
    holidayBrowse.year < holidayYearMax ||
    (holidayBrowse.year === holidayYearMax && holidayBrowse.month < 12)
  const [selectedCompanyIds, setSelectedCompanyIds] = useState([])
  const [companyAttendanceData, setCompanyAttendanceData] = useState(null)
  const [companiesList, setCompaniesList] = useState([])
  const [companyChartLoading, setCompanyChartLoading] = useState(false)
  const [halfDayModalOpen, setHalfDayModalOpen] = useState(false)
  const [halfDayList, setHalfDayList] = useState(null)
  const [halfDayListLoading, setHalfDayListLoading] = useState(false)
  const [regularizationActionById, setRegularizationActionById] = useState({})
  const [birthdayTab, setBirthdayTab] = useState('month')
  const [birthdaySearch, setBirthdaySearch] = useState('')
  const manilaToday = useMemo(() => manilaCalendarParts(), [])
  const [birthdayBrowseMonth, setBirthdayBrowseMonth] = useState(() => manilaCalendarParts())
  const navigate = useNavigate()
  const hrBase = useHrBasePath()

  const attendanceCorrectionsHref = useCallback((opts = {}) => {
    const q = new URLSearchParams({ tab: 'corrections' })
    if (opts.status) q.set('status', String(opts.status))
    const rid = opts.request_id ?? opts.requestId
    if (rid != null && rid !== '') q.set('request_id', String(rid))
    return `${hrPanelPath(hrBase, 'attendance')}?${q}`
  }, [hrBase])

  const dashboardQuery = useQuery({
    queryKey: ['admin-dashboard'],
    queryFn: getDashboardData,
    enabled: !authLoading,
    refetchInterval: 15000,
    refetchOnMount: 'always',
    refetchOnWindowFocus: true,
  })

  const isBrowsingCurrentBirthdayMonth =
    birthdayBrowseMonth.year === manilaToday.year && birthdayBrowseMonth.month === manilaToday.month

  const birthdayMonthQuery = useQuery({
    queryKey: ['admin-dashboard-birthdays', birthdayBrowseMonth.year, birthdayBrowseMonth.month],
    queryFn: () =>
      getAdminDashboardBirthdays({
        year: birthdayBrowseMonth.year,
        month: birthdayBrowseMonth.month,
      }),
    enabled: !authLoading && isHrAdmin && !isBrowsingCurrentBirthdayMonth,
    staleTime: 60_000,
  })

  const overtimePendingQuery = useQuery({
    queryKey: ['admin-dashboard-overtime-pending'],
    queryFn: () => getAdminOvertime({ status: 'pending', page: 1, per_page: 1 }),
    enabled: !authLoading && canViewOvertime,
    refetchInterval: 15000,
    refetchOnMount: 'always',
    refetchOnWindowFocus: true,
  })

  const attendanceCorrectionsPendingQuery = useQuery({
    queryKey: ['admin-dashboard-attendance-corrections-pending'],
    queryFn: () => getAdminPresenceFilings({ status: 'pending' }),
    enabled: !authLoading && canApproveAttendanceCorrections,
    refetchInterval: 15000,
    refetchOnMount: 'always',
    refetchOnWindowFocus: true,
  })

  const queryClient = useQueryClient()

  useEffect(() => {
    const onPendingApprovalsChanged = () => {
      void queryClient.invalidateQueries({ queryKey: ['admin-dashboard'] })
      void queryClient.invalidateQueries({ queryKey: ['admin-dashboard-overtime-pending'] })
      void queryClient.invalidateQueries({ queryKey: ['admin-dashboard-attendance-corrections-pending'] })
    }
    window.addEventListener(HR_PENDING_APPROVALS_CHANGED, onPendingApprovalsChanged)
    return () => window.removeEventListener(HR_PENDING_APPROVALS_CHANGED, onPendingApprovalsChanged)
  }, [queryClient])

  const loading = authLoading || dashboardQuery.isLoading
  const overtimePendingCount = Number(
    overtimePendingQuery.data?.summary?.pending_count
      ?? overtimePendingQuery.data?.summary?.pending
      ?? overtimePendingQuery.data?.summary?.pending_requests
      ?? overtimePendingQuery.data?.pending_count
      ?? overtimePendingQuery.data?.pagination?.total
      ?? overtimePendingQuery.data?.meta?.total
      ?? 0,
  )
  const pendingOvertimeRequest = Array.isArray(overtimePendingQuery.data?.overtimes)
    ? overtimePendingQuery.data.overtimes[0] || null
    : null

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
    let logs = todayLogs
    if (attendanceFilter === 'late') {
      logs = todayLogs.filter((l) => l.is_late)
    } else if (attendanceFilter === 'absent') {
      logs = todayLogs.filter((l) => l.is_absent)
    }
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
          const keyOf = (row) => String(row.employee_sort_key || row.employee_name || '').toLowerCase()
          aVal = keyOf(a)
          bVal = keyOf(b)
        }
        if (aVal < bVal) return sortConfig.dir === 'asc' ? -1 : 1
        if (aVal > bVal) return sortConfig.dir === 'asc' ? 1 : -1
        return 0
      })
    }
    return logs
  }, [todayLogs, attendanceFilter, sortConfig])

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

  const todayBirthdays = Array.isArray(data?.today_birthdays) ? data.today_birthdays : []
  const currentMonthBirthdays = Array.isArray(data?.current_month_birthdays) ? data.current_month_birthdays : []
  const upcomingBirthdays = Array.isArray(data?.upcoming_30_days)
    ? data.upcoming_30_days
    : Array.isArray(data?.upcoming_birthdays)
      ? data.upcoming_birthdays
      : []
  const upcomingBirthdays90 = Array.isArray(data?.upcoming_90_days)
    ? data.upcoming_90_days
    : Array.isArray(data?.upcoming_birthdays_90)
      ? data.upcoming_birthdays_90
      : []
  const birthdayMonthLabel = data?.birthday_month_label || 'This Month'
  const birthdayMonthRangeLabel = data?.birthday_month_range_label || ''
  const browsedMonthBirthdays = isBrowsingCurrentBirthdayMonth
    ? currentMonthBirthdays
    : (birthdayMonthQuery.data?.birthdays ?? [])
  const browsedBirthdayMonthLabel = isBrowsingCurrentBirthdayMonth
    ? birthdayMonthLabel
    : (birthdayMonthQuery.data?.birthday_month_label || birthdayMonthLabel)
  const browsedBirthdayMonthRangeLabel = isBrowsingCurrentBirthdayMonth
    ? birthdayMonthRangeLabel
    : (birthdayMonthQuery.data?.birthday_month_range_label || '')
  const browsedBirthdayIsFutureMonth = isBrowsingCurrentBirthdayMonth
    ? false
    : Boolean(birthdayMonthQuery.data?.is_future_month)
  const earliestBirthdayBrowseMonth = useMemo(
    () => shiftCalendarMonth(manilaToday, -(BIRTHDAY_CALENDAR_MONTHS_BACK - 1)),
    [manilaToday]
  )
  const latestBirthdayBrowseMonth = useMemo(
    () => shiftCalendarMonth(manilaToday, BIRTHDAY_CALENDAR_MONTHS_AHEAD - 1),
    [manilaToday]
  )
  const birthdayMonthSelectOptions = useMemo(
    () => buildBirthdayMonthSelectOptions(earliestBirthdayBrowseMonth, latestBirthdayBrowseMonth, manilaToday),
    [earliestBirthdayBrowseMonth, latestBirthdayBrowseMonth, manilaToday]
  )
  const birthdayBrowseMonthValue = calendarMonthKey(birthdayBrowseMonth)
  const birthdayMonthBrowseLoading =
    !isBrowsingCurrentBirthdayMonth && (birthdayMonthQuery.isLoading || birthdayMonthQuery.isFetching)
  const birthdayMonthBrowseError = !isBrowsingCurrentBirthdayMonth ? birthdayMonthQuery.error : null
  const birthdayMonthShortName = birthdayMonthShortLabel(browsedBirthdayMonthLabel)
  const birthdayTabOptions = useMemo(
    () => [
      {
        value: 'month',
        label: birthdayMonthShortName,
        ariaLabel: `Calendar month, ${browsedBirthdayMonthLabel}`,
        count: browsedMonthBirthdays.length,
      },
      {
        value: 'upcoming30',
        label: '30 days',
        shortLabel: '30d',
        ariaLabel: 'Upcoming 30 days',
        count: upcomingBirthdays.length,
      },
      {
        value: 'upcoming90',
        label: '90 days',
        shortLabel: '90d',
        ariaLabel: 'Upcoming 90 days',
        count: upcomingBirthdays90.length,
      },
    ],
    [
      birthdayMonthShortName,
      browsedBirthdayMonthLabel,
      browsedMonthBirthdays.length,
      upcomingBirthdays.length,
      upcomingBirthdays90.length,
    ]
  )
  const birthdayRowsForTab =
    birthdayTab === 'upcoming90'
      ? upcomingBirthdays90
      : birthdayTab === 'upcoming30'
        ? upcomingBirthdays
        : browsedMonthBirthdays
  const birthdaySearchTerm = birthdaySearch.trim().toLowerCase()
  const visibleBirthdayRows = birthdaySearchTerm
    ? birthdayRowsForTab.filter((person) => [
        person?.full_name,
        person?.department,
        person?.position,
        person?.birth_date_formatted,
        person?.day_name,
      ].some((value) => String(value || '').toLowerCase().includes(birthdaySearchTerm)))
    : birthdayRowsForTab

  const handleBirthdayMonthSelect = useCallback((value) => {
    if (!value) return
    const parsed = parseCalendarMonthKey(value)
    if (!Number.isFinite(parsed.year) || !Number.isFinite(parsed.month)) return
    if (isCalendarMonthBefore(parsed, earliestBirthdayBrowseMonth)) return
    if (isCalendarMonthAfter(parsed, latestBirthdayBrowseMonth)) return
    setBirthdayBrowseMonth(parsed)
  }, [earliestBirthdayBrowseMonth, latestBirthdayBrowseMonth])

  const upcomingHolidaysFiltered = useMemo(
    () =>
      filterUpcomingHolidaysByMonth(
        Array.isArray(data?.upcoming_holidays) ? data.upcoming_holidays : [],
        holidayMonth
      ),
    [data?.upcoming_holidays, holidayMonth]
  )

  const upcomingHolidaysDisplay = useMemo(
    () => upcomingHolidaysFiltered.slice(0, UPCOMING_HOLIDAYS_DISPLAY_LIMIT),
    [upcomingHolidaysFiltered]
  )

  const handleHolidayYearSelect = useCallback((yearValue) => {
    const year = Number(yearValue)
    const parsed = parseHolidayMonthKey(holidayMonth)
    if (!Number.isFinite(year) || !parsed) return
    setHolidayMonth(holidayMonthKey({ year, month: parsed.month }))
  }, [holidayMonth])

  const handleHolidayCalendarMonthSelect = useCallback((monthValue) => {
    const month = Number(monthValue)
    const parsed = parseHolidayMonthKey(holidayMonth)
    if (!Number.isFinite(month) || month < 1 || month > 12 || !parsed) return
    setHolidayMonth(holidayMonthKey({ year: parsed.year, month }))
  }, [holidayMonth])

  const handleHolidayMonthStep = useCallback(
    (delta) => {
      const parsed = parseHolidayMonthKey(holidayMonth)
      if (!parsed) return
      const next = shiftHolidayMonth(parsed, delta)
      if (next.year < holidayYearMin || next.year > holidayYearMax) return
      setHolidayMonth(holidayMonthKey(next))
    },
    [holidayMonth, holidayYearMin, holidayYearMax]
  )
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
  const weeklyMax = weeklyData.reduce(
    (max, d) => Math.max(max, typeof d?.present_count === 'number' ? d.present_count : 0),
    0
  )
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
      trendType: 'weekly',
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
    present: Number(c.present ?? 0),
    headcount: Number(c.headcount ?? 0),
    late: Number(c.late ?? 0),
    absent: Number(c.absent ?? 0),
    on_leave: Number(c.on_leave ?? 0),
    // Attendance formula: (present employees / total company employees) * 100.
    attendance_pct:
      Number(c.headcount ?? 0) > 0
        ? Number(((Number(c.present ?? 0) / Number(c.headcount ?? 0)) * 100).toFixed(2))
        : 0,
    company: c.company ?? 'Unassigned',
    company_id: c.company_id,
    // Keep backend percentage for reference/debug; UI uses computed attendance_pct above.
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
  const absentTodayLogsCount = todayLogs.filter((l) => l.is_absent).length
  const upcomingRegularizations = Array.isArray(data?.upcoming_regularizations)
    ? data.upcoming_regularizations
    : []
  const expiringContracts = Array.isArray(data?.expiring_contracts) ? data.expiring_contracts : []
  const pendingAttendanceCorrectionsCount = canApproveAttendanceCorrections
    ? Number(
        attendanceCorrectionsPendingQuery.data?.presence_filings?.length
          ?? data?.pending_attendance_corrections
          ?? 0
      ) || 0
    : 0
  const pendingAttendanceCorrectionPreview =
    canApproveAttendanceCorrections && pendingAttendanceCorrectionsCount > 0
      ? data?.pending_attendance_correction_preview ?? null
      : null
  const pendingAttendanceCorrectionPreviews =
    canApproveAttendanceCorrections && Array.isArray(attendanceCorrectionsPendingQuery.data?.presence_filings)
      ? attendanceCorrectionsPendingQuery.data.presence_filings
      : canApproveAttendanceCorrections && Array.isArray(data?.pending_requests)
      ? data.pending_requests
      : canApproveAttendanceCorrections && Array.isArray(data?.pending_attendance_correction_previews)
        ? data.pending_attendance_correction_previews
      : pendingAttendanceCorrectionPreview
        ? [pendingAttendanceCorrectionPreview]
        : []
  const todayLeavesPreview = todayLeaves.slice(0, 1)
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
  const emptyLogsMessage =
    attendanceFilter === 'late'
      ? 'No late employees today.'
      : attendanceFilter === 'absent'
        ? 'No absent employees today.'
        : 'No attendance logs today yet.'

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
      className="admin-dashboard space-y-3 text-foreground dark:text-zinc-50 @md:space-y-4"
      initial="hidden"
      animate="visible"
      variants={{ hidden: { opacity: 0 }, visible: { opacity: 1, transition: { staggerChildren: 0.04 } } }}
    >
      <Motion.div
        className="mb-0 flex flex-col gap-3 pt-0 @md:flex-row @md:items-start @md:justify-between"
        variants={itemVariants}
      >
        <div className="space-y-1">
          <p className="text-[13px] font-semibold uppercase tracking-[0.18em] text-foreground/85 dark:text-foreground/75">
            {getGreeting()}
            {isHrAdmin ? ', Admin' : dashboardScopeLabel ? `, ${dashboardScopeLabel} lead` : ''}
          </p>
          <div className="flex flex-wrap items-center gap-2.5">
            {dashboardCompanyLogo ? (
              <div className="flex size-11 shrink-0 items-center justify-center overflow-hidden rounded-xl border border-border/70 bg-background p-1.5 shadow-sm @md:size-12">
                <img
                  src={dashboardCompanyLogo}
                  alt={user?.company_name ? `${user.company_name} logo` : 'Company logo'}
                  className="max-h-full max-w-full object-contain"
                  loading="lazy"
                />
              </div>
            ) : null}
            <h2 className="text-[30px] font-extrabold leading-none tracking-tight @md:text-[34px]">
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
          <p className="max-w-2xl text-sm font-normal leading-snug text-muted-foreground">
            {isHrAdmin
              ? 'Real-time insight into employees, attendance, and daily workforce activity.'
              : hrRole === 'department_head'
                ? 'Department attendance, team metrics, and upcoming holidays for your scope.'
                : 'Workforce metrics and attendance for your organization scope.'}
          </p>
        </div>
        <div className="flex flex-wrap items-center gap-2">
          <div className="inline-flex items-center gap-1.5 rounded-full border border-emerald-500/25 bg-card px-3 py-1.5 text-[11px] text-emerald-800 shadow-sm dark:bg-card dark:text-emerald-300">
            <span className="relative inline-flex size-2 shrink-0">
              <span className="absolute inline-flex size-full animate-ping rounded-full bg-emerald-400/70" />
              <span className="relative inline-flex size-2 rounded-full bg-emerald-500" />
            </span>
            <span className="font-extrabold uppercase tracking-[0.14em] text-emerald-700 dark:text-emerald-200">Live</span>
            <span className="text-[10px] font-normal opacity-75">Auto-refresh 15s</span>
          </div>
          <div className="inline-flex items-center gap-2 rounded-full border border-border/70 bg-card px-3 py-1.5 text-xs shadow-sm">
            <span className="text-[11px] font-normal uppercase tracking-wide text-muted-foreground">Range</span>
            <span className="rounded-full bg-background px-2.5 py-0.5 text-xs font-semibold text-foreground dark:bg-muted">
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
        className="mt-0 grid gap-3 @sm:grid-cols-2 @lg:grid-cols-3 @xl:grid-cols-5"
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
          }

          return (
            <Motion.div key={key} variants={itemVariants} whileHover={{ y: -2, transition: { duration: 0.15, ease: 'easeOut' } }}>
              <Card
                className={[
                  'admin-dashboard-card relative min-h-[250px] gap-0 overflow-hidden py-0 transition-all duration-200',
                  isLateAlert
                    ? 'border-rose-500/40 ring-1 ring-rose-500/15'
                    : `${meta.hoverShadow ?? ''}`,
                ].join(' ')}
              >
              <CardHeader className="relative z-10 flex flex-col items-start gap-5 px-5 pb-0 pt-5">
                <div className={`flex size-11 items-center justify-center rounded-full ring-1 ${isLateAlert ? 'animate-pulse bg-rose-500/15 text-rose-500 ring-rose-500/35' : `${meta.iconBg} ${meta.accent}`}`}>
                  <Icon className="size-5" />
                </div>
                <CardTitle className={`mb-0 text-[13px] font-extrabold uppercase tracking-[0.04em] ${isLateAlert ? 'text-rose-700 dark:text-rose-300' : 'text-foreground'}`}>
                  {label}
                </CardTitle>
              </CardHeader>
              <CardContent className="relative z-10 px-5 pb-5 pt-5">
                <div className="flex items-end justify-between gap-3">
                  <div className={`text-[46px] font-extrabold tabular-nums leading-none tracking-tight ${isLateAlert ? 'text-rose-600 dark:text-rose-300' : 'text-foreground'}`}>
                    <KpiValue value={value} />
                  </div>
                  <div className="mb-1 flex flex-col items-end gap-1">
                    <span className="text-[11px] font-normal text-muted-foreground">
                      {periodLabel}
                    </span>
                    <div className={`flex items-center text-xs font-bold tabular-nums ${deltaColorClass}`}>
                      {direction === 'up' ? (
                        <ArrowUpRight className="mr-0.5 size-3.5" />
                      ) : direction === 'down' ? (
                        <ArrowDownRight className="mr-0.5 size-3.5" />
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
                            <span>·</span>
                          )
                        ) : labelKind === 'count' ? (
                          deltaCount > 0 ? (
                            <span>+{formattedCount}</span>
                          ) : deltaCount < 0 ? (
                            <span>{formattedCount}</span>
                          ) : (
                            <span>·</span>
                          )
                        ) : (
                          <span>·</span>  
                        )
                      ) : (
                        <span className="text-[11px] font-normal">·</span>
                      )}
                    </div>
                  </div>
                </div>
                <div className="mt-7 h-10 w-full">
                  {miniSeries.length > 0 ? (
                    <div className={`h-full w-full ${isLateAlert ? 'text-rose-500' : meta.accent}`}>
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
                          strokeWidth={1.5}
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
        className="mt-4 grid items-stretch gap-3 @sm:grid-cols-2 @xl:grid-cols-3"
        variants={containerVariants}
        initial="hidden"
        whileInView="visible"
        viewport={scrollViewport}
        transition={scrollRevealTransition}
      >
        {/* 1. Today's Leaves */}
        <Motion.div variants={itemVariants} whileHover={{ y: -2, transition: { duration: 0.15 } }} className="self-stretch">
          <Card className={cn(
            'admin-dashboard-card h-[400px] max-h-[400px] min-h-[400px] gap-0 overflow-hidden py-0 transition-[transform,box-shadow] duration-300 hover:-translate-y-px @xl:h-[420px] @xl:max-h-[420px] @xl:min-h-[420px]',
          )}>
            <CardHeader className="px-4 pb-3 pt-4 @sm:px-5 @md:px-6 @md:pt-5">
              <div className="flex flex-col gap-2.5 @sm:flex-row @sm:items-start @sm:justify-between @sm:gap-4">
                <div className="min-w-0">
                  <CardTitle className="mb-2.5 flex min-w-0 flex-wrap items-center gap-2 text-base font-extrabold leading-snug tracking-tight text-foreground">
                    <CalendarDays className="size-4 shrink-0 text-brand" aria-hidden="true" />
                    <span className="truncate">Today&apos;s Leaves</span>
                  </CardTitle>
                  <CardDescription className="mt-0 text-xs font-normal leading-[1.55] text-muted-foreground">
                    {todayLeaves.length > 0
                      ? `${todayLeaves.length} employee${todayLeaves.length !== 1 ? 's are' : ' is'} on leave today`
                      : 'Updates automatically from approved leave requests.'}
                  </CardDescription>
                </div>
                {canViewLeave ? (
                  <Button
                    type="button"
                    variant="outline"
                    size="sm"
                    className={cn(
                      'h-8 w-full shrink-0 rounded-md border-border/70 bg-background/70 px-3 @sm:mt-1 @sm:w-auto',
                      'text-xs font-medium shadow-sm hover:bg-accent/55',
                    )}
                    onClick={() => navigate(hrPanelPath(hrBase, 'leave'))}
                  >
                    View All
                    <ArrowRight className="ml-1.5 size-3.5 opacity-70" aria-hidden />
                  </Button>
                ) : null}
              </div>
            </CardHeader>
            <CardContent className="flex min-h-0 flex-1 flex-col gap-3 overflow-y-auto overscroll-contain px-4 pb-4 pt-0 pr-3 @sm:px-5 @sm:pr-4 @md:px-6">
              {todayLeaves.length === 0 ? (
                <div className="flex min-h-[172px] flex-col items-center justify-center rounded-lg border border-emerald-500/10 bg-[radial-gradient(circle_at_center,rgba(16,185,129,0.14),rgba(16,185,129,0.04)_58%,transparent)] p-5 text-center dark:border-emerald-400/15">
                  <span className="mb-4 flex size-9 items-center justify-center rounded-full bg-emerald-500 text-white shadow-sm">
                    <ClipboardCheck className="size-5" aria-hidden />
                  </span>
                  <p className="text-sm font-semibold leading-[1.55] text-foreground">No leaves today. Everyone is present.</p>
                  <p className="mt-2 max-w-56 text-xs font-normal leading-[1.55] text-muted-foreground">
                    This section updates automatically from approved leave requests.
                  </p>
                </div>
              ) : (
                <div className="space-y-2.5 @sm:space-y-3">
                  {todayLeavesPreview.map((leave) => {
                    const profileSrc = leave.profile_image_url || profileImageUrl(leave.profile_image) || undefined
                    const secondary = [leave.department, leave.position].filter(Boolean).join(' / ') || 'Unassigned'
                    return (
                      <article
                        key={`${leave.leave_request_id}-${leave.user_id}`}
                        className="rounded-lg border border-border/70 bg-background/70 p-2.5 shadow-sm transition-[border-color,box-shadow,transform] duration-200 hover:border-brand/25 hover:shadow-md @sm:p-3"
                      >
                        <div className="flex items-start gap-3">
                          <Avatar className="size-9 shrink-0 rounded-full border border-border/70 ring-2 ring-primary/10 @sm:size-10">
                            <AvatarImage src={profileSrc} alt={leave.employee_name || 'Employee'} />
                            <AvatarFallback>{(leave.employee_name || EMPTY_PLACEHOLDER).slice(0, 2).toUpperCase()}</AvatarFallback>
                          </Avatar>
                          <div className="min-w-0 flex-1">
                            <p className="wrap-break-word text-sm font-semibold leading-snug text-foreground">{formatEmpty(leave.employee_name)}</p>
                            <p className="mt-0.5 wrap-break-word text-xs leading-snug text-muted-foreground">{secondary}</p>
                            <div className="mt-2 flex flex-wrap items-center gap-1.5 @sm:gap-2">
                              <span className="inline-flex items-center rounded-full border border-violet-500/35 bg-violet-500/10 px-2 py-0.5 text-[10px] font-semibold uppercase tracking-wide text-violet-700 dark:text-violet-300">
                                {leave.leave_type || 'Leave'}
                              </span>
                              <span className="text-[11px] font-medium text-muted-foreground">{leave.duration_label || 'Full day'}</span>
                            </div>
                          </div>
                        </div>
                        {canViewLeave && (leave.leave_request_id != null || leave.request_id != null) ? (
                          <div className="mt-3 border-t border-border/70 pt-3">
                            <Button
                              type="button"
                              className="h-9 w-full rounded-lg bg-brand px-4 text-xs font-semibold text-brand-foreground shadow-[0_10px_20px_rgba(255,107,0,0.24)] hover:bg-brand-strong"
                              onClick={() => {
                                const rid = leave.request_id ?? leave.leave_request_id
                                navigate(
                                  `${hrPanelPath(hrBase, 'leave')}?request_id=${encodeURIComponent(String(rid))}`,
                                )
                              }}
                            >
                              <Send className="mr-2 size-4" aria-hidden />
                              Review Request
                            </Button>
                          </div>
                        ) : null}
                      </article>
                    )
                  })}
                </div>
              )}
            </CardContent>
          </Card>
        </Motion.div>

        {/* 2. Half-Day Summary — clickable drill-down */}
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

        {/* 3. Overtime Requests */}
        <Motion.div variants={itemVariants} className="self-stretch">
          <OvertimeRequestsCard
            loading={(!canViewOvertime && loading) || overtimePendingQuery.isLoading}
            pendingCount={canViewOvertime ? overtimePendingCount : 0}
            request={pendingOvertimeRequest}
            onViewAll={() => navigate(hrPanelPath(hrBase, 'overtime'))}
            onReviewRequest={(req) => {
              const rid = req?.request_id ?? req?.id
              if (rid == null || rid === '') {
                navigate(hrPanelPath(hrBase, 'overtime'))
                return
              }
              navigate(`${hrPanelPath(hrBase, 'overtime')}?request_id=${encodeURIComponent(String(rid))}`)
            }}
          />
        </Motion.div>

        {/* 4. Attendance corrections (approval queue) */}
        <Motion.div variants={itemVariants} className="self-stretch">
          <AttendanceCorrectionsCard
            loading={
              (!canApproveAttendanceCorrections && loading) ||
              (canApproveAttendanceCorrections && (dashboardQuery.isLoading || attendanceCorrectionsPendingQuery.isLoading))
            }
            pendingCount={canApproveAttendanceCorrections ? pendingAttendanceCorrectionsCount : 0}
            request={pendingAttendanceCorrectionPreview}
            requests={pendingAttendanceCorrectionPreviews}
            onViewAll={() => navigate(attendanceCorrectionsHref({ status: 'pending' }))}
            onReviewRequest={(item) => navigate(attendanceCorrectionsHref({
              status: 'pending',
              request_id: item?.correction_request_id ?? item?.id,
            }))}
          />
        </Motion.div>
      </Motion.div>

      {isHrAdmin ? (
        <Motion.div
          className="mt-3"
          variants={containerVariants}
          initial="hidden"
          whileInView="visible"
          viewport={scrollViewport}
          transition={scrollRevealTransition}
        >
          <Motion.div variants={itemVariants}>
            <Card className="admin-birthday-dashboard admin-dashboard-card overflow-hidden rounded-[1.25rem] py-0">
              <CardHeader className="border-b border-border/70 px-5 py-7 @md:px-8 @xl:py-9">
                <div className="flex flex-col gap-6 @xl:flex-row @xl:items-start @xl:justify-between">
                  <div className="min-w-0">
                    <CardTitle className="flex min-w-0 flex-wrap items-center gap-3 text-2xl font-extrabold tracking-tight text-foreground">
                      <Cake className="size-9 shrink-0 text-brand" strokeWidth={2.4} aria-hidden="true" />
                      <span>Employee Birthdays</span>
                    </CardTitle>
                    <CardDescription className="mt-3 max-w-2xl text-base leading-relaxed text-muted-foreground">
                      Track today&apos;s celebrants, browse birthdays by month (including past months), and see who is coming up in the next 30 and 90 days.
                      {browsedBirthdayMonthRangeLabel
                        ? ` Viewing ${browsedBirthdayMonthLabel}: ${browsedBirthdayMonthRangeLabel}.`
                        : ''}
                    </CardDescription>
                  </div>
                  <div className="grid w-full grid-cols-1 gap-3 @sm:grid-cols-3 @xl:w-auto">
                    <div className="admin-birthday-stat admin-birthday-stat--active rounded-lg border border-brand/25 bg-brand/5 px-5 py-4">
                      <p className="flex items-center gap-3 text-sm font-extrabold uppercase tracking-wide text-brand">
                        <Calendar className="size-5" aria-hidden />
                        Today
                      </p>
                      <p className="mt-4 text-4xl font-extrabold leading-none text-brand">
                        {todayBirthdays.length}
                      </p>
                    </div>
                    <div className="admin-birthday-stat rounded-lg border border-border/70 bg-background/70 px-5 py-4">
                      <p className="flex items-center gap-3 text-sm font-extrabold uppercase tracking-wide text-foreground">
                        <CalendarDays className="size-5 text-muted-foreground" aria-hidden />
                        {birthdayMonthShortName}
                      </p>
                      <p className="mt-4 text-4xl font-extrabold leading-none text-foreground">
                        {birthdayTab === 'month' ? browsedMonthBirthdays.length : currentMonthBirthdays.length}
                      </p>
                    </div>
                    <div className="admin-birthday-stat rounded-lg border border-border/70 bg-background/70 px-5 py-4">
                      <p className="flex items-center gap-3 text-sm font-extrabold uppercase tracking-wide text-foreground">
                        <Cake className="size-5 text-muted-foreground" aria-hidden />
                        90 Days
                      </p>
                      <p className="mt-4 text-4xl font-extrabold leading-none text-brand">
                        {upcomingBirthdays90.length}
                      </p>
                    </div>
                  </div>
                </div>
              </CardHeader>
              <CardContent className="px-5 py-7 @md:px-8">
                <Tabs value={birthdayTab} onValueChange={setBirthdayTab} className="gap-6">
                  <div className="flex flex-col gap-4 @xl:flex-row @xl:items-center @xl:justify-between">
                    <div className="flex min-w-0 flex-col gap-3 @md:flex-row @md:items-stretch @md:gap-3">
                    <TabsList className={cn(BIRTHDAY_TAB_LIST_CLASS, '@md:max-w-xl')}>
                      {birthdayTabOptions.map(({ value, label, shortLabel, ariaLabel, count }) => (
                        <TabsTrigger
                          key={value}
                          value={value}
                          className={BIRTHDAY_TAB_TRIGGER_CLASS}
                          aria-label={`${ariaLabel}, ${count} ${count === 1 ? 'employee' : 'employees'}`}
                        >
                          <span className="text-center text-[11px] font-semibold leading-tight @md:text-sm">
                            <span className="@md:hidden">{shortLabel || label}</span>
                            <span className="hidden @md:inline">{label}</span>
                          </span>
                          <span
                            className={cn(
                              'inline-flex min-w-6 items-center justify-center rounded-full px-1.5 py-0.5 text-[10px] font-bold tabular-nums leading-none @md:min-w-7 @md:px-2 @md:text-[11px]',
                              'bg-background/90 text-muted-foreground ring-1 ring-border/50',
                              'group-data-[state=active]:bg-brand/12 group-data-[state=active]:text-brand group-data-[state=active]:ring-brand/25'
                            )}
                          >
                            {count}
                          </span>
                        </TabsTrigger>
                      ))}
                    </TabsList>
                    {birthdayTab === 'month' ? (
                      <div className="admin-birthday-month-nav w-full shrink-0 @md:w-auto">
                        <Select
                          value={birthdayBrowseMonthValue}
                          onValueChange={handleBirthdayMonthSelect}
                          disabled={birthdayMonthBrowseLoading}
                        >
                          <SelectTrigger
                            className="h-11 w-full min-w-[10.5rem] rounded-lg border-border/70 bg-background/90 text-sm font-semibold shadow-sm @md:min-w-[12rem]"
                            aria-label="Select birthday calendar month"
                          >
                            <SelectValue placeholder="Select month" />
                          </SelectTrigger>
                          <SelectContent position="popper" className="max-h-72">
                            {birthdayMonthSelectOptions.map((option) => (
                              <SelectItem key={option.value} value={option.value}>
                                {option.label}
                                {option.isCurrent ? ' (current)' : option.isFuture ? ' (upcoming)' : ''}
                              </SelectItem>
                            ))}
                          </SelectContent>
                        </Select>
                      </div>
                    ) : null}
                    </div>
                    <div className="relative w-full @xl:max-w-md">
                      <Search className="pointer-events-none absolute left-4 top-1/2 size-5 -translate-y-1/2 text-foreground/80" aria-hidden />
                      <input
                        type="search"
                        value={birthdaySearch}
                        onChange={(event) => setBirthdaySearch(event.target.value)}
                        placeholder="Search birthdays..."
                        className="admin-birthday-search h-14 w-full rounded-lg border border-border/70 bg-background/75 pl-12 pr-4 text-base text-foreground shadow-sm outline-none transition placeholder:text-muted-foreground focus:border-brand/45 focus:ring-2 focus:ring-brand/15"
                      />
                    </div>
                  </div>

                  <TabsContent value="month" className="mt-0 space-y-4">
                    {birthdayMonthBrowseError ? (
                      <Motion.div className="rounded-lg border border-destructive/30 bg-destructive/5 px-4 py-3 text-sm text-destructive">
                        {birthdayMonthBrowseError.message || 'Failed to load birthdays for this month.'}
                      </Motion.div>
                    ) : null}
                    {birthdayMonthBrowseLoading ? (
                      <Motion.div className="rounded-lg border border-border/70 bg-muted/20 px-4 py-12 text-center text-sm text-muted-foreground">
                        Loading birthdays for {browsedBirthdayMonthLabel}…
                      </Motion.div>
                    ) : visibleBirthdayRows.length === 0 ? (
                      <Motion.div className="rounded-lg border border-border/70 bg-muted/20 px-4 py-12 text-center text-sm text-muted-foreground">
                        No birthdays found for {browsedBirthdayMonthLabel}.
                      </Motion.div>
                    ) : (
                      <div className="admin-birthday-grid grid max-h-[520px] gap-3 overflow-y-auto pr-1 @lg:grid-cols-2 @3xl:grid-cols-3">
                        {visibleBirthdayRows.map((person) => (
                          <BirthdayPersonRow
                            key={`month-${person.employee_id}-${birthdayBrowseMonth.year}-${birthdayBrowseMonth.month}`}
                            person={person}
                            tone={Number(person.days_until_birthday) === 0 ? 'today' : 'upcoming'}
                            monthView
                            futureMonthView={browsedBirthdayIsFutureMonth}
                            onOpen={() => navigate(hrPanelPath(hrBase, `employees/${person.employee_id}`))}
                          />
                        ))}
                      </div>
                    )}
                  </TabsContent>

                  <TabsContent value="upcoming30" className="mt-0">
                    {visibleBirthdayRows.length === 0 ? (
                      <div className="rounded-lg border border-border/70 bg-muted/20 px-4 py-12 text-center text-sm text-muted-foreground">
                        No birthdays found for this period.
                      </div>
                    ) : (
                      <div className="admin-birthday-grid grid max-h-[520px] gap-3 overflow-y-auto pr-1 @lg:grid-cols-2 @3xl:grid-cols-3">
                        {visibleBirthdayRows.map((person) => (
                          <BirthdayPersonRow
                            key={`upcoming30-${person.employee_id}`}
                            person={person}
                            tone={Number(person.days_until_birthday) === 0 ? 'today' : 'upcoming'}
                            onOpen={() => navigate(hrPanelPath(hrBase, `employees/${person.employee_id}`))}
                          />
                        ))}
                      </div>
                    )}
                  </TabsContent>

                  <TabsContent value="upcoming90" className="mt-0">
                    {visibleBirthdayRows.length === 0 ? (
                      <div className="rounded-lg border border-border/70 bg-muted/20 px-4 py-12 text-center text-sm text-muted-foreground">
                        No birthdays found for this period.
                      </div>
                    ) : (
                      <div className="admin-birthday-grid grid max-h-[520px] gap-3 overflow-y-auto pr-1 @lg:grid-cols-2 @3xl:grid-cols-3">
                        {visibleBirthdayRows.map((person) => (
                          <BirthdayPersonRow
                            key={`upcoming90-${person.employee_id}`}
                            person={person}
                            tone={Number(person.days_until_birthday) === 0 ? 'today' : 'upcoming'}
                            onOpen={() => navigate(hrPanelPath(hrBase, `employees/${person.employee_id}`))}
                          />
                        ))}
                      </div>
                    )}
                  </TabsContent>
                </Tabs>
              </CardContent>
            </Card>
          </Motion.div>
        </Motion.div>
      ) : null}

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
                const statusBadgeText = `${emp.status_label || emp.indicator_label || 'Status'} · ${formatDaysLabel(emp.days_remaining_label)}`

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
                            {formatEmpty(emp.employee_code)}
                          </span>
                          <span className="rounded-full border border-border/70 bg-muted/20 px-2 py-0.5 capitalize">
                            {employmentTypeLabel}
                          </span>
                          <span className="text-muted-foreground/60"></span>
                          <span>
                            Hired <span className="font-medium text-foreground/85">{formatDate(emp.hire_date)}</span>
                          </span>
                        </div>

                        <div className="mt-2 grid gap-1.5 text-xs @sm:grid-cols-2">
                          <p className="text-muted-foreground">
                            Service length:{' '}
                            <span className="font-medium text-foreground/85">{formatEmpty(emp.service_length_label)}</span>
                          </p>
                          <p className="text-muted-foreground">
                            Next milestone:{' '}
                            <span className="font-medium text-foreground/85">
                              {formatEmpty(emp.next_milestone)} ({formatDate(emp.next_milestone_date)})
                            </span>
                          </p>
                        </div>

                        <div className="mt-3 rounded-xl border border-border/60 bg-muted/10 px-3 py-2.5">
                          <p className="text-[11px] font-semibold uppercase tracking-wide text-muted-foreground">
                            Recommended action
                          </p>
                          <p className="mt-1 text-sm leading-snug text-foreground/90">
                            {formatEmpty(emp.recommended_action)}
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
                              Start: {formatDate(emp.contract_start_date)} · End: {formatDate(emp.contract_end_date)}
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
          <AttendanceCorrectionsCard
            loading={
              (!canApproveAttendanceCorrections && loading) ||
              (canApproveAttendanceCorrections && (dashboardQuery.isLoading || attendanceCorrectionsPendingQuery.isLoading))
            }
            pendingCount={canApproveAttendanceCorrections ? pendingAttendanceCorrectionsCount : 0}
            request={pendingAttendanceCorrectionPreview}
            requests={pendingAttendanceCorrectionPreviews}
            onViewAll={() => navigate(attendanceCorrectionsHref({ status: 'pending' }))}
            onReviewRequest={(item) => navigate(attendanceCorrectionsHref({
              status: 'pending',
              request_id: item?.correction_request_id ?? item?.id,
            }))}
          />
        </Motion.div>
      </Motion.div>

      {/* Charts row — redesigned UI */}
      <Motion.div
        className="mt-4 grid gap-3 @lg:grid-cols-2 @lg:items-stretch"
        variants={containerVariants}
        initial="hidden"
        whileInView="visible"
        viewport={scrollViewport}
        transition={scrollRevealTransition}
      >
        {/* Weekly Attendance — vertical bars, stronger contrast */}
        <Motion.div variants={chartCardVariants} className="h-full" whileHover={{ y: -2, transition: { duration: 0.15 } }}>
          <Card className="admin-dashboard-card flex h-full flex-col overflow-hidden py-0 transition-all duration-150 hover:shadow-md">
          <CardHeader className="px-5 pb-5 pt-6">
            <CardTitle className="mb-3 flex items-center gap-2 text-base font-extrabold leading-snug tracking-tight text-foreground">
              <BarChart3 className="size-4 text-brand" aria-hidden />
              Weekly Attendance
            </CardTitle>
            <CardDescription className="mt-0 text-xs font-normal leading-[1.55] text-muted-foreground">
              Employees who clocked in per day (last 7 days)
            </CardDescription>
          </CardHeader>
          <CardContent className="px-5 pb-5 pt-0">
            <div className="h-[300px] w-full rounded-lg bg-background/35 px-2 pt-2 dark:bg-background/25">
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
                        <stop offset="60%" stopColor={CHART.weeklyBar} stopOpacity={0.9} />
                        <stop offset="100%" stopColor={CHART.weeklyBar} stopOpacity={0.78} />
                      </linearGradient>
                    </defs>
                    <CartesianGrid
                      strokeDasharray="2 4"
                      stroke="color-mix(in oklab, var(--border) 76%, transparent)"
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
                        stroke: 'var(--brand-strong)',
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

        {/* Upcoming Holidays — Holiday Module (matched height to Weekly Attendance) */}
        <Motion.div variants={chartCardVariants} className="h-full" whileHover={{ y: -2, transition: { duration: 0.15 } }}>
          <Card className="admin-dashboard-card flex h-full flex-col overflow-hidden py-0 transition-all duration-150 hover:shadow-md">
          <CardHeader className="px-5 pb-5 pt-6">
            <div className="flex flex-wrap items-start justify-between gap-3">
              <div className="min-w-0">
                <CardTitle className="mb-3 flex items-center gap-2 text-base font-extrabold leading-snug tracking-tight text-foreground">
                  <Calendar className="size-4 text-brand" aria-hidden />
                  Upcoming Holidays
                </CardTitle>
                <CardDescription className="mt-0 text-xs font-normal leading-[1.55] text-muted-foreground">
                  Holidays from the Holiday Module
                </CardDescription>
              </div>
              {canViewHolidays ? (
                <Button
                  type="button"
                  variant="outline"
                  size="sm"
                  className="shrink-0 gap-1.5 border-brand/40 text-brand hover:bg-brand/8"
                  onClick={() => navigate(hrPanelPath(hrBase, 'holiday'))}
                >
                  View All Holidays
                  <ArrowRight className="size-3.5" aria-hidden />
                </Button>
              ) : null}
            </div>
          </CardHeader>
          <CardContent className="flex flex-1 flex-col px-5 pb-5 pt-0">
            <div className="mb-3 flex flex-col gap-2.5 @md:flex-row @md:flex-wrap @md:items-center @md:justify-between">
              <div className="flex w-full items-center gap-1.5 @md:w-auto">
                <Button
                  type="button"
                  variant="outline"
                  size="icon"
                  className="size-10 shrink-0 rounded-lg border-border/70 bg-background/90 shadow-sm hover:border-brand/40 hover:bg-brand/5 disabled:opacity-40"
                  aria-label="Previous month"
                  disabled={!canHolidayMonthPrev}
                  onClick={() => handleHolidayMonthStep(-1)}
                >
                  <ChevronLeft className="size-4" aria-hidden />
                </Button>
                <Select value={String(holidayBrowse.year)} onValueChange={handleHolidayYearSelect}>
                  <SelectTrigger
                    className={cn(
                      'h-10 w-[5.5rem] shrink-0 gap-1.5 rounded-lg border-brand/30 bg-gradient-to-br from-brand/[0.08] via-background to-background px-2.5',
                      'text-sm font-semibold text-foreground shadow-sm ring-1 ring-brand/15',
                      'transition hover:border-brand/45 hover:shadow-md focus:ring-2 focus:ring-brand/25'
                    )}
                    aria-label="Select year for holidays"
                  >
                    <SelectValue placeholder="Year" />
                  </SelectTrigger>
                  <SelectContent position="popper" className="rounded-xl border-border/80 p-1.5 shadow-xl">
                    {holidayYearOptions.map((year) => (
                      <SelectItem
                        key={year}
                        value={String(year)}
                        className="cursor-pointer rounded-lg py-2 pl-8 pr-3 text-sm font-medium focus:bg-brand/10 focus:text-brand"
                      >
                        {year}
                      </SelectItem>
                    ))}
                  </SelectContent>
                </Select>
                <Select value={String(holidayBrowse.month)} onValueChange={handleHolidayCalendarMonthSelect}>
                  <SelectTrigger
                    className={cn(
                      'h-10 min-w-0 flex-1 gap-2.5 rounded-lg border-brand/30 bg-gradient-to-br from-brand/[0.08] via-background to-background px-3',
                      'text-sm font-semibold text-foreground shadow-sm ring-1 ring-brand/15',
                      'transition hover:border-brand/45 hover:shadow-md focus:ring-2 focus:ring-brand/25',
                      '@md:min-w-[9.5rem]'
                    )}
                    aria-label="Select month for holidays"
                  >
                    <span className="flex size-7 shrink-0 items-center justify-center rounded-md bg-brand/15 ring-1 ring-brand/20">
                      <Calendar className="size-4 text-brand" aria-hidden />
                    </span>
                    <SelectValue placeholder="Month" />
                  </SelectTrigger>
                  <SelectContent
                    position="popper"
                    className="max-h-72 min-w-[var(--radix-select-trigger-width)] rounded-xl border-border/80 p-1.5 shadow-xl"
                  >
                    {holidayMonthOptions.map((option) => (
                      <SelectItem
                        key={option.value}
                        value={String(option.value)}
                        className="cursor-pointer rounded-lg py-2.5 pl-9 pr-3 text-sm font-medium focus:bg-brand/10 focus:text-brand data-[state=checked]:bg-brand/12 data-[state=checked]:text-brand"
                      >
                        {option.label}
                      </SelectItem>
                    ))}
                  </SelectContent>
                </Select>
                <Button
                  type="button"
                  variant="outline"
                  size="icon"
                  className="size-10 shrink-0 rounded-lg border-border/70 bg-background/90 shadow-sm hover:border-brand/40 hover:bg-brand/5 disabled:opacity-40"
                  aria-label="Next month"
                  disabled={!canHolidayMonthNext}
                  onClick={() => handleHolidayMonthStep(1)}
                >
                  <ChevronRight className="size-4" aria-hidden />
                </Button>
              </div>
              {!loading && data && !error ? (
                <span className="text-[11px] font-medium text-muted-foreground @md:text-right">
                  {upcomingHolidaysFiltered.length === 0
                    ? `No holidays in ${formatHolidayMonthLabel(holidayMonth)}`
                    : `${upcomingHolidaysFiltered.length} holiday${upcomingHolidaysFiltered.length === 1 ? '' : 's'} in ${formatHolidayMonthLabel(holidayMonth)}`}
                </span>
              ) : null}
            </div>
            <div className="h-[300px] w-full overflow-hidden rounded-lg bg-background/35 dark:bg-background/25">
              {loading && !data ? (
                <div className="flex h-full items-center justify-center px-4 text-sm text-muted-foreground">
                  Loading upcoming holidays…
                </div>
              ) : error ? (
                <div className="flex h-full flex-col items-center justify-center px-4 text-center">
                  <AlertCircle className="mb-2 size-8 text-rose-500/80" aria-hidden />
                  <p className="text-sm text-rose-700 dark:text-rose-200">Could not load holidays.</p>
                  <p className="mt-1 text-xs text-muted-foreground">Refresh the dashboard to try again.</p>
                </div>
              ) : upcomingHolidaysDisplay.length === 0 ? (
                <div className="flex h-full flex-col items-center justify-center px-4 text-center">
                  <Calendar className="mb-3 size-9 text-muted-foreground/50" aria-hidden />
                  <p className="text-sm font-medium text-foreground">No upcoming holidays found.</p>
                  <p className="mt-1 max-w-xs text-xs text-muted-foreground">
                    {upcomingHolidaysFiltered.length > UPCOMING_HOLIDAYS_DISPLAY_LIMIT
                      ? `More than ${UPCOMING_HOLIDAYS_DISPLAY_LIMIT} holidays match this month; use View All Holidays.`
                      : `No active holidays in ${formatHolidayMonthLabel(holidayMonth)} for your scope.`}
                  </p>
                  {canViewHolidays ? (
                    <Button
                      type="button"
                      variant="link"
                      size="sm"
                      className="mt-2 text-brand"
                      onClick={() => navigate(hrPanelPath(hrBase, 'holiday'))}
                    >
                      Open Holiday Module
                    </Button>
                  ) : null}
                </div>
              ) : (
                <ul className="h-full space-y-2.5 overflow-y-auto p-2 pr-1">
                {upcomingHolidaysDisplay.map((holiday) => {
                  const rowKey = upcomingHolidayUniqueKey(holiday)
                  const typeLabel = holiday.type_label || holidayTypeLabel(holiday.type || holiday.holiday_type)
                  const multiplierLabel = formatHolidayMultiplierLabel(holiday)
                  const scopeType = holiday.scope_type || 'Nationwide'
                  const scopeLine = formatHolidayScopeLine(holiday)
                  const holidayLogo = resolveCompanyLogoSrc(holiday.company_logo_url, {
                    logo_url: holiday.company_logo_url,
                    company_id: holiday.company_id,
                  })
                  const daysLabel =
                    holiday.days_remaining_label ||
                    (holiday.is_today ? 'Today' : `In ${holiday.days_remaining ?? 0} days`)
                  const countdownClass = holiday.is_today
                    ? 'border-emerald-500/40 bg-emerald-500/12 text-emerald-800 dark:text-emerald-200'
                    : 'border-brand/35 bg-brand/12 text-orange-800 dark:text-orange-200'

                  return (
                    <li
                      key={rowKey}
                      className={cn(
                        'relative flex overflow-hidden rounded-xl border border-border/80 bg-card shadow-sm transition-shadow hover:shadow-md',
                        holiday.is_today && 'ring-1 ring-emerald-500/25'
                      )}
                    >
                      <span
                        className={cn('w-1 shrink-0', holiday.is_today ? 'bg-emerald-500' : 'bg-brand')}
                        aria-hidden
                      />
                      <div className="flex min-w-0 flex-1 items-stretch gap-3 py-3 pl-3 pr-2">
                        <div className="flex size-10 shrink-0 items-center justify-center overflow-hidden rounded-lg bg-brand/12 ring-1 ring-brand/15">
                          {holidayLogo ? (
                            <img
                              src={holidayLogo}
                              alt=""
                              className="max-h-8 max-w-8 object-contain"
                              loading="lazy"
                            />
                          ) : (
                            <Flag className="size-5 text-brand" aria-hidden />
                          )}
                        </div>
                        <div className="min-w-0 flex-1 space-y-1.5">
                          <div className="flex flex-wrap items-center gap-2">
                            <p className="text-sm font-bold leading-tight text-foreground">{holiday.name}</p>
                            {holiday.is_today ? (
                              <span className="inline-flex rounded-full border border-emerald-500/40 bg-emerald-500/15 px-2 py-0.5 text-[10px] font-bold uppercase tracking-wide text-emerald-800 dark:text-emerald-200">
                                Today
                              </span>
                            ) : null}
                          </div>
                          <p className="text-xs text-muted-foreground">{formatHolidayDateLine(holiday)}</p>
                          <div className="flex flex-wrap gap-1.5">
                            <span
                              className={cn(
                                'inline-flex rounded-full border px-2 py-0.5 text-[10px] font-semibold',
                                holidayTypeBadgeClass(holiday.type)
                              )}
                            >
                              {typeLabel}
                            </span>
                            <span
                              className={cn(
                                'inline-flex rounded-full border px-2 py-0.5 text-[10px] font-semibold',
                                holidayMultiplierBadgeClass()
                              )}
                              title="Holiday pay multiplier"
                            >
                              {multiplierLabel}
                            </span>
                            <span
                              className={cn(
                                'inline-flex rounded-full border px-2 py-0.5 text-[10px] font-semibold',
                                holidayScopeBadgeClass(scopeType)
                              )}
                            >
                              {scopeType}
                            </span>
                          </div>
                          <p className="flex items-center gap-1 text-[11px] text-muted-foreground">
                            <Building2 className="size-3.5 shrink-0 opacity-70" aria-hidden />
                            <span className="truncate">{scopeLine}</span>
                          </p>
                        </div>
                        <div className="flex shrink-0 items-center pr-1">
                          <span
                            className={cn(
                              'inline-flex items-center gap-1 rounded-full border px-2.5 py-1 text-[11px] font-semibold whitespace-nowrap',
                              countdownClass
                            )}
                          >
                            <Calendar className="size-3 opacity-80" aria-hidden />
                            {daysLabel}
                          </span>
                        </div>
                      </div>
                    </li>
                  )
                })}
                </ul>
              )}
            </div>
          </CardContent>
        </Card>
        </Motion.div>
      </Motion.div>

      {/* Company Attendance Comparison — horizontal bars with date + company filters */}
      <Motion.div
        variants={chartCardVariants}
        initial="hidden"
        whileInView="visible"
        viewport={scrollViewport}
        transition={{ ...scrollRevealTransition, duration: 0.9 }}
        whileHover={{ y: -2, transition: { duration: 0.15 } }}
      >
        <Card className="admin-dashboard-card overflow-hidden py-0 transition-all duration-150 hover:shadow-md">
        <CardHeader className="px-5 pb-5 pt-6">
          <div className="flex flex-wrap items-start justify-between gap-4">
            <div>
              <CardTitle className="mb-3 flex items-center gap-2 text-base font-extrabold leading-snug tracking-tight text-foreground">
                {isSingleCompany && companyData[0]?.logo_url ? (
                  <img
                    src={resolveCompanyLogoSrc(companyData[0].logo_url, { logo_url: companyData[0].logo_url, id: companyData[0].company_id })}
                    alt=""
                    className="size-8 shrink-0 rounded-md border border-border/60 bg-background object-contain p-0.5"
                    loading="lazy"
                  />
                ) : (
                  <Building2 className="size-4 text-brand" aria-hidden />
                )}
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
          <div className="mt-5 flex flex-col gap-3 @md:flex-row @md:flex-wrap @md:items-center">
            <div className="flex w-full flex-wrap items-center gap-2 @md:w-auto">
              <span className="text-[11px] font-normal uppercase tracking-wide text-muted-foreground">Date</span>
              <input
                type="date"
                value={companyDateFrom}
                onChange={(e) => setCompanyDateFrom(e.target.value)}
                className="min-w-0 flex-1 rounded-md border border-border bg-background px-3 py-1.5 text-sm font-semibold text-foreground shadow-sm @md:w-auto @md:flex-none"
              />
              <span className="text-xs text-muted-foreground">→</span>
              <input
                type="date"
                value={companyDateTo}
                onChange={(e) => setCompanyDateTo(e.target.value)}
                className="min-w-0 flex-1 rounded-md border border-border bg-background px-3 py-1.5 text-sm font-semibold text-foreground shadow-sm @md:w-auto @md:flex-none"
              />
              <Button
                variant="ghost"
                size="sm"
                className="h-7 px-2 text-xs font-semibold text-foreground @md:ml-1"
                onClick={() => {
                  const today = toLocalDateString(new Date())
                  setCompanyDateFrom(today)
                  setCompanyDateTo(today)
                }}
              >
                Today
              </Button>
            </div>
            <div className="flex w-full flex-col gap-2 @md:w-auto @md:flex-row @md:items-center">
              <span className="text-[11px] font-normal uppercase tracking-wide text-muted-foreground">Companies</span>
              <div className="flex flex-wrap gap-1.5">
                <button
                  type="button"
                  onClick={() => setSelectedCompanyIds([])}
                  className={`rounded-full px-3 py-1 text-xs transition-colors ${
                    selectedCompanyIds.length === 0
                      ? 'border border-brand bg-brand font-bold text-brand-foreground shadow-sm'
                      : 'border border-border bg-muted/50 font-normal text-muted-foreground hover:bg-muted'
                  }`}
                >
                  All
                </button>
                {companiesList.map((c) => {
                  const isSelected = selectedCompanyIds.includes(c.id)
                  const companyFilterLogo = companyLogoUrl(c)
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
                      className={`inline-flex items-center gap-1.5 rounded-full px-3 py-1 text-xs transition-colors ${
                        isSelected
                          ? 'border border-brand bg-brand font-bold text-brand-foreground shadow-sm'
                          : 'border border-border bg-muted/50 font-normal text-muted-foreground hover:bg-muted'
                      }`}
                    >
                      {companyFilterLogo ? (
                        <img
                          src={companyFilterLogo}
                          alt=""
                          className="size-4 shrink-0 rounded-sm object-contain"
                          loading="lazy"
                        />
                      ) : null}
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
              {companyData[0].company}: {companyData[0].present ?? 0} present ({companyData[0].attendance_pct ?? 0}%)
              {(companyData[0].headcount ?? 0) > 0 && ` · ${companyData[0].headcount} total staff`}
            </p>
          )}
          {/* Company legend · plain text, scrollable when many */}
          {companyData.length > 0 && !isSingleCompany && (
            <div className="mt-3 overflow-x-auto overflow-y-hidden scrollbar-thin">
              <div className="flex flex-nowrap gap-3 pb-1 min-w-0">
                {companyData.map((cd) => {
                  const isUnassigned = (cd.company || '').toLowerCase() === 'unassigned'
                  const legendLogo = resolveCompanyLogoSrc(cd.logo_url, { logo_url: cd.logo_url, id: cd.company_id })
                  return (
                    <div
                      key={cd.company_id ?? cd.company}
                      className={`inline-flex shrink-0 items-center gap-2 rounded-lg border border-border/50 px-2 py-1.5 ${isUnassigned ? 'bg-muted/10' : 'bg-muted/20'}`}
                    >
                      {legendLogo ? (
                        <img
                          src={legendLogo}
                          alt=""
                          className="size-5 shrink-0 rounded-sm object-contain"
                          loading="lazy"
                        />
                      ) : (
                        <Building2 className="size-4 shrink-0 text-muted-foreground" aria-hidden />
                      )}
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
        <CardContent className="px-5 pb-5 pt-0">
          <div className="h-[260px] w-full rounded-lg bg-background/35 px-1.5 pt-2 dark:bg-background/25 @sm:h-[280px] @md:h-[300px] @md:px-2">
            {companyChartLoading ? (
              <div className="flex h-full items-center justify-center rounded-lg bg-muted/30 text-sm text-muted-foreground">
                Loading…
              </div>
            ) : companyData.length > 0 ? (
              <ResponsiveContainer width="100%" height="100%">
                <BarChart
                  layout="vertical"
                  data={companyData}
                    margin={{ top: 8, right: 10, left: 4, bottom: 8 }}
                >
                  <CartesianGrid
                    strokeDasharray="2 4"
                    stroke="color-mix(in oklab, var(--border) 76%, transparent)"
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
                    width={96}
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
                      const tooltipLogo = resolveCompanyLogoSrc(row.logo_url, { logo_url: row.logo_url, id: row.company_id })
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
                          <div className="flex items-center gap-2">
                            {tooltipLogo ? (
                              <img
                                src={tooltipLogo}
                                alt=""
                                className="size-6 shrink-0 rounded-sm object-contain"
                                loading="lazy"
                              />
                            ) : null}
                            <p className="font-medium" style={{ color: 'var(--foreground)' }}>
                              {row.company}
                            </p>
                          </div>
                          <p className="mt-0.5 tabular-nums" style={{ color: 'var(--muted-foreground)' }}>
                            Present: <span className="font-semibold" style={{ color: 'var(--foreground)' }}>{present}</span>
                            {headcount > 0 && (
                              <span className="ml-1 text-xs">({row.attendance_pct}%)</span>
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
                      formatter: (v, entry) => {
                        const pct = Number(entry?.payload?.attendance_pct ?? 0)
                        if (pct <= 0) return `${v}`
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

      {/* Data tables — Today's Attendance Logs (real-time) */}
      <Motion.div
        className="space-y-3"
        variants={itemVariants}
        initial="hidden"
        whileInView="visible"
        viewport={scrollViewport}
        transition={scrollRevealTransition}
      >
        <div className="flex items-center gap-2 text-muted-foreground">
          <Table2 className="size-4 shrink-0 opacity-80" aria-hidden />
          <h3 className="text-xs font-semibold uppercase tracking-[0.12em] text-muted-foreground">Data tables</h3>
        </div>
        <Card className="admin-dashboard-card overflow-hidden py-0">
          <CardHeader className="border-b border-border/45 bg-card px-5 pb-5 pt-6">
            <div className="flex flex-wrap items-start justify-between gap-4">
              <div>
                <CardTitle className="mb-3 text-base font-extrabold leading-snug tracking-tight text-foreground">
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
                onClick={() => { setAttendanceFilter('all'); setLogsPage(1) }}
                className={[
                  'inline-flex h-8 items-center gap-1.5 rounded-full border px-3.5 text-xs transition-all',
                  attendanceFilter === 'all'
                    ? 'border border-brand bg-brand font-bold text-brand-foreground shadow-md'
                    : 'border-border/60 bg-transparent font-normal text-muted-foreground hover:border-border hover:text-foreground',
                ].join(' ')}
              >All {attendanceFilter === 'all' && `(${todayLogs.length})`}</button>
              <button
                type="button"
                onClick={() => { setAttendanceFilter('late'); setLogsPage(1) }}
                className={[
                  'inline-flex h-8 items-center gap-1.5 rounded-full border px-3.5 text-xs transition-all',
                  attendanceFilter === 'late'
                    ? 'border-2 border-rose-500/60 bg-rose-500/20 font-bold text-rose-800 shadow-md dark:text-rose-200'
                    : 'border-border/60 bg-transparent font-normal text-muted-foreground hover:border-rose-500/40 hover:text-rose-600 dark:hover:text-rose-400',
                ].join(' ')}
              >
                <span className="inline-flex size-1.5 rounded-full bg-rose-500" />
                Late only {lateTodayCount > 0 && `(${lateTodayCount})`}
              </button>
              <button
                type="button"
                onClick={() => { setAttendanceFilter('absent'); setLogsPage(1) }}
                className={[
                  'inline-flex h-8 items-center gap-1.5 rounded-full border px-3.5 text-xs transition-all',
                  attendanceFilter === 'absent'
                    ? 'border-2 border-red-500/60 bg-red-500/20 font-bold text-red-800 shadow-md dark:text-red-200'
                    : 'border-border/60 bg-transparent font-normal text-muted-foreground hover:border-red-500/40 hover:text-red-600 dark:hover:text-red-400',
                ].join(' ')}
              >
                <span className="inline-flex size-1.5 rounded-full bg-red-500" />
                Absent {absentTodayLogsCount > 0 && `(${absentTodayLogsCount})`}
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
                  {emptyLogsMessage}
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

                    const statusPill = log.is_absent
                      ? { icon: UserX, label: 'Absent', cls: 'text-red-700 dark:text-red-300 border-red-500/30 bg-red-500/10' }
                      : log.time_in && !log.time_out
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
                                {formatEmpty(log.company_name)}
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
                              {log.time_in ? formatTime(log.time_in) : ''}
                            </p>
                          </div>
                          <div className="rounded-lg border border-border/50 bg-muted/20 p-2">
                            <p className="text-[10px] font-semibold uppercase tracking-[0.12em] text-muted-foreground">
                              Time out
                            </p>
                            <p className="mt-1 font-mono text-[12px] tabular-nums text-foreground">
                              {log.time_out ? formatTime(log.time_out) : ''}
                            </p>
                          </div>
                        </div>

                        <div className="mt-2">
                          {log.is_absent ? (
                            <span className="inline-flex items-center rounded-full border border-red-400/60 bg-linear-to-r from-red-500/20 via-red-500/10 to-red-500/0 px-2.5 py-1 text-xs font-medium text-red-800 shadow-sm dark:border-red-500/70 dark:bg-red-500/20 dark:text-red-100">
                              Absent
                            </span>
                          ) : log.time_in ? (
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
                            <span className="text-xs text-muted-foreground"></span>
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
                    <th className="sticky left-0 z-30 bg-card/95 backdrop-blur-sm px-5 py-3 text-left text-xs font-medium text-muted-foreground">
                      <button type="button" onClick={() => handleSort('employee_name')} className="inline-flex items-center gap-1 hover:text-foreground transition-colors">
                        Employee
                        {sortConfig.key === 'employee_name' ? (sortConfig.dir === 'asc' ? <ArrowUp className="size-3" /> : <ArrowDown className="size-3" />) : <ArrowUpDown className="size-3 opacity-40" />}
                      </button>
                    </th>
                    <th className="px-5 py-3 text-left text-xs font-medium text-muted-foreground">
                      Company
                    </th>
                    <th className="px-5 py-3 text-left text-xs font-medium text-muted-foreground">
                      <button type="button" onClick={() => handleSort('time_in')} className="inline-flex items-center gap-1 hover:text-foreground transition-colors">
                        Time In
                        {sortConfig.key === 'time_in' ? (sortConfig.dir === 'asc' ? <ArrowUp className="size-3" /> : <ArrowDown className="size-3" />) : <ArrowUpDown className="size-3 opacity-40" />}
                      </button>
                    </th>
                    <th className="px-5 py-3 text-left text-xs font-medium text-muted-foreground">
                      <button type="button" onClick={() => handleSort('is_late')} className="inline-flex items-center gap-1 hover:text-foreground transition-colors">
                        Late
                        {sortConfig.key === 'is_late' ? (sortConfig.dir === 'asc' ? <ArrowUp className="size-3" /> : <ArrowDown className="size-3" />) : <ArrowUpDown className="size-3 opacity-40" />}
                      </button>
                    </th>
                    <th className="px-5 py-3 text-left text-xs font-medium text-muted-foreground">
                      Status
                    </th>
                    <th className="px-5 py-3 text-left text-xs font-medium text-muted-foreground">
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
                        {emptyLogsMessage}
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
                      const rowBase = log.is_absent
                        ? 'bg-red-50/60 dark:bg-red-950/35 hover:bg-red-100/70 dark:hover:bg-red-950/50'
                        : log.is_late
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
                                      {formatEmpty(log.company_name)}
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
                                <span className="text-xs text-foreground truncate max-w-[140px]" title={formatEmpty(log.company_name)}>
                                  {formatEmpty(log.company_name)}
                                </span>
                              </div>
                            </td>
                            <td className={`${compact ? 'px-4 py-2' : 'px-5 py-3'} align-middle font-mono text-xs text-muted-foreground tabular-nums`}>
                              {log.time_in ? formatTime(log.time_in) : ''}
                            </td>
                            <td className={`${compact ? 'px-4 py-2' : 'px-5 py-3'} align-middle`}>
                              {log.is_absent ? (
                                <span className="inline-flex items-center rounded-full border border-red-400/60 bg-linear-to-r from-red-500/20 via-red-500/10 to-red-500/0 px-2.5 py-0.5 text-[11px] font-medium text-red-800 shadow-sm dark:border-red-500/70 dark:bg-red-500/20 dark:text-red-100">
                                  Absent
                                </span>
                              ) : log.time_in ? (
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
                                <span className="text-xs text-muted-foreground"></span>
                              )}
                            </td>
                            <td className={`${compact ? 'px-4 py-2' : 'px-5 py-3'} align-middle`}>
                              <span className="inline-flex items-center gap-1.5 rounded-full border border-border/60 bg-muted/40 dark:bg-white/6 px-2.5 py-1 text-[11px] font-medium shadow-sm">
                                {log.is_absent ? (
                                  <>
                                    <UserX className="size-3.5 text-red-600 dark:text-red-400" />
                                    <span className="text-red-700 dark:text-red-300">Absent</span>
                                  </>
                                ) : log.time_in && !log.time_out ? (
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
                              {log.time_out ? formatTime(log.time_out) : ''}
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
                                      {log.time_in ? formatTime(log.time_in) : ''}
                                    </span>
                                  </p>
                                  <p>
                                    Last clock-out:{' '}
                                    <span className="font-mono text-[11px] text-foreground">
                                      {log.time_out ? formatTime(log.time_out) : ''}
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
                                  {log.is_absent && (
                                    <p>
                                      Attendance status:{' '}
                                      <span className="font-medium text-red-600 dark:text-red-300">
                                        Absent
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
                    {logsStart}{logsEnd}
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
        .admin-dashboard-card {
          border: 1px solid var(--border);
          border-radius: 0.625rem;
          background: color-mix(in oklab, var(--card) 96%, transparent);
          box-shadow:
            0 1px 0 rgba(15, 23, 42, 0.03),
            0 12px 28px rgba(15, 23, 42, 0.055);
        }
        .admin-dashboard-card:hover {
          box-shadow:
            0 1px 0 rgba(15, 23, 42, 0.04),
            0 16px 34px rgba(15, 23, 42, 0.08);
        }
        .dark .admin-dashboard-card {
          background: color-mix(in oklab, var(--card) 92%, transparent);
          border-color: var(--border);
          box-shadow:
            0 1px 0 rgba(255, 255, 255, 0.03),
            0 18px 40px rgba(0, 0, 0, 0.3);
        }
        .dark .admin-dashboard-card:hover {
          box-shadow:
            0 1px 0 rgba(255, 255, 255, 0.04),
            0 22px 52px rgba(0, 0, 0, 0.38);
        }
        .admin-birthday-dashboard {
          background:
            linear-gradient(180deg, color-mix(in oklab, var(--card) 98%, transparent), color-mix(in oklab, var(--card) 94%, transparent));
        }
        .admin-birthday-stat {
          min-width: min(100%, 10rem);
          box-shadow: 0 12px 28px rgba(15, 23, 42, 0.045);
        }
        .admin-birthday-stat--active {
          box-shadow:
            inset 0 1px 0 rgba(255, 255, 255, 0.55),
            0 14px 34px rgba(249, 115, 22, 0.11);
        }
        .admin-birthday-tabs [data-slot='tabs-trigger']::after {
          display: none;
        }
        .admin-birthday-search {
          color-scheme: light;
        }
        .admin-birthday-person {
          background:
            linear-gradient(180deg, color-mix(in oklab, var(--background) 82%, var(--card) 18%), color-mix(in oklab, var(--card) 94%, transparent));
        }
        .admin-birthday-grid {
          scrollbar-width: thin;
          scrollbar-color: color-mix(in oklab, var(--brand) 46%, transparent) transparent;
        }
        .dark .admin-birthday-dashboard {
          background:
            linear-gradient(180deg, color-mix(in oklab, var(--card) 94%, transparent), color-mix(in oklab, var(--background) 82%, var(--card) 18%));
        }
        .dark .admin-birthday-stat,
        .dark .admin-birthday-search,
        .dark .admin-birthday-person {
          border-color: var(--border);
          background-color: color-mix(in oklab, var(--card) 88%, transparent);
          box-shadow:
            inset 0 1px 0 rgba(255, 255, 255, 0.035),
            0 16px 38px rgba(0, 0, 0, 0.22);
        }
        .dark .admin-birthday-stat--active {
          border-color: color-mix(in oklab, var(--brand) 38%, var(--border));
          background-color: color-mix(in oklab, var(--brand) 10%, var(--card));
        }
        .dark .admin-birthday-search {
          color-scheme: dark;
        }
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
                        <td className="py-2.5 px-2 text-muted-foreground">{emp.branch ?? ''}</td>
                        <td className="py-2.5 px-2 tabular-nums text-muted-foreground">
                          {emp.time_in ? formatTime(`2000-01-01T${emp.time_in}`) : ''}
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
                          {emp.notes ?? ''}
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
