import { useEffect, useMemo, useState } from 'react'
import { useQuery } from '@tanstack/react-query'
import { pdf } from '@react-pdf/renderer'
import {
  Calendar,
  CalendarDays,
  Clock4,
  Filter,
  RefreshCw,
  Table2,
  Loader2,
  Plus,
  FileText,
  UserX,
  AlertCircle,
  Moon,
  Search,
  ChevronDown,
  Download,
} from 'lucide-react'
import { exportRowsToXlsx } from '@/lib/excelExport'
import { useHrBasePath } from '@/contexts/HrAppPathContext'
import { hrPanelPath } from '@/lib/hrRoutes'
import { AttendanceRecordsDataTable } from '@/components/attendance/AttendanceRecordsDataTable'
import { AttendanceRecordDetailSheet } from '@/components/attendance/AttendanceRecordDetailSheet'
import {
  attendanceRecordRef,
  resolveAdminStatusLabel,
  tableApprovedOtHours,
  tableOtHoursHrs,
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
  saveAttendanceCorrection,
  getEmployees,
  profileImageUrl,
  REPORTS_AND_ATTENDANCE_PAGE_SIZE,
} from '@/api'
import ReportPdfDocument from '@/components/reports/ReportPdfDocument'
import { Dialog, DialogContent, DialogHeader, DialogTitle, DialogDescription, DialogFooter } from '@/components/ui/dialog'
import { DIALOG_CONTENT_CLASS } from '@/lib/fieldClasses'
import { Label } from '@/components/ui/label'
import { Checkbox } from '@/components/ui/checkbox'
import { Textarea } from '@/components/ui/textarea'
import { cn } from '@/lib/utils'
import { useAuth } from '@/contexts/AuthContext'
import { isAdminHrUser } from '@/lib/hrRoutes'

function toHhMm(value) {
  if (value == null || typeof value !== 'string') return value
  const trimmed = value.trim()
  if (!trimmed) return trimmed
  return trimmed.length >= 5 ? trimmed.slice(0, 5) : trimmed
}

function formatTime(value) {
  if (!value) return '—'

  // If backend sends full ISO strings (e.g. 2026-02-27T08:00:00Z or +08:00),
  // ignore timezone and just take the local clock time portion to avoid shifts.
  if (typeof value === 'string') {
    const isoMatch = value.match(/T(\d{2}:\d{2})/)
    const timePart = isoMatch ? isoMatch[1] : value.trim()

    // Handle plain "HH:MM" values as well
    if (/^\d{1,2}:\d{2}$/.test(timePart)) {
      const [hStr, mStr] = timePart.split(':')
      let h = Number(hStr)
      if (Number.isNaN(h)) return timePart
      const suffix = h >= 12 ? 'PM' : 'AM'
      h = h % 12 || 12
      const hourLabel = String(h).padStart(2, '0')
      return `${hourLabel}:${mStr} ${suffix}`
    }
  }

  // Fallback: try native Date parsing if it's not in the expected format.
  try {
    const d = new Date(value)
    if (Number.isNaN(d.getTime())) return String(value)
    return d.toLocaleTimeString(undefined, {
      hour: '2-digit',
      minute: '2-digit',
    })
  } catch {
    return String(value)
  }
}

/** Local calendar date YYYY-MM-DD (avoids UTC date mismatch with server timezone). */
function getLocalDateString(d = new Date()) {
  const y = d.getFullYear()
  const m = String(d.getMonth() + 1).padStart(2, '0')
  const day = String(d.getDate()).padStart(2, '0')
  return `${y}-${m}-${day}`
}

export default function AdminAttendance() {
  const { user } = useAuth()
  const hrBase = useHrBasePath()
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
    () => ({ ...attendanceFilters, page: attendancePage }),
    [attendanceFilters, attendancePage],
  )

  const attendanceQuery = useQuery({
    queryKey: ['admin-attendance', attendanceParams],
    queryFn: () => getAdminAttendance(attendanceParams),
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
    } else if (attendanceQuery.isLoading) {
      setLoading(true)
    }
  }, [attendanceQuery.data, attendanceQuery.error, attendanceQuery.isLoading])

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
  ])

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

  const [addOpen, setAddOpen] = useState(false)
  const [allEmployees, setAllEmployees] = useState([])
  const [addEmployeesLoading, setAddEmployeesLoading] = useState(false)
  const [addSubmitting, setAddSubmitting] = useState(false)
  const [addError, setAddError] = useState(null)
  const [addForm, setAddForm] = useState({
    employee_id: '',
    date: new Date().toISOString().slice(0, 10),
    /** `full` = clock in + clock out; `in_only` = clock in only (no time out). */
    manual_punch_mode: 'full',
    time_in: '08:00',
    time_out: '17:00',
    remarks: '',
    approved: true,
    override_leave: false,
    use_schedule_regular: false,
    manual_presence_reason: '',
  })

  useEffect(() => {
    if (addOpen) {
      setAddForm((f) => ({ ...f, manual_punch_mode: 'full' }))
      setAddEmployeesLoading(true)
      setAddError(null)
      const scope = user?.attendance_scope
      const params = { per_page: 200 }
      if (scope?.kind === 'department' && scope.department_ids?.length === 1) {
        params.department_id = scope.department_ids[0]
      } else if (scope?.kind === 'branch' && scope.branch_id) {
        params.branch_id = scope.branch_id
      } else if (scope?.kind === 'company' && scope.company_ids?.length === 1) {
        params.company_id = scope.company_ids[0]
      }
      getEmployees(params)
        .then((res) => {
          const list = res?.employees ?? res ?? []
          setAllEmployees(Array.isArray(list) ? list : [])
        })
        .catch(() => setAllEmployees([]))
        .finally(() => setAddEmployeesLoading(false))
    }
  }, [addOpen, user?.attendance_scope])

  function validateAddForm() {
    const empId = addForm.employee_id?.trim()
    const date = addForm.date?.trim()
    const timeIn = addForm.time_in?.trim()
    const timeOut = addForm.time_out?.trim()
    if (!empId) return 'Employee is required. Please select an employee.'
    if (!date) return 'Date is required. Please select a date.'
    const dateObj = new Date(date)
    if (Number.isNaN(dateObj.getTime())) return 'Please enter a valid date.'
    if (addForm.use_schedule_regular) {
      return null
    }
    if (!timeIn) return 'Time in is required (or enable “Use scheduled shift times”).'
    if (addForm.manual_punch_mode === 'full' && !timeOut) return 'Time out is required when recording both clock in and clock out.'
    // Night shift (e.g. 22:00–06:00): timeOut < timeIn is valid; backend normalizes to next day.
    return null
  }

  async function handleAddManualSubmit(e) {
    e.preventDefault()
    const err = validateAddForm()
    if (err) {
      setAddError(err)
      return
    }
    setAddError(null)
    setAddSubmitting(true)
    try {
      const empId = Number(addForm.employee_id)
      const date = addForm.date.trim()

      // Prevent duplicate manual attendance for the same employee and date
      const duplicateRows = await fetchAllAdminAttendanceRows({
        from_date: date,
        to_date: date,
        employee_id: empId,
      })
      const hasDuplicate = duplicateRows.some(
        (r) => r.employee_id === empId && r.date === date && r.has_correction,
      )

      if (hasDuplicate) {
        setAddError('Manual attendance for this employee and date already exists.')
        return
      }

      const clockInOnly = addForm.manual_punch_mode === 'in_only'
      const basePayload = {
        employee_id: empId,
        date,
        preset_schedule_regular: Boolean(addForm.use_schedule_regular),
        time_in: addForm.use_schedule_regular ? undefined : (toHhMm(addForm.time_in?.trim() || '') || undefined),
        time_out:
          addForm.use_schedule_regular || clockInOnly
            ? undefined
            : (toHhMm(addForm.time_out?.trim() || '') || undefined),
        remarks: addForm.remarks?.trim() || undefined,
        manual_presence_reason: addForm.manual_presence_reason?.trim() || undefined,
        approved: Boolean(addForm.approved),
      }

      const attemptSave = async (overrideLeave) => {
        await saveAttendanceCorrection({
          ...basePayload,
          override_leave: overrideLeave,
        })
      }

      try {
        await attemptSave(Boolean(addForm.override_leave))
      } catch (error) {
        const msg = error?.message || ''
        const leaveConflict =
          msg.toLowerCase().includes('approved full-day leave') ||
          msg.toLowerCase().includes('approved leave on this date')

        if (leaveConflict && !addForm.override_leave) {
          const confirmed = window.confirm(
            'This employee has an approved leave on this date. Do you want to override it?'
          )
          if (!confirmed) {
            setAddError(msg)
            return
          }

          // Retry with override flag enabled
          await attemptSave(true)
        } else {
          throw error
        }
      }

      setAddOpen(false)
      await load()
    } catch (err) {
      setAddError(err?.message || 'Failed to add manual attendance.')
    } finally {
      setAddSubmitting(false)
    }
  }

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

  function pdfColumns() {
    const base = [
      { label: 'Record ID', accessor: (row) => attendanceRecordRef(row.employee_id, row.date) },
      { label: 'Employee', accessor: 'employee_name' },
      { label: 'Department', accessor: (row) => row.department || 'No Department Assigned' },
      { label: 'Date', accessor: (row) => row.date },
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
      { label: 'Clock In', accessor: (row) => formatTime(row.time_in) },
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
        label: 'Rendered OT (h)',
        accessor: (row) =>
          row.rendered_overtime_hours != null ? String(row.rendered_overtime_hours) : '—',
      },
      {
        label: 'OT hrs (approved)',
        accessor: (row) => tableApprovedOtHours(row),
      },
      {
        label: 'Unapproved OT (hrs)',
        accessor: (row) => tableOtHoursHrs(row.unapproved_overtime_hours),
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

  const displayRows = rows

  const secondsAgo = Math.floor((Date.now() - lastRefresh.getTime()) / 1000)

  function exportAttendanceCsv() {
    // Use backend export endpoint for consistent column set and status logic.
    return exportAdminAttendance({
      ...attendanceFilters,
      format: 'csv',
    }).then((blob) => {
      const url = URL.createObjectURL(blob)
      const a = document.createElement('a')
      a.href = url
      a.download = `attendance-${fromDate || ''}-${toDate || ''}.csv`
      document.body.appendChild(a)
      a.click()
      document.body.removeChild(a)
      URL.revokeObjectURL(url)
    })
  }

  async function exportAttendanceExcel() {
    const data = await exportAdminAttendance({ ...attendanceFilters, format: 'json' })
    const exportRows = Array.isArray(data?.rows) ? data.rows : []
    const headers = [
      'Employee ID',
      'Employee Name',
      'Department',
      'Company',
      'Date',
      'Time In',
      'Time Out',
      'Status',
      'Approved OT (hrs)',
      'Unapproved OT (hrs)',
      'Rendered Overtime Hours',
      'Night Hours',
      'Total Hours Worked',
      'Has Correction',
      'Correction Approved',
      'Correction Remarks',
    ]
    const rowsMatrix = exportRows.map((r) => [
      r.employee_id ?? '',
      r.employee_name ?? '',
      r.department ?? '',
      r.company_name ?? '',
      r.date ?? '',
      r.time_in ?? '',
      r.time_out ?? '',
      resolveAdminStatusLabel(r),
      r.approved_overtime_hours ?? r.overtime_hours ?? '',
      r.unapproved_overtime_hours ?? '',
      r.rendered_overtime_hours ?? '',
      r.night_hours ?? '',
      r.total_rendered_hours ?? r.total_hours ?? '',
      r.has_correction ? 'Yes' : 'No',
      r.correction_approved ? 'Yes' : 'No',
      r.correction_remarks ?? '',
    ])
    await exportRowsToXlsx(
      headers,
      rowsMatrix,
      `attendance-${fromDate || ''}-${toDate || ''}.xlsx`,
      'Attendance',
    )
  }

  return (
    <div className="space-y-6">
      <div className="flex flex-col gap-2 @md:flex-row @md:items-center @md:justify-between">
        <div>
          <div className="flex items-center gap-3 flex-wrap">
            <h1 className="hr-page-title">Attendance</h1>
            <span className="inline-flex items-center gap-1.5 rounded-full bg-emerald-500/15 px-2.5 py-1 text-[11px] font-bold text-emerald-700 dark:bg-emerald-500/20 dark:text-emerald-400">
              <span className="size-1.5 animate-pulse rounded-full bg-emerald-500" />
              LIVE
            </span>
          </div>
          <p className="text-sm text-muted-foreground">
            {String(user?.hr_role || user?.role || 'manager')
              .replace(/_/g, ' ')
              .replace(/\b\w/g, (c) => c.toUpperCase())}
            {' · '}
            {isUnrestrictedHr
              ? 'Organization-wide monitoring, approvals, and exports. '
              : 'Scoped to your organization — monitoring and exports. '}
            <span className="text-xs text-muted-foreground/60">
              Updated {secondsAgo < 5 ? 'just now' : `${secondsAgo}s ago`} · Auto-refreshes every 15s
            </span>
          </p>
        </div>
      </div>

      {(lateCount > 0 || absentCount > 0) && (
        <div className="flex flex-col @sm:flex-row items-start @sm:items-center justify-between gap-3 rounded-xl border border-red-400/50 bg-red-500/8 px-4 py-3.5 dark:border-red-500/40 dark:bg-red-500/8">
          <div className="flex items-center gap-3">
            <AlertCircle className="size-5 shrink-0 text-red-600 dark:text-red-400" />
            <div>
              <p className="font-semibold text-red-800 dark:text-red-200">
                {[lateCount > 0 ? `${lateCount} Late` : null, absentCount > 0 ? `${absentCount} Absent` : null].filter(Boolean).join(' • ')} — Action Needed
              </p>
              <p className="text-xs text-red-700/70 dark:text-red-300/60">
                Review these employees and follow up as needed.
              </p>
            </div>
          </div>
          <div className="flex items-center gap-3 shrink-0">
            {lateCount > 0 && (
              <button
                type="button"
                onClick={() => { setStatus('late'); loadWith({ status: 'late' }) }}
                className="text-xs font-semibold text-amber-700 hover:text-amber-600 dark:text-amber-400 dark:hover:text-amber-300 underline underline-offset-2"
              >
                View {lateCount} Late
              </button>
            )}
            {absentCount > 0 && (
              <button
                type="button"
                onClick={() => { setStatus('absent'); loadWith({ status: 'absent' }) }}
                className="text-xs font-semibold text-red-700 hover:text-red-600 dark:text-red-400 dark:hover:text-red-300 underline underline-offset-2"
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
        <Card className="border border-border/60 shadow-md dark:border-white/8 bg-card overflow-hidden">
          <CardContent className="p-5">
            <div className="flex items-start justify-between">
              <div>
                <p className="text-xs font-medium text-muted-foreground">Present</p>
                <p className="mt-1 text-4xl font-black tracking-tight text-emerald-600 dark:text-emerald-400">{presentCount}</p>
                <p className="mt-1 text-xs text-muted-foreground">Clocked in</p>
              </div>
              <div className="flex size-10 items-center justify-center rounded-xl bg-emerald-500/15 dark:bg-emerald-500/20">
                <Clock4 className="size-5 text-emerald-600 dark:text-emerald-400" />
              </div>
            </div>
          </CardContent>
        </Card>

        {/* Late */}
        <Card className={`border shadow-md bg-card overflow-hidden transition-all ${lateCount > 0 ? 'border-amber-400/60 dark:border-amber-500/40 shadow-[0_0_18px_rgba(245,158,11,0.12)]' : 'border-border/60 dark:border-white/8'}`}>
          <CardContent className="p-5">
            <div className="flex items-start justify-between">
              <div>
                <p className="text-xs font-medium text-muted-foreground">Late</p>
                <p className={`mt-1 text-4xl font-black tracking-tight ${lateCount > 0 ? 'text-amber-600 dark:text-amber-400' : 'text-foreground'}`}>{lateCount}</p>
                <p className="mt-1 text-xs text-muted-foreground">{lateCount > 0 ? 'Need follow-up' : 'All on time'}</p>
              </div>
              <div className={`flex size-10 items-center justify-center rounded-xl ${lateCount > 0 ? 'bg-amber-500/20 animate-pulse' : 'bg-amber-500/10'}`}>
                <AlertCircle className={`size-5 ${lateCount > 0 ? 'text-amber-500 dark:text-amber-400' : 'text-amber-500/50'}`} />
              </div>
            </div>
          </CardContent>
        </Card>

        {/* Absent */}
        <Card className={`border shadow-md bg-card overflow-hidden transition-all ${absentCount > 0 ? 'border-red-400/60 dark:border-red-500/40 shadow-[0_0_18px_rgba(239,68,68,0.12)]' : 'border-border/60 dark:border-white/8'}`}>
          <CardContent className="p-5">
            <div className="flex items-start justify-between">
              <div>
                <p className="text-xs font-medium text-muted-foreground">Absent</p>
                <p className={`mt-1 text-4xl font-black tracking-tight ${absentCount > 0 ? 'text-red-600 dark:text-red-400' : 'text-foreground'}`}>{absentCount}</p>
                <p className="mt-1 text-xs text-muted-foreground">{absentCount > 0 ? 'Unaccounted' : 'Full attendance'}</p>
              </div>
              <div className={`flex size-10 items-center justify-center rounded-xl ${absentCount > 0 ? 'bg-red-500/20 animate-pulse' : 'bg-red-500/10'}`}>
                <UserX className={`size-5 ${absentCount > 0 ? 'text-red-500 dark:text-red-400' : 'text-red-500/50'}`} />
              </div>
            </div>
          </CardContent>
        </Card>

        {/* On leave */}
        <Card className="border border-border/60 shadow-md dark:border-white/8 bg-card overflow-hidden">
          <CardContent className="p-5">
            <div className="flex items-start justify-between">
              <div>
                <p className="text-xs font-medium text-muted-foreground">On leave</p>
                <p className="mt-1 text-4xl font-black tracking-tight text-violet-600 dark:text-violet-400">{onLeaveCount}</p>
                <p className="mt-1 text-xs text-muted-foreground">Approved leave</p>
              </div>
              <div className="flex size-10 items-center justify-center rounded-xl bg-violet-500/15 dark:bg-violet-500/20">
                <CalendarDays className="size-5 text-violet-600 dark:text-violet-400" />
              </div>
            </div>
          </CardContent>
        </Card>
      </div>

      <Card className="border border-border/60 shadow-md dark:border-white/8 bg-card">
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
                className="inline-flex rounded-xl border border-border/60 bg-muted/30 p-0.5"
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
                      'rounded-[10px] px-3 py-1.5 text-xs font-semibold transition-all',
                      scopeSegment === id
                        ? 'bg-background text-foreground shadow-sm'
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
                    'inline-flex items-center rounded-full border px-3 py-1.5 text-xs font-semibold transition-all',
                    accent === 'amber'
                      ? 'border-amber-400/50 bg-amber-500/10 text-amber-700 hover:bg-amber-500/20 dark:border-amber-500/40 dark:text-amber-400'
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
                className="gap-1.5"
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
      <Card className="overflow-hidden rounded-xl border border-border/60 shadow-md dark:border-white/8 bg-card">
        <CardHeader className="flex flex-col gap-3 pb-3 @sm:flex-row @sm:items-center @sm:justify-between">
          <div>
            <CardTitle className="text-sm font-semibold">Attendance records</CardTitle>
            <CardDescription className="text-xs">
              Page {Math.min(attendancePage, attendanceLastPage)} of {attendanceLastPage} · Showing{' '}
              {displayRows.length} / {attendanceTotalMatched} record
              {attendanceTotalMatched !== 1 ? 's' : ''} · {REPORTS_AND_ATTENDANCE_PAGE_SIZE} per page ·{' '}
              {periodLabel || 'selected period'}
            </CardDescription>
          </div>
          <DropdownMenu>
            <DropdownMenuTrigger asChild>
              <Button
                type="button"
                size="sm"
                variant="outline"
                className="gap-1.5 dark:border-white/10 dark:text-slate-300 dark:hover:bg-white/5"
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
            loading={loading}
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
              <span className="tabular-nums">
                Page {Math.min(attendancePage, attendanceLastPage)} of {attendanceLastPage}
              </span>
              <div className="flex gap-2">
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
        correctionsHref={hrPanelPath(hrBase, 'attendance-corrections')}
      />

      {/* Add manual attendance */}
      <Dialog
        open={addOpen}
        onOpenChange={(open) => {
          if (!open) {
            setAddOpen(false)
            setAddError(null)
          }
        }}
      >
        <DialogContent
          className={cn(
            DIALOG_CONTENT_CLASS,
            'flex max-h-[min(90vh,880px)] flex-col gap-0 overflow-hidden p-0 sm:max-w-lg',
          )}
        >
          <DialogHeader className="shrink-0 space-y-1.5 px-6 pb-2 pt-6 text-left">
            <DialogTitle className="flex items-center gap-2 text-xl">
              <Plus className="size-5 text-primary" />
              Add manual attendance
            </DialogTitle>
            <DialogDescription>
              Record clock in only, or both clock in and clock out, without a scan. Use the date filter to see the new entry.
            </DialogDescription>
          </DialogHeader>
          <form onSubmit={handleAddManualSubmit} className="flex min-h-0 flex-1 flex-col overflow-hidden">
            <div className="min-h-0 flex-1 overflow-y-auto overflow-x-hidden px-6 py-1">
          {addError && (
            <div className="mb-3 rounded-md border border-destructive/50 bg-destructive/10 px-3 py-2 text-sm text-destructive">
              {addError}
            </div>
          )}
          <div className="flex flex-col gap-4 pb-2">
            <div className="space-y-1.5">
              <Label htmlFor="add-employee" className="text-xs">Employee <span className="text-destructive">*</span></Label>
              <Select
                value={addForm.employee_id || ' '}
                onValueChange={(v) => setAddForm((f) => ({ ...f, employee_id: v === ' ' ? '' : v }))}
                disabled={addEmployeesLoading}
              >
                <SelectTrigger id="add-employee" className="h-9">
                  <SelectValue placeholder={addEmployeesLoading ? 'Loading…' : 'Select employee'} />
                </SelectTrigger>
                <SelectContent>
                  <SelectItem value=" ">
                    {addEmployeesLoading ? 'Loading employees…' : '— Select employee —'}
                  </SelectItem>
                  {allEmployees.map((emp) => (
                    <SelectItem key={emp.id} value={String(emp.id)}>
                      {emp.name}
                    </SelectItem>
                  ))}
                </SelectContent>
              </Select>
              {!addEmployeesLoading && allEmployees.length === 0 && addOpen && (
                <p className="text-xs text-muted-foreground">No employees found. Add employees first.</p>
              )}
            </div>
            <div className="space-y-1.5">
              <Label htmlFor="add-date" className="text-xs">Date <span className="text-destructive">*</span></Label>
              <Input
                id="add-date"
                type="date"
                value={addForm.date}
                onChange={(e) => setAddForm((f) => ({ ...f, date: e.target.value }))}
                className="h-9"
                required
              />
            </div>
            <div className="space-y-2">
              <Label className="text-xs">What to record</Label>
              <div className="flex flex-wrap gap-2">
                <button
                  type="button"
                  className={cn(
                    'inline-flex min-w-[8.5rem] flex-1 items-center justify-center rounded-lg border px-3 py-2 text-xs font-semibold transition-colors',
                    addForm.manual_punch_mode === 'in_only'
                      ? 'border-primary bg-primary/15 text-primary shadow-sm dark:bg-primary/20'
                      : 'border-border bg-transparent text-muted-foreground hover:border-border hover:text-foreground dark:border-white/10',
                  )}
                  onClick={() => setAddForm((f) => ({ ...f, manual_punch_mode: 'in_only' }))}
                >
                  Clock in only
                </button>
                <button
                  type="button"
                  className={cn(
                    'inline-flex min-w-[8.5rem] flex-1 items-center justify-center rounded-lg border px-3 py-2 text-xs font-semibold transition-colors',
                    addForm.manual_punch_mode === 'full'
                      ? 'border-primary bg-primary/15 text-primary shadow-sm dark:bg-primary/20'
                      : 'border-border bg-transparent text-muted-foreground hover:border-border hover:text-foreground dark:border-white/10',
                  )}
                  onClick={() => setAddForm((f) => ({ ...f, manual_punch_mode: 'full' }))}
                >
                  Clock in &amp; out
                </button>
              </div>
              <p className="text-[11px] leading-snug text-muted-foreground">
                {addForm.manual_punch_mode === 'in_only'
                  ? 'Records arrival only (no clock out). Use when the employee has not left yet or exit was not captured.'
                  : 'Records both time in and time out for a completed shift.'}
              </p>
            </div>
            <div className="flex flex-col gap-2 rounded-lg border border-border/60 bg-muted/10 p-3">
              <div className="flex items-center gap-2">
                <Checkbox
                  id="add-schedule-regular"
                  checked={addForm.use_schedule_regular}
                  onCheckedChange={(c) =>
                    setAddForm((f) => ({
                      ...f,
                      use_schedule_regular: c === true,
                      manual_punch_mode: c === true ? 'full' : f.manual_punch_mode,
                    }))
                  }
                />
                <Label htmlFor="add-schedule-regular" className="text-xs leading-snug">
                  Use scheduled shift times (regular day; no OT from this manual entry)
                </Label>
              </div>
              <div className="space-y-1.5">
                <Label htmlFor="add-manual-reason" className="text-xs">
                  Reason for manual presence (optional)
                </Label>
                <Textarea
                  id="add-manual-reason"
                  rows={2}
                  value={addForm.manual_presence_reason}
                  onChange={(e) => setAddForm((f) => ({ ...f, manual_presence_reason: e.target.value }))}
                  placeholder="e.g. Forgot to punch, kiosk offline"
                  className="min-h-0 text-xs"
                />
              </div>
            </div>
            {!addForm.use_schedule_regular && (
            <div className={cn('grid gap-4', addForm.manual_punch_mode === 'full' ? 'grid-cols-1 @sm:grid-cols-2' : 'grid-cols-1')}>
              <div className="space-y-1.5">
                <Label htmlFor="add-time-in" className="text-xs">Time in <span className="text-destructive">*</span></Label>
                <Input
                  id="add-time-in"
                  type="time"
                  value={addForm.time_in}
                  onChange={(e) => setAddForm((f) => ({ ...f, time_in: e.target.value }))}
                  className="h-9"
                  required
                />
              </div>
              {addForm.manual_punch_mode === 'full' ? (
                <div className="space-y-1.5">
                  <Label htmlFor="add-time-out" className="text-xs">Time out <span className="text-destructive">*</span></Label>
                  <Input
                    id="add-time-out"
                    type="time"
                    value={addForm.time_out}
                    onChange={(e) => setAddForm((f) => ({ ...f, time_out: e.target.value }))}
                    className="h-9"
                    required
                  />
                </div>
              ) : (
                <div className="rounded-lg border border-dashed border-border/70 bg-muted/20 px-3 py-2.5 text-[11px] text-muted-foreground dark:border-white/10 dark:bg-white/5">
                  Clock out is not recorded for this entry.
                </div>
              )}
            </div>
            )}
            {addForm.use_schedule_regular && (
              <p className="rounded-md border border-sky-500/30 bg-sky-500/10 px-3 py-2 text-xs text-sky-900 dark:text-sky-200">
                Times will be taken from the employee&apos;s assigned schedule for that date (regular hours; night differential still follows actual window if applicable).
              </p>
            )}
            {(addForm.manual_punch_mode === 'full' && addForm.time_in && addForm.time_out && addForm.time_out <= addForm.time_in) && (
              <p className="flex items-center gap-1.5 rounded-md border border-amber-500/40 bg-amber-500/10 px-3 py-2 text-xs text-amber-700 dark:text-amber-400">
                <Moon className="size-3.5 shrink-0 opacity-90" aria-hidden />
                Night shift detected (crosses midnight). Time out will be treated as next day.
              </p>
            )}
            <div className="space-y-1.5">
              <Label htmlFor="add-remarks" className="text-xs">Remarks (optional)</Label>
              <Input
                id="add-remarks"
                value={addForm.remarks}
                onChange={(e) => setAddForm((f) => ({ ...f, remarks: e.target.value }))}
                placeholder="e.g. Manual entry"
                className="h-9"
                maxLength={500}
              />
            </div>
            <div className="flex flex-col gap-2">
              <div className="flex items-center gap-2">
                <Checkbox
                  id="add-approved"
                  checked={addForm.approved}
                  onCheckedChange={(c) => setAddForm((f) => ({ ...f, approved: c === true }))}
                />
                <Label htmlFor="add-approved" className="text-xs">Mark as approved</Label>
              </div>
              <div className="flex items-start gap-2">
                <Checkbox
                  id="add-override-leave"
                  checked={addForm.override_leave}
                  onCheckedChange={(c) => setAddForm((f) => ({ ...f, override_leave: c === true }))}
                />
                <div className="space-y-0.5">
                  <Label htmlFor="add-override-leave" className="text-xs">Override approved leave for this date</Label>
                  <p className="text-[11px] text-muted-foreground">
                    When checked, you can record attendance even if a full-day leave exists. Half‑day and undertime rules still apply.
                  </p>
                </div>
              </div>
            </div>
            </div>
            </div>
            <DialogFooter className="shrink-0 gap-2 border-t border-border/60 bg-card px-6 py-4 sm:justify-end">
              <Button
                type="button"
                variant="outline"
                onClick={() => { setAddOpen(false); setAddError(null); }}
                disabled={addSubmitting}
                className="dark:border-white/10 dark:text-slate-300 dark:hover:bg-white/5"
              >
                Cancel
              </Button>
              <Button
                type="submit"
                disabled={
                  addSubmitting
                  || addEmployeesLoading
                  || !addForm.employee_id
                  || !addForm.date
                  || !addForm.time_in
                  || (addForm.manual_punch_mode === 'full' && !addForm.time_out)
                }
                className="gap-2 bg-teal-600 text-white hover:bg-teal-500 dark:bg-teal-600 dark:hover:bg-teal-500"
              >
                {addSubmitting && <Loader2 className="size-4 animate-spin" />}
                Add attendance
              </Button>
            </DialogFooter>
          </form>
        </DialogContent>
      </Dialog>

    </div>
  )
}

