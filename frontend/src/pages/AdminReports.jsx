import { useEffect, useMemo, useRef, useState } from 'react'
import { useLocation } from 'react-router-dom'
import { useQuery } from '@tanstack/react-query'
import { pdf } from '@react-pdf/renderer'
import { exportRowsToXlsx } from '@/lib/excelExport'
import { FileDown, FileText, Filter, RefreshCw, Table2 } from 'lucide-react'
import { Card, CardContent, CardDescription, CardHeader, CardTitle } from '@/components/ui/card'
import { Avatar, AvatarFallback, AvatarImage } from '@/components/ui/avatar'
import { Button } from '@/components/ui/button'
import { Input } from '@/components/ui/input'
import { Select, SelectContent, SelectItem, SelectTrigger, SelectValue } from '@/components/ui/select'
import { Tabs, TabsContent, TabsList, TabsTrigger } from '@/components/ui/tabs'
import {
  getAdminReportsSummary,
  getAdminReportsDetailed,
  getEmployeeReportsSummary,
  getEmployeeReportsDetailed,
  getAdminPremiumReport,
  getEmployees,
  profileImageUrl,
} from '@/api'
import { formatEmploymentStatusForViewer } from '@/lib/employmentStatus'
import ReportPdfDocument from '@/components/reports/ReportPdfDocument'
import { AttendanceStatusBadge } from '@/components/AttendanceStatusBadge'
import { TableBodySkeleton } from '@/components/skeletons'
import { useAuth } from '@/contexts/AuthContext'
import { isAdminHrUser } from '@/lib/hrRoutes'

export { AttendanceStatusBadge }

function debugSessionLog(message, data, hypothesisId, runId = 'initial') {
  // #region agent log
  fetch('http://127.0.0.1:7828/ingest/ed61e194-ee8a-447d-bdd1-54b594b0f0aa', {
    method: 'POST',
    headers: {
      'Content-Type': 'application/json',
      'X-Debug-Session-Id': '0a687c',
    },
    body: JSON.stringify({
      sessionId: '0a687c',
      location: 'AdminReports.jsx',
      message,
      data,
      hypothesisId,
      runId,
      timestamp: Date.now(),
    }),
  }).catch(() => {})
  // #endregion agent log
}

function debugLog(message, data, hypothesisId, runId = 'initial') {
  // #region agent log
  fetch('http://127.0.0.1:7828/ingest/ed61e194-ee8a-447d-bdd1-54b594b0f0aa', {
    method: 'POST',
    headers: {
      'Content-Type': 'application/json',
      'X-Debug-Session-Id': 'd0782e',
    },
    body: JSON.stringify({
      sessionId: 'd0782e',
      location: 'AdminReports.jsx',
      message,
      data,
      hypothesisId,
      runId,
      timestamp: Date.now(),
    }),
  }).catch(() => {})
  // #endregion agent log
}

function todayIso() {
  const d = new Date()
  const year = d.getFullYear()
  const month = String(d.getMonth() + 1).padStart(2, '0')
  const day = String(d.getDate()).padStart(2, '0')
  return `${year}-${month}-${day}`
}

function firstDayOfMonthIso() {
  const d = new Date()
  const firstDay = new Date(d.getFullYear(), d.getMonth(), 1)
  const year = firstDay.getFullYear()
  const month = String(firstDay.getMonth() + 1).padStart(2, '0')
  const day = String(firstDay.getDate()).padStart(2, '0')
  return `${year}-${month}-${day}`
}

function formatNumber(value) {
  if (value == null) return '0'
  return Number(value).toLocaleString()
}

// Attendance report times: normalize to 24-hour HH:MM:SS (and strip broken prefixes like "—01:00").
function formatTimeTo12Hour(value) {
  if (!value) return '—'
  let str = String(value).trim()
  str = str.replace(/^[^\d]*/, '')

  // ISO timestamps: derive local time components.
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

  // HH:MM:SS
  if (/^\d{1,2}:\d{2}:\d{2}$/.test(str)) {
    const [hStr, mStr, sStr] = str.split(':')
    const h = Number(hStr)
    if (Number.isNaN(h)) return str
    return `${String(h).padStart(2, '0')}:${mStr}:${sStr}`
  }

  // HH:MM
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

function isWeekendDate(value) {
  if (!value) return false
  const [year, month, day] = String(value).split('-').map(Number)
  if (!year || !month || !day) return false
  const d = new Date(year, month - 1, day)
  const dayOfWeek = d.getDay()
  return dayOfWeek === 0 || dayOfWeek === 6
}

function getReportTitle(tab) {
  switch (tab) {
    case 'late':
      return 'Late Report'
    case 'undertime':
      return 'Undertime Report'
    case 'halfday':
      return 'Half Day Report'
    case 'absences':
      return 'Absences Report'
    case 'overtime':
      return 'Overtime Report'
    case 'department':
      return 'Company Summary'
    case 'premium':
      return 'Premium Pay Report (OT, ND, Holidays, Rest Day, Combined)'
    default:
      return 'Monthly Summary Per Employee'
  }
}

export default function AdminReports() {
  const [fromDate, setFromDate] = useState(() => firstDayOfMonthIso())
  const [toDate, setToDate] = useState(() => todayIso())
  const [companyId, setCompanyId] = useState('all')  // company filter — uses actual company IDs
  const [employeeId, setEmployeeId] = useState('all')
  const [filterEmployees, setFilterEmployees] = useState([]) // All employees for filter dropdowns (from getEmployees)
  const [data, setData] = useState({ employees: [], departments: [], from_date: '', to_date: '' })
  const [loading, setLoading] = useState(true)
  const [reportError, setReportError] = useState(null)
  const [tab, setTab] = useState('detailed')
  const [search, setSearch] = useState('')
  const [page, setPage] = useState(1)
  const pageSize = 10

  const [detailedRows, setDetailedRows] = useState([])
  const detailedRequestKeyRef = useRef('')
  const [premiumData, setPremiumData] = useState({ employees: [], from_date: '', to_date: '' })
  const [premiumLoading, setPremiumLoading] = useState(false)

  const { user } = useAuth()
  const location = useLocation()
  const isEmployeeSelfReport = location.pathname.startsWith('/employee/reports')
  const viewerIsAdminHr = isAdminHrUser(user)
  const showPayrollReports = viewerIsAdminHr || isEmployeeSelfReport

  useEffect(() => {
    if (!showPayrollReports && tab === 'premium') {
      setTab('detailed')
    }
  }, [showPayrollReports, tab])

  useEffect(() => {
    if (isEmployeeSelfReport && tab === 'department') {
      setTab('detailed')
    }
  }, [isEmployeeSelfReport, tab])

  // Normalize date range: use YYYY-MM-DD for API.
  function effectiveDateRange() {
    const from = fromDate || firstDayOfMonthIso()
    const to = toDate || todayIso()
    return { from, to }
  }

  const effectiveRange = useMemo(() => effectiveDateRange(), [fromDate, toDate])
  const summaryParams = useMemo(() => ({
    from_date: effectiveRange.from,
    to_date: effectiveRange.to,
    company_id: companyId !== 'all' ? Number(companyId) : undefined,
    employee_id: employeeId !== 'all' ? Number(employeeId) : undefined,
  }), [effectiveRange, companyId, employeeId])

  const summaryQuery = useQuery({
    queryKey: ['admin-reports-summary', isEmployeeSelfReport, summaryParams],
    queryFn: async () => {
      const fetchSummary = isEmployeeSelfReport ? getEmployeeReportsSummary : getAdminReportsSummary
      return fetchSummary(summaryParams)
    },
  })

  const detailedQuery = useQuery({
    queryKey: ['admin-reports-detailed', isEmployeeSelfReport, summaryParams],
    enabled: tab === 'detailed',
    queryFn: async () => {
      const fetchDetailed = isEmployeeSelfReport ? getEmployeeReportsDetailed : getAdminReportsDetailed
      return fetchDetailed(summaryParams)
    },
  })

  const premiumQuery = useQuery({
    queryKey: ['admin-reports-premium', summaryParams],
    enabled: tab === 'premium' && showPayrollReports && !isEmployeeSelfReport,
    queryFn: () => getAdminPremiumReport(summaryParams),
  })

  const filterEmployeesQuery = useQuery({
    queryKey: ['admin-reports-filter-employees', isEmployeeSelfReport],
    enabled: !isEmployeeSelfReport,
    queryFn: () => getEmployees({ per_page: 200 }),
  })

  async function load() {
    setLoading(true)
    setReportError(null)
    const { from, to } = effectiveDateRange()
    try {
      const q = await summaryQuery.refetch()
      const res = q.data || {}
      setData({
        employees: res.employees || [],
        departments: res.departments || [],
        from_date: res.from_date ?? from,
        to_date: res.to_date ?? to,
      })
    } catch (err) {
      setReportError(err instanceof Error ? err.message : 'Failed to load report')
      setData({
        employees: [],
        departments: [],
        from_date: from,
        to_date: to,
      })
    } finally {
      setLoading(false)
    }
  }

  async function loadDetailed() {
    const { from, to } = effectiveDateRange()
    setReportError(null)

    // Request key so only the latest response updates state (avoids race where "All" overwrites "Kurt").
    const requestKey = `${from}|${to}|${companyId}|${employeeId}`
    detailedRequestKeyRef.current = requestKey

    try {
      const q = await detailedQuery.refetch()
      const res = q.data || {}

      if (detailedRequestKeyRef.current !== requestKey) return

      let rawRows = Array.isArray(res.rows) ? res.rows : []
      // Company filter is applied server-side; no client-side re-filter needed (rows already scoped).
      if (employeeId !== 'all') {
        const requestedId = Number(employeeId)
        if (!Number.isNaN(requestedId)) {
          rawRows = rawRows.filter(
            (row) => Number(row.employee_id ?? row.id) === requestedId
          )
        }
      }
      // One row per employee per date (backend already dedupes; guard against duplicates).
      const seen = new Set()
      const rows = rawRows.filter((row) => {
        const key = `${row.employee_id ?? row.id}|${row.date || ''}`
        if (seen.has(key)) return false
        seen.add(key)
        return true
      })
      setDetailedRows(rows)
    } catch (err) {
      if (detailedRequestKeyRef.current !== requestKey) return
      setReportError(err instanceof Error ? err.message : 'Failed to load detailed report')
      setDetailedRows([])
    }
  }

  async function loadPremium() {
    const { from, to } = effectiveDateRange()
    setPremiumLoading(true)
    setReportError(null)
    try {
      const q = await premiumQuery.refetch()
      const res = q.data || {}
      setPremiumData({
        employees: res.employees || [],
        from_date: res.from_date ?? from,
        to_date: res.to_date ?? to,
      })
    } catch (err) {
      setReportError(err instanceof Error ? err.message : 'Failed to load premium report')
      setPremiumData({ employees: [], from_date: from, to_date: to })
    } finally {
      setPremiumLoading(false)
    }
  }

  // Sync dropdown options from query cache.
  useEffect(() => {
    if (isEmployeeSelfReport) {
      setFilterEmployees([])
      return undefined
    }
    const list = Array.isArray(filterEmployeesQuery.data?.employees) ? filterEmployeesQuery.data.employees : []
    const active = list.filter((e) => e.is_active !== false)
    setFilterEmployees(active)
  }, [isEmployeeSelfReport, filterEmployeesQuery.data])

  useEffect(() => {
    const { from, to } = effectiveDateRange()
    if (summaryQuery.data) {
      const res = summaryQuery.data
      setData({
        employees: res.employees || [],
        departments: res.departments || [],
        from_date: res.from_date ?? from,
        to_date: res.to_date ?? to,
      })
      setLoading(false)
      setReportError(null)
    } else if (summaryQuery.error) {
      setReportError(summaryQuery.error?.message || 'Failed to load report')
      setLoading(false)
    } else if (summaryQuery.isLoading) {
      setLoading(true)
    }
  }, [summaryQuery.data, summaryQuery.error, summaryQuery.isLoading, fromDate, toDate])

  useEffect(() => {
    if (tab !== 'detailed') return
    if (detailedQuery.data) {
      const rawRows = Array.isArray(detailedQuery.data.rows) ? detailedQuery.data.rows : []
      setDetailedRows(rawRows)
      setReportError(null)
    } else if (detailedQuery.error) {
      setReportError(detailedQuery.error?.message || 'Failed to load detailed report')
      setDetailedRows([])
    }
  }, [tab, detailedQuery.data, detailedQuery.error])

  useEffect(() => {
    if (tab !== 'premium') return
    const { from, to } = effectiveDateRange()
    if (premiumQuery.data) {
      const res = premiumQuery.data
      setPremiumData({
        employees: res.employees || [],
        from_date: res.from_date ?? from,
        to_date: res.to_date ?? to,
      })
      setPremiumLoading(false)
      setReportError(null)
    } else if (premiumQuery.error) {
      setReportError(premiumQuery.error?.message || 'Failed to load premium report')
      setPremiumData({ employees: [], from_date: from, to_date: to })
      setPremiumLoading(false)
    } else if (premiumQuery.isLoading || premiumQuery.isFetching) {
      setPremiumLoading(true)
    }
  }, [tab, premiumQuery.data, premiumQuery.error, premiumQuery.isLoading, premiumQuery.isFetching, fromDate, toDate])

  useEffect(() => {
    setPage(1)
  }, [tab, fromDate, toDate, companyId, employeeId, search])

  // Filter dropdowns: build unique companies list from filterEmployees using actual company data.
  const companiesOptions = useMemo(() => {
    const map = new Map() // id -> name
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

  function currentColumns() {
    const emp = (row) =>
      formatEmploymentStatusForViewer(row.employment_status, row.employment_status_label, viewerIsAdminHr)
    const base = [
      { label: 'Employee', accessor: 'employee_name' },
      { label: 'Company', accessor: (row) => row.company_name || '—' },
      { label: 'Department', accessor: (row) => row.department || 'No Department Assigned' },
      { label: 'Employment status', accessor: emp },
      { label: 'Hire date', accessor: (row) => row.hire_date || '—' },
    ]
    if (tab === 'detailed') {
      const num = (align = 'right') => ({ minW: 90, align })
      const txt = (minW = 100, align = 'left') => ({ minW, align })
      const detailedStatusCol = {
        label: 'Status',
        accessor: (row) =>
          row.status === 'undertime'
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
                          : row.status || '—',
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
      const overtimeStatusCol = { label: 'Overtime Status', accessor: (row) => row.overtime_status || '—', minW: 110, align: 'center' }

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
        { label: 'Schedule', accessor: (row) => row.schedule || '—', ...txt(110) },
        { label: 'Time In', accessor: (row) => (row.time_in ? formatTimeTo12Hour(row.time_in) : '—'), minW: 90, align: 'center' },
        { label: 'Time Out', accessor: (row) => (row.time_out ? formatTimeTo12Hour(row.time_out) : '—'), minW: 90, align: 'center' },
        { label: 'Total Hours', accessor: (row) => (row.total_hours != null ? row.total_hours.toFixed(2) : '—'), ...num() },
        { label: 'Late (min)', accessor: (row) => (row.late_minutes != null ? String(row.late_minutes) : '0'), ...num() },
        { label: 'Undertime (min)', accessor: (row) => (row.undertime_minutes != null ? String(row.undertime_minutes) : '0'), ...num() },
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
        { label: 'Schedule', accessor: (row) => row.schedule || '—', ...txt(110) },
        { label: 'Time In', accessor: (row) => (row.time_in ? formatTimeTo12Hour(row.time_in) : '—'), minW: 90, align: 'center' },
        { label: 'Time Out', accessor: (row) => (row.time_out ? formatTimeTo12Hour(row.time_out) : '—'), minW: 90, align: 'center' },
        {
          label: 'Early Out Time',
          accessor: (row) =>
            row.early_out_time
              ? formatTimeTo12Hour(row.early_out_time)
              : row.status === 'undertime' && row.time_out
                ? formatTimeTo12Hour(row.time_out)
                : '—',
          minW: 100,
          align: 'center',
        },
        { label: 'Total Hours', accessor: (row) => (row.total_hours != null ? row.total_hours.toFixed(2) : '—'), ...num() },
        { label: 'Late (min)', accessor: (row) => (row.late_minutes != null ? String(row.late_minutes) : '0'), ...num() },
        { label: 'Undertime (min)', accessor: (row) => (row.undertime_minutes != null ? String(row.undertime_minutes) : '0'), ...num() },
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
    if (tab === 'late') {
      return [
        ...base,
        { label: 'Total Late Count', accessor: (row) => formatNumber(row.late_count) },
        { label: 'Total Late Minutes', accessor: (row) => formatNumber(row.late_minutes) },
      ]
    }
    if (tab === 'undertime') {
      return [
        ...base,
        { label: 'Total Undertime Count', accessor: (row) => formatNumber(row.undertime_count) },
      ]
    }
    if (tab === 'halfday') {
      return [
        ...base,
        { label: 'Half Day Count', accessor: (row) => formatNumber(row.halfday_count) },
      ]
    }
    if (tab === 'absences') {
      return [
        ...base,
        { label: 'Absences Count', accessor: (row) => formatNumber(row.absent_count) },
      ]
    }
    if (tab === 'overtime') {
      return [
        ...base,
        { label: 'Overtime Count', accessor: (row) => formatNumber(row.overtime_count ?? 0) },
        { label: 'Overtime Hours', accessor: (row) => (row.overtime_hours ?? 0).toFixed(2) },
      ]
    }
    if (tab === 'premium') {
      return [
        { label: 'Employee', accessor: 'employee_name' },
        { label: 'OT Pay (₱)', accessor: (row) => formatNumber(row.summary?.total_overtime_pay ?? 0) },
        { label: 'ND Pay (₱)', accessor: (row) => formatNumber(row.summary?.total_night_differential_pay ?? 0) },
        { label: 'Regular Holiday (₱)', accessor: (row) => formatNumber(row.summary?.total_regular_holiday_pay ?? 0) },
        { label: 'Special Holiday (₱)', accessor: (row) => formatNumber(row.summary?.total_special_holiday_pay ?? 0) },
        { label: 'Rest Day Premium (₱)', accessor: (row) => formatNumber(row.summary?.total_rest_day_premium_pay ?? 0) },
        { label: 'Combined Premiums (₱)', accessor: (row) => formatNumber(row.summary?.total_combined_premiums_pay ?? 0) },
        { label: 'Total Premium (₱)', accessor: (row) => formatNumber(row.summary?.total_premium_pay ?? 0) },
        { label: 'Days w/ Attendance', accessor: (row) => formatNumber(row.summary?.days_with_attendance ?? 0) },
      ]
    }
    if (tab === 'department') {
      return [
        { label: 'Department', accessor: (row) => row.department || 'No Department Assigned' },
        {
          label: 'Attendance Rate %',
          accessor: (row) => (row.attendance_rate_percent ?? 0).toFixed(2),
        },
        {
          label: 'Total Hours Rendered',
          accessor: (row) => (row.total_hours ?? 0).toFixed(2),
        },
        {
          label: 'Most Late Employees',
          accessor: (row) =>
            (row.most_late_employees || [])
              .map((e) => `${e.employee_name} (${e.late_count})`)
              .join(', ') || '—',
        },
      ]
    }
    return [
      ...base,
      { label: 'Present Days', accessor: (row) => formatNumber(row.present_count) },
      { label: 'Late Days', accessor: (row) => formatNumber(row.late_count) },
      { label: 'Absences', accessor: (row) => formatNumber(row.absent_count) },
      { label: 'Half Days', accessor: (row) => formatNumber(row.halfday_count) },
      { label: 'Undertime Days', accessor: (row) => formatNumber(row.undertime_count) },
      { label: 'Total Hours', accessor: (row) => (row.total_hours ?? 0).toFixed(2) },
    ]
  }

  const [exportingPdf, setExportingPdf] = useState(false)
  const [exportingExcel, setExportingExcel] = useState(false)

  const rowsForTable = useMemo(() => {
    // Start from the base dataset for the active tab. Always return an array.
    if (tab === 'department') {
      return Array.isArray(data.departments) ? data.departments : []
    }

    if (tab === 'premium') {
      let rows = Array.isArray(premiumData.employees) ? premiumData.employees : []
      if (employeeId !== 'all') {
        const empIdNum = Number(employeeId)
        if (!Number.isNaN(empIdNum)) {
          rows = rows.filter((r) => Number(r.employee_id) === empIdNum)
        }
      }
      return rows
    }

    // Detailed tab: apply same strict AND filter as PDF/Excel (date + department + employee).
    // Use selected employee's name from dropdown as source of truth so only that person's rows show.
    // Dedupe by employee_id|date so we never show duplicate day rows (e.g. from race or stale state).
    if (tab === 'detailed') {
      let rows = Array.isArray(detailedRows) ? detailedRows : []
      // Company filter is applied server-side; detailed rows are already scoped to the selected company.
      if (employeeId !== 'all') {
        const selected = employeesOptions.find((o) => String(o.id) === String(employeeId))
        const matchByName = selected?.name?.trim()
        const matchById = Number(employeeId)
        rows = rows.filter((row) => {
          if (matchByName && (row.employee_name || '').trim() === matchByName) return true
          return !Number.isNaN(matchById) && Number(row.employee_id ?? row.id) === matchById
        })
      }
      const seen = new Set()
      rows = rows.filter((row) => {
        const key = `${row.employee_id ?? row.id}|${row.date || ''}`
        if (seen.has(key)) return false
        seen.add(key)
        return true
      })
      return rows
    }

    // For summary-type reports, only include employees with meaningful values
    // for the specific metric to avoid rows of all zeros/placeholders.
    let base = Array.isArray(data.employees) ? data.employees : []
    if (tab === 'late') {
      base = base.filter((e) => (e.late_count || 0) > 0 || (e.late_minutes || 0) > 0)
    } else if (tab === 'undertime') {
      base = base.filter((e) => (e.undertime_count || 0) > 0)
    } else if (tab === 'halfday') {
      base = base.filter((e) => (e.halfday_count || 0) > 0)
    } else if (tab === 'absences') {
      base = base.filter((e) => (e.absent_count || 0) > 0)
    } else if (tab === 'overtime') {
      base = base.filter((e) => (e.overtime_count || 0) > 0 || (e.overtime_hours || 0) > 0)
    }

    // If an employee filter is active (and we're not in the department tab),
    // further restrict the summary rows to that employee only.
    if (employeeId !== 'all') {
      const empIdNum = Number(employeeId)
      if (!Number.isNaN(empIdNum)) {
        base = base.filter((row) => {
          const rowEmpId = row.employee_id ?? row.id
          return Number(rowEmpId) === empIdNum
        })
      }
    }

    return base
  }, [tab, data.departments, data.employees, detailedRows, premiumData.employees, employeeId, employeesOptions])

  const filteredRows = useMemo(() => {
    const rows = Array.isArray(rowsForTable) ? rowsForTable : []
    const term = search.trim().toLowerCase()
    if (!term) return rows
    return rows.filter((row) => {
      if (tab === 'department') {
        return (row.department || '').toLowerCase().includes(term)
      }
      const name = (row.employee_name || '').toLowerCase()
      const dept = (row.department || '').toLowerCase()
      const company = (row.company_name || '').toLowerCase()
      return name.includes(term) || dept.includes(term) || company.includes(term)
    })
  }, [rowsForTable, search, tab])

  const pageCount = Math.max(1, Math.ceil(filteredRows.length / pageSize))
  const currentPage = Math.min(page, pageCount)
  const paginatedRows = filteredRows.slice(
    (currentPage - 1) * pageSize,
    currentPage * pageSize
  )


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
    const cols = currentColumns()
    const rows = filteredRows
    const title = tab === 'detailed' ? 'Detailed Attendance Report' : getReportTitle(tab)
    const period = periodLabel || `${fromDate} to ${toDate}`
    const generatedAt = new Date().toLocaleString()
    const filtersSummary = buildFiltersSummary()
    debugLog(
      'handleExportPdf',
      {
        tab,
        rowsCount: rows.length,
        filteredRowsCount: filteredRows.length,
        colsCount: cols.length,
      },
      'H1'
    )
    debugSessionLog(
      'handleExportPdf columns snapshot',
      {
        tab,
        columnLabels: cols.map((c) => c.label),
        rowsCount: rows.length,
      },
      'H3'
    )
    // Match UI summary: same filters (date range, company, employee) and formulas.
    const exportTotalHours = rows.reduce((sum, r) => sum + (Number(r.total_hours) || 0), 0)
    const exportTotalAbsences =
      tab === 'detailed'
        ? rows.filter((r) => r.status === 'absent' || r.status === 'leave').length
        : rows.reduce((sum, r) => sum + (Number(r.absent_count) || 0), 0)

    const exportTotalLates =
      tab === 'detailed'
        ? rows.filter((r) => r.status === 'late').length
        : rows.reduce((sum, r) => sum + (Number(r.late_count) || 0), 0)

    const exportTotalOvertime =
      tab === 'detailed'
        ? rows.reduce((sum, r) => sum + (Number(r.overtime_minutes) || 0), 0) / 60
        : rows.reduce((sum, r) => sum + (Number(r.overtime_hours) || 0), 0)

    setExportingPdf(true)
    try {
      const doc = (
        <ReportPdfDocument
          title={title}
          period={period}
          rows={rows}
          columns={cols}
          generatedAt={generatedAt}
          filtersSummary={filtersSummary}
          subtitle={tab === 'detailed' ? 'Detailed per-day attendance records' : undefined}
          orientation={tab === 'detailed' ? 'landscape' : 'portrait'}
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
      a.download = `report-${tab}-${data.from_date || fromDate}-${data.to_date || toDate}.pdf`
      document.body.appendChild(a)
      a.click()
      document.body.removeChild(a)
      URL.revokeObjectURL(url)
    } finally {
      setExportingPdf(false)
    }
  }

  async function handleExportExcel() {
    const cols = currentColumns()
    const rows = filteredRows
    // Match UI: same filters and formulas as Reports summary.
    const exportTotalHours = rows.reduce((sum, r) => sum + (Number(r.total_hours) || 0), 0)
    const exportTotalAbsences =
      tab === 'detailed'
        ? rows.filter((r) => r.status === 'absent' || r.status === 'leave').length
        : rows.reduce((sum, r) => sum + (Number(r.absent_count) || 0), 0)

    setExportingExcel(true)
    try {
      const colCount = cols.length
      const pad = (n) => Array(n).fill('')
      const headerRow = cols.map((c) => c.label)
      const dataRows = rows.map((row) =>
        cols.map((c) => {
          const raw = typeof c.accessor === 'function' ? c.accessor(row) : row[c.accessor]
          return raw != null ? raw : ''
        })
      )
      // Summary at bottom right: pad left so label and value sit in last two columns
      const summaryRowsBottom = [
        [...pad(colCount)],
        [...pad(Math.max(0, colCount - 2)), 'Summary', ''],
        [...pad(Math.max(0, colCount - 2)), 'Total Hours Rendered', exportTotalHours.toFixed(2)],
        [...pad(Math.max(0, colCount - 2)), 'Total Absences', exportTotalAbsences],
      ]
      const filename = `report-${tab}-${data.from_date || fromDate}-${data.to_date || toDate}.xlsx`
      await exportRowsToXlsx(
        headerRow,
        [...dataRows, ...summaryRowsBottom],
        filename,
        getReportTitle(tab).slice(0, 31) || 'Report',
      )
    } finally {
      setExportingExcel(false)
    }
  }

  const cols = currentColumns()
  const hasRows = filteredRows.length > 0

  useEffect(() => {
    debugSessionLog(
      'currentColumns snapshot',
      {
        tab,
        columnLabels: cols.map((c) => c.label),
      },
      'H1'
    )
  }, [tab, cols.length])

  useEffect(() => {
    debugSessionLog(
      'rowsForTable snapshot',
      {
        tab,
        totalRows: rowsForTable.length,
        sampleRowKeys:
          rowsForTable.length > 0 ? Object.keys(rowsForTable[0]).slice(0, 12) : [],
      },
      'H2'
    )
  }, [tab, rowsForTable.length])

  const summary = useMemo(() => {
    const allRows = filteredRows
    const totalCount = allRows.length
    const visibleRows = paginatedRows

    // Use the same filtered dataset for counts and totals (table, PDF, Excel).
    if (tab === 'detailed') {
      const totalHours = allRows.reduce((sum, r) => sum + (r.total_hours || 0), 0)
      return {
        primary: { label: 'Total records', value: totalCount },
        secondary: [
          { label: 'Total hours rendered', value: totalHours.toFixed(2) },
        ],
      }
    }

    // If nothing in the table, surface zeros.
    if (!visibleRows.length) {
      return {
        primary: { label: 'Records in table', value: 0 },
        secondary: [],
      }
    }

    if (tab === 'late') {
      const totalLate = allRows.reduce((sum, r) => sum + (r.late_count || 0), 0)
      const totalLateMinutes = allRows.reduce((sum, r) => sum + (r.late_minutes || 0), 0)
      const avgLatePerEmployee = totalCount ? totalLate / totalCount : 0
      return {
        primary: { label: 'Records in table', value: totalCount },
        secondary: [
          { label: 'Total late instances', value: totalLate },
          { label: 'Total late minutes', value: totalLateMinutes },
          { label: 'Avg late per employee', value: avgLatePerEmployee.toFixed(2) },
        ],
      }
    }

    if (tab === 'absences') {
      const totalAbsences = allRows.reduce((sum, r) => sum + (r.absent_count || 0), 0)
      const employeesWithAbsences = allRows.filter((r) => (r.absent_count || 0) > 0).length
      return {
        primary: { label: 'Records in table', value: totalCount },
        secondary: [
          { label: 'Total absences', value: totalAbsences },
          { label: 'Employees with absences', value: employeesWithAbsences },
          { label: 'Employees in report', value: totalCount },
        ],
      }
    }

    if (tab === 'undertime') {
      const totalUndertime = allRows.reduce((sum, r) => sum + (r.undertime_count || 0), 0)
      const undertimeHours = allRows.reduce((sum, r) => sum + (r.undertime_hours || 0), 0)
      return {
        primary: { label: 'Records in table', value: totalCount },
        secondary: [
          { label: 'Total undertime instances', value: totalUndertime },
          { label: 'Total undertime hours', value: undertimeHours.toFixed(2) },
          { label: 'Employees in report', value: totalCount },
        ],
      }
    }

    if (tab === 'halfday') {
      const totalHalfday = allRows.reduce((sum, r) => sum + (r.halfday_count || 0), 0)
      return {
        primary: { label: 'Records in table', value: totalCount },
        secondary: [
          { label: 'Total halfday records', value: totalHalfday },
          { label: 'Employees in report', value: totalCount },
        ],
      }
    }

    if (tab === 'overtime') {
      const totalOvertimeHours = allRows.reduce((sum, r) => sum + (r.overtime_hours || 0), 0)
      const totalOvertimeDays = allRows.reduce((sum, r) => sum + (r.overtime_count || 0), 0)
      return {
        primary: { label: 'Records in table', value: totalCount },
        secondary: [
          { label: 'Total overtime hours', value: totalOvertimeHours.toFixed(2) },
          { label: 'Total overtime instances', value: totalOvertimeDays },
          { label: 'Employees in report', value: totalCount },
        ],
      }
    }

    if (tab === 'department') {
      const avgAttendanceRate =
        allRows.length > 0
          ? allRows.reduce((sum, r) => sum + (r.attendance_rate_percent || 0), 0) / allRows.length
          : 0
      return {
        primary: { label: 'Records in table', value: totalCount },
        secondary: [
          { label: 'Total companies', value: totalCount },
          { label: 'Avg attendance rate %', value: avgAttendanceRate.toFixed(2) },
        ],
      }
    }

    if (tab === 'premium') {
      // No aggregate KPI strip for Premium Pay — details stay in the table only.
      return {
        primary: { label: '', value: '' },
        secondary: [],
      }
    }

    // Monthly summary
    const totalHours = allRows.reduce((sum, r) => sum + (r.total_hours || 0), 0)
    const totalLateMinutes = allRows.reduce((sum, r) => sum + (r.late_minutes || 0), 0)
    const totalAbsences = allRows.reduce((sum, r) => sum + (r.absent_count || 0), 0)
    return {
      primary: { label: 'Records in table', value: totalCount },
      secondary: [
        { label: 'Total hours rendered', value: totalHours.toFixed(2) },
        { label: 'Total late minutes', value: totalLateMinutes },
        { label: 'Total absences', value: totalAbsences },
      ],
    }
  }, [filteredRows, paginatedRows, tab])

  return (
    <div className="space-y-6">
      <div className="flex flex-col gap-2 @md:flex-row @md:items-center @md:justify-between">
        <div>
          <h1 className="hr-page-title">Reports</h1>
          <p className="text-sm text-muted-foreground">
            {isEmployeeSelfReport
              ? 'Personal attendance, overtime, and leave activity for your account only.'
              : 'Generate late, undertime, half-day, absences, and monthly summary reports per employee.'}
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
                  <Select value={companyId} onValueChange={(v) => { setCompanyId(v); setEmployeeId('all') }}>
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
                load()
                if (tab === 'detailed') loadDetailed()
                if (tab === 'premium') loadPremium()
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
                load()
                if (tab === 'detailed') loadDetailed()
                if (tab === 'premium') loadPremium()
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
          <h3 className="text-base font-semibold text-foreground">Data tables</h3>
        </div>
      <Card className="border border-border/60 bg-card/95 shadow-sm rounded-xl">
        <CardHeader className="flex flex-col gap-3 border-b border-border/40 bg-muted/40 py-4 @md:flex-row @md:items-center @md:justify-between">
          <div>
            <CardTitle className="text-base font-semibold @md:text-lg">Reports</CardTitle>
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
            {tab !== 'premium' && (
              <div className="grid grid-cols-1 gap-2 @md:grid-cols-3 @md:gap-4">
                <div className="rounded-md border border-border/40 bg-muted/20 px-3 py-2">
                  <p className="text-[10px] font-medium uppercase text-muted-foreground">
                    {summary.primary.label}
                  </p>
                  <p className="text-lg font-semibold">
                    {formatNumber(summary.primary.value)}
                  </p>
                </div>
                {summary.secondary.map((item) => (
                  <div
                    key={item.label}
                    className="rounded-md border border-border/40 bg-muted/10 px-3 py-2"
                  >
                    <p className="text-[10px] font-medium uppercase text-muted-foreground">
                      {item.label}
                    </p>
                    <p className="text-sm font-semibold">
                      {typeof item.value === 'number'
                        ? formatNumber(item.value)
                        : item.value}
                    </p>
                  </div>
                ))}
              </div>
            )}
            <div className={tab === 'premium' ? 'w-full @md:ml-auto @md:w-64' : 'w-full @md:w-64'}>
              <Input
                type="text"
                value={search}
                onChange={(e) => setSearch(e.target.value)}
                placeholder={
                  tab === 'department'
                    ? 'Search company...'
                    : 'Search employee or company...'
                }
                className="h-9 text-sm"
              />
            </div>
          </div>
          <Tabs value={tab} onValueChange={setTab}>
            <TabsList className="mb-4 flex flex-wrap gap-2 rounded-full bg-muted/60 p-1">
              <TabsTrigger
                value="detailed"
                className="rounded-full px-3 py-1.5 text-xs @md:text-sm data-[state=active]:bg-background data-[state=active]:shadow-sm"
              >
                Detailed
              </TabsTrigger>
              <TabsTrigger
                value="late"
                className="rounded-full px-3 py-1.5 text-xs @md:text-sm data-[state=active]:bg-background data-[state=active]:shadow-sm"
              >
                Late
              </TabsTrigger>
              <TabsTrigger
                value="undertime"
                className="rounded-full px-3 py-1.5 text-xs @md:text-sm data-[state=active]:bg-background data-[state=active]:shadow-sm"
              >
                Undertime
              </TabsTrigger>
              <TabsTrigger
                value="halfday"
                className="rounded-full px-3 py-1.5 text-xs @md:text-sm data-[state=active]:bg-background data-[state=active]:shadow-sm"
              >
                Half Day
              </TabsTrigger>
              <TabsTrigger
                value="absences"
                className="rounded-full px-3 py-1.5 text-xs @md:text-sm data-[state=active]:bg-background data-[state=active]:shadow-sm"
              >
                Absences
              </TabsTrigger>
              <TabsTrigger
                value="overtime"
                className="rounded-full px-3 py-1.5 text-xs @md:text-sm data-[state=active]:bg-background data-[state=active]:shadow-sm"
              >
                Overtime
              </TabsTrigger>
              {!isEmployeeSelfReport && (
                <TabsTrigger
                  value="department"
                  className="rounded-full px-3 py-1.5 text-xs @md:text-sm data-[state=active]:bg-background data-[state=active]:shadow-sm"
                >
                  Company Summary
                </TabsTrigger>
              )}
              {showPayrollReports && !isEmployeeSelfReport && (
                <TabsTrigger
                  value="premium"
                  className="rounded-full px-3 py-1.5 text-xs @md:text-sm data-[state=active]:bg-background data-[state=active]:shadow-sm"
                >
                  Premium Pay
                </TabsTrigger>
              )}
              <TabsTrigger
                value="summary"
                className="rounded-full px-3 py-1.5 text-xs @md:text-sm data-[state=active]:bg-background data-[state=active]:shadow-sm"
              >
                Monthly Summary
              </TabsTrigger>
            </TabsList>

            <TabsContent value={tab} className="mt-0">
              <div className="overflow-x-auto rounded-xl border border-border/40 bg-background/80">
                <table className="min-w-max w-full text-xs @md:text-sm border-0">
                  <thead>
                    <tr className="sticky top-0 z-10 border-b border-border/40 bg-muted/60">
                      {cols.map((c) => {
                        const align = c.align || 'left'
                        const alignClass = align === 'right' ? 'text-right' : align === 'center' ? 'text-center' : 'text-left'
                        return (
                          <th
                            key={c.label}
                            style={c.minW ? { minWidth: `${c.minW}px` } : undefined}
                            className={`px-4 py-3 whitespace-nowrap ${alignClass} text-[10px] font-semibold uppercase tracking-wide text-muted-foreground @md:text-xs`}
                          >
                            {c.label}
                          </th>
                        )
                      })}
                    </tr>
                  </thead>
                  <tbody>
                    {(() => {
                      const isLoading = tab === 'premium' ? premiumLoading : loading
                      if (isLoading) {
                        return (
                          <TableBodySkeleton
                            rows={8}
                            cols={Math.min(cols.length, 6)}
                          />
                        )
                      }

                      if (!hasRows) {
                        return (
                          <tr>
                            <td
                              colSpan={cols.length}
                              className="px-4 py-8 text-center text-sm text-muted-foreground"
                            >
                              {reportError
                                ? reportError
                                : 'No records for this period and filters.'}
                            </td>
                          </tr>
                        )
                      }

                      return paginatedRows.map((row, rowIndex) => {
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

                        const isWeekend =
                          tab === 'detailed' && row.date ? isWeekendDate(row.date) : false
                        return (
                          <tr
                            key={
                              tab === 'detailed' && row.date
                                ? `${row.employee_id ?? row.id}-${row.date}`
                                : (row.employee_id ?? row.id ?? row.department ?? rowIndex)
                            }
                            className={`border-b border-border/20 hover:bg-muted/40 transition-colors ${
                              isWeekend ? 'bg-muted/30 text-muted-foreground' : ''
                            }`}
                          >
                            {cols.map((c) => {
                              const raw =
                                typeof c.accessor === 'function'
                                  ? c.accessor(row)
                                  : row[c.accessor]
                              const value =
                                raw != null && raw !== ''
                                  ? (typeof raw === 'number' ? String(raw) : String(raw))
                                  : '—'
                              const isEmployeeCol = c.label === 'Employee' && (employeeName || raw)
                              const isStatusCol = c.label === 'Status' && tab === 'detailed'
                              const showDeptSubLabel = tab === 'detailed' && row.department

                              const align = c.align || 'left'
                              const alignClass = align === 'right' ? 'text-right' : align === 'center' ? 'text-center' : 'text-left'
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
                                    <AttendanceStatusBadge status={row.status} label={value} />
                                  ) : (
                                    <span className="whitespace-nowrap text-xs @md:text-sm">
                                      {value}
                                    </span>
                                  )}
                                </td>
                              )
                            })}
                          </tr>
                        )
                      })
                    })()}
                  </tbody>
                </table>
                {hasRows && (
                  <div className="flex items-center justify-between border-t border-border/40 px-4 py-3 text-[11px] text-muted-foreground @md:text-xs">
                    <span>
                      Page {currentPage} of {pageCount}
                    </span>
                    <div className="flex gap-2">
                      <Button
                        type="button"
                        size="xs"
                        variant="outline"
                        disabled={currentPage <= 1}
                        onClick={() => setPage((p) => Math.max(1, p - 1))}
                      >
                        Previous
                      </Button>
                      <Button
                        type="button"
                        size="xs"
                        variant="outline"
                        disabled={currentPage >= pageCount}
                        onClick={() => setPage((p) => Math.min(pageCount, p + 1))}
                      >
                        Next
                      </Button>
                    </div>
                  </div>
                )}
              </div>
            </TabsContent>
          </Tabs>
        </CardContent>
      </Card>
      </div>
    </div>
  )
}

