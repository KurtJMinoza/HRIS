import { useMemo, useState } from 'react'
import { useReactTable, getCoreRowModel, getSortedRowModel, flexRender } from '@tanstack/react-table'
import { Eye, ArrowUpDown, ArrowUp, ArrowDown } from 'lucide-react'
import {
  Table,
  TableBody,
  TableCell,
  TableHead,
  TableHeader,
  TableRow,
} from '@/components/ui/table'
import { Button } from '@/components/ui/button'
import { Avatar, AvatarFallback, AvatarImage } from '@/components/ui/avatar'
import { cn } from '@/lib/utils'
import { TableBodySkeleton } from '@/components/skeletons'
import {
  attendanceRecordRef,
  formatShortDate,
  tableRenderedHoursLabel,
  resolveAdminStatusLabel,
  resolveEmployeeStatusLabel,
  mutedTimeCell,
  formatScheduleRange,
  tableLateMinutes,
  tableUndertimeMinutes,
  tableOvertimeMinutes,
  tableApprovedOtHours,
  tableOtHoursHrs,
  minutesCellText,
} from '@/components/attendance/attendanceRecordUtils'
import { AttendanceStatusPill } from '@/components/attendance/AttendanceStatusPill'

function timeSortKey(value) {
  if (value == null || value === '') return null
  if (typeof value === 'string' && /^\d{1,2}:\d{2}$/.test(value.trim())) {
    const [h, m] = value.trim().split(':').map(Number)
    return h * 60 + m
  }
  const d = new Date(value)
  if (Number.isNaN(d.getTime())) return null
  return d.getHours() * 60 + d.getMinutes()
}

function totalHoursSortValue(row) {
  const v = row.total_rendered_hours ?? row.total_hours
  if (typeof v === 'number' && !Number.isNaN(v)) return v
  return -1
}

function SortableHeader({ column, label }) {
  const sorted = column.getIsSorted()
  const Icon = !sorted ? ArrowUpDown : sorted === 'asc' ? ArrowUp : ArrowDown
  return (
    <Button
      type="button"
      variant="ghost"
      size="sm"
      className="-ml-2 h-8 gap-1 px-2 text-[11px] font-bold uppercase tracking-wider text-muted-foreground hover:bg-muted/80 hover:text-foreground"
      onClick={column.getToggleSortingHandler()}
    >
      {label}
      <Icon className={cn('size-3.5', sorted ? 'text-primary opacity-100' : 'opacity-40')} aria-hidden />
    </Button>
  )
}

export function AttendanceRecordsDataTable({
  mode,
  rows,
  loading,
  onOpenDetails,
  profileImageUrl,
  viewerName,
  viewerInitials,
  viewerImageSrc,
  viewerCompany,
  viewerDepartment,
  emptyMessage,
  hideCompanyColumn = false,
  hideDepartmentColumn = false,
}) {
  const isAdmin = mode === 'admin'
  const [sorting, setSorting] = useState(() => [{ id: 'date', desc: true }])

  const columns = useMemo(() => {
    const employeeColumn = {
        id: 'employee',
        header: 'Employee',
        enableSorting: true,
        accessorFn: (row) => (isAdmin ? row.employee_name || '' : viewerName || ''),
        sortingFn: (a, b) =>
          String(isAdmin ? a.original.employee_name || '' : viewerName || '').localeCompare(
            String(isAdmin ? b.original.employee_name || '' : viewerName || ''),
            undefined,
            { sensitivity: 'base' }
          ),
        cell: ({ row }) => {
          const r = row.original
          if (isAdmin) {
            const initials = (r.employee_name || '?')
              .trim()
              .split(/\s+/)
              .map((n) => n[0])
              .join('')
              .toUpperCase()
              .slice(0, 2) || '?'
            return (
              <div className="flex min-w-[12rem] max-w-[18rem] items-center gap-3">
                <Avatar className="size-10 shrink-0 border-2 border-white shadow-sm ring-2 ring-emerald-500/15 ring-offset-2 ring-offset-background dark:border-slate-800">
                  <AvatarImage src={profileImageUrl?.(r.profile_image)} alt="" className="object-cover" />
                  <AvatarFallback className="bg-emerald-500/15 text-xs font-bold text-emerald-800 dark:text-emerald-200">
                    {initials}
                  </AvatarFallback>
                </Avatar>
                <div className="min-w-0">
                  <p className="truncate font-semibold text-foreground">{r.employee_name}</p>
                  <p className="truncate font-mono text-[10px] text-muted-foreground">
                    {attendanceRecordRef(r.employee_id, r.date)}
                  </p>
                </div>
              </div>
            )
          }
          return (
            <div className="flex min-w-[12rem] max-w-[18rem] items-center gap-3">
              <Avatar className="size-10 shrink-0 border-2 border-white shadow-sm ring-2 ring-emerald-500/15 ring-offset-2 ring-offset-background">
                <AvatarImage src={viewerImageSrc} alt="" className="object-cover" />
                <AvatarFallback className="bg-emerald-500/15 text-xs font-bold text-emerald-800 dark:text-emerald-200">
                  {viewerInitials}
                </AvatarFallback>
              </Avatar>
              <div className="min-w-0">
                <p className="truncate font-semibold text-foreground">{viewerName}</p>
                <p className="truncate text-xs text-muted-foreground">My attendance</p>
              </div>
            </div>
          )
        }
    }

    const companyColumn = {
        id: 'company',
        header: 'Company',
        enableSorting: true,
        accessorFn: (row) =>
          isAdmin ? row.company_name || '' : viewerCompany || '',
        cell: ({ row }) => (
          <span className="block max-w-[10rem] truncate text-sm text-foreground">
            {isAdmin ? row.original.company_name || '—' : viewerCompany || '—'}
          </span>
        ),
    }

    const departmentColumn = {
        id: 'department',
        header: 'Department',
        enableSorting: true,
        accessorFn: (row) =>
          isAdmin ? row.department || '' : viewerDepartment || '',
        cell: ({ row }) => (
          <span className="block max-w-[10rem] truncate text-sm text-foreground">
            {isAdmin ? row.original.department || '—' : viewerDepartment || '—'}
          </span>
        ),
    }

    const tailColumns = [
      {
        id: 'date',
        header: ({ column }) => <SortableHeader column={column} label="Date" />,
        accessorFn: (row) => row.date || '',
        sortingFn: (a, b) => String(a.original.date).localeCompare(String(b.original.date)),
        cell: ({ row }) => (
          <span className="whitespace-nowrap text-sm font-medium tabular-nums text-foreground">
            {formatShortDate(row.original.date)}
          </span>
        ),
      },
      {
        id: 'schedule',
        header: 'Schedule',
        enableSorting: false,
        cell: ({ row }) => (
          <span className="max-w-[11rem] text-sm leading-snug text-foreground">{formatScheduleRange(row.original)}</span>
        ),
      },
      {
        id: 'time_in',
        header: ({ column }) => <SortableHeader column={column} label="Time in" />,
        accessorFn: (row) => timeSortKey(row.time_in) ?? -1,
        cell: ({ row }) => {
          const t = mutedTimeCell(row.original.time_in)
          return (
            <span
              className={cn(
                'whitespace-nowrap font-mono text-sm tabular-nums',
                t.muted ? 'text-muted-foreground' : 'text-foreground'
              )}
            >
              {t.muted ? '—' : t.text}
            </span>
          )
        },
      },
      {
        id: 'time_out',
        header: ({ column }) => <SortableHeader column={column} label="Time out" />,
        accessorFn: (row) => timeSortKey(row.time_out) ?? -1,
        cell: ({ row }) => {
          const t = mutedTimeCell(row.original.time_out)
          return (
            <span
              className={cn(
                'whitespace-nowrap font-mono text-sm tabular-nums',
                t.muted ? 'text-muted-foreground' : 'text-foreground'
              )}
            >
              {t.muted ? '—' : t.text}
              {row.original.time_out_next_day ? (
                <span className="ml-1 text-[10px] font-sans font-normal text-muted-foreground">(+1)</span>
              ) : null}
            </span>
          )
        },
      },
      {
        id: 'total_hours',
        header: ({ column }) => <SortableHeader column={column} label="Total hours" />,
        accessorFn: (row) => totalHoursSortValue(row),
        cell: ({ row }) => (
          <span className="text-sm tabular-nums text-foreground">{tableRenderedHoursLabel(row.original)}</span>
        ),
      },
      {
        id: 'late_min',
        header: ({ column }) => <SortableHeader column={column} label="Late (min)" />,
        accessorFn: (row) => tableLateMinutes(row) ?? -1,
        cell: ({ row }) => {
          const n = tableLateMinutes(row.original)
          return (
            <span
              className={cn(
                'text-sm tabular-nums',
                n != null ? 'text-amber-800 dark:text-amber-200' : 'text-muted-foreground'
              )}
            >
              {minutesCellText(n)}
            </span>
          )
        },
      },
      {
        id: 'undertime_min',
        header: 'Undertime (min)',
        enableSorting: false,
        cell: ({ row }) => {
          const n = tableUndertimeMinutes(row.original)
          return (
            <span
              className={cn('text-sm tabular-nums', n != null ? 'text-foreground' : 'text-muted-foreground')}
            >
              {minutesCellText(n)}
            </span>
          )
        },
      },
      {
        id: 'overtime_min',
        header: 'Overtime (min)',
        enableSorting: false,
        cell: ({ row }) => {
          const n = tableOvertimeMinutes(row.original)
          return (
            <span
              className={cn('text-sm tabular-nums', n != null ? 'text-foreground' : 'text-muted-foreground')}
            >
              {minutesCellText(n)}
            </span>
          )
        },
      },
      {
        id: 'unapproved_ot_hrs',
        header: 'Unapproved OT (hrs)',
        enableSorting: false,
        cell: ({ row }) => (
          <span className="text-sm tabular-nums text-foreground">{tableOtHoursHrs(row.original.unapproved_overtime_hours)}</span>
        ),
      },
      {
        id: 'approved_ot_hrs',
        header: 'Approved OT (hrs)',
        enableSorting: false,
        cell: ({ row }) => (
          <span className="text-sm tabular-nums text-foreground">{tableApprovedOtHours(row.original)}</span>
        ),
      },
      {
        id: 'overtime_status',
        header: 'Overtime Status',
        enableSorting: false,
        cell: ({ row }) => (
          <span className="text-sm text-foreground">
            {row.original.overtime_status ? String(row.original.overtime_status).replace(/_/g, ' ') : '—'}
          </span>
        ),
      },
      {
        id: 'payroll_impact_hrs',
        header: 'Payroll Impact (hrs)',
        enableSorting: false,
        cell: ({ row }) => (
          <span className="text-sm tabular-nums text-foreground">{tableOtHoursHrs(row.original.payroll_impact_hours)}</span>
        ),
      },
      {
        id: 'status',
        header: ({ column }) => <SortableHeader column={column} label="Status" />,
        accessorFn: (row) =>
          isAdmin ? resolveAdminStatusLabel(row) : resolveEmployeeStatusLabel(row),
        sortingFn: (a, b) => {
          const la = isAdmin ? resolveAdminStatusLabel(a.original) : resolveEmployeeStatusLabel(a.original)
          const lb = isAdmin ? resolveAdminStatusLabel(b.original) : resolveEmployeeStatusLabel(b.original)
          return String(la).localeCompare(String(lb), undefined, { sensitivity: 'base' })
        },
        cell: ({ row }) => {
          const r = row.original
          const label = isAdmin ? resolveAdminStatusLabel(r) : resolveEmployeeStatusLabel(r)
          return <AttendanceStatusPill status={r.status} label={label} presenceIssue={r.presence_issue} />
        },
      },
      {
        id: 'actions',
        header: () => <span className="sr-only">Actions</span>,
        enableSorting: false,
        cell: ({ row }) => {
          const r = row.original
          return (
            <Button
              type="button"
              size="sm"
              variant="outline"
              className="h-8 gap-1 px-2.5"
              onClick={(e) => {
                e.stopPropagation()
                onOpenDetails?.(r)
              }}
            >
              <Eye className="size-3.5" aria-hidden />
              Details
            </Button>
          )
        },
      },
    ]

    return [
      employeeColumn,
      ...(hideCompanyColumn ? [] : [companyColumn]),
      ...(hideDepartmentColumn ? [] : [departmentColumn]),
      ...tailColumns,
    ]
  }, [
    hideCompanyColumn,
    hideDepartmentColumn,
    isAdmin,
    onOpenDetails,
    profileImageUrl,
    viewerCompany,
    viewerDepartment,
    viewerImageSrc,
    viewerInitials,
    viewerName,
  ])

  const table = useReactTable({
    data: rows,
    columns,
    state: { sorting },
    onSortingChange: setSorting,
    getCoreRowModel: getCoreRowModel(),
    getSortedRowModel: getSortedRowModel(),
    getRowId: (r) => (isAdmin ? `${r.employee_id}-${r.date}` : r.date),
  })

  const colCount = columns.length

  if (loading) {
    return (
      <div className="hidden w-full min-w-0 overflow-x-auto rounded-xl border border-border/50 md:block">
        <table className="w-full min-w-0 text-sm">
          <tbody>
            <TableBodySkeleton rows={8} cols={colCount} />
          </tbody>
        </table>
      </div>
    )
  }

  if (!rows.length) {
    return (
      <div className="flex flex-col items-center justify-center rounded-xl border border-dashed border-border/70 bg-muted/15 px-6 py-14 text-center">
        <p className="max-w-sm text-sm font-medium text-foreground">{emptyMessage || 'No records to show.'}</p>
        <p className="mt-1 text-xs text-muted-foreground">Adjust filters or refresh to try again.</p>
      </div>
    )
  }

  return (
    <>
      <div className="hidden w-full min-w-0 md:block">
        <div className="w-full min-w-0 overflow-x-auto rounded-xl border border-border/50 bg-card shadow-sm">
          <Table className="w-full min-w-[1100px]">
            <TableHeader>
              {table.getHeaderGroups().map((hg) => (
                <TableRow
                  key={hg.id}
                  className="border-b border-border/50 bg-muted/40 hover:bg-muted/40 dark:bg-muted/25"
                >
                  {hg.headers.map((header) => (
                    <TableHead
                      key={header.id}
                      className="text-left align-middle text-[11px] font-bold uppercase tracking-wider text-muted-foreground"
                    >
                      {header.isPlaceholder ? null : flexRender(header.column.columnDef.header, header.getContext())}
                    </TableHead>
                  ))}
                </TableRow>
              ))}
            </TableHeader>
            <TableBody>
              {table.getRowModel().rows.map((tableRow) => (
                <TableRow
                  key={tableRow.id}
                  tabIndex={0}
                  className={cn(
                    'cursor-pointer border-border/40 transition-colors hover:bg-muted/25',
                    'focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-emerald-500/40',
                  )}
                  onClick={() => onOpenDetails?.(tableRow.original)}
                  onKeyDown={(e) => {
                    if (e.key === 'Enter' || e.key === ' ') {
                      e.preventDefault()
                      onOpenDetails?.(tableRow.original)
                    }
                  }}
                >
                  {tableRow.getVisibleCells().map((cell) => (
                    <TableCell key={cell.id} className="align-middle py-3">
                      {flexRender(cell.column.columnDef.cell, cell.getContext())}
                    </TableCell>
                  ))}
                </TableRow>
              ))}
            </TableBody>
          </Table>
        </div>
      </div>

      <div className="space-y-3 md:hidden">
        {rows.map((r) => {
          const id = isAdmin ? `${r.employee_id}-${r.date}` : r.date
          const label = isAdmin ? resolveAdminStatusLabel(r) : resolveEmployeeStatusLabel(r)
          const ti = mutedTimeCell(r.time_in)
          const to = mutedTimeCell(r.time_out)
          return (
            <button
              key={id}
              type="button"
              onClick={() => onOpenDetails?.(r)}
              className="w-full rounded-xl border border-border/60 bg-card p-4 text-left shadow-sm transition hover:border-emerald-500/25 hover:shadow-md focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-emerald-500/40"
            >
              <div className="flex items-start justify-between gap-2">
                <div className="min-w-0">
                  <p className="text-xs font-medium text-muted-foreground">{formatShortDate(r.date)}</p>
                  {isAdmin ? (
                    <p className="truncate font-semibold text-foreground">{r.employee_name}</p>
                  ) : (
                    <p className="truncate font-semibold text-foreground">{viewerName}</p>
                  )}
                </div>
                <AttendanceStatusPill status={r.status} label={label} presenceIssue={r.presence_issue} />
              </div>
              <div className="mt-3 grid grid-cols-2 gap-x-3 gap-y-2 text-xs">
                {!hideCompanyColumn ? (
                  <div>
                    <p className="font-semibold uppercase tracking-wide text-muted-foreground">Company</p>
                    <p className="truncate text-foreground">{isAdmin ? r.company_name || '—' : viewerCompany || '—'}</p>
                  </div>
                ) : null}
                {!hideDepartmentColumn ? (
                  <div>
                    <p className="font-semibold uppercase tracking-wide text-muted-foreground">Department</p>
                    <p className="truncate text-foreground">{isAdmin ? r.department || '—' : viewerDepartment || '—'}</p>
                  </div>
                ) : null}
                <div className="col-span-2">
                  <p className="font-semibold uppercase tracking-wide text-muted-foreground">Schedule</p>
                  <p className="text-foreground">{formatScheduleRange(r)}</p>
                </div>
                <div>
                  <p className="font-semibold uppercase tracking-wide text-muted-foreground">In</p>
                  <p className={cn('font-mono tabular-nums', ti.muted && 'text-muted-foreground')}>
                    {ti.muted ? '—' : ti.text}
                  </p>
                </div>
                <div>
                  <p className="font-semibold uppercase tracking-wide text-muted-foreground">Out</p>
                  <p className={cn('font-mono tabular-nums', to.muted && 'text-muted-foreground')}>
                    {to.muted ? '—' : to.text}
                  </p>
                </div>
                <div>
                  <p className="font-semibold uppercase tracking-wide text-muted-foreground">Total</p>
                  <p className="tabular-nums">{tableRenderedHoursLabel(r)}</p>
                </div>
                <div>
                  <p className="font-semibold uppercase tracking-wide text-muted-foreground">Late / UT / OT</p>
                  <p className="tabular-nums text-[11px] leading-relaxed">
                    {minutesCellText(tableLateMinutes(r))} / {minutesCellText(tableUndertimeMinutes(r))} /{' '}
                    {minutesCellText(tableOvertimeMinutes(r))}
                  </p>
                </div>
                <div className="col-span-2">
                  <p className="font-semibold uppercase tracking-wide text-muted-foreground">Approved / Unapproved OT (hrs)</p>
                  <p className="tabular-nums text-[11px]">
                    {tableApprovedOtHours(r)} / {tableOtHoursHrs(r.unapproved_overtime_hours)}
                  </p>
                </div>
                <div className="col-span-2">
                  <p className="font-semibold uppercase tracking-wide text-muted-foreground">Overtime Status / Payroll Impact</p>
                  <p className="text-[11px]">
                    {(r.overtime_status ? String(r.overtime_status).replace(/_/g, ' ') : '—')} / {tableOtHoursHrs(r.payroll_impact_hours)} hrs
                  </p>
                </div>
              </div>
              <p className="mt-3 text-right text-xs font-semibold text-emerald-700 dark:text-emerald-400">
                View details →
              </p>
            </button>
          )
        })}
      </div>
    </>
  )
}
