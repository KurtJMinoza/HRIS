import { useEffect, useMemo, useState } from 'react'
import { useQuery, keepPreviousData } from '@tanstack/react-query'
import {
  Calendar,
  ChevronLeft,
  ChevronRight,
  Download,
  RefreshCw,
  ScrollText,
  Search,
} from 'lucide-react'
import { Card, CardContent, CardDescription, CardHeader, CardTitle } from '@/components/ui/card'
import { Button } from '@/components/ui/button'
import { Input } from '@/components/ui/input'
import { Select, SelectContent, SelectItem, SelectTrigger, SelectValue } from '@/components/ui/select'
import {
  Dialog,
  DialogContent,
  DialogDescription,
  DialogHeader,
  DialogTitle,
} from '@/components/ui/dialog'
import {
  attendanceFilterInputClass,
  attendanceOutlineButtonSmClass,
  attendancePaginationActiveClass,
  attendancePrimaryButtonSmClass,
  attendanceSelectContentClass,
  attendanceSelectItemClass,
  attendanceSelectTriggerClass,
} from '@/lib/attendanceUiClasses'
import {
  ADMIN_ATTENDANCE_PAGE_SIZE,
  ATTENDANCE_PAGE_SIZE_OPTIONS,
  exportAdminEmployeeLogs,
  getAdminEmployeeLog,
  getAdminEmployeeLogs,
  getEmployees,
  normalizeAttendancePerPage,
  profileImageUrl,
} from '@/api'
import { formatEmployeeName } from '@/lib/employeeSort'
import { cn } from '@/lib/utils'

const CATEGORY_OPTIONS = [
  { value: 'all', label: 'All activity' },
  { value: 'auth', label: 'Sign in / out' },
  { value: 'navigation', label: 'Navigation' },
  { value: 'attendance', label: 'Attendance' },
  { value: 'leave', label: 'Leave' },
  { value: 'overtime', label: 'Overtime' },
  { value: 'correction', label: 'Corrections' },
  { value: 'schedule', label: 'Schedule' },
  { value: 'loan', label: 'Loans' },
  { value: 'account', label: 'Account' },
]

const CATEGORY_BADGE_CLASS = {
  auth: 'border-blue-200 bg-blue-50 text-blue-700 dark:border-blue-500/30 dark:bg-blue-500/10 dark:text-blue-300',
  navigation: 'border-cyan-200 bg-cyan-50 text-cyan-800 dark:border-cyan-500/30 dark:bg-cyan-500/10 dark:text-cyan-200',
  attendance: 'border-emerald-200 bg-emerald-50 text-emerald-700 dark:border-emerald-500/30 dark:bg-emerald-500/10 dark:text-emerald-300',
  leave: 'border-sky-200 bg-sky-50 text-sky-700 dark:border-sky-500/30 dark:bg-sky-500/10 dark:text-sky-300',
  overtime: 'border-violet-200 bg-violet-50 text-violet-700 dark:border-violet-500/30 dark:bg-violet-500/10 dark:text-violet-300',
  correction: 'border-amber-200 bg-amber-50 text-amber-800 dark:border-amber-500/30 dark:bg-amber-500/10 dark:text-amber-200',
  schedule: 'border-indigo-200 bg-indigo-50 text-indigo-700 dark:border-indigo-500/30 dark:bg-indigo-500/10 dark:text-indigo-300',
  loan: 'border-rose-200 bg-rose-50 text-rose-700 dark:border-rose-500/30 dark:bg-rose-500/10 dark:text-rose-300',
  account: 'border-slate-200 bg-slate-50 text-slate-700 dark:border-slate-500/30 dark:bg-slate-500/10 dark:text-slate-300',
}

function getLocalDateString(d = new Date()) {
  const y = d.getFullYear()
  const m = String(d.getMonth() + 1).padStart(2, '0')
  const day = String(d.getDate()).padStart(2, '0')
  return `${y}-${m}-${day}`
}

function defaultFromDate() {
  const d = new Date()
  d.setDate(d.getDate() - 29)
  return getLocalDateString(d)
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

function DetailRow({ label, value }) {
  if (value == null || value === '') return null
  return (
    <div className="flex items-start justify-between gap-4 border-b border-border/60 py-2.5 text-sm last:border-0">
      <span className="text-muted-foreground">{label}</span>
      <span className="max-w-[65%] whitespace-pre-wrap text-right font-medium text-foreground">{value}</span>
    </div>
  )
}

function statusBadgeClass(status) {
  const s = String(status || '').toLowerCase()
  if (s.includes('approv')) return 'border-emerald-200 bg-emerald-50 text-emerald-700'
  if (s.includes('reject') || s.includes('denied')) return 'border-rose-200 bg-rose-50 text-rose-700'
  if (s.includes('pending')) return 'border-amber-200 bg-amber-50 text-amber-800'
  return 'border-border bg-muted/40 text-muted-foreground'
}

export default function AdminEmployeeLogs() {
  const [fromDate, setFromDate] = useState(defaultFromDate)
  const [toDate, setToDate] = useState(() => getLocalDateString())
  const [search, setSearch] = useState('')
  const [debouncedSearch, setDebouncedSearch] = useState('')
  const [employeeId, setEmployeeId] = useState('all')
  const [category, setCategory] = useState('all')
  const [page, setPage] = useState(1)
  const [perPage, setPerPage] = useState(ADMIN_ATTENDANCE_PAGE_SIZE)
  const [selectedRef, setSelectedRef] = useState(null)
  const [exporting, setExporting] = useState(false)

  useEffect(() => {
    const timer = setTimeout(() => setDebouncedSearch(search.trim()), 300)
    return () => clearTimeout(timer)
  }, [search])

  useEffect(() => {
    setPage(1)
  }, [fromDate, toDate, debouncedSearch, employeeId, category, perPage])

  const queryParams = useMemo(() => ({
    from_date: fromDate,
    to_date: toDate,
    search: debouncedSearch || undefined,
    employee_id: employeeId !== 'all' ? employeeId : undefined,
    category: category !== 'all' ? category : undefined,
    page,
    per_page: perPage,
  }), [fromDate, toDate, debouncedSearch, employeeId, category, page, perPage])

  const logsQuery = useQuery({
    queryKey: ['admin-employee-logs', queryParams],
    queryFn: ({ signal }) => getAdminEmployeeLogs({ ...queryParams, signal }),
    placeholderData: keepPreviousData,
  })

  const employeesQuery = useQuery({
    queryKey: ['admin-employee-logs-employees'],
    queryFn: () => getEmployees({ per_page: 500, active_filter: 'active' }),
    staleTime: 5 * 60 * 1000,
  })

  const detailQuery = useQuery({
    queryKey: ['admin-employee-log', selectedRef],
    queryFn: () => getAdminEmployeeLog(selectedRef),
    enabled: selectedRef != null,
  })

  const rows = logsQuery.data?.rows ?? []
  const meta = logsQuery.data?.meta ?? {}
  const categoryCounts = meta.category_counts ?? {}
  const lastPage = Math.max(1, Number(meta.last_page) || 1)
  const total = Number(meta.total) || 0

  const employeeOptions = useMemo(() => {
    const list = employeesQuery.data?.employees ?? []
    return Array.isArray(list) ? list : []
  }, [employeesQuery.data])

  async function handleExport() {
    setExporting(true)
    try {
      const blob = await exportAdminEmployeeLogs(queryParams)
      const url = URL.createObjectURL(blob)
      const link = document.createElement('a')
      link.href = url
      link.download = `employee-activity_${fromDate}_${toDate}.csv`
      link.click()
      URL.revokeObjectURL(url)
    } finally {
      setExporting(false)
    }
  }

  const detail = detailQuery.data

  return (
    <div className="mx-auto w-full max-w-[1400px] space-y-5 p-4 @md:p-6">
      <div className="flex flex-col gap-3 @md:flex-row @md:items-start @md:justify-between">
        <div>
          <h1 className="flex items-center gap-2 text-2xl font-bold tracking-tight text-foreground">
            <ScrollText className="size-6 text-orange-600" aria-hidden />
            Employee Logs
          </h1>
          <p className="mt-1 max-w-2xl text-sm text-muted-foreground">
            Detailed trail of employee sessions — sign-ins, module navigation, page visits, plus requests and attendance actions.
          </p>
        </div>
        <div className="flex flex-wrap gap-2">
          <Button type="button" variant="outline" size="sm" className={attendanceOutlineButtonSmClass} onClick={() => logsQuery.refetch()} disabled={logsQuery.isFetching}>
            <RefreshCw className={cn('size-4', logsQuery.isFetching && 'animate-spin')} />
            Refresh
          </Button>
          <Button type="button" size="sm" className={attendancePrimaryButtonSmClass} onClick={handleExport} disabled={exporting || logsQuery.isLoading}>
            <Download className="size-4" />
            {exporting ? 'Exporting…' : 'Export CSV'}
          </Button>
        </div>
      </div>

      <Card className="border-border/70 shadow-sm">
        <CardHeader className="pb-3">
          <CardTitle className="text-base">Filters</CardTitle>
          <CardDescription>Default range is the last 30 days.</CardDescription>
        </CardHeader>
        <CardContent className="space-y-4">
          <div className="grid gap-3 @md:grid-cols-2 @xl:grid-cols-4">
            <label className="space-y-1.5 text-xs font-medium text-muted-foreground">
              From
              <div className="relative">
                <Calendar className="pointer-events-none absolute left-2.5 top-1/2 size-4 -translate-y-1/2 text-muted-foreground" />
                <Input type="date" value={fromDate} onChange={(e) => setFromDate(e.target.value)} className={attendanceFilterInputClass} />
              </div>
            </label>
            <label className="space-y-1.5 text-xs font-medium text-muted-foreground">
              To
              <div className="relative">
                <Calendar className="pointer-events-none absolute left-2.5 top-1/2 size-4 -translate-y-1/2 text-muted-foreground" />
                <Input type="date" value={toDate} onChange={(e) => setToDate(e.target.value)} className={attendanceFilterInputClass} />
              </div>
            </label>
            <label className="space-y-1.5 text-xs font-medium text-muted-foreground">
              Employee
              <Select value={employeeId} onValueChange={setEmployeeId}>
                <SelectTrigger className={attendanceSelectTriggerClass}>
                  <SelectValue placeholder="All employees" />
                </SelectTrigger>
                <SelectContent className={attendanceSelectContentClass}>
                  <SelectItem value="all" className={attendanceSelectItemClass}>All employees</SelectItem>
                  {employeeOptions.map((emp) => (
                    <SelectItem key={emp.id} value={String(emp.id)} className={attendanceSelectItemClass}>
                      {formatEmployeeName(emp)}{emp.employee_code ? ` (${emp.employee_code})` : ''}
                    </SelectItem>
                  ))}
                </SelectContent>
              </Select>
            </label>
            <label className="space-y-1.5 text-xs font-medium text-muted-foreground">
              Category
              <Select value={category} onValueChange={setCategory}>
                <SelectTrigger className={attendanceSelectTriggerClass}>
                  <SelectValue />
                </SelectTrigger>
                <SelectContent className={attendanceSelectContentClass}>
                  {CATEGORY_OPTIONS.map((opt) => (
                    <SelectItem key={opt.value} value={opt.value} className={attendanceSelectItemClass}>{opt.label}</SelectItem>
                  ))}
                </SelectContent>
              </Select>
            </label>
          </div>
          <label className="block space-y-1.5 text-xs font-medium text-muted-foreground">
            Search
            <div className="relative max-w-md">
              <Search className="pointer-events-none absolute left-2.5 top-1/2 size-4 -translate-y-1/2 text-muted-foreground" />
              <Input value={search} onChange={(e) => setSearch(e.target.value)} placeholder="Employee, action, summary…" className={attendanceFilterInputClass} />
            </div>
          </label>
          <div className="flex flex-wrap gap-2">
            {CATEGORY_OPTIONS.filter((opt) => opt.value !== 'all').map((opt) => {
              const count = categoryCounts[opt.value] ?? 0
              return (
                <button
                  key={opt.value}
                  type="button"
                  onClick={() => setCategory((c) => (c === opt.value ? 'all' : opt.value))}
                  className={cn(
                    'rounded-full border px-3 py-1 text-xs font-medium transition-colors',
                    category === opt.value
                      ? 'border-orange-500 bg-orange-50 text-orange-700 dark:bg-orange-500/10 dark:text-orange-300'
                      : 'border-border bg-background text-muted-foreground hover:bg-muted/40',
                  )}
                >
                  {opt.label}
                  <span className="ml-1.5 tabular-nums opacity-70">{count}</span>
                </button>
              )
            })}
          </div>
        </CardContent>
      </Card>

      <Card className="border-border/70 shadow-sm">
        <CardHeader className="flex flex-row items-center justify-between space-y-0 pb-3">
          <div>
            <CardTitle className="text-base">Activity timeline</CardTitle>
            <CardDescription>{total.toLocaleString()} event{total === 1 ? '' : 's'} in range</CardDescription>
          </div>
          <Select value={String(perPage)} onValueChange={(v) => setPerPage(normalizeAttendancePerPage(Number(v), ADMIN_ATTENDANCE_PAGE_SIZE))}>
            <SelectTrigger className={cn(attendanceSelectTriggerClass, 'w-[7.5rem]')}>
              <SelectValue />
            </SelectTrigger>
            <SelectContent className={attendanceSelectContentClass}>
              {ATTENDANCE_PAGE_SIZE_OPTIONS.map((size) => (
                <SelectItem key={size} value={String(size)} className={attendanceSelectItemClass}>{size} / page</SelectItem>
              ))}
            </SelectContent>
          </Select>
        </CardHeader>
        <CardContent className="p-0">
          {logsQuery.isLoading ? (
            <div className="px-6 py-12 text-sm text-muted-foreground">Loading employee activity…</div>
          ) : logsQuery.isError ? (
            <div className="px-6 py-12 text-sm text-destructive">{logsQuery.error?.message || 'Failed to load logs.'}</div>
          ) : rows.length === 0 ? (
            <div className="px-6 py-12 text-center text-sm text-muted-foreground">No activity matches your filters.</div>
          ) : (
            <div className="overflow-x-auto">
              <table className="w-full min-w-[64rem] border-collapse text-sm">
                <thead className="border-y border-border bg-muted/20">
                  <tr className="text-left text-xs font-medium text-muted-foreground">
                    <th className="px-4 py-3">When</th>
                    <th className="px-4 py-3">Employee</th>
                    <th className="px-4 py-3">Category</th>
                    <th className="px-4 py-3">Module</th>
                    <th className="px-4 py-3">Action</th>
                    <th className="px-4 py-3">Path / Summary</th>
                    <th className="px-4 py-3">Device</th>
                    <th className="px-4 py-3">Status</th>
                    <th className="px-4 py-3 text-right">Details</th>
                  </tr>
                </thead>
                <tbody>
                  {rows.map((row) => (
                    <tr key={row.id} className="border-b border-border/60 transition-colors hover:bg-muted/15">
                      <td className="whitespace-nowrap px-4 py-3">
                        <div className="font-medium text-foreground">{row.occurred_at_label || '—'}</div>
                      </td>
                      <td className="px-4 py-3">
                        <div className="flex items-center gap-2.5">
                          {row.profile_image ? (
                            <img src={profileImageUrl(row.profile_image)} alt="" className="size-8 rounded-full object-cover" />
                          ) : (
                            <div className="flex size-8 items-center justify-center rounded-full bg-muted text-xs font-semibold text-muted-foreground">
                              {(row.employee_name || '?').slice(0, 1)}
                            </div>
                          )}
                          <div className="min-w-0">
                            <div className="truncate font-medium text-foreground">{row.employee_name || '—'}</div>
                            <div className="truncate text-xs text-muted-foreground">{row.employee_code || row.department_name || '—'}</div>
                          </div>
                        </div>
                      </td>
                      <td className="px-4 py-3">
                        <span className={cn(
                          'inline-flex rounded-md border px-2 py-0.5 text-xs font-semibold',
                          CATEGORY_BADGE_CLASS[row.category] || CATEGORY_BADGE_CLASS.account,
                        )}>
                          {row.category_label || row.category}
                        </span>
                      </td>
                      <td className="max-w-[8rem] truncate px-4 py-3 text-muted-foreground" title={row.module || undefined}>{row.module || '—'}</td>
                      <td className="px-4 py-3 font-medium text-foreground">{row.title || '—'}</td>
                      <td className="max-w-xs px-4 py-3 text-muted-foreground">
                        <div className="truncate" title={row.path || row.summary || undefined}>{row.path || row.summary || '—'}</div>
                        {row.path && row.summary && row.summary !== row.path ? (
                          <div className="truncate text-xs opacity-80" title={row.summary}>{row.summary}</div>
                        ) : null}
                      </td>
                      <td className="whitespace-nowrap px-4 py-3 text-xs capitalize text-muted-foreground">{row.device_type || '—'}</td>
                      <td className="px-4 py-3">
                        {row.status ? (
                          <span className={cn('inline-flex rounded-md border px-2 py-0.5 text-xs font-medium', statusBadgeClass(row.status))}>
                            {row.status}
                          </span>
                        ) : (
                          <span className="text-muted-foreground">—</span>
                        )}
                      </td>
                      <td className="px-4 py-3 text-right">
                        <Button type="button" variant="ghost" size="sm" className="h-8 text-xs" onClick={() => setSelectedRef(row.id)}>
                          View
                        </Button>
                      </td>
                    </tr>
                  ))}
                </tbody>
              </table>
            </div>
          )}

          {rows.length > 0 && (
            <div className="flex flex-col gap-3 border-t border-border px-4 py-3 @sm:flex-row @sm:items-center @sm:justify-between">
              <p className="text-xs text-muted-foreground">
                Page {meta.current_page || page} of {lastPage} · {total.toLocaleString()} total
              </p>
              <div className="flex flex-wrap items-center gap-1">
                <Button type="button" variant="outline" size="icon" className="size-9" disabled={page <= 1} onClick={() => setPage((p) => Math.max(1, p - 1))}>
                  <ChevronLeft className="size-4" />
                </Button>
                {paginationWindow(page, lastPage).map((item) => (
                  item === 'ellipsis-start' || item === 'ellipsis-end' ? (
                    <span key={item} className="px-1 text-muted-foreground">…</span>
                  ) : (
                    <Button
                      key={item}
                      type="button"
                      variant={item === page ? 'default' : 'outline'}
                      size="icon"
                      className={cn('size-9', item === page && attendancePaginationActiveClass)}
                      onClick={() => setPage(item)}
                    >
                      {item}
                    </Button>
                  )
                ))}
                <Button type="button" variant="outline" size="icon" className="size-9" disabled={page >= lastPage} onClick={() => setPage((p) => Math.min(lastPage, p + 1))}>
                  <ChevronRight className="size-4" />
                </Button>
              </div>
            </div>
          )}
        </CardContent>
      </Card>

      <Dialog open={selectedRef != null} onOpenChange={(open) => { if (!open) setSelectedRef(null) }}>
        <DialogContent className="max-w-lg gap-0 p-0">
          <DialogHeader className="border-b px-5 py-4 text-left">
            <DialogTitle className="text-base font-semibold">{detail?.title || 'Activity details'}</DialogTitle>
            <DialogDescription>
              {detail?.category_label || 'Employee activity'}
            </DialogDescription>
          </DialogHeader>
          <div className="max-h-[min(70vh,520px)] overflow-y-auto px-5 py-2">
            {detailQuery.isLoading ? (
              <p className="py-8 text-sm text-muted-foreground">Loading…</p>
            ) : detail?.fields ? (
              Object.entries(detail.fields).map(([label, value]) => (
                <DetailRow key={label} label={label} value={value} />
              ))
            ) : (
              <p className="py-8 text-sm text-muted-foreground">Activity not found.</p>
            )}
          </div>
        </DialogContent>
      </Dialog>
    </div>
  )
}
