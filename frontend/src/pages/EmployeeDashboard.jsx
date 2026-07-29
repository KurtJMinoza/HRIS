import { useState, useEffect, useMemo, useRef, useCallback } from 'react'
import { useNavigate } from 'react-router-dom'
import { motion as Motion } from 'framer-motion'
import { Clock, FileCheck, User, ScanLine, ArrowUpRight, ArrowDownRight, ArrowUpDown, Minus, ScanFace, ChevronLeft, ChevronRight, Timer, X, ListTree, CalendarDays, Zap, Info, FileText, Search, SlidersHorizontal, MoreVertical } from 'lucide-react'
import { PieChart, Pie, Cell, ResponsiveContainer } from 'recharts'
import { Card, CardContent, CardDescription, CardHeader, CardTitle } from '@/components/ui/card'
import { Badge } from '@/components/ui/badge'
import { Button } from '@/components/ui/button'
import { FaceVerificationLiveness } from '@/components/FaceVerificationLiveness'
import {
  Dialog,
  DialogContent,
  DialogDescription,
  DialogHeader,
  DialogTitle,
} from '@/components/ui/dialog'
import { useAuth } from '@/contexts/AuthContext'
import { useHrBasePath } from '@/contexts/useHrBasePath'
import { employeeSelfHref } from '@/lib/hrRoutes'
import {
  getEmployeeDashboardAttendanceCalendar,
  getEmployeeDashboardSummary,
  getEmployeeDashboardPerformanceKpi,
  getEmployeeEvaluationWidget,
  getMyHolidays,
} from '@/api'
import { formatClockTimeDisplay, formatHHmmTo12h, formatScheduleLabel12h, toHhMm } from '@/lib/timeFormat'
import { cn } from '@/lib/utils'
import { formatEmployeeName } from '@/lib/employeeSort'
import {
  buildEmployeeCorrectionHref,
  calendarIncompleteBadge,
  isIncompleteAttendanceRecord,
  shouldOfferCorrection,
} from '@/components/attendance/attendanceRecordUtils'
import { useDismissOnRouteChange } from '@/hooks/useDismissOnRouteChange'
import { navigateAfterOverlayDismiss } from '@/lib/radixModalLock'
import { Select, SelectContent, SelectItem, SelectTrigger, SelectValue } from '@/components/ui/select'

const DEFAULT_CALENDAR_VALUE = null

const MONTHS = [
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
const WEEKDAYS = ['SUN', 'MON', 'TUE', 'WED', 'THU', 'FRI', 'SAT']

/** JS Date#getDay(): 0=Sunday … 6=Saturday → backend schedule keys (matches AttendanceController). */
const DAY_KEYS_FROM_JS_WEEKDAY = ['sun', 'mon', 'tue', 'wed', 'thu', 'fri', 'sat']

/** Same 6×7 grid algorithm as Admin → Holidays calendar. */
function getCalendarCells(year, month) {
  const first = new Date(year, month, 1)
  const last = new Date(year, month + 1, 0)
  const startPad = first.getDay()
  const daysInMonth = last.getDate()
  const prevMonth = month === 0 ? 11 : month - 1
  const prevYear = month === 0 ? year - 1 : year
  const prevLast = new Date(prevYear, prevMonth + 1, 0).getDate()

  const cells = []
  for (let i = 0; i < startPad; i++) {
    const d = prevLast - startPad + 1 + i
    const dateStr = `${prevYear}-${String(prevMonth + 1).padStart(2, '0')}-${String(d).padStart(2, '0')}`
    cells.push({ day: d, month: prevMonth, year: prevYear, dateStr, isAdjacent: true })
  }
  for (let d = 1; d <= daysInMonth; d++) {
    const dateStr = `${year}-${String(month + 1).padStart(2, '0')}-${String(d).padStart(2, '0')}`
    cells.push({ day: d, month, year, dateStr, isAdjacent: false })
  }
  const remaining = 42 - cells.length
  for (let i = 0; i < remaining; i++) {
    const d = i + 1
    const nextMonth = month === 11 ? 0 : month + 1
    const nextYear = month === 11 ? year + 1 : year
    const dateStr = `${nextYear}-${String(nextMonth + 1).padStart(2, '0')}-${String(d).padStart(2, '0')}`
    cells.push({ day: d, month: nextMonth, year: nextYear, dateStr, isAdjacent: true })
  }
  return cells
}

/**
 * Local calendar date as YYYY-MM-DD. Avoid `toISOString().slice(0, 10)` — that uses UTC
 * and shifts the day for timezones ahead of UTC (e.g. Philippines), breaking API keys and calendar tiles.
 */
function formatLocalDateKey(date) {
  if (!date || !(date instanceof Date) || Number.isNaN(date.getTime())) return ''
  const y = date.getFullYear()
  const m = String(date.getMonth() + 1).padStart(2, '0')
  const day = String(date.getDate()).padStart(2, '0')
  return `${y}-${m}-${day}`
}

function roundHours1(n) {
  const x = typeof n === 'number' && Number.isFinite(n) ? n : 0
  return Math.round(x * 10) / 10
}

/** "6:00 AM - 8:00 AM" from API H:i strings. */
function formatHhMmRange12h(startHm, endHm) {
  const a = formatHHmmTo12h(toHhMm(startHm))
  const b = formatHHmmTo12h(toHhMm(endHm))
  if (!a || !b) return null
  return `${a} - ${b}`
}

/** Unfiled clock OT: pre/post segments in 12h + hours; mirrors Admin Reports windows. */
function formatUnfiledOtClockSummary12h(preSeg, postSeg, totalHours) {
  const parts = []
  if (preSeg?.start && preSeg?.end) {
    const range = formatHhMmRange12h(preSeg.start, preSeg.end)
    const h = typeof preSeg.hours === 'number' ? preSeg.hours : roundHours1((preSeg.minutes || 0) / 60)
    if (range) parts.push(`${range} (${roundHours1(h)}h)`)
  }
  if (postSeg?.start && postSeg?.end) {
    const range = formatHhMmRange12h(postSeg.start, postSeg.end)
    const h = typeof postSeg.hours === 'number' ? postSeg.hours : roundHours1((postSeg.minutes || 0) / 60)
    if (range) parts.push(`${range} (${roundHours1(h)}h)`)
  }
  if (!parts.length) return null
  if (parts.length === 1) return parts[0]
  return `${parts.join(' + ')} = ${roundHours1(totalHours)}h`
}

function formatOtRequestRange12h(startRaw, endRaw, hours) {
  const range = formatHhMmRange12h(toHhMm(startRaw || ''), toHhMm(endRaw || ''))
  if (!range) return null
  return `${range} (${roundHours1(hours)}h)`
}

function timeToMinutes(t) {
  if (!t) return null
  const s = String(t).trim()
  const m = s.match(/^(\d{1,2}):(\d{2})/)
  if (!m) return null
  return parseInt(m[1], 10) * 60 + parseInt(m[2], 10)
}

function segmentCoveredByRequest(seg, request) {
  if (!seg?.start || !seg?.end || !request) return false
  const segStart = timeToMinutes(seg.start)
  const reqStart = timeToMinutes(request.start_time || request.schedule_end)
  const reqEnd = timeToMinutes(request.end_time || request.expected_end_time)
  if (segStart == null || reqStart == null || reqEnd == null) return false
  const segEnd = timeToMinutes(seg.end)
  if (segEnd == null) return false
  const overlapStart = Math.max(segStart, reqStart)
  const overlapEnd = Math.min(segEnd, reqEnd)
  const overlap = overlapEnd - overlapStart
  const segDuration = segEnd - segStart
  return segDuration > 0 && overlap >= segDuration * 0.5
}

function formatYmdShort(dateStr) {
  if (!dateStr) return '—'
  try {
    const d = new Date(`${dateStr}T12:00:00`)
    if (Number.isNaN(d.getTime())) return String(dateStr)
    return d.toLocaleDateString('en-PH', { weekday: 'short', month: 'short', day: 'numeric', year: 'numeric' })
  } catch {
    return String(dateStr)
  }
}

function isAttendanceSummarySkipDay(day) {
  if (!day?.date) return true
  const status = String(day.status || '').toLowerCase()
  if (status === 'upcoming') return true
  if (day.is_rest_day || status === 'rest' || status === 'rest_day') return true
  if (status === 'leave' || status === 'holiday') return true
  return false
}

function isScheduledWorkDay(day) {
  return !isAttendanceSummarySkipDay(day)
}

/** Daily breakdown / efficiency table: regular scheduled days plus rest days actually worked. */
function isAttendanceEfficiencyDetailDay(day) {
  if (!day?.date) return false
  if (day.is_rest_day_worked) return true
  return isScheduledWorkDay(day)
}

function formatAttendanceMetricPercent(count, base) {
  if (!base || base <= 0) return count > 0 ? '100.00' : '0.00'
  return ((count / base) * 100).toFixed(2)
}

const ATTENDANCE_SUMMARY_SLICE_META = {
  present: { label: 'Present', color: '#22c55e' },
  absent: { label: 'Absent', color: '#ef4444' },
  late: { label: 'Late', color: '#f97316' },
  undertime: { label: 'Undertime', color: '#eab308' },
  efficiency: { label: 'Efficiency', color: '#8b5cf6' },
}

function efficiencyBadgeClass(pct) {
  if (pct == null || typeof pct !== 'number') return 'bg-gray-100 text-gray-700 border-gray-300'
  if (pct >= 98) return 'bg-emerald-100 text-emerald-800 border-emerald-400'
  if (pct >= 95) return 'bg-green-100 text-green-800 border-green-400'
  if (pct >= 90) return 'bg-amber-100 text-amber-800 border-amber-400'
  if (pct >= 85) return 'bg-orange-100 text-orange-800 border-orange-400'
  return 'bg-red-100 text-red-800 border-red-400'
}

function efficiencyLabel(pct) {
  if (pct == null || typeof pct !== 'number') return 'N/A'
  if (pct >= 98) return 'Outstanding'
  if (pct >= 95) return 'Excellent'
  if (pct >= 90) return 'Very Good'
  if (pct >= 85) return 'Good'
  return 'Needs Improvement'
}

const ATTENDANCE_SUMMARY_STATUS_STYLES = {
  present: 'border-emerald-200 bg-emerald-50 text-emerald-700 dark:border-emerald-500/25 dark:bg-emerald-500/10 dark:text-emerald-300',
  absent: 'border-red-200 bg-red-50 text-red-700 dark:border-red-500/25 dark:bg-red-500/10 dark:text-red-300',
  late: 'border-orange-200 bg-orange-50 text-orange-700 dark:border-orange-500/25 dark:bg-orange-500/10 dark:text-orange-300',
  undertime: 'border-amber-200 bg-amber-50 text-amber-700 dark:border-amber-500/25 dark:bg-amber-500/10 dark:text-amber-300',
}

function attendanceSummaryStatusKey(day) {
  const status = String(day?.status || day?.status_label || '').toLowerCase()
  const lateMinutes = Number(day?.late_minutes || 0)
  const undertimeMinutes = Number(day?.undertime_minutes || 0)
  if (status.includes('absent') || status === 'awol') return 'absent'
  if (lateMinutes > 0 || status.includes('late')) return 'late'
  if (undertimeMinutes > 0 || status.includes('undertime')) return 'undertime'
  return 'present'
}

function attendanceSummaryStatusLabel(day) {
  if (day?.is_rest_day_worked) return 'Rest Day Worked'
  const key = attendanceSummaryStatusKey(day)
  return ATTENDANCE_SUMMARY_SLICE_META[key]?.label || 'Present'
}

function compactPaginationPages(currentPage, pageCount) {
  if (pageCount <= 5) return Array.from({ length: pageCount }, (_, index) => index + 1)
  return [...new Set([1, currentPage - 1, currentPage, currentPage + 1, pageCount])]
    .filter((page) => page >= 1 && page <= pageCount)
    .sort((a, b) => a - b)
}

const ATTENDANCE_MONTH_LOOKBACK = 24

function calendarMonthSelectKey(year, monthIndex) {
  return `${year}-${String(monthIndex + 1).padStart(2, '0')}`
}

function parseCalendarMonthSelectKey(key) {
  const [yearRaw, monthRaw] = String(key || '').split('-')
  return { year: Number(yearRaw), monthIndex: Number(monthRaw) - 1 }
}

function buildAttendanceMonthOptions() {
  const now = new Date()
  const options = []
  let year = now.getFullYear()
  let monthIndex = now.getMonth()
  for (let i = 0; i <= ATTENDANCE_MONTH_LOOKBACK; i += 1) {
    const date = new Date(year, monthIndex, 1)
    options.push({
      value: calendarMonthSelectKey(year, monthIndex),
      label: date.toLocaleDateString('en-PH', { month: 'long', year: 'numeric' }),
      isCurrent: i === 0,
    })
    monthIndex -= 1
    if (monthIndex < 0) {
      monthIndex = 11
      year -= 1
    }
  }
  return options
}

function formatHolidayCardDate(dateStr) {
  if (!dateStr) return { month: '—', day: '—', weekday: '—' }
  try {
    const d = new Date(`${dateStr}T12:00:00`)
    if (Number.isNaN(d.getTime())) return { month: '—', day: String(dateStr).slice(-2) || '—', weekday: '—' }
    return {
      month: d.toLocaleDateString('en-PH', { month: 'short' }).toUpperCase(),
      day: String(d.getDate()).padStart(2, '0'),
      weekday: d.toLocaleDateString('en-PH', { weekday: 'long' }),
    }
  } catch {
    return { month: '—', day: String(dateStr).slice(-2) || '—', weekday: '—' }
  }
}

function calendarStatusBadge(record, fallback) {
  if (!record) return fallback
  return record.display_badge || record.status_label || fallback
}

function calendarLateBadge(record) {
  const lateM = typeof record?.late_minutes === 'number' ? record.late_minutes : 0
  const lateLbl = String(record?.late_label || '').trim()
  if (lateLbl && !/^present$/i.test(lateLbl)) return lateLbl
  if (lateM > 0) return `${lateM} min late`
  return 'Late'
}

function holidayTypeDisplay(type) {
  const key = String(type || '').toLowerCase()
  if (key === 'regular') return 'Regular Holiday'
  if (key === 'special' || key === 'special_non_working') return 'Special (Non-working) Day'
  if (key === 'special_working') return 'Special Working Day'
  if (key === 'company') return 'Local Holiday'
  return 'Holiday'
}

/**
 * Maps API day row → calendar tile + badge (handles `clocked_in`, `incomplete`, leave, rest).
 * Backend sets status to `clocked_in` while still on shift; late/undertime flags stay on the row.
 */
function getCalendarDayVisual(record, dateKey, ctx) {
  const { scheduleAssigned, todayKey, isRestDay, isPastAbsentCutoff, isAdjacent } = ctx

  /** Neutral frame + soft tint; status is plain text (no pill chrome). */
  const baseGridCell =
    'touch-manipulation group relative flex h-full min-h-[4.75rem] w-full min-w-0 max-w-full flex-col rounded-lg border border-border bg-card p-1.5 text-left shadow-[0_8px_18px_-18px_rgba(15,23,42,0.7)] @sm:min-h-[5.25rem] @sm:p-2.5 transition-colors duration-150 ease-out focus:outline-none focus-visible:ring-2 focus-visible:ring-orange-300 focus-visible:ring-offset-1 ring-offset-background hover:border-border/80 hover:bg-muted/35 active:scale-[0.995] dark:bg-card/80'

  /** Plain label: color only, no borders or badge backgrounds. */
  const L = {
    ink: 'block max-w-full text-[10px] font-semibold leading-snug tracking-tight line-clamp-2 @sm:text-xs @sm:font-medium',
    muted: 'text-muted-foreground',
    emerald: 'text-emerald-700 dark:text-emerald-400',
    amber: 'text-amber-800 dark:text-amber-300',
    red: 'text-red-700 dark:text-red-400',
    blue: 'text-blue-700 dark:text-blue-400',
    slate: 'text-slate-600 dark:text-slate-400',
    sky: 'text-sky-700 dark:text-sky-400',
    orange: 'text-orange-800 dark:text-orange-300',
  }

  const tint = {
    base: 'bg-card dark:bg-card/80',
    muted: 'bg-muted/25 dark:bg-muted/30',
    emerald: 'bg-emerald-50 dark:bg-emerald-500/12',
    amber: 'bg-amber-50 dark:bg-amber-500/12',
    red: 'bg-red-50 dark:bg-red-500/12',
    blue: 'bg-blue-50 dark:bg-blue-500/12',
    slate: 'bg-slate-50 dark:bg-muted/35',
    sky: 'bg-sky-50 dark:bg-sky-500/12',
    orange: 'bg-orange-50 dark:bg-orange-500/12',
  }

  const empty = {
    badge: '',
    tileClass: `${baseGridCell} ${tint.base} text-foreground`,
    badgeClass: '',
  }

  if (dateKey === todayKey && scheduleAssigned === false) {
    return {
      badge: 'No schedule',
      tileClass: `${baseGridCell} ${tint.amber}`,
      badgeClass: `${L.ink} ${L.amber}`,
    }
  }

  if (isAdjacent && !record) {
    return {
      badge: '',
      tileClass: `${baseGridCell} ${tint.muted} text-muted-foreground`,
      badgeClass: '',
    }
  }

  if (!record) {
    return empty
  }

  const status = record.status
  const lateM = typeof record.late_minutes === 'number' ? record.late_minutes : 0
  const lateLbl = String(record.late_label || '').trim()

  if (status === 'leave') {
    return {
      badge: 'Leave',
      tileClass: `${baseGridCell} ${tint.blue}`,
      badgeClass: `${L.ink} ${L.blue}`,
    }
  }

  if (status === 'holiday' || record.is_holiday) {
    const holidayName = String(record.holiday_name || record.status_label || '').trim()
    const badge = holidayName && holidayName.length <= 18 ? holidayName : 'Holiday'
    return {
      badge,
      tileClass: `${baseGridCell} ${tint.sky}`,
      badgeClass: `${L.ink} ${L.sky}`,
    }
  }

  if (status === 'rest' || status === 'rest_day' || status === 'no_schedule_rest') {
    return {
      badge: 'Rest Day',
      tileClass: `${baseGridCell} ${tint.slate}`,
      badgeClass: `${L.ink} ${L.slate}`,
    }
  }

  if (isRestDay(dateKey) && (status === 'absent' || status === '—')) {
    return {
      badge: 'Rest Day',
      tileClass: `${baseGridCell} ${tint.slate}`,
      badgeClass: `${L.ink} ${L.slate}`,
    }
  }

  if (isIncompleteAttendanceRecord(record)) {
    return {
      badge: calendarIncompleteBadge(record),
      tileClass: `${baseGridCell} ${tint.amber}`,
      badgeClass: `${L.ink} ${L.amber}`,
    }
  }

  if (status === 'clocked_in') {
    const isLate = lateM > 0 || /^late$/i.test(lateLbl)
    if (isLate) {
      return {
        badge: calendarLateBadge(record),
        tileClass: `${baseGridCell} ${tint.amber}`,
        badgeClass: `${L.ink} ${L.amber}`,
      }
    }
    return {
      badge: 'Present',
      tileClass: `${baseGridCell} ${tint.emerald}`,
      badgeClass: `${L.ink} ${L.emerald}`,
    }
  }

  if (status === 'late') {
    return {
      badge: calendarLateBadge(record),
      tileClass: `${baseGridCell} ${tint.amber}`,
      badgeClass: `${L.ink} ${L.amber}`,
    }
  }

  if (status === 'present' || status === 'present_with_ot') {
    return {
      badge: calendarStatusBadge(record, status === 'present_with_ot' ? 'Present w/ OT' : 'Present'),
      tileClass: `${baseGridCell} ${tint.emerald}`,
      badgeClass: `${L.ink} ${L.emerald}`,
    }
  }

  if (status === 'absent') {
    if (dateKey === todayKey && !isPastAbsentCutoff()) {
      return {
        badge: 'Pending',
        tileClass: `${baseGridCell} ${tint.amber}`,
        badgeClass: `${L.ink} ${L.amber}`,
      }
    }
    return {
      badge: 'Absent',
      tileClass: `${baseGridCell} ${tint.red}`,
      badgeClass: `${L.ink} ${L.red}`,
    }
  }

  if (status === 'halfday') {
    return {
      badge: 'Half day',
      tileClass: `${baseGridCell} ${tint.sky}`,
      badgeClass: `${L.ink} ${L.sky}`,
    }
  }

  if (status === 'undertime') {
    return {
      badge: calendarStatusBadge(record, 'Undertime'),
      tileClass: `${baseGridCell} ${tint.orange}`,
      badgeClass: `${L.ink} ${L.orange}`,
    }
  }

  if (status === 'incomplete') {
    return {
      badge: 'Incomplete',
      tileClass: `${baseGridCell} ${tint.amber}`,
      badgeClass: `${L.ink} ${L.amber}`,
    }
  }

  if (!status || status === '—') {
    if (isRestDay(dateKey)) {
      return {
        badge: 'Rest Day',
        tileClass: `${baseGridCell} ${tint.slate}`,
        badgeClass: `${L.ink} ${L.slate}`,
      }
    }
    return empty
  }

  return empty
}

const containerVariants = {
  hidden: { opacity: 0 },
  visible: {
    opacity: 1,
    transition: { staggerChildren: 0.06, delayChildren: 0.05 },
  },
}
const itemVariants = {
  hidden: { opacity: 0, y: 14 },
  visible: { opacity: 1, y: 0 },
}
const scrollViewport = { once: true, amount: 0.12 }
const scrollRevealTransition = { duration: 0.5, ease: [0.25, 0.1, 0.25, 1] }

function AnimatedNumber({ value, duration = 2400 }) {
  const [display, setDisplay] = useState(0)
  const previousRef = useRef(0)

  useEffect(() => {
    const end = typeof value === 'number' && Number.isFinite(value) ? value : 0
    const start = previousRef.current
    if (start === end) return

    let frame
    const startTime = performance.now()
    const run = (now) => {
      const elapsed = now - startTime
      const t = Math.min(1, elapsed / duration)
      const eased = 1 - Math.pow(1 - t, 3)
      const next = start + (end - start) * eased
      setDisplay(next)
      if (t < 1) {
        frame = requestAnimationFrame(run)
      } else {
        previousRef.current = end
      }
    }
    frame = requestAnimationFrame(run)
    return () => {
      if (frame) cancelAnimationFrame(frame)
    }
  }, [value, duration])

  const rounded = Number.isInteger(value) ? Math.round(display) : Number(display.toFixed(2))
  return <span className="tabular-nums">{rounded}</span>
}

function LiveClock() {
  const [now, setNow] = useState(() => new Date())
  useEffect(() => {
    const t = setInterval(() => setNow(new Date()), 1000)
    return () => clearInterval(t)
  }, [])
  const time = now.toLocaleTimeString('en-PH', { hour: '2-digit', minute: '2-digit', second: '2-digit', hour12: true })
  const date = now.toLocaleDateString('en-PH', { weekday: 'long', month: 'short', day: 'numeric', year: 'numeric' })
  return (
    <div className="w-full shrink-0 rounded-xl border border-border bg-card px-4 py-3 shadow-sm @sm:px-5 @sm:py-4 @lg:w-auto dark:bg-card/85">
      <p className="flex min-w-0 flex-wrap items-baseline gap-2 text-2xl font-extrabold tabular-nums tracking-tight text-foreground @sm:flex-nowrap @sm:text-3xl @md:text-4xl @lg:text-5xl">
        {time}
        <span
          className="inline-flex h-2 w-2 shrink-0 rounded-full bg-emerald-500 shadow-[0_0_0_4px_rgba(16,185,129,0.25)] animate-pulse @md:h-2.5 @md:w-2.5"
          aria-hidden
        />
      </p>
      <p className="mt-1 text-xs font-medium text-muted-foreground @sm:text-sm @md:text-base">{date}</p>
    </div>
  )
}

export default function EmployeeDashboard() {
  const navigate = useNavigate()
  const hrBase = useHrBasePath()
  const goSelf = useCallback(
    (employeePath) => navigate(employeeSelfHref(hrBase, employeePath)),
    [hrBase, navigate],
  )
  const { user, refreshUser } = useAuth()
  const employeeDisplayName = formatEmployeeName(user, 'there')

  const [summary, setSummary] = useState(null)
  const [days, setDays] = useState([])
  const [evaluationWidget, setEvaluationWidget] = useState(null)
  const [evaluationLoading, setEvaluationLoading] = useState(true)
  const [holidayRows, setHolidayRows] = useState([])
  const [holidaySummary, setHolidaySummary] = useState(null)
  const [holidayLoading, setHolidayLoading] = useState(true)
  const [loading, setLoading] = useState(true)
  const [calendarLoading, setCalendarLoading] = useState(true)
  const [summaryReady, setSummaryReady] = useState(false)
  const [error, setError] = useState(null)
  const [prevSummary, setPrevSummary] = useState(null)
  const [monthOtRequests, setMonthOtRequests] = useState([])
  const [absentCounts, setAbsentCounts] = useState({ absent: 0, leave: 0, holiday: 0 })
  const [attendanceSummaryModalOpen, setAttendanceSummaryModalOpen] = useState(false)
  const [evaluationDetailsOpen, setEvaluationDetailsOpen] = useState(false)
  const [attendanceSummarySearch, setAttendanceSummarySearch] = useState('')
  const [attendanceSummaryFilter, setAttendanceSummaryFilter] = useState('all')
  const [attendanceSummaryPage, setAttendanceSummaryPage] = useState(1)
  const [otDetailsOpen, setOtDetailsOpen] = useState(false)
  const [otNoticeDismissed, setOtNoticeDismissed] = useState(false)
  const [faceAttendanceOpen, setFaceAttendanceOpen] = useState(false)
  const [faceAttendanceType, setFaceAttendanceType] = useState('clock_in')
  const [selectedDay, setSelectedDay] = useState(DEFAULT_CALENDAR_VALUE)
  const [calendarYear, setCalendarYear] = useState(() => new Date().getFullYear())
  const [calendarMonth, setCalendarMonth] = useState(() => new Date().getMonth())
  const summaryAbortRef = useRef(null)
  const calendarAbortRef = useRef(null)
  const evaluationAbortRef = useRef(null)
  const evaluationWidgetRef = useRef(null)
  const calendarCacheRef = useRef(new Map())

  const mergeSummary = useCallback((next) => {
    setSummary((current) => ({
      ...(current || {}),
      ...(next || {}),
      today: next?.today ?? current?.today,
      pending_requests: next?.pending_requests ?? current?.pending_requests,
      upcoming_payroll: next?.upcoming_payroll ?? current?.upcoming_payroll,
      latest_payslip: next?.latest_payslip ?? current?.latest_payslip,
      pending_schedule_change: Object.prototype.hasOwnProperty.call(next || {}, 'pending_schedule_change')
        ? next.pending_schedule_change
        : current?.pending_schedule_change,
    }))
  }, [])

  const loadDashboardSummary = useCallback(async (opts = {}) => {
    summaryAbortRef.current?.abort()
    const controller = new AbortController()
    summaryAbortRef.current = controller
    const soft = opts.soft === true
    if (!soft) {
      setLoading(true)
      setError(null)
    }
    try {
      const data = await getEmployeeDashboardSummary({ signal: controller.signal })
      mergeSummary(data)
      setError(null)
    } catch (e) {
      if (controller.signal.aborted) return
      if (!soft) setError(e.message)
    } finally {
      if (summaryAbortRef.current === controller) {
        summaryAbortRef.current = null
      }
      if (!soft) {
        setLoading(false)
        setSummaryReady(true)
      }
    }
  }, [mergeSummary])

  const prefetchAdjacentMonths = useCallback(async (year, month) => {
    const adjacents = [
      { y: month === 0 ? year - 1 : year, m: month === 0 ? 11 : month - 1 },
      { y: month === 11 ? year + 1 : year, m: month === 11 ? 0 : month + 1 },
    ]
    for (const { y, m } of adjacents) {
      const mk = calendarMonthSelectKey(y, m)
      if (calendarCacheRef.current.has(mk)) continue
      try {
        const data = await getEmployeeDashboardAttendanceCalendar({ month: mk })
        if (data?.meta?.schema_version === 17) {
          calendarCacheRef.current.set(mk, data)
        }
      } catch {
        // Silently ignore pre-fetch errors
      }
    }
  }, [])

  const loadAttendanceCalendar = useCallback(async (year, month) => {
    const monthKey = calendarMonthSelectKey(year, month)
    calendarAbortRef.current?.abort()
    const cached = calendarCacheRef.current.get(monthKey)
    const cacheValid = cached && cached?.meta?.schema_version === 17

    if (cacheValid) {
      setDays(Array.isArray(cached.days) ? cached.days : [])
      mergeSummary({
        ...(cached.summary || {}),
        from_date: `${monthKey}-01`,
        schedule_assigned: cached.schedule_assigned,
      })
      setAbsentCounts(cached.absent_counts || { absent: 0, leave: 0, holiday: 0 })
      setMonthOtRequests(Array.isArray(cached.overtime_requests) ? cached.overtime_requests : [])
      setPrevSummary(null)
      setCalendarLoading(false)
    } else {
      setCalendarLoading(true)
    }

    const controller = new AbortController()
    calendarAbortRef.current = controller
    try {
      const data = await getEmployeeDashboardAttendanceCalendar({ month: monthKey, signal: controller.signal })
      calendarCacheRef.current.set(monthKey, data)
      setDays(Array.isArray(data.days) ? data.days : [])
      mergeSummary({
        ...(data.summary || {}),
        from_date: `${monthKey}-01`,
        schedule_assigned: data.schedule_assigned,
      })
      setAbsentCounts(data.absent_counts || { absent: 0, leave: 0, holiday: 0 })
      setMonthOtRequests(Array.isArray(data.overtime_requests) ? data.overtime_requests : [])
      setPrevSummary(null)
      setError(null)
      void prefetchAdjacentMonths(year, month)
    } catch (e) {
      if (controller.signal.aborted) return
      if (!cacheValid) {
        setDays([])
        setAbsentCounts({ absent: 0, leave: 0, holiday: 0 })
        setMonthOtRequests([])
      }
      setError(e.message)
    } finally {
      if (calendarAbortRef.current === controller) {
        calendarAbortRef.current = null
        setCalendarLoading(false)
      }
    }
  }, [mergeSummary, prefetchAdjacentMonths])

  const loadEvaluationWidget = useCallback(async () => {
    evaluationAbortRef.current?.abort()
    const controller = new AbortController()
    evaluationAbortRef.current = controller
    const monthKey = calendarMonthSelectKey(calendarYear, calendarMonth)
    // Only block the Performance card on the first load. Soft-refresh on month
    // change so Select/Dialog portals are not unmounted mid-close (removeChild).
    setEvaluationLoading((prev) => (evaluationWidgetRef.current == null ? true : prev))
    try {
      const [evalData, kpiData] = await Promise.all([
        getEmployeeEvaluationWidget({ month: monthKey, signal: controller.signal }).catch(() => ({ widget: null })),
        getEmployeeDashboardPerformanceKpi({ month: monthKey, signal: controller.signal }).catch(() => ({ performance: null })),
      ])
      if (controller.signal.aborted) return
      const widget = evalData?.widget && typeof evalData.widget === 'object' ? { ...evalData.widget } : {}
      // Prefer dedicated KPI endpoint (no evaluations.view required); fall back to widget payload.
      widget.performance = kpiData?.performance ?? widget.performance ?? null
      const hasEvalModule = widget.evaluation || widget.stats || (Array.isArray(widget.history) && widget.history.length > 0)
      const next = widget.performance || hasEvalModule ? widget : null
      evaluationWidgetRef.current = next
      setEvaluationWidget(next)
    } catch {
      if (controller.signal.aborted) return
      evaluationWidgetRef.current = null
      setEvaluationWidget(null)
    } finally {
      if (evaluationAbortRef.current === controller) {
        evaluationAbortRef.current = null
        setEvaluationLoading(false)
      }
    }
  }, [calendarMonth, calendarYear])

  useEffect(() => {
    const id = window.setTimeout(() => {
      void (async () => {
        await loadDashboardSummary()
        void loadEvaluationWidget()
      })()
    }, 80)
    return () => {
      window.clearTimeout(id)
      summaryAbortRef.current?.abort()
      evaluationAbortRef.current?.abort()
    }
  }, [loadDashboardSummary, loadEvaluationWidget])

  useEffect(() => {
    if (!summaryReady) return undefined
    void loadAttendanceCalendar(calendarYear, calendarMonth)
    return () => {
      calendarAbortRef.current?.abort()
    }
  }, [calendarYear, calendarMonth, loadAttendanceCalendar, summaryReady])

  useEffect(() => {
    const onVis = () => {
      if (document.visibilityState === 'visible') void loadDashboardSummary({ soft: true })
    }
    document.addEventListener('visibilitychange', onVis)
    const id = window.setInterval(() => void loadDashboardSummary({ soft: true }), 60_000)
    return () => {
      document.removeEventListener('visibilitychange', onVis)
      window.clearInterval(id)
    }
  }, [loadDashboardSummary])

  useEffect(() => {
    let cancelled = false
    setHolidayLoading(true)
    getMyHolidays({ year: calendarYear })
      .then((data) => {
        if (cancelled) return
        setHolidayRows(Array.isArray(data?.holidays) ? data.holidays : [])
        setHolidaySummary(data?.summary || null)
      })
      .catch(() => {
        if (cancelled) return
        setHolidayRows([])
        setHolidaySummary(null)
      })
      .finally(() => {
        if (!cancelled) setHolidayLoading(false)
      })

    return () => {
      cancelled = true
    }
  }, [calendarYear])

  useEffect(() => {
    const onSchedulesChanged = () => {
      void refreshUser?.()
      calendarCacheRef.current.clear()
      void loadDashboardSummary({ soft: true })
      void loadAttendanceCalendar(calendarYear, calendarMonth, { force: true })
    }
    window.addEventListener('hr:schedules-changed', onSchedulesChanged)
    return () => window.removeEventListener('hr:schedules-changed', onSchedulesChanged)
  }, [calendarMonth, calendarYear, loadAttendanceCalendar, loadDashboardSummary, refreshUser])

  /** Prefer server-computed rest day from /attendance/summary; fallback to schedule template / legacy JSON. */
  const restDayByDate = useMemo(() => {
    const map = {}
    if (Array.isArray(days)) {
      for (const row of days) {
        if (row?.date && typeof row.is_rest_day === 'boolean') {
          map[row.date] = row.is_rest_day
        }
      }
    }
    return map
  }, [days])

  /** Absent cutoff: 5 PM (17:00) — only show "Absent" after shift end. Before that, use softer messaging. */
  const ABSENT_CUTOFF_HOUR = 17

  function isPastAbsentCutoff() {
    const now = new Date()
    const hour = now.getHours()
    const minute = now.getMinutes()
    return hour > ABSENT_CUTOFF_HOUR || (hour === ABSENT_CUTOFF_HOUR && minute >= 0)
  }

  const isRestDay = useCallback(
    (dateKey) => {
      if (!dateKey) return false
      if (Object.prototype.hasOwnProperty.call(restDayByDate, dateKey)) {
        return restDayByDate[dateKey]
      }
      const hasTemplateRest = Array.isArray(user?.working_schedule_rest_days)
      const hasPerDay = user?.schedule_per_day && typeof user.schedule_per_day === 'object'
      if (hasTemplateRest || hasPerDay) {
        const d = new Date(`${dateKey}T12:00:00`)
        const key = DAY_KEYS_FROM_JS_WEEKDAY[d.getDay()]
        if (hasTemplateRest && user.working_schedule_rest_days.includes(key)) return true
        if (hasPerDay) {
          const row = user.schedule_per_day[key]
          if (row === null || row === undefined) return true
          if (typeof row === 'object' && row !== null && !String(row.in || '').trim()) return true
        }
        return false
      }
      const d = new Date(`${dateKey}T12:00:00`)
      return d.getDay() === 0
    },
    [restDayByDate, user]
  )

  function formatTodayStatus() {
    if (summary?.schedule_assigned === false) return 'No schedule assigned'
    const t = summary?.today
    const status = t?.status
    const todayKey = formatLocalDateKey(new Date())
    const timeIn = t?.time_in
    const timeOut = t?.time_out
    const lateLabel = t?.late_label

    if (!status) return '—'
    if (t.presence_issue === 'incomplete_pair' && t.presence_label) return t.presence_label
    if (t.presence_issue === 'correction_pending' && t.presence_label) return t.presence_label
    if (status === 'leave') return 'On leave'
    if (status === 'rest' || status === 'rest_day' || status === 'no_schedule_rest') return 'Rest Day'
    if (status === 'late') return timeIn && !timeOut ? 'Working' : (lateLabel || 'Late')
    if (status === 'halfday') return timeIn && !timeOut ? 'Working' : 'Half Day'
    if (status === 'absent') {
      if (isRestDay(todayKey)) return 'Rest Day'
      return isPastAbsentCutoff() ? 'Missed clock-in' : 'Not started'
    }
    if (status === 'present') return timeIn && !timeOut ? 'Working' : 'Present'
    if (status === 'clocked_in') {
      if (timeIn && !timeOut) {
        const lm = typeof t?.late_minutes === 'number' ? t.late_minutes : 0
        if (lm > 0) return lateLabel || 'Working (late)'
        return lateLabel || 'Working'
      }
      return lateLabel || 'Clocked in'
    }
    if (status === 'undertime') return 'Undertime'
    if (status === 'incomplete') return 'Incomplete'
    if (status === '—' && isRestDay(todayKey)) return 'Rest Day'
    return status
  }

  function formatDurationMinutes(total) {
    if (typeof total !== 'number' || total <= 0) return null
    const hours = Math.floor(total / 60)
    const minutes = total % 60
    if (hours && minutes) return `${hours}h ${minutes}m`
    if (hours) return `${hours}h`
    return `${minutes}m`
  }

  function formatTodayContext() {
    if (summary?.schedule_assigned === false) return 'Contact HR or your administrator to get assigned a schedule.'
    const t = summary?.today
    if (!t) return ''

    const timeInLabel = t.time_in ? formatTime(t.time_in) : null
    const timeOutLabel = t.time_out ? formatTime(t.time_out) : null
    const late = typeof t.late_minutes === 'number' ? t.late_minutes : null
    const undertime = typeof t.undertime_minutes === 'number' ? t.undertime_minutes : null

    if (t.status === 'halfday') {
      const parts = []
      if (timeOutLabel) parts.push(`Clocked out at ${timeOutLabel}`)
      const shortLabel = formatDurationMinutes(undertime)
      if (shortLabel) parts.push(`${shortLabel} short`)
      return parts.join(' • ') || 'Recorded as half day against your schedule.'
    }

    if (t.status === 'rest' || t.status === 'rest_day' || t.status === 'no_schedule_rest') {
      return 'Rest Day - no work scheduled.'
    }

    if (t.status === 'undertime') {
      const parts = []
      if (timeOutLabel) parts.push(`Left at ${timeOutLabel}`)
      const shortLabel = formatDurationMinutes(undertime)
      if (shortLabel) parts.push(`${shortLabel} short`)
      return parts.join(' • ') || 'Marked undertime based on early time out.'
    }

    if (t.status === 'late') {
      const lateDisplay = t.late_label || (late ? formatDurationMinutes(late) + ' late' : null)
      if (timeInLabel && !timeOutLabel) {
        return lateDisplay ? `Working (${lateDisplay}) — clock out when you leave.` : `Working since ${timeInLabel}.`
      }
      if (lateDisplay) return lateDisplay + ' on arrival'
      if (timeInLabel) return `Clocked in at ${timeInLabel}`
      return 'Marked late based on your time in.'
    }

    if (t.status === 'present') {
      if (timeInLabel && !timeOutLabel) {
        return `Working since ${timeInLabel} — clock out when you leave.`
      }
      if (timeInLabel || timeOutLabel) {
        return `In: ${timeInLabel || '—'} • Out: ${timeOutLabel || '—'}`
      }
      return 'Present for today’s schedule.'
    }

    if (t.status === 'clocked_in') {
      const parts = []
      if (late != null && late > 0) parts.push(`${late} min late`)
      if (t.late_label && String(t.late_label).trim()) parts.push(`Status: ${t.late_label}`)
      if (timeInLabel && !timeOutLabel) {
        return parts.length
          ? `${parts.join(' · ')} — Working since ${timeInLabel} — clock out when you leave.`
          : `Working since ${timeInLabel} — clock out when you leave.`
      }
      if (timeInLabel || timeOutLabel) {
        return `In: ${timeInLabel || '—'} • Out: ${timeOutLabel || '—'}`
      }
      return parts.join(' · ') || 'Currently clocked in.'
    }

    if (t.status === 'incomplete') {
      return 'Shift ended without a complete clock-out; check with HR if this was corrected.'
    }

    if (t.status === 'absent') {
      if (isRestDay(formatLocalDateKey(new Date()))) {
        return 'Rest Day - no work scheduled.'
      }
      return isPastAbsentCutoff()
        ? 'No attendance recorded for today.'
        : 'Scan your QR code or use Face Recognition to clock in when you arrive.'
    }

    if (t.status === 'leave') {
      return 'On approved leave; no work expected today.'
    }

    return ''
  }

  function formatTime(value) {
    return formatClockTimeDisplay(value)
  }

  function formatTodayDate(dateStr) {
    if (!dateStr) return '—'
    try {
      const d = new Date(dateStr + 'T12:00:00')
      if (Number.isNaN(d.getTime())) return dateStr
      return d.toLocaleDateString('en-PH', { weekday: 'short', month: 'short', day: 'numeric' })
    } catch {
      return dateStr
    }
  }

  function formatScheduleChangeDate(dateStr) {
    if (!dateStr) return '-'
    try {
      const d = new Date(`${dateStr}T12:00:00`)
      if (Number.isNaN(d.getTime())) return dateStr
      return d.toLocaleDateString('en-PH', { month: 'short', day: 'numeric', year: 'numeric' })
    } catch {
      return dateStr
    }
  }

  function formatScheduleChangeRange(schedule) {
    if (!schedule?.time_in || !schedule?.time_out) return null
    const start = formatClockTimeDisplay(schedule.time_in)
    const end = formatClockTimeDisplay(schedule.time_out)
    if (!start || !end) return null
    return `${start} - ${end}`
  }

  function getMonthLabel() {
    if (!summary?.from_date) {
      const now = new Date()
      return now.toLocaleDateString('en-PH', { month: 'long', year: 'numeric' })
    }
    const d = new Date(`${summary.from_date}T12:00:00`)
    if (Number.isNaN(d.getTime())) return String(summary.from_date)
    return d.toLocaleDateString('en-PH', { month: 'long', year: 'numeric' })
  }

  const statusByDate = useMemo(() => {
    const map = new Map()
    days.forEach((d) => {
      if (d.date) map.set(d.date, d.status || '—')
    })
    return map
  }, [days])

  const recordByDate = useMemo(() => {
    const map = new Map()
    days.forEach((d) => {
      if (d?.date) map.set(d.date, d)
    })
    return map
  }, [days])

  function calendarRecordForTile(key) {
    const raw = recordByDate.get(key) ?? null
    if (raw) return raw
    const st = statusByDate.get(key)
    const effective = st ?? '—'
    if (isRestDay(key) && (effective === '—' || effective === 'absent')) {
      return { status: effective, date: key }
    }
    return null
  }

  const attendanceCalendarCells = useMemo(
    () => getCalendarCells(calendarYear, calendarMonth),
    [calendarYear, calendarMonth],
  )

  /** True when the calendar / stats month is before the current calendar month (local). */
  const canGoNextMonth = useMemo(() => {
    const t = new Date()
    const y = t.getFullYear()
    const m = t.getMonth()
    if (calendarYear < y) return true
    if (calendarYear > y) return false
    return calendarMonth < m
  }, [calendarYear, calendarMonth])

  const isViewingCurrentMonth = useMemo(() => {
    const t = new Date()
    return calendarYear === t.getFullYear() && calendarMonth === t.getMonth()
  }, [calendarYear, calendarMonth])

  const upcomingHolidays = useMemo(() => {
    const today = formatLocalDateKey(new Date())
    return holidayRows
      .filter((holiday) => String(holiday?.status || 'active').toLowerCase() === 'active')
      .filter((holiday) => String(holiday?.date || holiday?.holiday_date || '') >= today)
      .sort((a, b) => String(a.date || '').localeCompare(String(b.date || '')))
      .slice(0, 3)
  }, [holidayRows])

  const currentHolidaySummary = useMemo(() => ({
    regular: holidaySummary?.regular ?? holidayRows.filter((h) => String(h?.type || '').toLowerCase() === 'regular').length,
    special: holidaySummary?.special ?? holidayRows.filter((h) => ['special', 'special_non_working'].includes(String(h?.type || '').toLowerCase())).length,
    local: holidaySummary?.local ?? holidayRows.filter((h) => !['nationwide', 'regional'].includes(String(h?.scope || '').toLowerCase())).length,
    total: holidaySummary?.total ?? holidayRows.length,
  }), [holidayRows, holidaySummary])

  function goPrevCalendarMonth() {
    if (calendarMonth === 0) {
      setCalendarMonth(11)
      setCalendarYear((y) => y - 1)
    } else setCalendarMonth((m) => m - 1)
  }

  function goNextCalendarMonth() {
    if (!canGoNextMonth) return
    if (calendarMonth === 11) {
      setCalendarMonth(0)
      setCalendarYear((y) => y + 1)
    } else setCalendarMonth((m) => m + 1)
  }

  function goCalendarToday() {
    const t = new Date()
    setCalendarYear(t.getFullYear())
    setCalendarMonth(t.getMonth())
  }

  const employeeIsExecom = Boolean(
    summary?.is_execom
    || user?.is_execom
    || summary?.execom_badge
    || user?.execom_badge
    || summary?.classification === 'EXECom'
    || user?.classification === 'EXECom',
  )
  const employeeClassification = summary?.classification || user?.classification || (employeeIsExecom ? 'EXECom' : null)

  const monthAttendanceMetrics = useMemo(() => {
    const dayList = Array.isArray(days) ? days : []
    const scheduledDays = dayList.filter(isScheduledWorkDay).length
    const restDaysWorked = dayList.filter((d) => d?.is_rest_day_worked).length
    const rawExpectedHours = Number(summary?.expected_scheduled_hours ?? 0)
    const rawPayrollImpactHours = Number(summary?.payroll_impact_hours ?? 0)
    return {
      present: summary?.present_count ?? 0,
      absent: absentCounts?.absent ?? summary?.absent_count ?? 0,
      late: summary?.late_count ?? 0,
      undertime: summary?.undertime_count ?? 0,
      scheduledDays,
      restDaysWorked,
      efficiency: employeeIsExecom ? 100 : (summary?.attendance_efficiency_percentage ?? 0),
      expectedScheduledHours: rawExpectedHours,
      actualWorkedHours: summary?.actual_worked_hours ?? 0,
      payrollImpactHours: employeeIsExecom ? rawExpectedHours : rawPayrollImpactHours,
      lateMinutes: summary?.late_minutes ?? 0,
      undertimeMinutes: summary?.undertime_minutes ?? 0,
      absentHours: employeeIsExecom ? 0 : (summary?.absent_hours ?? 0),
      presentDays: summary?.present_days ?? 0,
      absentDays: summary?.absent_days ?? 0,
      lateDays: summary?.late_days ?? 0,
      undertimeDays: summary?.undertime_days ?? 0,
      restDays: summary?.rest_day_count ?? 0,
      leaveDays: summary?.leave_count ?? 0,
      holidayDays: summary?.holiday_count ?? 0,
      lostHours: employeeIsExecom ? 0 : (summary?.lost_hours ?? Math.max(0, rawExpectedHours - rawPayrollImpactHours)),
      isExecom: employeeIsExecom,
      classification: employeeClassification,
    }
  }, [summary, absentCounts, days, employeeIsExecom, employeeClassification])

  const attendanceSummaryBaseDays = monthAttendanceMetrics.scheduledDays

  const attendanceSummarySlices = useMemo(() => {
    const counts = {
      present: monthAttendanceMetrics.present,
      absent: monthAttendanceMetrics.absent,
      late: monthAttendanceMetrics.late,
      undertime: monthAttendanceMetrics.undertime,
    }
    const slices = ['present', 'absent', 'late', 'undertime'].map((id) => {
      const count = counts[id]
      const meta = ATTENDANCE_SUMMARY_SLICE_META[id]
      return {
        id,
        label: meta.label,
        color: meta.color,
        count,
        percent: formatAttendanceMetricPercent(count, attendanceSummaryBaseDays),
        chartValue: count > 0 ? count : 0,
      }
    })
    const effPct = monthAttendanceMetrics.efficiency
    slices.push({
      id: 'efficiency',
      label: 'Efficiency',
      color: ATTENDANCE_SUMMARY_SLICE_META.efficiency.color,
      count: typeof effPct === 'number' && Number.isFinite(effPct) ? `${effPct.toFixed(2)}%` : '0.00%',
      percent: '',
      chartValue: 0,
      efficiency: true,
    })
    return slices
  }, [monthAttendanceMetrics, attendanceSummaryBaseDays])

  const attendanceSummaryHasData = attendanceSummarySlices.some((slice) => slice.chartValue > 0)

  const attendanceMonthOptions = useMemo(() => buildAttendanceMonthOptions(), [])

  const attendanceMonthValue = calendarMonthSelectKey(calendarYear, calendarMonth)

  const handleAttendanceMonthSelect = useCallback((value) => {
    const parsed = parseCalendarMonthSelectKey(value)
    if (!Number.isFinite(parsed.year) || !Number.isFinite(parsed.monthIndex)) return
    if (parsed.monthIndex < 0 || parsed.monthIndex > 11) return
    // Defer until Radix Select portal finishes closing — avoids removeChild races.
    window.setTimeout(() => {
      setCalendarYear(parsed.year)
      setCalendarMonth(parsed.monthIndex)
    }, 0)
  }, [])

  const handlePerformanceCalendarCellSelect = useCallback((cell) => {
    if (!cell?.isAdjacent) return
    setCalendarYear(cell.year)
    setCalendarMonth(cell.month)
  }, [])

  const attendanceSummaryModalDays = useMemo(
    () => (Array.isArray(days) ? days : [])
      .filter(isAttendanceEfficiencyDetailDay)
      .sort((a, b) => String(a.date).localeCompare(String(b.date))),
    [days],
  )

  const attendanceSummaryFilteredDays = useMemo(() => {
    const query = attendanceSummarySearch.trim().toLowerCase()
    return attendanceSummaryModalDays.filter((day) => {
      const statusKey = attendanceSummaryStatusKey(day)
      if (attendanceSummaryFilter !== 'all' && statusKey !== attendanceSummaryFilter) return false
      if (!query) return true
      const haystack = [
        formatYmdShort(day.date),
        attendanceSummaryStatusLabel(day),
        day.formatted_time_in,
        day.formatted_time_out,
        day.remarks,
        day.remark,
      ].filter(Boolean).join(' ').toLowerCase()
      return haystack.includes(query)
    })
  }, [attendanceSummaryFilter, attendanceSummaryModalDays, attendanceSummarySearch])

  const attendanceSummaryPageSize = 10
  const attendanceSummaryPageCount = Math.max(1, Math.ceil(attendanceSummaryFilteredDays.length / attendanceSummaryPageSize))
  const attendanceSummarySafePage = Math.min(attendanceSummaryPage, attendanceSummaryPageCount)
  const attendanceSummaryPagedDays = useMemo(() => {
    const start = (attendanceSummarySafePage - 1) * attendanceSummaryPageSize
    return attendanceSummaryFilteredDays.slice(start, start + attendanceSummaryPageSize)
  }, [attendanceSummaryFilteredDays, attendanceSummarySafePage])

  useEffect(() => {
    setAttendanceSummaryPage(1)
  }, [attendanceMonthValue, attendanceSummaryFilter, attendanceSummarySearch])

  const openAttendanceDayFromSummary = useCallback((dateStr) => {
    if (!dateStr) return
    const d = new Date(`${dateStr}T12:00:00`)
    if (Number.isNaN(d.getTime())) return
    setAttendanceSummaryModalOpen(false)
    setSelectedDay(d)
  }, [])

  /** Row for the clicked calendar day: API day, synthetic rest-day tile, or empty placeholder. */
  const selectedDayDetails = useMemo(() => {
    if (!selectedDay || !Array.isArray(days)) return null
    const iso = formatLocalDateKey(selectedDay)
    const fromApi = days.find((d) => d.date === iso)
    if (fromApi) {
      const todayKey = formatLocalDateKey(new Date())
      const today = summary?.today
      const merged =
        iso === todayKey && today
          ? {
              ...fromApi,
              time_in: fromApi.time_in || today.time_in || null,
              time_out: fromApi.time_out || today.time_out || null,
              formatted_time_in: fromApi.formatted_time_in || today.formatted_time_in || null,
              formatted_time_out: fromApi.formatted_time_out || today.formatted_time_out || null,
            }
          : fromApi
      return { ...merged, date_iso: iso }
    }
    const st = statusByDate.get(iso)
    const effective = st ?? '—'
    if (isRestDay(iso) && (effective === '—' || effective === 'absent')) {
      return { status: effective, date: iso, date_iso: iso }
    }
    return { date_iso: iso, status: null }
  }, [selectedDay, days, statusByDate, isRestDay, summary?.today])

  const dismissOverlays = useCallback(() => {
    setSelectedDay(null)
    setAttendanceSummaryModalOpen(false)
    setEvaluationDetailsOpen(false)
    setOtDetailsOpen(false)
    setFaceAttendanceOpen(false)
  }, [])
  useDismissOnRouteChange(dismissOverlays)

  const handleFileCorrection = useCallback(() => {
    if (!selectedDayDetails) return
    const to = buildEmployeeCorrectionHref(
      selectedDayDetails,
      employeeSelfHref(hrBase, 'correction-requests'),
    )
    setSelectedDay(null)
    navigateAfterOverlayDismiss(navigate, to)
  }, [hrBase, navigate, selectedDayDetails])

  const performanceWidget = evaluationWidget?.performance || null
  const evaluationModuleWidget = evaluationWidget?.evaluation || null
  const evaluationStats = evaluationWidget?.stats || {}
  const evaluationHistory = useMemo(
    () => (Array.isArray(evaluationWidget?.history) ? evaluationWidget.history : []),
    [evaluationWidget?.history],
  )
  const performanceHistory = useMemo(
    () => (Array.isArray(performanceWidget?.history) ? performanceWidget.history : []),
    [performanceWidget?.history],
  )
  const completedEvaluationHistory = useMemo(
    () => evaluationHistory.filter((row) => row?.percentage != null || String(row?.status || '').toLowerCase() === 'completed'),
    [evaluationHistory],
  )
  const openEvaluationHistory = useMemo(
    () => evaluationHistory.filter((row) => row?.percentage == null && String(row?.status || '').toLowerCase() !== 'completed'),
    [evaluationHistory],
  )
  const latestCompletedEvaluation = completedEvaluationHistory[0] || null
  const performanceSnapshotAveragePercentage = Number(
    performanceWidget?.snapshot_average_percentage ?? performanceWidget?.average_percentage,
  )
  const performanceLatestPercentage = Number(
    performanceWidget?.latest_percentage ?? performanceWidget?.average_percentage,
  )
  const hasPerformanceSnapshotAverage = Number.isFinite(performanceSnapshotAveragePercentage)
  const hasPerformanceLatestScore = Number.isFinite(performanceLatestPercentage)
  const performanceSnapshotCount = Number(performanceWidget?.snapshot_count || 0)
  const performanceStatusSlices = useMemo(() => (
    performanceSnapshotCount > 0
      ? [{ id: 'snapshots', label: 'Snapshots', count: performanceSnapshotCount, color: '#8b5cf6' }]
      : []
  ), [performanceSnapshotCount])
  const performanceChartTotal = performanceStatusSlices.reduce((sum, slice) => sum + slice.count, 0)
  const performanceByDate = useMemo(() => {
    const map = new Map()
    for (const row of performanceHistory) {
      if (row?.date) map.set(row.date, row)
    }
    return map
  }, [performanceHistory])
  const performanceCalendarCells = useMemo(
    () => getCalendarCells(calendarYear, calendarMonth),
    [calendarYear, calendarMonth],
  )

  const otMonthBreakdown = useMemo(() => {
    const pendingH = monthOtRequests
      .filter((o) => o && o.status === 'pending')
      .reduce((s, o) => s + (Number(o.computed_hours) || 0), 0)
    const approvedH = monthOtRequests
      .filter((o) => o && o.status === 'approved')
      .reduce((s, o) => s + (Number(o.computed_hours) || 0), 0)

    const requestsByDate = {}
    for (const o of monthOtRequests) {
      if (!o?.date || (o.status !== 'pending' && o.status !== 'approved')) continue
      if (!requestsByDate[o.date]) requestsByDate[o.date] = []
      requestsByDate[o.date].push(o)
    }

    const unfiledEntries = []
    if (Array.isArray(days)) {
      for (const d of days) {
        if (!d?.date) continue
        const preSeg = d.raw_pre_ot ?? null
        const postSeg = d.raw_post_ot ?? null
        if (!preSeg && !postSeg) continue

        const dateRequests = requestsByDate[d.date] || []
        const preIsCovered = preSeg && dateRequests.some((r) => segmentCoveredByRequest(preSeg, r))
        const postIsCovered = postSeg && dateRequests.some((r) => segmentCoveredByRequest(postSeg, r))

        const unfiledPre = preSeg && !preIsCovered ? preSeg : null
        const unfiledPost = postSeg && !postIsCovered ? postSeg : null

        if (!unfiledPre && !unfiledPost) continue

        const unfiledH =
          (unfiledPre ? (typeof unfiledPre.hours === 'number' ? unfiledPre.hours : (unfiledPre.minutes || 0) / 60) : 0) +
          (unfiledPost ? (typeof unfiledPost.hours === 'number' ? unfiledPost.hours : (unfiledPost.minutes || 0) / 60) : 0)

        if (unfiledH <= 0.001) continue

        unfiledEntries.push({
          date: d.date,
          hours: roundHours1(unfiledH),
          rawPreOt: unfiledPre,
          rawPostOt: unfiledPost,
        })
      }
    }
    const unfiledH = unfiledEntries.reduce((s, e) => s + (Number(e.hours) || 0), 0)

    return {
      pendingH: roundHours1(pendingH),
      approvedH: roundHours1(approvedH),
      unfiledH: roundHours1(unfiledH),
      unfiledEntries,
    }
  }, [monthOtRequests, days])

  const otModalRows = useMemo(() => {
    const rows = []
    for (const o of monthOtRequests) {
      if (!o?.date) continue
      const ch = Number(o.computed_hours) || 0
      const otSummaryLine = formatOtRequestRange12h(
        o.start_time || o.schedule_end,
        o.end_time || o.expected_end_time,
        ch,
      )
      rows.push({
        key: `req-${o.id}`,
        date: o.date,
        hours: ch,
        status: o.status,
        label: o.status === 'approved' ? 'Approved OT' : (o.display_status || o.status || '—'),
        rowKind: 'request',
        otSummaryLine,
      })
    }
    for (const u of otMonthBreakdown.unfiledEntries) {
      const otSummaryLine = formatUnfiledOtClockSummary12h(u.rawPreOt, u.rawPostOt, u.hours)
      rows.push({
        key: `unfiled-${u.date}`,
        date: u.date,
        hours: u.hours,
        status: 'unfiled',
        label: 'Unfiled OT (clock)',
        rowKind: 'unfiled',
        otSummaryLine,
      })
    }
    rows.sort((a, b) => String(b.date).localeCompare(String(a.date)))
    return rows
  }, [monthOtRequests, otMonthBreakdown.unfiledEntries])

  const unfiledDatesLabel = useMemo(() => {
    const entries = otMonthBreakdown.unfiledEntries
    if (!entries.length) return ''
    const labels = entries.slice(0, 4).map((e) => formatYmdShort(e.date))
    const extra = entries.length > 4 ? ` +${entries.length - 4} more` : ''
    return labels.join(' · ') + extra
  }, [otMonthBreakdown.unfiledEntries])

  const otModalTotalHours = useMemo(
    () => roundHours1(otMonthBreakdown.pendingH + otMonthBreakdown.approvedH + otMonthBreakdown.unfiledH),
    [otMonthBreakdown.pendingH, otMonthBreakdown.approvedH, otMonthBreakdown.unfiledH],
  )

  const monthTrend = useMemo(() => {
    if (!summary || !prevSummary) return null
    const current = typeof summary.total_hours === 'number' ? summary.total_hours : null
    const prev = typeof prevSummary.total_hours === 'number' ? prevSummary.total_hours : null
    if (current == null || prev == null) return null
    const delta = current - prev
    if (Math.abs(delta) < 0.01) {
      return {
        direction: 'flat',
        label: 'Same total hours as last month',
        colorClass: 'text-muted-foreground',
      }
    }
    const absDelta = Math.abs(delta)
    const direction = delta > 0 ? 'up' : 'down'
    const hoursLabel = `${absDelta.toFixed(1)}h`
    const good = delta > 0
    return {
      direction,
      label: `${delta > 0 ? '+' : '-'}${hoursLabel} vs last month`,
      colorClass: good
        ? 'text-emerald-600 dark:text-emerald-400'
        : 'text-red-600 dark:text-red-400',
    }
  }, [summary, prevSummary])

  const scheduleAssigned = summary?.schedule_assigned !== false
  const todayTimeIn = summary?.today?.time_in
  const todayTimeOut = summary?.today?.time_out
  const todayStatus = summary?.today?.status
  const canClockWithFace =
    scheduleAssigned &&
    !['leave', 'rest', 'rest_day', 'no_schedule_rest'].includes(String(todayStatus || '')) &&
    !(todayTimeIn && todayTimeOut)
  const faceClockType = todayTimeIn && !todayTimeOut ? 'clock_out' : 'clock_in'
  const pendingScheduleChange = summary?.pending_schedule_change?.schedule
    ? summary.pending_schedule_change
    : null
  const pendingScheduleRange = pendingScheduleChange
    ? formatScheduleChangeRange(pendingScheduleChange.schedule)
    : null

  const currentStatus = useMemo(() => {
    const t = summary?.today
    if (!t) return null

    // No schedule assigned: always show this first
    if (!scheduleAssigned) {
      return {
        tone: 'warning',
        dotClass: 'bg-amber-500',
        label: 'No schedule assigned',
        detail: 'Contact HR or your administrator.',
      }
    }

    const timeIn = t.time_in
    const timeOut = t.time_out
    const status = t.status

    // Currently clocked in (has time in, no time out yet)
    if (timeIn && !timeOut && (status === 'present' || status === 'late' || status === 'halfday' || status === 'clocked_in')) {
      const lateBit =
        typeof t.late_minutes === 'number' && t.late_minutes > 0
          ? ` (${t.late_minutes} min late)`
          : t.late_label && String(t.late_label).trim()
            ? ` (${t.late_label})`
            : ''
      return {
        tone: 'active',
        dotClass: 'bg-emerald-500',
        label: 'Working',
        detail: `Clocked in at ${formatTime(timeIn)}${lateBit}`,
      }
    }

    if (status === 'leave') {
      return {
        tone: 'info',
        dotClass: 'bg-sky-500',
        label: 'On leave',
        detail: 'You are not expected to work today.',
      }
    }

    if (status === 'rest' || status === 'rest_day' || status === 'no_schedule_rest') {
      return {
        tone: 'info',
        dotClass: 'bg-slate-400',
        label: 'Rest Day',
        detail: 'Rest Day - no work scheduled.',
      }
    }

    // Completed shift — both clock-in and clock-out are recorded (real logs or manual correction)
    const completedStatuses = ['present', 'late', 'halfday', 'undertime', 'incomplete']
    if (timeIn && timeOut && completedStatuses.includes(status)) {
      return {
        tone: 'idle',
        dotClass: 'bg-zinc-400',
        label: 'Clocked out',
        detail: `In: ${formatTime(timeIn)} · Out: ${formatTime(timeOut)}`,
      }
    }

    const todayKey = formatLocalDateKey(new Date())
    if (status === 'absent') {
      if (isRestDay(todayKey)) {
        return {
          tone: 'info',
          dotClass: 'bg-slate-400',
          label: 'Rest Day',
          detail: 'Rest Day - no work scheduled.',
        }
      }
      const pastCutoff = isPastAbsentCutoff()
      return {
        tone: pastCutoff ? 'danger' : 'idle',
        dotClass: pastCutoff ? 'bg-red-500' : 'bg-amber-400',
        label: pastCutoff ? 'Missed clock-in' : 'Not started',
        detail: pastCutoff ? 'No attendance recorded for today.' : 'Scan your QR code or use Face Recognition to clock in when you arrive.',
      }
    }

    if (timeOut && timeIn) {
      return {
        tone: 'idle',
        dotClass: 'bg-zinc-400',
        label: 'Clocked out',
        detail: `In: ${formatTime(timeIn)} · Out: ${formatTime(timeOut)}`,
      }
    }

    // Default neutral state
    return {
      tone: 'idle',
      dotClass: 'bg-amber-400',
      label: 'Not started',
      detail: 'Once you clock in, your live status will appear here.',
    }
  }, [summary, scheduleAssigned, isRestDay])

  function getDisplayStatus(status, dateKey, lateLabel, lateMinutes, statusLabel = null, presenceLabel = null, presenceIssue = null) {
    if (presenceIssue === 'incomplete_pair' && presenceLabel) return presenceLabel
    if (presenceIssue === 'correction_pending' && presenceLabel) return presenceLabel
    if (statusLabel) return statusLabel
    if (!dateKey) return status
    const todayKey = formatLocalDateKey(new Date())
    if (dateKey === todayKey && summary?.schedule_assigned === false) return 'No schedule'
    if (status === 'leave') return 'On leave'
    if (status === 'holiday') return 'Holiday'
    if (status === 'rest' || status === 'rest_day' || status === 'no_schedule_rest') return 'Rest Day'
    if (status === 'absent' || status === '—') {
      if (isRestDay(dateKey)) return 'Rest Day'
      // Scheduled workday with no punches yet (today before cutoff).
      if (dateKey === todayKey && !isPastAbsentCutoff()) return 'Not started'
      if (status === 'absent' || status === '—') return 'Missed clock-in'
    }
    if (status === 'clocked_in') {
      const lm = typeof lateMinutes === 'number' ? lateMinutes : 0
      if (lm > 0) return lateLabel || `${lm} min late`
      return lateLabel || 'Present'
    }
    if (status === 'present') return 'Present'
    if (status === 'present_with_ot') return 'Present with OT'
    if (status === 'late') {
      const lm = typeof lateMinutes === 'number' ? lateMinutes : 0
      return lateLabel || (lm > 0 ? `${lm} min late` : 'Late')
    }
    if (status === 'halfday') return 'Half Day'
    if (status === 'undertime') return 'Undertime'
    if (status === 'incomplete') return 'Incomplete'
    return status
  }

  function tileTooltipLines(record, dateKey) {
    if (!record) return []
    const lines = []
    const label = getDisplayStatus(
      record.status,
      dateKey,
      record.late_label,
      record.late_minutes,
      record.status_label,
      record.presence_label,
      record.presence_issue,
    ) || '—'
    lines.push(label)
    const timeIn = record.formatted_time_in || record.time_in
    const timeOut = record.formatted_time_out || record.time_out
    if (timeIn) lines.push(`In: ${formatTime(timeIn)}`)
    if (timeOut) lines.push(`Out: ${formatTime(timeOut)}`)
    if (record.late_label) lines.push(`Status: ${record.late_label}`)
    if (!record.late_label && typeof record.late_minutes === 'number' && record.late_minutes > 0) {
      lines.push(`Status: ${record.late_minutes} min`)
    }
    if (typeof record.undertime_minutes === 'number' && record.undertime_minutes > 0) lines.push(`Undertime: ${record.undertime_minutes} min`)
    if (typeof record.total_hours === 'number') lines.push(`Total: ${record.total_hours.toFixed ? record.total_hours.toFixed(2) : record.total_hours}h`)
    const otHours =
      typeof record.raw_overtime_hours === 'number'
        ? record.raw_overtime_hours
        : typeof record.overtime_hours === 'number'
          ? record.overtime_hours
          : typeof record.overtime_minutes === 'number' && record.overtime_minutes > 0
            ? record.overtime_minutes / 60
            : null
    if (otHours != null && otHours > 0) {
      lines.push(`OT: ${typeof otHours === 'number' && otHours.toFixed ? otHours.toFixed(2) : otHours} hrs`)
    }
    return lines
  }

  function calendarTileTimeLines(record) {
    if (!record) return []

    const timeIn = record.formatted_time_in || record.time_in
    const timeOut = record.formatted_time_out || record.time_out
    const rows = []

    if (timeIn) rows.push({ label: 'In', value: formatTime(timeIn) })
    if (timeOut) rows.push({ label: 'Out', value: formatTime(timeOut) })

    const hours = getAttendanceTotalHours(record)
    if (hours != null) rows.push({ label: 'Hrs', value: `${Number(hours).toFixed(1)}h` })

    return rows.filter((row) => row.value && row.value !== '—')
  }

  function getAttendanceTotalHours(record) {
    const hours =
      typeof record?.total_rendered_hours === 'number'
        ? record.total_rendered_hours
        : typeof record?.total_hours === 'number'
          ? record.total_hours
          : null
    return typeof hours === 'number' && Number.isFinite(hours) ? hours : null
  }

  function openFaceAttendance(type = faceClockType) {
    setFaceAttendanceType(type)
    setFaceAttendanceOpen(true)
  }

  async function handleFaceAttendanceSuccess(data) {
    setFaceAttendanceOpen(false)
    calendarCacheRef.current.clear()
    await Promise.all([
      loadDashboardSummary({ soft: true }),
      loadAttendanceCalendar(calendarYear, calendarMonth, { force: true }),
      refreshUser?.(),
    ])
    window.dispatchEvent(new CustomEvent('hr:attendance-recorded', {
      detail: {
        source: 'employee_dashboard_face',
        type: data?.attendance?.type || faceAttendanceType,
      },
    }))
  }

  return (
    <Motion.div
      className="min-w-0 max-w-full space-y-3 overflow-x-clip text-sm text-foreground @sm:space-y-4 @md:text-[15px]"
      initial="hidden"
      animate="visible"
      variants={{ hidden: { opacity: 0 }, visible: { opacity: 1, transition: { staggerChildren: 0.04 } } }}
    >
      {/* Welcome + live clock */}
      <Motion.div
        className="flex min-w-0 flex-col gap-3 @sm:gap-4 @lg:flex-row @lg:items-start @lg:justify-between"
        variants={itemVariants}
        initial="hidden"
        whileInView="visible"
        viewport={scrollViewport}
        transition={scrollRevealTransition}
      >
        <div className="min-w-0 space-y-2">
          <div>
            <h2 className="text-xl font-extrabold tracking-tight text-foreground wrap-break-word @sm:text-2xl @md:text-[1.7rem]">
              Welcome back, {employeeDisplayName} <span className="align-middle text-xl">{'\u{1F44B}'}</span>
            </h2>
            {user?.position && (
              <p className="mt-1 text-xs font-extrabold uppercase tracking-wide text-orange-600">
                {user.position}
              </p>
            )}
            {employeeIsExecom && (
              <Badge className="mt-2 border-violet-200 bg-violet-50 px-2.5 py-1 text-xs font-extrabold uppercase tracking-wide text-violet-700 shadow-sm dark:border-violet-500/30 dark:bg-violet-500/10 dark:text-violet-200">
                EXECom
              </Badge>
            )}
            <p className="mt-2 max-w-3xl text-sm leading-relaxed text-muted-foreground @sm:text-[15px]">
              Track your time, review your logs, and stay on top of your schedule.
            </p>
          </div>
          {currentStatus && (
            <div className="inline-flex w-full flex-wrap items-start gap-2 rounded-lg border border-border bg-card px-3 py-2 text-sm shadow-sm transition-opacity duration-200 @md:w-auto dark:bg-card/85">
              <div className="flex w-full flex-wrap items-center gap-2 @sm:w-auto">
                <span className={`inline-flex h-2 w-2 shrink-0 rounded-full ${currentStatus.dotClass}`} />
                <span className="font-semibold text-foreground">{currentStatus.label}</span>
                {currentStatus.detail && (
                  <span className="w-full text-muted-foreground @md:w-auto @md:pl-1">- {currentStatus.detail}</span>
                )}
              </div>
              {canClockWithFace && (
                <div className="flex w-full flex-wrap items-center gap-2">
                  {user?.has_face ? (
                    <>
                      <Button
                        size="sm"
                        className="h-9 w-full gap-1.5 rounded-md px-3 text-sm font-semibold transition-all duration-200 hover:scale-[1.02] active:scale-[0.98] @sm:h-8 @sm:w-auto"
                        onClick={() => openFaceAttendance('clock_in')}
                      >
                        <ScanFace className="size-3.5" />
                        Face Clock In
                      </Button>
                      <Button
                        size="sm"
                        variant="outline"
                        className="h-9 w-full gap-1.5 rounded-md px-3 text-sm font-semibold transition-all duration-200 hover:scale-[1.02] active:scale-[0.98] @sm:h-8 @sm:w-auto"
                        onClick={() => openFaceAttendance('clock_out')}
                      >
                        <ScanFace className="size-3.5" />
                        Face Clock Out
                      </Button>
                    </>
                  ) : (
                    <Button
                      size="sm"
                      variant="outline"
                      className="h-9 w-full gap-1.5 rounded-md px-3 text-sm font-semibold transition-all duration-200 hover:scale-[1.02] active:scale-[0.98] @sm:h-8 @sm:w-auto"
                      onClick={() => goSelf('qr')}
                    >
                      <ScanFace className="size-3.5" />
                      Register Face
                    </Button>
                  )}
                </div>
              )}
            </div>
          )}
          {pendingScheduleChange ? (
            <div className="flex max-w-3xl items-start gap-3 rounded-lg border border-brand/20 bg-brand/5 px-3 py-2.5 text-sm shadow-sm">
              <CalendarDays className="mt-0.5 size-4 shrink-0 text-brand" />
              <div className="min-w-0">
                <p className="font-semibold text-foreground">Upcoming schedule change</p>
                <p className="mt-0.5 leading-relaxed text-muted-foreground">
                  Starting <span className="font-semibold tabular-nums text-foreground">{formatScheduleChangeDate(pendingScheduleChange.effective_from)}</span>
                  {pendingScheduleChange.effective_to ? (
                    <>
                      {' '}until <span className="font-semibold tabular-nums text-foreground">{formatScheduleChangeDate(pendingScheduleChange.effective_to)}</span>
                    </>
                  ) : null}
                  , your schedule will update to{' '}
                  <span className="font-semibold text-foreground">{pendingScheduleChange.schedule.name}</span>
                  {pendingScheduleRange ? (
                    <span className="text-muted-foreground"> ({pendingScheduleRange})</span>
                  ) : null}
                  .
                </p>
              </div>
            </div>
          ) : null}
        </div>
        <div className="flex w-full min-w-0 flex-wrap items-stretch justify-start @lg:w-auto @lg:justify-end">
          <LiveClock />
        </div>
      </Motion.div>

      {/* Stats cards */}
      {error && (
        <Motion.div
          className="rounded-md border border-destructive/50 bg-destructive/10 px-4 py-2 text-base text-destructive"
          variants={itemVariants}
        >
          {error}
        </Motion.div>
      )}
      <Motion.div
        className="grid min-w-0 gap-3 @md:grid-cols-2 @lg:gap-4 @xl:grid-cols-[minmax(0,1.65fr)_minmax(240px,0.95fr)_minmax(280px,1.08fr)]"
        variants={containerVariants}
        initial="hidden"
        whileInView="visible"
        viewport={scrollViewport}
        transition={scrollRevealTransition}
      >
        {/* Today — primary, elevated */}
        <Motion.div variants={itemVariants} whileHover={{ y: -2, transition: { duration: 0.15 } }}>
        <Card className="min-h-40 overflow-hidden rounded-xl border-border bg-card shadow-[0_12px_30px_-22px_rgba(15,23,42,0.7)] transition-all duration-200 hover:shadow-[0_18px_36px_-24px_rgba(15,23,42,0.8)] @sm:min-h-[11.2rem] dark:bg-card/85">
          <CardHeader className="flex flex-row items-start justify-between gap-3 space-y-0 pb-2">
            <div className="flex flex-wrap items-center gap-2">
              <CardTitle className="text-sm font-extrabold uppercase tracking-wide text-foreground">
                Today
              </CardTitle>
              {!loading && summary?.schedule_assigned === false && (
                <Badge variant="secondary" className="bg-amber-500/15 text-amber-800 dark:bg-amber-400/20 dark:text-amber-200 border-amber-500/30">
                  No Shift Assigned
                </Badge>
              )}
              {!loading && summary?.schedule_assigned !== false && user?.working_schedule_name && (
                <Badge variant="secondary" className="rounded-full border-emerald-500/20 bg-emerald-50 text-xs font-bold text-emerald-700">
                  Assigned: {user.working_schedule_name}
                  {user?.working_schedule_time && ` (${formatScheduleLabel12h(user.working_schedule_time)})`}
                </Badge>
              )}
            </div>
            <div className="rounded-full bg-muted p-2">
              <Clock className="size-5 text-muted-foreground" />
            </div>
          </CardHeader>
          <CardContent>
            <div className="flex flex-col gap-1 @sm:flex-row @sm:items-baseline @sm:justify-between @sm:gap-2">
              <span className="text-2xl font-extrabold tracking-tight text-foreground @sm:text-3xl @md:text-4xl">
                {loading ? '—' : formatTodayStatus()}
              </span>
              <span className="shrink-0 text-xs font-medium text-muted-foreground @sm:text-sm">
                {formatTodayDate(summary?.today?.date)}
              </span>
            </div>
            {!loading && formatTodayContext() && (
              <p className="mt-2 text-sm leading-relaxed text-muted-foreground transition-opacity duration-200 @sm:text-base">
                {formatTodayContext()}
              </p>
            )}
          </CardContent>
        </Card>
        </Motion.div>
        {/* Today's Time — secondary */}
        <Motion.div variants={itemVariants} whileHover={{ y: -2, transition: { duration: 0.15 } }}>
        <Card className="min-h-40 overflow-hidden rounded-xl border-border bg-card shadow-[0_12px_30px_-22px_rgba(15,23,42,0.65)] transition-all duration-200 hover:shadow-[0_18px_36px_-24px_rgba(15,23,42,0.75)] @sm:min-h-[11.2rem] dark:bg-card/85">
          <CardHeader className="flex flex-row items-center justify-between space-y-0 pb-2">
            <CardTitle className="text-sm font-extrabold uppercase tracking-wide text-muted-foreground">
              Today&apos;s Time
            </CardTitle>
            <div className="rounded-lg bg-muted p-2">
              <FileCheck className="size-4 text-muted-foreground" />
            </div>
          </CardHeader>
          <CardContent>
            <div className="flex flex-col gap-2 text-base">
              <div className="flex items-center justify-between">
                <span className="text-sm text-muted-foreground">Time in</span>
                <span className="font-semibold text-foreground">
                  {loading ? '—' : (formatTime(summary?.today?.time_in) || '—')}
                </span>
              </div>
              <div className="flex items-center justify-between">
                <span className="text-sm text-muted-foreground">Time out</span>
                <span className="font-semibold text-foreground">
                  {loading ? '—' : (formatTime(summary?.today?.time_out) || '—')}
                </span>
              </div>
              {!loading && (
                <div className="flex items-center justify-between">
                  <span className="text-sm text-muted-foreground">Status</span>
                  <span className={`text-sm font-medium ${summary?.today?.time_in && !summary?.today?.time_out ? 'text-emerald-600 dark:text-emerald-400' : 'text-muted-foreground'}`}>
                    {summary?.today?.time_in && !summary?.today?.time_out
                      ? `Working since ${formatTime(summary.today.time_in)}`
                      : summary?.today?.time_in && summary?.today?.time_out
                        ? 'Clocked out'
                        : '—'}
                  </span>
                </div>
              )}
              {(summary?.today?.late_minutes != null && summary.today.late_minutes > 0) && (
                <div className="flex items-center justify-between">
                  <span className="text-sm text-muted-foreground">Late (min)</span>
                  <span className="font-medium text-amber-600 dark:text-amber-400">
                    {summary.today.late_minutes}
                  </span>
                </div>
              )}
              {(summary?.today?.undertime_minutes != null && summary.today.undertime_minutes > 0) && (
                <div className="flex items-center justify-between">
                  <span className="text-sm text-muted-foreground">Undertime (min)</span>
                  <span className="font-medium text-orange-600 dark:text-orange-400">
                    {summary.today.undertime_minutes}
                  </span>
                </div>
              )}
            </div>
          </CardContent>
        </Card>
        </Motion.div>
        <Motion.div variants={itemVariants} whileHover={{ y: -2, transition: { duration: 0.15 } }} className="@md:col-span-2 @xl:col-span-1 @xl:col-start-3 @xl:row-start-1 @xl:row-span-2">
        <Card className="h-full overflow-hidden rounded-xl border-border bg-card shadow-[0_12px_30px_-22px_rgba(15,23,42,0.7)] transition-all duration-200 hover:shadow-[0_18px_36px_-24px_rgba(15,23,42,0.8)] dark:bg-card/85">
          <CardHeader className="space-y-3 pb-2">
            <div className="flex items-start justify-between gap-3">
              <div className="min-w-0 space-y-1">
                <CardTitle className="text-sm font-extrabold uppercase tracking-wide text-muted-foreground">
                  Monthly overview
                </CardTitle>
                <p className="text-xs leading-snug text-muted-foreground">
                  {isViewingCurrentMonth
                    ? 'Use ← to open past months (future months are hidden).'
                    : 'Tap the month name to return to this month, or → to move forward.'}
                </p>
              </div>
              <div className="shrink-0 rounded-lg bg-muted p-2">
                <User className="size-4 text-muted-foreground" aria-hidden />
              </div>
            </div>
            <div className="flex min-w-0 items-center gap-0.5 rounded-xl border border-border bg-muted/40 p-1">
              <Button
                type="button"
                variant="ghost"
                size="icon"
                className="size-9 shrink-0 rounded-lg hover:bg-background/80"
                onClick={goPrevCalendarMonth}
                aria-label="Previous month"
              >
                <ChevronLeft className="size-4" />
              </Button>
              <button
                type="button"
                onClick={goCalendarToday}
                className="min-w-0 flex-1 truncate rounded-md px-2 py-2 text-center text-sm font-semibold tabular-nums tracking-tight text-foreground transition-colors hover:bg-background/70 dark:hover:bg-background/10"
              >
                {MONTHS[calendarMonth]} {calendarYear}
              </button>
              <Button
                type="button"
                variant="ghost"
                size="icon"
                className="size-9 shrink-0 rounded-lg hover:bg-background/80 disabled:opacity-40"
                onClick={goNextCalendarMonth}
                disabled={!canGoNextMonth || loading || calendarLoading}
                aria-label="Next month"
              >
                <ChevronRight className="size-4" />
              </Button>
            </div>
            {isViewingCurrentMonth && (
              <Badge variant="secondary" className="w-fit border-primary/20 bg-primary/10 text-xs font-medium text-primary">
                Current month
              </Badge>
            )}
          </CardHeader>
          <CardContent>
            <Motion.div
              key={`month-stats-${calendarYear}-${calendarMonth}`}
              initial={{ opacity: 0.5, y: 6 }}
              animate={{ opacity: 1, y: 0 }}
              transition={{ duration: 0.24, ease: [0.25, 0.1, 0.25, 1] }}
              className="space-y-3"
            >
              <div className="grid grid-cols-2 gap-3 text-sm @sm:text-base">
                <div>
                  <div className="text-sm text-muted-foreground">Late days</div>
                  <div className="mt-0.5 text-lg font-medium">
                    {loading || calendarLoading ? '—' : <AnimatedNumber value={summary?.late_count ?? 0} />}
                  </div>
                </div>
                <div>
                  <div className="text-sm text-muted-foreground">Late (min)</div>
                  <div className="mt-0.5 text-lg font-medium">
                    {loading || calendarLoading ? '—' : <AnimatedNumber value={summary?.late_minutes ?? 0} />}
                  </div>
                </div>
                <div>
                  <div className="text-sm text-muted-foreground">Undertime days</div>
                  <div className="mt-0.5 text-lg font-medium">
                    {loading || calendarLoading ? '—' : <AnimatedNumber value={summary?.undertime_count ?? 0} />}
                  </div>
                </div>
                <div>
                  <div className="text-sm text-muted-foreground">Total hours</div>
                  <div className="mt-0.5 text-lg font-medium">
                    {loading || calendarLoading ? '—' : <><AnimatedNumber value={summary?.total_hours ?? 0} duration={700} />h</>}
                  </div>
                </div>
              </div>
              <div className="rounded-xl border border-border/60 bg-muted/20 p-3 dark:bg-muted/15">
                <p className="mb-2.5 text-center text-[10px] font-semibold uppercase tracking-wider text-muted-foreground">
                  Overtime (selected month)
                </p>
                <div className="grid grid-cols-1 gap-2 text-center @sm:grid-cols-3 @sm:divide-x @sm:divide-border/50">
                  <div className="px-1">
                    <div className="text-[11px] font-medium uppercase tracking-wide text-amber-800 dark:text-amber-300/90">
                      Pending
                    </div>
                    <div className="mt-1 text-base font-semibold tabular-nums text-amber-700 dark:text-amber-400">
                      {loading || calendarLoading ? '—' : `${otMonthBreakdown.pendingH}h`}
                    </div>
                  </div>
                  <div className="px-1">
                    <div className="text-[11px] font-medium uppercase tracking-wide text-emerald-800 dark:text-emerald-300/90">
                      Approved
                    </div>
                    <div className="mt-1 text-base font-semibold tabular-nums text-emerald-700 dark:text-emerald-400">
                      {loading || calendarLoading ? '—' : `${otMonthBreakdown.approvedH}h`}
                    </div>
                  </div>
                  <div className="px-1">
                    <div className="text-[11px] font-medium uppercase tracking-wide text-slate-600 dark:text-slate-400">
                      Unfiled
                    </div>
                    <div className="mt-1 text-base font-semibold tabular-nums text-slate-700 dark:text-slate-300">
                      {loading || calendarLoading ? '—' : `${otMonthBreakdown.unfiledH}h`}
                    </div>
                  </div>
                </div>
                <p className="mt-2 text-center text-[10px] text-muted-foreground">Requests sync when you change month</p>
              </div>
              {!loading && !calendarLoading && unfiledDatesLabel && (
                <p className="text-xs leading-relaxed text-muted-foreground">
                  <span className="font-medium text-foreground/80">Unfiled clock OT:</span> {unfiledDatesLabel}
                </p>
              )}
              {!loading && !calendarLoading && !unfiledDatesLabel && otMonthBreakdown.unfiledH <= 0 && (
                <p className="text-xs text-muted-foreground">No clock-detected OT without an active filing this month.</p>
              )}
              <Button
                type="button"
                variant="outline"
                size="sm"
                className="h-9 w-full gap-2 border-border/80 text-sm font-medium"
                onClick={() => setOtDetailsOpen(true)}
                disabled={loading || calendarLoading}
              >
                <ListTree className="size-4 shrink-0 opacity-70" aria-hidden />
                View OT details
              </Button>
            </Motion.div>
            {monthTrend && (
              <div className="mt-3 flex flex-col gap-1 text-sm text-muted-foreground @sm:flex-row @sm:items-center @sm:justify-between">
                <span className={`inline-flex items-center gap-1 font-medium ${monthTrend.colorClass}`}>
                  {monthTrend.direction === 'up' ? (
                    <ArrowUpRight className="size-3.5" />
                  ) : monthTrend.direction === 'down' ? (
                    <ArrowDownRight className="size-3.5" />
                  ) : (
                    <Minus className="size-3.5" />
                  )}
                  <span>{monthTrend.label}</span>
                </span>
                <span>vs last month</span>
              </div>
            )}
          </CardContent>
        </Card>
        </Motion.div>
        {/* Attendance Summary — beside Leave Overview, same footprint as Today's Time */}
        <Motion.div variants={itemVariants} whileHover={{ y: -2, transition: { duration: 0.15 } }} className="@xl:col-start-1 @xl:row-start-2">
        <Card className="min-h-40 overflow-hidden rounded-xl border-border bg-card shadow-[0_12px_30px_-22px_rgba(15,23,42,0.65)] transition-all duration-200 hover:shadow-[0_18px_36px_-24px_rgba(15,23,42,0.75)] @sm:min-h-[11.2rem] dark:bg-card/85">
          <CardHeader className="flex flex-row items-center justify-between space-y-0 pb-2">
            <CardTitle className="text-sm font-extrabold uppercase tracking-wide text-muted-foreground">
              Attendance Efficiency Details
            </CardTitle>
            <div className="rounded-lg bg-orange-500/10 p-2">
              <CalendarDays className="size-4 text-orange-600 dark:text-orange-400" />
            </div>
          </CardHeader>
          <CardContent className="pt-0">
            <div className="flex min-w-0 items-center gap-0.5 rounded-lg border border-orange-500/25 p-0.5 bg-gradient-to-br from-orange-500/[0.08] via-background to-background shadow-sm ring-1 ring-orange-500/10 mb-2">
              <button type="button" className="flex size-7 shrink-0 items-center justify-center rounded-md text-orange-700 hover:bg-orange-500/10 dark:text-orange-400" onClick={goPrevCalendarMonth} aria-label="Previous month">
                <ChevronLeft className="size-3.5" />
              </button>
              <Select value={attendanceMonthValue} onValueChange={handleAttendanceMonthSelect}>
                <SelectTrigger size="sm" className="h-8 min-w-0 flex-1 gap-1.5 rounded-md border-0 bg-transparent px-2 shadow-none text-xs font-semibold text-foreground ring-0 focus:ring-0 focus-visible:ring-0 hover:bg-orange-500/5 data-[state=open]:bg-orange-500/8 @sm:text-sm">
                  <CalendarDays className="size-3.5 shrink-0 text-orange-600 dark:text-orange-400" aria-hidden />
                  <SelectValue placeholder="Select month" />
                </SelectTrigger>
                <SelectContent position="popper" className="max-h-56 min-w-[var(--radix-select-trigger-width)] rounded-xl border-border/80 p-1.5 shadow-xl">
                  {attendanceMonthOptions.map((option) => (
                    <SelectItem key={option.value} value={option.value} className="cursor-pointer rounded-lg py-2.5 pl-8 pr-3 text-sm font-medium focus:bg-orange-500/10 focus:text-orange-800 dark:focus:text-orange-300 data-[state=checked]:bg-orange-500/12 data-[state=checked]:font-semibold data-[state=checked]:text-orange-800 dark:data-[state=checked]:text-orange-300">
                      {option.label}
                      {option.isCurrent ? <span className="ml-1.5 text-xs font-normal text-muted-foreground">· Current</span> : null}
                    </SelectItem>
                  ))}
                </SelectContent>
              </Select>
              <button type="button" className="flex size-7 shrink-0 items-center justify-center rounded-md text-orange-700 hover:bg-orange-500/10 disabled:opacity-40 dark:text-orange-400" onClick={goNextCalendarMonth} disabled={!canGoNextMonth || loading || calendarLoading} aria-label="Next month">
                <ChevronRight className="size-3.5" />
              </button>
            </div>
            {loading || calendarLoading ? (
              <div className="flex h-24 items-center justify-center text-xs text-muted-foreground">Loading…</div>
            ) : (
              <button
                type="button"
                className="w-full text-left focus:outline-none focus-visible:ring-2 focus-visible:ring-ring/50 rounded-lg"
                onClick={() => setAttendanceSummaryModalOpen(true)}
                aria-label={`View full attendance details for ${getMonthLabel()}`}
              >
                <div className="grid grid-cols-3 divide-x divide-border/40">
                  <div className="flex flex-col items-center justify-center gap-1.5 py-4">
                    <span className="text-3xl font-extrabold tabular-nums tracking-tight text-foreground">
                      {Number(monthAttendanceMetrics.efficiency).toFixed(1)}
                      <span className="ml-0.5 text-lg font-semibold text-muted-foreground">%</span>
                    </span>
                    <span className={cn('inline-flex items-center gap-1 rounded-full border py-0.5 pl-1 pr-2.5', efficiencyBadgeClass(monthAttendanceMetrics.efficiency))}>
                      <span className="flex size-[15px] items-center justify-center rounded-full bg-white/60 text-[9px] font-bold" style={{ color: 'inherit' }}>
                        {(() => {
                          const e = monthAttendanceMetrics.efficiency
                          if (e >= 90) return 'A'
                          if (e >= 80) return 'B'
                          if (e >= 70) return 'C'
                          return 'D'
                        })()}
                      </span>
                      <span className="text-[10px] font-semibold">{efficiencyLabel(monthAttendanceMetrics.efficiency)}</span>
                    </span>
                  </div>
                  <div className="flex items-center justify-center py-4">
                    <div className="relative h-24 w-24">
                      {attendanceSummaryHasData ? (
                        <>
                          <ResponsiveContainer width="100%" height="100%">
                            <PieChart>
                              <Pie data={attendanceSummarySlices.filter((s) => !s.efficiency)} dataKey="chartValue" nameKey="label" cx="50%" cy="50%" innerRadius="55%" outerRadius="90%" paddingAngle={2} stroke="hsl(var(--background))" strokeWidth={2}>
                                {attendanceSummarySlices.filter((s) => !s.efficiency).map((slice) => (
                                  <Cell key={slice.id} fill={slice.color} />
                                ))}
                              </Pie>
                            </PieChart>
                          </ResponsiveContainer>
                          <div className="pointer-events-none absolute inset-0 flex flex-col items-center justify-center">
                            <span className="text-base font-bold tabular-nums leading-none text-foreground">{attendanceSummaryBaseDays}</span>
                            <span className="mt-px text-[9px] font-medium text-muted-foreground">days</span>
                          </div>
                        </>
                      ) : (
                        <div className="flex h-full items-center justify-center rounded-full border border-dashed border-border bg-muted/20 text-xs text-muted-foreground">No data</div>
                      )}
                    </div>
                  </div>
                  <div className="flex flex-col justify-center gap-1.5 py-4 pl-4 pr-2">
                    {attendanceSummarySlices.filter((s) => !s.efficiency).map((slice) => (
                      <div key={slice.id} className="grid grid-cols-[8px_1fr_28px_auto] items-center gap-x-2 gap-y-0">
                        <span className="size-2 rounded-full ring-1 ring-black/5" style={{ backgroundColor: slice.color }} aria-hidden />
                        <span className="text-xs text-muted-foreground">{slice.label}</span>
                        <span className="text-xs font-semibold tabular-nums text-foreground text-right">{slice.count}</span>
                        <span className="text-xs tabular-nums text-muted-foreground text-right w-12">({slice.percent}%)</span>
                      </div>
                    ))}
                  </div>
                </div>
              </button>
            )}
          </CardContent>
        </Card>
        </Motion.div>
        <Motion.div variants={itemVariants} whileHover={{ y: -2, transition: { duration: 0.15 } }} className="@xl:col-start-2 @xl:row-start-2">
        <Card className="overflow-hidden rounded-xl border-border bg-card shadow-[0_12px_30px_-22px_rgba(15,23,42,0.65)] transition-all duration-200 hover:shadow-[0_18px_36px_-24px_rgba(15,23,42,0.75)] dark:bg-card/85">
          <CardHeader className="flex flex-row items-center justify-between space-y-0 pb-2">
            <CardTitle className="text-sm font-extrabold uppercase tracking-wide text-muted-foreground">
              Performance
            </CardTitle>
            <div className="rounded-lg bg-violet-500/10 p-2">
              <FileCheck className="size-4 text-violet-600 dark:text-violet-300" />
            </div>
          </CardHeader>
          <CardContent>
            {evaluationLoading ? (
              <div className="flex h-28 items-center justify-center text-xs text-muted-foreground">Loading performance…</div>
            ) : (
              <button
                type="button"
                className="w-full rounded-2xl text-left focus:outline-none focus-visible:ring-2 focus-visible:ring-ring/50"
                onClick={() => setEvaluationDetailsOpen(true)}
                aria-label="View performance details"
              >
                <div className="grid gap-3 rounded-2xl border border-border/70 bg-muted/15 p-3 transition-colors hover:border-violet-500/35 hover:bg-violet-500/[0.03] sm:grid-cols-2">
                  <div className="rounded-xl border border-violet-500/25 bg-linear-to-br from-violet-500/[0.10] to-background p-4">
                    <div className="flex items-center justify-between gap-3">
                      <span className="flex size-9 items-center justify-center rounded-lg bg-violet-500/10 text-violet-700 dark:text-violet-200">
                        <Zap className="size-4" aria-hidden />
                      </span>
                      <Badge variant="outline" className="rounded-full border-violet-500/30 bg-background/80 text-violet-700 dark:text-violet-200">
                        KPI
                      </Badge>
                    </div>
                    <p className="mt-4 text-xs font-bold uppercase tracking-wide text-violet-700 dark:text-violet-200">
                      KPI Performance
                    </p>
                    <p className="mt-1 text-3xl font-extrabold tabular-nums tracking-tight text-foreground">
                      {hasPerformanceSnapshotAverage ? Number(performanceSnapshotAveragePercentage).toFixed(1) : '—'}
                      {hasPerformanceSnapshotAverage ? <span className="ml-1 text-base font-semibold text-muted-foreground">%</span> : null}
                    </p>
                    <p className="mt-1 text-xs text-muted-foreground">
                      {hasPerformanceSnapshotAverage
                        ? (performanceWidget?.source === 'merged_kpi_user_averages'
                          ? 'Overall KPI average'
                          : 'Monthly KPI snapshot result')
                        : 'No monthly KPI snapshots'}
                    </p>
                  </div>

                  <div className="rounded-xl border border-blue-500/25 bg-linear-to-br from-blue-500/[0.08] to-background p-4">
                    <div className="flex items-center justify-between gap-3">
                      <span className="flex size-9 items-center justify-center rounded-lg bg-blue-500/10 text-blue-700 dark:text-blue-200">
                        <FileCheck className="size-4" aria-hidden />
                      </span>
                      <Badge variant="outline" className="rounded-full border-blue-500/30 bg-background/80 text-blue-700 dark:text-blue-200">
                        Evaluation
                      </Badge>
                    </div>
                    <p className="mt-4 text-xs font-bold uppercase tracking-wide text-blue-700 dark:text-blue-200">
                      Evaluation Module
                    </p>
                    <p className="mt-1 text-3xl font-extrabold tabular-nums tracking-tight text-foreground">
                      {evaluationModuleWidget?.latest_percentage != null ? Number(evaluationModuleWidget.latest_percentage).toFixed(1) : '—'}
                      {evaluationModuleWidget?.latest_percentage != null ? <span className="ml-1 text-base font-semibold text-muted-foreground">%</span> : null}
                    </p>
                    <p className="mt-1 text-xs text-muted-foreground">
                      {(evaluationStats.active_assignments ?? 0) > 0
                        ? `${evaluationStats.active_assignments} open evaluation${Number(evaluationStats.active_assignments) === 1 ? '' : 's'}`
                        : evaluationModuleWidget?.latest_percentage != null
                          ? 'Latest evaluation result'
                          : 'No evaluation result'}
                    </p>
                  </div>
                </div>
              </button>
            )}
          </CardContent>
        </Card>
        </Motion.div>
      </Motion.div>

      {!loading && summary?.today?.ot_detection && summary.today.ot_detection.can_file && !otNoticeDismissed && (
        <Motion.div
          className="relative overflow-hidden rounded-lg border border-amber-500/40 bg-amber-50/80 px-4 py-3.5 dark:border-amber-400/30 dark:bg-amber-950/30"
          initial={{ opacity: 0, y: -8 }}
          animate={{ opacity: 1, y: 0 }}
          transition={{ duration: 0.25 }}
        >
          <button
            type="button"
            className="absolute right-2.5 top-2.5 rounded-md p-1 text-amber-600/60 hover:bg-amber-200/40 hover:text-amber-700 dark:text-amber-400/60 dark:hover:bg-amber-800/30 dark:hover:text-amber-300"
            onClick={() => setOtNoticeDismissed(true)}
            aria-label="Dismiss"
          >
            <X className="size-4" />
          </button>
          <div className="flex items-start gap-3">
            <div className="mt-0.5 rounded-lg bg-amber-500/15 p-2 dark:bg-amber-400/15">
              <Timer className="size-5 text-amber-600 dark:text-amber-400" />
            </div>
            <div className="flex-1 pr-6">
              <p className="text-sm font-semibold text-amber-900 dark:text-amber-200">
                {summary.today.ot_detection.pre_shift && !summary.today.ot_detection.post_shift
                  ? 'You clocked in before your scheduled shift. Would you like to file a pre-shift overtime request?'
                  : !summary.today.ot_detection.pre_shift && summary.today.ot_detection.post_shift
                    ? 'You worked beyond your scheduled shift. Would you like to file an overtime request?'
                    : 'You have possible pre-shift and post-shift overtime. Would you like to file an overtime request?'}
              </p>
              <div className="mt-1 space-y-0.5 text-sm text-amber-800/80 dark:text-amber-300/70">
                {summary.today.ot_detection.pre_shift && (
                  <p>
                    Pre-shift: {formatTime(summary.today.ot_detection.pre_shift.clock_in)}
                    {' – '}
                    {formatTime(summary.today.ot_detection.schedule_start)}
                    {' '}({summary.today.ot_detection.pre_shift.label})
                  </p>
                )}
                {summary.today.ot_detection.post_shift && (
                  <p>
                    Post-shift: {formatTime(summary.today.ot_detection.schedule_end)}
                    {' – '}
                    {formatTime(summary.today.ot_detection.post_shift.work_end)}
                    {' '}({summary.today.ot_detection.post_shift.label})
                  </p>
                )}
                <p className="pt-0.5">Total detected OT: {summary.today.ot_detection.total_extra_label}.</p>
              </div>
          <div className="mt-2.5 flex flex-col gap-2 @sm:flex-row @sm:flex-wrap">
                {(() => {
                  const hasPre = Boolean(summary.today.ot_detection.pre_shift)
                  const hasPost = Boolean(summary.today.ot_detection.post_shift)
                  const todayDate = summary.today.ot_detection.date || formatLocalDateKey(new Date())
                  if (hasPre && hasPost) {
                    return (
                      <>
                        <Button
                          size="sm"
                          className="h-9 w-full px-3 text-xs @sm:h-8 @sm:w-auto"
                          onClick={() => goSelf(`overtime?date=${encodeURIComponent(todayDate)}&segments=pre_shift`)}
                        >
                          File pre-shift
                        </Button>
                        <Button
                          size="sm"
                          className="h-9 w-full px-3 text-xs @sm:h-8 @sm:w-auto"
                          onClick={() => goSelf(`overtime?date=${encodeURIComponent(todayDate)}&segments=post_shift`)}
                        >
                          File post-shift
                        </Button>
                      </>
                    )
                  }
                  return (
                    <Button
                      size="sm"
                      className="h-9 w-full px-3 text-xs @sm:h-8 @sm:w-auto"
                      onClick={() => goSelf('overtime')}
                    >
                      File OT
                    </Button>
                  )
                })()}
                <Button
                  size="sm"
                  variant="ghost"
                  className="h-9 w-full px-3 text-xs text-amber-700 hover:bg-amber-200/40 hover:text-amber-800 @sm:h-8 @sm:w-auto dark:text-amber-400 dark:hover:bg-amber-800/30"
                  onClick={() => setOtNoticeDismissed(true)}
                >
                  Ignore
                </Button>
              </div>
            </div>
          </div>
        </Motion.div>
      )}

      {!loading && summary?.today?.ot_detection && summary.today.ot_detection.has_filed_ot && (
        <Motion.div
          className="rounded-lg border border-emerald-500/30 bg-emerald-50/60 px-4 py-3 dark:border-emerald-400/20 dark:bg-emerald-950/20"
          initial={{ opacity: 0, y: -8 }}
          animate={{ opacity: 1, y: 0 }}
          transition={{ duration: 0.25 }}
        >
          <div className="flex items-center gap-3">
            <div className="rounded-lg bg-emerald-500/15 p-2 dark:bg-emerald-400/15">
              <Timer className="size-4 text-emerald-600 dark:text-emerald-400" />
            </div>
            <p className="text-sm text-emerald-800 dark:text-emerald-300">
              OT filed for today — status:{' '}
              <span className="font-semibold capitalize">{summary.today.ot_detection.filed_ot_status}</span>
              {' '}({summary.today.ot_detection.total_extra_label})
            </p>
          </div>
        </Motion.div>
      )}

      <Motion.div
        className="flex flex-col gap-3 rounded-xl border border-border bg-card px-4 py-4 text-sm shadow-[0_12px_30px_-24px_rgba(15,23,42,0.7)] @sm:flex-row @sm:items-center @sm:justify-between @md:px-5 @md:text-base dark:bg-card/85"
        variants={itemVariants}
        initial="hidden"
        whileInView="visible"
        viewport={scrollViewport}
        transition={scrollRevealTransition}
      >
        <p className="flex min-w-0 items-start gap-3 text-sm text-muted-foreground @sm:items-center">
          <span className="flex size-9 shrink-0 items-center justify-center rounded-full border border-orange-200 bg-orange-50 text-orange-600">
            <Zap className="size-4" />
          </span>
          <span>Need to take action? Jump straight from your dashboard.</span>
        </p>
        <div className="flex w-full flex-col gap-2 @sm:w-auto @sm:flex-row @sm:flex-wrap">
          <Button
            size="sm"
            className="h-10 w-full rounded-lg bg-orange-600 px-5 text-sm font-bold text-white shadow-[0_12px_24px_-16px_rgba(234,88,12,0.8)] hover:bg-orange-700 @sm:w-auto"
            onClick={() => goSelf('requests')}
          >
            Request leave
          </Button>
          <Button
            size="sm"
            variant="outline"
            className="h-10 w-full rounded-lg border-border px-5 text-sm font-bold text-foreground @sm:w-auto"
            onClick={() => goSelf('attendance')}
          >
            View full attendance
          </Button>
          <Button
            size="sm"
            variant="outline"
            className="h-10 w-full rounded-lg border-border px-5 text-sm font-bold text-foreground @sm:w-auto"
            onClick={() => goSelf('overtime')}
          >
            File overtime
          </Button>
          {user?.has_face ? (
            <>
              <Button
                size="sm"
                variant="outline"
                className="h-10 w-full gap-2 rounded-lg border-border px-5 text-sm font-bold text-foreground @sm:w-auto"
                onClick={() => openFaceAttendance('clock_in')}
                disabled={!canClockWithFace}
              >
                <ScanFace className="size-4" />
                Face Clock In
              </Button>
              <Button
                size="sm"
                variant="outline"
                className="h-10 w-full gap-2 rounded-lg border-border px-5 text-sm font-bold text-foreground @sm:w-auto"
                onClick={() => openFaceAttendance('clock_out')}
                disabled={!canClockWithFace}
              >
                <ScanFace className="size-4" />
                Face Clock Out
              </Button>
            </>
          ) : (
            <Button
              size="sm"
              variant="outline"
              className="h-10 w-full gap-2 rounded-lg border-border px-5 text-sm font-bold text-foreground @sm:w-auto"
              onClick={() => goSelf('qr')}
            >
              <ScanFace className="size-4" />
              Register Face
            </Button>
          )}
        </div>
      </Motion.div>

      {/* Attendance calendar */}
      <Motion.div
        className="grid min-w-0 gap-4 @lg:gap-6 @xl:grid-cols-[minmax(0,1fr)_minmax(18rem,22rem)]"
        variants={containerVariants}
        initial="hidden"
        whileInView="visible"
        viewport={scrollViewport}
        transition={scrollRevealTransition}
      >
        <Motion.div variants={itemVariants}>
          <Card className="overflow-hidden rounded-xl border-border bg-card shadow-[0_14px_36px_-26px_rgba(15,23,42,0.8)] dark:bg-card/85">
            <CardHeader className="bg-card px-4 @md:px-6 dark:bg-card/85">
              <CardTitle className="flex items-center gap-3 text-lg font-extrabold tracking-tight text-foreground @md:text-xl">
                <span className="flex size-7 items-center justify-center rounded-lg bg-orange-50 text-orange-600">
                  <CalendarDays className="size-4" />
                </span>
                Attendance calendar
              </CardTitle>
              <CardDescription className="text-sm text-muted-foreground @md:text-base">
                Use the arrows or tap the month to jump to today. Each day shows your attendance status; tap a day for
                time in/out and totals.
              </CardDescription>
            </CardHeader>
            <CardContent className="space-y-3 p-0">
              <div className="bg-card px-2.5 py-2.5 @sm:px-4 md:px-6 dark:bg-card/85">
                <div className="mx-auto flex w-full max-w-6xl min-w-0 items-center justify-center gap-0.5 rounded-xl border border-border bg-card p-1 dark:bg-card/85">
                  <Button
                    type="button"
                    variant="ghost"
                    size="icon"
                    className="size-9 shrink-0 rounded-lg hover:bg-background/80 @sm:size-10"
                    onClick={goPrevCalendarMonth}
                    aria-label="Previous month"
                  >
                    <ChevronLeft className="size-4 @sm:size-[18px]" />
                  </Button>
                  <button
                    type="button"
                    onClick={goCalendarToday}
                    className="min-w-0 flex-1 truncate px-2 py-2 text-center text-sm font-semibold tracking-tight text-foreground hover:bg-background/60 dark:hover:bg-background/10 @sm:text-base"
                  >
                    {MONTHS[calendarMonth]} {calendarYear}
                  </button>
                  <Button
                    type="button"
                    variant="ghost"
                    size="icon"
                    className="size-9 shrink-0 rounded-lg hover:bg-background/80 disabled:opacity-40 @sm:size-10"
                    onClick={goNextCalendarMonth}
                    disabled={!canGoNextMonth || loading || calendarLoading}
                    aria-label="Next month"
                  >
                    <ChevronRight className="size-4 @sm:size-[18px]" />
                  </Button>
                </div>
                {calendarLoading && (
                  <div className="mx-auto mt-2 h-1 w-full max-w-6xl overflow-hidden rounded-full bg-muted">
                    <div className="h-full w-1/3 animate-pulse rounded-full bg-orange-500/70" />
                  </div>
                )}
              </div>
              <div className="mt-2 space-y-2 px-2.5 pb-3 @sm:px-4 md:pb-4">
                <div className="mx-auto w-full max-w-6xl min-w-0">
                  <div className="grid w-full min-w-0 grid-cols-7 grid-rows-[auto_repeat(6,minmax(4.75rem,auto))] gap-1 @sm:grid-rows-[auto_repeat(6,minmax(5.25rem,auto))] @sm:gap-2">
                    {WEEKDAYS.map((w) => (
                      <div
                        key={w}
                        className="min-w-0 rounded-md bg-card px-0.5 py-1.5 text-center text-[10px] font-extrabold uppercase leading-tight tracking-wide text-muted-foreground @sm:px-1.5 @sm:py-2.5 @sm:text-xs"
                      >
                        <span className="@sm:hidden" aria-hidden>
                          {w.charAt(0)}
                        </span>
                        <span className="hidden @sm:inline">{w}</span>
                      </div>
                    ))}
                    {attendanceCalendarCells.map((cell, idx) => {
                      const key = cell.dateStr
                      const record = calendarRecordForTile(key)
                      const todayKeyNow = formatLocalDateKey(new Date())
                      const ctx = {
                        scheduleAssigned: summary?.schedule_assigned !== false,
                        todayKey: todayKeyNow,
                        isRestDay,
                        isPastAbsentCutoff,
                        isAdjacent: cell.isAdjacent,
                      }
                      const visual = getCalendarDayVisual(record, key, ctx)
                      const lines = tileTooltipLines(record, key)
                      const timeLines = calendarTileTimeLines(record)
                      const monthShort = MONTHS[cell.month]?.slice(0, 3) ?? ''
                      const isToday = key === todayKeyNow
                      const isSelected = selectedDay != null && formatLocalDateKey(selectedDay) === key
                      const tooltipTitle = lines.length ? lines.join('\n') : undefined

                      return (
                        <div key={`${key}-${idx}`} className="flex min-h-19 min-w-0 @sm:min-h-21">
                          <button
                            type="button"
                            title={tooltipTitle}
                            onClick={() => setSelectedDay(new Date(cell.year, cell.month, cell.day))}
                            className={cn(
                              visual.tileClass,
                              'text-sm @sm:text-base',
                              isToday && 'ring-1 ring-orange-500 ring-offset-2 ring-offset-background',
                              isSelected &&
                                'z-1 border-orange-500 ring-1 ring-orange-300 ring-offset-1 ring-offset-background',
                              cell.isAdjacent && record && 'opacity-[0.88]',
                            )}
                          >
                            <div className="flex items-start justify-between gap-0.5">
                              <span
                                className={cn(
                                  'text-xs font-semibold tabular-nums leading-none tracking-tight @sm:text-lg',
                                  cell.isAdjacent && !record && 'text-muted-foreground/80',
                                )}
                              >
                                {isToday ? (
                                  <span className="inline-flex min-w-5 items-center justify-center rounded-md bg-orange-500 px-1 py-0.5 text-[10px] font-semibold text-white @sm:min-w-9 @sm:px-2.5 @sm:text-base">
                                    {cell.day}
                                  </span>
                                ) : (
                                  cell.day
                                )}
                              </span>
                              {cell.isAdjacent && (
                                <span className="shrink-0 text-[7px] font-medium uppercase tracking-wide text-muted-foreground @sm:text-[9px]">
                                  {monthShort}
                                </span>
                              )}
                            </div>
                            {visual.badge ? (
                              <div className="mt-auto space-y-0.5 pt-1">
                                <span className={visual.badgeClass}>{visual.badge}</span>
                                {timeLines.length > 0 && (
                                  <div className="space-y-0.5 text-left text-[8px] font-semibold leading-tight text-muted-foreground @sm:text-[10px]">
                                    {timeLines.slice(0, 2).map((row) => (
                                      <div key={row.label} className="truncate tabular-nums">
                                        <span className="uppercase tracking-wide">{row.label}</span>{' '}
                                        <span className="text-foreground/80">{row.value}</span>
                                      </div>
                                    ))}
                                  </div>
                                )}
                              </div>
                            ) : (
                              <div className="mt-auto min-h-4" aria-hidden />
                            )}
                          </button>
                        </div>
                      )
                    })}
                  </div>
                </div>
              </div>
            </CardContent>
          </Card>
        </Motion.div>
        <Motion.div variants={itemVariants}>
          <Card className="h-full overflow-hidden rounded-xl border-border bg-card shadow-[0_14px_36px_-26px_rgba(15,23,42,0.8)] dark:bg-card/85">
            <CardHeader className="space-y-1.5 pb-3">
              <CardTitle className="flex items-center gap-3 text-lg font-extrabold tracking-tight text-foreground">
                <span className="flex size-7 items-center justify-center rounded-lg bg-orange-50 text-orange-600 dark:bg-orange-500/10 dark:text-orange-300">
                  <CalendarDays className="size-4" />
                </span>
                Holiday calendar
              </CardTitle>
              <CardDescription className="text-sm text-muted-foreground">Upcoming holidays</CardDescription>
            </CardHeader>
            <CardContent className="space-y-4 px-4 @md:px-6">
              <div className="space-y-2">
                {holidayLoading ? (
                  Array.from({ length: 3 }).map((_, index) => (
                    <div key={index} className="h-[4.6rem] animate-pulse rounded-xl border border-border/60 bg-muted/35" />
                  ))
                ) : upcomingHolidays.length > 0 ? (
                  upcomingHolidays.map((holiday) => {
                    const dateParts = formatHolidayCardDate(holiday.date || holiday.holiday_date)
                    return (
                      <div
                        key={`${holiday.date}-${holiday.name}-${holiday.scope || 'nationwide'}`}
                        className="flex min-w-0 items-center gap-3 rounded-xl border border-border/70 bg-card px-3 py-3 shadow-sm dark:bg-card/70"
                      >
                        <div className="flex w-12 shrink-0 flex-col items-center justify-center rounded-lg bg-orange-50 px-2 py-1.5 text-orange-700 dark:bg-orange-500/10 dark:text-orange-300">
                          <span className="text-[10px] font-extrabold uppercase tracking-wide">{dateParts.month}</span>
                          <span className="text-xl font-black leading-none tabular-nums">{dateParts.day}</span>
                        </div>
                        <div className="min-w-0 flex-1">
                          <p className="truncate text-sm font-extrabold text-foreground">{holiday.name || 'Holiday'}</p>
                          <p className="mt-0.5 truncate text-xs font-medium text-muted-foreground">{dateParts.weekday}</p>
                          <p className="mt-1 truncate text-[11px] font-semibold text-orange-700 dark:text-orange-300">
                            {holidayTypeDisplay(holiday.type)}
                          </p>
                          <p className="mt-1 truncate text-[11px] text-muted-foreground">
                            Scope: {holiday.scope_label || 'Nationwide'}
                            {holiday.scope_target ? ` · ${holiday.scope_target}` : ''}
                          </p>
                          {holiday.scope_path && holiday.scope_path !== holiday.scope_target ? (
                            <p className="mt-0.5 truncate text-[10px] text-muted-foreground">{holiday.scope_path}</p>
                          ) : null}
                        </div>
                      </div>
                    )
                  })
                ) : (
                  <div className="rounded-xl border border-dashed border-border bg-muted/25 px-4 py-6 text-center">
                    <p className="text-sm font-semibold text-foreground">No upcoming holidays found.</p>
                    <p className="mt-1 text-xs text-muted-foreground">Applicable holidays will appear here.</p>
                  </div>
                )}
              </div>

              <Button
                type="button"
                variant="outline"
                className="h-10 w-full gap-2 rounded-lg border-orange-500/40 text-sm font-bold text-orange-700 hover:bg-orange-50 dark:text-orange-300 dark:hover:bg-orange-500/10"
                onClick={() => goSelf('holidays')}
              >
                <CalendarDays className="size-4" />
                View full holiday calendar
              </Button>

              <div className="space-y-3 border-t border-border/60 pt-4">
                <p className="text-sm font-extrabold text-foreground">Holiday summary ({calendarYear})</p>
                <div className="space-y-2 text-sm">
                  <div className="flex items-center justify-between gap-3">
                    <span className="text-muted-foreground">Regular Holidays</span>
                    <span className="font-semibold tabular-nums text-foreground">{holidayLoading ? '—' : currentHolidaySummary.regular}</span>
                  </div>
                  <div className="flex items-center justify-between gap-3">
                    <span className="text-muted-foreground">Special (Non-working) Days</span>
                    <span className="font-semibold tabular-nums text-foreground">{holidayLoading ? '—' : currentHolidaySummary.special}</span>
                  </div>
                  <div className="flex items-center justify-between gap-3">
                    <span className="text-muted-foreground">Local Holidays</span>
                    <span className="font-semibold tabular-nums text-foreground">{holidayLoading ? '—' : currentHolidaySummary.local}</span>
                  </div>
                  <div className="flex items-center justify-between gap-3 border-t border-border/60 pt-2">
                    <span className="font-semibold text-muted-foreground">Total Holidays</span>
                    <span className="font-extrabold tabular-nums text-foreground">{holidayLoading ? '—' : currentHolidaySummary.total}</span>
                  </div>
                </div>
              </div>

              <div className="flex items-start gap-2 rounded-xl border border-border/70 bg-muted/25 px-3 py-3 text-xs text-muted-foreground">
                <Info className="mt-0.5 size-4 shrink-0" aria-hidden />
                <p>Dates may change based on official government announcements.</p>
              </div>
            </CardContent>
          </Card>
        </Motion.div>
      </Motion.div>

      <Dialog
        open={selectedDay != null}
        onOpenChange={(open) => {
          if (!open) setSelectedDay(null)
        }}
      >
        <DialogContent
          className="w-[calc(100vw-1rem)] max-w-md rounded-2xl border-border sm:max-w-md"
          innerClassName="max-h-[min(92vh,40rem)] gap-0 overflow-y-auto p-0 pr-0"
          closeButtonClassName="right-4 top-4 bg-card/95"
        >
          {selectedDayDetails && (
            <>
              <DialogHeader className="border-b border-border/70 bg-linear-to-br from-orange-50 via-card to-card px-5 py-5 pr-16 text-left dark:from-orange-500/10 dark:via-card dark:to-card">
                <div className="flex items-center gap-3">
                  <span className="flex size-10 shrink-0 items-center justify-center rounded-xl bg-orange-500/10 text-orange-600 dark:text-orange-300">
                    <CalendarDays className="size-5" />
                  </span>
                  <div className="min-w-0">
                    <DialogTitle className="truncate text-xl font-extrabold tracking-tight text-foreground">
                      {formatTodayDate(selectedDayDetails.date_iso)}
                    </DialogTitle>
                    {selectedDayDetails.status != null && (
                      <p className="mt-1 text-sm font-semibold text-orange-700 dark:text-orange-300">
                        {getDisplayStatus(
                          selectedDayDetails.status,
                          selectedDayDetails.date_iso,
                          selectedDayDetails.late_label,
                          selectedDayDetails.late_minutes,
                          selectedDayDetails.status_label || selectedDayDetails.display_badge,
                          selectedDayDetails.presence_label,
                          selectedDayDetails.presence_issue,
                        ) || '—'}
                      </p>
                    )}
                  </div>
                </div>
                <DialogDescription className="sr-only">
                  {`Attendance details for ${formatTodayDate(selectedDayDetails.date_iso)}.`}
                </DialogDescription>
              </DialogHeader>
              <div className="space-y-4 px-5 py-5">
                {selectedDayDetails.status == null ? (
                  <div className="rounded-xl border border-dashed border-border bg-muted/30 p-4 text-sm text-muted-foreground">
                    No attendance record for this date.
                  </div>
                ) : (
                  <>
                    <div className="grid gap-2 text-sm">
                      {(() => {
                        const scheduleIn = selectedDayDetails.schedule_in
                        const scheduleOut = selectedDayDetails.schedule_out
                        const scheduleLabel = selectedDayDetails.schedule_label
                        if (scheduleLabel === 'Rest Day' || selectedDayDetails.is_rest_day) {
                          return (
                            <div className="flex items-center justify-between gap-3 rounded-xl border border-border/70 bg-muted/20 px-3 py-2.5">
                              <span className="font-medium text-muted-foreground">Schedule</span>
                              <span className="font-semibold text-foreground">Rest Day</span>
                            </div>
                          )
                        }
                        if (scheduleIn && scheduleOut) {
                          return (
                            <div className="flex items-center justify-between gap-3 rounded-xl border border-border/70 bg-muted/20 px-3 py-2.5">
                              <span className="font-medium text-muted-foreground">Schedule</span>
                              <span className="font-semibold tabular-nums text-foreground">
                                {formatTime(scheduleIn)} - {formatTime(scheduleOut)}
                              </span>
                            </div>
                          )
                        }
                        return null
                      })()}
                      {(() => {
                        const timeIn = selectedDayDetails.formatted_time_in || selectedDayDetails.time_in
                        const timeOut = selectedDayDetails.formatted_time_out || selectedDayDetails.time_out
                        return (
                          <>
                      {timeIn ? (
                        <div className="flex items-center justify-between gap-3 rounded-xl border border-border/70 bg-muted/20 px-3 py-2.5">
                          <span className="font-medium text-muted-foreground">In</span>
                          <span className="font-semibold tabular-nums text-foreground">{formatTime(timeIn)}</span>
                        </div>
                      ) : null}
                      {timeOut ? (
                        <div className="flex items-center justify-between gap-3 rounded-xl border border-border/70 bg-muted/20 px-3 py-2.5">
                          <span className="font-medium text-muted-foreground">Out</span>
                          <span className="font-semibold tabular-nums text-foreground">{formatTime(timeOut)}</span>
                        </div>
                      ) : null}
                      {timeIn && timeOut ? (
                        <div className="flex items-center justify-between gap-3 rounded-xl border border-border/70 bg-card px-3 py-2.5">
                          <span className="font-medium text-muted-foreground">Status</span>
                          <span className="font-semibold text-foreground">Clocked out</span>
                        </div>
                      ) : null}
                          </>
                        )
                      })()}
                      {(selectedDayDetails.late_label ||
                        (typeof selectedDayDetails.late_minutes === 'number' && selectedDayDetails.late_minutes > 0)) && (
                        <div className="flex items-center justify-between gap-3 rounded-xl border border-amber-500/25 bg-amber-500/10 px-3 py-2.5">
                          <span className="font-medium text-muted-foreground">Status</span>
                          <span className="font-semibold text-amber-700 dark:text-amber-300">
                            {selectedDayDetails.late_label || `${selectedDayDetails.late_minutes} min`}
                          </span>
                        </div>
                      )}
                      {typeof selectedDayDetails.undertime_minutes === 'number' &&
                        selectedDayDetails.undertime_minutes > 0 && (
                          <div className="flex items-center justify-between gap-3 rounded-xl border border-orange-500/25 bg-orange-500/10 px-3 py-2.5">
                            <span className="font-medium text-muted-foreground">Undertime</span>
                            <span className="font-semibold text-orange-700 dark:text-orange-300">
                              {selectedDayDetails.undertime_minutes} min short
                            </span>
                          </div>
                        )}
                      {typeof selectedDayDetails.unapproved_overtime_hours === 'number' &&
                        selectedDayDetails.unapproved_overtime_hours > 0 && (
                          <div className="flex items-center justify-between gap-3 rounded-xl border border-amber-500/25 bg-amber-500/10 px-3 py-2.5">
                            <span className="font-medium text-muted-foreground">Unapproved OT</span>
                            <span className="font-semibold text-amber-700 dark:text-amber-300">
                              {selectedDayDetails.unapproved_overtime_hours.toFixed(2)} hrs
                            </span>
                          </div>
                        )}
                      {typeof selectedDayDetails.approved_overtime_hours === 'number' &&
                        selectedDayDetails.approved_overtime_hours > 0 && (
                          <div className="flex items-center justify-between gap-3 rounded-xl border border-emerald-500/25 bg-emerald-500/10 px-3 py-2.5">
                            <span className="font-medium text-muted-foreground">Approved OT</span>
                            <span className="font-semibold text-emerald-700 dark:text-emerald-300">
                              {selectedDayDetails.approved_overtime_hours.toFixed(2)} hrs
                            </span>
                          </div>
                        )}
                      {typeof selectedDayDetails.payable_overtime_hours === 'number' &&
                        selectedDayDetails.payable_overtime_hours > 0 && (
                          <div className="flex items-center justify-between gap-3 rounded-xl border border-emerald-500/25 bg-emerald-500/10 px-3 py-2.5">
                            <span className="font-medium text-muted-foreground">Payable OT</span>
                            <span className="font-semibold text-emerald-700 dark:text-emerald-300">
                              {selectedDayDetails.payable_overtime_hours.toFixed(2)} hrs
                            </span>
                          </div>
                        )}
                      {typeof selectedDayDetails.payroll_impact_hours === 'number' &&
                        Number.isFinite(selectedDayDetails.payroll_impact_hours) && (
                          <div className="flex items-center justify-between gap-3 rounded-xl border border-blue-500/25 bg-blue-500/10 px-3 py-2.5">
                            <span className="font-medium text-muted-foreground">Payroll Impact</span>
                            <span className="font-semibold tabular-nums text-blue-700 dark:text-blue-300">
                              {selectedDayDetails.payroll_impact_hours.toFixed(2)} hrs
                            </span>
                          </div>
                        )}
                      {(() => {
                        const th = getAttendanceTotalHours(selectedDayDetails)
                        if (th == null) return null
                        return (
                          <div className="flex items-center justify-between gap-3 rounded-xl border border-border/70 bg-card px-3 py-3 shadow-sm">
                            <span className="font-semibold text-foreground">Total</span>
                            <span className="text-lg font-extrabold tabular-nums text-foreground">{th.toFixed(2)}h</span>
                          </div>
                        )
                      })()}
                      {!(selectedDayDetails.formatted_time_in || selectedDayDetails.time_in) &&
                        !(selectedDayDetails.formatted_time_out || selectedDayDetails.time_out) &&
                        getAttendanceTotalHours(selectedDayDetails) == null && (
                        <p className="rounded-xl border border-dashed border-border bg-muted/30 p-4 text-sm text-muted-foreground">
                          {selectedDayDetails.schedule_in && selectedDayDetails.schedule_out
                            ? 'No clock in/out yet for this scheduled day.'
                            : 'No clock in/out details captured for this day.'}
                        </p>
                      )}
                    </div>
                    {shouldOfferCorrection(selectedDayDetails) ? (
                      <div className="pt-2">
                        <Button variant="outline" className="w-full gap-2" type="button" onClick={handleFileCorrection}>
                          <FileText className="size-4" aria-hidden />
                          File correction
                        </Button>
                      </div>
                    ) : null}
                  </>
                )}
              </div>
            </>
          )}
        </DialogContent>
      </Dialog>

      <Dialog
        open={attendanceSummaryModalOpen}
        onOpenChange={setAttendanceSummaryModalOpen}
      >
        <DialogContent
          className="w-[calc(100vw-1rem)] max-w-6xl gap-0 overflow-hidden rounded-2xl border-border/70 bg-background p-0 shadow-[0_30px_90px_-28px_rgba(15,23,42,0.55)] sm:max-w-6xl"
          innerClassName="max-h-[92vh] gap-0 overflow-y-auto p-0"
        >
          <DialogHeader className="border-b-0 px-5 pb-3 pt-6 pr-14 text-left sm:px-7 sm:pb-4 sm:pt-7">
            <div className="flex flex-col gap-4 sm:flex-row sm:items-start sm:justify-between">
              <div>
                <DialogTitle className="text-xl font-bold tracking-tight text-foreground sm:text-2xl">
                  Attendance Efficiency Details
                </DialogTitle>
                <DialogDescription className="mt-1.5 text-sm text-muted-foreground sm:text-base">
                  {getMonthLabel()}
                  {attendanceSummaryBaseDays > 0 && (
                    <> <span className="px-1.5">•</span> {attendanceSummaryBaseDays} scheduled work day{attendanceSummaryBaseDays === 1 ? '' : 's'}</>
                  )}
                </DialogDescription>
              </div>
              <Select value={attendanceMonthValue} onValueChange={handleAttendanceMonthSelect}>
                <SelectTrigger className="h-10 w-full shrink-0 rounded-lg border-border bg-background px-3 text-sm font-medium shadow-sm sm:w-40" aria-label="Select attendance summary month">
                  <CalendarDays className="size-4 text-muted-foreground" aria-hidden />
                  <SelectValue placeholder="Select month" />
                </SelectTrigger>
                <SelectContent position="popper" className="max-h-64 rounded-xl">
                  {attendanceMonthOptions.map((option) => (
                    <SelectItem key={option.value} value={option.value} className="rounded-lg">
                      {option.label}
                    </SelectItem>
                  ))}
                </SelectContent>
              </Select>
            </div>
          </DialogHeader>

          {calendarLoading ? (
            <div className="flex min-h-[34rem] items-center justify-center px-5 py-10 text-sm text-muted-foreground">
              Loading attendance summary…
            </div>
          ) : (
            <div className="px-5 pb-6 sm:px-7 sm:pb-7">
              {/* Header Section */}
              <div className="mb-5 flex flex-col gap-4 rounded-xl border border-border/70 bg-card p-5 shadow-sm sm:flex-row sm:items-center sm:justify-between">
                <div className="flex min-w-0 flex-1 flex-col gap-1">
                  <div className="flex items-center gap-2">
                    <h3 className="text-lg font-bold text-foreground">Attendance Efficiency Details</h3>
                    <div className="h-6 w-px bg-border" />
                    <span className="text-sm font-medium text-muted-foreground">{getMonthLabel()}</span>
                  </div>
                  <div className="space-y-1 text-sm text-muted-foreground">
                    <p>Employee: <span className="font-medium text-foreground">{employeeDisplayName}</span></p>
                    {monthAttendanceMetrics.classification && (
                      <p>Classification: <span className="font-medium text-foreground">{monthAttendanceMetrics.classification}</span></p>
                    )}
                    <p>Period: <span className="font-medium text-foreground">{getMonthLabel()}</span> &middot; {attendanceSummaryBaseDays} scheduled work day{attendanceSummaryBaseDays === 1 ? '' : 's'}</p>
                  </div>
                </div>
                <div className="flex shrink-0 items-center gap-3">
                  <div className="flex flex-col items-end">
                    <span className="text-xs font-semibold uppercase tracking-wide text-muted-foreground">Efficiency</span>
                    <span className="text-2xl font-bold tabular-nums text-foreground">
                      {Number(monthAttendanceMetrics.efficiency).toFixed(2)}%
                    </span>
                  </div>
                  <span className={cn('inline-flex items-center rounded-full border px-3 py-1 text-xs font-bold', efficiencyBadgeClass(monthAttendanceMetrics.efficiency))}>
                    {efficiencyLabel(monthAttendanceMetrics.efficiency)}
                  </span>
                </div>
              </div>

              {(() => {
                const expDays = attendanceSummaryBaseDays
                const pdDays = monthAttendanceMetrics.presentDays + monthAttendanceMetrics.lateDays
                const lostDays = expDays - pdDays
                const expHrs = Number(monthAttendanceMetrics.expectedScheduledHours)
                const piHrs = Number(monthAttendanceMetrics.payrollImpactHours)
                const awHrs = Number(monthAttendanceMetrics.actualWorkedHours)
                const lostHrs = Math.max(0, Number(monthAttendanceMetrics.lostHours ?? expHrs - piHrs))
                return (
                  <section className="mb-5 grid gap-3 sm:grid-cols-2 lg:grid-cols-4">
                    {[
                      { label: 'Expected', days: expDays, hrs: expHrs, cls: 'from-blue-50/80 to-background border-blue-200/70 dark:from-blue-500/10 dark:border-blue-500/20' },
                      { label: 'Payroll Impact', days: pdDays, hrs: piHrs, cls: 'from-emerald-50/80 to-background border-emerald-200/70 dark:from-emerald-500/10 dark:border-emerald-500/20' },
                      { label: 'Actual Worked', days: pdDays, hrs: awHrs, cls: 'from-violet-50/80 to-background border-violet-200/70 dark:from-violet-500/10 dark:border-violet-500/20' },
                      { label: 'Lost', days: lostDays, hrs: lostHrs, cls: 'from-amber-50/80 to-background border-amber-200/70 dark:from-amber-500/10 dark:border-amber-500/20' },
                    ].map((metric) => (
                      <div key={metric.label} className={cn('flex items-center justify-between rounded-xl border bg-gradient-to-br p-4 shadow-sm', metric.cls)}>
                        <div>
                          <p className="text-xs font-semibold uppercase tracking-wide text-gray-500 dark:text-muted-foreground">{metric.label}</p>
                          <p className="mt-1.5 text-xl font-bold tabular-nums text-black dark:text-foreground">
                            {metric.days} day{metric.days === 1 ? '' : 's'} <span className="text-sm font-normal text-muted-foreground">({metric.hrs.toFixed(2)}h)</span>
                          </p>
                        </div>
                      </div>
                    ))}
                  </section>
                )
              })()}

              <section className="mb-5 grid gap-5 lg:grid-cols-[1fr_1.2fr]">
                {/* Attendance Metrics */}
                <div className="rounded-xl border border-border/70 bg-card p-5 shadow-sm">
                  <h4 className="mb-3 text-sm font-bold uppercase tracking-wide text-foreground">Attendance Metrics</h4>
                  <div className="grid grid-cols-2 gap-x-6 gap-y-2.5 text-sm">
                    {[
                      { label: 'Present days', value: monthAttendanceMetrics.presentDays, color: '#22c55e' },
                      { label: 'Absent days', value: monthAttendanceMetrics.absentDays, color: '#ef4444' },
                      { label: 'Late days', value: monthAttendanceMetrics.lateDays, color: '#f97316' },
                      { label: 'Undertime days', value: monthAttendanceMetrics.undertimeDays, color: '#eab308' },
                      { label: 'Rest days', value: monthAttendanceMetrics.restDays, color: '#94a3b8' },
                      { label: 'Rest days worked', value: monthAttendanceMetrics.restDaysWorked, color: '#6366f1' },
                      { label: 'Leave days', value: monthAttendanceMetrics.leaveDays, color: '#3b82f6' },
                      { label: 'Holidays', value: monthAttendanceMetrics.holidayDays, color: '#06b6d4' },
                    ].map((item) => (
                      <div key={item.label} className="flex items-center justify-between border-b border-border/60 pb-1.5 last:border-0">
                        <span className="flex items-center gap-2 text-muted-foreground">
                          <span className="size-2 rounded-full" style={{ backgroundColor: item.color }} />
                          {item.label}
                        </span>
                        <span className="font-semibold tabular-nums text-foreground">
                          {item.value} <span className="font-normal text-muted-foreground">({formatAttendanceMetricPercent(item.value, attendanceSummaryBaseDays)}%)</span>
                        </span>
                      </div>
                    ))}
                  </div>
                </div>

                {/* Attendance Efficiency Breakdown */}
                <div className="rounded-xl border border-border/70 bg-card p-5 shadow-sm">
                  <h4 className="mb-3 text-sm font-bold uppercase tracking-wide text-foreground">Attendance Efficiency Breakdown</h4>
                  <div className="space-y-2.5 text-sm">
                    {(() => {
                      const expDays = attendanceSummaryBaseDays
                      const pdDays = monthAttendanceMetrics.presentDays
                      const absDays = monthAttendanceMetrics.absentDays
                      const lateDays = monthAttendanceMetrics.lateDays
                      const underDays = monthAttendanceMetrics.undertimeDays
                      const finalEff = monthAttendanceMetrics.efficiency
                      const expectedHours = Number(monthAttendanceMetrics.expectedScheduledHours || 0)
                      const payrollImpactHours = Number(monthAttendanceMetrics.payrollImpactHours || 0)
                      const absenceLostHours = Number(monthAttendanceMetrics.absentHours || 0)
                      const lateLostHours = monthAttendanceMetrics.isExecom ? 0 : Number(monthAttendanceMetrics.lateMinutes || 0) / 60
                      const undertimeLostHours = monthAttendanceMetrics.isExecom ? 0 : Number(monthAttendanceMetrics.undertimeMinutes || 0) / 60
                      return (
                        <>
                          {[
                            { label: 'Scheduled Work Days', value: expDays },
                            { label: 'Rest Days Worked', value: monthAttendanceMetrics.restDaysWorked },
                            { label: 'Present Days', value: pdDays },
                            { label: 'Absent Days', value: absDays },
                            { label: 'Late Days', value: lateDays },
                            { label: 'Undertime Days', value: underDays },
                            { label: 'Expected Scheduled Hours', value: `${expectedHours.toFixed(2)} hrs` },
                            { label: 'Payroll Impact Hours', value: `${payrollImpactHours.toFixed(2)} hrs` },
                            { label: 'Hours Lost to Absence', value: `${absenceLostHours.toFixed(2)} hrs` },
                            { label: 'Hours Lost to Late', value: `${lateLostHours.toFixed(2)} hrs` },
                            { label: 'Hours Lost to Undertime', value: `${undertimeLostHours.toFixed(2)} hrs` },
                          ].map((row) => (
                            <div key={row.label} className="flex items-center justify-between border-b border-border/60 pb-1.5 last:border-0 text-muted-foreground">
                              <span>{row.label}</span>
                              <span className="font-semibold tabular-nums text-foreground">{row.value}</span>
                            </div>
                          ))}
                          <div className="mt-3 flex items-center justify-between rounded-lg bg-gradient-to-r from-orange-50 to-amber-50 p-3 dark:from-orange-500/10 dark:to-amber-500/10">
                            <span className="text-sm font-bold text-gray-800 dark:text-foreground">Attendance Efficiency</span>
                            <div className="flex items-center gap-2">
                              <span className="text-lg font-extrabold tabular-nums text-black dark:text-foreground">{finalEff.toFixed(2)}%</span>
                              <span className={cn('rounded-full border px-2 py-0.5 text-xs font-bold', efficiencyBadgeClass(finalEff))}>{efficiencyLabel(finalEff)}</span>
                            </div>
                          </div>
                        </>
                      )
                    })()}
                  </div>
                </div>
              </section>

              {/* Daily Breakdown */}
              <section>
                <div className="border-b border-border">
                  <div className="relative w-fit px-5 pb-3 text-sm font-semibold text-orange-600">
                    Daily Breakdown
                    <span className="absolute inset-x-0 -bottom-px h-0.5 rounded-full bg-orange-500" />
                  </div>
                </div>

                <div className="flex flex-col gap-3 py-4 sm:flex-row sm:items-center sm:justify-between">
                  <label className="relative block w-full sm:max-w-sm">
                    <Search className="pointer-events-none absolute left-3 top-1/2 size-4 -translate-y-1/2 text-muted-foreground" aria-hidden />
                    <input
                      type="search"
                      value={attendanceSummarySearch}
                      onChange={(event) => setAttendanceSummarySearch(event.target.value)}
                      placeholder="Search by date..."
                      className="h-10 w-full rounded-lg border border-input bg-background pl-10 pr-4 text-sm shadow-sm outline-none transition focus:border-orange-400 focus:ring-2 focus:ring-orange-400/20"
                    />
                  </label>
                  <label className="relative inline-flex h-10 w-full items-center gap-2 rounded-lg border border-input bg-background px-3 text-sm font-medium text-foreground shadow-sm sm:w-auto">
                    <SlidersHorizontal className="size-4 text-muted-foreground" aria-hidden />
                    <span>{attendanceSummaryFilter === 'all' ? 'Filters' : ATTENDANCE_SUMMARY_SLICE_META[attendanceSummaryFilter]?.label}</span>
                    <select
                      value={attendanceSummaryFilter}
                      onChange={(event) => setAttendanceSummaryFilter(event.target.value)}
                      className="absolute inset-0 cursor-pointer opacity-0"
                      aria-label="Filter attendance records by status"
                    >
                      <option value="all">All statuses</option>
                      {attendanceSummarySlices.filter((s) => !s.efficiency).map((slice) => (
                        <option key={slice.id} value={slice.id}>{slice.label}</option>
                      ))}
                    </select>
                  </label>
                </div>

                <div className="overflow-hidden rounded-xl border border-border/70 bg-card shadow-sm">
                  {attendanceSummaryFilteredDays.length === 0 ? (
                    <div className="flex min-h-48 flex-col items-center justify-center px-5 py-10 text-center">
                      <CalendarDays className="mb-3 size-8 text-muted-foreground/50" aria-hidden />
                      <p className="font-medium text-foreground">No attendance records found</p>
                      <p className="mt-1 text-sm text-muted-foreground">Try another date or status filter.</p>
                    </div>
                  ) : (
                    <div className="overflow-x-auto">
                      <table className="w-full min-w-[80rem] border-collapse text-sm">
                        <thead className="bg-muted/25">
                          <tr className="border-b border-border text-left text-xs font-medium text-muted-foreground">
                            {['Date', 'Day', 'Schedule', 'Time In', 'Time Out', 'Status', 'Late', 'Undertime', 'Payroll Impact', 'Remarks'].map((label) => (
                              <th key={label} className="h-11 whitespace-nowrap px-3 font-medium first:pl-4">
                                <span className="inline-flex items-center gap-1.5">
                                  {label}
                                </span>
                              </th>
                            ))}
                            <th className="h-11 w-12 px-3" />
                          </tr>
                        </thead>
                        <tbody>
                          {attendanceSummaryPagedDays.map((day) => {
                            const lateM = Number(day.late_minutes || 0)
                            const utM = Number(day.undertime_minutes || 0)
                            const piHours = Number(day.payroll_impact_hours || 0)
                            const timeIn = day.formatted_time_in || formatTime(day.time_in)
                            const timeOut = day.formatted_time_out || formatTime(day.time_out)
                            const statusKey = attendanceSummaryStatusKey(day)
                            const statusLabel = attendanceSummaryStatusLabel(day)
                            const scheduleLabel = day.schedule_label || (day.schedule_in && day.schedule_out ? `${formatTime(day.schedule_in)} - ${formatTime(day.schedule_out)}` : day.is_rest_day ? 'Rest Day' : '—')
                            const dayName = day.date ? new Date(day.date + 'T12:00:00').toLocaleDateString('en-PH', { weekday: 'short' }) : '—'
                            return (
                              <tr key={day.date} className="border-b border-border/60 transition-colors last:border-0 hover:bg-muted/20">
                                <td className="whitespace-nowrap px-3 py-3 pl-4 text-foreground">
                                  <span className="inline-flex items-center gap-2.5">
                                    <span className="flex size-7 items-center justify-center rounded-md bg-muted text-muted-foreground">
                                      <CalendarDays className="size-3.5" aria-hidden />
                                    </span>
                                    <span className="font-medium">{formatYmdShort(day.date)}</span>
                                  </span>
                                </td>
                                <td className="whitespace-nowrap px-3 py-3 text-foreground">{dayName}</td>
                                <td className="whitespace-nowrap px-3 py-3 text-xs text-foreground">{scheduleLabel}</td>
                                <td className="whitespace-nowrap px-3 py-3 tabular-nums text-foreground">{timeIn || '—'}</td>
                                <td className="whitespace-nowrap px-3 py-3 tabular-nums text-foreground">{timeOut || '—'}</td>
                                <td className="whitespace-nowrap px-3 py-3">
                                  <span className={cn('inline-flex items-center gap-2 rounded-md border px-2.5 py-1 text-xs font-semibold', ATTENDANCE_SUMMARY_STATUS_STYLES[statusKey] || 'border-border bg-muted text-muted-foreground')}>
                                    <span className="size-2 rounded-full bg-current" aria-hidden />
                                    {statusLabel}
                                  </span>
                                </td>
                                <td className={cn('whitespace-nowrap px-3 py-3 tabular-nums', lateM > 0 ? 'font-medium text-orange-600 dark:text-orange-400' : 'text-muted-foreground')}>
                                  {lateM > 0 ? `${lateM}m` : '—'}
                                </td>
                                <td className={cn('whitespace-nowrap px-3 py-3 tabular-nums', utM > 0 ? 'font-medium text-amber-600 dark:text-amber-400' : 'text-muted-foreground')}>
                                  {utM > 0 ? `${utM}m` : '—'}
                                </td>
                                <td className={cn('whitespace-nowrap px-3 py-3 tabular-nums', piHours > 0 ? 'font-medium text-emerald-600 dark:text-emerald-400' : 'text-muted-foreground')}>
                                  {piHours > 0 ? `${piHours.toFixed(2)}h` : '—'}
                                </td>
                                <td className="max-w-32 truncate px-3 py-3 text-foreground">{day.remarks || day.remark || 'Open'}</td>
                                <td className="px-3 py-3 text-right">
                                  <button
                                    type="button"
                                    className="inline-flex size-8 items-center justify-center rounded-md text-muted-foreground transition hover:bg-muted hover:text-foreground"
                                    onClick={() => openAttendanceDayFromSummary(day.date)}
                                    aria-label={`Open attendance details for ${formatYmdShort(day.date)}`}
                                  >
                                    <MoreVertical className="size-4" aria-hidden />
                                  </button>
                                </td>
                              </tr>
                            )
                          })}
                        </tbody>
                      </table>
                    </div>
                  )}
                </div>

                {attendanceSummaryFilteredDays.length > 0 && (
                  <div className="flex flex-col gap-3 pt-4 text-xs text-muted-foreground sm:flex-row sm:items-center sm:justify-between">
                    <p>
                      Showing {(attendanceSummarySafePage - 1) * attendanceSummaryPageSize + 1} to{' '}
                      {Math.min(attendanceSummarySafePage * attendanceSummaryPageSize, attendanceSummaryFilteredDays.length)} of{' '}
                      {attendanceSummaryFilteredDays.length} entries
                    </p>
                    <div className="flex flex-wrap items-center gap-1.5">
                      <Button type="button" variant="outline" size="icon" className="size-9 rounded-lg" onClick={() => setAttendanceSummaryPage((page) => Math.max(1, page - 1))} disabled={attendanceSummarySafePage <= 1} aria-label="Previous attendance page">
                        <ChevronLeft className="size-4" />
                      </Button>
                      {compactPaginationPages(attendanceSummarySafePage, attendanceSummaryPageCount).map((page, index, pages) => (
                        <span key={page} className="contents">
                          {index > 0 && page - pages[index - 1] > 1 ? <span className="px-1">…</span> : null}
                          <Button type="button" variant={page === attendanceSummarySafePage ? 'default' : 'outline'} size="icon" className={cn('size-9 rounded-lg', page === attendanceSummarySafePage && 'bg-orange-600 text-white hover:bg-orange-700')} onClick={() => setAttendanceSummaryPage(page)}>
                            {page}
                          </Button>
                        </span>
                      ))}
                      <Button type="button" variant="outline" size="icon" className="size-9 rounded-lg" onClick={() => setAttendanceSummaryPage((page) => Math.min(attendanceSummaryPageCount, page + 1))} disabled={attendanceSummarySafePage >= attendanceSummaryPageCount} aria-label="Next attendance page">
                        <ChevronRight className="size-4" />
                      </Button>
                      <div className="ml-1 flex h-9 items-center rounded-lg border border-input bg-background px-3 font-medium text-foreground shadow-sm">10 / page</div>
                    </div>
                  </div>
                )}
              </section>
            </div>
          )}
        </DialogContent>
      </Dialog>

      <Dialog open={evaluationDetailsOpen} onOpenChange={setEvaluationDetailsOpen}>
        <DialogContent
          className="w-[calc(100vw-1rem)] max-w-4xl gap-0 overflow-hidden rounded-2xl border-border/70 bg-background p-0 shadow-[0_30px_90px_-28px_rgba(15,23,42,0.55)] sm:max-w-4xl"
          innerClassName="max-h-[92vh] gap-0 overflow-y-auto p-0"
        >
          <DialogHeader className="border-b-0 px-5 pb-3 pt-6 pr-14 text-left sm:px-7 sm:pb-4 sm:pt-7">
            <div className="flex flex-col gap-4 sm:flex-row sm:items-start sm:justify-between">
              <div>
                <DialogTitle className="text-xl font-bold tracking-tight text-foreground sm:text-2xl">
                  Performance
                </DialogTitle>
                <DialogDescription className="mt-1.5 text-sm text-muted-foreground sm:text-base">
                  {employeeDisplayName}
                  <span className="px-1.5">•</span>
                  KPI performance and evaluation results are shown separately.
                </DialogDescription>
              </div>
              <Badge variant="outline" className="w-fit rounded-full border-violet-500/30 bg-violet-500/10 text-violet-700 dark:text-violet-200">
                KPI Performance
              </Badge>
            </div>
          </DialogHeader>

          <div className="px-5 pb-6 sm:px-7">
            {evaluationLoading && !evaluationWidget ? (
              <div className="flex min-h-72 items-center justify-center text-sm text-muted-foreground">Loading performance details…</div>
            ) : !evaluationWidget ? (
              <div className="rounded-2xl border border-dashed border-border bg-muted/25 p-8 text-center">
                <div className="mx-auto flex size-14 items-center justify-center rounded-2xl bg-violet-500/10 text-violet-600 dark:text-violet-300">
                  <FileCheck className="size-7" />
                </div>
                <h3 className="mt-4 text-base font-bold text-foreground">No performance data yet</h3>
                <p className="mx-auto mt-2 max-w-md text-sm text-muted-foreground">
                  KPI performance snapshots and evaluation-module results will appear here once available.
                </p>
              </div>
            ) : (
              <div className={cn('space-y-5', evaluationLoading && 'pointer-events-none opacity-70')}>
                <section className="rounded-2xl border border-border/70 bg-card p-5 shadow-sm dark:bg-card/85">
                  <div className="mb-4 flex flex-col gap-3 rounded-2xl border border-violet-500/20 bg-violet-500/[0.04] p-3 sm:flex-row sm:items-center sm:justify-between">
                    <div>
                      <p className="text-xs font-bold uppercase tracking-wide text-violet-700 dark:text-violet-200">KPI Month</p>
                      <p className="mt-1 text-xs text-muted-foreground">Select a previous month to load its KPI snapshots.</p>
                    </div>
                    <div className="flex min-w-0 items-center gap-1 rounded-lg border border-violet-500/25 bg-background/80 p-1 shadow-sm sm:w-72">
                      <button
                        type="button"
                        className="flex size-8 shrink-0 items-center justify-center rounded-md text-violet-700 hover:bg-violet-500/10 dark:text-violet-300"
                        onClick={goPrevCalendarMonth}
                        aria-label="Previous KPI month"
                      >
                        <ChevronLeft className="size-4" />
                      </button>
                      <Select value={attendanceMonthValue} onValueChange={handleAttendanceMonthSelect}>
                        <SelectTrigger size="sm" className="h-8 min-w-0 flex-1 border-0 bg-transparent px-2 text-xs font-semibold text-foreground shadow-none ring-0 focus:ring-0 focus-visible:ring-0">
                          <CalendarDays className="size-3.5 shrink-0 text-violet-600 dark:text-violet-300" aria-hidden />
                          <SelectValue placeholder="Select KPI month" />
                        </SelectTrigger>
                        <SelectContent position="popper" className="max-h-64 rounded-xl">
                          {attendanceMonthOptions.map((option) => (
                            <SelectItem key={option.value} value={option.value} className="rounded-lg">
                              {option.label}
                            </SelectItem>
                          ))}
                        </SelectContent>
                      </Select>
                      <button
                        type="button"
                        className="flex size-8 shrink-0 items-center justify-center rounded-md text-violet-700 hover:bg-violet-500/10 disabled:opacity-40 dark:text-violet-300"
                        onClick={goNextCalendarMonth}
                        disabled={!canGoNextMonth || loading || calendarLoading || evaluationLoading}
                        aria-label="Next KPI month"
                      >
                        <ChevronRight className="size-4" />
                      </button>
                    </div>
                  </div>
                  <div className="grid gap-5 lg:grid-cols-[0.9fr_1.35fr]">
                    <div>
                      <div className="flex items-start justify-between gap-4">
                        <div>
                          <p className="text-xs font-bold uppercase tracking-wide text-muted-foreground">Performance Avg (KPI)</p>
                          <p className="mt-2 text-4xl font-extrabold tabular-nums tracking-tight text-foreground">
                            {hasPerformanceSnapshotAverage ? Number(performanceSnapshotAveragePercentage).toFixed(2) : '—'}
                            {hasPerformanceSnapshotAverage ? <span className="ml-1 text-xl font-semibold text-muted-foreground">%</span> : null}
                          </p>
                          <p className="mt-2 text-sm font-semibold text-foreground">KPI snapshot average for {getMonthLabel()}</p>
                          <p className="mt-1 text-sm text-muted-foreground">
                            {performanceWidget?.latest_rating || 'No rating yet'} · Latest snapshot: {performanceWidget?.latest_date || '—'}
                          </p>
                        </div>
                        <div className="relative size-24 shrink-0">
                          {performanceChartTotal > 0 ? (
                            <ResponsiveContainer width="100%" height="100%">
                              <PieChart>
                                <Pie data={performanceStatusSlices} dataKey="count" nameKey="label" cx="50%" cy="50%" innerRadius="58%" outerRadius="92%" paddingAngle={2} stroke="hsl(var(--background))" strokeWidth={2} isAnimationActive={false}>
                                  {performanceStatusSlices.map((slice) => (
                                    <Cell key={slice.id} fill={slice.color} />
                                  ))}
                                </Pie>
                              </PieChart>
                            </ResponsiveContainer>
                          ) : (
                            <div className="flex h-full items-center justify-center rounded-full border border-dashed border-border bg-muted/20 text-xs text-muted-foreground">No KPI</div>
                          )}
                          <div className="pointer-events-none absolute inset-0 flex flex-col items-center justify-center">
                            <span className="text-lg font-bold tabular-nums text-foreground">{performanceChartTotal}</span>
                            <span className="text-[10px] text-muted-foreground">days</span>
                          </div>
                        </div>
                      </div>

                      <div className="mt-5 grid grid-cols-2 gap-3 text-sm">
                        {[
                          { label: 'Snapshot Days', value: performanceSnapshotCount, color: '#22c55e' },
                          { label: 'Latest KPI %', value: hasPerformanceLatestScore ? Number(performanceLatestPercentage).toFixed(1) : '—', color: '#8b5cf6' },
                          { label: 'Average KPI %', value: hasPerformanceSnapshotAverage ? Number(performanceSnapshotAveragePercentage).toFixed(1) : '—', color: '#6366f1' },
                          { label: 'KPI Rows', value: performanceHistory.length, color: '#14b8a6' },
                        ].map((item) => (
                          <div key={item.label} className="rounded-xl border border-border/60 bg-muted/20 p-3">
                            <span className="flex items-center gap-2 text-xs font-semibold uppercase tracking-wide text-muted-foreground">
                              <span className="size-2 rounded-full" style={{ backgroundColor: item.color }} />
                              {item.label}
                            </span>
                            <p className="mt-1 text-2xl font-bold tabular-nums text-foreground">{item.value}</p>
                          </div>
                        ))}
                      </div>
                    </div>

                    <div className="rounded-2xl border border-border/60 bg-muted/10 p-4">
                      <div className="flex items-center justify-between gap-3">
                        <div>
                          <h3 className="text-sm font-bold uppercase tracking-wide text-foreground">Monthly KPI Calendar</h3>
                          <p className="mt-1 text-xs text-muted-foreground">Daily KPI snapshots for {getMonthLabel()}.</p>
                        </div>
                        <Badge variant="secondary" className="rounded-full">
                          {performanceSnapshotCount} days
                        </Badge>
                      </div>
                      <div className="mt-4 grid grid-cols-7 gap-1 text-center text-[10px] font-bold uppercase tracking-wide text-muted-foreground">
                        {['Sun', 'Mon', 'Tue', 'Wed', 'Thu', 'Fri', 'Sat'].map((day) => (
                          <div key={day}>{day}</div>
                        ))}
                      </div>
                      <div className="mt-2 grid grid-cols-7 gap-1">
                        {performanceCalendarCells.map((cell) => {
                          const row = performanceByDate.get(cell.dateStr)
                          return (
                            <div
                              key={cell.dateStr}
                              onClick={() => handlePerformanceCalendarCellSelect(cell)}
                              className={cn(
                                'min-h-16 rounded-lg border p-1.5 text-left transition-colors',
                                cell.isAdjacent
                                  ? 'border-border/30 bg-muted/10 text-muted-foreground/50'
                                  : row
                                    ? 'border-violet-500/30 bg-violet-500/10 text-foreground'
                                    : 'border-border/50 bg-background/70 text-muted-foreground',
                              )}
                            >
                              <div className="text-xs font-bold tabular-nums">{cell.day}</div>
                              {row ? (
                                <div className="mt-1">
                                  <div className="text-xs font-extrabold tabular-nums text-violet-700 dark:text-violet-200">
                                    {Number(row.percentage).toFixed(1)}%
                                  </div>
                                  <div className="mt-0.5 truncate text-[9px] text-muted-foreground">{row.rating || '—'}</div>
                                </div>
                              ) : !cell.isAdjacent ? (
                                <div className="mt-2 text-[10px] text-muted-foreground">—</div>
                              ) : null}
                            </div>
                          )
                        })}
                      </div>
                    </div>
                  </div>
                </section>

                <section className="overflow-hidden rounded-xl border border-slate-200/90 bg-white shadow-[0_14px_36px_-32px_rgba(15,23,42,0.45)] dark:border-slate-800/80 dark:bg-card/95">
                  <div className="flex flex-col gap-3 border-b border-slate-200/90 px-4 py-3 dark:border-slate-800/80 sm:flex-row sm:items-center sm:justify-between">
                    <div className="flex min-w-0 items-start gap-3">
                      <span className="flex size-11 shrink-0 items-center justify-center rounded-xl bg-orange-100/70 text-orange-600 shadow-[inset_0_1px_0_rgba(255,255,255,0.85)] dark:bg-orange-500/10 dark:text-orange-300">
                        <FileCheck className="size-6" strokeWidth={2.2} aria-hidden />
                      </span>
                      <div className="min-w-0">
                        <h3 className="text-base font-black uppercase leading-tight tracking-[0.01em] text-slate-950 dark:text-slate-50">
                          Evaluation Module
                        </h3>
                        <p className="mt-1 text-xs leading-relaxed text-slate-900 dark:text-slate-300">
                          Separate evaluation results. These are not used to compute KPI Performance.
                        </p>
                      </div>
                    </div>
                    <Button
                      type="button"
                      variant="outline"
                      className="h-9 w-full justify-center gap-2 rounded-lg border-orange-600 bg-white px-4 text-xs font-medium text-orange-600 shadow-none hover:bg-orange-50 hover:text-orange-700 dark:border-orange-400 dark:bg-card dark:text-orange-300 dark:hover:bg-orange-500/10 sm:w-auto"
                      onClick={() => {
                        setEvaluationDetailsOpen(false)
                        navigateAfterOverlayDismiss(navigate, employeeSelfHref(hrBase, 'evaluations'))
                      }}
                    >
                      <ArrowUpRight className="size-4" strokeWidth={2.4} aria-hidden />
                      Open Evaluations
                    </Button>
                  </div>

                  <div className="grid gap-3 p-3 lg:grid-cols-[0.97fr_1.03fr]">
                    <div className="rounded-xl border border-slate-200/95 bg-white p-3 dark:border-slate-800 dark:bg-card/80">
                      <div className="flex items-center justify-between gap-3">
                        <h4 className="text-xs font-black uppercase tracking-[0.01em] text-slate-950 dark:text-slate-50">
                          Latest Completed Result
                        </h4>
                        <span className="inline-flex min-h-6 items-center rounded-lg bg-orange-50 px-2.5 text-[11px] font-medium text-orange-600 dark:bg-orange-500/10 dark:text-orange-300">
                          {completedEvaluationHistory.length > 0 ? 'Completed' : 'No result'}
                        </span>
                      </div>

                      <div className="flex min-h-[122px] flex-col items-center justify-center px-2 py-2.5 text-center">
                        <div className="relative flex size-16 items-center justify-center">
                          <span className="absolute inset-2.5 rounded-full bg-orange-100/70 dark:bg-orange-500/10" />
                          <span className="absolute left-1 top-4 size-1.5 rounded-full border border-orange-300 bg-white dark:bg-card" />
                          <span className="absolute right-3 top-1 size-1.5 rounded-full border border-slate-300 bg-white dark:border-slate-600 dark:bg-card" />
                          <span className="absolute bottom-4 left-3 size-1 rounded-full bg-orange-300/80" />
                          <span className="absolute right-1 top-7 size-1 rounded-full bg-orange-300" />
                          <span className="relative block h-10 w-8 rounded-md border-2 border-slate-900 bg-white shadow-[0_5px_14px_-10px_rgba(15,23,42,0.75)] dark:border-slate-100 dark:bg-card">
                            <span className="absolute left-1.5 right-2 top-2 h-0.5 rounded-full bg-orange-500" />
                            <span className="absolute left-1.5 right-1.5 top-4 h-0.5 rounded-full bg-slate-300 dark:bg-slate-600" />
                            <span className="absolute left-1.5 right-2.5 top-6 h-0.5 rounded-full bg-slate-300 dark:bg-slate-600" />
                            <span className="absolute -right-1.5 top-1.5 size-3 rotate-45 border-b-2 border-r-2 border-slate-900 bg-white dark:border-slate-100 dark:bg-card" />
                            <span className="absolute -bottom-0.5 -right-3 size-5 rounded-full border-[3px] border-orange-600 bg-white/80 dark:border-orange-300 dark:bg-card/90" />
                            <span className="absolute -bottom-2.5 -right-5 h-3.5 w-[3px] -rotate-45 rounded-full bg-orange-600 dark:bg-orange-300" />
                          </span>
                        </div>

                        <h5 className="mt-2 text-sm font-black tracking-tight text-slate-950 dark:text-slate-50">
                          {latestCompletedEvaluation?.template || (evaluationModuleWidget?.latest_percentage != null ? evaluationModuleWidget?.template : null) || 'No completed evaluation result'}
                        </h5>
                        <p className="mt-1.5 text-xs leading-relaxed text-slate-500 dark:text-slate-400">
                          {latestCompletedEvaluation?.percentage != null
                            ? `${Number(latestCompletedEvaluation.percentage).toFixed(1)}%`
                            : evaluationModuleWidget?.latest_percentage != null
                              ? `${Number(evaluationModuleWidget.latest_percentage).toFixed(1)}%`
                              : 'No rating yet'}
                          <span className="px-2 text-slate-300">&bull;</span>
                          {latestCompletedEvaluation?.rating || evaluationModuleWidget?.latest_rating || <>&mdash;</>}
                        </p>
                      </div>

                      <div className="border-t border-slate-200 pt-3 dark:border-slate-800">
                        <div className="grid gap-2 sm:grid-cols-3">
                          {[
                            { label: 'Completed', value: evaluationStats.completed ?? 0, Icon: FileCheck },
                            { label: 'Assigned', value: evaluationStats.active_assignments ?? 0, Icon: User },
                            { label: 'Overdue', value: evaluationStats.overdue_assignments ?? 0, Icon: Clock },
                          ].map(({ label, value, Icon }) => (
                            <div key={label} className="min-h-[72px] rounded-lg border border-slate-200/95 bg-white p-2.5 dark:border-slate-800 dark:bg-background/50">
                              <span className="flex size-7 items-center justify-center rounded-lg bg-orange-100/70 text-orange-600 dark:bg-orange-500/10 dark:text-orange-300">
                                <Icon className="size-3.5" strokeWidth={2.2} aria-hidden />
                              </span>
                              <p className="mt-2 text-xl font-black tabular-nums tracking-tight text-slate-950 dark:text-slate-50">{value}</p>
                              <p className="text-xs text-slate-500 dark:text-slate-400">{label}</p>
                            </div>
                          ))}
                        </div>
                      </div>
                    </div>

                    <div className="rounded-xl border border-slate-200/95 bg-white p-3 dark:border-slate-800 dark:bg-card/80">
                      <div className="flex items-start justify-between gap-3">
                        <div>
                          <h4 className="text-xs font-black uppercase tracking-[0.01em] text-slate-950 dark:text-slate-50">
                            Evaluation Activity
                          </h4>
                          <p className="mt-1.5 max-w-md text-xs leading-relaxed text-slate-900 dark:text-slate-300">
                            Completed results are separated from drafts and assigned evaluations.
                          </p>
                        </div>
                        <span className="inline-flex min-h-6 shrink-0 items-center rounded-lg bg-orange-50 px-2.5 text-[11px] font-medium text-orange-600 dark:bg-orange-500/10 dark:text-orange-300">
                          {evaluationHistory.length} total
                        </span>
                      </div>

                      <div className="mt-3 max-h-[235px] overflow-y-auto">
                        {completedEvaluationHistory.length === 0 && openEvaluationHistory.length === 0 ? (
                          <div className="flex min-h-[160px] flex-col items-center justify-center px-2 pb-2 text-center">
                            <div className="relative flex size-20 items-center justify-center">
                              <span className="absolute inset-4 rounded-full bg-orange-100/60 dark:bg-orange-500/10" />
                              <span className="absolute left-3 top-5 size-1.5 rounded-full border border-orange-300 bg-white dark:bg-card" />
                              <span className="absolute bottom-4 left-4 size-1.5 rounded-full border border-slate-300 bg-white dark:border-slate-600 dark:bg-card" />
                              <span className="absolute bottom-3 left-6 size-1 rounded-full bg-orange-300" />
                              <span className="absolute right-3 bottom-4 size-1 rounded-full bg-orange-600 dark:bg-orange-300" />
                              <span className="absolute right-1 top-2 h-16 w-8 rounded-r-full border-r-2 border-dashed border-orange-500" />
                              <FileText className="relative size-11 rotate-[-7deg] text-slate-300 dark:text-slate-600" strokeWidth={2.5} aria-hidden />
                              <span className="absolute top-[27px] flex h-3 w-7 items-center justify-center rounded-t bg-orange-500 text-white">
                                <span className="size-1 rounded-full bg-orange-100" />
                              </span>
                            </div>
                            <h5 className="mt-1.5 text-base font-black tracking-tight text-slate-950 dark:text-slate-50">
                              No evaluation activity yet.
                            </h5>
                            <p className="mt-2 max-w-md text-xs leading-relaxed text-slate-500 dark:text-slate-400">
                              Assigned evaluations and completed results<br className="hidden sm:block" />
                              will appear here.
                            </p>
                          </div>
                        ) : (
                          <div className="space-y-5">
                            {completedEvaluationHistory.length > 0 ? (
                              <div>
                                <p className="mb-3 text-[11px] font-bold uppercase tracking-wide text-slate-500 dark:text-slate-400">Completed Results</p>
                                <ul className="space-y-2">
                                  {completedEvaluationHistory.map((row) => (
                                    <li key={row.id} className="rounded-xl border border-emerald-500/20 bg-emerald-500/[0.04] p-3">
                                      <div className="flex items-start justify-between gap-4">
                                        <div className="min-w-0">
                                          <p className="truncate text-sm font-bold text-foreground">{row.template || 'Evaluation'}</p>
                                          <p className="mt-1 text-xs text-muted-foreground">
                                            {row.evaluator || <>&mdash;</>} <span>&bull;</span> {row.evaluated_at || <>&mdash;</>}
                                          </p>
                                        </div>
                                        <div className="shrink-0 text-right">
                                          <p className="text-sm font-bold tabular-nums text-emerald-700 dark:text-emerald-300">
                                            {row.percentage != null ? `${Number(row.percentage).toFixed(1)}%` : <>&mdash;</>}
                                          </p>
                                          <p className="text-xs text-muted-foreground">{row.rating || 'Completed'}</p>
                                        </div>
                                      </div>
                                    </li>
                                  ))}
                                </ul>
                              </div>
                            ) : null}

                            <div>
                              <p className="mb-3 text-[11px] font-bold uppercase tracking-wide text-slate-500 dark:text-slate-400">Open Evaluations</p>
                              {openEvaluationHistory.length === 0 ? (
                                <div className="rounded-xl border border-dashed border-slate-200 bg-orange-50/35 p-5 text-center text-sm text-slate-500 dark:border-slate-800 dark:bg-orange-500/5 dark:text-slate-400">
                                  No draft or assigned evaluations.
                                </div>
                              ) : (
                                <ul className="space-y-2">
                                  {openEvaluationHistory.map((row) => (
                                    <li key={row.id} className="rounded-xl border border-orange-500/20 bg-orange-500/[0.04] p-3">
                                      <div className="flex items-start justify-between gap-4">
                                        <div className="min-w-0">
                                          <p className="truncate text-sm font-bold text-foreground">{row.template || 'Evaluation'}</p>
                                          <p className="mt-1 text-xs text-muted-foreground">
                                            Evaluator: {row.evaluator || <>&mdash;</>}
                                          </p>
                                        </div>
                                        <Badge variant="outline" className="shrink-0 rounded-full capitalize">
                                          {String(row.status || 'assigned').replace(/_/g, ' ')}
                                        </Badge>
                                      </div>
                                    </li>
                                  ))}
                                </ul>
                              )}
                            </div>
                          </div>
                        )}
                      </div>
                    </div>
                  </div>
                </section>


              </div>
            )}
          </div>
        </DialogContent>
      </Dialog>

      <Dialog open={otDetailsOpen} onOpenChange={setOtDetailsOpen}>
        <DialogContent className="w-[calc(100vw-1rem)] max-w-lg sm:max-w-2xl" innerClassName="gap-0">
          <DialogHeader className="pb-2 pr-2">
            <DialogTitle className="text-lg font-semibold tracking-tight">Overtime — {getMonthLabel()}</DialogTitle>
            <DialogDescription>
              Hours from your OT requests and clock-detected overtime without an active filing for the month you
              selected above. Times are shown in 12-hour form (your attendance timezone).
            </DialogDescription>
          </DialogHeader>
          <div className="mt-2 grid grid-cols-1 gap-2 rounded-xl border border-border/60 bg-muted/20 p-3 text-center @sm:grid-cols-3">
            <div>
              <p className="text-[10px] font-semibold uppercase tracking-wider text-amber-800 dark:text-amber-300/90">
                Pending
              </p>
              <p className="mt-1 text-lg font-bold tabular-nums text-amber-700 dark:text-amber-400">
                {loading ? '—' : `${otMonthBreakdown.pendingH}h`}
              </p>
            </div>
            <div>
              <p className="text-[10px] font-semibold uppercase tracking-wider text-emerald-800 dark:text-emerald-300/90">
                Approved
              </p>
              <p className="mt-1 text-lg font-bold tabular-nums text-emerald-700 dark:text-emerald-400">
                {loading ? '—' : `${otMonthBreakdown.approvedH}h`}
              </p>
            </div>
            <div>
              <p className="text-[10px] font-semibold uppercase tracking-wider text-slate-600 dark:text-slate-400">
                Unfiled
              </p>
              <p className="mt-1 text-lg font-bold tabular-nums text-slate-700 dark:text-slate-300">
                {loading ? '—' : `${otMonthBreakdown.unfiledH}h`}
              </p>
            </div>
          </div>
          <p className="mt-3 text-center text-sm text-muted-foreground">
            Combined total <span className="font-semibold text-foreground">{loading ? '—' : `${otModalTotalHours}h`}</span>
            {' · '}
            Payroll uses <span className="font-medium text-foreground">approved</span> OT for pay.
          </p>
          <div className="mt-4 max-h-[min(52vh,420px)] overflow-y-auto rounded-xl border border-border/60">
            {loading ? (
              <div className="p-8 text-center text-sm text-muted-foreground">Loading…</div>
            ) : otModalRows.length === 0 ? (
              <div className="p-8 text-center text-sm text-muted-foreground">
                No overtime rows for this month. File a request if you worked beyond schedule.
              </div>
            ) : (
              <ul className="divide-y divide-border/60">
                {otModalRows.map((row) => {
                  const badgePending =
                    'border-amber-500/35 bg-amber-500/12 text-amber-900 dark:bg-amber-500/15 dark:text-amber-200'
                  const badgeApproved =
                    'border-emerald-500/35 bg-emerald-500/12 text-emerald-900 dark:bg-emerald-500/15 dark:text-emerald-200'
                  const badgeUnfiled =
                    'border-slate-500/30 bg-slate-500/10 text-slate-800 dark:bg-slate-500/15 dark:text-slate-200'
                  const badgeRejected =
                    'border-red-500/30 bg-red-500/10 text-red-900 dark:bg-red-500/15 dark:text-red-200'
                  const badgeClass =
                    row.status === 'pending'
                      ? badgePending
                      : row.status === 'approved'
                        ? badgeApproved
                        : row.status === 'unfiled'
                          ? badgeUnfiled
                          : row.status === 'rejected'
                            ? badgeRejected
                            : 'border-border bg-muted/50 text-foreground'
                  const showStatusBadge = row.rowKind === 'request'
                  return (
                    <li key={row.key} className="px-4 py-4 @sm:px-5">
                      <div className="flex flex-col gap-3 @sm:flex-row @sm:items-start @sm:justify-between @sm:gap-4">
                        <div className="min-w-0 flex-1 space-y-2">
                          <p className="text-base font-semibold leading-snug tracking-tight text-foreground">
                            {formatYmdShort(row.date)}
                          </p>
                          <p className="text-sm font-medium text-muted-foreground">{row.label}</p>
                          {row.otSummaryLine ? (
                            <p className="text-sm leading-relaxed text-foreground tabular-nums">{row.otSummaryLine}</p>
                          ) : row.rowKind === 'unfiled' ? (
                            <p className="text-sm text-muted-foreground">
                              Time range unavailable — check schedule and punches for this date.
                            </p>
                          ) : null}
                          {row.rowKind === 'unfiled' && row.otSummaryLine && (
                            <div className="space-y-2 rounded-lg border border-border/60 bg-muted/20 p-3 dark:bg-muted/15">
                              <p className="text-xs leading-relaxed text-muted-foreground">
                                File OT with the same windows when filing pre-shift or post-shift overtime.
                              </p>
                              <Button
                                type="button"
                                size="sm"
                                variant="secondary"
                                className="h-9 w-full font-semibold @sm:w-auto"
                                onClick={() => {
                                  setOtDetailsOpen(false)
                                  goSelf(`overtime?date=${encodeURIComponent(row.date)}`)
                                }}
                              >
                                File OT
                              </Button>
                            </div>
                          )}
                        </div>
                        {showStatusBadge && (
                          <div className="flex shrink-0 flex-col items-stretch gap-2 @sm:items-end">
                            <Badge variant="outline" className={cn('w-fit shrink-0 border font-normal', badgeClass)}>
                              {row.status === 'pending'
                                ? 'Pending'
                                : row.status === 'approved'
                                  ? 'Approved'
                                  : row.status === 'rejected'
                                    ? 'Rejected'
                                    : String(row.status || '—')}
                            </Badge>
                            <span className="text-right text-sm font-semibold tabular-nums text-muted-foreground">
                              {roundHours1(row.hours)}h
                            </span>
                          </div>
                        )}
                      </div>
                    </li>
                  )
                })}
              </ul>
            )}
          </div>
          <div className="mt-4 flex flex-col gap-2 border-t border-border/60 pt-4 sm:flex-row sm:justify-end">
            <Button type="button" variant="outline" onClick={() => setOtDetailsOpen(false)}>
              Close
            </Button>
            <Button type="button" onClick={() => { setOtDetailsOpen(false); goSelf('overtime') }}>
              File or manage OT
            </Button>
          </div>
        </DialogContent>
      </Dialog>

      <Dialog open={faceAttendanceOpen} onOpenChange={setFaceAttendanceOpen}>
        <DialogContent
          className="w-[calc(100vw-1rem)] max-w-2xl overflow-hidden rounded-2xl border-border bg-card p-0 text-card-foreground shadow-xl max-[760px]:left-0 max-[760px]:top-0 max-[760px]:h-[100dvh] max-[760px]:max-h-[100dvh] max-[760px]:w-screen max-[760px]:translate-x-0 max-[760px]:translate-y-0 max-[760px]:rounded-none"
          innerClassName="gap-0 p-0"
        >
          <DialogHeader className="border-b border-border/70 bg-card px-5 py-4 text-left max-[760px]:px-3 max-[760px]:py-3">
            <DialogTitle className="flex min-w-0 items-center gap-2 text-lg font-extrabold text-foreground max-[360px]:text-base">
              <ScanFace className="size-5 text-orange-600" />
              {faceAttendanceType === 'clock_out' ? 'Face Clock Out' : 'Face Clock In'}
            </DialogTitle>
            <DialogDescription className="text-sm text-muted-foreground max-[360px]:text-xs">
              Complete face verification to record your {faceAttendanceType === 'clock_out' ? 'clock-out' : 'clock-in'} for today.
            </DialogDescription>
          </DialogHeader>
          <div className="min-w-0 overflow-x-hidden bg-card px-4 py-5 @sm:px-6 max-[760px]:px-2 max-[760px]:py-2">
            {faceAttendanceOpen ? (
              <FaceVerificationLiveness
                kioskMode
                authenticatedAttendance
                surface="light"
                kioskType={faceAttendanceType}
                onKioskSuccess={handleFaceAttendanceSuccess}
                onKioskCancel={() => setFaceAttendanceOpen(false)}
                instructionText="Face the camera straight, align your face in the frame, and hold still in good lighting."
              />
            ) : null}
          </div>
        </DialogContent>
      </Dialog>
    </Motion.div>
  )
}
