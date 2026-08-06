import { useCallback, useEffect, useMemo, useState } from 'react'
import { useQuery, keepPreviousData } from '@tanstack/react-query'
import {
  Calendar,
  History,
  Loader2,
  Plus,
  RefreshCw,
  RotateCcw,
  Search,
  Users,
} from 'lucide-react'
import { Card, CardContent, CardDescription, CardHeader, CardTitle } from '@/components/ui/card'
import { Button } from '@/components/ui/button'
import { Input } from '@/components/ui/input'
import { Badge } from '@/components/ui/badge'
import {
  Dialog,
  DialogContent,
  DialogDescription,
  DialogFooter,
  DialogHeader,
  DialogTitle,
} from '@/components/ui/dialog'
import { Textarea } from '@/components/ui/textarea'
import { Label } from '@/components/ui/label'
import { Checkbox } from '@/components/ui/checkbox'
import { Avatar, AvatarFallback, AvatarImage } from '@/components/ui/avatar'
import {
  Table,
  TableBody,
  TableCell,
  TableHead,
  TableHeader,
  TableRow,
} from '@/components/ui/table'
import { Select, SelectContent, SelectItem, SelectTrigger, SelectValue } from '@/components/ui/select'
import { FilterField, FilterSelect } from '@/components/ui/filter-select'
import { useToast } from '@/components/ui/use-toast'
import { useAuth } from '@/contexts/AuthContext'
import { isAdminHrUser } from '@/lib/hrRoutes'
import { cn } from '@/lib/utils'
import {
  attendanceFilterInputClass,
  attendanceOutlineButtonSmClass,
  attendancePaginationActiveClass,
  attendancePrimaryButtonSmClass,
  attendanceSelectContentClass,
  attendanceSelectItemClass,
} from '@/lib/attendanceUiClasses'
import { EmployeeAvatarNameCell, getInitials } from '@/components/presenceFiling/CorrectionTableCells'
import ManualAttendanceModal from '@/components/attendance/ManualAttendanceModal'
import {
  bulkManualAttendance,
  getEmployees,
  getManualAttendanceHistory,
  getManualAttendanceList,
  profileImageUrl,
  reverseManualAttendance,
} from '@/api'

const fieldClass =
  'h-11 w-full rounded-xl border-input bg-background px-3 text-base text-foreground shadow-sm sm:h-[3.25rem] sm:px-4'

const fileModalShellClass =
  'flex max-h-[min(90dvh,calc(100dvh-2.5rem))] w-[calc(100vw-1.5rem)] max-w-[min(100vw-1.5rem,42rem)] flex-col overflow-hidden rounded-2xl border border-border/80 bg-card p-0 text-card-foreground shadow-[0_24px_80px_-28px_rgba(0,0,0,0.55)] scheme-light sm:w-[calc(100vw-2rem)] dark:border-white/10 dark:bg-card dark:scheme-dark'

const confirmModalShellClass =
  'max-w-md overflow-hidden rounded-2xl border border-border/80 bg-card p-0 shadow-[0_24px_80px_-28px_rgba(0,0,0,0.45)] dark:border-white/10'

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

function ActionButton({ children, onClick, title, disabled, tone = 'default' }) {
  return (
    <Button
      type="button"
      variant="outline"
      size="sm"
      disabled={disabled}
      title={title}
      aria-label={title}
      onClick={(e) => {
        e.stopPropagation()
        onClick?.(e)
      }}
      className={cn(
        'h-8 shrink-0 gap-1 rounded-lg px-2 text-xs font-semibold shadow-sm border-border/80 bg-background hover:bg-muted/70',
        tone === 'danger' && 'border-red-200 text-red-700 hover:bg-red-50 hover:text-red-800 dark:border-red-500/30 dark:text-red-300 dark:hover:bg-red-950/40',
      )}
    >
      {children}
    </Button>
  )
}

export default function ManualAttendance() {
  const { user } = useAuth()
  const { toast } = useToast()
  const perms = user?.permissions instanceof Set ? user.permissions : new Set(user?.permissions || [])
  const adminBypass = isAdminHrUser(user) || user?.role === 'admin'
  const canCreate = perms.has('attendance.manual.create') || adminBypass
  const canEdit = perms.has('attendance.manual.edit') || adminBypass
  const canReverse = perms.has('attendance.manual.reverse') || adminBypass
  const canBulk = perms.has('attendance.manual.bulk_create') || adminBypass
  const canOverride = perms.has('attendance.manual.override_conflict') || adminBypass

  const [page, setPage] = useState(1)
  const [fromDate, setFromDate] = useState('')
  const [toDate, setToDate] = useState('')
  const [statusFilter, setStatusFilter] = useState('active')
  const [searchQuery, setSearchQuery] = useState('')
  const [appliedFrom, setAppliedFrom] = useState('')
  const [appliedTo, setAppliedTo] = useState('')
  const [appliedStatus, setAppliedStatus] = useState('active')
  const [appliedSearch, setAppliedSearch] = useState('')

  const [modalOpen, setModalOpen] = useState(false)
  const [editRecord, setEditRecord] = useState(null)
  const [historyOpen, setHistoryOpen] = useState(false)
  const [historyRows, setHistoryRows] = useState([])
  const [historyLoading, setHistoryLoading] = useState(false)
  const [reverseOpen, setReverseOpen] = useState(false)
  const [reverseTarget, setReverseTarget] = useState(null)
  const [reverseReason, setReverseReason] = useState('')
  const [reversing, setReversing] = useState(false)

  const [bulkOpen, setBulkOpen] = useState(false)
  const [bulkDate, setBulkDate] = useState('')
  const [bulkTimeIn, setBulkTimeIn] = useState('08:00')
  const [bulkTimeOut, setBulkTimeOut] = useState('17:00')
  const [bulkReason, setBulkReason] = useState('administrative_correction')
  const [bulkRemarks, setBulkRemarks] = useState('')
  const [bulkPreview, setBulkPreview] = useState(null)
  const [bulkEmployeeIds, setBulkEmployeeIds] = useState([])
  const [bulkSearch, setBulkSearch] = useState('')
  const [bulkBusy, setBulkBusy] = useState(false)

  const listQuery = useQuery({
    queryKey: ['manual-attendance', page, appliedFrom, appliedTo, appliedStatus],
    queryFn: () => getManualAttendanceList({
      page,
      per_page: 25,
      from_date: appliedFrom || undefined,
      to_date: appliedTo || undefined,
      status: appliedStatus || 'active',
    }),
    placeholderData: keepPreviousData,
  })

  const employeesQuery = useQuery({
    queryKey: ['manual-attendance-roster', 'all'],
    queryFn: async () => {
      const res = await getEmployees({ per_page: 'all', lite: 1, active_filter: 'active' })
      return res?.employees ?? []
    },
    staleTime: 60_000,
  })

  const rows = listQuery.data?.data ?? []
  const meta = listQuery.data?.meta ?? {}
  const reasonCodes = listQuery.data?.reason_codes ?? {}

  const filteredRows = useMemo(() => {
    const q = appliedSearch.trim().toLowerCase()
    if (!q) return rows
    return rows.filter((row) => {
      const hay = [row.employee_name, row.employee_number, row.reason_label, row.entered_by, row.company_name, row.department_name]
        .filter(Boolean)
        .join(' ')
        .toLowerCase()
      return hay.includes(q)
    })
  }, [rows, appliedSearch])

  const filteredEmployees = useMemo(() => {
    const list = employeesQuery.data ?? []
    const q = bulkSearch.trim().toLowerCase()
    if (!q) return list
    return list.filter((emp) => {
      const hay = [emp.name, emp.employee_id, emp.company_name, emp.branch_name, emp.department_name]
        .filter(Boolean)
        .join(' ')
        .toLowerCase()
      return hay.includes(q)
    })
  }, [employeesQuery.data, bulkSearch])

  const applyFilters = () => {
    setAppliedFrom(fromDate)
    setAppliedTo(toDate)
    setAppliedStatus(statusFilter)
    setAppliedSearch(searchQuery)
    setPage(1)
  }

  const openCreate = () => {
    setEditRecord(null)
    setModalOpen(true)
  }

  const openEdit = (row) => {
    setEditRecord(row)
    setModalOpen(true)
  }

  const openHistory = async (row) => {
    setHistoryOpen(true)
    setHistoryLoading(true)
    setHistoryRows([])
    try {
      const data = await getManualAttendanceHistory(row.id)
      setHistoryRows(data.history || [])
    } catch (err) {
      toast({ variant: 'destructive', title: 'History failed', description: err.message })
      setHistoryOpen(false)
    } finally {
      setHistoryLoading(false)
    }
  }

  const handleReverse = async () => {
    if (!reverseTarget) return
    setReversing(true)
    try {
      await reverseManualAttendance(reverseTarget.id, reverseReason)
      toast({ title: 'Manual attendance reversed' })
      setReverseOpen(false)
      setReverseReason('')
      setReverseTarget(null)
      listQuery.refetch()
    } catch (err) {
      toast({ variant: 'destructive', title: 'Reverse failed', description: err.message })
    } finally {
      setReversing(false)
    }
  }

  const runBulkPreview = useCallback(async () => {
    if (!bulkDate || bulkEmployeeIds.length === 0) {
      toast({ variant: 'destructive', title: 'Select a date and at least one employee' })
      return
    }
    setBulkBusy(true)
    try {
      const data = await bulkManualAttendance({
        date: bulkDate,
        time_in: bulkTimeIn,
        time_out: bulkTimeOut,
        reason_code: bulkReason,
        manual_remarks: bulkRemarks || undefined,
        employee_ids: bulkEmployeeIds,
        apply: false,
      })
      setBulkPreview(data)
    } catch (err) {
      toast({ variant: 'destructive', title: 'Bulk preview failed', description: err.message })
    } finally {
      setBulkBusy(false)
    }
  }, [bulkDate, bulkTimeIn, bulkTimeOut, bulkReason, bulkRemarks, bulkEmployeeIds, toast])

  const applyBulk = async () => {
    setBulkBusy(true)
    try {
      await bulkManualAttendance({
        date: bulkDate,
        time_in: bulkTimeIn,
        time_out: bulkTimeOut,
        reason_code: bulkReason,
        manual_remarks: bulkRemarks || undefined,
        employee_ids: bulkEmployeeIds,
        apply: true,
      })
      toast({ title: 'Bulk manual attendance applied' })
      setBulkOpen(false)
      setBulkPreview(null)
      setBulkEmployeeIds([])
      listQuery.refetch()
    } catch (err) {
      toast({ variant: 'destructive', title: 'Bulk apply failed', description: err.message })
    } finally {
      setBulkBusy(false)
    }
  }

  useEffect(() => {
    if (!bulkOpen) {
      setBulkPreview(null)
      setBulkSearch('')
    }
  }, [bulkOpen])

  const pageButtons = paginationWindow(meta.current_page || page, meta.last_page || 1)
  const activeCount = filteredRows.filter((r) => !r.is_reversed).length
  const reversedCount = filteredRows.filter((r) => r.is_reversed).length

  return (
    <div className="min-w-0 max-w-full space-y-5 overflow-x-clip">
      <div className="flex flex-col gap-4 @md:flex-row @md:items-start @md:justify-between">
        <div className="space-y-1.5">
          <p className="text-[10px] font-black uppercase tracking-[0.18em] text-brand">Time & Attendance</p>
          <h1 className="mb-0 text-[28px] font-black leading-tight tracking-normal text-foreground">
            Manual Attendance
          </h1>
          <p className="max-w-2xl text-sm text-muted-foreground">
            Create, complete, or replace employee attendance records without an approval workflow.
          </p>
        </div>
        <div className="flex flex-wrap gap-2">
          {canBulk && (
            <Button type="button" variant="outline" className={cn(attendanceOutlineButtonSmClass, 'h-9')} onClick={() => setBulkOpen(true)}>
              <Users className="size-4 mr-1.5" /> Bulk Manual Attendance
            </Button>
          )}
          {canCreate && (
            <Button type="button" className={attendancePrimaryButtonSmClass} onClick={openCreate}>
              <Plus className="size-4 mr-1.5" /> Add Manual Attendance
            </Button>
          )}
        </div>
      </div>

      <div className="grid gap-3 @sm:grid-cols-3">
        <Card className="rounded-xl border-border/70 shadow-sm dark:border-white/10">
          <CardContent className="p-4">
            <p className="text-xs font-semibold uppercase tracking-wide text-muted-foreground">Records</p>
            <p className="mt-1 text-2xl font-black tabular-nums">{meta.total ?? filteredRows.length}</p>
          </CardContent>
        </Card>
        <Card className="rounded-xl border-border/70 shadow-sm dark:border-white/10">
          <CardContent className="p-4">
            <p className="text-xs font-semibold uppercase tracking-wide text-muted-foreground">Active</p>
            <p className="mt-1 text-2xl font-black tabular-nums text-emerald-700 dark:text-emerald-300">{activeCount}</p>
          </CardContent>
        </Card>
        <Card className="rounded-xl border-border/70 shadow-sm dark:border-white/10">
          <CardContent className="p-4">
            <p className="text-xs font-semibold uppercase tracking-wide text-muted-foreground">Reversed</p>
            <p className="mt-1 text-2xl font-black tabular-nums text-muted-foreground">{reversedCount}</p>
          </CardContent>
        </Card>
      </div>

      <Card className="min-w-0 overflow-hidden rounded-xl border-border/70 shadow-sm dark:border-white/10">
        <CardHeader className="pb-3">
          <CardTitle className="text-base">Manual Attendance Records</CardTitle>
          <CardDescription>Directly approved authoritative entries · source badge: Admin Manual</CardDescription>
        </CardHeader>
        <CardContent className="min-w-0 space-y-4">
          <div className="rounded-xl bg-muted/20 p-4 ring-1 ring-border/40">
            <div className="grid grid-cols-1 gap-3 @md:grid-cols-2 @xl:grid-cols-5">
              <FilterField label="Search">
                <div className="relative">
                  <Search className="pointer-events-none absolute left-3 top-1/2 size-4 -translate-y-1/2 text-muted-foreground" />
                  <Input
                    value={searchQuery}
                    onChange={(e) => setSearchQuery(e.target.value)}
                    placeholder="Employee, reason, entered by…"
                    className={attendanceFilterInputClass}
                    onKeyDown={(e) => e.key === 'Enter' && applyFilters()}
                  />
                </div>
              </FilterField>
              <FilterField label="From">
                <div className="relative">
                  <Calendar className="pointer-events-none absolute left-3 top-1/2 size-4 -translate-y-1/2 text-muted-foreground" />
                  <Input type="date" value={fromDate} onChange={(e) => setFromDate(e.target.value)} className={attendanceFilterInputClass} />
                </div>
              </FilterField>
              <FilterField label="To">
                <div className="relative">
                  <Calendar className="pointer-events-none absolute left-3 top-1/2 size-4 -translate-y-1/2 text-muted-foreground" />
                  <Input type="date" value={toDate} onChange={(e) => setToDate(e.target.value)} className={attendanceFilterInputClass} />
                </div>
              </FilterField>
              <FilterField label="Status">
                <FilterSelect value={statusFilter} onChange={(e) => setStatusFilter(e.target.value)}>
                  <option value="active">Active</option>
                  <option value="reversed">Reversed</option>
                  <option value="all">All</option>
                </FilterSelect>
              </FilterField>
              <div className="flex items-end gap-2">
                <Button type="button" className={cn(attendancePrimaryButtonSmClass, 'h-9 flex-1')} onClick={applyFilters}>
                  Apply
                </Button>
                <Button
                  type="button"
                  variant="outline"
                  size="sm"
                  className={cn(attendanceOutlineButtonSmClass, 'h-9')}
                  onClick={() => listQuery.refetch()}
                >
                  <RefreshCw className={cn('size-4', listQuery.isFetching && 'animate-spin')} />
                </Button>
              </div>
            </div>
            {(appliedFrom || appliedTo || appliedSearch || appliedStatus !== 'active') && (
              <div className="mt-3 flex flex-wrap gap-1.5">
                {appliedSearch && <span className="rounded-full border border-border/70 bg-card px-2.5 py-0.5 text-[11px] font-medium">Search: {appliedSearch}</span>}
                {appliedFrom && <span className="rounded-full border border-border/70 bg-card px-2.5 py-0.5 text-[11px] font-medium">From: {appliedFrom}</span>}
                {appliedTo && <span className="rounded-full border border-border/70 bg-card px-2.5 py-0.5 text-[11px] font-medium">To: {appliedTo}</span>}
                {appliedStatus !== 'active' && <span className="rounded-full border border-border/70 bg-card px-2.5 py-0.5 text-[11px] font-medium">Status: {appliedStatus}</span>}
              </div>
            )}
          </div>

          <div className="w-full min-w-0 overflow-hidden rounded-xl">
            <Table className="w-full min-w-0 table-fixed border-0 text-[12px]" containerClassName="overflow-visible">
              <TableHeader className="[&_tr]:border-0">
                <TableRow className="border-0 bg-muted/40 hover:bg-muted/40 dark:bg-muted/25 dark:hover:bg-muted/25">
                  <TableHead className="w-[16%] px-2 py-2.5 text-[10px] font-bold uppercase tracking-wide text-muted-foreground">
                    Employee
                  </TableHead>
                  <TableHead className="w-[8%] px-2 py-2.5 text-[10px] font-bold uppercase tracking-wide text-muted-foreground">
                    Date
                  </TableHead>
                  <TableHead className="hidden w-[10%] px-2 py-2.5 text-[10px] font-bold uppercase tracking-wide text-muted-foreground xl:table-cell">
                    Schedule
                  </TableHead>
                  <TableHead className="w-[8%] px-2 py-2.5 text-[10px] font-bold uppercase tracking-wide text-muted-foreground">
                    Time In
                  </TableHead>
                  <TableHead className="w-[8%] px-2 py-2.5 text-[10px] font-bold uppercase tracking-wide text-muted-foreground">
                    Time Out
                  </TableHead>
                  <TableHead className="w-[10%] px-2 py-2.5 text-[10px] font-bold uppercase tracking-wide text-muted-foreground">
                    Status
                  </TableHead>
                  <TableHead className="w-[6%] px-2 py-2.5 text-[10px] font-bold uppercase tracking-wide text-muted-foreground">
                    Hrs
                  </TableHead>
                  <TableHead className="hidden w-[7%] px-2 py-2.5 text-[10px] font-bold uppercase tracking-wide text-muted-foreground lg:table-cell">
                    Payroll
                  </TableHead>
                  <TableHead className="w-[11%] px-2 py-2.5 text-[10px] font-bold uppercase tracking-wide text-muted-foreground">
                    Reason
                  </TableHead>
                  <TableHead className="hidden w-[10%] px-2 py-2.5 text-[10px] font-bold uppercase tracking-wide text-muted-foreground md:table-cell">
                    Entered By
                  </TableHead>
                  <TableHead className="w-[12%] px-2 py-2.5 text-right text-[10px] font-bold uppercase tracking-wide text-muted-foreground">
                    Actions
                  </TableHead>
                </TableRow>
              </TableHeader>
              <TableBody>
                {listQuery.isLoading ? (
                  <TableRow className="border-0">
                    <TableCell colSpan={11} className="h-28 text-center text-muted-foreground">
                      <span className="inline-flex items-center gap-2"><Loader2 className="size-4 animate-spin" /> Loading records…</span>
                    </TableCell>
                  </TableRow>
                ) : filteredRows.length === 0 ? (
                  <TableRow className="border-0">
                    <TableCell colSpan={11} className="h-28 text-center text-muted-foreground">
                      No manual attendance records match the current filters.
                    </TableCell>
                  </TableRow>
                ) : filteredRows.map((row, idx) => (
                  <TableRow key={row.id} className={cn('border-0', idx % 2 === 1 && 'bg-muted/15')}>
                    <TableCell className="min-w-0 px-2 py-2 align-middle">
                      <EmployeeAvatarNameCell
                        compact
                        name={row.employee_name}
                        imageUrl={row.profile_image}
                        idHint={row.employee_number}
                      />
                    </TableCell>
                    <TableCell className="min-w-0 px-2 py-2 align-middle whitespace-nowrap font-medium tabular-nums">
                      {row.date || '—'}
                    </TableCell>
                    <TableCell className="hidden min-w-0 px-2 py-2 align-middle xl:table-cell">
                      <span className="block truncate" title={row.resolved_schedule || ''}>
                        {row.resolved_schedule || '—'}
                      </span>
                    </TableCell>
                    <TableCell className="min-w-0 px-2 py-2 align-middle whitespace-nowrap tabular-nums">
                      {row.time_in || '—'}
                    </TableCell>
                    <TableCell className="min-w-0 px-2 py-2 align-middle whitespace-nowrap tabular-nums">
                      {row.time_out || '—'}
                    </TableCell>
                    <TableCell className="min-w-0 px-2 py-2 align-middle">
                      <Badge
                        variant="outline"
                        className={cn(
                          'max-w-full truncate rounded-md px-1.5 py-0 text-[10px] font-semibold',
                          row.is_reversed
                            ? 'border-border/70 bg-muted text-muted-foreground'
                            : 'border-emerald-200 bg-emerald-50 text-emerald-800 dark:border-emerald-500/30 dark:bg-emerald-950/40 dark:text-emerald-200',
                        )}
                        title={row.computed_status || ''}
                      >
                        {row.computed_status || '—'}
                      </Badge>
                    </TableCell>
                    <TableCell className="min-w-0 px-2 py-2 align-middle tabular-nums">
                      {row.total_hours != null ? Number(row.total_hours).toFixed(2) : '—'}
                    </TableCell>
                    <TableCell className="hidden min-w-0 px-2 py-2 align-middle tabular-nums lg:table-cell">
                      {row.payroll_impact_hours != null ? Number(row.payroll_impact_hours).toFixed(2) : '—'}
                    </TableCell>
                    <TableCell className="min-w-0 px-2 py-2 align-middle">
                      <div className="truncate font-medium" title={row.reason_label || ''}>
                        {row.reason_label || '—'}
                      </div>
                    </TableCell>
                    <TableCell className="hidden min-w-0 px-2 py-2 align-middle md:table-cell">
                      <div className="truncate font-medium" title={row.entered_by || ''}>
                        {row.entered_by || '—'}
                      </div>
                      <div className="truncate text-[10px] text-muted-foreground" title={row.entered_at ? new Date(row.entered_at).toLocaleString() : ''}>
                        {row.entered_at ? new Date(row.entered_at).toLocaleDateString() : ''}
                      </div>
                    </TableCell>
                    <TableCell className="px-2 py-2 align-middle text-right">
                      <div className="inline-flex flex-wrap justify-end gap-1">
                        {canEdit && !row.is_reversed && (
                          <ActionButton title="Edit" onClick={() => openEdit(row)}>
                            Edit
                          </ActionButton>
                        )}
                        <ActionButton title="History" onClick={() => openHistory(row)}>
                          <History className="size-3.5" />
                        </ActionButton>
                        {canReverse && !row.is_reversed && (
                          <ActionButton
                            title="Reverse"
                            tone="danger"
                            onClick={() => {
                              setReverseTarget(row)
                              setReverseReason('')
                              setReverseOpen(true)
                            }}
                          >
                            <RotateCcw className="size-3.5" />
                          </ActionButton>
                        )}
                      </div>
                    </TableCell>
                  </TableRow>
                ))}
              </TableBody>
            </Table>
          </div>

          {(meta.last_page > 1 || (meta.total ?? 0) > 0) && (
            <div className="flex flex-col gap-3 @sm:flex-row @sm:items-center @sm:justify-between">
              <p className="text-sm text-muted-foreground">
                Page {meta.current_page || 1} of {meta.last_page || 1} · {meta.total ?? 0} records
              </p>
              <div className="flex flex-wrap items-center gap-1.5">
                <Button
                  type="button"
                  variant="outline"
                  size="sm"
                  className={cn(attendanceOutlineButtonSmClass, 'h-8 px-3')}
                  disabled={page <= 1 || listQuery.isFetching}
                  onClick={() => setPage((p) => Math.max(1, p - 1))}
                >
                  Previous
                </Button>
                {pageButtons.map((p) =>
                  typeof p === 'number' ? (
                    <Button
                      key={p}
                      type="button"
                      variant={p === page ? 'default' : 'outline'}
                      size="sm"
                      className={cn('h-8 min-w-8 px-2.5', p === page ? attendancePaginationActiveClass : attendanceOutlineButtonSmClass)}
                      disabled={listQuery.isFetching}
                      onClick={() => setPage(p)}
                    >
                      {p}
                    </Button>
                  ) : (
                    <span key={p} className="px-1 text-muted-foreground">…</span>
                  ),
                )}
                <Button
                  type="button"
                  variant="outline"
                  size="sm"
                  className={cn(attendanceOutlineButtonSmClass, 'h-8 px-3')}
                  disabled={page >= (meta.last_page || 1) || listQuery.isFetching}
                  onClick={() => setPage((p) => p + 1)}
                >
                  Next
                </Button>
              </div>
            </div>
          )}
        </CardContent>
      </Card>

      <ManualAttendanceModal
        open={modalOpen}
        onOpenChange={setModalOpen}
        onSaved={() => listQuery.refetch()}
        editRecord={editRecord}
        reasonCodes={reasonCodes}
        canOverrideConflict={canOverride}
        employees={employeesQuery.data ?? []}
      />

      <Dialog open={historyOpen} onOpenChange={setHistoryOpen}>
        <DialogContent className={confirmModalShellClass} showCloseButton>
          <DialogHeader className="border-b border-border/70 px-5 py-4 text-left">
            <DialogTitle>Revision History</DialogTitle>
            <DialogDescription>Every create, edit, replace, and reverse for this record.</DialogDescription>
          </DialogHeader>
          <div className="max-h-[50vh] space-y-3 overflow-y-auto px-5 py-4 text-sm">
            {historyLoading ? (
              <p className="inline-flex items-center gap-2 text-muted-foreground"><Loader2 className="size-4 animate-spin" /> Loading…</p>
            ) : historyRows.length === 0 ? (
              <p className="text-muted-foreground">No revisions recorded.</p>
            ) : historyRows.map((h) => (
              <div key={h.id} className="rounded-xl border border-border/70 bg-muted/15 p-3 dark:border-white/10">
                <p className="font-semibold capitalize text-foreground">{String(h.change_type || '').replace(/_/g, ' ')}</p>
                <p className="mt-0.5 text-xs text-muted-foreground">
                  {h.changed_by} · {h.changed_at ? new Date(h.changed_at).toLocaleString() : ''}
                </p>
                {h.reason ? <p className="mt-2 whitespace-pre-wrap text-sm leading-relaxed">{h.reason}</p> : null}
              </div>
            ))}
          </div>
        </DialogContent>
      </Dialog>

      <Dialog open={reverseOpen} onOpenChange={setReverseOpen}>
        <DialogContent className={confirmModalShellClass} showCloseButton>
          <DialogHeader className="border-b border-border/70 px-5 py-4 text-left">
            <DialogTitle>Reverse Manual Attendance</DialogTitle>
            <DialogDescription>
              Preserves history and restores the previous attendance state when available. Finalized payroll periods stay locked.
            </DialogDescription>
          </DialogHeader>
          <div className="space-y-3 px-5 py-4">
            {reverseTarget && (
              <div className="rounded-xl border border-border/70 bg-muted/20 px-3 py-2.5 text-sm dark:border-white/10">
                <p className="font-semibold">{reverseTarget.employee_name}</p>
                <p className="text-muted-foreground">{reverseTarget.date} · {reverseTarget.time_in || '—'} – {reverseTarget.time_out || '—'}</p>
              </div>
            )}
            <div className="space-y-1.5">
              <Label className="text-sm font-bold">Reversal Reason <span className="text-destructive">*</span></Label>
              <Textarea
                value={reverseReason}
                onChange={(e) => setReverseReason(e.target.value)}
                rows={3}
                className="rounded-xl"
                placeholder="Why is this record being reversed?"
              />
            </div>
          </div>
          <DialogFooter className="gap-2 border-t border-border/70 bg-muted/15 px-5 py-4">
            <Button type="button" variant="outline" className={attendanceOutlineButtonSmClass} onClick={() => setReverseOpen(false)}>
              Cancel
            </Button>
            <Button
              type="button"
              variant="destructive"
              disabled={!reverseReason.trim() || reversing}
              onClick={handleReverse}
            >
              {reversing ? <Loader2 className="mr-2 size-4 animate-spin" /> : <RotateCcw className="mr-2 size-4" />}
              Reverse
            </Button>
          </DialogFooter>
        </DialogContent>
      </Dialog>

      <Dialog open={bulkOpen} onOpenChange={setBulkOpen}>
        <DialogContent
          showCloseButton
          closeButtonClassName="right-3 top-3 size-9 rounded-lg border-border/80 bg-card/95 text-foreground shadow-md hover:bg-muted sm:right-4 sm:top-4 sm:size-10"
          innerClassName="flex min-h-0 flex-1 flex-col gap-0 overflow-hidden p-0"
          className={fileModalShellClass}
        >
          <div className="min-h-0 flex-1 overflow-y-auto overscroll-contain bg-card">
            <DialogHeader className="border-b border-border/70 px-4 pb-4 pt-4 text-left sm:px-7 sm:pb-5 sm:pt-7">
              <div className="flex flex-col gap-3 pr-10 sm:flex-row sm:items-start sm:gap-4 sm:pr-12">
                <div className="flex size-11 shrink-0 items-center justify-center rounded-2xl bg-muted ring-1 ring-border sm:size-14">
                  <Users className="size-5 text-brand sm:size-6" aria-hidden />
                </div>
                <div className="min-w-0 space-y-1.5">
                  <p className="text-[10px] font-black uppercase tracking-[0.18em] text-brand sm:text-[11px]">
                    Manual attendance
                  </p>
                  <DialogTitle className="text-xl font-black tracking-tight text-foreground sm:text-2xl">
                    Bulk Manual Attendance
                  </DialogTitle>
                  <DialogDescription className="text-sm leading-relaxed text-muted-foreground">
                    Apply the same time logs to selected employees. Conflicted rows are skipped — never overridden silently.
                  </DialogDescription>
                </div>
              </div>
            </DialogHeader>

            <div className="space-y-5 px-4 py-4 sm:px-7 sm:py-6">
              <div className="grid gap-4 sm:grid-cols-2">
                <div className="space-y-2">
                  <Label className="text-sm font-bold">Date <span className="text-destructive">*</span></Label>
                  <Input type="date" value={bulkDate} onChange={(e) => { setBulkDate(e.target.value); setBulkPreview(null) }} className={fieldClass} />
                </div>
                <div className="space-y-2">
                  <Label className="text-sm font-bold">Reason <span className="text-destructive">*</span></Label>
                  <Select value={bulkReason} onValueChange={(v) => { setBulkReason(v); setBulkPreview(null) }}>
                    <SelectTrigger className={cn(fieldClass, 'w-full')}>
                      <SelectValue />
                    </SelectTrigger>
                    <SelectContent className={attendanceSelectContentClass}>
                      {Object.entries(reasonCodes).map(([code, label]) => (
                        <SelectItem key={code} value={code} className={attendanceSelectItemClass}>{label}</SelectItem>
                      ))}
                    </SelectContent>
                  </Select>
                </div>
                <div className="space-y-2">
                  <Label className="text-sm font-bold">Time In <span className="text-destructive">*</span></Label>
                  <Input type="time" value={bulkTimeIn} onChange={(e) => { setBulkTimeIn(e.target.value); setBulkPreview(null) }} className={fieldClass} />
                </div>
                <div className="space-y-2">
                  <Label className="text-sm font-bold">Time Out <span className="text-destructive">*</span></Label>
                  <Input type="time" value={bulkTimeOut} onChange={(e) => { setBulkTimeOut(e.target.value); setBulkPreview(null) }} className={fieldClass} />
                </div>
              </div>

              <div className="space-y-2">
                <Label className="text-sm font-bold">Remarks</Label>
                <Textarea
                  value={bulkRemarks}
                  onChange={(e) => setBulkRemarks(e.target.value)}
                  rows={2}
                  className="rounded-xl"
                  placeholder={bulkReason === 'other' ? 'Required when reason is Other' : 'Optional notes'}
                />
              </div>

              <div className="space-y-3">
                <div className="flex flex-wrap items-center justify-between gap-2">
                  <Label className="text-sm font-bold">Employees</Label>
                  <Badge variant="outline" className="rounded-md font-semibold">{bulkEmployeeIds.length} selected</Badge>
                </div>
                <div className="relative">
                  <Search className="pointer-events-none absolute left-3 top-1/2 size-4 -translate-y-1/2 text-muted-foreground" />
                  <Input
                    className="h-11 rounded-xl pl-9"
                    value={bulkSearch}
                    onChange={(e) => setBulkSearch(e.target.value)}
                    placeholder="Search name, employee number, company…"
                  />
                </div>
                <div className="max-h-72 overflow-y-auto rounded-xl border border-border/70 dark:border-white/10">
                  {employeesQuery.isLoading ? (
                    <div className="p-5 text-sm text-muted-foreground">Loading employees…</div>
                  ) : filteredEmployees.length === 0 ? (
                    <div className="p-5 text-sm text-muted-foreground">No matching employees.</div>
                  ) : filteredEmployees.map((emp) => {
                    const checked = bulkEmployeeIds.includes(emp.id)
                    return (
                      <button
                        key={emp.id}
                        type="button"
                        className={cn(
                          'flex w-full items-center gap-3 border-b border-border/60 px-3 py-2.5 text-left last:border-b-0 dark:border-white/10',
                          checked ? 'bg-brand/[0.07]' : 'hover:bg-muted/40',
                        )}
                        onClick={() => {
                          setBulkEmployeeIds((prev) =>
                            checked ? prev.filter((id) => id !== emp.id) : [...prev, emp.id],
                          )
                          setBulkPreview(null)
                        }}
                      >
                        <Avatar className="size-9 border border-border/60">
                          {emp.profile_image ? <AvatarImage src={profileImageUrl(emp.profile_image)} alt="" /> : null}
                          <AvatarFallback className="text-[10px] font-bold">{getInitials(emp.name)}</AvatarFallback>
                        </Avatar>
                        <span className="min-w-0 flex-1">
                          <span className="block truncate text-sm font-semibold">{emp.name}</span>
                          <span className="block truncate text-xs text-muted-foreground">
                            {[emp.employee_id, emp.company_name, emp.branch_name, emp.department_name].filter(Boolean).join(' · ')}
                          </span>
                        </span>
                        <Checkbox checked={checked} className="size-5 rounded-md" />
                      </button>
                    )
                  })}
                </div>
              </div>

              {bulkPreview && (
                <div className="grid gap-2 rounded-xl border border-border/70 bg-muted/20 p-4 sm:grid-cols-3 dark:border-white/10">
                  <div>
                    <p className="text-[11px] font-semibold uppercase tracking-wide text-muted-foreground">Selected</p>
                    <p className="text-xl font-black tabular-nums">{bulkPreview.selected}</p>
                  </div>
                  <div>
                    <p className="text-[11px] font-semibold uppercase tracking-wide text-muted-foreground">Ready</p>
                    <p className="text-xl font-black tabular-nums text-emerald-700 dark:text-emerald-300">{bulkPreview.ready}</p>
                  </div>
                  <div>
                    <p className="text-[11px] font-semibold uppercase tracking-wide text-muted-foreground">Conflicts</p>
                    <p className="text-xl font-black tabular-nums text-amber-700 dark:text-amber-300">{bulkPreview.conflicts?.length ?? 0}</p>
                  </div>
                  {(bulkPreview.conflicts?.length ?? 0) > 0 && (
                    <div className="sm:col-span-3 max-h-28 overflow-y-auto rounded-lg border border-amber-500/25 bg-amber-500/8 p-2 text-xs text-amber-900 dark:text-amber-100">
                      {(bulkPreview.conflicts || []).slice(0, 8).map((c, i) => (
                        <p key={i}>#{c.employee_id} — {c.message || (c.conflicts || []).map((x) => x.message).join('; ')}</p>
                      ))}
                    </div>
                  )}
                </div>
              )}
            </div>
          </div>

          <div className="mt-auto flex shrink-0 flex-col-reverse gap-2 border-t border-border/70 bg-muted/15 px-4 py-4 sm:flex-row sm:justify-end sm:gap-3 sm:px-7 sm:py-5">
            <Button type="button" variant="outline" className={attendanceOutlineButtonSmClass} onClick={() => setBulkOpen(false)}>
              Cancel
            </Button>
            <Button type="button" variant="outline" className={attendanceOutlineButtonSmClass} disabled={bulkBusy} onClick={runBulkPreview}>
              {bulkBusy ? <Loader2 className="mr-2 size-4 animate-spin" /> : null}
              Preview
            </Button>
            <Button
              type="button"
              className="h-9 rounded-lg bg-orange-500 px-4 text-sm font-semibold text-white hover:bg-orange-600"
              disabled={bulkBusy || !bulkPreview?.ready}
              onClick={applyBulk}
            >
              {bulkBusy ? <Loader2 className="mr-2 size-4 animate-spin" /> : null}
              Apply to Ready
            </Button>
          </div>
        </DialogContent>
      </Dialog>
    </div>
  )
}
