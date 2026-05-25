import { useEffect, useMemo, useState } from 'react'
import { useQuery, keepPreviousData } from '@tanstack/react-query'
import { pdf } from '@react-pdf/renderer'
import { useSearchParams } from 'react-router-dom'
import {
  Calendar,
  CalendarDays,
  CheckCircle2,
  Filter,
  RefreshCw,
  Table2,
  FileText,
  UserX,
  AlertCircle,
  Search,
  ChevronDown,
  Download,
} from 'lucide-react'
import { exportRowsToXlsx } from '@/lib/excelExport'
import { useHrBasePath } from '@/contexts/HrAppPathContext'
import { hrPanelPath } from '@/lib/hrRoutes'
import AttendanceCorrections from '@/pages/AttendanceCorrections'
import { AttendanceRecordsDataTable } from '@/components/attendance/AttendanceRecordsDataTable'
import { AttendanceRecordDetailSheet } from '@/components/attendance/AttendanceRecordDetailSheet'
import {
  attendanceRecordRef,
  formatDayName,
  formatScheduleRange,
  tableRenderedHoursLabel,
  tableLateMinutes,
  tableUndertimeMinutes,
  tableOvertimeMinutes,
  minutesCellText,
  resolveAdminStatusLabel,
  tableApprovedOtHours,
  tableActualRenderedOtHours,
  tablePayableOtHours,
  tableOtHoursHrs,
  displayAttendanceTime,
} from '@/components/attendance/attendanceRecordUtils'
import {
  DropdownMenu,
  DropdownMenuContent,
  DropdownMenuItem,
  DropdownMenuTrigger,
} from '@/components/ui/dropdown-menu'
import { Card, CardContent, CardDescription, CardHeader, CardTitle } from '@/components/ui/card'
import { Button } from '@/components/ui/button'
import { Input } from '@/components/ui/input'
import { Select, SelectContent, SelectItem, SelectTrigger, SelectValue } from '@/components/ui/select'
import {
  getAdminAttendance,
  fetchAllAdminAttendanceRows,
  exportAdminAttendance,
  getEmployees,
  profileImageUrl,
  ADMIN_ATTENDANCE_PAGE_SIZE,
  ATTENDANCE_PAGE_SIZE_OPTIONS,
  normalizeAttendancePerPage,
} from '@/api'
import ReportPdfDocument from '@/components/reports/ReportPdfDocument'
import { cn } from '@/lib/utils'
import { useAuth } from '@/contexts/AuthContext'
import { isAdminHrUser } from '@/lib/hrRoutes'

function toCsvCell(value) {
  const s = value == null ? '' : String(value)
  if (s.includes('"') || s.includes(',') || s.includes('\n') || s.includes('\r')) {
    return `"${s.replace(/"/g, '""')}"`
  }
  return s
}

/** Local calendar date YYYY-MM-DD (avoids UTC date mismatch with server timezone). */
function getLocalDateString(d = new Date()) {
  const y = d.getFullYear()
  const m = String(d.getMonth() + 1).padStart(2, '0')
  const day = String(d.getDate()).padStart(2, '0')
  return `${y}-${m}-${day}`
}

const ADMIN_ATTENDANCE_PER_PAGE_KEY = 'hr-admin-attendance-per-page'

function readStoredAttendancePerPage() {
  try {
    return normalizeAttendancePerPage(
      Number(localStorage.getItem(ADMIN_ATTENDANCE_PER_PAGE_KEY)),
      ADMIN_ATTENDANCE_PAGE_SIZE,
    )
  } catch {
    return ADMIN_ATTENDANCE_PAGE_SIZE
  }
}

function paginationWindow(current, last) {
  const total = Math.max(1, Number(last) || 1)
  const active = Math.min(Math.max(1, Number(current) || 1), total)
  if (total <= 6) return Array.from({ length: total }, (_, i) => i + 1)

  const pages = [1]
  const start = Math.max(2, active - 1)
  const end = Math.min(total - 1, active + 1)

  if (start > 2) pages.push('ellipsis-start')
  for (let p = start; p <= end; p += 1) pages.push(p)
  if (end < total - 1) pages.push('ellipsis-end')
  pages.push(total)

  return pages
}

export default function AdminAttendance() {
  const { user } = useAuth()
  const hrBase = useHrBasePath()
  const [searchParams, setSearchParams] = useSearchParams()
  const correctionsTabActive = searchParams.get('tab') === 'corrections'
  const attendanceScope = user?.attendance_scope
  /** Full HR (no org hat): unrestricted org-wide filters. */
  const isUnrestrictedHr = !attendanceScope
  const hrRole = String(user?.hr_role || '').trim()
  const hideCompanyColumn =
    hrRole === 'department_head' ||
    hrRole === 'branch_head' ||
    (hrRole === 'company_head' && (attendanceScope?.company_names?.length ?? 0) === 1)
  const hideDepartmentColumn = hrRole === 'department_head' && (attendanceScope?.department_names?.length ?? 0) === 1
  const showCompanyFilter =
    isUnrestrictedHr ||
    (hrRole === 'company_head' && (attendanceScope?.company_names?.length ?? 0) > 1)
  const showPayrollAttendanceColumns = isAdminHrUser(user)
  const [fromDate, setFromDate] = useState(() => getLocalDateString())
  const [toDate, setToDate] = useState(() => getLocalDateString())
  const [status, setStatus] = useState('all')
  const [premiumType, setPremiumType] = useState('all')
  const [department, setDepartment] = useState('all')
  const [companyFilter, setCompanyFilter] = useState('all')
  const [employeeId, setEmployeeId] = useState('all')
  const [rows, setRows] = useState([])
  const [loading, setLoading] = useState(true)
  const [error, setError] = useState(null)

  const [exportingPdf, setExportingPdf] = useState(false)
  const [detailOpen, setDetailOpen] = useState(false)
  const [detailRow, setDetailRow] = useState(null)
  const [searchQuery, setSearchQuery] = useState('')
  const [scopeSegment, setScopeSegment] = useState('all')
  const [attendancePage, setAttendancePage] = useState(1)
  const [attendancePerPage, setAttendancePerPage] = useState(readStoredAttendancePerPage)
  const [debouncedAttendanceSearch, setDebouncedAttendanceSearch] = useState('')
  const [lastRefresh, setLastRefresh] = useState(() => new Date())
  const [, forceTickUpdate] = useState(0)

  useEffect(() => {
    const timer = setTimeout(() => setDebouncedAttendanceSearch(searchQuery.trim()), 300)
    return () => clearTimeout(timer)
  }, [searchQuery])

  /** Department name sent to API (scoped managers: fixed org; others: dropdown). */
  function resolveDepartmentForApi(overrides = {}) {
    const deptState = overrides.department ?? department
    const scope = user?.attendance_scope
    if (scope?.kind === 'department' && scope.department_names?.length === 1) {
      return scope.department_names[0]
    }
    return deptState !== 'all' ? deptState : undefined
  }

  const rosterEmployeesQuery = useQuery({
    queryKey: ['admin-attendance-roster', user?.id, user?.hr_role, JSON.stringify(user?.attendance_scope || {})],
    queryFn: async () => {
      const scope = user?.attendance_scope
      const params = { per_page: 500 }
      if (scope?.kind === 'department' && scope.department_ids?.length === 1) {
        params.department_id = scope.department_ids[0]
      } else if (scope?.kind === 'branch' && scope.branch_id) {
        params.branch_id = scope.branch_id
      } else if (scope?.kind === 'company' && scope.company_ids?.length === 1) {
        params.company_id = scope.company_ids[0]
      }
      const res = await getEmployees(params)
      const list = res?.employees ?? res ?? []
      return Array.isArray(list) ? list : []
    },
    staleTime: 60_000,
  })

  const rosterEmployees = rosterEmployeesQuery.data ?? []

  useEffect(() => {
    if (!showPayrollAttendanceColumns && premiumType !== 'all') {
      setPremiumType('all')
    }
  }, [showPayrollAttendanceColumns, premiumType])

  const attendanceFilters = useMemo(
    () => ({
      from_date: fromDate,
      to_date: toDate,
      department: resolveDepartmentForApi(),
      employee_id: employeeId !== 'all' ? Number(employeeId) : undefined,
      status: status !== 'all' ? status : undefined,
      premium_type: premiumType !== 'all' ? premiumType : undefined,
      search: debouncedAttendanceSearch || undefined,
      company: showCompanyFilter && companyFilter !== 'all' ? companyFilter : undefined,
      pending_attention: scopeSegment === 'pending' ? true : undefined,
    }),
    [
      fromDate,
      toDate,
      department,
      employeeId,
      status,
      premiumType,
      user?.attendance_scope,
      debouncedAttendanceSearch,
      showCompanyFilter,
      companyFilter,
      scopeSegment,
    ],
  )

  const attendanceParams = useMemo(
    () => ({ ...attendanceFilters, page: attendancePage, per_page: attendancePerPage }),
    [attendanceFilters, attendancePage, attendancePerPage],
  )

  const attendanceQuery = useQuery({
    queryKey: ['admin-attendance', attendanceParams],
    placeholderData: keepPreviousData,
    queryFn: ({ signal }) => getAdminAttendance({ ...attendanceParams, signal }),
    refetchInterval: 15000,
    refetchOnWindowFocus: true,
  })

  async function load() {
    setLoading(true)
    setError(null)
    try {
      const result = await attendanceQuery.refetch()
      const res = result.data || {}
      setRows(res.rows || [])
    } catch (e) {
      setError(e.message)
      setRows([])
    } finally {
      setLoading(false)
      setLastRefresh(new Date())
    }
  }

  useEffect(() => {
    const s = user?.attendance_scope
    if (s?.kind === 'department' && s.department_names?.length === 1) {
      setDepartment(s.department_names[0])
    }
  }, [user?.attendance_scope])

  useEffect(() => {
    if (attendanceQuery.data) {
      setRows(attendanceQuery.data.rows || [])
      setError(null)
      setLoading(false)
      setLastRefresh(new Date())
    } else if (attendanceQuery.error) {
      setRows([])
      setError(attendanceQuery.error.message || 'Failed to load attendance')
      setLoading(false)
    } else if (attendanceQuery.isLoading && !attendanceQuery.isPlaceholderData) {
      setLoading(true)
    }
  }, [attendanceQuery.data, attendanceQuery.error, attendanceQuery.isLoading, attendanceQuery.isPlaceholderData])

  useEffect(() => {
    const cp = attendanceQuery.data?.meta?.current_page
    if (cp == null) return
    const n = Number(cp)
    if (!Number.isFinite(n) || n < 1) return
    const lp = Math.max(1, Number(attendanceQuery.data?.meta?.last_page ?? 1))
    const clamped = Math.min(n, lp)
    if (clamped !== attendancePage) {
      setAttendancePage(clamped)
    }
  }, [attendanceQuery.data?.meta?.current_page, attendanceQuery.data?.meta?.last_page, attendancePage])

  // Tick every second for "X seconds ago" display
  useEffect(() => {
    const t = setInterval(() => forceTickUpdate((n) => n + 1), 1000)
    return () => clearInterval(t)
  }, [])

  // Auto-refresh handled by React Query refetchInterval.

  useEffect(() => {
    setAttendancePage(1)
  }, [
    fromDate,
    toDate,
    department,
    employeeId,
    status,
    premiumType,
    debouncedAttendanceSearch,
    companyFilter,
    scopeSegment,
    attendancePerPage,
  ])

  function handleAttendancePerPageChange(value) {
    const next = normalizeAttendancePerPage(value, ADMIN_ATTENDANCE_PAGE_SIZE)
    setAttendancePerPage(next)
    setAttendancePage(1)
    try {
      localStorage.setItem(ADMIN_ATTENDANCE_PER_PAGE_KEY, String(next))
    } catch {
      /* ignore */
    }
  }

  // Apply: reload with current filter values (from/to, department, employee, status)
  function applyFilters() {
    load()
  }

  function loadWith(overrides = {}) {
    if (overrides.fromDate !== undefined) setFromDate(overrides.fromDate)
    if (overrides.toDate !== undefined) setToDate(overrides.toDate)
    if (overrides.department !== undefined) setDepartment(overrides.department)
    if (overrides.employeeId !== undefined) setEmployeeId(overrides.employeeId)
    if (overrides.status !== undefined) setStatus(overrides.status)
    if (overrides.premiumType !== undefined) setPremiumType(overrides.premiumType)
    setAttendancePage(1)
  }

  function rosterDepartmentName(e) {
    const nm = typeof e?.department_relation?.name === 'string' ? e.department_relation.name : ''
    return nm || String(e?.department ?? e?.department_name ?? '').trim() || ''
  }

  function rosterCompanyName(e) {
    const fromRel = typeof e?.department_relation?.branch?.company?.name === 'string'
      ? e.department_relation.branch.company.name
      : ''
    if (fromRel) return fromRel
    const co = typeof e?.company?.name === 'string' ? e.company.name : ''
    return co || String(e?.company_name ?? '').trim() || ''
  }

  const departmentsFromRoster = useMemo(() => {
    const set = new Set()
    rosterEmployees.forEach((e) => {
      const d = rosterDepartmentName(e)
      if (d) set.add(d)
    })
    return Array.from(set).sort()
  }, [rosterEmployees])

  const departmentFilterOptions = useMemo(() => {
    if (attendanceScope?.kind === 'department' || attendanceScope?.kind === 'branch') {
      const names = attendanceScope?.department_names
      return Array.isArray(names) ? [...names].sort() : []
    }
    return departmentsFromRoster
  }, [attendanceScope, departmentsFromRoster])

  const departmentSelectAllowsAll =
    isUnrestrictedHr ||
    (attendanceScope?.kind === 'department' && (attendanceScope.department_names?.length ?? 0) > 1) ||
    (attendanceScope?.kind === 'branch' && (attendanceScope.department_names?.length ?? 0) > 1) ||
    attendanceScope?.kind === 'company'

  const companies = useMemo(() => {
    if (attendanceScope?.kind === 'company' && attendanceScope.company_names?.length) {
      return [...attendanceScope.company_names].sort()
    }
    const set = new Set()
    rosterEmployees.forEach((e) => {
      const co = rosterCompanyName(e)
      if (co) set.add(co)
    })
    return Array.from(set).sort()
  }, [rosterEmployees, attendanceScope])

  const employees = useMemo(() => {
    const map = new Map()
    rosterEmployees.forEach((e) => {
      if (e?.id != null && e?.name) {
        map.set(e.id, e.name)
      }
    })
    return Array.from(map.entries()).sort((a, b) => a[1].localeCompare(b[1]))
  }, [rosterEmployees])

  const periodLabel =
    fromDate && toDate && fromDate !== toDate ? `${fromDate} to ${toDate}` : fromDate || ''

  const attendanceListMeta = attendanceQuery.data?.meta
  const rollups = attendanceListMeta?.totals ?? {}

  const presentCount = rollups.present_count ?? 0
  const absentCount = rollups.absent_count ?? 0
  const lateCount = rollups.late_count ?? 0
  const onLeaveCount = rollups.leave_or_halfday_count ?? 0

  const totalHoursRendered =
    typeof rollups.total_hours_rendered === 'number' ? rollups.total_hours_rendered : 0

  const attendanceTotalMatched = Number(attendanceListMeta?.total ?? rows.length ?? 0)
  const attendanceLastPage = Math.max(1, Number(attendanceListMeta?.last_page ?? 1))
  const attendancePerPageResolved = Number(attendanceListMeta?.per_page) || attendancePerPage

  const todayIso = getLocalDateString()
  const dateFilterApplied = fromDate !== todayIso || toDate !== todayIso
  const departmentFilterApplied = department !== 'all'
  const companyFilterApplied = showCompanyFilter && companyFilter !== 'all'
  const employeeFilterApplied = employeeId !== 'all'
  const statusFilterApplied = status !== 'all'
  const premiumFilterApplied = showPayrollAttendanceColumns && premiumType !== 'all'

  const appliedFiltersCount = [
    dateFilterApplied,
    companyFilterApplied,
    departmentFilterApplied,
    employeeFilterApplied,
    statusFilterApplied,
    premiumFilterApplied,
  ].filter(Boolean).length

  const departmentFilterDisabled =
    (attendanceScope?.kind === 'department' && attendanceScope.department_names?.length === 1) ||
    (attendanceScope?.kind === 'branch' && attendanceScope.department_names?.length === 1)

  const selectedEmployeeName =
    employeeId === 'all'
      ? null
      : (employees.find(([id]) => String(id) === String(employeeId)) || [null, null])[1]

  function attendanceTableExportSchema(exportRows) {
    const columns = [
      // Export parity contract: always include the same attendance data columns in this exact order.
      { key: 'employee', label: 'Employee', accessor: (r) => r.employee_name || '' },
      { key: 'company', label: 'Company', accessor: (r) => r.company_name || '—' },
      { key: 'department', label: 'Department', accessor: (r) => r.department || '—' },
      { key: 'date', label: 'Date', accessor: (r) => r.date || '' },
      { key: 'day_name', label: 'Day', accessor: (r) => formatDayName(r.date, r.day_name) },
      { key: 'schedule', label: 'Schedule', accessor: (r) => formatScheduleRange(r) },
      { key: 'time_in', label: 'Time in', accessor: (r) => displayAttendanceTime(r.time_in, r.formatted_time_in) || '—' },
      { key: 'time_out', label: 'Time out', accessor: (r) => displayAttendanceTime(r.time_out, r.formatted_time_out) || '—' },
      { key: 'total_hours', label: 'Total hours', accessor: (r) => tableRenderedHoursLabel(r) },
      { key: 'late_min', label: 'Late (min)', accessor: (r) => minutesCellText(tableLateMinutes(r)) },
      { key: 'undertime_min', label: 'Undertime (min)', accessor: (r) => minutesCellText(tableUndertimeMinutes(r)) },
      { key: 'overtime_min', label: 'Overtime (min)', accessor: (r) => minutesCellText(tableOvertimeMinutes(r)) },
      { key: 'unapproved_ot', label: 'Unapproved OT (hrs)', accessor: (r) => tableOtHoursHrs(r.unapproved_overtime_hours) },
      { key: 'approved_ot', label: 'Approved OT (hrs)', accessor: (r) => tableApprovedOtHours(r) },
      { key: 'actual_rendered_ot', label: 'Actual Rendered OT (hrs)', accessor: (r) => tableActualRenderedOtHours(r) },
      { key: 'payable_ot', label: 'Payable OT (hrs)', accessor: (r) => tablePayableOtHours(r) },
      { key: 'ot_reduction_reason', label: 'OT Reduction Reason', accessor: (r) => r.overtime_reduction_reason || '—' },
      {
        key: 'overtime_status',
        label: 'Overtime Status',
        accessor: (r) => (r.overtime_status ? String(r.overtime_status).replace(/_/g, ' ') : '—'),
      },
      { key: 'payroll_impact', label: 'Payroll Impact (hrs)', accessor: (r) => tableOtHoursHrs(r.payroll_impact_hours) },
      { key: 'night_hours', label: 'ND hrs', accessor: (r) => tableOtHoursHrs(r.night_hours) },
      {
        key: 'night_differential_pay',
        label: 'ND pay',
        accessor: (r) => (r.night_differential_pay != null ? Number(r.night_differential_pay).toFixed(2) : '—'),
      },
      {
        key: 'total_premium_pay',
        label: 'Total premium',
        accessor: (r) => (r.total_premium_pay != null ? Number(r.total_premium_pay).toFixed(2) : '—'),
      },
      { key: 'status', label: 'Status', accessor: (r) => resolveAdminStatusLabel(r) },
    ]

    return {
      headers: columns.map((c) => c.label),
      rowsMatrix: exportRows.map((r) => columns.map((c) => c.accessor(r))),
    }
  }

  function pdfColumns() {
    const base = [
      { label: 'Record ID', accessor: (row) => attendanceRecordRef(row.employee_id, row.date) },
      { label: 'Employee', accessor: 'employee_name' },
      { label: 'Department', accessor: (row) => row.department || 'No Department Assigned' },
      { label: 'Date', accessor: (row) => row.date },
      { label: 'Day', accessor: (row) => formatDayName(row.date, row.day_name) },
      {
        label: 'Scheduled regular (h)',
        accessor: (row) => (row.scheduled_regular_hours != null ? String(row.scheduled_regular_hours) : '—'),
      },
      {
        label: 'Total rendered (h)',
        accessor: (row) =>
          row.total_rendered_hours != null
            ? String(row.total_rendered_hours)
            : row.total_hours != null
              ? String(row.total_hours)
              : '—',
      },
      { label: 'Clock In', accessor: (row) => displayAttendanceTime(row.time_in, row.formatted_time_in) || '—' },
      { label: 'Clock Out', accessor: (row) => displayAttendanceTime(row.time_out, row.formatted_time_out) || '—' },
      {
        label: 'Late Minutes',
        accessor: (row) =>
          row.late_minutes != null ? String(row.late_minutes) : row.late_label || '0',
      },
      {
        label: 'Undertime (min)',
        accessor: (row) => (row.undertime_minutes != null ? String(row.undertime_minutes) : '0'),
      },
      {
        label: 'Overtime (min)',
        accessor: (row) => (row.overtime_minutes != null ? String(row.overtime_minutes) : '0'),
      },
      {
        label: 'Actual Rendered OT (hrs)',
        accessor: (row) => tableActualRenderedOtHours(row),
      },
      {
        label: 'Approved OT (hrs)',
        accessor: (row) => tableApprovedOtHours(row),
      },
      {
        label: 'Payable OT (hrs)',
        accessor: (row) => tablePayableOtHours(row),
      },
      {
        label: 'OT Reduction Reason',
        accessor: (row) => row.overtime_reduction_reason || '—',
      },
      {
        label: 'Unapproved OT (hrs)',
        accessor: (row) => tableOtHoursHrs(row.unapproved_overtime_hours),
      },
      {
        label: 'Overtime status',
        accessor: (row) => (row.overtime_status ? String(row.overtime_status).replace(/_/g, ' ') : '—'),
      },
      {
        label: 'Payroll impact (hrs)',
        accessor: (row) => tableOtHoursHrs(row.payroll_impact_hours),
      },
    ]
    const payroll = [
      {
        label: 'ND Hours',
        accessor: (row) => (row.night_hours != null ? String(row.night_hours) : '—'),
      },
      {
        label: 'Premium',
        accessor: (row) => row.premium_description || row.premium_type || '—',
      },
    ]
    const statusCol = {
      label: 'Status',
      accessor: (row) =>
        row.status === 'late' && row.late_label
          ? row.late_label
          : row.status === 'halfday' && row.late_label
            ? row.late_label
            : row.status === '—'
              ? '—'
              : row.status === 'present' && row.has_approved_overtime
                ? 'Present + OT'
                : row.status,
    }
    return showPayrollAttendanceColumns ? [...base, ...payroll, statusCol] : [...base, statusCol]
  }

  async function handleExportPdf() {
    if (!attendanceTotalMatched) return
    setExportingPdf(true)
    try {
      const cols = pdfColumns()
      const title = 'Attendance Report'
      const period = periodLabel
      const exportRowsFull = await fetchAllAdminAttendanceRows(attendanceFilters)
      const doc = (
        <ReportPdfDocument
          title={title}
          period={period}
          rows={exportRowsFull}
          columns={cols}
          totalHoursRendered={Number.isFinite(totalHoursRendered) ? totalHoursRendered.toFixed(2) : undefined}
          totalAbsences={absentCount}
          totalLates={lateCount}
        />
      )
      const blob = await pdf(doc).toBlob()
      const url = URL.createObjectURL(blob)
      const a = document.createElement('a')
      a.href = url
      a.download = `attendance-${fromDate || ''}-${toDate || ''}.pdf`
      document.body.appendChild(a)
      a.click()
      document.body.removeChild(a)
      URL.revokeObjectURL(url)
    } finally {
      setExportingPdf(false)
    }
  }

  const displayRows = useMemo(() => rows, [rows])

  const secondsAgo = Math.floor((Date.now() - lastRefresh.getTime()) / 1000)
  const pageButtons = paginationWindow(attendancePage, attendanceLastPage)

  function exportAttendanceCsv() {
    // Build CSV from the same column schema as the on-screen table for 1:1 parity.
    return exportAdminAttendance({
      ...attendanceFilters,
      format: 'json',
    }).then((data) => {
      const exportRows = Array.isArray(data?.rows) ? data.rows : []
      const { headers, rowsMatrix } = attendanceTableExportSchema(exportRows)
      const csvLines = [
        headers.map(toCsvCell).join(','),
        ...rowsMatrix.map((row) => row.map(toCsvCell).join(',')),
      ]
      const blob = new Blob(["\uFEFF" + csvLines.join('\r\n')], { type: 'text/csv;charset=utf-8;' })
      const url = URL.createObjectURL(blob)
      const a = document.createElement('a')
      a.href = url
      const stamp = new Date().toISOString().replace(/[:.]/g, '-')
      a.download = `attendance-${fromDate || ''}-${toDate || ''}-${stamp}.csv`
      document.body.appendChild(a)
      a.click()
      document.body.removeChild(a)
      URL.revokeObjectURL(url)
    })
  }

  async function exportAttendanceExcel() {
    const data = await exportAdminAttendance({ ...attendanceFilters, format: 'json' })
    const exportRows = Array.isArray(data?.rows) ? data.rows : []
    const { headers, rowsMatrix } = attendanceTableExportSchema(exportRows)
    await exportRowsToXlsx(
      headers,
      rowsMatrix,
      `attendance-${fromDate || ''}-${toDate || ''}-${new Date().toISOString().replace(/[:.]/g, '-')}.xlsx`,
      'Attendance',
    )
  }

  if (correctionsTabActive) {
    return (
      <div className="space-y-4">
        <div className="flex flex-wrap items-center gap-3">
          <Button
            type="button"
            variant="outline"
            size="sm"
            className="border-border/70"
            onClick={() => {
              const next = new URLSearchParams(searchParams)
              next.delete('tab')
              next.delete('request_id')
              next.delete('status')
              setSearchParams(next, { replace: true })
            }}
          >
            ← Attendance overview
          </Button>
        </div>
        <AttendanceCorrections />
      </div>
    )
  }

  return (
    <div className="space-y-5">
      <div className="flex flex-col gap-2 @md:flex-row @md:items-center @md:justify-between">
        <div>
          <div className="flex items-center gap-3 flex-wrap">
            <h1 className="mb-0 text-[28px] font-black leading-tight tracking-normal text-foreground">Attendance</h1>
            <span className="inline-flex items-center gap-1.5 rounded-full bg-emerald-500/15 px-2.5 py-1 text-[11px] font-bold text-emerald-700 dark:bg-emerald-500/20 dark:text-emerald-300">
              <span className="size-1.5 animate-pulse rounded-full bg-emerald-500" />
              LIVE
            </span>
          </div>
          <p className="text-sm text-muted-foreground">
            {String(user?.hr_role || user?.role || 'manager')
              .replace(/_/g, ' ')
              .replace(/\b\w/g, (c) => c.toUpperCase())}
            {' - '}
            {isUnrestrictedHr
              ? 'Organization-wide monitoring, approvals, and exports. '
              : 'Scoped to your organization - monitoring and exports. '}
            <span className="text-xs text-muted-foreground/60">
              Updated {secondsAgo < 5 ? 'just now' : `${secondsAgo}s ago`} - Auto-refreshes every 15s
            </span>
          </p>
        </div>
      </div>

      {(lateCount > 0 || absentCount > 0) && (
        <div className="flex flex-col @sm:flex-row items-start @sm:items-center justify-between gap-3 rounded-lg border border-orange-500/35 bg-orange-500/8 px-5 py-4 shadow-sm dark:border-orange-400/30 dark:bg-orange-500/10">
          <div className="flex items-center gap-3">
            <span className="flex size-8 items-center justify-center rounded-full bg-orange-500/10 text-orange-600 ring-1 ring-orange-500/25 dark:bg-orange-500/15 dark:text-orange-300">
              <AlertCircle className="size-4" />
            </span>
            <div>
              <p className="font-bold text-orange-700 dark:text-orange-200">
                {[lateCount > 0 ? `${lateCount} Late` : null, absentCount > 0 ? `${absentCount} Absent` : null].filter(Boolean).join(' - ')} - Action Needed
              </p>
              <p className="text-xs font-medium text-orange-700/75 dark:text-orange-200/70">
                Review these employees and follow up as needed.
              </p>
            </div>
          </div>
          <div className="flex items-center gap-3 shrink-0">
            {lateCount > 0 && (
              <button
                type="button"
                onClick={() => { setStatus('late'); loadWith({ status: 'late' }) }}
                className="text-xs font-bold text-orange-700 underline underline-offset-2 hover:text-orange-600 dark:text-orange-300 dark:hover:text-orange-200"
              >
                View {lateCount} Late
              </button>
            )}
            {absentCount > 0 && (
              <button
                type="button"
                onClick={() => { setStatus('absent'); loadWith({ status: 'absent' }) }}
                className="text-xs font-bold text-red-700 underline underline-offset-2 hover:text-red-600 dark:text-red-300 dark:hover:text-red-200"
              >
                View {absentCount} Absent
              </button>
            )}
          </div>
        </div>
      )}

      <p className="text-xs font-medium text-muted-foreground">
        Snapshot for the selected period{fromDate === toDate ? ` (${fromDate})` : ''}.
      </p>
      <div className="grid gap-3 @sm:grid-cols-2 @lg:grid-cols-4">
        {/* Present */}
        <Card className="overflow-hidden rounded-lg border border-border/70 bg-card shadow-sm dark:border-white/10">
          <CardContent className="p-5">
            <div className="flex items-start justify-between">
              <div>
                <p className="text-xs font-semibold text-muted-foreground">Present</p>
                <p className="mt-1 text-3xl font-black tracking-normal text-foreground">{presentCount}</p>
                <p className="mt-2 text-xs text-muted-foreground">Clocked in</p>
              </div>
              <div className="flex size-12 items-center justify-center rounded-full bg-orange-500/10 text-orange-600 dark:bg-orange-500/15 dark:text-orange-300">
                <CheckCircle2 className="size-6" />
              </div>
            </div>
          </CardContent>
        </Card>

        {/* Late */}
        <Card className={cn('overflow-hidden rounded-lg border bg-card shadow-sm transition-all dark:border-white/10', lateCount > 0 ? 'border-orange-500/45 shadow-[0_0_0_1px_rgba(249,115,22,0.12)] dark:border-orange-400/35' : 'border-border/70')}>
          <CardContent className="p-5">
            <div className="flex items-start justify-between">
              <div>
                <p className="text-xs font-semibold text-muted-foreground">Late</p>
                <p className="mt-1 text-3xl font-black tracking-normal text-foreground">{lateCount}</p>
                <p className="mt-2 text-xs text-muted-foreground">{lateCount > 0 ? 'Need follow-up' : 'All on time'}</p>
              </div>
              <div className={cn('flex size-12 items-center justify-center rounded-full bg-orange-500/10 text-orange-600 dark:bg-orange-500/15 dark:text-orange-300', lateCount > 0 && 'animate-pulse')}>
                <AlertCircle className="size-6" />
              </div>
            </div>
          </CardContent>
        </Card>

        {/* Absent */}
        <Card className={cn('overflow-hidden rounded-lg border bg-card shadow-sm transition-all dark:border-white/10', absentCount > 0 ? 'border-orange-500/45 shadow-[0_0_0_1px_rgba(249,115,22,0.12)] dark:border-orange-400/35' : 'border-border/70')}>
          <CardContent className="p-5">
            <div className="flex items-start justify-between">
              <div>
                <p className="text-xs font-semibold text-muted-foreground">Absent</p>
                <p className="mt-1 text-3xl font-black tracking-normal text-foreground">{absentCount}</p>
                <p className="mt-2 text-xs text-muted-foreground">{absentCount > 0 ? 'Unaccounted' : 'Full attendance'}</p>
              </div>
              <div className={cn('flex size-12 items-center justify-center rounded-full bg-orange-500/10 text-orange-600 dark:bg-orange-500/15 dark:text-orange-300', absentCount > 0 && 'animate-pulse')}>
                <UserX className="size-6" />
              </div>
            </div>
          </CardContent>
        </Card>

        {/* On leave */}
        <Card className="overflow-hidden rounded-lg border border-border/70 bg-card shadow-sm dark:border-white/10">
          <CardContent className="p-5">
            <div className="flex items-start justify-between">
              <div>
                <p className="text-xs font-semibold text-muted-foreground">On leave</p>
                <p className="mt-1 text-3xl font-black tracking-normal text-foreground">{onLeaveCount}</p>
                <p className="mt-2 text-xs text-muted-foreground">Approved leave</p>
              </div>
              <div className="flex size-12 items-center justify-center rounded-full bg-orange-500/10 text-orange-600 dark:bg-orange-500/15 dark:text-orange-300">
                <CalendarDays className="size-6" />
              </div>
            </div>
          </CardContent>
        </Card>
      </div>

      <Card className="rounded-lg border border-border/70 bg-card shadow-sm dark:border-white/10">
        <CardHeader className="pb-3">
          <div className="flex flex-col @sm:flex-row @sm:items-start @sm:justify-between gap-3">
            <div>
              <CardTitle className="text-sm font-semibold">Filters</CardTitle>
              <CardDescription className="text-xs">
                {isUnrestrictedHr
                  ? 'Narrow down attendance by date, department, employee, or status.'
                  : 'Filters apply within your authorized scope. Use department and employee to narrow further.'}
              </CardDescription>
            </div>
            <div className="flex flex-wrap items-center gap-2">
              <span className="text-xs font-medium text-muted-foreground">Scope:</span>
              <div
                className="inline-flex rounded-lg border border-border/60 bg-muted/30 p-0.5 dark:bg-muted/20"
                role="tablist"
                aria-label="Date scope"
              >
                {[
                  {
                    id: 'all',
                    label: 'All',
                    action: () => {
                      setScopeSegment('all')
                    },
                  },
                  {
                    id: 'today',
                    label: 'Today',
                    action: () => {
                      const t = getLocalDateString()
                      setFromDate(t)
                      setToDate(t)
                      setScopeSegment('today')
                      loadWith({ fromDate: t, toDate: t })
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
                      const m = getLocalDateString(mon)
                      const tod = getLocalDateString(now)
                      setFromDate(m)
                      setToDate(tod)
                      setScopeSegment('week')
                      loadWith({ fromDate: m, toDate: tod })
                    },
                  },
                  {
                    id: 'pending',
                    label: 'Pending',
                    action: () => {
                      setScopeSegment('pending')
                    },
                  },
                ].map(({ id, label, action }) => (
                  <button
                    key={id}
                    type="button"
                    role="tab"
                    aria-selected={scopeSegment === id}
                    onClick={action}
                    className={cn(
                      'rounded-md px-3 py-1.5 text-xs font-semibold transition-all',
                      scopeSegment === id
                        ? 'bg-background text-foreground shadow-sm ring-1 ring-border/50 dark:bg-input/40'
                        : 'text-muted-foreground hover:text-foreground',
                    )}
                  >
                    {label}
                  </button>
                ))}
              </div>
              <span className="text-xs font-medium text-muted-foreground">Status:</span>
              {[
                { label: 'Late', accent: 'amber', action: () => { setStatus('late'); setScopeSegment('all'); loadWith({ status: 'late' }) } },
                { label: 'Absent', accent: 'red', action: () => { setStatus('absent'); setScopeSegment('all'); loadWith({ status: 'absent' }) } },
              ].map(({ label, action, accent }) => (
                <button
                  key={label}
                  type="button"
                  onClick={action}
                  className={[
                    'inline-flex items-center rounded-md border px-3 py-1.5 text-xs font-semibold transition-all',
                    accent === 'amber'
                      ? 'border-orange-400/50 bg-orange-500/10 text-orange-700 hover:bg-orange-500/20 dark:border-orange-500/40 dark:text-orange-300'
                      : 'border-red-400/50 bg-red-500/10 text-red-700 hover:bg-red-500/20 dark:border-red-500/40 dark:text-red-400',
                  ].join(' ')}
                >
                  {label}
                </button>
              ))}

              <DropdownMenu>
                <DropdownMenuTrigger asChild>
                  <Button
                    type="button"
                    variant="outline"
                    size="sm"
                    className="gap-1.5 border-border/70 bg-background text-[#0A0A0A] shadow-sm hover:bg-muted/60 dark:text-foreground"
                    disabled={loading || !attendanceTotalMatched}
                    title="Export Attendance (CSV / Excel)"
                  >
                    <Download className="size-4" aria-hidden />
                    Export Attendance
                    <ChevronDown className="size-3.5 opacity-60" aria-hidden />
                  </Button>
                </DropdownMenuTrigger>
                <DropdownMenuContent align="end">
                  <DropdownMenuItem onSelect={() => void exportAttendanceCsv()}>
                    <FileText className="size-4" />
                    CSV
                  </DropdownMenuItem>
                  <DropdownMenuItem onSelect={() => void exportAttendanceExcel()}>
                    <FileText className="size-4" />
                    Excel
                  </DropdownMenuItem>
                </DropdownMenuContent>
              </DropdownMenu>
            </div>
          </div>
        </CardHeader>
        <CardContent className="flex flex-col gap-4">
          <div className="relative w-full max-w-md">
            <Search className="pointer-events-none absolute left-2.5 top-1/2 size-4 -translate-y-1/2 text-muted-foreground" aria-hidden />
            <Input
              value={searchQuery}
              onChange={(e) => setSearchQuery(e.target.value)}
              placeholder={
                hideCompanyColumn
                  ? 'Search name, department, date, ID, status…'
                  : 'Search name, company, department, date, ID, status…'
              }
              className="h-9 pl-9 text-sm"
              aria-label="Search attendance records"
            />
          </div>
          <div className="flex flex-col gap-3 @md:flex-row @md:items-end @md:justify-between">
          <div className="grid w-full grid-cols-1 gap-3 @md:grid-cols-2 @xl:grid-cols-3 @2xl:grid-cols-6">
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
            {attendanceScope?.kind === 'branch' && attendanceScope.branch_name ? (
              <div className="space-y-1.5">
                <span className="text-xs font-medium text-muted-foreground">Branch</span>
                <Input
                  readOnly
                  disabled
                  value={attendanceScope.branch_name}
                  className="h-9 cursor-not-allowed bg-muted/50 text-sm"
                  title="Your branch scope"
                />
              </div>
            ) : null}
            {showCompanyFilter ? (
              <div className="space-y-1.5">
                <span className="text-xs font-medium text-muted-foreground">Company</span>
                <Select value={companyFilter} onValueChange={setCompanyFilter}>
                  <SelectTrigger className="h-9 text-sm">
                    <SelectValue placeholder="All companies" />
                  </SelectTrigger>
                  <SelectContent>
                    <SelectItem value="all">All companies</SelectItem>
                    {companies.map((c) => (
                      <SelectItem key={c} value={c}>
                        {c}
                      </SelectItem>
                    ))}
                  </SelectContent>
                </Select>
              </div>
            ) : null}
            {!showCompanyFilter && attendanceScope?.kind === 'company' && attendanceScope.company_names?.length === 1 ? (
              <div className="space-y-1.5">
                <span className="text-xs font-medium text-muted-foreground">Company</span>
                <Input
                  readOnly
                  disabled
                  value={attendanceScope.company_names[0]}
                  className="h-9 cursor-not-allowed bg-muted/50 text-sm"
                  title="Your company scope"
                />
              </div>
            ) : null}
            <div className="space-y-1.5">
              <span className="text-xs font-medium text-muted-foreground">Department</span>
              <Select
                value={department}
                onValueChange={setDepartment}
                disabled={departmentFilterDisabled}
              >
                <SelectTrigger className="h-9 text-sm">
                  <SelectValue placeholder={departmentSelectAllowsAll ? 'All departments' : 'Department'} />
                </SelectTrigger>
                <SelectContent>
                  {departmentSelectAllowsAll ? (
                    <SelectItem value="all">All departments</SelectItem>
                  ) : null}
                  {departmentFilterOptions.map((d) => (
                    <SelectItem key={d} value={d}>
                      {d}
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
                  {employees.map(([id, name]) => (
                    <SelectItem key={id} value={String(id)}>
                      {name}
                    </SelectItem>
                  ))}
                </SelectContent>
              </Select>
            </div>
            <div className="space-y-1.5">
              <span className="text-xs font-medium text-muted-foreground">Status</span>
              <Select value={status} onValueChange={setStatus}>
                <SelectTrigger className="h-9 text-sm">
                  <SelectValue placeholder="All statuses" />
                </SelectTrigger>
                <SelectContent>
                  <SelectItem value="all">All statuses</SelectItem>
                  <SelectItem value="present">Present</SelectItem>
                  <SelectItem value="late">Late</SelectItem>
                  <SelectItem value="absent">Absent</SelectItem>
                  <SelectItem value="halfday">Halfday</SelectItem>
                  <SelectItem value="undertime">Undertime</SelectItem>
                </SelectContent>
              </Select>
            </div>
            {showPayrollAttendanceColumns && (
              <div className="space-y-1.5">
                <span className="text-xs font-medium text-muted-foreground" title="Premium type (DOLE rules: ordinary, rest day, holiday)">Premium</span>
                <Select value={premiumType} onValueChange={setPremiumType}>
                  <SelectTrigger className="h-9 text-sm">
                    <SelectValue placeholder="All premium types" />
                  </SelectTrigger>
                  <SelectContent>
                    <SelectItem value="all">All premium types</SelectItem>
                    <SelectItem value="ordinary">Ordinary Day</SelectItem>
                    <SelectItem value="rest_day">Rest Day</SelectItem>
                    <SelectItem value="special_holiday">Special Holiday</SelectItem>
                    <SelectItem value="regular_holiday">Regular Holiday</SelectItem>
                    <SelectItem value="special_holiday_rest_day">Special Holiday + Rest</SelectItem>
                    <SelectItem value="regular_holiday_rest_day">Regular Holiday + Rest</SelectItem>
                  </SelectContent>
                </Select>
              </div>
            )}
          </div>
          <div className="flex flex-col items-end gap-1.5">
            <div className="flex gap-2">
              <Button
                type="button"
                variant="default"
                size="sm"
                className="gap-1.5 bg-orange-600 text-white shadow-sm shadow-orange-500/20 hover:bg-orange-500 dark:bg-orange-500 dark:hover:bg-orange-400"
                onClick={applyFilters}
              >
                <Filter className="size-3.5" />
                Apply
              </Button>
              <Button
                type="button"
                variant="outline"
                size="sm"
                className="gap-1.5"
                onClick={load}
              >
                <RefreshCw className="size-3.5" />
                Refresh
              </Button>
            </div>
            <p className="text-[11px] text-muted-foreground">
              {appliedFiltersCount > 0
                ? `${appliedFiltersCount} filter${appliedFiltersCount > 1 ? 's' : ''} applied`
                : 'No filters applied'}
            </p>
            {appliedFiltersCount > 0 && (
              <div className="flex flex-wrap justify-end gap-1.5">
                {dateFilterApplied && (
                  <span className="inline-flex items-center rounded-full border border-border/60 bg-muted px-2 py-0.5 text-[11px] text-muted-foreground">
                    Date:{' '}
                    <span className="ml-1 font-medium text-foreground">
                      {fromDate}
                      {fromDate !== toDate ? ` → ${toDate}` : ''}
                    </span>
                  </span>
                )}
                {companyFilterApplied && (
                  <span className="inline-flex items-center rounded-full border border-border/60 bg-muted px-2 py-0.5 text-[11px] text-muted-foreground">
                    Company:{' '}
                    <span className="ml-1 font-medium text-foreground">{companyFilter}</span>
                  </span>
                )}
                {departmentFilterApplied && (
                  <span className="inline-flex items-center rounded-full border border-border/60 bg-muted px-2 py-0.5 text-[11px] text-muted-foreground">
                    Department:{' '}
                    <span className="ml-1 font-medium text-foreground">{department}</span>
                  </span>
                )}
                {employeeFilterApplied && selectedEmployeeName && (
                  <span className="inline-flex items-center rounded-full border border-border/60 bg-muted px-2 py-0.5 text-[11px] text-muted-foreground">
                    Employee:{' '}
                    <span className="ml-1 font-medium text-foreground">{selectedEmployeeName}</span>
                  </span>
                )}
                {statusFilterApplied && (
                  <span className="inline-flex items-center rounded-full border border-border/60 bg-muted px-2 py-0.5 text-[11px] text-muted-foreground">
                    Status:{' '}
                    <span className="ml-1 font-medium capitalize text-foreground">{status}</span>
                  </span>
                )}
                {premiumFilterApplied && (
                  <span className="inline-flex items-center rounded-full border border-border/60 bg-muted px-2 py-0.5 text-[11px] text-muted-foreground">
                    Premium:{' '}
                    <span className="ml-1 font-medium text-foreground">{premiumType.replace(/_/g, ' ')}</span>
                  </span>
                )}
              </div>
            )}
          </div>
          </div>
        </CardContent>
      </Card>

      {error && (
        <div className="rounded-md border border-destructive/50 bg-destructive/10 px-3 py-2 text-sm text-destructive">
          {error}
        </div>
      )}
      <div className="space-y-3">
        <div className="flex items-center gap-2 text-muted-foreground">
          <Table2 className="size-5 shrink-0" aria-hidden />
          <h3 className="text-base font-semibold text-foreground">Records</h3>
        </div>
      <Card className="overflow-hidden rounded-lg border border-border/70 bg-card shadow-sm dark:border-white/10">
        <CardHeader className="flex flex-col gap-3 pb-3 @sm:flex-row @sm:items-center @sm:justify-between">
          <div>
            <CardTitle className="text-sm font-semibold">Attendance records</CardTitle>
            <CardDescription className="text-xs">
              Page {Math.min(attendancePage, attendanceLastPage)} of {attendanceLastPage} · Showing{' '}
              {displayRows.length} / {attendanceTotalMatched} record
              {attendanceTotalMatched !== 1 ? 's' : ''} · {attendancePerPageResolved} per page ·{' '}
              {periodLabel || 'selected period'}
            </CardDescription>
          </div>
          <DropdownMenu>
            <DropdownMenuTrigger asChild>
              <Button
                type="button"
                size="sm"
                variant="outline"
                className="gap-1.5 dark:border-white/10 dark:text-foreground dark:hover:bg-white/5"
                disabled={!attendanceTotalMatched}
              >
                <Download className="size-4" aria-hidden />
                Export
                <ChevronDown className="size-3.5 opacity-60" aria-hidden />
              </Button>
            </DropdownMenuTrigger>
            <DropdownMenuContent align="end">
              <DropdownMenuItem onSelect={() => exportAttendanceCsv()}>
                <FileText className="size-4" />
                CSV
              </DropdownMenuItem>
              <DropdownMenuItem onSelect={() => exportAttendanceExcel()}>
                <FileText className="size-4" />
                Excel
              </DropdownMenuItem>
              <DropdownMenuItem disabled={exportingPdf} onSelect={() => handleExportPdf()}>
                {exportingPdf ? <RefreshCw className="size-4 animate-spin" /> : <FileText className="size-4" />}
                PDF
              </DropdownMenuItem>
            </DropdownMenuContent>
          </DropdownMenu>
        </CardHeader>
        <CardContent className="pb-6">
          <AttendanceRecordsDataTable
            mode="admin"
            rows={displayRows}
            loading={loading && !attendanceQuery.isPlaceholderData}
            profileImageUrl={profileImageUrl}
            hideCompanyColumn={hideCompanyColumn}
            hideDepartmentColumn={hideDepartmentColumn}
            onOpenDetails={(r) => {
              setDetailRow(r)
              setDetailOpen(true)
            }}
            emptyMessage={
              attendanceTotalMatched === 0 && !loading
                ? 'No attendance records for this date and filters.'
                : loading
                  ? ''
                  : 'No rows on this page.'
            }
          />
          {attendanceTotalMatched > 0 && (
            <div className="flex flex-col gap-2 border-t border-border/40 px-4 py-3 text-[11px] text-muted-foreground @sm:flex-row @sm:items-center @sm:justify-between">
              <div className="flex flex-wrap items-center gap-3">
                <span className="tabular-nums">
                  Showing {Math.min((attendancePage - 1) * attendancePerPageResolved + 1, attendanceTotalMatched)} to{' '}
                  {Math.min(attendancePage * attendancePerPageResolved, attendanceTotalMatched)} of {attendanceTotalMatched}{' '}
                  records
                </span>
                <label className="flex items-center gap-2 text-[11px]">
                  <span className="text-muted-foreground">Rows per page:</span>
                  <Select value={String(attendancePerPage)} onValueChange={handleAttendancePerPageChange}>
                    <SelectTrigger className="h-8 w-[72px] text-xs">
                      <SelectValue />
                    </SelectTrigger>
                    <SelectContent>
                      {ATTENDANCE_PAGE_SIZE_OPTIONS.map((n) => (
                        <SelectItem key={n} value={String(n)}>
                          {n}
                        </SelectItem>
                      ))}
                    </SelectContent>
                  </Select>
                </label>
              </div>
              <div className="flex flex-wrap items-center gap-1.5">
                <Button
                  type="button"
                  variant="outline"
                  size="sm"
                  className="h-8 px-3"
                  disabled={loading || attendancePage <= 1}
                  onClick={() => setAttendancePage((p) => Math.max(1, p - 1))}
                >
                  Previous
                </Button>
                {pageButtons.map((page) =>
                  typeof page === 'number' ? (
                    <Button
                      key={page}
                      type="button"
                      variant={page === attendancePage ? 'default' : 'outline'}
                      size="sm"
                      className={cn(
                        'h-8 min-w-8 px-2.5',
                        page === attendancePage && 'bg-orange-600 text-white hover:bg-orange-500 dark:bg-orange-500 dark:hover:bg-orange-400',
                      )}
                      disabled={loading}
                      onClick={() => setAttendancePage(page)}
                    >
                      {page}
                    </Button>
                  ) : (
                    <span key={page} className="px-1.5 text-muted-foreground">...</span>
                  ),
                )}
                <Button
                  type="button"
                  variant="outline"
                  size="sm"
                  className="h-8 px-3"
                  disabled={loading || attendancePage >= attendanceLastPage}
                  onClick={() => setAttendancePage((p) => Math.min(attendanceLastPage, p + 1))}
                >
                  Next
                </Button>
              </div>
            </div>
          )}
        </CardContent>
      </Card>
      </div>

      <AttendanceRecordDetailSheet
        open={detailOpen}
        onOpenChange={(o) => {
          setDetailOpen(o)
          if (!o) setDetailRow(null)
        }}
        mode="admin"
        row={detailRow}
        profileImageUrl={profileImageUrl}
        showPayrollColumns={showPayrollAttendanceColumns}
        correctionsHref={`${hrPanelPath(hrBase, 'attendance')}?tab=corrections`}
      />

    </div>
  )
}

