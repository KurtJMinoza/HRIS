import { useState, useEffect, useRef, useCallback, useMemo } from 'react'
import { Link, useSearchParams } from 'react-router-dom'
import {
  Calendar,
  CalendarDays,
  CheckCircle2,
  Clock,
  FileText,
  LogIn,
  LogOut,
  RefreshCw,
  Scan,
  Search,
  Table2,
  UserX,
  AlertCircle,
  ChevronDown,
  Download,
} from 'lucide-react'
import * as XLSX from 'xlsx'
import { AttendanceRecordsDataTable } from '@/components/attendance/AttendanceRecordsDataTable'
import { AttendanceRecordDetailSheet } from '@/components/attendance/AttendanceRecordDetailSheet'
import {
  isPendingEmployeeRow,
  resolveEmployeeStatusLabel,
  employeeDurationLabel,
  employeeTypeReasonLabel,
  employeeActivityLine,
  attendanceRecordRef,
  formatTimeHhMm,
} from '@/components/attendance/attendanceRecordUtils'
import {
  DropdownMenu,
  DropdownMenuContent,
  DropdownMenuItem,
  DropdownMenuTrigger,
} from '@/components/ui/dropdown-menu'
import { Button } from '@/components/ui/button'
import { Card, CardContent, CardDescription, CardHeader, CardTitle } from '@/components/ui/card'
import {
  Dialog,
  DialogContent,
  DialogFooter,
  DialogHeader,
  DialogTitle,
} from '@/components/ui/dialog'
import {
  ADMIN_FORM_DIALOG_BODY_CLASS,
  ADMIN_FORM_DIALOG_DESC_CLASS,
  ADMIN_FORM_DIALOG_FOOTER_CLASS,
  ADMIN_FORM_DIALOG_HEADER_INNER_CLASS,
  ADMIN_FORM_DIALOG_HEADER_WRAP_CLASS,
  ADMIN_FORM_DIALOG_TITLE_CLASS,
  adminFormDialogContentClass,
  ADMIN_FORM_DIALOG_MAX_W_MD,
} from '@/lib/adminFormDialogStyles'
import { cn } from '@/lib/utils'
import { getMyAttendanceSummary, recordAttendance, getStoredUser } from '@/api'
import { Input } from '@/components/ui/input'
import { ScannerInput } from '@/components/ScannerInput'
import { useToast } from '@/components/ui/use-toast'
function formatHours(value) {
  if (value == null) return '—'
  return Number.isFinite(value) ? value.toFixed(2) : value
}

function getLocalDateStr() {
  const n = new Date()
  return `${n.getFullYear()}-${String(n.getMonth() + 1).padStart(2, '0')}-${String(n.getDate()).padStart(2, '0')}`
}

export default function EmployeeAttendance() {
  const [rows, setRows] = useState([])
  const [attSummary, setAttSummary] = useState(null)
  const [loading, setLoading] = useState(true)
  const [error, setError] = useState(null)
  const [successMessage, setSuccessMessage] = useState(null)
  const [modalOpen, setModalOpen] = useState(false)
  const [modalType, setModalType] = useState('clock_in')
  const [submitting, setSubmitting] = useState(false)
  const [detailOpen, setDetailOpen] = useState(false)
  const [detailRow, setDetailRow] = useState(null)
  const [searchQuery, setSearchQuery] = useState('')
  const [scopeSegment, setScopeSegment] = useState('all')
  const lastScanRef = useRef({ text: null, at: 0 })
  const { toast } = useToast()
  const [searchParams, setSearchParams] = useSearchParams()

  const [fromDate, setFromDate] = useState(() => {
    const now = new Date()
    return new Date(now.getFullYear(), now.getMonth(), 1).toISOString().slice(0, 10)
  })
  const [toDate, setToDate] = useState(() => {
    const now = new Date()
    return new Date(now.getFullYear(), now.getMonth() + 1, 0).toISOString().slice(0, 10)
  })

  const fetchHistory = useCallback(async () => {
    setError(null)
    setLoading(true)
    try {
      const data = await getMyAttendanceSummary({ from_date: fromDate, to_date: toDate })
      setAttSummary(data.summary ?? null)
      const days = Array.isArray(data.days) ? data.days : []
      const todayKey = new Date().toISOString().slice(0, 10)
      const mapped = days
        .filter((d) => {
          if (fromDate && d.date < fromDate) return false
          if (toDate && d.date > toDate) return false
          return true
        })
        .map((d) => {
          const isFuture = d.date > todayKey
          const rawStatus = d.status || '—'
          const status = isFuture ? 'upcoming' : rawStatus
          const lateLabel = d.late_label || (rawStatus === 'late' ? 'Late' : null)
          return {
            date: d.date,
            time_in: isFuture ? null : d.time_in,
            time_out: isFuture ? null : d.time_out,
            virtual_time_out_from_ot: isFuture ? null : d.virtual_time_out_from_ot,
            scheduled_regular_hours: isFuture ? null : d.scheduled_regular_hours,
            schedule_in: isFuture ? null : d.schedule_in,
            schedule_out: isFuture ? null : d.schedule_out,
            total_rendered_hours: isFuture ? null : d.total_rendered_hours,
            total_hours: isFuture ? null : d.total_hours,
            night_hours: isFuture ? null : d.night_hours,
            status,
            late_label: lateLabel,
            late_minutes: isFuture ? null : d.late_minutes,
            undertime_minutes: isFuture ? null : d.undertime_minutes,
            overtime_minutes: isFuture ? null : d.overtime_minutes,
            rendered_overtime_hours: isFuture ? null : d.rendered_overtime_hours,
            approved_overtime_hours: isFuture ? null : d.approved_overtime_hours,
            overtime_hours: isFuture ? null : d.overtime_hours,
            presence_filing: d.presence_filing ?? null,
            presence_label: d.presence_label ?? null,
            presence_issue: d.presence_issue ?? null,
            leave_pay_status: d.leave_pay_status ?? null,
          }
        })
        .sort((a, b) => (a.date < b.date ? 1 : a.date > b.date ? -1 : 0))
      setRows(mapped)
    } catch (e) {
      setError(e.message)
      setRows([])
      setAttSummary(null)
    } finally {
      setLoading(false)
    }
  }, [fromDate, toDate])

  useEffect(() => {
    fetchHistory()
  }, [fetchHistory])

  const viewerUser = getStoredUser()

  const displayRows = useMemo(() => {
    let list = rows
    if (scopeSegment === 'pending') {
      list = list.filter((r) => isPendingEmployeeRow(r))
    }
    const q = searchQuery.trim().toLowerCase()
    if (!q) return list
    return list.filter((r) => {
      const refId = attendanceRecordRef(null, r.date).toLowerCase()
      const status = resolveEmployeeStatusLabel(r).toLowerCase()
      const dt = (r.date || '').toLowerCase()
      return (
        refId.includes(q) ||
        status.includes(q) ||
        dt.includes(q) ||
        (viewerUser?.company?.name || '').toLowerCase().includes(q) ||
        (viewerUser?.company_name || '').toLowerCase().includes(q) ||
        (viewerUser?.department?.name || viewerUser?.department_name || viewerUser?.department || '')
          .toLowerCase()
          .includes(q)
      )
    })
  }, [rows, scopeSegment, searchQuery, viewerUser])

  const todayIso = getLocalDateStr()
  const todayRow = useMemo(
    () => rows.find((r) => r.date === todayIso) || null,
    [rows, todayIso]
  )

  const isClockedIn =
    !!todayRow &&
    !!todayRow.time_in &&
    !todayRow.time_out &&
    todayRow.status !== 'leave' &&
    todayRow.status !== 'absent'

  const dtrStatusLabel = (() => {
    if (!todayRow) return 'No attendance recorded yet today.'
    if (isClockedIn) {
      return `Clocked in at ${formatTimeHhMm(todayRow.time_in)} — waiting for clock out.`
    }
    if (todayRow.time_in && todayRow.time_out) {
      return `Completed: ${formatTimeHhMm(todayRow.time_in)} – ${formatTimeHhMm(todayRow.time_out)}.`
    }
    if (todayRow.status === 'leave') {
      if (todayRow.leave_pay_status === 'paid') return 'On approved paid leave today.'
      if (todayRow.leave_pay_status === 'unpaid') return 'On approved unpaid leave today.'
      return 'On approved leave today.'
    }
    if (todayRow.status === 'absent') {
      return 'Marked absent for today.'
    }
    if (todayRow.status === 'upcoming') {
      return 'Upcoming workday. Your attendance will appear here once you clock in.'
    }
    return `Today: ${todayRow.status || '—'}`
  })()

  const targetDailyHours = useMemo(() => {
    const scheduledDays = rows.filter(
      (r) =>
        typeof r.scheduled_regular_hours === 'number' &&
        r.scheduled_regular_hours > 0 &&
        r.status !== 'leave' &&
        r.status !== 'absent' &&
        r.status !== 'upcoming'
    )
    if (scheduledDays.length) {
      return scheduledDays.reduce((max, r) => Math.max(max, r.scheduled_regular_hours || 0), 0)
    }
    const workedDays = rows.filter(
      (r) =>
        typeof (r.total_rendered_hours ?? r.total_hours) === 'number' &&
        (r.total_rendered_hours ?? r.total_hours) > 0 &&
        r.status !== 'leave' &&
        r.status !== 'absent' &&
        r.status !== 'upcoming'
    )
    if (!workedDays.length) return null
    return workedDays.reduce(
      (max, r) => Math.max(max, r.total_rendered_hours ?? r.total_hours ?? 0),
      0
    )
  }, [rows])

  const todayWorkedHours = (() => {
    const tr = todayRow?.total_rendered_hours ?? todayRow?.total_hours
    return typeof tr === 'number' ? tr : 0
  })()

  const remainingHours =
    typeof targetDailyHours === 'number'
      ? Math.max(targetDailyHours - todayWorkedHours, 0)
      : null

  function formatHoursLabel(value) {
    if (value == null) return '—'
    return formatHours(value)
  }

  function handleMonthChange(e) {
    const value = e.target.value // YYYY-MM
    if (!value) return
    const [yearStr, monthStr] = value.split('-')
    const year = Number(yearStr)
    const month = Number(monthStr) - 1
    if (Number.isNaN(year) || Number.isNaN(month)) return
    const start = new Date(year, month, 1)
    const end = new Date(year, month + 1, 0)
    setFromDate(start.toISOString().slice(0, 10))
    setToDate(end.toISOString().slice(0, 10))
  }

  async function handleScan(text) {
    if (!text || submitting) return
    const now = Date.now()
    const last = lastScanRef.current
    if (last.text === text && now - last.at < 2500) return
    lastScanRef.current = { text, at: now }

    setError(null)
    setSubmitting(true)
    try {
      const data = await recordAttendance(modalType, text)
      setModalOpen(false)
      let msg = data.message ?? 'Recorded.'
      if (modalType === 'clock_in' && data.attendance?.late_label) {
        msg += ` — ${data.attendance.late_label}`
      }
      toast({ title: 'Attendance recorded', description: msg, variant: 'success' })
      await fetchHistory()
    } catch (e) {
      setError(e.message)
      toast({ title: 'Failed to record attendance', description: e.message, variant: 'error' })
    } finally {
      setSubmitting(false)
    }
  }

  const openModal = (type) => {
    setModalType(type)
    setError(null)
    setSuccessMessage(null)
    setModalOpen(true)
  }

  useEffect(() => {
    const action = searchParams.get('action')
    if (action === 'clock_in' && !isClockedIn) {
      setModalType('clock_in')
      setError(null)
      setSuccessMessage(null)
      setModalOpen(true)
      setSearchParams({}, { replace: true })
    }
  }, [searchParams, isClockedIn, setSearchParams])

  const viewerName = viewerUser?.name ?? 'Me'
  const viewerInitials =
    (viewerName || '?')
      .trim()
      .split(/\s+/)
      .map((n) => n[0])
      .join('')
      .toUpperCase()
      .slice(0, 2) || '?'

  const viewerCompany =
    viewerUser?.company?.name ?? viewerUser?.company_name ?? viewerUser?.employer_name ?? '—'
  const viewerDepartment =
    viewerUser?.department?.name ?? viewerUser?.department_name ?? viewerUser?.department ?? '—'

  function exportEmployeeCsv() {
    const header = [
      'Record ID',
      'Date',
      'Rendered Hours',
      'Type',
      'Documents',
      'Activity',
      'Status',
    ]
    const csvRows = [
      header,
      ...displayRows.map((r) => [
        attendanceRecordRef(null, r.date),
        r.date,
        employeeDurationLabel(r),
        employeeTypeReasonLabel(r),
        r.presence_filing ? 1 : 0,
        employeeActivityLine(r),
        resolveEmployeeStatusLabel(r),
      ]),
    ]
    const blob = new Blob(
      [csvRows.map((cols) => cols.map((c) => `"${String(c).replace(/"/g, '""')}"`).join(',')).join('\n')],
      { type: 'text/csv;charset=utf-8;' },
    )
    const url = URL.createObjectURL(blob)
    const a = document.createElement('a')
    a.href = url
    a.download = `my-attendance-${fromDate}-${toDate}.csv`
    document.body.appendChild(a)
    a.click()
    document.body.removeChild(a)
    URL.revokeObjectURL(url)
  }

  function exportEmployeeExcel() {
    const data = displayRows.map((r) => ({
      record_id: attendanceRecordRef(null, r.date),
      date: r.date,
      rendered_hours: employeeDurationLabel(r),
      type: employeeTypeReasonLabel(r),
      documents: r.presence_filing ? 1 : 0,
      activity: employeeActivityLine(r),
      status: resolveEmployeeStatusLabel(r),
    }))
    const ws = XLSX.utils.json_to_sheet(data)
    const wb = XLSX.utils.book_new()
    XLSX.utils.book_append_sheet(wb, ws, 'Attendance')
    XLSX.writeFile(wb, `my-attendance-${fromDate}-${toDate}.xlsx`)
  }

  return (
    <div className="space-y-6">
      <div className="relative overflow-hidden rounded-2xl border border-border/60 bg-gradient-to-br from-[#f8fafc] via-card to-emerald-50/30 p-6 shadow-sm dark:from-card dark:via-card dark:to-emerald-950/20">
        <div className="flex flex-col gap-4 @lg:flex-row @lg:items-end @lg:justify-between">
          <div className="space-y-2">
            <p className="text-xs font-semibold uppercase tracking-[0.12em] text-emerald-700 dark:text-emerald-400">
              Attendance
            </p>
            <h1 className="hr-page-title">My Attendance</h1>
            <p className="max-w-xl text-sm text-muted-foreground">
              Clock in, file corrections, and request leave — your daily records stay organized in one calm workspace.
            </p>
            <p className="text-xs text-muted-foreground">
              Period <span className="font-medium text-foreground">{fromDate}</span> →{' '}
              <span className="font-medium text-foreground">{toDate}</span>
            </p>
          </div>
          <div className="flex max-w-xl flex-col gap-2 @sm:flex-row @sm:flex-wrap @sm:justify-end">
            <Button
              className="gap-2 bg-emerald-600 text-white hover:bg-emerald-500"
              onClick={() => openModal(isClockedIn ? 'clock_out' : 'clock_in')}
            >
              {isClockedIn ? <LogOut className="size-4" /> : <LogIn className="size-4" />}
              {isClockedIn ? 'Clock Out' : 'Clock In'}
            </Button>
            <Button variant="outline" className="gap-2" asChild>
              <Link to="/employee/correction-requests">File correction</Link>
            </Button>
            <Button variant="outline" className="gap-2" asChild>
              <Link to="/employee/requests">Request leave</Link>
            </Button>
          </div>
        </div>
      </div>

      {error && (
        <div className="rounded-md border border-destructive/50 bg-destructive/10 px-4 py-2 text-sm text-destructive">
          {error}
        </div>
      )}
      {successMessage && (
        <div className="rounded-md border border-green-500/50 bg-green-500/10 px-4 py-2 text-sm text-green-700 dark:text-green-300" role="status">
          {successMessage}
        </div>
      )}

      {attSummary && (
        <div className="grid gap-3 @sm:grid-cols-2 @lg:grid-cols-4">
          <Card className="overflow-hidden rounded-xl border border-border/60 shadow-sm">
            <CardContent className="p-5">
              <div className="flex items-start justify-between">
                <div>
                  <p className="text-xs font-medium text-muted-foreground">Present</p>
                  <p className="mt-1 text-3xl font-black tracking-tight text-emerald-600 dark:text-emerald-400">
                    {attSummary.present_count ?? 0}
                  </p>
                  <p className="mt-1 text-xs text-muted-foreground">In this period</p>
                </div>
                <div className="flex size-10 items-center justify-center rounded-xl bg-emerald-500/15">
                  <CheckCircle2 className="size-5 text-emerald-600" aria-hidden />
                </div>
              </div>
            </CardContent>
          </Card>
          <Card className="overflow-hidden rounded-xl border border-border/60 shadow-sm">
            <CardContent className="p-5">
              <div className="flex items-start justify-between">
                <div>
                  <p className="text-xs font-medium text-muted-foreground">Late</p>
                  <p className="mt-1 text-3xl font-black tracking-tight text-amber-600 dark:text-amber-400">
                    {attSummary.late_count ?? 0}
                  </p>
                  <p className="mt-1 text-xs text-muted-foreground">Grace / tardy</p>
                </div>
                <div className="flex size-10 items-center justify-center rounded-xl bg-amber-500/15">
                  <AlertCircle className="size-5 text-amber-600" aria-hidden />
                </div>
              </div>
            </CardContent>
          </Card>
          <Card className="overflow-hidden rounded-xl border border-border/60 shadow-sm">
            <CardContent className="p-5">
              <div className="flex items-start justify-between">
                <div>
                  <p className="text-xs font-medium text-muted-foreground">Absent</p>
                  <p className="mt-1 text-3xl font-black tracking-tight text-red-600 dark:text-red-400">
                    {attSummary.absent_count ?? 0}
                  </p>
                  <p className="mt-1 text-xs text-muted-foreground">Unexcused</p>
                </div>
                <div className="flex size-10 items-center justify-center rounded-xl bg-red-500/15">
                  <UserX className="size-5 text-red-600" aria-hidden />
                </div>
              </div>
            </CardContent>
          </Card>
          <Card className="overflow-hidden rounded-xl border border-border/60 shadow-sm">
            <CardContent className="p-5">
              <div className="flex items-start justify-between">
                <div>
                  <p className="text-xs font-medium text-muted-foreground">On leave</p>
                  <p className="mt-1 text-3xl font-black tracking-tight text-violet-600 dark:text-violet-400">
                    {attSummary.leave_count ?? 0}
                  </p>
                  <p className="mt-1 text-xs text-muted-foreground">Approved</p>
                </div>
                <div className="flex size-10 items-center justify-center rounded-xl bg-violet-500/15">
                  <CalendarDays className="size-5 text-violet-600" aria-hidden />
                </div>
              </div>
            </CardContent>
          </Card>
        </div>
      )}

      {targetDailyHours && (
        <Card className="border-border/80 bg-card/95 shadow-sm">
          <CardHeader className="pb-3">
            <CardTitle className="text-sm font-semibold @md:text-base">
              Today&apos;s schedule context
            </CardTitle>
            <CardDescription className="text-xs @md:text-sm">
              Based on your recent working days. Actual rules (grace period, overtime) are enforced
              by the SmartDTR engine.
            </CardDescription>
          </CardHeader>
          <CardContent>
            <div className="grid gap-4 text-sm @md:grid-cols-4">
              <div className="space-y-1">
                <div className="text-[11px] font-medium uppercase tracking-[0.08em] text-muted-foreground">
                  Planned hours (approx.)
                </div>
                <div className="text-lg font-semibold text-foreground">
                  {formatHoursLabel(targetDailyHours)}h
                </div>
              </div>
              <div className="space-y-1">
                <div className="text-[11px] font-medium uppercase tracking-[0.08em] text-muted-foreground">
                  Worked so far today
                </div>
                <div className="text-lg font-semibold text-foreground">
                  {formatHoursLabel(todayWorkedHours)}h
                </div>
              </div>
              <div className="space-y-1">
                <div className="text-[11px] font-medium uppercase tracking-[0.08em] text-muted-foreground">
                  Remaining (approx.)
                </div>
                <div className="text-lg font-semibold text-foreground">
                  {remainingHours != null ? `${formatHoursLabel(remainingHours)}h` : '—'}
                </div>
              </div>
              <div className="space-y-1">
                <div className="text-[11px] font-medium uppercase tracking-[0.08em] text-muted-foreground">
                  Today&apos;s status
                </div>
                <div className="text-lg font-semibold capitalize text-foreground">
                  {todayRow?.status === 'upcoming' ? 'Upcoming' : todayRow?.status || '—'}
                </div>
              </div>
            </div>
          </CardContent>
        </Card>
      )}

      <Card className="border-border/80 bg-card/95 shadow-sm">
        <CardHeader>
          <CardTitle className="flex items-center gap-2">
            <Clock className="size-5" />
            DTR
          </CardTitle>
          <CardDescription>
            Scan your QR code or use Face Recognition to record your attendance — the same secure method as the SmartDTR kiosk.
          </CardDescription>
        </CardHeader>
        <CardContent className="flex flex-col gap-3 @sm:flex-row @sm:flex-wrap @sm:items-center @sm:justify-between">
          <div className="flex flex-wrap gap-3">
            <Button
              onClick={() => openModal('clock_in')}
              disabled={isClockedIn}
              variant={isClockedIn ? 'outline' : 'default'}
            >
              <LogIn className="size-4" />
              Clock In
            </Button>
            <Button
              variant={isClockedIn ? 'default' : 'outline'}
              disabled={!isClockedIn}
              onClick={() => openModal('clock_out')}
            >
              <LogOut className="size-4" />
              Clock Out
            </Button>
          </div>
          <p className="text-xs text-muted-foreground">{dtrStatusLabel}</p>
        </CardContent>
      </Card>

      <Card className="border-border/80 bg-card/95 shadow-sm">
        <CardHeader className="pb-3">
          <CardTitle className="text-base font-semibold">Correction requests</CardTitle>
          <CardDescription>
            Submit and track attendance corrections in one place — open{' '}
            <Link to="/employee/correction-requests" className="font-medium text-primary underline-offset-4 hover:underline">
              Correction Requests
            </Link>{' '}
            to file a new request or view status.
          </CardDescription>
        </CardHeader>
      </Card>

      <Card className="rounded-xl border-border/80 bg-card/95 shadow-sm">
        <CardHeader className="pb-3">
          <div className="flex flex-col gap-3 @lg:flex-row @lg:items-start @lg:justify-between">
            <div>
              <CardTitle className="text-sm font-semibold">Filters</CardTitle>
              <CardDescription className="text-xs">
                Choose a month or custom date range, then search or narrow to pending items.
              </CardDescription>
            </div>
            <div
              className="inline-flex flex-wrap rounded-xl border border-border/60 bg-muted/30 p-0.5"
              role="tablist"
              aria-label="Scope"
            >
              {[
                { id: 'all', label: 'All', action: () => setScopeSegment('all') },
                {
                  id: 'today',
                  label: 'Today',
                  action: () => {
                    const t = getLocalDateStr()
                    setFromDate(t)
                    setToDate(t)
                    setScopeSegment('today')
                  },
                },
                {
                  id: 'week',
                  label: 'This week',
                  action: () => {
                    const now = new Date()
                    const day = now.getDay()
                    const diff = day === 0 ? -6 : 1 - day
                    const mon = new Date(now)
                    mon.setDate(now.getDate() + diff)
                    const m = `${mon.getFullYear()}-${String(mon.getMonth() + 1).padStart(2, '0')}-${String(mon.getDate()).padStart(2, '0')}`
                    const tod = getLocalDateStr()
                    setFromDate(m)
                    setToDate(tod)
                    setScopeSegment('week')
                  },
                },
                { id: 'pending', label: 'Pending', action: () => setScopeSegment('pending') },
              ].map(({ id, label, action }) => (
                <button
                  key={id}
                  type="button"
                  role="tab"
                  aria-selected={scopeSegment === id}
                  onClick={action}
                  className={cn(
                    'rounded-[10px] px-3 py-1.5 text-xs font-semibold transition-all',
                    scopeSegment === id ? 'bg-background text-foreground shadow-sm' : 'text-muted-foreground hover:text-foreground',
                  )}
                >
                  {label}
                </button>
              ))}
            </div>
          </div>
        </CardHeader>
        <CardContent className="flex flex-col gap-4 @md:flex-row @md:items-end @md:justify-between">
          <div className="relative w-full max-w-md">
            <Search className="pointer-events-none absolute left-2.5 top-1/2 size-4 -translate-y-1/2 text-muted-foreground" aria-hidden />
            <Input
              value={searchQuery}
              onChange={(e) => setSearchQuery(e.target.value)}
              placeholder="Search date, status, record ID…"
              className="h-9 pl-9 text-sm"
              aria-label="Search attendance history"
            />
          </div>
          <div className="grid w-full grid-cols-1 gap-3 @md:max-w-xl @md:grid-cols-3">
            <div className="space-y-1.5">
              <span className="text-xs font-medium text-muted-foreground">Month</span>
              <div className="relative">
                <Calendar className="pointer-events-none absolute left-2.5 top-1/2 size-4 -translate-y-1/2 text-foreground/70" />
                <input
                  type="month"
                  value={fromDate.slice(0, 7)}
                  onChange={handleMonthChange}
                  className="flex h-9 w-full rounded-md border border-input bg-background pl-9 pr-3 text-sm shadow-sm focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-ring"
                />
              </div>
            </div>
            <div className="space-y-1.5">
              <span className="text-xs font-medium text-muted-foreground">From</span>
              <div className="relative">
                <Calendar className="pointer-events-none absolute left-2.5 top-1/2 size-4 -translate-y-1/2 text-foreground/70" />
                <Input
                  type="date"
                  value={fromDate}
                  onChange={(e) => setFromDate(e.target.value)}
                  className="h-9 pl-9 text-sm"
                />
              </div>
            </div>
            <div className="space-y-1.5">
              <span className="text-xs font-medium text-muted-foreground">To</span>
              <div className="relative">
                <Calendar className="pointer-events-none absolute left-2.5 top-1/2 size-4 -translate-y-1/2 text-foreground/70" />
                <Input
                  type="date"
                  value={toDate}
                  onChange={(e) => setToDate(e.target.value)}
                  className="h-9 pl-9 text-sm"
                />
              </div>
            </div>
          </div>
          <div className="flex flex-wrap items-center gap-2">
            <Button type="button" variant="default" size="sm" className="gap-1.5" onClick={fetchHistory}>
              <RefreshCw className="size-3.5" />
              Apply
            </Button>
            <DropdownMenu>
              <DropdownMenuTrigger asChild>
                <Button type="button" variant="outline" size="sm" className="gap-1.5" disabled={!displayRows.length}>
                  <Download className="size-3.5" aria-hidden />
                  Export
                  <ChevronDown className="size-3.5 opacity-60" aria-hidden />
                </Button>
              </DropdownMenuTrigger>
              <DropdownMenuContent align="end">
                <DropdownMenuItem onSelect={() => exportEmployeeCsv()}>
                  <FileText className="size-4" />
                  CSV
                </DropdownMenuItem>
                <DropdownMenuItem onSelect={() => exportEmployeeExcel()}>
                  <FileText className="size-4" />
                  Excel
                </DropdownMenuItem>
              </DropdownMenuContent>
            </DropdownMenu>
          </div>
        </CardContent>
      </Card>

      <div className="space-y-3">
        <div className="flex items-center gap-2 text-muted-foreground">
          <Table2 className="size-5 shrink-0" aria-hidden />
          <h3 className="text-base font-semibold text-foreground">History</h3>
        </div>
        <Card className="overflow-hidden rounded-xl border border-border/60 shadow-sm">
          <CardHeader>
            <CardTitle>Attendance history</CardTitle>
            <CardDescription>Tap a row for clock times, filings, and remarks.</CardDescription>
          </CardHeader>
          <CardContent>
            <AttendanceRecordsDataTable
              mode="employee"
              rows={displayRows}
              loading={loading}
              onOpenDetails={(r) => {
                setDetailRow(r)
                setDetailOpen(true)
              }}
              viewerName={viewerName}
              viewerInitials={viewerInitials}
              viewerImageSrc={viewerUser?.profile_image}
              viewerCompany={viewerCompany}
              viewerDepartment={viewerDepartment}
              emptyMessage={
                rows.length === 0
                  ? 'No attendance records yet for this period.'
                  : 'No records match your search or filters.'
              }
            />
          </CardContent>
        </Card>
      </div>

      <AttendanceRecordDetailSheet
        open={detailOpen}
        onOpenChange={(o) => {
          setDetailOpen(o)
          if (!o) setDetailRow(null)
        }}
        mode="employee"
        row={detailRow}
        employeeName={viewerName}
        employeeInitials={viewerInitials}
        profileSrc={viewerUser?.profile_image}
        correctionsHref="/employee/correction-requests"
      />

      <Dialog open={modalOpen} onOpenChange={(open) => { setModalOpen(open); if (!open) setError(null); }}>
        <DialogContent
          showCloseButton
          className={adminFormDialogContentClass(ADMIN_FORM_DIALOG_MAX_W_MD)}
          aria-describedby="emp-attendance-scan-desc"
        >
          <div className={ADMIN_FORM_DIALOG_HEADER_WRAP_CLASS}>
            <DialogHeader className={ADMIN_FORM_DIALOG_HEADER_INNER_CLASS}>
              <DialogTitle className={cn(ADMIN_FORM_DIALOG_TITLE_CLASS, 'flex items-center gap-2')}>
                <Scan className="size-5 text-primary" />
                {modalType === 'clock_in' ? 'Clock In' : 'Clock Out'}
              </DialogTitle>
              <p id="emp-attendance-scan-desc" className={ADMIN_FORM_DIALOG_DESC_CLASS}>
                Scan your personal QR code using the barcode scanner. Your attendance will be recorded automatically — no button press required.
              </p>
            </DialogHeader>
          </div>
          <div className={ADMIN_FORM_DIALOG_BODY_CLASS}>
            {modalOpen && (
              <ScannerInput
                onScan={handleScan}
                submitting={submitting}
                error={error}
                theme="light"
              />
            )}
          </div>
          <DialogFooter className={ADMIN_FORM_DIALOG_FOOTER_CLASS}>
            <Button type="button" variant="outline" onClick={() => { setModalOpen(false); setError(null); }}>
              Cancel
            </Button>
          </DialogFooter>
        </DialogContent>
      </Dialog>

    </div>
  )
}
