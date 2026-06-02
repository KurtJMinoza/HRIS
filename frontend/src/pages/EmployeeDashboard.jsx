import { useState, useEffect, useMemo, useRef, useCallback } from 'react'
import { useNavigate } from 'react-router-dom'
import { motion as Motion } from 'framer-motion'
import { Clock, FileCheck, User, ScanLine, ArrowUpRight, ArrowDownRight, Minus, QrCode, ScanFace, ChevronLeft, ChevronRight, Timer, X, ListTree, CalendarDays, Zap, Info } from 'lucide-react'
import { Card, CardContent, CardDescription, CardHeader, CardTitle } from '@/components/ui/card'
import { Badge } from '@/components/ui/badge'
import { Button } from '@/components/ui/button'
import {
  Dialog,
  DialogContent,
  DialogDescription,
  DialogHeader,
  DialogTitle,
} from '@/components/ui/dialog'
import { useAuth } from '@/contexts/AuthContext'
import {
  getEmployeeDashboardAttendanceCalendar,
  getEmployeeDashboardRecentRequests,
  getEmployeeDashboardSummary,
  getMyHolidays,
} from '@/api'
import { formatClockTimeDisplay, formatHHmmTo12h, formatScheduleLabel12h, toHhMm } from '@/lib/timeFormat'
import { cn } from '@/lib/utils'
import { formatEmployeeName } from '@/lib/employeeSort'

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
    'touch-manipulation group relative flex h-full min-h-[4rem] w-full min-w-0 max-w-full flex-col rounded-lg border border-border bg-card p-2 text-left shadow-[0_8px_18px_-18px_rgba(15,23,42,0.7)] @sm:min-h-[4.75rem] @sm:p-2.5 transition-colors duration-150 ease-out focus:outline-none focus-visible:ring-2 focus-visible:ring-orange-300 focus-visible:ring-offset-1 ring-offset-background hover:border-border/80 hover:bg-muted/35 active:scale-[0.995] dark:bg-card/80'

  /** Plain label: color only, no borders or badge backgrounds. */
  const L = {
    ink: 'block max-w-full truncate text-[11px] font-medium leading-snug tracking-tight @sm:text-xs',
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

  if (status === 'clocked_in') {
    const isLate = lateM > 0 || /^late$/i.test(lateLbl)
    if (isLate) {
      return {
        badge: 'Late',
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
      badge: 'Late',
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
    <div className="shrink-0 rounded-xl border border-border bg-card px-5 py-4 shadow-sm dark:bg-card/85">
      <p className="flex items-baseline gap-2.5 text-3xl font-extrabold tabular-nums tracking-tight text-foreground @md:text-4xl @lg:text-5xl">
        {time}
        <span
          className="inline-flex h-2 w-2 shrink-0 rounded-full bg-emerald-500 shadow-[0_0_0_4px_rgba(16,185,129,0.25)] animate-pulse @md:h-2.5 @md:w-2.5"
          aria-hidden
        />
      </p>
      <p className="mt-1 text-sm font-medium text-muted-foreground @md:text-base">{date}</p>
    </div>
  )
}

export default function EmployeeDashboard() {
  const navigate = useNavigate()
  const { user, refreshUser } = useAuth()
  const employeeDisplayName = formatEmployeeName(user, 'there')

  const [summary, setSummary] = useState(null)
  const [days, setDays] = useState([])
  const [leaveSummary, setLeaveSummary] = useState(null)
  const [leaveRequests, setLeaveRequests] = useState([])
  const [holidayRows, setHolidayRows] = useState([])
  const [holidaySummary, setHolidaySummary] = useState(null)
  const [holidayLoading, setHolidayLoading] = useState(true)
  const [loading, setLoading] = useState(true)
  const [calendarLoading, setCalendarLoading] = useState(true)
  const [summaryReady, setSummaryReady] = useState(false)
  const [error, setError] = useState(null)
  const [prevSummary, setPrevSummary] = useState(null)
  const [monthOtRequests, setMonthOtRequests] = useState([])
  const [otDetailsOpen, setOtDetailsOpen] = useState(false)
  const [otNoticeDismissed, setOtNoticeDismissed] = useState(false)
  const [selectedDay, setSelectedDay] = useState(DEFAULT_CALENDAR_VALUE)
  const [calendarYear, setCalendarYear] = useState(() => new Date().getFullYear())
  const [calendarMonth, setCalendarMonth] = useState(() => new Date().getMonth())
  const summaryAbortRef = useRef(null)
  const calendarAbortRef = useRef(null)
  const recentAbortRef = useRef(null)
  const calendarCacheRef = useRef(new Map())

  const mergeSummary = useCallback((next) => {
    setSummary((current) => ({
      ...(current || {}),
      ...(next || {}),
      today: next?.today ?? current?.today,
      pending_requests: next?.pending_requests ?? current?.pending_requests,
      upcoming_payroll: next?.upcoming_payroll ?? current?.upcoming_payroll,
      latest_payslip: next?.latest_payslip ?? current?.latest_payslip,
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

  const loadAttendanceCalendar = useCallback(async (year, month, opts = {}) => {
    const monthKey = `${year}-${String(month + 1).padStart(2, '0')}`
    calendarAbortRef.current?.abort()
    const cached = calendarCacheRef.current.get(monthKey)
    if (cached && cached?.meta?.schema_version === 4 && opts.force !== true) {
      setDays(Array.isArray(cached.days) ? cached.days : [])
      mergeSummary({
        ...(cached.summary || {}),
        from_date: `${monthKey}-01`,
        schedule_assigned: cached.schedule_assigned,
      })
      setMonthOtRequests(Array.isArray(cached.overtime_requests) ? cached.overtime_requests : [])
      setPrevSummary(null)
      setCalendarLoading(false)
      return
    }

    const controller = new AbortController()
    calendarAbortRef.current = controller
    setCalendarLoading(true)
    try {
      const data = await getEmployeeDashboardAttendanceCalendar({ month: monthKey, signal: controller.signal })
      calendarCacheRef.current.set(monthKey, data)
      setDays(Array.isArray(data.days) ? data.days : [])
      mergeSummary({
        ...(data.summary || {}),
        from_date: `${monthKey}-01`,
        schedule_assigned: data.schedule_assigned,
      })
      setMonthOtRequests(Array.isArray(data.overtime_requests) ? data.overtime_requests : [])
      setPrevSummary(null)
      setError(null)
    } catch (e) {
      if (controller.signal.aborted) return
      setDays([])
      setMonthOtRequests([])
      setError(e.message)
    } finally {
      if (calendarAbortRef.current === controller) {
        calendarAbortRef.current = null
        setCalendarLoading(false)
      }
    }
  }, [mergeSummary])

  const loadRecentRequests = useCallback(async () => {
    recentAbortRef.current?.abort()
    const controller = new AbortController()
    recentAbortRef.current = controller
    try {
      const data = await getEmployeeDashboardRecentRequests({ signal: controller.signal })
      setLeaveSummary(data.leave_summary || null)
      setLeaveRequests(Array.isArray(data.leave_requests) ? data.leave_requests : [])
    } catch {
      if (controller.signal.aborted) return
      setLeaveSummary(null)
      setLeaveRequests([])
    } finally {
      if (recentAbortRef.current === controller) {
        recentAbortRef.current = null
      }
    }
  }, [])

  useEffect(() => {
    const id = window.setTimeout(() => {
      void (async () => {
        await loadDashboardSummary()
        void loadRecentRequests()
      })()
    }, 80)
    return () => {
      window.clearTimeout(id)
      summaryAbortRef.current?.abort()
      recentAbortRef.current?.abort()
    }
  }, [loadDashboardSummary, loadRecentRequests])

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

  const hasLeaveActivity = useMemo(() => {
    if (loading) return true
    const stats = leaveSummary || {}
    const hasCounts =
      (stats.pending ?? 0) > 0 ||
      (stats.approved ?? 0) > 0 ||
      (stats.rejected ?? 0) > 0 ||
      (stats.upcoming ?? 0) > 0
    return hasCounts || leaveRequests.length > 0
  }, [loading, leaveSummary, leaveRequests])

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

  function getDisplayStatus(status, dateKey, lateLabel, lateMinutes, statusLabel = null) {
    if (statusLabel) return statusLabel
    if (!dateKey) return status
    const todayKey = formatLocalDateKey(new Date())
    if (dateKey === todayKey && summary?.schedule_assigned === false) return 'No schedule'
    if (status === 'leave') return 'On leave'
    if (status === 'rest' || status === 'rest_day' || status === 'no_schedule_rest') return 'Rest Day'
    if (status === 'absent' || status === '—') {
      if (isRestDay(dateKey)) return 'Rest Day'
      if (dateKey === todayKey && status === 'absent' && !isPastAbsentCutoff()) return 'Not started'
      if (status === 'absent') return 'Missed clock-in'
    }
    if (status === 'clocked_in') {
      const lm = typeof lateMinutes === 'number' ? lateMinutes : 0
      if (lm > 0) return lateLabel || 'Late'
      return lateLabel || 'Present'
    }
    if (status === 'present') return 'Present'
    if (status === 'present_with_ot') return 'Present with OT'
    if (status === 'late') return lateLabel || 'Late'
    if (status === 'halfday') return 'Half Day'
    if (status === 'undertime') return 'Undertime'
    if (status === 'incomplete') return 'Incomplete'
    return status
  }

  function tileTooltipLines(record, dateKey) {
    if (!record) return []
    const lines = []
    const label = getDisplayStatus(record.status, dateKey, record.late_label, record.late_minutes, record.status_label) || '—'
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

  function getAttendanceTotalHours(record) {
    const hours =
      typeof record?.total_rendered_hours === 'number'
        ? record.total_rendered_hours
        : typeof record?.total_hours === 'number'
          ? record.total_hours
          : null
    return typeof hours === 'number' && Number.isFinite(hours) ? hours : null
  }

  return (
    <Motion.div
      className="space-y-4 text-[15px] text-foreground"
      initial="hidden"
      animate="visible"
      variants={{ hidden: { opacity: 0 }, visible: { opacity: 1, transition: { staggerChildren: 0.04 } } }}
    >
      {/* Welcome + live clock */}
      <Motion.div
        className="flex flex-col gap-4 @lg:flex-row @lg:items-start @lg:justify-between"
        variants={itemVariants}
        initial="hidden"
        whileInView="visible"
        viewport={scrollViewport}
        transition={scrollRevealTransition}
      >
        <div className="min-w-0 space-y-2">
          <div>
            <h2 className="text-2xl font-extrabold tracking-tight text-foreground @md:text-[1.7rem]">
              Welcome back, {employeeDisplayName} <span className="align-middle text-xl">{'\u{1F44B}'}</span>
            </h2>
            {user?.position && (
              <p className="mt-1 text-xs font-extrabold uppercase tracking-wide text-orange-600">
                {user.position}
              </p>
            )}
            <p className="mt-2 text-[15px] text-muted-foreground">
              Track your time, review your logs, and stay on top of your schedule.
            </p>
          </div>
          {currentStatus && (
            <div className="inline-flex w-full flex-wrap items-start gap-2 rounded-lg border border-border bg-card px-3 py-2 text-sm shadow-sm transition-opacity duration-200 @sm:w-auto dark:bg-card/85">
              <div className="flex w-full flex-wrap items-center gap-2 @sm:w-auto">
                <span className={`inline-flex h-2 w-2 shrink-0 rounded-full ${currentStatus.dotClass}`} />
                <span className="font-semibold text-foreground">{currentStatus.label}</span>
                {currentStatus.detail && (
                  <span className="w-full text-muted-foreground @sm:w-auto @sm:pl-1">- {currentStatus.detail}</span>
                )}
              </div>
              {(currentStatus.label === 'Not started' || currentStatus.label === 'No activity yet today') && scheduleAssigned && (
                <div className="flex w-full flex-wrap items-center gap-2">
                  <Button
                    size="sm"
                    className="h-8 w-full gap-1.5 rounded-md px-3 text-sm font-semibold transition-all duration-200 hover:scale-[1.02] active:scale-[0.98] @sm:w-auto"
                    onClick={() => navigate('/employee/attendance?action=clock_in')}
                  >
                    <QrCode className="size-3.5" />
                    Clock In
                  </Button>
                  {!user?.has_face && (
                    <Button
                      size="sm"
                      variant="outline"
                      className="h-8 w-full gap-1.5 rounded-md px-3 text-sm font-semibold transition-all duration-200 hover:scale-[1.02] active:scale-[0.98] @sm:w-auto"
                      onClick={() => navigate('/employee/qr')}
                    >
                      <ScanFace className="size-3.5" />
                      Register Face
                    </Button>
                  )}
                </div>
              )}
            </div>
          )}
        </div>
        <div className="flex w-full flex-wrap items-stretch justify-start @lg:w-auto @lg:justify-end">
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
        className="grid gap-4 @lg:grid-cols-2 @xl:grid-cols-[minmax(0,1.65fr)_minmax(240px,0.95fr)_minmax(280px,1.08fr)]"
        variants={containerVariants}
        initial="hidden"
        whileInView="visible"
        viewport={scrollViewport}
        transition={scrollRevealTransition}
      >
        {/* Today — primary, elevated */}
        <Motion.div variants={itemVariants} whileHover={{ y: -2, transition: { duration: 0.15 } }}>
        <Card className="min-h-[11.2rem] overflow-hidden rounded-xl border-border bg-card shadow-[0_12px_30px_-22px_rgba(15,23,42,0.7)] transition-all duration-200 hover:shadow-[0_18px_36px_-24px_rgba(15,23,42,0.8)] dark:bg-card/85">
          <CardHeader className="flex flex-row items-center justify-between space-y-0 pb-2">
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
            <div className="flex items-baseline justify-between gap-2">
              <span className="text-3xl font-extrabold tracking-tight text-foreground @md:text-4xl">
                {loading ? '—' : formatTodayStatus()}
              </span>
              <span className="shrink-0 text-sm font-medium text-muted-foreground">
                {formatTodayDate(summary?.today?.date)}
              </span>
            </div>
            {!loading && formatTodayContext() && (
              <p className="mt-2 text-base text-muted-foreground transition-opacity duration-200">
                {formatTodayContext()}
              </p>
            )}
          </CardContent>
        </Card>
        </Motion.div>
        {/* Today's Time — secondary */}
        <Motion.div variants={itemVariants} whileHover={{ y: -2, transition: { duration: 0.15 } }}>
        <Card className="min-h-[11.2rem] overflow-hidden rounded-xl border-border bg-card shadow-[0_12px_30px_-22px_rgba(15,23,42,0.65)] transition-all duration-200 hover:shadow-[0_18px_36px_-24px_rgba(15,23,42,0.75)] dark:bg-card/85">
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
        <Motion.div variants={itemVariants} whileHover={{ y: -2, transition: { duration: 0.15 } }} className="@xl:row-span-2">
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
              <div className="grid grid-cols-2 gap-3 text-base">
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
                <div className="grid grid-cols-3 gap-2 divide-x divide-border/50 text-center">
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
              <div className="mt-3 flex items-center justify-between text-sm text-muted-foreground">
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
        <Motion.div variants={itemVariants} whileHover={{ y: -2, transition: { duration: 0.15 } }}>
        <Card className="overflow-hidden rounded-xl border-border bg-card shadow-[0_12px_30px_-22px_rgba(15,23,42,0.65)] transition-all duration-200 hover:shadow-[0_18px_36px_-24px_rgba(15,23,42,0.75)] dark:bg-card/85">
          <CardHeader className="flex flex-row items-center justify-between space-y-0 pb-2">
            <CardTitle className="text-sm font-extrabold uppercase tracking-wide text-muted-foreground">
              Leave Overview
            </CardTitle>
            <div className="rounded-lg bg-muted p-2">
              <ScanLine className="size-4 text-muted-foreground" />
            </div>
          </CardHeader>
          <CardContent>
            <p className="mb-4 text-sm font-semibold text-foreground">
              {getMonthLabel()}
            </p>
            {!loading && !hasLeaveActivity ? (
              <div className="flex items-center gap-4 rounded-lg border border-border bg-muted/30 px-4 py-5 text-sm">
                <div className="flex size-11 shrink-0 items-center justify-center rounded-lg border border-orange-200/70 bg-card text-orange-600 dark:bg-card/80">
                  <CalendarDays className="size-6" />
                </div>
                <div className="min-w-0">
                  <p className="text-sm font-extrabold uppercase tracking-wide text-foreground">
                    No leave activity this month
                  </p>
                  <p className="mt-1 text-sm text-muted-foreground">
                    You&apos;re fully available for your current schedule.
                  </p>
                </div>
              </div>
            ) : (
              <>
                <div className="grid grid-cols-2 gap-2 text-base">
                  <div>
                    <div className="text-sm text-muted-foreground">Pending</div>
                    <div className="mt-0.5 text-lg font-medium">
                      {loading ? '—' : <AnimatedNumber value={leaveSummary?.pending ?? 0} />}
                    </div>
                  </div>
                  <div>
                    <div className="text-sm text-muted-foreground">Approved</div>
                    <div className="mt-0.5 text-lg font-medium">
                      {loading ? '—' : <AnimatedNumber value={leaveSummary?.approved ?? 0} />}
                    </div>
                  </div>
                  <div>
                    <div className="text-sm text-muted-foreground">Rejected</div>
                    <div className="mt-0.5 text-lg font-medium">
                      {loading ? '—' : <AnimatedNumber value={leaveSummary?.rejected ?? 0} />}
                    </div>
                  </div>
                  <div>
                    <div className="text-sm text-muted-foreground">Upcoming</div>
                    <div className="mt-0.5 text-lg font-medium">
                      {loading ? '—' : <AnimatedNumber value={leaveSummary?.upcoming ?? 0} />}
                    </div>
                  </div>
                </div>
                {leaveRequests.length > 0 && (
                  <p className="mt-2 text-sm text-muted-foreground">
                    Last request:{' '}
                    {new Date(`${leaveRequests[0].start_date}T12:00:00`).toLocaleDateString('en-PH', {
                      month: 'short',
                      day: 'numeric',
                      year: 'numeric',
                    })}{' '}
                    –{' '}
                    {new Date(`${leaveRequests[0].end_date}T12:00:00`).toLocaleDateString('en-PH', {
                      month: 'short',
                      day: 'numeric',
                      year: 'numeric',
                    })}{' '}
                    ({leaveRequests[0].status})
                  </p>
                )}
              </>
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
              <div className="mt-2.5 flex gap-2">
                {(() => {
                  const hasPre = Boolean(summary.today.ot_detection.pre_shift)
                  const hasPost = Boolean(summary.today.ot_detection.post_shift)
                  const todayDate = summary.today.ot_detection.date || formatLocalDateKey(new Date())
                  if (hasPre && hasPost) {
                    return (
                      <>
                        <Button
                          size="sm"
                          className="h-8 px-3 text-xs"
                          onClick={() => navigate(`/employee/overtime?date=${encodeURIComponent(todayDate)}&segments=pre_shift`)}
                        >
                          File pre-shift
                        </Button>
                        <Button
                          size="sm"
                          className="h-8 px-3 text-xs"
                          onClick={() => navigate(`/employee/overtime?date=${encodeURIComponent(todayDate)}&segments=post_shift`)}
                        >
                          File post-shift
                        </Button>
                      </>
                    )
                  }
                  return (
                    <Button
                      size="sm"
                      className="h-8 px-3 text-xs"
                      onClick={() => navigate('/employee/overtime')}
                    >
                      File OT
                    </Button>
                  )
                })()}
                <Button
                  size="sm"
                  variant="ghost"
                  className="h-8 px-3 text-xs text-amber-700 hover:bg-amber-200/40 hover:text-amber-800 dark:text-amber-400 dark:hover:bg-amber-800/30"
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
        className="flex flex-col gap-3 rounded-xl border border-border bg-card px-5 py-4 text-base shadow-[0_12px_30px_-24px_rgba(15,23,42,0.7)] @sm:flex-row @sm:items-center @sm:justify-between dark:bg-card/85"
        variants={itemVariants}
        initial="hidden"
        whileInView="visible"
        viewport={scrollViewport}
        transition={scrollRevealTransition}
      >
        <p className="flex items-center gap-3 text-sm text-muted-foreground">
          <span className="flex size-9 shrink-0 items-center justify-center rounded-full border border-orange-200 bg-orange-50 text-orange-600">
            <Zap className="size-4" />
          </span>
          <span>Need to take action? Jump straight from your dashboard.</span>
        </p>
        <div className="flex w-full flex-col gap-2 @sm:w-auto @sm:flex-row @sm:flex-wrap">
          <Button
            size="sm"
            className="h-10 w-full rounded-lg bg-orange-600 px-5 text-sm font-bold text-white shadow-[0_12px_24px_-16px_rgba(234,88,12,0.8)] hover:bg-orange-700 @sm:w-auto"
            onClick={() => navigate('/employee/requests')}
          >
            Request leave
          </Button>
          <Button
            size="sm"
            variant="outline"
            className="h-10 w-full rounded-lg border-border px-5 text-sm font-bold text-foreground @sm:w-auto"
            onClick={() => navigate('/employee/attendance')}
          >
            View full attendance
          </Button>
          <Button
            size="sm"
            variant="outline"
            className="h-10 w-full rounded-lg border-border px-5 text-sm font-bold text-foreground @sm:w-auto"
            onClick={() => navigate('/employee/overtime')}
          >
            File overtime
          </Button>
        </div>
      </Motion.div>

      {/* Attendance calendar */}
      <Motion.div
        className="grid gap-6 @xl:grid-cols-[minmax(0,1fr)_minmax(18rem,22rem)]"
        variants={containerVariants}
        initial="hidden"
        whileInView="visible"
        viewport={scrollViewport}
        transition={scrollRevealTransition}
      >
        <Motion.div variants={itemVariants}>
          <Card className="overflow-hidden rounded-xl border-border bg-card shadow-[0_14px_36px_-26px_rgba(15,23,42,0.8)] dark:bg-card/85">
            <CardHeader className="bg-card dark:bg-card/85">
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
              <div className="bg-card px-3 py-2.5 @sm:px-4 md:px-6 dark:bg-card/85">
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
              <div className="mt-2 space-y-2 px-3 pb-3 @sm:px-4 md:pb-4">
                <div className="mx-auto w-full max-w-6xl min-w-0 overflow-x-auto @sm:overflow-x-visible">
                  <div className="grid w-full min-w-[320px] grid-cols-7 grid-rows-[auto_repeat(6,minmax(4.25rem,1fr))] gap-2 @sm:min-w-0 @sm:grid-rows-[auto_repeat(6,minmax(5rem,1fr))]">
                    {WEEKDAYS.map((w) => (
                      <div
                        key={w}
                        className="min-w-0 rounded-md bg-card px-1.5 py-2 text-center text-[10px] font-extrabold uppercase leading-tight tracking-wide text-muted-foreground @sm:py-2.5 @sm:text-xs"
                      >
                        {w}
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
                      const monthShort = MONTHS[cell.month]?.slice(0, 3) ?? ''
                      const isToday = key === todayKeyNow
                      const isSelected = selectedDay != null && formatLocalDateKey(selectedDay) === key
                      const tooltipTitle = lines.length ? lines.join('\n') : undefined

                      return (
                        <div key={`${key}-${idx}`} className="flex min-h-17 min-w-0 @sm:min-h-20">
                          <button
                            type="button"
                            title={tooltipTitle}
                            onClick={() => setSelectedDay(new Date(cell.year, cell.month, cell.day))}
                            className={cn(
                              visual.tileClass,
                              'text-base',
                              isToday && 'ring-1 ring-orange-500 ring-offset-2 ring-offset-background',
                              isSelected &&
                                'z-1 border-orange-500 ring-1 ring-orange-300 ring-offset-1 ring-offset-background',
                              cell.isAdjacent && record && 'opacity-[0.88]',
                            )}
                          >
                            <div className="flex items-start justify-between gap-1">
                              <span
                                className={cn(
                                  'text-base font-semibold tabular-nums leading-none tracking-tight @sm:text-lg',
                                  cell.isAdjacent && !record && 'text-muted-foreground/80',
                                )}
                              >
                                {isToday ? (
                                  <span className="inline-flex min-w-8 items-center justify-center rounded-md bg-orange-500 px-2 py-0.5 text-sm font-semibold text-white @sm:min-w-9 @sm:px-2.5 @sm:text-base">
                                    {cell.day}
                                  </span>
                                ) : (
                                  cell.day
                                )}
                              </span>
                              {cell.isAdjacent && (
                                <span className="shrink-0 text-[9px] font-medium uppercase tracking-wide text-muted-foreground">
                                  {monthShort}
                                </span>
                              )}
                            </div>
                            {visual.badge ? (
                              <div className="mt-auto pt-1">
                                <span className={visual.badgeClass}>{visual.badge}</span>
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
            <CardContent className="space-y-4">
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
                        className="flex items-center gap-3 rounded-xl border border-border/70 bg-card px-3 py-3 shadow-sm dark:bg-card/70"
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
                onClick={() => navigate('/employee/holidays')}
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
          className="max-w-md rounded-2xl border-border sm:max-w-md"
          innerClassName="gap-0 p-0 pr-0"
          closeButtonClassName="right-4 top-4 bg-card/95"
          onPointerDown={(event) => {
            if (event.target === event.currentTarget) setSelectedDay(null)
          }}
        >
          {selectedDayDetails && (
            <>
              <DialogHeader className="border-b border-border/70 bg-gradient-to-br from-orange-50 via-card to-card px-5 py-5 pr-16 text-left dark:from-orange-500/10 dark:via-card dark:to-card">
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
                          No clock in/out details captured for this day.
                        </p>
                      )}
                    </div>
                  </>
                )}
              </div>
            </>
          )}
        </DialogContent>
      </Dialog>

      <Dialog open={otDetailsOpen} onOpenChange={setOtDetailsOpen}>
        <DialogContent className="max-w-lg sm:max-w-2xl" innerClassName="gap-0">
          <DialogHeader className="pb-2 pr-2">
            <DialogTitle className="text-lg font-semibold tracking-tight">Overtime — {getMonthLabel()}</DialogTitle>
            <DialogDescription>
              Hours from your OT requests and clock-detected overtime without an active filing for the month you
              selected above. Times are shown in 12-hour form (your attendance timezone).
            </DialogDescription>
          </DialogHeader>
          <div className="mt-2 grid grid-cols-3 gap-2 rounded-xl border border-border/60 bg-muted/20 p-3 text-center">
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
                                  navigate(`/employee/overtime?date=${encodeURIComponent(row.date)}`)
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
            <Button type="button" onClick={() => { setOtDetailsOpen(false); navigate('/employee/overtime') }}>
              File or manage OT
            </Button>
          </div>
        </DialogContent>
      </Dialog>
    </Motion.div>
  )
}
