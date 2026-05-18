import { useEffect, useMemo, useState } from 'react'
import { useLocation } from 'react-router-dom'
import { useQuery, keepPreviousData } from '@tanstack/react-query'
import { pdf } from '@react-pdf/renderer'
import { exportRowsToXlsx } from '@/lib/excelExport'
import { FileDown, FileText, Filter, RefreshCw, Table2 } from 'lucide-react'
import { Card, CardContent, CardDescription, CardHeader, CardTitle } from '@/components/ui/card'
import { Avatar, AvatarFallback, AvatarImage } from '@/components/ui/avatar'
import { Button } from '@/components/ui/button'
import { Input } from '@/components/ui/input'
import { Select, SelectContent, SelectItem, SelectTrigger, SelectValue } from '@/components/ui/select'
import {
  getAdminReportsDetailed,
  getEmployeeReportsDetailed,
  getEmployees,
  fetchAllAdminReportsDetailedRows,
  fetchAllEmployeeReportsDetailedRows,
  profileImageUrl,
  REPORTS_AND_ATTENDANCE_PAGE_SIZE,
} from '@/api'
import { formatEmploymentStatusForViewer } from '@/lib/employmentStatus'
import ReportPdfDocument from '@/components/reports/ReportPdfDocument'
import { AttendanceStatusBadge } from '@/components/AttendanceStatusBadge'
import { TableBodySkeleton } from '@/components/skeletons'
import { useAuth } from '@/contexts/AuthContext'
import { isAdminHrUser } from '@/lib/hrRoutes'
import { displayAttendanceTime, formatDayName } from '@/components/attendance/attendanceRecordUtils'

export { AttendanceStatusBadge }

const DETAILED_REPORT_TITLE = 'Detailed Attendance Report'
const DETAILED_SHEET_NAME = 'Detailed Report'

/** Default “from” date: last N days including today — smaller range = faster first load. */
function defaultReportFromDateIso(daysBack = 13) {
  const d = new Date()
  d.setDate(d.getDate() - daysBack)
  const year = d.getFullYear()
  const month = String(d.getMonth() + 1).padStart(2, '0')
  const day = String(d.getDate()).padStart(2, '0')
  return `${year}-${month}-${day}`
}

function todayIso() {
  const d = new Date()
  const year = d.getFullYear()
  const month = String(d.getMonth() + 1).padStart(2, '0')
  const day = String(d.getDate()).padStart(2, '0')
  return `${year}-${month}-${day}`
}

function formatNumber(value) {
  if (value == null) return '0'
  return Number(value).toLocaleString()
}

function reportClockTime(row, key) {
  const formattedKey = key === 'time_in' ? 'formatted_time_in' : key === 'time_out' ? 'formatted_time_out' : null
  return displayAttendanceTime(row?.[key], formattedKey ? row?.[formattedKey] : undefined) || '—'
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

function formatDetailedReportOvertimeStatus(row) {
  const renderedMinutes = Number(row?.overtime_minutes ?? 0)
  const renderedHours = renderedMinutes > 0 ? renderedMinutes / 60 : 0
  const approvedHoursRaw = Number(row?.approved_overtime_hours ?? 0)
  const approvedHours = Number.isFinite(approvedHoursRaw) ? approvedHoursRaw : 0
  const overtimeStatus = row?.overtime_status ? String(row.overtime_status).toLowerCase() : null
  const pendingHours =
    overtimeStatus === 'pending' ? Number(row?.overtime_hours_requested ?? 0) || 0 : 0

  if (renderedHours <= 0 && approvedHours <= 0 && pendingHours <= 0) {
    return row?.overtime_status || '—'
  }

  if (!row?.overtime_filed) return 'Not filed'
  if (overtimeStatus === 'approved') return 'Filed (approved)'
  if (overtimeStatus === 'pending') return 'Filed (pending)'
  if (overtimeStatus === 'rejected') return 'Filed (rejected)'
  return 'Filed'
}

function isWeekendDate(value) {
  if (!value) return false
  const [year, month, day] = String(value).split('-').map(Number)
  if (!year || !month || !day) return false
  const d = new Date(year, month - 1, day)
  const dayOfWeek = d.getDay()
  return dayOfWeek === 0 || dayOfWeek === 6
}

function dedupeDetailedReportRows(rows, employeeId, employeesOptions) {
  let list = Array.isArray(rows) ? [...rows] : []
  if (employeeId !== 'all') {
    const selected = employeesOptions.find((o) => String(o.id) === String(employeeId))
    const matchByName = selected?.name?.trim()
    const matchById = Number(employeeId)
    list = list.filter((row) => {
      if (matchByName && (row.employee_name || '').trim() === matchByName) return true
      return !Number.isNaN(matchById) && Number(row.employee_id ?? row.id) === matchById
    })
  }
  const seen = new Set()
  return list.filter((row) => {
    const key = `${row.employee_id ?? row.id}|${row.date || ''}`
    if (seen.has(key)) return false
    seen.add(key)
    return true
  })
}

export default function AdminReports() {
  const [fromDate, setFromDate] = useState(() => defaultReportFromDateIso(13))
  const [toDate, setToDate] = useState(() => todayIso())
  const [companyId, setCompanyId] = useState('all')
  const [employeeId, setEmployeeId] = useState('all')
  const [includeDeactivated, setIncludeDeactivated] = useState(false)
  const [filterEmployees, setFilterEmployees] = useState([])
  const [data, setData] = useState({ from_date: '', to_date: '' })
  const [reportError, setReportError] = useState(null)
  const [search, setSearch] = useState('')
  const [detailedPage, setDetailedPage] = useState(1)
  const [debouncedDetailedSearch, setDebouncedDetailedSearch] = useState('')
  const [exportingPdf, setExportingPdf] = useState(false)
  const [exportingExcel, setExportingExcel] = useState(false)
  const pageSize = REPORTS_AND_ATTENDANCE_PAGE_SIZE

  const { user } = useAuth()
  const location = useLocation()
  const isEmployeeSelfReport = location.pathname.startsWith('/employee/reports')
  const viewerIsAdminHr = isAdminHrUser(user)
  const showPayrollReports = viewerIsAdminHr || isEmployeeSelfReport

  function effectiveDateRange() {
    const from = fromDate || defaultReportFromDateIso(13)
    const to = toDate || todayIso()
    return { from, to }
  }

  const effectiveRange = useMemo(() => effectiveDateRange(), [fromDate, toDate])
  const reportFilters = useMemo(
    () => ({
      from_date: effectiveRange.from,
      to_date: effectiveRange.to,
      company_id: companyId !== 'all' ? Number(companyId) : undefined,
      employee_id: employeeId !== 'all' ? Number(employeeId) : undefined,
      include_deactivated: includeDeactivated || undefined,
    }),
    [effectiveRange, companyId, employeeId, includeDeactivated],
  )

  useEffect(() => {
    const t = setTimeout(() => setDebouncedDetailedSearch(search.trim()), 300)
    return () => clearTimeout(t)
  }, [search])

  useEffect(() => {
    setDetailedPage(1)
  }, [fromDate, toDate, companyId, employeeId, debouncedDetailedSearch, includeDeactivated])

  const detailedFetchParams = useMemo(
    () => ({
      ...reportFilters,
      page: detailedPage,
      search: debouncedDetailedSearch || undefined,
    }),
    [reportFilters, detailedPage, debouncedDetailedSearch],
  )

  const detailedQuery = useQuery({
    queryKey: ['reports-detailed', isEmployeeSelfReport, detailedFetchParams],
    placeholderData: keepPreviousData,
    queryFn: async ({ signal }) => {
      const fetchDetailed = isEmployeeSelfReport ? getEmployeeReportsDetailed : getAdminReportsDetailed
      return fetchDetailed(detailedFetchParams, { signal })
    },
  })

  const filterEmployeesQuery = useQuery({
    queryKey: ['reports-filter-employees', isEmployeeSelfReport, includeDeactivated],
    enabled: !isEmployeeSelfReport,
    queryFn: () => getEmployees({ per_page: 200, active_filter: includeDeactivated ? 'all' : 'active' }),
  })

  useEffect(() => {
    if (isEmployeeSelfReport) {
      setFilterEmployees([])
      return undefined
    }
    const list = Array.isArray(filterEmployeesQuery.data?.employees) ? filterEmployeesQuery.data.employees : []
    const active = includeDeactivated ? list : list.filter((e) => e.is_active !== false)
    setFilterEmployees(active)
  }, [isEmployeeSelfReport, filterEmployeesQuery.data, includeDeactivated])

  useEffect(() => {
    const d = detailedQuery.data
    if (!d) return
    setData((prev) => ({
      ...prev,
      from_date: d.from_date ?? prev.from_date,
      to_date: d.to_date ?? prev.to_date,
    }))
  }, [detailedQuery.data])

  useEffect(() => {
    if (detailedQuery.error) {
      setReportError(detailedQuery.error?.message || 'Failed to load detailed report')
    } else if (detailedQuery.data) {
      setReportError(null)
    }
  }, [detailedQuery.error, detailedQuery.data])

  useEffect(() => {
    const cp = detailedQuery.data?.meta?.current_page
    if (cp == null) return
    const n = Number(cp)
    if (!Number.isFinite(n) || n < 1) return
    const lp = Math.max(1, Number(detailedQuery.data?.meta?.last_page ?? 1))
    const clamped = Math.min(n, lp)
    if (clamped !== detailedPage) setDetailedPage(clamped)
  }, [detailedQuery.data?.meta?.current_page, detailedQuery.data?.meta?.last_page, detailedPage])

  const companiesOptions = useMemo(() => {
    const map = new Map()
    filterEmployees.forEach((e) => {
      if (e.company_id && e.company_name) {
        map.set(String(e.company_id), e.company_name)
      }
    })
    return Array.from(map.entries())
      .map(([id, name]) => ({ id, name }))
      .sort((a, b) => a.name.localeCompare(b.name))
  }, [filterEmployees])

  const employeesOptions = useMemo(() => {
    const source =
      companyId === 'all'
        ? filterEmployees
        : filterEmployees.filter((e) => String(e.company_id ?? '') === String(companyId))
    return [...source]
      .map((e) => ({ id: e.id, name: e.name || '' }))
      .filter((e) => e.id != null && e.name !== undefined)
      .sort((a, b) => String(a.name).localeCompare(String(b.name)))
  }, [filterEmployees, companyId])

  const periodLabel =
    data.from_date && data.to_date && data.from_date !== data.to_date
      ? `${data.from_date} to ${data.to_date}`
      : data.from_date || ''

  /** Detailed report columns only (single report view). */
  function currentColumns() {
    const emp = (row) =>
      formatEmploymentStatusForViewer(row.employment_status, row.employment_status_label, viewerIsAdminHr)
    const num = (align = 'right') => ({ minW: 90, align })
    const txt = (minW = 100, align = 'left') => ({ minW, align })
    const detailedStatusCol = {
      label: 'Status',
      accessor: (row) => {
        if (row.presence_label != null && String(row.presence_label).trim() !== '') {
          return row.presence_label
        }
        return row.status === 'undertime'
          ? row.undertime_filing_status === 'approved' ||
            (row.leave_type === 'undertime' && row.leave_status === 'approved')
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
      },
      minW: 100,
      align: 'center',
    }
    const leaveTypeCol = { label: 'Leave Type', accessor: (row) => formatLeaveType(row.leave_type), ...txt(110) }
    const leaveStatusCol = { label: 'Leave Status', accessor: (row) => row.leave_status || '—', minW: 100, align: 'center' }
    const leaveDurationCol = {
      label: 'Leave Duration',
      accessor: (row) => {
        if (row.leave_type === 'undertime' && row.leave_status === 'approved' && row.undertime_minutes != null) {
          const m = Number(row.undertime_minutes) || 0
          const h = m / 60
          const r = Math.round(h * 100) / 100
          return `${Number.isInteger(r) ? r.toFixed(0) : r.toFixed(2)} hours`
        }
        return row.leave_duration_days != null ? `${row.leave_duration_days} day(s)` : '—'
      },
      ...num(),
    }
    const overtimeStatusCol = {
      label: 'Overtime Status',
      accessor: (row) => formatDetailedReportOvertimeStatus(row),
      minW: 280,
      align: 'left',
    }

    const otHoursAcc = (row, key) => {
      const v = row[key]
      if (v == null || v === '') return '—'
      const n = Number(v)
      if (Number.isNaN(n)) return '—'
      return n.toFixed(2)
    }

    const detailedCoreNonPayroll = [
      { label: 'Employee', accessor: 'employee_name', ...txt(140) },
      { label: 'Company', accessor: (row) => row.company_name || '—', ...txt(140) },
      { label: 'Department', accessor: (row) => row.department || 'No Department Assigned', ...txt(130) },
      { label: 'Employment status', accessor: emp, ...txt(130) },
      { label: 'Date', accessor: 'date', ...txt(100) },
      { label: 'Day', accessor: (row) => formatDayName(row.date, row.day_name), ...txt(100) },
      { label: 'Schedule', accessor: (row) => row.schedule || '—', ...txt(110) },
      { label: 'Time In', accessor: (row) => reportClockTime(row, 'time_in'), minW: 90, align: 'center' },
      { label: 'Time Out', accessor: (row) => reportClockTime(row, 'time_out'), minW: 90, align: 'center' },
      { label: 'Total Hours', accessor: (row) => (row.total_hours != null ? row.total_hours.toFixed(2) : '—'), ...num() },
      { label: 'Late (min)', accessor: (row) => (row.late_minutes != null ? String(row.late_minutes) : '0'), ...num() },
      {
        label: 'Undertime (min)',
        accessor: (row) =>
          row.undertime_minutes != null && row.undertime_minutes !== '' ? String(row.undertime_minutes) : '—',
        ...num(),
      },
      { label: 'Overtime (min)', accessor: (row) => (row.overtime_minutes != null ? String(row.overtime_minutes) : '—'), ...num() },
      {
        label: 'Unapproved OT (hrs)',
        accessor: (row) => otHoursAcc(row, 'unapproved_overtime_hours'),
        minW: 110,
        align: 'right',
      },
      {
        label: 'Approved OT (hrs)',
        accessor: (row) => otHoursAcc(row, 'approved_overtime_hours'),
        minW: 110,
        align: 'right',
      },
    ]

    const detailedCorePayroll = [
      { label: 'Employee', accessor: 'employee_name', ...txt(140) },
      { label: 'Company', accessor: (row) => row.company_name || '—', ...txt(140) },
      { label: 'Department', accessor: (row) => row.department || 'No Department Assigned', ...txt(130) },
      { label: 'Employment status', accessor: emp, ...txt(130) },
      { label: 'Hire date', accessor: (row) => row.hire_date || '—', ...txt(100) },
      { label: 'Date', accessor: 'date', ...txt(100) },
      { label: 'Day', accessor: (row) => formatDayName(row.date, row.day_name), ...txt(100) },
      { label: 'Schedule', accessor: (row) => row.schedule || '—', ...txt(110) },
      { label: 'Time In', accessor: (row) => reportClockTime(row, 'time_in'), minW: 90, align: 'center' },
      { label: 'Time Out', accessor: (row) => reportClockTime(row, 'time_out'), minW: 90, align: 'center' },
      {
        label: 'Early Out Time',
        accessor: (row) =>
          row.early_out_time
            ? reportClockTime(row, 'early_out_time')
            : row.status === 'undertime' && row.time_out
              ? reportClockTime(row, 'time_out')
              : '—',
        minW: 100,
        align: 'center',
      },
      { label: 'Total Hours', accessor: (row) => (row.total_hours != null ? row.total_hours.toFixed(2) : '—'), ...num() },
      { label: 'Late (min)', accessor: (row) => (row.late_minutes != null ? String(row.late_minutes) : '0'), ...num() },
      {
        label: 'Undertime (min)',
        accessor: (row) =>
          row.undertime_minutes != null && row.undertime_minutes !== '' ? String(row.undertime_minutes) : '—',
        ...num(),
      },
      { label: 'Overtime (min)', accessor: (row) => (row.overtime_minutes != null ? String(row.overtime_minutes) : '—'), ...num() },
      {
        label: 'Unapproved OT (hrs)',
        accessor: (row) => otHoursAcc(row, 'unapproved_overtime_hours'),
        minW: 110,
        align: 'right',
      },
      {
        label: 'Approved OT (hrs)',
        accessor: (row) => otHoursAcc(row, 'approved_overtime_hours'),
        minW: 110,
        align: 'right',
      },
      { label: 'ND hrs', accessor: (row) => (row.night_hours != null ? Number(row.night_hours).toFixed(2) : '—'), minW: 70, align: 'right' },
    ]

    if (!showPayrollReports) {
      return [
        ...detailedCoreNonPayroll,
        detailedStatusCol,
        leaveTypeCol,
        leaveStatusCol,
        leaveDurationCol,
        overtimeStatusCol,
      ]
    }

    return [
      ...detailedCorePayroll,
      { label: 'ND pay (₱)', accessor: (row) => (row.night_differential_pay != null ? formatNumber(row.night_differential_pay) : '—'), minW: 100, align: 'right' },
      { label: 'OT pay (₱)', accessor: (row) => (row.overtime_pay != null ? formatNumber(row.overtime_pay) : '—'), minW: 100, align: 'right' },
      { label: 'Total premium (₱)', accessor: (row) => (row.total_premium_pay != null ? formatNumber(row.total_premium_pay) : '—'), minW: 120, align: 'right' },
      { label: 'Work Condition', accessor: (row) => row.work_condition || '—', ...txt(140) },
      { label: 'Pay Rule', accessor: (row) => row.pay_rule || '—', ...txt(110) },
      { label: 'Multiplier', accessor: (row) => row.multiplier || '—', minW: 110, align: 'center' },
      detailedStatusCol,
      leaveTypeCol,
      leaveStatusCol,
      leaveDurationCol,
      { label: 'Payroll Impact (hrs)', accessor: (row) => (row.payroll_impact_hours != null ? Number(row.payroll_impact_hours).toFixed(2) : '0.00'), minW: 110, align: 'right' },
      overtimeStatusCol,
    ]
  }

  const cols = useMemo(() => currentColumns(), [showPayrollReports, viewerIsAdminHr])

  const rowsForTable = useMemo(() => {
    let rows = Array.isArray(detailedQuery.data?.rows) ? detailedQuery.data.rows : []
    rows = dedupeDetailedReportRows(rows, employeeId, employeesOptions)
    return rows
  }, [detailedQuery.data?.rows, employeeId, employeesOptions])

  const filteredRows = rowsForTable

  const detailedReportMeta = detailedQuery.data?.meta
  const pageCountForUi = Math.max(1, Number(detailedReportMeta?.last_page ?? 1))
  const paginationCurrentPage = Math.min(detailedPage, pageCountForUi)
  const tableRowsForRender = filteredRows

  const summaryKpi = useMemo(() => {
    const totalCount = Number(detailedReportMeta?.total ?? filteredRows.length)
    const totalHoursPage = filteredRows.reduce((sum, r) => sum + (Number(r.total_hours) || 0), 0)
    return {
      primary: { label: 'Total records (all pages)', value: totalCount },
      secondary: [
        { label: 'Total hours (this page)', value: totalHoursPage.toFixed(2) },
        { label: 'Rows per page', value: pageSize },
      ],
    }
  }, [detailedReportMeta?.total, filteredRows, pageSize])

  function buildFiltersSummary() {
    const parts = []
    if (companyId !== 'all') {
      const co = companiesOptions.find((c) => String(c.id) === String(companyId))
      parts.push(`Company: ${co?.name || companyId}`)
    }
    if (employeeId !== 'all') {
      const emp = employeesOptions.find((e) => String(e.id) === String(employeeId))
      parts.push(`Employee: ${emp?.name || employeeId}`)
    }
    if (!parts.length) return 'All'
    return parts.join(' | ')
  }

  async function handleExportPdf() {
    const period = periodLabel || `${fromDate} to ${toDate}`
    const generatedAt = new Date().toLocaleString()
    const filtersSummary = buildFiltersSummary()

    setExportingPdf(true)
    try {
      const base = { ...reportFilters, search: debouncedDetailedSearch || undefined }
      const raw = isEmployeeSelfReport
        ? await fetchAllEmployeeReportsDetailedRows(base)
        : await fetchAllAdminReportsDetailedRows(base)
      const rows = dedupeDetailedReportRows(raw, employeeId, employeesOptions)

      const exportTotalHours = rows.reduce((sum, r) => sum + (Number(r.total_hours) || 0), 0)
      const exportTotalAbsences = rows.filter((r) => r.status === 'absent' || r.status === 'leave').length
      const exportTotalLates = rows.filter((r) => r.status === 'late').length
      const exportTotalOvertime = rows.reduce((sum, r) => sum + (Number(r.overtime_minutes) || 0), 0) / 60

      const doc = (
        <ReportPdfDocument
          title={DETAILED_REPORT_TITLE}
          period={period}
          rows={rows}
          columns={cols}
          generatedAt={generatedAt}
          filtersSummary={filtersSummary}
          subtitle="Detailed per-day attendance records"
          orientation="landscape"
          totalHoursRendered={exportTotalHours.toFixed(2)}
          totalAbsences={exportTotalAbsences}
          totalLates={exportTotalLates}
          totalOvertime={exportTotalOvertime.toFixed(2)}
        />
      )
      const blob = await pdf(doc).toBlob()
      const url = URL.createObjectURL(blob)
      const a = document.createElement('a')
      a.href = url
      a.download = `report-detailed-${data.from_date || fromDate}-${data.to_date || toDate}.pdf`
      document.body.appendChild(a)
      a.click()
      document.body.removeChild(a)
      URL.revokeObjectURL(url)
    } finally {
      setExportingPdf(false)
    }
  }

  async function handleExportExcel() {

    setExportingExcel(true)
    try {
      const base = { ...reportFilters, search: debouncedDetailedSearch || undefined }
      const raw = isEmployeeSelfReport
        ? await fetchAllEmployeeReportsDetailedRows(base)
        : await fetchAllAdminReportsDetailedRows(base)
      const rows = dedupeDetailedReportRows(raw, employeeId, employeesOptions)

      const exportTotalHours = rows.reduce((sum, r) => sum + (Number(r.total_hours) || 0), 0)
      const exportTotalAbsences = rows.filter((r) => r.status === 'absent' || r.status === 'leave').length

      const colCount = cols.length
      const pad = (n) => Array(n).fill('')
      const headerRow = cols.map((c) => c.label)
      const dataRows = rows.map((row) =>
        cols.map((c) => {
          const rawCell = typeof c.accessor === 'function' ? c.accessor(row) : row[c.accessor]
          return rawCell != null ? rawCell : ''
        }),
      )
      const summaryRowsBottom = [
        [...pad(colCount)],
        [...pad(Math.max(0, colCount - 2)), 'Summary', ''],
        [...pad(Math.max(0, colCount - 2)), 'Total Hours Rendered', exportTotalHours.toFixed(2)],
        [...pad(Math.max(0, colCount - 2)), 'Total Absences', exportTotalAbsences],
      ]
      const filename = `report-detailed-${data.from_date || fromDate}-${data.to_date || toDate}.xlsx`
      await exportRowsToXlsx(headerRow, [...dataRows, ...summaryRowsBottom], filename, DETAILED_SHEET_NAME.slice(0, 31))
    } finally {
      setExportingExcel(false)
    }
  }

  const hasRows = Number(detailedReportMeta?.total ?? 0) > 0
  const showTableSkeleton = detailedQuery.isLoading

  return (
    <div className="space-y-6">
      <div className="flex flex-col gap-2 @md:flex-row @md:items-center @md:justify-between">
        <div>
          <h1 className="hr-page-title">Reports</h1>
          <p className="text-sm text-muted-foreground">
            {isEmployeeSelfReport
              ? 'Detailed per-day attendance, overtime, leave, and payroll columns for your account.'
              : 'Detailed per-day attendance report with filters by company and employee.'}{' '}
            <span className="text-muted-foreground/80">
              (Default period is the last 14 days for quicker loads—widen the range when you need a full month.)
            </span>
          </p>
        </div>
      </div>

      <Card className="border border-primary/15 bg-card shadow-sm">
        <CardHeader className="border-b border-primary/10 bg-primary/5 px-4 py-3">
          <CardTitle className="text-sm font-semibold">Filters</CardTitle>
          <CardDescription className="text-xs">
            {isEmployeeSelfReport
              ? 'Choose a date range for your records.'
              : 'Choose a date range and narrow down by company or employee.'}
          </CardDescription>
        </CardHeader>
        <CardContent className="flex flex-col gap-3 bg-primary/5 px-4 py-3 @md:flex-row @md:items-end @md:justify-between">
          <div
            className={`grid w-full grid-cols-1 gap-3 ${isEmployeeSelfReport ? '@md:max-w-md @md:grid-cols-2' : '@md:max-w-3xl @md:grid-cols-4'}`}
          >
            <div className="space-y-1.5">
              <span className="text-xs font-medium text-muted-foreground">From</span>
              <Input
                type="date"
                value={fromDate}
                onChange={(e) => setFromDate(e.target.value)}
                className="h-9 text-sm"
              />
            </div>
            <div className="space-y-1.5">
              <span className="text-xs font-medium text-muted-foreground">To</span>
              <Input
                type="date"
                value={toDate}
                onChange={(e) => setToDate(e.target.value)}
                className="h-9 text-sm"
              />
            </div>
            {!isEmployeeSelfReport && (
              <>
                <div className="space-y-1.5">
                  <span className="text-xs font-medium text-muted-foreground">Company</span>
                  <Select
                    value={companyId}
                    onValueChange={(v) => {
                      setCompanyId(v)
                      setEmployeeId('all')
                    }}
                  >
                    <SelectTrigger className="h-9 text-sm">
                      <SelectValue placeholder="All companies" />
                    </SelectTrigger>
                    <SelectContent>
                      <SelectItem value="all">All companies</SelectItem>
                      {companiesOptions.map((c) => (
                        <SelectItem key={c.id} value={c.id}>
                          {c.name}
                        </SelectItem>
                      ))}
                    </SelectContent>
                  </Select>
                </div>
                <div className="space-y-1.5">
                  <span className="text-xs font-medium text-muted-foreground">Employee</span>
                  <Select value={employeeId} onValueChange={setEmployeeId}>
                    <SelectTrigger className="h-9 text-sm">
                      <SelectValue placeholder="All employees" />
                    </SelectTrigger>
                    <SelectContent>
                      <SelectItem value="all">All employees</SelectItem>
                      {employeesOptions.map((e) => (
                        <SelectItem key={e.id} value={String(e.id)}>
                          {e.name}
                        </SelectItem>
                      ))}
                    </SelectContent>
                  </Select>
                </div>
                <label className="flex min-h-9 items-center gap-2 self-end text-sm text-muted-foreground">
                  <input
                    type="checkbox"
                    checked={includeDeactivated}
                    onChange={(event) => {
                      setIncludeDeactivated(event.target.checked)
                      setEmployeeId('all')
                    }}
                    className="size-4 rounded border-border"
                  />
                  Include deactivated employees
                </label>
              </>
            )}
          </div>
          <div className="flex flex-wrap gap-2 @md:justify-end">
            <Button
              type="button"
              variant="default"
              size="sm"
              className="gap-1.5 shadow-sm"
              onClick={() => {
                void detailedQuery.refetch()
              }}
            >
              <Filter className="size-3.5" />
              Apply
            </Button>
            <Button
              type="button"
              variant="ghost"
              size="sm"
              className="gap-1.5 text-muted-foreground hover:bg-primary/5"
              onClick={() => {
                void detailedQuery.refetch()
              }}
            >
              <RefreshCw className="size-3.5" />
              Refresh
            </Button>
          </div>
        </CardContent>
      </Card>

      <div className="space-y-3">
        <div className="flex items-center gap-2 text-muted-foreground">
          <Table2 className="size-5 shrink-0" aria-hidden />
          <h3 className="text-base font-semibold text-foreground">Detailed report</h3>
        </div>
        <Card className="border border-border/60 bg-card/95 shadow-sm rounded-xl">
          <CardHeader className="flex flex-col gap-3 border-b border-border/40 bg-muted/40 py-4 @md:flex-row @md:items-center @md:justify-between">
            <div>
              <CardTitle className="text-base font-semibold @md:text-lg">{DETAILED_REPORT_TITLE}</CardTitle>
              <CardDescription className="text-xs @md:text-sm">
                Period: {periodLabel || `${fromDate} to ${toDate}`}
              </CardDescription>
            </div>
            <div className="flex flex-wrap items-center gap-2">
              <Button
                type="button"
                variant="outline"
                size="sm"
                className="gap-1.5"
                onClick={handleExportPdf}
                disabled={!hasRows || exportingPdf}
              >
                {exportingPdf ? (
                  <RefreshCw className="size-3.5 animate-spin" />
                ) : (
                  <FileText className="size-3.5" />
                )}
                Export PDF
              </Button>
              <Button
                type="button"
                variant="outline"
                size="sm"
                className="gap-1.5"
                onClick={handleExportExcel}
                disabled={!hasRows || exportingExcel}
              >
                {exportingExcel ? (
                  <RefreshCw className="size-3.5 animate-spin" />
                ) : (
                  <FileDown className="size-3.5" />
                )}
                Export Excel
              </Button>
            </div>
          </CardHeader>
          <CardContent className="pt-4">
            <div className="mb-4 flex flex-col gap-3 @md:flex-row @md:items-center @md:justify-between">
              <div className="grid grid-cols-1 gap-2 @md:grid-cols-3 @md:gap-4">
                <div className="rounded-md border border-border/40 bg-muted/20 px-3 py-2">
                  <p className="text-[10px] font-medium uppercase text-muted-foreground">
                    {summaryKpi.primary.label}
                  </p>
                  <p className="text-lg font-semibold">{formatNumber(summaryKpi.primary.value)}</p>
                </div>
                {summaryKpi.secondary.map((item) => (
                  <div
                    key={item.label}
                    className="rounded-md border border-border/40 bg-muted/10 px-3 py-2"
                  >
                    <p className="text-[10px] font-medium uppercase text-muted-foreground">{item.label}</p>
                    <p className="text-sm font-semibold">
                      {typeof item.value === 'number' ? formatNumber(item.value) : item.value}
                    </p>
                  </div>
                ))}
              </div>
              <div className="w-full @md:w-64">
                <Input
                  type="text"
                  value={search}
                  onChange={(e) => setSearch(e.target.value)}
                  placeholder={
                    isEmployeeSelfReport
                      ? 'Search rows (name, department, date)…'
                      : 'Search employee, company, or department…'
                  }
                  className="h-9 text-sm"
                />
              </div>
            </div>

            <div className="max-h-[70vh] overflow-auto rounded-xl border border-border/40 bg-background/80">
              <table className="min-w-max w-full text-xs @md:text-sm border-0">
                <thead className="sticky top-0 z-20 bg-card dark:bg-card">
                  <tr className="border-b border-border/40 bg-card dark:bg-card">
                    {cols.map((c) => {
                      const align = c.align || 'left'
                      const alignClass =
                        align === 'right' ? 'text-right' : align === 'center' ? 'text-center' : 'text-left'
                      return (
                        <th
                          key={c.label}
                          style={c.minW ? { minWidth: `${c.minW}px` } : undefined}
                          className={`sticky top-0 z-20 bg-card px-4 py-3 whitespace-nowrap ${alignClass} text-[10px] font-semibold uppercase tracking-wide text-muted-foreground shadow-[inset_0_-1px_0_var(--border)] dark:bg-card @md:text-xs`}
                        >
                          {c.label}
                        </th>
                      )
                    })}
                  </tr>
                </thead>
                <tbody>
                  {showTableSkeleton ? (
                    <TableBodySkeleton rows={8} cols={Math.min(cols.length, 6)} />
                  ) : !hasRows ? (
                    <tr>
                      <td
                        colSpan={cols.length}
                        className="px-4 py-8 text-center text-sm text-muted-foreground"
                      >
                        {reportError ? reportError : 'No records for this period and filters.'}
                      </td>
                    </tr>
                  ) : (
                    tableRowsForRender.map((row, rowIndex) => {
                      const employeeName =
                        row.employee_name ?? (typeof row.name === 'string' ? row.name : null)
                      const initials =
                        (employeeName || '?')
                          .trim()
                          .split(/\s+/)
                          .map((n) => n[0])
                          .join('')
                          .toUpperCase()
                          .slice(0, 2) || '?'

                      const isWeekend = row.date ? isWeekendDate(row.date) : false
                      return (
                        <tr
                          key={row.date ? `${row.employee_id ?? row.id}-${row.date}` : (row.employee_id ?? row.id ?? rowIndex)}
                          className={`border-b border-border/20 hover:bg-muted/40 transition-colors ${
                            isWeekend ? 'bg-muted/30 text-muted-foreground' : ''
                          }`}
                        >
                          {cols.map((c) => {
                            const raw =
                              typeof c.accessor === 'function' ? c.accessor(row) : row[c.accessor]
                            const value =
                              raw != null && raw !== ''
                                ? typeof raw === 'number'
                                  ? String(raw)
                                  : String(raw)
                                : '—'
                            const isEmployeeCol = c.label === 'Employee' && (employeeName || raw)
                            const isStatusCol = c.label === 'Status'
                            const showDeptSubLabel = row.department

                            const align = c.align || 'left'
                            const alignClass =
                              align === 'right' ? 'text-right' : align === 'center' ? 'text-center' : 'text-left'
                            const isNumeric = align === 'right'
                            return (
                              <td
                                key={c.label}
                                style={c.minW ? { minWidth: `${c.minW}px` } : undefined}
                                className={`px-4 py-3 align-middle whitespace-nowrap ${alignClass} ${isNumeric ? 'tabular-nums' : ''}`}
                              >
                                {isEmployeeCol ? (
                                  <div className="flex items-center gap-3">
                                    <Avatar className="size-10 shrink-0 rounded-full ring-2 ring-primary/10">
                                      <AvatarImage
                                        src={profileImageUrl(row.profile_image)}
                                        alt=""
                                        className="object-cover"
                                      />
                                      <AvatarFallback className="rounded-full bg-primary/10 text-xs font-semibold text-primary">
                                        {initials}
                                      </AvatarFallback>
                                    </Avatar>
                                    <div className="flex flex-col">
                                      <span className="text-sm font-semibold text-foreground @md:text-[0.95rem]">
                                        {value}
                                      </span>
                                      {showDeptSubLabel && (
                                        <span className="text-[11px] font-medium uppercase tracking-wide text-muted-foreground">
                                          {row.department}
                                        </span>
                                      )}
                                    </div>
                                  </div>
                                ) : isStatusCol ? (
                                  <AttendanceStatusBadge
                                    status={row.status}
                                    label={value}
                                    presenceIssue={row.presence_issue}
                                  />
                                ) : (
                                  <span className="whitespace-nowrap text-xs @md:text-sm">{value}</span>
                                )}
                              </td>
                            )
                          })}
                        </tr>
                      )
                    })
                  )}
                </tbody>
              </table>
              {hasRows && !showTableSkeleton && (
                <div className="flex items-center justify-between border-t border-border/40 px-4 py-3 text-[11px] text-muted-foreground @md:text-xs">
                  <span>
                    Page {paginationCurrentPage} of {pageCountForUi}
                  </span>
                  <div className="flex gap-2">
                    <Button
                      type="button"
                      size="xs"
                      variant="outline"
                      disabled={paginationCurrentPage <= 1}
                      onClick={() => setDetailedPage((p) => Math.max(1, p - 1))}
                    >
                      Previous
                    </Button>
                    <Button
                      type="button"
                      size="xs"
                      variant="outline"
                      disabled={paginationCurrentPage >= pageCountForUi}
                      onClick={() => setDetailedPage((p) => Math.min(pageCountForUi, p + 1))}
                    >
                      Next
                    </Button>
                  </div>
                </div>
              )}
            </div>
          </CardContent>
        </Card>
      </div>
    </div>
  )
}
