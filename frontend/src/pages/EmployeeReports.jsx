import { useCallback, useEffect, useMemo, useState } from 'react'
import { Calendar, FileText, RefreshCw, Table2 } from 'lucide-react'
import { Card, CardContent, CardDescription, CardHeader, CardTitle } from '@/components/ui/card'
import { Button } from '@/components/ui/button'
import { Input } from '@/components/ui/input'
import { getMyAttendanceSummary } from '@/api'
import { AttendanceStatusBadge } from '@/components/AttendanceStatusBadge'
import { CardMetricSkeleton, TableBodySkeleton } from '@/components/skeletons'

function todayIso() {
  return new Date().toISOString().slice(0, 10)
}

function firstDayOfMonthIso() {
  const d = new Date()
  return new Date(d.getFullYear(), d.getMonth(), 1).toISOString().slice(0, 10)
}

function formatDate(dateStr) {
  if (!dateStr) return '—'
  const d = new Date(dateStr)
  return d.toLocaleDateString('en-PH', {
    year: 'numeric',
    month: 'short',
    day: 'numeric',
  })
}

function formatHours(value) {
  if (value == null) return '—'
  const num = Number(value)
  if (!Number.isFinite(num)) return '—'
  return num.toFixed(2)
}

// Attendance report times: normalize to 24-hour HH:MM:SS (and strip broken prefixes like "—01:00").
function formatTimeTo12Hour(value) {
  if (!value) return '—'
  let str = String(value).trim()
  str = str.replace(/^[^\d]*/, '')

  if (str.includes('T')) {
    const isoWithSeconds = str.match(/T(\d{2}):(\d{2}):(\d{2})/)
    if (isoWithSeconds) {
      const [, hh, mm, ss] = isoWithSeconds
      return `${hh}:${mm}:${ss}`
    }
    const isoWithoutSeconds = str.match(/T(\d{2}):(\d{2})/)
    if (isoWithoutSeconds) {
      const [, hh, mm] = isoWithoutSeconds
      return `${hh}:${mm}:00`
    }
    return str
  }

  if (/^\d{1,2}:\d{2}:\d{2}$/.test(str)) {
    const [hStr, mStr, sStr] = str.split(':')
    const h = Number(hStr)
    if (Number.isNaN(h)) return str
    return `${String(h).padStart(2, '0')}:${mStr}:${sStr}`
  }

  if (/^\d{1,2}:\d{2}$/.test(str)) {
    const [hStr, mStr] = str.split(':')
    const h = Number(hStr)
    if (Number.isNaN(h)) return str
    return `${String(h).padStart(2, '0')}:${mStr}:00`
  }

  return str
}

const LEAVE_TYPE_LABELS = {
  vacation: 'Vacation',
  sick: 'Sick',
  emergency: 'Emergency',
  undertime: 'Undertime',
  half_day: 'Half Day',
  other: 'Other',
}

function formatLeaveType(value) {
  if (!value) return '—'
  return LEAVE_TYPE_LABELS[value] || String(value).replace(/_/g, ' ')
}

export default function EmployeeReports() {
  const [fromDate, setFromDate] = useState(() => firstDayOfMonthIso())
  const [toDate, setToDate] = useState(() => todayIso())
  const [summary, setSummary] = useState(null)
  const [days, setDays] = useState([])
  const [loading, setLoading] = useState(true)
  const [error, setError] = useState(null)
  const [exportingPdf, setExportingPdf] = useState(false)

  const load = useCallback(async () => {
    const from = fromDate || firstDayOfMonthIso()
    const to = toDate || todayIso()
    setLoading(true)
    setError(null)
    try {
      const data = await getMyAttendanceSummary({ from_date: from, to_date: to })
      setSummary(data.summary || null)
      setDays(Array.isArray(data.days) ? data.days : [])
    } catch (e) {
      setError(e?.message || 'Failed to load attendance summary')
      setSummary(null)
      setDays([])
    } finally {
      setLoading(false)
    }
  }, [fromDate, toDate])

  useEffect(() => {
    load()
  }, [load])

  /** Keep employee view aligned with admin after clock-out / corrections (QA: single source of truth). */
  useEffect(() => {
    const id = window.setInterval(() => load(), 10000)
    return () => window.clearInterval(id)
  }, [load])

  const periodLabel =
    summary?.from_date && summary?.to_date && summary.from_date !== summary.to_date
      ? `${summary.from_date} to ${summary.to_date}`
      : summary?.from_date || ''

  const metrics = useMemo(() => {
    const s = summary || {}
    const workDays =
      (s.present_count || 0) +
      (s.late_count || 0) +
      (s.halfday_count || 0) +
      (s.undertime_count || 0)
    return {
      workDays,
      lateCount: s.late_count || 0,
      overtimeHours: s.overtime_hours || 0,
      absences: s.absent_count || 0,
      totalHours: s.total_hours || 0,
    }
  }, [summary])

  const rowsForTable = useMemo(
    () =>
      days
        .filter((d) => d.time_in || d.time_out || (d.status && d.status !== '—'))
        .sort((a, b) => (a.date < b.date ? 1 : a.date > b.date ? -1 : 0)),
    [days]
  )

  const dailyBreakdownRows = useMemo(() => {
    return rowsForTable.map((att) => {
      const hasOut = Boolean(att.time_out)
      const overtimeHours = hasOut ? att.overtime_hours : null
      const overtimeMinutes =
        hasOut && overtimeHours != null && Number.isFinite(Number(overtimeHours))
          ? Math.round(Number(overtimeHours) * 60)
          : null
      const schedLabel =
        att.schedule_in && att.schedule_out
          ? `${att.schedule_in} – ${att.schedule_out}`
          : att.schedule_in || att.schedule_out || '—'
      return {
        ...att,
        schedule: schedLabel,
        early_out_time: null,
        overtime_minutes: overtimeMinutes,
        night_hours: hasOut ? att.night_hours ?? null : null,
        night_differential_pay: null,
        overtime_pay: null,
        total_premium_pay: null,
        work_condition: null,
        pay_rule: null,
        multiplier: null,
        leave_type: att.leave_type ?? null,
        leave_status: att.leave_status ?? null,
        leave_duration_days: att.leave_duration_days ?? null,
        undertime_filing_status: att.undertime_filing_status ?? null,
        payroll_impact_hours: att.payroll_impact_hours ?? null,
        overtime_status: att.overtime_status ?? null,
      }
    })
  }, [rowsForTable])

  async function handleExportPdf() {
    if (!dailyBreakdownRows.length) return
    setExportingPdf(true)
    try {
      const [{ pdf }, { default: ReportPdfDocument }] = await Promise.all([
        import('@react-pdf/renderer'),
        import('@/components/reports/ReportPdfDocument'),
      ])
      const getStatusLabel = (row) =>
        row.status === 'undertime'
          ? row.undertime_filing_status === 'approved'
            ? 'Undertime (Approved)'
            : 'Undertime (Unfiled)'
          : row.status === 'clocked_in'
            ? row.late_label
              ? `Clocked In · ${row.late_label}`
              : 'Clocked In'
            : row.status === 'incomplete'
              ? 'Incomplete'
              : row.status === 'late' && row.late_label
                ? row.late_label
                : row.status === 'present'
                  ? 'Present'
                  : row.status === 'halfday'
                    ? 'Half Day'
                    : row.status === 'absent'
                      ? 'Absent'
                      : row.status === 'leave'
                        ? 'Leave'
                        : row.status || '—'
      const getLeaveDuration = (row) =>
        row.leave_type === 'undertime' &&
        row.leave_status === 'approved' &&
        row.undertime_minutes != null
          ? `${(Number(row.undertime_minutes) / 60).toFixed(2)} hours`
          : row.leave_duration_days != null
            ? `${row.leave_duration_days} day(s)`
            : '—'
      const columns = [
        { label: 'Date', accessor: (row) => formatDate(row.date) },
        { label: 'Schedule', accessor: (row) => row.schedule ?? '—' },
        { label: 'Time In', accessor: (row) => (row.time_in ? formatTimeTo12Hour(row.time_in) : '—') },
        { label: 'Time Out', accessor: (row) => (row.time_out ? formatTimeTo12Hour(row.time_out) : '—') },
        { label: 'Early Out', accessor: (row) => (row.early_out_time ? formatTimeTo12Hour(row.early_out_time) : '—') },
        { label: 'Total Hrs', accessor: (row) => (row.total_hours != null ? Number(row.total_hours).toFixed(2) : '—') },
        { label: 'Late (min)', accessor: (row) => (row.late_minutes != null ? String(row.late_minutes) : '0') },
        { label: 'Undertime (min)', accessor: (row) => (row.undertime_minutes != null ? String(row.undertime_minutes) : '0') },
        { label: 'OT (min)', accessor: (row) => (row.overtime_minutes != null ? String(row.overtime_minutes) : '—') },
        { label: 'ND hrs', accessor: (row) => (row.night_hours != null ? Number(row.night_hours).toFixed(2) : '—') },
        {
          label: 'ND pay (₱)',
          accessor: (row) =>
            row.night_differential_pay != null
              ? Number(row.night_differential_pay).toLocaleString('en-PH', { minimumFractionDigits: 2 })
              : '—',
        },
        {
          label: 'OT pay (₱)',
          accessor: (row) =>
            row.overtime_pay != null
              ? Number(row.overtime_pay).toLocaleString('en-PH', { minimumFractionDigits: 2 })
              : '—',
        },
        {
          label: 'Total premium (₱)',
          accessor: (row) =>
            row.total_premium_pay != null
              ? Number(row.total_premium_pay).toLocaleString('en-PH', { minimumFractionDigits: 2 })
              : '—',
        },
        { label: 'Work Condition', accessor: (row) => row.work_condition ?? '—' },
        { label: 'Pay Rule', accessor: (row) => row.pay_rule ?? '—' },
        { label: 'Multiplier', accessor: (row) => row.multiplier ?? '—' },
        { label: 'Status', accessor: (row) => getStatusLabel(row) },
        { label: 'Leave Type', accessor: (row) => formatLeaveType(row.leave_type) },
        { label: 'Leave Status', accessor: (row) => row.leave_status ?? '—' },
        { label: 'Leave Duration', accessor: (row) => getLeaveDuration(row) },
        {
          label: 'Payroll Impact',
          accessor: (row) =>
            row.payroll_impact_hours != null ? Number(row.payroll_impact_hours).toFixed(2) : '0.00',
        },
        { label: 'OT Status', accessor: (row) => row.overtime_status ?? '—' },
      ]
      const title = 'Personal Attendance Report'
      const period =
        periodLabel || `${fromDate || '—'} to ${toDate || '—'}`
      const doc = (
        <ReportPdfDocument
          title={title}
          period={period}
          rows={dailyBreakdownRows}
          columns={columns}
          orientation="landscape"
        />
      )
      const blob = await pdf(doc).toBlob()
      const url = URL.createObjectURL(blob)
      const a = document.createElement('a')
      a.href = url
      a.download = `attendance-report-${fromDate || ''}-${toDate || ''}.pdf`
      document.body.appendChild(a)
      a.click()
      document.body.removeChild(a)
      URL.revokeObjectURL(url)
    } finally {
      setExportingPdf(false)
    }
  }

  return (
    <div className="min-h-[60vh] space-y-6">
      <div className="flex flex-col gap-2 @md:flex-row @md:items-center @md:justify-between">
        <div>
          <h1 className="hr-page-title">My Reports</h1>
          <p className="text-sm text-muted-foreground">
            Personal monthly attendance summary with exportable report.
          </p>
        </div>
      </div>

      <Card className="border-border/80 bg-card/95 shadow-sm">
        <CardHeader className="pb-3">
          <CardTitle className="text-sm font-semibold">Period</CardTitle>
          <CardDescription className="text-xs">
            Choose a date range to generate your personal report.
          </CardDescription>
        </CardHeader>
        <CardContent className="flex flex-col gap-3 @md:flex-row @md:items-end @md:justify-between">
          <div className="grid w-full grid-cols-1 gap-3 @md:max-w-md @md:grid-cols-2">
            <div className="space-y-1.5">
              <span className="text-xs font-medium text-muted-foreground">From</span>
              <div className="relative">
                <Calendar className="pointer-events-none absolute left-2.5 top-1/2 size-4 -translate-y-1/2 text-muted-foreground" />
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
                <Calendar className="pointer-events-none absolute left-2.5 top-1/2 size-4 -translate-y-1/2 text-muted-foreground" />
                <Input
                  type="date"
                  value={toDate}
                  onChange={(e) => setToDate(e.target.value)}
                  className="h-9 pl-9 text-sm"
                />
              </div>
            </div>
          </div>
          <div className="flex flex-wrap gap-2">
            <Button
              type="button"
              variant="outline"
              size="sm"
              className="gap-1.5"
              onClick={() => load()}
            >
              <RefreshCw className="size-3.5" />
              Refresh
            </Button>
            <Button
              type="button"
              variant="outline"
              size="sm"
              className="gap-1.5"
              onClick={handleExportPdf}
              disabled={exportingPdf || !dailyBreakdownRows.length}
            >
              {exportingPdf ? (
                <RefreshCw className="size-3.5 animate-spin" />
              ) : (
                <FileText className="size-3.5" />
              )}
              Download PDF
            </Button>
          </div>
        </CardContent>
      </Card>

      {error && (
        <div className="rounded-md border border-destructive/50 bg-destructive/10 px-3 py-2 text-sm text-destructive">
          {error}
        </div>
      )}

      <div className="grid gap-4 @md:grid-cols-2 @lg:grid-cols-3">
        {loading ? (
          Array.from({ length: 5 }, (_, i) => <CardMetricSkeleton key={i} />)
        ) : (
          <>
            <Card className="border-border/80 shadow-sm">
              <CardHeader className="pb-2">
                <CardTitle className="text-sm font-medium text-muted-foreground">
                  Total work days
                </CardTitle>
              </CardHeader>
              <CardContent>
                <p className="text-2xl font-bold">{metrics.workDays}</p>
              </CardContent>
            </Card>
            <Card className="border-border/80 shadow-sm">
              <CardHeader className="pb-2">
                <CardTitle className="text-sm font-medium text-muted-foreground">
                  Total late count
                </CardTitle>
              </CardHeader>
              <CardContent>
                <p className="text-2xl font-bold">{metrics.lateCount}</p>
              </CardContent>
            </Card>
            <Card className="border-border/80 shadow-sm">
              <CardHeader className="pb-2">
                <CardTitle className="text-sm font-medium text-muted-foreground">
                  Total overtime hours
                </CardTitle>
              </CardHeader>
              <CardContent>
                <p className="text-2xl font-bold">{formatHours(metrics.overtimeHours)}</p>
              </CardContent>
            </Card>
            <Card className="border-border/80 shadow-sm">
              <CardHeader className="pb-2">
                <CardTitle className="text-sm font-medium text-muted-foreground">
                  Total absences
                </CardTitle>
              </CardHeader>
              <CardContent>
                <p className="text-2xl font-bold">{metrics.absences}</p>
              </CardContent>
            </Card>
            <Card className="border-border/80 shadow-sm">
              <CardHeader className="pb-2">
                <CardTitle className="text-sm font-medium text-muted-foreground">
                  Total hours
                </CardTitle>
              </CardHeader>
              <CardContent>
                <p className="text-2xl font-bold">{formatHours(metrics.totalHours)}</p>
              </CardContent>
            </Card>
          </>
        )}
      </div>

      <div className="space-y-3">
        <div className="flex items-center gap-2 text-muted-foreground">
          <Table2 className="size-5 shrink-0" aria-hidden />
          <h3 className="text-base font-semibold text-foreground">Daily breakdown</h3>
        </div>
        <Card className="border-0 shadow-sm">
          <CardContent className="pt-4">
            <div className="overflow-x-auto rounded-md border-0">
              <table className="min-w-max w-full text-sm border-0">
                <thead>
                  <tr className="sticky top-0 z-10 border-b-0 bg-muted/40">
                    <th style={{ minWidth: 100 }} className="px-3 py-2 whitespace-nowrap text-left text-xs font-medium text-muted-foreground">Date</th>
                    <th style={{ minWidth: 110 }} className="px-3 py-2 whitespace-nowrap text-left text-xs font-medium text-muted-foreground">Schedule</th>
                    <th style={{ minWidth: 90 }} className="px-3 py-2 whitespace-nowrap text-center text-xs font-medium text-muted-foreground">Time In</th>
                    <th style={{ minWidth: 90 }} className="px-3 py-2 whitespace-nowrap text-center text-xs font-medium text-muted-foreground">Time Out</th>
                    <th style={{ minWidth: 90 }} className="px-3 py-2 whitespace-nowrap text-center text-xs font-medium text-muted-foreground">Early Out</th>
                    <th style={{ minWidth: 80 }} className="px-3 py-2 whitespace-nowrap text-right text-xs font-medium text-muted-foreground">Total Hrs</th>
                    <th style={{ minWidth: 80 }} className="px-3 py-2 whitespace-nowrap text-right text-xs font-medium text-muted-foreground">Late (min)</th>
                    <th style={{ minWidth: 100 }} className="px-3 py-2 whitespace-nowrap text-right text-xs font-medium text-muted-foreground">Undertime (min)</th>
                    <th style={{ minWidth: 80 }} className="px-3 py-2 whitespace-nowrap text-right text-xs font-medium text-muted-foreground">OT (min)</th>
                    <th style={{ minWidth: 70 }} className="px-3 py-2 whitespace-nowrap text-right text-xs font-medium text-muted-foreground">ND hrs</th>
                    <th style={{ minWidth: 100 }} className="px-3 py-2 whitespace-nowrap text-right text-xs font-medium text-muted-foreground">ND pay (₱)</th>
                    <th style={{ minWidth: 100 }} className="px-3 py-2 whitespace-nowrap text-right text-xs font-medium text-muted-foreground">OT pay (₱)</th>
                    <th style={{ minWidth: 120 }} className="px-3 py-2 whitespace-nowrap text-right text-xs font-medium text-muted-foreground">Total premium (₱)</th>
                    <th style={{ minWidth: 140 }} className="px-3 py-2 whitespace-nowrap text-left text-xs font-medium text-muted-foreground">Work Condition</th>
                    <th style={{ minWidth: 110 }} className="px-3 py-2 whitespace-nowrap text-left text-xs font-medium text-muted-foreground">Pay Rule</th>
                    <th style={{ minWidth: 110 }} className="px-3 py-2 whitespace-nowrap text-center text-xs font-medium text-muted-foreground">Multiplier</th>
                    <th style={{ minWidth: 100 }} className="px-3 py-2 whitespace-nowrap text-center text-xs font-medium text-muted-foreground">Status</th>
                    <th style={{ minWidth: 110 }} className="px-3 py-2 whitespace-nowrap text-left text-xs font-medium text-muted-foreground">Leave Type</th>
                    <th style={{ minWidth: 100 }} className="px-3 py-2 whitespace-nowrap text-center text-xs font-medium text-muted-foreground">Leave Status</th>
                    <th style={{ minWidth: 90 }} className="px-3 py-2 whitespace-nowrap text-right text-xs font-medium text-muted-foreground">Leave Duration</th>
                    <th style={{ minWidth: 100 }} className="px-3 py-2 whitespace-nowrap text-right text-xs font-medium text-muted-foreground">Payroll Impact</th>
                    <th style={{ minWidth: 110 }} className="px-3 py-2 whitespace-nowrap text-center text-xs font-medium text-muted-foreground">OT Status</th>
                  </tr>
                </thead>
                <tbody>
                  {loading ? (
                    <TableBodySkeleton rows={6} cols={12} />
                  ) : !dailyBreakdownRows.length ? (
                    <tr>
                      <td colSpan={21} className="px-3 py-8 text-center text-sm text-muted-foreground">
                        No attendance records for this period.
                      </td>
                    </tr>
                  ) : (
                    dailyBreakdownRows.map((row) => {
                      const statusLabel =
                        row.status === 'undertime'
                          ? row.undertime_filing_status === 'approved'
                            ? 'Undertime (Approved)'
                            : 'Undertime (Unfiled)'
                          : row.status === 'clocked_in'
                            ? row.late_label
                              ? `Clocked In · ${row.late_label}`
                              : 'Clocked In'
                            : row.status === 'incomplete'
                              ? 'Incomplete'
                              : row.status === 'late' && row.late_label
                                ? row.late_label
                                : row.status === 'present'
                                  ? row.late_label || 'Present'
                                  : row.status === 'halfday'
                                    ? row.late_label || 'Half Day'
                                    : row.status === 'absent'
                                      ? 'Absent'
                                      : row.status === 'leave'
                                        ? 'Leave'
                                        : row.status || '—'
                      const leaveDuration =
                        row.leave_type === 'undertime' &&
                        row.leave_status === 'approved' &&
                        row.undertime_minutes != null
                          ? `${(Number(row.undertime_minutes) / 60).toFixed(2)} hours`
                          : row.leave_duration_days != null
                            ? `${row.leave_duration_days} day(s)`
                            : '—'
                      return (
                        <tr key={row.date} className="border-b-0 hover:bg-muted/30">
                          <td style={{ minWidth: 100 }} className="px-3 py-2 whitespace-nowrap text-xs">{formatDate(row.date)}</td>
                          <td style={{ minWidth: 110 }} className="px-3 py-2 whitespace-nowrap text-xs">{row.schedule ?? '—'}</td>
                          <td style={{ minWidth: 90 }} className="px-3 py-2 whitespace-nowrap text-center font-mono text-xs tabular-nums">
                            {row.time_in ? formatTimeTo12Hour(row.time_in) : '—'}
                          </td>
                          <td style={{ minWidth: 90 }} className="px-3 py-2 whitespace-nowrap text-center font-mono text-xs tabular-nums">
                            {row.time_out ? formatTimeTo12Hour(row.time_out) : '—'}
                          </td>
                          <td style={{ minWidth: 90 }} className="px-3 py-2 whitespace-nowrap text-center font-mono text-xs tabular-nums">
                            {row.early_out_time ? formatTimeTo12Hour(row.early_out_time) : '—'}
                          </td>
                          <td style={{ minWidth: 80 }} className="px-3 py-2 whitespace-nowrap text-right font-mono text-xs tabular-nums">
                            {row.total_hours != null ? Number(row.total_hours).toFixed(2) : '—'}
                          </td>
                          <td style={{ minWidth: 80 }} className="px-3 py-2 whitespace-nowrap text-right font-mono text-xs tabular-nums">
                            {row.late_minutes != null ? String(row.late_minutes) : '0'}
                          </td>
                          <td style={{ minWidth: 100 }} className="px-3 py-2 whitespace-nowrap text-right font-mono text-xs tabular-nums">
                            {row.undertime_minutes != null ? String(row.undertime_minutes) : '0'}
                          </td>
                          <td style={{ minWidth: 80 }} className="px-3 py-2 whitespace-nowrap text-right font-mono text-xs tabular-nums">
                            {row.overtime_minutes != null ? String(row.overtime_minutes) : '—'}
                          </td>
                          <td style={{ minWidth: 70 }} className="px-3 py-2 whitespace-nowrap text-right font-mono text-xs tabular-nums">
                            {row.night_hours != null ? Number(row.night_hours).toFixed(2) : '—'}
                          </td>
                          <td style={{ minWidth: 100 }} className="px-3 py-2 whitespace-nowrap text-right font-mono text-xs tabular-nums">
                            {row.night_differential_pay != null
                              ? Number(row.night_differential_pay).toLocaleString('en-PH', {
                                  minimumFractionDigits: 2,
                                })
                              : '—'}
                          </td>
                          <td style={{ minWidth: 100 }} className="px-3 py-2 whitespace-nowrap text-right font-mono text-xs tabular-nums">
                            {row.overtime_pay != null
                              ? Number(row.overtime_pay).toLocaleString('en-PH', {
                                  minimumFractionDigits: 2,
                                })
                              : '—'}
                          </td>
                          <td style={{ minWidth: 120 }} className="px-3 py-2 whitespace-nowrap text-right font-mono text-xs tabular-nums">
                            {row.total_premium_pay != null
                              ? Number(row.total_premium_pay).toLocaleString('en-PH', {
                                  minimumFractionDigits: 2,
                                })
                              : '—'}
                          </td>
                          <td style={{ minWidth: 140 }} className="px-3 py-2 whitespace-nowrap text-xs">{row.work_condition ?? '—'}</td>
                          <td style={{ minWidth: 110 }} className="px-3 py-2 whitespace-nowrap text-xs">{row.pay_rule ?? '—'}</td>
                          <td style={{ minWidth: 110 }} className="px-3 py-2 whitespace-nowrap text-center font-mono text-xs tabular-nums">{row.multiplier ?? '—'}</td>
                          <td style={{ minWidth: 100 }} className="px-3 py-2 whitespace-nowrap text-center">
                            {statusLabel === '—' ? (
                              <span className="text-muted-foreground">—</span>
                            ) : (
                              <AttendanceStatusBadge status={row.status} label={statusLabel} />
                            )}
                          </td>
                          <td style={{ minWidth: 110 }} className="px-3 py-2 whitespace-nowrap text-xs">{formatLeaveType(row.leave_type)}</td>
                          <td style={{ minWidth: 100 }} className="px-3 py-2 whitespace-nowrap text-center text-xs">{row.leave_status ?? '—'}</td>
                          <td style={{ minWidth: 90 }} className="px-3 py-2 whitespace-nowrap text-right font-mono text-xs tabular-nums">{leaveDuration}</td>
                          <td style={{ minWidth: 100 }} className="px-3 py-2 whitespace-nowrap text-right font-mono text-xs tabular-nums">
                            {row.payroll_impact_hours != null
                              ? Number(row.payroll_impact_hours).toFixed(2)
                              : '0.00'}
                          </td>
                          <td style={{ minWidth: 110 }} className="px-3 py-2 whitespace-nowrap text-center text-xs">{row.overtime_status ?? '—'}</td>
                        </tr>
                      )
                    })
                  )}
                </tbody>
              </table>
            </div>
          </CardContent>
        </Card>
      </div>
    </div>
  )
}

