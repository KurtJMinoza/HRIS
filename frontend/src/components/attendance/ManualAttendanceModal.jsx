import { useCallback, useEffect, useMemo, useState } from 'react'
import {
  AlertTriangle,
  CalendarDays,
  CheckCircle2,
  ChevronLeft,
  ChevronRight,
  ClipboardPen,
  Clock,
  Loader2,
  Search,
} from 'lucide-react'
import {
  Dialog,
  DialogContent,
  DialogDescription,
  DialogHeader,
  DialogTitle,
} from '@/components/ui/dialog'
import { Button } from '@/components/ui/button'
import { Input } from '@/components/ui/input'
import { Label } from '@/components/ui/label'
import { Textarea } from '@/components/ui/textarea'
import { Select, SelectContent, SelectItem, SelectTrigger, SelectValue } from '@/components/ui/select'
import { Avatar, AvatarFallback, AvatarImage } from '@/components/ui/avatar'
import { useToast } from '@/components/ui/use-toast'
import { cn } from '@/lib/utils'
import {
  attendanceOutlineButtonSmClass,
  attendanceSelectContentClass,
  attendanceSelectItemClass,
} from '@/lib/attendanceUiClasses'
import { buildMonthCalendarCells, CALENDAR_MONTHS, CALENDAR_WEEKDAYS } from '@/lib/monthCalendarGrid'
import { calendarYmdInTimeZone } from '@/lib/attendanceDates'
import { displayAttendanceTime, resolveAdminStatusLabel } from '@/components/attendance/attendanceRecordUtils'
import { getInitials } from '@/components/presenceFiling/CorrectionTableCells'
import {
  createManualAttendance,
  getAdminAttendance,
  getEmployees,
  previewManualAttendance,
  profileImageUrl,
  updateManualAttendance,
} from '@/api'

const fieldClass =
  'h-10 w-full rounded-xl border-input bg-background px-3 text-sm text-foreground shadow-sm'

const modalShellClass =
  'flex h-[min(94dvh,calc(100dvh-1rem))] max-h-[min(94dvh,calc(100dvh-1rem))] w-[calc(100vw-0.75rem)] !max-w-[min(99.5vw,110rem)] flex-col overflow-hidden rounded-2xl border border-border/80 bg-card p-0 text-card-foreground shadow-[0_24px_80px_-28px_rgba(0,0,0,0.55)] scheme-light sm:w-[calc(100vw-1rem)] sm:!max-w-[min(99.5vw,110rem)] dark:border-white/10 dark:bg-card dark:scheme-dark'

const baseTileClass =
  'touch-manipulation flex h-full min-h-[4.5rem] w-full min-w-0 flex-col rounded-lg border border-border bg-card p-1.5 text-left shadow-sm transition-colors hover:bg-muted/35 focus:outline-none focus-visible:ring-2 focus-visible:ring-orange-300 sm:min-h-[5rem] sm:p-2'

function Section({ icon: Icon, title, children, className }) {
  return (
    <section className={cn('space-y-3', className)}>
      <div className="flex items-center gap-2">
        {Icon ? (
          <span className="flex size-7 items-center justify-center rounded-lg bg-muted ring-1 ring-border">
            <Icon className="size-3.5 text-brand" aria-hidden />
          </span>
        ) : null}
        <h3 className="text-[11px] font-black uppercase tracking-[0.14em] text-brand">{title}</h3>
      </div>
      {children}
    </section>
  )
}

function MetaTile({ label, value }) {
  return (
    <div className="rounded-lg border border-border/60 bg-background/80 px-2.5 py-2 dark:border-white/10">
      <p className="text-[10px] font-semibold uppercase tracking-wide text-muted-foreground">{label}</p>
      <p className="mt-0.5 line-clamp-2 text-xs font-semibold text-foreground" title={value || undefined}>{value || '—'}</p>
    </div>
  )
}

function ContextPanel({ context }) {
  if (!context) return null
  const schedule = context.schedule || {}
  const leave = context.leave
  const existing = context.existing_attendance
  const holiday = context.holiday

  return (
    <div className="space-y-2.5 rounded-2xl border border-border/70 bg-muted/20 p-3 dark:border-white/10">
      <p className="text-sm font-bold text-foreground">Attendance Context</p>
      <div className="grid grid-cols-2 gap-2">
        <MetaTile label="Schedule" value={schedule.label} />
        <MetaTile label="Shift" value={schedule.shift} />
        <MetaTile label="Day Type" value={schedule.day_type} />
        <MetaTile label="Holiday" value={holiday?.name || 'None'} />
        <MetaTile label="Leave" value={leave ? `${leave.label}${leave.is_full_day ? ' · Full Day' : ''}` : 'None'} />
        <MetaTile label="Existing" value={existing?.status || 'None'} />
        <MetaTile label="Break" value={schedule.break || 'None'} />
        <MetaTile label="Payroll" value={context.payroll_status} />
      </div>
      {schedule.schedule_type === 'flexible' && (schedule.flexible_options?.length ?? 0) > 0 && (
        <div>
          <p className="mb-1.5 text-xs font-semibold text-muted-foreground">Available Shift Options</p>
          <div className="flex flex-wrap gap-1.5">
            {schedule.flexible_options.map((opt) => (
              <span key={opt.id ?? opt.label} className="rounded-full border border-border/70 bg-card px-2.5 py-1 text-xs font-medium">
                {opt.label}
              </span>
            ))}
          </div>
        </div>
      )}
      {(context.conflicts?.length ?? 0) > 0 && (
        <div className="flex gap-2 rounded-xl border border-amber-500/35 bg-amber-500/10 px-3 py-2.5 text-sm text-amber-950 dark:text-amber-100">
          <AlertTriangle className="mt-0.5 size-4 shrink-0" />
          <div className="space-y-0.5">
            {(context.conflicts || []).map((c) => (
              <p key={c.type}>{c.message}</p>
            ))}
          </div>
        </div>
      )}
    </div>
  )
}

function PreviewPanel({ preview }) {
  if (!preview) return null
  return (
    <div className="space-y-2.5 rounded-2xl border border-emerald-500/25 bg-emerald-500/5 p-3 dark:bg-emerald-500/10">
      <div className="flex items-center gap-2">
        <CheckCircle2 className="size-4 text-emerald-600 dark:text-emerald-300" />
        <p className="text-sm font-bold text-foreground">Attendance Preview</p>
      </div>
      <div className="grid grid-cols-2 gap-2">
        <MetaTile label="Resolved Shift" value={preview.resolved_shift ? `${preview.resolved_shift.in}–${preview.resolved_shift.out}` : null} />
        <MetaTile label="Status" value={preview.status_label || preview.status} />
        <MetaTile label="Time In" value={preview.time_in} />
        <MetaTile label="Time Out" value={preview.time_out} />
        <MetaTile label="Late" value={preview.late_minutes ? `${preview.late_minutes} min` : '—'} />
        <MetaTile label="Undertime" value={preview.undertime_minutes ? `${preview.undertime_minutes} min` : '—'} />
        <MetaTile label="Total Hours" value={preview.total_hours != null ? `${Number(preview.total_hours).toFixed(2)} hrs` : null} />
        <MetaTile label="Payroll Impact" value={preview.payroll_impact_hours != null ? `${Number(preview.payroll_impact_hours).toFixed(2)} hrs` : null} />
      </div>
    </div>
  )
}

function parseTimeValue(v) {
  if (!v) return ''
  const s = String(v)
  if (s.includes('T')) return s.slice(11, 16)
  const m24 = s.match(/^(\d{1,2}):(\d{2})/)
  if (m24 && !/[ap]m/i.test(s)) return `${m24[1].padStart(2, '0')}:${m24[2]}`
  const m12 = s.match(/^(\d{1,2}):(\d{2})\s*(AM|PM)$/i)
  if (m12) {
    let h = Number(m12[1])
    const min = m12[2]
    const ap = m12[3].toUpperCase()
    if (ap === 'PM' && h < 12) h += 12
    if (ap === 'AM' && h === 12) h = 0
    return `${String(h).padStart(2, '0')}:${min}`
  }
  return ''
}

function monthBounds(year, month) {
  const from = `${year}-${String(month + 1).padStart(2, '0')}-01`
  const last = new Date(year, month + 1, 0).getDate()
  const to = `${year}-${String(month + 1).padStart(2, '0')}-${String(last).padStart(2, '0')}`
  return { from, to }
}

function tileVisual(record, isAdjacent) {
  if (!record) {
    return {
      badge: '',
      tileClass: cn(baseTileClass, isAdjacent && 'bg-muted/25 text-muted-foreground'),
      badgeClass: 'text-[10px] font-semibold leading-snug text-muted-foreground @sm:text-xs',
    }
  }
  const status = String(record.status || '')
  let tint = 'bg-card'
  let badgeTone = 'text-muted-foreground'
  if (status === 'leave') {
    tint = 'bg-blue-50 dark:bg-blue-500/12'
    badgeTone = 'text-blue-700 dark:text-blue-400'
  } else if (status === 'holiday' || record.is_holiday) {
    tint = 'bg-sky-50 dark:bg-sky-500/12'
    badgeTone = 'text-sky-700 dark:text-sky-400'
  } else if (status === 'rest' || status === 'rest_day' || record.is_rest_day) {
    tint = 'bg-slate-50 dark:bg-muted/35'
    badgeTone = 'text-slate-600 dark:text-slate-400'
  } else if (status === 'absent') {
    tint = 'bg-red-50 dark:bg-red-500/12'
    badgeTone = 'text-red-700 dark:text-red-400'
  } else if (status === 'late' || status === 'undertime' || status === 'incomplete' || status === 'halfday') {
    tint = 'bg-amber-50 dark:bg-amber-500/12'
    badgeTone = 'text-amber-800 dark:text-amber-300'
  } else if (status === 'present' || status === 'present_with_ot' || status === 'clocked_in') {
    tint = 'bg-emerald-50 dark:bg-emerald-500/12'
    badgeTone = 'text-emerald-700 dark:text-emerald-400'
  }
  const badge = resolveAdminStatusLabel(record)
  return {
    badge: badge && badge !== '—' ? badge : '',
    tileClass: cn(baseTileClass, tint),
    badgeClass: cn('block max-w-full text-[10px] font-semibold leading-snug tracking-tight line-clamp-2 @sm:text-xs', badgeTone),
  }
}

function tileTimeLines(record) {
  if (!record) return []
  const rows = []
  const timeIn = displayAttendanceTime(record.time_in, record.formatted_time_in)
  const timeOut = displayAttendanceTime(record.time_out, record.formatted_time_out)
  if (timeIn && timeIn !== '—') rows.push({ label: 'In', value: timeIn })
  if (timeOut && timeOut !== '—') rows.push({ label: 'Out', value: timeOut })
  return rows
}

function employeeSearchHaystack(emp) {
  return [
    emp?.name,
    emp?.formatted_name,
    emp?.display_name,
    emp?.employee_id,
    emp?.employee_code,
    emp?.username,
    emp?.email,
    emp?.company_name,
    emp?.branch_name,
    emp?.department_name,
    emp?.department,
  ]
    .filter(Boolean)
    .join(' ')
    .toLowerCase()
}

function EmployeePicker({ employees, value, onChange, disabled, loading = false }) {
  const [search, setSearch] = useState('')
  const selected = useMemo(
    () => employees.find((e) => String(e.id) === String(value)),
    [employees, value],
  )
  const filtered = useMemo(() => {
    const q = search.trim().toLowerCase()
    if (!q) return employees
    return employees.filter((emp) => employeeSearchHaystack(emp).includes(q))
  }, [employees, search])

  if (disabled && selected) {
    return (
      <div className="flex items-center gap-3 rounded-xl border border-border/70 bg-muted/20 px-3 py-2.5 dark:border-white/10">
        <Avatar className="size-9 shrink-0 border border-border/60">
          {selected.profile_image ? <AvatarImage src={profileImageUrl(selected.profile_image)} alt="" /> : null}
          <AvatarFallback className="text-[10px] font-bold">{getInitials(selected.name)}</AvatarFallback>
        </Avatar>
        <span className="min-w-0 flex-1">
          <span className="block truncate text-sm font-semibold text-foreground">{selected.name}</span>
          <span className="block truncate text-xs text-muted-foreground">
            {[selected.employee_id || selected.employee_code, selected.company_name, selected.department_name || selected.department].filter(Boolean).join(' · ')}
          </span>
        </span>
      </div>
    )
  }

  return (
    <div className="space-y-2">
      <div className="relative">
        <Search className="pointer-events-none absolute left-3 top-1/2 size-4 -translate-y-1/2 text-muted-foreground" />
        <Input
          value={search}
          onChange={(e) => setSearch(e.target.value)}
          placeholder="Filter by name or employee number…"
          className="h-10 rounded-xl pl-9 text-sm"
          disabled={disabled || loading}
        />
      </div>
      <p className="px-0.5 text-[11px] text-muted-foreground">
        {loading
          ? 'Loading employees…'
          : `${filtered.length} of ${employees.length} employees`}
      </p>
      <div className="max-h-56 overflow-y-auto overscroll-contain rounded-xl border border-border/70 dark:border-white/10">
        {loading ? (
          <p className="inline-flex w-full items-center justify-center gap-2 px-3 py-8 text-sm text-muted-foreground">
            <Loader2 className="size-4 animate-spin" /> Loading employees…
          </p>
        ) : filtered.length === 0 ? (
          <p className="px-3 py-8 text-center text-sm text-muted-foreground">
            {employees.length === 0 ? 'No employees available.' : 'No matching employees.'}
          </p>
        ) : filtered.map((emp) => (
          <button
            key={emp.id}
            type="button"
            disabled={disabled}
            className={cn(
              'flex w-full items-center gap-2.5 border-b border-border/60 px-3 py-2 text-left last:border-b-0 dark:border-white/10',
              String(emp.id) === String(value) ? 'bg-brand/10' : 'hover:bg-muted/40',
              disabled && 'cursor-not-allowed opacity-60',
            )}
            onClick={() => onChange(String(emp.id))}
          >
            <Avatar className="size-8 shrink-0 border border-border/60">
              {emp.profile_image ? <AvatarImage src={profileImageUrl(emp.profile_image)} alt="" /> : null}
              <AvatarFallback className="text-[10px] font-bold">{getInitials(emp.name)}</AvatarFallback>
            </Avatar>
            <span className="min-w-0 flex-1">
              <span className="block truncate text-sm font-semibold">{emp.name}</span>
              <span className="block truncate text-xs text-muted-foreground">
                {[emp.employee_id || emp.employee_code, emp.company_name, emp.branch_name, emp.department_name || emp.department].filter(Boolean).join(' · ')}
              </span>
            </span>
          </button>
        ))}
      </div>
    </div>
  )
}

function AttendanceMonthCalendar({
  year,
  month,
  onYearMonthChange,
  recordByDate,
  loading,
  selectedDates,
  onToggleDate,
  multiSelect,
  disabled,
}) {
  const todayKey = calendarYmdInTimeZone(new Date())
  const cells = useMemo(() => buildMonthCalendarCells(year, month), [year, month])
  const selectedSet = useMemo(() => new Set(selectedDates), [selectedDates])

  const goPrev = () => {
    if (month === 0) onYearMonthChange(year - 1, 11)
    else onYearMonthChange(year, month - 1)
  }
  const goNext = () => {
    if (month === 11) onYearMonthChange(year + 1, 0)
    else onYearMonthChange(year, month + 1)
  }
  const goToday = () => {
    const now = new Date()
    onYearMonthChange(now.getFullYear(), now.getMonth())
  }

  return (
    <div className="space-y-2 rounded-2xl border border-border/70 bg-card p-2.5 dark:border-white/10 sm:p-3">
      <div className="flex w-full items-center justify-center gap-0.5 rounded-xl border border-border bg-muted/15 p-0.5">
        <Button type="button" variant="ghost" size="icon" className="size-8 shrink-0 rounded-lg" onClick={goPrev} aria-label="Previous month">
          <ChevronLeft className="size-4" />
        </Button>
        <button
          type="button"
          onClick={goToday}
          className="min-w-0 flex-1 truncate px-2 py-1.5 text-center text-sm font-semibold tracking-tight text-foreground hover:bg-background/60"
        >
          {CALENDAR_MONTHS[month]} {year}
        </button>
        <Button type="button" variant="ghost" size="icon" className="size-8 shrink-0 rounded-lg" onClick={goNext} aria-label="Next month">
          <ChevronRight className="size-4" />
        </Button>
      </div>
      {loading && (
        <div className="h-1 overflow-hidden rounded-full bg-muted">
          <div className="h-full w-1/3 animate-pulse rounded-full bg-orange-500/70" />
        </div>
      )}
      <p className="text-[11px] text-muted-foreground">
        {multiSelect
          ? 'Tap days to multi-select. In/Out show on each day.'
          : 'Attendance day for this record.'}
      </p>
      <div className="grid w-full min-w-0 grid-cols-7 grid-rows-[auto_repeat(6,minmax(3.75rem,auto))] gap-1 @sm:grid-rows-[auto_repeat(6,minmax(4.25rem,auto))]">
        {CALENDAR_WEEKDAYS.map((w) => (
          <div
            key={w}
            className="min-w-0 rounded-md bg-muted/30 px-0.5 py-1 text-center text-[9px] font-extrabold uppercase tracking-wide text-muted-foreground @sm:text-[10px]"
          >
            {w.charAt(0)}
          </div>
        ))}
        {cells.map((cell, idx) => {
          const key = cell.dateStr
          const record = recordByDate.get(key) || null
          const visual = tileVisual(record, cell.isAdjacent)
          const timeLines = tileTimeLines(record)
          const isToday = key === todayKey
          const isSelected = selectedSet.has(key)
          const monthShort = CALENDAR_MONTHS[cell.month]?.slice(0, 3) ?? ''

          return (
            <div key={`${key}-${idx}`} className="flex min-h-[3.75rem] min-w-0 @sm:min-h-[4.25rem]">
              <button
                type="button"
                disabled={disabled}
                title={[visual.badge, ...timeLines.map((r) => `${r.label}: ${r.value}`)].filter(Boolean).join('\n') || undefined}
                onClick={() => onToggleDate(key)}
                className={cn(
                  visual.tileClass,
                  isToday && 'ring-1 ring-orange-500 ring-offset-1 ring-offset-background',
                  isSelected && 'z-[1] border-orange-500 ring-2 ring-orange-400/80 ring-offset-1 ring-offset-background',
                  cell.isAdjacent && record && 'opacity-[0.88]',
                  disabled && 'cursor-default opacity-90',
                )}
              >
                <div className="flex items-start justify-between gap-0.5">
                  <span className={cn('text-[11px] font-semibold tabular-nums @sm:text-sm', cell.isAdjacent && !record && 'text-muted-foreground/80')}>
                    {isToday ? (
                      <span className="inline-flex min-w-5 items-center justify-center rounded-md bg-orange-500 px-1 py-0.5 text-[10px] font-semibold text-white">
                        {cell.day}
                      </span>
                    ) : (
                      cell.day
                    )}
                  </span>
                  {cell.isAdjacent && (
                    <span className="shrink-0 text-[7px] font-medium uppercase text-muted-foreground">{monthShort}</span>
                  )}
                </div>
                {visual.badge || timeLines.length > 0 ? (
                  <div className="mt-auto space-y-0.5 pt-0.5">
                    {visual.badge ? <span className={cn(visual.badgeClass, 'text-[9px] @sm:text-[10px]')}>{visual.badge}</span> : null}
                    {timeLines.length > 0 && (
                      <div className="space-y-0 text-left text-[8px] font-semibold leading-tight text-muted-foreground @sm:text-[9px]">
                        {timeLines.slice(0, 2).map((row) => (
                          <div key={row.label} className="truncate tabular-nums">
                            <span className="uppercase tracking-wide">{row.label}</span>{' '}
                            <span className="text-foreground/80">{row.value}</span>
                          </div>
                        ))}
                      </div>
                    )}
                  </div>
                ) : (
                  <div className="mt-auto min-h-3" aria-hidden />
                )}
              </button>
            </div>
          )
        })}
      </div>
      {selectedDates.length > 0 && (
        <div className="flex max-h-20 flex-wrap items-center gap-1.5 overflow-y-auto border-t border-border/60 pt-2">
          <span className="text-[11px] font-semibold text-muted-foreground">{selectedDates.length} day{selectedDates.length === 1 ? '' : 's'}:</span>
          {selectedDates.map((d) => (
            <button
              key={d}
              type="button"
              disabled={disabled || !multiSelect}
              onClick={() => onToggleDate(d)}
              className="rounded-full border border-orange-500/40 bg-orange-500/10 px-2 py-0.5 text-[10px] font-semibold tabular-nums text-orange-900 dark:text-orange-100"
            >
              {d}
            </button>
          ))}
        </div>
      )}
    </div>
  )
}

export function ManualAttendanceModal({
  open,
  onOpenChange,
  onSaved,
  editRecord = null,
  reasonCodes = {},
  canOverrideConflict = false,
  employees: employeesProp = null,
  employeesLoading: employeesLoadingProp = false,
}) {
  const { toast } = useToast()
  const isEdit = Boolean(editRecord?.id)
  const now = new Date()

  const [employeesLocal, setEmployeesLocal] = useState([])
  const [employeesLocalLoading, setEmployeesLocalLoading] = useState(false)
  // Parent often passes [] while loading; empty array must not block the local roster fetch.
  const employees = (Array.isArray(employeesProp) && employeesProp.length > 0)
    ? employeesProp
    : employeesLocal
  const employeesLoading = Boolean(employeesLoadingProp) || (employees.length === 0 && employeesLocalLoading)
  const [employeeId, setEmployeeId] = useState('')
  const [selectedDates, setSelectedDates] = useState([])
  const [calYear, setCalYear] = useState(now.getFullYear())
  const [calMonth, setCalMonth] = useState(now.getMonth())
  const [recordByDate, setRecordByDate] = useState(() => new Map())
  const [calLoading, setCalLoading] = useState(false)
  const [timeIn, setTimeIn] = useState('')
  const [timeOut, setTimeOut] = useState('')
  const [reasonCode, setReasonCode] = useState('administrative_correction')
  const [manualRemarks, setManualRemarks] = useState('')
  const [editReason, setEditReason] = useState('')
  const [shiftMatchMode, setShiftMatchMode] = useState('auto')
  const [scheduleOptionId, setScheduleOptionId] = useState('')
  const [conflictAction, setConflictAction] = useState('create')
  const [overrideLeave, setOverrideLeave] = useState(false)
  const [partialDay, setPartialDay] = useState(false)

  const [context, setContext] = useState(null)
  const [preview, setPreview] = useState(null)
  const [loadingContext, setLoadingContext] = useState(false)
  const [saving, setSaving] = useState(false)

  const date = selectedDates[selectedDates.length - 1] || ''

  useEffect(() => {
    if (!open) return
    if (Array.isArray(employeesProp) && employeesProp.length > 0) return
    let cancelled = false
    setEmployeesLocalLoading(true)
    getEmployees({ per_page: 'all', lite: 1, active_filter: 'active' })
      .then((res) => {
        if (!cancelled) setEmployeesLocal(res?.employees ?? [])
      })
      .catch(() => {
        if (!cancelled) setEmployeesLocal([])
      })
      .finally(() => {
        if (!cancelled) setEmployeesLocalLoading(false)
      })
    return () => {
      cancelled = true
    }
  }, [open, employeesProp])

  useEffect(() => {
    if (!open) return
    if (editRecord) {
      const d = editRecord.date || ''
      setEmployeeId(String(editRecord.employee_id || ''))
      setSelectedDates(d ? [d] : [])
      if (d) {
        const [y, m] = d.split('-').map(Number)
        if (y && m) {
          setCalYear(y)
          setCalMonth(m - 1)
        }
      }
      setReasonCode(editRecord.reason_code || 'administrative_correction')
      setManualRemarks(editRecord.manual_remarks || '')
      setTimeIn(parseTimeValue(editRecord.time_in))
      setTimeOut(parseTimeValue(editRecord.time_out))
    } else {
      const n = new Date()
      setEmployeeId('')
      setSelectedDates([])
      setCalYear(n.getFullYear())
      setCalMonth(n.getMonth())
      setTimeIn('')
      setTimeOut('')
      setReasonCode('administrative_correction')
      setManualRemarks('')
      setEditReason('')
      setConflictAction('create')
      setOverrideLeave(false)
      setPartialDay(false)
      setShiftMatchMode('auto')
      setScheduleOptionId('')
    }
    setContext(null)
    setPreview(null)
    setRecordByDate(new Map())
  }, [open, editRecord])

  const selectedEmployee = useMemo(
    () => employees.find((e) => String(e.id) === String(employeeId)),
    [employees, employeeId],
  )

  const loadCalendarMonth = useCallback(async (empId, year, month) => {
    if (!empId) {
      setRecordByDate(new Map())
      return
    }
    setCalLoading(true)
    const { from, to } = monthBounds(year, month)
    try {
      const data = await getAdminAttendance({
        employee_id: Number(empId),
        from_date: from,
        to_date: to,
        per_page: 50,
      })
      const map = new Map()
      for (const row of data?.rows || []) {
        const key = String(row.date || '').slice(0, 10)
        if (key) map.set(key, row)
      }
      setRecordByDate(map)
    } catch {
      // ponytail: calendar details need attendance.view; selection still works without tiles
      setRecordByDate(new Map())
    } finally {
      setCalLoading(false)
    }
  }, [])

  useEffect(() => {
    if (!open || !employeeId) return
    void loadCalendarMonth(employeeId, calYear, calMonth)
  }, [open, employeeId, calYear, calMonth, loadCalendarMonth])

  const handleEmployeeChange = (id) => {
    setEmployeeId(id)
    setSelectedDates([])
    setContext(null)
    setPreview(null)
    setConflictAction('create')
  }

  const toggleDate = (dateStr) => {
    if (isEdit) return
    setSelectedDates((prev) => {
      if (prev.includes(dateStr)) return prev.filter((d) => d !== dateStr)
      return [...prev, dateStr].sort()
    })
    setConflictAction('create')
  }

  const fetchPreview = useCallback(async () => {
    if (!employeeId || !date) return
    setLoadingContext(true)
    try {
      const payload = {
        employee_id: Number(employeeId),
        date,
        time_in: timeIn || undefined,
        time_out: timeOut || undefined,
        shift_match_mode: shiftMatchMode,
        schedule_option_id: scheduleOptionId ? Number(scheduleOptionId) : undefined,
      }
      const data = await previewManualAttendance(payload)
      setContext(data.context || null)
      setPreview(data.preview || null)

      const hasExisting = data.context?.existing_attendance?.has_complete_pair
        || data.context?.existing_attendance?.has_partial
        || data.context?.existing_manual
      if (hasExisting && conflictAction === 'create' && !isEdit) {
        setConflictAction(data.context?.existing_attendance?.has_partial ? 'complete_missing' : 'replace')
      }
    } catch (err) {
      toast({ variant: 'destructive', title: 'Preview failed', description: err.message })
    } finally {
      setLoadingContext(false)
    }
  }, [employeeId, date, timeIn, timeOut, shiftMatchMode, scheduleOptionId, conflictAction, isEdit, toast])

  useEffect(() => {
    if (!open || !employeeId || !date) return
    const timer = setTimeout(fetchPreview, 400)
    return () => clearTimeout(timer)
  }, [open, employeeId, date, timeIn, timeOut, shiftMatchMode, scheduleOptionId, fetchPreview])

  const handleSave = async () => {
    if (!timeIn || !timeOut) {
      toast({ variant: 'destructive', title: 'Time In and Time Out are required' })
      return
    }
    if (selectedDates.length === 0) {
      toast({ variant: 'destructive', title: 'Select at least one date on the calendar' })
      return
    }
    if (reasonCode === 'other' && !manualRemarks.trim()) {
      toast({ variant: 'destructive', title: 'Remarks required when reason is Other' })
      return
    }
    if (isEdit && !editReason.trim()) {
      toast({ variant: 'destructive', title: 'Edit reason is required' })
      return
    }
    setSaving(true)
    try {
      const basePayload = {
        employee_id: Number(employeeId),
        time_in: timeIn,
        time_out: timeOut,
        reason_code: reasonCode,
        manual_remarks: manualRemarks,
        shift_match_mode: shiftMatchMode,
        schedule_option_id: scheduleOptionId ? Number(scheduleOptionId) : undefined,
        conflict_action: conflictAction === 'add_segment' ? 'replace' : conflictAction,
        override_leave: overrideLeave,
        partial_day: partialDay,
      }
      if (isEdit) {
        await updateManualAttendance(editRecord.id, { ...basePayload, edit_reason: editReason })
        toast({ title: 'Manual attendance updated' })
      } else {
        // Multi-day: one request. Default create→replace so days with existing attendance still save.
        const conflict =
          selectedDates.length > 1 && (basePayload.conflict_action === 'create' || !basePayload.conflict_action)
            ? 'replace'
            : basePayload.conflict_action
        const data = await createManualAttendance({
          ...basePayload,
          conflict_action: conflict,
          ...(selectedDates.length === 1
            ? { date: selectedDates[0] }
            : { dates: selectedDates }),
        })
        const saved = Number(data.saved ?? (selectedDates.length === 1 ? 1 : 0))
        const failed = Array.isArray(data.failed) ? data.failed : []
        toast({
          title: saved === 1
            ? 'Manual attendance saved'
            : `Saved ${saved} of ${selectedDates.length} days`,
          description: failed.length
            ? failed.slice(0, 3).map((f) => `${f.date}: ${f.message}`).join(' · ')
            : 'Approved immediately.',
        })
      }
      onSaved?.()
      onOpenChange(false)
    } catch (err) {
      toast({ variant: 'destructive', title: 'Save failed', description: err.message })
    } finally {
      setSaving(false)
    }
  }

  const flexOptions = context?.schedule?.flexible_options || []
  const showConflictActions = !isEdit && (
    context?.existing_attendance?.has_complete_pair
    || context?.existing_attendance?.has_partial
    || context?.existing_manual
  )
  const showLeaveActions = context?.leave?.is_full_day
  const saveLabel = isEdit
    ? 'Save Changes'
    : selectedDates.length > 1
      ? `Save ${selectedDates.length} Days`
      : 'Save Manual Attendance'

  return (
    <Dialog open={open} onOpenChange={onOpenChange}>
      <DialogContent
        showCloseButton
        closeButtonClassName="right-3 top-3 size-9 rounded-lg border-border/80 bg-card/95 text-foreground shadow-md hover:bg-muted sm:right-4 sm:top-3.5 sm:size-9"
        innerClassName="flex min-h-0 flex-1 flex-col gap-0 overflow-hidden p-0"
        className={modalShellClass}
      >
        <DialogHeader className="shrink-0 border-b border-border/70 px-4 py-3 text-left sm:px-5 sm:py-3.5">
          <div className="flex items-start gap-3 pr-10">
            <div className="flex size-10 shrink-0 items-center justify-center rounded-xl bg-muted ring-1 ring-border">
              <ClipboardPen className="size-5 text-brand" aria-hidden />
            </div>
            <div className="min-w-0 space-y-0.5">
              <p className="text-[10px] font-black uppercase tracking-[0.18em] text-brand">
                Manual attendance
              </p>
              <DialogTitle className="text-lg font-black tracking-tight text-foreground sm:text-xl">
                {isEdit ? 'Edit Manual Attendance' : 'Add Manual Attendance'}
              </DialogTitle>
              <DialogDescription className="text-xs leading-relaxed text-muted-foreground sm:text-sm">
                Left: employee + calendar. Right: times, reason, and preview.
              </DialogDescription>
            </div>
          </div>
        </DialogHeader>

        <div className="grid min-h-0 flex-1 grid-cols-1 overflow-hidden lg:grid-cols-[minmax(0,1.15fr)_minmax(20rem,0.85fr)]">
          {/* Left: employee + calendar + context */}
          <div className="min-h-0 space-y-4 overflow-y-auto overscroll-contain border-b border-border/70 px-4 py-4 lg:border-b-0 lg:border-r lg:px-5 lg:py-4 dark:border-white/10">
            <Section icon={CalendarDays} title="Employee">
              <div className="space-y-2">
                <Label className="text-sm font-bold">Employee <span className="text-destructive">*</span></Label>
                <EmployeePicker
                  employees={employees}
                  value={employeeId}
                  onChange={handleEmployeeChange}
                  disabled={isEdit}
                  loading={employeesLoading}
                />
                {selectedEmployee && (
                  <p className="text-xs text-muted-foreground">
                    {[selectedEmployee.branch_name, selectedEmployee.department_name || selectedEmployee.department, selectedEmployee.employment_status, selectedEmployee.payroll_type]
                      .filter(Boolean)
                      .join(' · ')}
                  </p>
                )}
              </div>
            </Section>

            {employeeId ? (
              <Section icon={CalendarDays} title={isEdit ? 'Attendance Date' : 'Select Dates'}>
                <AttendanceMonthCalendar
                  year={calYear}
                  month={calMonth}
                  onYearMonthChange={(y, m) => {
                    setCalYear(y)
                    setCalMonth(m)
                  }}
                  recordByDate={recordByDate}
                  loading={calLoading}
                  selectedDates={selectedDates}
                  onToggleDate={toggleDate}
                  multiSelect={!isEdit}
                  disabled={isEdit}
                />
              </Section>
            ) : (
              <div className="rounded-2xl bg-muted/15 px-4 py-10 text-center text-sm text-muted-foreground">
                Select an employee to open their attendance calendar.
              </div>
            )}

            {loadingContext ? (
              <div className="flex items-center gap-2 rounded-xl border border-border/60 bg-muted/20 px-3 py-2.5 text-sm text-muted-foreground">
                <Loader2 className="size-4 animate-spin" /> Resolving schedule, leave, and payroll context…
              </div>
            ) : (
              <>
                {selectedDates.length > 1 && (
                  <p className="text-[11px] text-muted-foreground">
                    Context is for the last selected day ({date}). Same times apply to all selected days.
                  </p>
                )}
                <ContextPanel context={context} />
              </>
            )}
          </div>

          {/* Right: time logs, conflicts, reason, preview */}
          <div className="min-h-0 space-y-4 overflow-y-auto overscroll-contain px-4 py-4 lg:px-5 lg:py-4">
            {flexOptions.length > 0 && (
              <Section icon={Clock} title="Matched Shift">
                <div className="flex flex-wrap gap-3 text-sm">
                  <label className="inline-flex items-center gap-2 font-medium">
                    <input type="radio" className="size-4" checked={shiftMatchMode === 'auto'} onChange={() => setShiftMatchMode('auto')} />
                    Auto-detect
                  </label>
                  <label className="inline-flex items-center gap-2 font-medium">
                    <input type="radio" className="size-4" checked={shiftMatchMode === 'explicit'} onChange={() => setShiftMatchMode('explicit')} />
                    Select shift
                  </label>
                </div>
                {shiftMatchMode === 'explicit' && (
                  <Select value={scheduleOptionId} onValueChange={setScheduleOptionId}>
                    <SelectTrigger className={cn(fieldClass, 'mt-1 w-full')}>
                      <SelectValue placeholder="Choose shift option" />
                    </SelectTrigger>
                    <SelectContent className={attendanceSelectContentClass}>
                      {flexOptions.map((opt) => (
                        <SelectItem key={opt.id ?? opt.label} value={String(opt.id ?? opt.label)} className={attendanceSelectItemClass}>
                          {opt.label}
                        </SelectItem>
                      ))}
                    </SelectContent>
                  </Select>
                )}
              </Section>
            )}

            <Section icon={Clock} title="Time Logs">
              <p className="text-[11px] text-muted-foreground">
                Cross-midnight outs supported
                {selectedDates.length > 1 ? ` · applied to ${selectedDates.length} days` : ''}.
              </p>
              <div className="grid gap-3 grid-cols-2">
                <div className="space-y-1.5">
                  <Label className="text-xs font-bold">Time In <span className="text-destructive">*</span></Label>
                  <Input type="time" value={timeIn} onChange={(e) => setTimeIn(e.target.value)} className={fieldClass} />
                </div>
                <div className="space-y-1.5">
                  <Label className="text-xs font-bold">Time Out <span className="text-destructive">*</span></Label>
                  <Input type="time" value={timeOut} onChange={(e) => setTimeOut(e.target.value)} className={fieldClass} />
                </div>
              </div>
            </Section>

            {showConflictActions && (
              <div className="space-y-2.5 rounded-2xl border border-amber-500/35 bg-amber-500/8 p-3">
                <p className="text-sm font-bold text-amber-950 dark:text-amber-100">Existing Attendance Found</p>
                <Select value={conflictAction === 'add_segment' ? 'replace' : conflictAction} onValueChange={setConflictAction}>
                  <SelectTrigger className={cn(fieldClass, 'w-full')}><SelectValue /></SelectTrigger>
                  <SelectContent className={attendanceSelectContentClass}>
                    <SelectItem value="complete_missing" className={attendanceSelectItemClass}>Complete Missing Log</SelectItem>
                    <SelectItem value="replace" className={attendanceSelectItemClass}>Replace Existing Attendance</SelectItem>
                  </SelectContent>
                </Select>
              </div>
            )}

            {showLeaveActions && (
              <div className="space-y-2.5 rounded-2xl border border-amber-500/35 bg-amber-500/8 p-3">
                <p className="text-sm font-bold text-amber-950 dark:text-amber-100">
                  Approved Leave — {context.leave.label}
                </p>
                <div className="flex flex-col gap-2 text-sm">
                  <label className="inline-flex items-center gap-2 font-medium">
                    <input type="radio" className="size-4" checked={!overrideLeave && !partialDay} onChange={() => { setOverrideLeave(false); setPartialDay(false) }} />
                    Block (default)
                  </label>
                  <label className="inline-flex items-center gap-2 font-medium">
                    <input type="radio" className="size-4" checked={partialDay} onChange={() => { setPartialDay(true); setOverrideLeave(false) }} />
                    Partial-Day Attendance
                  </label>
                  {canOverrideConflict && (
                    <label className="inline-flex items-center gap-2 font-medium">
                      <input type="radio" className="size-4" checked={overrideLeave} onChange={() => { setOverrideLeave(true); setPartialDay(false) }} />
                      Override Leave Conflict
                    </label>
                  )}
                </div>
              </div>
            )}

            <Section icon={ClipboardPen} title="Reason">
              <div className="space-y-3">
                <div className="space-y-1.5">
                  <Label className="text-sm font-bold">Reason <span className="text-destructive">*</span></Label>
                  <Select value={reasonCode} onValueChange={setReasonCode}>
                    <SelectTrigger className={cn(fieldClass, 'w-full')}><SelectValue /></SelectTrigger>
                    <SelectContent className={attendanceSelectContentClass}>
                      {Object.entries(reasonCodes).map(([code, label]) => (
                        <SelectItem key={code} value={code} className={attendanceSelectItemClass}>{label}</SelectItem>
                      ))}
                    </SelectContent>
                  </Select>
                </div>
                <div className="space-y-1.5">
                  <Label className="text-sm font-bold">
                    Remarks{reasonCode === 'other' ? <span className="text-destructive"> *</span> : null}
                  </Label>
                  <Textarea
                    value={manualRemarks}
                    onChange={(e) => setManualRemarks(e.target.value)}
                    rows={2}
                    className="rounded-xl"
                    placeholder="Why is this manual entry required?"
                  />
                </div>
                {isEdit && (
                  <div className="space-y-1.5">
                    <Label className="text-sm font-bold">Edit Reason <span className="text-destructive">*</span></Label>
                    <Textarea
                      value={editReason}
                      onChange={(e) => setEditReason(e.target.value)}
                      rows={2}
                      className="rounded-xl"
                      placeholder="What changed and why?"
                    />
                  </div>
                )}
              </div>
            </Section>

            <PreviewPanel preview={preview} />
          </div>
        </div>

        <div className="mt-auto flex shrink-0 flex-col-reverse gap-2 border-t border-border/70 bg-muted/15 px-4 py-3 sm:flex-row sm:items-center sm:justify-between sm:gap-3 sm:px-5 sm:py-3.5">
          <p className="text-[11px] text-muted-foreground sm:text-xs">
            {employeeId
              ? selectedDates.length
                ? `${selectedDates.length} day${selectedDates.length === 1 ? '' : 's'} selected`
                : 'Select date(s) on the calendar'
              : 'Select an employee to begin'}
          </p>
          <div className="flex flex-col-reverse gap-2 sm:flex-row sm:justify-end sm:gap-2">
            <Button type="button" variant="outline" className={attendanceOutlineButtonSmClass} onClick={() => onOpenChange(false)}>
              Cancel
            </Button>
            <Button
              type="button"
              className="h-9 rounded-lg bg-orange-500 px-4 text-sm font-semibold text-white shadow-sm hover:bg-orange-600"
              disabled={saving || !employeeId || selectedDates.length === 0 || !timeIn || !timeOut}
              onClick={handleSave}
            >
              {saving ? <Loader2 className="mr-2 size-4 animate-spin" /> : null}
              {saveLabel}
            </Button>
          </div>
        </div>
      </DialogContent>
    </Dialog>
  )
}

export default ManualAttendanceModal
