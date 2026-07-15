import { createElement, useEffect, useState, useMemo, useCallback } from 'react'
import {
  AlertTriangle,
  Calendar,
  CalendarCheck,
  CheckCircle2,
  Clock,
  Copy,
  Download,
  Layers,
  Loader2,
  Lock,
  PieChart,
  Plus,
  Search,
  ShieldCheck,
  Trash2,
  Users,
} from 'lucide-react'
import {
  flexRender,
  getCoreRowModel,
  getFilteredRowModel,
  getPaginationRowModel,
  getSortedRowModel,
  useReactTable,
} from '@tanstack/react-table'
import { format, formatDistanceToNow, isValid } from 'date-fns'
import { TableBodySkeleton } from '@/components/skeletons'
import { Card, CardContent } from '@/components/ui/card'
import { Avatar, AvatarFallback, AvatarImage } from '@/components/ui/avatar'
import { Badge } from '@/components/ui/badge'
import { Button } from '@/components/ui/button'
import { Input } from '@/components/ui/input'
import { Checkbox } from '@/components/ui/checkbox'
import { Tabs, TabsContent, TabsList, TabsTrigger } from '@/components/ui/tabs'
import { ScheduleEditorDialog } from '@/components/schedules/ScheduleEditorDialog'
import { ScheduleAdjustmentDialog } from '@/components/schedules/ScheduleAdjustmentDialog'
import { ShiftPill } from '@/components/schedules/ShiftPill'
import { ndOverlapMinutes, computePaidMinutes, SHIFT_TYPES, formatPaidHours } from '@/lib/scheduleLib'
import {
  Dialog,
  DialogContent,
  DialogFooter,
  DialogHeader,
  DialogTitle,
} from '@/components/ui/dialog'
import {
  getWorkingSchedules,
  getScheduleActivity,
  createWorkingSchedule,
  updateWorkingSchedule,
  deleteWorkingSchedule,
  assignWorkingSchedule,
  getEmployees,
  profileImageUrl,
  updateEmployeeSchedule,
} from '@/api'
import { useQueryClient } from '@tanstack/react-query'
import { toast } from 'sonner'
import { hasEmoji, hasFancyUnicode } from '@/validation'
import { cn } from '@/lib/utils'
import { FIELD_SELECT_CLASS_H10 } from '@/lib/fieldClasses'
import {
  ADMIN_FORM_DIALOG_DESC_CLASS,
  ADMIN_FORM_DIALOG_FOOTER_CLASS,
  ADMIN_FORM_DIALOG_HEADER_INNER_CLASS,
  ADMIN_FORM_DIALOG_HEADER_WRAP_CLASS,
  ADMIN_FORM_DIALOG_PRIMARY_BUTTON_CLASS,
  ADMIN_FORM_DIALOG_TITLE_CLASS,
  adminFormDialogContentClass,
  ADMIN_FORM_DIALOG_MAX_W_MD,
} from '@/lib/adminFormDialogStyles'
import { formatShiftRange12h, formatScheduleLabel12h, toHhMm } from '@/lib/timeFormat'

function validateSimpleLabel(value, fieldLabel) {
  const trimmed = value.trim()
  if (!trimmed) return `${fieldLabel} is required.`
  if (hasEmoji(trimmed)) return 'Emojis are not allowed.'
  if (hasFancyUnicode(trimmed)) {
    return 'Please use standard letters/numbers only (no styled fonts or special symbols).'
  }
  if (!/^[A-Za-z0-9\s\-']+$/.test(trimmed)) {
    return 'Only letters, numbers, spaces, hyphens, and apostrophes are allowed.'
  }
  if (trimmed.length > 100) return `${fieldLabel} must be 100 characters or less.`
  return ''
}

// Abbreviated day format: M, T, W, Th, F, S, Su (used for display and validation)
const DAY_OPTIONS = [
  { key: 'mon', label: 'M', full: 'Monday' },
  { key: 'tue', label: 'T', full: 'Tuesday' },
  { key: 'wed', label: 'W', full: 'Wednesday' },
  { key: 'thu', label: 'Th', full: 'Thursday' },
  { key: 'fri', label: 'F', full: 'Friday' },
  { key: 'sat', label: 'S', full: 'Saturday' },
  { key: 'sun', label: 'Su', full: 'Sunday' },
]

const DAY_ORDER = ['mon', 'tue', 'wed', 'thu', 'fri', 'sat', 'sun']

/** Default weekly days off for new templates (PH common case: single Sunday off). */
const DEFAULT_REST_DAYS = ['sun']

const workScheduleCardClass =
  'rounded-[18px] border border-border/70 bg-card text-card-foreground shadow-[0_12px_34px_-24px_rgba(15,23,42,0.55),0_2px_10px_-7px_rgba(15,23,42,0.25)] dark:border-white/10 dark:bg-card/95 dark:shadow-[0_18px_44px_-24px_rgba(0,0,0,0.75)]'
const workSchedulePrimaryButtonClass =
  'h-12 gap-2 rounded-lg bg-brand px-6 text-base font-semibold text-brand-foreground shadow-[0_12px_22px_-14px_rgba(234,88,12,0.9)] transition hover:bg-brand-strong dark:shadow-[0_12px_24px_-16px_rgba(251,146,60,0.75)]'
const workScheduleOutlineButtonClass =
  'h-12 gap-2 rounded-lg border-border/80 bg-card px-5 text-base font-semibold text-foreground shadow-sm transition hover:border-brand/45 hover:bg-brand/10 hover:text-brand dark:border-white/10 dark:bg-card/80 dark:hover:bg-brand/12'

/** True if employee has any schedule (working_schedule_id or custom schedule JSON). */
function hasSchedule(emp) {
  if (emp.working_schedule_id != null && emp.working_schedule_id !== '') return true
  return emp.schedule && typeof emp.schedule === 'object' && Object.values(emp.schedule).some((v) => v && v.in && v.out)
}

/** True if employee is on THIS schedule (assignSchedule). Only for working_schedule_id. */
function isOnThisSchedule(emp, assignSchedule) {
  if (!assignSchedule) return false
  return Number(emp.working_schedule_id) === Number(assignSchedule.id)
}

/** Assigned to a different shift than the one we are editing (cannot assign without unassign). */
function isAssignedOtherShift(emp, assignSchedule) {
  if (!assignSchedule) return false
  return hasSchedule(emp) && !isOnThisSchedule(emp, assignSchedule)
}

function matchesAssignSearch(emp, q) {
  if (!q) return true
  const term = q.toLowerCase()
  return (
    (emp.name && emp.name.toLowerCase().includes(term)) ||
    (emp.id != null && String(emp.id).includes(term)) ||
    (emp.department && emp.department.toLowerCase().includes(term))
  )
}

/** Format working days as abbreviated range, e.g. "M–F", "M–S", "M–F, Su" */
function formatWorkingDaysAbbr(restDays = []) {
  const restSet = new Set(Array.isArray(restDays) ? restDays : [])
  const working = DAY_ORDER.filter((d) => !restSet.has(d))
  if (working.length === 0) return 'None'
  const labels = working.map((d) => DAY_OPTIONS.find((x) => x.key === d)?.label ?? d)
  if (labels.length === 1) return labels[0]
  const first = labels[0]
  const last = labels[labels.length - 1]
  const contiguous = working.length === DAY_ORDER.indexOf(working[working.length - 1]) - DAY_ORDER.indexOf(working[0]) + 1
  if (contiguous === working.length) return `${first}–${last}`
  return labels.join(', ')
}

function ScheduleStatCard({ icon: Icon, label, value, helper, tone = 'brand', children }) {
  const tones = {
    brand: {
      icon: 'bg-brand/10 text-brand ring-brand/15 dark:bg-brand/15 dark:ring-brand/25',
      value: 'text-foreground',
    },
    danger: {
      icon: 'bg-brand/10 text-brand ring-brand/15 dark:bg-brand/15 dark:ring-brand/25',
      value: 'text-destructive',
    },
    success: {
      icon: 'bg-emerald-100 text-emerald-700 ring-emerald-200/70 dark:bg-emerald-950/45 dark:text-emerald-300 dark:ring-emerald-900/40',
      value: 'text-foreground',
    },
  }
  const t = tones[tone] || tones.brand

  return (
    <Card className={cn(workScheduleCardClass, 'min-h-[140px] overflow-hidden')}>
      <CardContent className="flex h-full items-center gap-5 p-6">
        <div className={cn('flex size-16 shrink-0 items-center justify-center rounded-full ring-1', t.icon)}>
          {createElement(Icon, { className: 'size-8', 'aria-hidden': true })}
        </div>
        <div className="min-w-0 flex-1">
          <p className="text-sm font-medium text-muted-foreground">{label}</p>
          <p className={cn('mt-2 text-4xl font-black tabular-nums tracking-tight', t.value)}>{value}</p>
          <div className="mt-3 text-sm leading-relaxed text-muted-foreground">
            {children || helper}
          </div>
        </div>
      </CardContent>
    </Card>
  )
}

/** Normalize assign API id lists for Set lookups (API returns ints; employee.id may be string). */
function normalizeIdSet(ids) {
  return new Set((ids || []).map((id) => Number(id)))
}

/** Apply assign/unassign API result to local employee rows immediately. */
function applyAssignResultToEmployees(prev, res, scheduleId) {
  const assignedIds = normalizeIdSet(res?.assigned_ids)
  const unassignedIds = normalizeIdSet(res?.unassigned_ids)
  return prev.map((e) => {
    const id = Number(e.id)
    if (assignedIds.has(id)) return { ...e, working_schedule_id: scheduleId, schedule: null }
    if (unassignedIds.has(id)) return { ...e, working_schedule_id: null, schedule: null }
    return e
  })
}

function formatActivityTimestamp(value) {
  if (!value) return 'Just now'
  const date = new Date(value)
  if (!isValid(date)) return 'Just now'
  return `${formatDistanceToNow(date, { addSuffix: true })} - ${format(date, 'MMM d, yyyy h:mm a')}`
}

function activityToneClass(type) {
  if (type === 'adjustment') return 'bg-brand/10 text-brand ring-brand/20'
  if (type === 'assignment') return 'bg-emerald-500/10 text-emerald-700 ring-emerald-500/20 dark:text-emerald-300'
  if (type === 'template_updated') return 'bg-sky-500/10 text-sky-700 ring-sky-500/20 dark:text-sky-300'
  return 'bg-muted text-muted-foreground ring-border/70'
}

export default function AdminSchedules() {
  const queryClient = useQueryClient()

  /** Refresh employee calendar, attendance tables, and profile snapshots after schedule changes. */
  const notifySchedulePropagation = useCallback(() => {
    window.dispatchEvent(new Event('hr:schedules-changed'))
    void queryClient.invalidateQueries({ queryKey: ['admin-attendance'] })
    void queryClient.invalidateQueries({ queryKey: ['employee-profile-snapshot'] })
    void queryClient.invalidateQueries({ queryKey: ['admin-employee-profile-snapshot'] })
  }, [queryClient])

  const [schedules, setSchedules] = useState([])
  const [loading, setLoading] = useState(true)
  const [error, setError] = useState(null)

  const [editOpen, setEditOpen] = useState(false)
  const [adjustmentOpen, setAdjustmentOpen] = useState(false)
  const [editingSchedule, setEditingSchedule] = useState(null)
  const [editSubmitting, setEditSubmitting] = useState(false)
  const [editForm, setEditForm] = useState({
    name: '',
    schedule_code: '',
    shift_type: 'fixed',
    time_in: '08:00',
    break_start: '',
    break_end: '',
    time_out: '17:00',
    breaks: [],
    work_blocks: [],
    expected_paid_minutes: '',
    half_day_threshold_minutes: '',
    grace_period_minutes: 5,
    early_timein_minutes: 60,
    late_allowance_minutes: '',
    early_timeout_minutes: '',
    overtime_buffer_minutes: 15,
    rest_days: [...DEFAULT_REST_DAYS],
    flexible_required_minutes: '',
    flexible_earliest_in: '',
    flexible_latest_out: '',
    core_hours_start: '',
    core_hours_end: '',
    description: '',
  })

  const [assignOpen, setAssignOpen] = useState(false)
  const [assignSchedule, setAssignSchedule] = useState(null)
  const [assignSubmitting, setAssignSubmitting] = useState(false)
  const [assignEmployeesLoading, setAssignEmployeesLoading] = useState(false)
  const [employees, setEmployees] = useState([])
  const [selectedEmployeeIds, setSelectedEmployeeIds] = useState([])
  const [assignSearch, setAssignSearch] = useState('')
  const [assignFilter, setAssignFilter] = useState('all') // 'all' | 'without_schedule' | 'with_schedule'
  const [assignPage, setAssignPage] = useState(1)
  const ASSIGN_PAGE_SIZE = 15
  const [unassigningId, setUnassigningId] = useState(null)

  const [deleteConfirmSchedule, setDeleteConfirmSchedule] = useState(null)
  const [deleteSubmitting, setDeleteSubmitting] = useState(false)

  const [mainTab, setMainTab] = useState('templates')
  const [templateSearch, setTemplateSearch] = useState('')
  const [coverageSearch, setCoverageSearch] = useState('')
  const [coverageDept, setCoverageDept] = useState('')
  const [coverageBranch, setCoverageBranch] = useState('')
  const [coverageStatus, setCoverageStatus] = useState('active')
  const [recentActivities, setRecentActivities] = useState([])
  const [activityLoading, setActivityLoading] = useState(false)
  const [activityError, setActivityError] = useState(null)
  const [rowSelection, setRowSelection] = useState({})

  async function loadSchedules({ silent = false, fresh = false } = {}) {
    if (!silent) {
      setLoading(true)
      setError(null)
    }
    try {
      const data = await getWorkingSchedules({ fresh: fresh || !silent })
      setSchedules(data.schedules || [])
    } catch (e) {
      if (!silent) {
        setError(e.message)
        setSchedules([])
      }
    } finally {
      if (!silent) setLoading(false)
    }
  }

  async function loadEmployees({ fresh = false } = {}) {
    try {
      const data = await getEmployees({ for_schedule_assignment: true, fresh })
      const list = data.employees || []
      setEmployees(list)
      return list
    } catch {
      setEmployees([])
      return []
    }
  }

  async function loadScheduleActivity({ fresh = false } = {}) {
    setActivityLoading(true)
    setActivityError(null)
    try {
      const data = await getScheduleActivity({ limit: 40, fresh })
      setRecentActivities(data.activities || [])
    } catch (e) {
      setActivityError(e.message || 'Failed to load recent schedule activity')
      setRecentActivities([])
    } finally {
      setActivityLoading(false)
    }
  }

  /** Refresh roster + stats after assign/unassign without skeleton flicker or stale GET cache. */
  async function refreshAfterScheduleAssign(scheduleId) {
    notifySchedulePropagation()
    const list = await loadEmployees({ fresh: true })
    await loadSchedules({ silent: true, fresh: true })
    await loadScheduleActivity({ fresh: true })
    if (scheduleId != null) {
      const onShiftIds = list.filter((e) => isOnThisSchedule(e, { id: scheduleId })).map((e) => e.id)
      setSelectedEmployeeIds(onShiftIds)
    }
    return list
  }

  useEffect(() => {
    Promise.all([loadSchedules(), loadEmployees(), loadScheduleActivity()])
  }, [])

  function openCreate() {
    setEditingSchedule(null)
    setEditForm({
      name: '',
      schedule_code: '',
      shift_type: 'fixed',
      time_in: '08:00',
      break_start: '',
      break_end: '',
      time_out: '17:00',
      breaks: [],
      work_blocks: [],
      expected_paid_minutes: '',
      half_day_threshold_minutes: '',
      grace_period_minutes: 5,
      early_timein_minutes: 60,
      late_allowance_minutes: '',
      early_timeout_minutes: '',
      overtime_buffer_minutes: 15,
      rest_days: [...DEFAULT_REST_DAYS],
      flexible_required_minutes: '',
      flexible_earliest_in: '',
      flexible_latest_out: '',
      core_hours_start: '',
      core_hours_end: '',
      description: '',
    })
    setEditOpen(true)
  }

  function openEdit(schedule) {
    setEditingSchedule(schedule)
    setEditForm({
      name: schedule.name || '',
      schedule_code: schedule.schedule_code || '',
      shift_type: schedule.shift_type || 'fixed',
      time_in: toHhMm(schedule.time_in) || '08:00',
      break_start: toHhMm(schedule.break_start) || '',
      break_end: toHhMm(schedule.break_end) || '',
      time_out: toHhMm(schedule.time_out) || '17:00',
      breaks: Array.isArray(schedule.breaks) ? schedule.breaks.map(b => ({
        start: toHhMm(b.start || b.break_start) || '',
        end: toHhMm(b.end || b.break_end) || '',
        is_paid: !!b.is_paid,
      })) : [],
      work_blocks: Array.isArray(schedule.work_blocks) ? schedule.work_blocks.map(b => ({
        start: toHhMm(b.start) || '',
        end: toHhMm(b.end) || '',
      })) : [],
      expected_paid_minutes: schedule.expected_paid_minutes ?? '',
      half_day_threshold_minutes: schedule.half_day_threshold_minutes ?? '',
      grace_period_minutes: schedule.grace_period_minutes ?? 5,
      early_timein_minutes: schedule.early_timein_minutes ?? 60,
      late_allowance_minutes: schedule.late_allowance_minutes ?? '',
      early_timeout_minutes: schedule.early_timeout_minutes ?? '',
      overtime_buffer_minutes: schedule.overtime_buffer_minutes ?? 15,
      rest_days:
        Array.isArray(schedule.rest_days) && schedule.rest_days.length > 0
          ? [...schedule.rest_days]
          : [...DEFAULT_REST_DAYS],
      flexible_required_minutes: schedule.flexible_required_minutes ?? '',
      flexible_earliest_in: toHhMm(schedule.flexible_earliest_in) || '',
      flexible_latest_out: toHhMm(schedule.flexible_latest_out) || '',
      core_hours_start: toHhMm(schedule.core_hours_start) || '',
      core_hours_end: toHhMm(schedule.core_hours_end) || '',
      description: schedule.description || '',
    })
    setEditOpen(true)
  }

  async function handleEditSubmit(e) {
    e.preventDefault()
    const nameError = validateSimpleLabel(editForm.name, 'Schedule name')
    if (nameError) {
      setError(nameError)
      return
    }
    setEditSubmitting(true)
    setError(null)
    try {
      const intOrNull = (v, def) => (v === '' || v == null ? def : Number(v))

      const payload = {
        name: editForm.name.trim(),
        schedule_code: editForm.schedule_code?.trim() || null,
        shift_type: editForm.shift_type || 'fixed',
        time_in: toHhMm(editForm.time_in) || editForm.time_in,
        break_start: editForm.break_start ? toHhMm(editForm.break_start) : null,
        break_end: editForm.break_end ? toHhMm(editForm.break_end) : null,
        time_out: toHhMm(editForm.time_out) || editForm.time_out,
        breaks: (editForm.breaks || []).filter(b => b.start && b.end).map(b => ({
          start: toHhMm(b.start) || b.start,
          end: toHhMm(b.end) || b.end,
          is_paid: !!b.is_paid,
        })),
        work_blocks: (editForm.work_blocks || []).filter(b => b.start && b.end).map(b => ({
          start: toHhMm(b.start) || b.start,
          end: toHhMm(b.end) || b.end,
        })),
        expected_paid_minutes: intOrNull(editForm.expected_paid_minutes, null),
        half_day_threshold_minutes: intOrNull(editForm.half_day_threshold_minutes, null),
        grace_period_minutes: intOrNull(editForm.grace_period_minutes, 5),
        early_timein_minutes: intOrNull(editForm.early_timein_minutes, 60),
        late_allowance_minutes: intOrNull(editForm.late_allowance_minutes, null),
        early_timeout_minutes: intOrNull(editForm.early_timeout_minutes, null),
        overtime_buffer_minutes: intOrNull(editForm.overtime_buffer_minutes, 15),
        rest_days: editForm.rest_days,
        flexible_required_minutes: intOrNull(editForm.flexible_required_minutes, null),
        flexible_earliest_in: editForm.flexible_earliest_in ? toHhMm(editForm.flexible_earliest_in) : null,
        flexible_latest_out: editForm.flexible_latest_out ? toHhMm(editForm.flexible_latest_out) : null,
        core_hours_start: editForm.core_hours_start ? toHhMm(editForm.core_hours_start) : null,
        core_hours_end: editForm.core_hours_end ? toHhMm(editForm.core_hours_end) : null,
        description: editForm.description?.trim() || null,
      }
      if (editingSchedule) {
        await updateWorkingSchedule(editingSchedule.id, payload)
      } else {
        await createWorkingSchedule(payload)
      }
      setEditOpen(false)
      setEditingSchedule(null)
      await loadSchedules()
      await loadScheduleActivity({ fresh: true })
      notifySchedulePropagation()
    } catch (err) {
      setError(err.message)
    } finally {
      setEditSubmitting(false)
    }
  }

  async function confirmDelete() {
    if (!deleteConfirmSchedule) return
    setError(null)
    setDeleteSubmitting(true)
    try {
      await deleteWorkingSchedule(deleteConfirmSchedule.id)
      setDeleteConfirmSchedule(null)
      await loadSchedules()
      await loadScheduleActivity({ fresh: true })
      notifySchedulePropagation()
    } catch (err) {
      setError(err.message)
    } finally {
      setDeleteSubmitting(false)
    }
  }

  async function openAssign(schedule) {
    setAssignSchedule(schedule)
    setAssignSearch('')
    setAssignFilter('all')
    setAssignPage(1)
    setAssignOpen(true)
    setAssignEmployeesLoading(true)
    try {
      const list = await loadEmployees({ fresh: true })
      const onThisShiftIds = list.filter((e) => isOnThisSchedule(e, schedule)).map((e) => e.id)
      setSelectedEmployeeIds(onThisShiftIds)
    } finally {
      setAssignEmployeesLoading(false)
    }
  }

  // Single source of truth: split "on this shift" vs everyone else — never mix into "Available" list.
  const assignSearchTrimmed = assignSearch.trim()

  const employeesOnThisShiftFiltered = useMemo(() => {
    if (!assignSchedule) return []
    return employees
      .filter((e) => isOnThisSchedule(e, assignSchedule))
      .filter((e) => matchesAssignSearch(e, assignSearchTrimmed))
  }, [employees, assignSchedule, assignSearchTrimmed])

  /** Employees not on the current shift — filters apply only here (excludes "On this shift" rows). */
  const assignFilteredOthers = useMemo(() => {
    if (!assignSchedule) return employees.filter((e) => matchesAssignSearch(e, assignSearchTrimmed))
    let list = employees.filter((e) => !isOnThisSchedule(e, assignSchedule))
    list = list.filter((e) => matchesAssignSearch(e, assignSearchTrimmed))
    if (assignFilter === 'without_schedule' || assignFilter === 'available_only') {
      list = list.filter((e) => !hasSchedule(e))
    } else if (assignFilter === 'with_schedule') {
      list = list.filter((e) => hasSchedule(e))
    } else if (assignFilter === 'on_this_shift') {
      list = []
    }
    return list
  }, [employees, assignSchedule, assignSearchTrimmed, assignFilter])

  const assignTotalPages = Math.max(1, Math.ceil(assignFilteredOthers.length / ASSIGN_PAGE_SIZE))
  const assignPaginatedOthers = useMemo(() => {
    const start = (assignPage - 1) * ASSIGN_PAGE_SIZE
    return assignFilteredOthers.slice(start, start + ASSIGN_PAGE_SIZE)
  }, [assignFilteredOthers, assignPage])

  useEffect(() => {
    const lastPage = Math.max(1, Math.ceil(assignFilteredOthers.length / ASSIGN_PAGE_SIZE))
    setAssignPage((p) => Math.min(p, lastPage))
  }, [assignFilteredOthers.length])

  const countOnThisShift = useMemo(
    () => (assignSchedule ? employees.filter((e) => isOnThisSchedule(e, assignSchedule)).length : 0),
    [employees, assignSchedule]
  )
  const countTrulyAvailable = useMemo(
    () => employees.filter((e) => !hasSchedule(e)).length,
    [employees]
  )
  const countNotOnThisShift = useMemo(
    () => (assignSchedule ? employees.filter((e) => !isOnThisSchedule(e, assignSchedule)).length : employees.length),
    [employees, assignSchedule]
  )

  const employeesWithoutSchedule = useMemo(
    () => employees.filter((e) => !hasSchedule(e)).map((e) => e.id),
    [employees]
  )

  function bulkSelectWithoutSchedule() {
    const ids = new Set(selectedEmployeeIds)
    employeesWithoutSchedule.forEach((id) => ids.add(id))
    setSelectedEmployeeIds(Array.from(ids))
  }

  async function handleAssignSubmit(e) {
    e.preventDefault()
    if (!assignSchedule || selectedEmployeeIds.length === 0) return
    setAssignSubmitting(true)
    setError(null)
    try {
      const res = await assignWorkingSchedule(assignSchedule.id, {
        employee_ids: selectedEmployeeIds.map((id) => Number(id)),
        mode: 'replace_roster',
      })
      setEmployees((prev) => applyAssignResultToEmployees(prev, res, assignSchedule.id))
      await refreshAfterScheduleAssign(assignSchedule.id)
      const assigned = res?.assigned_count ?? 0
      const unassigned = res?.unassigned_count ?? 0
      toast.success('Schedule updated', {
        description:
          assigned || unassigned
            ? `${assigned ? `${assigned} assigned` : ''}${assigned && unassigned ? ', ' : ''}${unassigned ? `${unassigned} unassigned` : ''}.`
            : 'Roster saved.',
      })
    } catch (err) {
      const msg = err.conflicts?.length
        ? `Employee already assigned: ${err.conflicts.map((c) => `${c.employee_name} (${c.current_schedule} ${formatScheduleLabel12h(c.current_time)})`).join('; ')}. Unassign first.`
        : err.message
      setError(msg)
    } finally {
      setAssignSubmitting(false)
    }
  }

  async function toggleEmployeeSelection(id) {
    const isRemoving = selectedEmployeeIds.includes(id)
    const newIds = isRemoving ? selectedEmployeeIds.filter((x) => x !== id) : [...selectedEmployeeIds, id]
    if (!isRemoving && assignSchedule) {
      const emp = employees.find((e) => e.id === id)
      if (emp && hasSchedule(emp) && !isOnThisSchedule(emp, assignSchedule)) {
        toast.error('Cannot assign', {
          description: 'Employee already has a schedule. Please unassign from the current shift first.',
        })
        return
      }
    }
    const previousIds = selectedEmployeeIds
    setSelectedEmployeeIds(newIds)
    if (!assignSchedule || newIds.length === 0) return
    setAssignSubmitting(true)
    setError(null)
    try {
      const res = await assignWorkingSchedule(assignSchedule.id, {
        employee_ids: newIds.map((x) => Number(x)),
        mode: 'replace_roster',
      })
      setEmployees((prev) => applyAssignResultToEmployees(prev, res, assignSchedule.id))
      await refreshAfterScheduleAssign(assignSchedule.id)
    } catch (err) {
      const msg = err.conflicts?.length
        ? `Employee already assigned: ${err.conflicts.map((c) => `${c.employee_name} (${c.current_schedule} ${formatScheduleLabel12h(c.current_time)})`).join('; ')}. Unassign first.`
        : err.message
      setError(msg)
      setSelectedEmployeeIds(previousIds)
    } finally {
      setAssignSubmitting(false)
    }
  }

  async function handleUnassign(emp, e) {
    e?.preventDefault?.()
    e?.stopPropagation?.()
    if (!emp?.id || unassigningId) return
    setUnassigningId(emp.id)
    setError(null)
    const empId = Number(emp.id)
    try {
      await updateEmployeeSchedule(emp.id, { schedule: null })
      setEmployees((prev) =>
        prev.map((row) =>
          Number(row.id) === empId ? { ...row, working_schedule_id: null, schedule: null } : row
        )
      )
      setSelectedEmployeeIds((prev) => prev.filter((id) => Number(id) !== empId))
      toast.success('Schedule unassigned', { description: `${emp.name} can now be assigned to a new shift.` })
      await refreshAfterScheduleAssign(assignSchedule?.id)
    } catch (err) {
      setError(err.message)
      toast.error('Failed to unassign', { description: err.message })
    } finally {
      setUnassigningId(null)
    }
  }

  const deptOptions = useMemo(() => {
    const s = new Set()
    employees.forEach((e) => {
      if (e.department) s.add(e.department)
    })
    return [...s].sort()
  }, [employees])

  const branchOptions = useMemo(() => {
    const s = new Set()
    employees.forEach((e) => {
      if (e.branch_name) s.add(e.branch_name)
    })
    return [...s].sort()
  }, [employees])

  const stats = useMemo(() => {
    const activeEmps = employees.filter((e) => e.is_active !== false)
    const scheduled = activeEmps.filter((e) => hasSchedule(e)).length
    const missing = activeEmps.filter((e) => !hasSchedule(e)).length
    const pct = activeEmps.length ? Math.round((scheduled / activeEmps.length) * 100) : 0
    const restRisk = schedules.filter((s) => !(Array.isArray(s.rest_days) && s.rest_days.length > 0)).length
    return { activeSchedules: schedules.length, missing, restRisk, coveragePct: pct, scheduled }
  }, [employees, schedules])

  const filteredTemplates = useMemo(() => {
    const q = templateSearch.trim().toLowerCase()
    return schedules.filter((s) => !q || String(s.name || '')
      .toLowerCase()
      .includes(q))
  }, [schedules, templateSearch])

  const coverageRows = useMemo(() => {
    return employees.filter((e) => {
      if (coverageStatus === 'active' && e.is_active === false) return false
      if (coverageStatus === 'inactive' && e.is_active !== false) return false
      if (coverageDept && e.department !== coverageDept) return false
      if (coverageBranch && e.branch_name !== coverageBranch) return false
      const q = coverageSearch.trim().toLowerCase()
      if (!q) return true
      return (
        (e.name || '').toLowerCase().includes(q) ||
        String(e.id).includes(q) ||
        (e.department && e.department.toLowerCase().includes(q))
      )
    })
  }, [employees, coverageSearch, coverageDept, coverageBranch, coverageStatus])

  const workingScheduleNameById = useMemo(() => {
    const map = new Map()
    for (const s of schedules) {
      map.set(String(s.id), s.name || `Schedule #${s.id}`)
    }
    return map
  }, [schedules])

  const duplicateSchedule = async (s) => {
    setError(null)
    try {
      const base = `${s.name} (copy)`
      const payload = {
        name: base.length > 100 ? base.slice(0, 100) : base,
        time_in: toHhMm(s.time_in) || s.time_in,
        break_start: s.break_start ? toHhMm(s.break_start) : null,
        break_end: s.break_end ? toHhMm(s.break_end) : null,
        time_out: toHhMm(s.time_out) || s.time_out,
        grace_period_minutes: s.grace_period_minutes ?? 5,
        early_timein_minutes: s.early_timein_minutes ?? 60,
        late_allowance_minutes: s.late_allowance_minutes ?? null,
        early_timeout_minutes: s.early_timeout_minutes ?? null,
        overtime_buffer_minutes: s.overtime_buffer_minutes ?? 15,
        rest_days: s.rest_days || [],
      }
      await createWorkingSchedule(payload)
      toast.success('Schedule duplicated')
      await loadSchedules()
      await loadScheduleActivity({ fresh: true })
      notifySchedulePropagation()
    } catch (err) {
      setError(err.message)
      toast.error(err.message)
    }
  }

  const exportTemplatesCsv = useCallback(() => {
    const rows = filteredTemplates.map((s) => ({
      name: s.name,
      shift_type: s.shift_type || 'fixed',
      shift: formatShiftRange12h(s.time_in, s.time_out, '-'),
      paid_hours: formatPaidHours(s.computed_paid_minutes || s.expected_paid_minutes || computePaidMinutes(s)),
      rest_days: (s.rest_days || []).join(';'),
      grace_min: s.grace_period_minutes ?? '',
      updated_at: s.updated_at || s.created_at || '',
    }))
    const header = ['name', 'shift_type', 'shift', 'paid_hours', 'rest_days', 'grace_min', 'updated_at']
    const csv = [header.join(','), ...rows.map((r) => header.map((h) => JSON.stringify(String(r[h] ?? ''))).join(','))].join('\n')
    const blob = new Blob([csv], { type: 'text/csv;charset=utf-8;' })
    const url = URL.createObjectURL(blob)
    const a = document.createElement('a')
    a.href = url
    a.download = `work-schedules-${format(new Date(), 'yyyy-MM-dd')}.csv`
    a.click()
    URL.revokeObjectURL(url)
    toast.success('Exported CSV')
  }, [filteredTemplates])

  const exportCoverageCsv = useCallback(() => {
    const rows = coverageRows.map((e) => ({
      id: e.id,
      name: e.name,
      department: e.department || '',
      branch: e.branch_name || '',
      schedule: workingScheduleNameById.get(String(e.working_schedule_id)) || 'Custom / none',
    }))
    const header = ['id', 'name', 'department', 'branch', 'schedule']
    const csv = [header.join(','), ...rows.map((r) => header.map((h) => JSON.stringify(String(r[h] ?? ''))).join(','))].join('\n')
    const blob = new Blob([csv], { type: 'text/csv;charset=utf-8;' })
    const url = URL.createObjectURL(blob)
    const a = document.createElement('a')
    a.href = url
    a.download = `schedule-coverage-${format(new Date(), 'yyyy-MM-dd')}.csv`
    a.click()
    URL.revokeObjectURL(url)
    toast.success('Exported CSV')
  }, [coverageRows, workingScheduleNameById])

  const templateColumns = useMemo(
    () => [
      {
        id: 'select',
        header: ({ table }) => (
          <Checkbox
            checked={
              table.getIsAllPageRowsSelected()
                ? true
                : table.getIsSomePageRowsSelected()
                  ? 'indeterminate'
                  : false
            }
            onCheckedChange={(v) => table.toggleAllPageRowsSelected(!!v)}
            aria-label="Select all"
            className="translate-y-0.5"
          />
        ),
        cell: ({ row }) => (
          <Checkbox
            checked={row.getIsSelected()}
            onCheckedChange={(v) => row.toggleSelected(!!v)}
            aria-label="Select row"
            className="translate-y-0.5"
          />
        ),
        enableSorting: false,
        size: 36,
      },
      {
        accessorKey: 'name',
        header: 'Schedule',
        cell: ({ row }) => (
          <div>
            <p className="font-semibold text-foreground">{row.original.name}</p>
            <p className="text-[11px] text-muted-foreground">Fixed shift · template</p>
          </div>
        ),
      },
      {
        id: 'pattern',
        header: 'Shift pattern',
        cell: ({ row }) => {
          const s = row.original
          const nd = ndOverlapMinutes(toHhMm(s.time_in) || s.time_in, toHhMm(s.time_out) || s.time_out) > 0
          const shiftTypeLabel = SHIFT_TYPES.find(t => t.value === s.shift_type)?.label || 'Fixed Shift'
          const paidMin = s.computed_paid_minutes || s.expected_paid_minutes || computePaidMinutes(s)
          return (
            <div className="flex flex-wrap items-center gap-1.5">
              <span className="text-sm tabular-nums">{formatShiftRange12h(s.time_in, s.time_out)}</span>
              <span className="text-[11px] text-muted-foreground">{formatWorkingDaysAbbr(s.rest_days)}</span>
              {s.shift_type && s.shift_type !== 'fixed' && (
                <ShiftPill variant="info" title={shiftTypeLabel}>
                  {shiftTypeLabel.replace(' Shift', '').replace(' Work Week', '')}
                </ShiftPill>
              )}
              {nd && (
                <ShiftPill variant="nd" title="Touches night differential window (22:00–06:00)">
                  ND
                </ShiftPill>
              )}
              <span className="text-[11px] text-muted-foreground">{formatPaidHours(paidMin)}</span>
            </div>
          )
        },
      },
      {
        id: 'rest',
        header: 'Days off',
        cell: ({ row }) => (
          <div className="flex flex-wrap gap-1" title="Highlighted = no shift that day">
            {DAY_ORDER.map((d) => {
              const isDayOff = row.original.rest_days?.includes(d)
              const label = DAY_OPTIONS.find((x) => x.key === d)?.label ?? d
              const full = DAY_OPTIONS.find((x) => x.key === d)?.full ?? d
              return (
                <span
                  key={d}
                  className={cn(
                    'rounded-md px-1.5 py-0.5 text-[10px] font-medium',
                    /* Rest days stand out; working days stay subtle (was inverted before). */
                    isDayOff
                      ? 'bg-primary/15 font-semibold text-foreground ring-1 ring-primary/25'
                      : 'bg-muted/50 text-muted-foreground'
                  )}
                  title={`${full}: ${isDayOff ? 'day off' : 'working day'}`}
                >
                  {label}
                </span>
              )
            })}
          </div>
        ),
      },
      {
        id: 'status',
        header: 'Status',
        cell: () => <Badge className="bg-emerald-600/15 text-emerald-800 hover:bg-emerald-600/20 dark:text-emerald-200">Active</Badge>,
      },
      {
        id: 'modified',
        header: 'Last updated',
        cell: ({ row }) => {
          const iso = row.original.updated_at || row.original.created_at
          if (!iso) return <span className="text-muted-foreground">—</span>
          const d = new Date(iso)
          if (!isValid(d)) return <span className="text-muted-foreground">—</span>
          const absolute = format(d, 'MMM d, yyyy · h:mm a')
          return (
            <span className="text-sm text-muted-foreground" title={absolute}>
              {formatDistanceToNow(d, { addSuffix: true })}
            </span>
          )
        },
      },
      {
        id: 'actions',
        header: '',
        cell: ({ row }) => (
          <div className="flex flex-wrap justify-end gap-1 opacity-100 @lg:opacity-90">
            <Button variant="ghost" size="icon" className="min-h-11 min-w-11" title="Assign" onClick={() => openAssign(row.original)}>
              <Users className="size-4" />
            </Button>
            <Button variant="ghost" size="icon" className="min-h-11 min-w-11" title="Edit" onClick={() => openEdit(row.original)}>
              <Clock className="size-4" />
            </Button>
            <Button
              variant="ghost"
              size="icon"
              className="min-h-11 min-w-11"
              title="Duplicate"
              onClick={() => duplicateSchedule(row.original)}
            >
              <Copy className="size-4" />
            </Button>
            <Button
              variant="ghost"
              size="icon"
              className="min-h-11 min-w-11 text-destructive hover:text-destructive"
              title="Delete"
              onClick={() => setDeleteConfirmSchedule(row.original)}
            >
              <Trash2 className="size-4" />
            </Button>
          </div>
        ),
        enableSorting: false,
      },
    ],
    // duplicateSchedule / openAssign / openEdit change identity; columns stay stable enough for UX
    // eslint-disable-next-line react-hooks/exhaustive-deps
    []
  )

  const templateTable = useReactTable({
    data: filteredTemplates,
    columns: templateColumns,
    state: { rowSelection },
    onRowSelectionChange: setRowSelection,
    getCoreRowModel: getCoreRowModel(),
    getSortedRowModel: getSortedRowModel(),
    getFilteredRowModel: getFilteredRowModel(),
    getPaginationRowModel: getPaginationRowModel(),
    initialState: { pagination: { pageSize: 10 } },
  })

  const selectedTemplateIds = templateTable.getSelectedRowModel().rows.map((r) => r.original.id)

  return (
    <div className="min-h-0 min-w-0 max-w-full space-y-8 overflow-x-hidden px-1 py-4 @sm:px-0 @sm:py-6">
      <div className="flex flex-col gap-5 pb-1 @lg:flex-row @lg:items-start @lg:justify-between">
        <div className="space-y-3">
          <h1 className="text-3xl font-extrabold tracking-tight text-foreground @md:text-4xl">Work schedules</h1>
          <p className="max-w-4xl text-base leading-relaxed text-muted-foreground">
            Build DOLE-aware shift templates, preview night differential exposure, and keep provincial teams covered.
            <span className="block">Optimized for clear scanning at night.</span>
          </p>
        </div>
        <div className="flex flex-wrap gap-3">
          <Button onClick={openCreate} className={cn(workSchedulePrimaryButtonClass, 'shrink-0')}>
            <Plus className="size-4" />
            New Work Schedule
          </Button>
          <Button onClick={() => setAdjustmentOpen(true)} variant="outline" className={cn(workScheduleOutlineButtonClass, 'shrink-0')}>
            <CalendarCheck className="size-4" />
            Add Schedule Adjustment
          </Button>
        </div>
      </div>

      {error && (
        <div className="rounded-lg border border-destructive/50 bg-destructive/10 px-4 py-2 text-sm text-destructive">
          {error}
        </div>
      )}

      <div className="grid gap-4 @lg:grid-cols-4 @lg:gap-6">
        <ScheduleStatCard icon={CalendarCheck} label="Active templates" value={stats.activeSchedules} helper="Reusable shift definitions" />
        <ScheduleStatCard icon={AlertTriangle} label="Missing schedule" value={stats.missing} tone={stats.missing > 0 ? 'danger' : 'success'}>
          {stats.missing > 0 ? (
            <span className="flex items-start gap-2 text-brand">
              <AlertTriangle className="mt-0.5 size-4 shrink-0" aria-hidden />
              Assign employees without a schedule
            </span>
          ) : (
            <span className="flex items-center gap-2 text-emerald-700 dark:text-emerald-300">
              <CheckCircle2 className="size-4 shrink-0" aria-hidden />
              All active employees covered
            </span>
          )}
        </ScheduleStatCard>
        <ScheduleStatCard icon={ShieldCheck} label="Rest-day risk" value={stats.restRisk} tone={stats.restRisk > 0 ? 'danger' : 'success'}>
          {stats.restRisk > 0 ? (
            'Templates missing a weekly rest day'
          ) : (
            <span className="flex items-center gap-2 text-emerald-700 dark:text-emerald-300">
              <CheckCircle2 className="size-4 shrink-0" aria-hidden />
              All templates include rest days
            </span>
          )}
        </ScheduleStatCard>
        <Card className={cn(workScheduleCardClass, 'min-h-[140px] overflow-hidden')}>
          <CardContent className="flex h-full flex-col p-6">
            <div className="flex items-start gap-5">
              <div className="flex size-16 shrink-0 items-center justify-center rounded-full bg-brand/10 text-brand ring-1 ring-brand/15 dark:bg-brand/15 dark:ring-brand/25">
                <PieChart className="size-8" aria-hidden />
              </div>
              <div className="min-w-0">
                <p className="text-sm font-medium text-muted-foreground">Schedule coverage</p>
                <p className="mt-2 text-4xl font-black tabular-nums tracking-tight text-foreground">{stats.coveragePct}%</p>
                <p className="mt-1 text-sm text-muted-foreground">
                  {stats.scheduled} of {employees.filter((e) => e.is_active !== false).length} active employees
                </p>
              </div>
            </div>
            <div
              className="mt-4 h-3 w-full overflow-hidden rounded-full bg-muted ring-1 ring-border/50"
              role="progressbar"
              aria-valuenow={stats.coveragePct}
              aria-valuemin={0}
              aria-valuemax={100}
              aria-label="Share of active employees with a schedule"
            >
              <div
                className="h-full rounded-full bg-brand transition-all"
                style={{ width: `${Math.min(100, stats.coveragePct)}%` }}
              />
            </div>
          </CardContent>
        </Card>
      </div>

      <Tabs value={mainTab} onValueChange={setMainTab} className="space-y-6">
        {/* Flex + fixed trigger height: TabsList defaults to h-9 while Trigger used min-h-11 — caused active pill to overflow the track */}
        <TabsList className="flex w-full max-w-2xl items-stretch gap-0 rounded-xl border border-border/70 bg-card p-0 shadow-sm !h-auto overflow-hidden dark:border-white/10">
          <TabsTrigger
            value="templates"
            className="flex min-h-0 flex-1 items-center justify-center gap-2 rounded-none border-b-2 border-transparent px-5 py-0 text-base font-semibold !h-14 text-muted-foreground data-[state=active]:border-brand data-[state=active]:bg-brand/5 data-[state=active]:text-brand data-[state=active]:shadow-none"
          >
            <Layers className="size-4 shrink-0" />
            Templates
          </TabsTrigger>
          <TabsTrigger
            value="coverage"
            className="flex min-h-0 flex-1 items-center justify-center gap-2 rounded-none border-b-2 border-transparent px-5 py-0 text-base font-semibold !h-14 text-muted-foreground data-[state=active]:border-brand data-[state=active]:bg-brand/5 data-[state=active]:text-brand data-[state=active]:shadow-none"
          >
            <Users className="size-4 shrink-0" />
            Team coverage
          </TabsTrigger>
          <TabsTrigger
            value="activity"
            className="flex min-h-0 flex-1 items-center justify-center gap-2 rounded-none border-b-2 border-transparent px-5 py-0 text-base font-semibold !h-14 text-muted-foreground data-[state=active]:border-brand data-[state=active]:bg-brand/5 data-[state=active]:text-brand data-[state=active]:shadow-none"
          >
            <Clock className="size-4 shrink-0" />
            Recent activity
          </TabsTrigger>
        </TabsList>

        <TabsContent value="templates" className="space-y-4 outline-none">
          <div className={cn(workScheduleCardClass, 'p-5')}>
            <div className="flex flex-col gap-3 @lg:flex-row @lg:items-end @lg:justify-between">
              <div className="relative max-w-xl flex-1">
                <Search className="absolute left-3 top-1/2 size-4 -translate-y-1/2 text-muted-foreground" aria-hidden />
                <Input
                  className="h-12 min-h-12 rounded-xl border-border/80 bg-background pl-10 text-base shadow-sm dark:border-white/10 dark:bg-background/40"
                  placeholder="Search schedule name, shift, or pattern..."
                  value={templateSearch}
                  onChange={(e) => setTemplateSearch(e.target.value)}
                />
              </div>
              <div className="flex flex-wrap gap-2">
                <Button type="button" variant="outline" className={workScheduleOutlineButtonClass} onClick={exportTemplatesCsv}>
                  <Download className="size-4" />
                  Export
                </Button>
              </div>
            </div>
          </div>

          {selectedTemplateIds.length > 0 && (
            <div
              className="flex flex-col gap-3 rounded-xl border border-border/60 bg-muted/40 px-4 py-3 shadow-sm @sm:flex-row @sm:items-center @sm:justify-between"
              role="status"
              aria-live="polite"
            >
              <span className="text-sm font-medium text-foreground">
                {selectedTemplateIds.length} selected
              </span>
              <div className="flex flex-wrap items-center gap-2">
                <Button
                  type="button"
                  size="sm"
                  variant="secondary"
                  className="min-h-10"
                  onClick={async () => {
                    for (const id of selectedTemplateIds) {
                      const s = schedules.find((x) => x.id === id)
                      if (s) await duplicateSchedule(s)
                    }
                    setRowSelection({})
                  }}
                >
                  <Copy className="size-4" />
                  Duplicate
                </Button>
                <Button type="button" size="sm" variant="secondary" className="min-h-10" onClick={exportTemplatesCsv}>
                  <Download className="size-4" />
                  Export
                </Button>
                <Button type="button" size="sm" variant="outline" className="min-h-10" onClick={() => setRowSelection({})}>
                  Clear
                </Button>
              </div>
            </div>
          )}

          {loading ? (
            <div className="overflow-x-auto rounded-xl border border-border/60 bg-muted/20 p-4">
              <table className="w-full text-sm">
                <tbody>
                  <TableBodySkeleton rows={6} cols={6} />
                </tbody>
              </table>
            </div>
          ) : filteredTemplates.length === 0 ? (
            <div className="flex flex-col items-center justify-center rounded-xl border border-dashed border-border/60 bg-muted/30 px-6 py-16 text-center">
              <Calendar className="mb-3 size-12 text-muted-foreground/50" />
              <p className="text-lg font-semibold text-foreground">No schedules yet</p>
              <p className="mt-1 max-w-sm text-sm text-muted-foreground">
                Create a template with clear rest days and grace — your team needs it before they can clock in.
              </p>
              <Button className="mt-6 min-h-11" onClick={openCreate}>
                <Plus className="size-4" />
                Create first schedule
              </Button>
            </div>
          ) : (
            <div className={cn(workScheduleCardClass, 'overflow-hidden')}>
              <table className="w-full min-w-[900px] border-collapse text-sm">
                <thead>
                  {templateTable.getHeaderGroups().map((hg) => (
                    <tr key={hg.id} className="border-b border-border/60 bg-card">
                      {hg.headers.map((header) => (
                        <th
                          key={header.id}
                          className="px-5 py-4 text-left text-xs font-bold tracking-tight text-muted-foreground"
                          style={{ width: header.getSize() !== 150 ? header.getSize() : undefined }}
                        >
                          {header.isPlaceholder ? null : flexRender(header.column.columnDef.header, header.getContext())}
                        </th>
                      ))}
                    </tr>
                  ))}
                </thead>
                <tbody>
                  {templateTable.getRowModel().rows.map((row, rowIdx) => (
                    <tr
                      key={row.id}
                      className={cn(
                        'border-b border-border/50 transition-colors hover:bg-muted/35',
                        rowIdx % 2 === 1 && 'bg-muted/10'
                      )}
                    >
                      {row.getVisibleCells().map((cell) => (
                        <td key={cell.id} className="px-5 py-5 align-middle">
                          {flexRender(cell.column.columnDef.cell, cell.getContext())}
                        </td>
                      ))}
                    </tr>
                  ))}
                </tbody>
              </table>
              <div className="flex flex-col items-center justify-between gap-3 border-t border-border/60 px-5 py-5 @sm:flex-row">
                <p className="text-xs text-muted-foreground">
                  Page {templateTable.getState().pagination.pageIndex + 1} of {templateTable.getPageCount() || 1}
                </p>
                <div className="flex gap-2">
                  <Button
                    type="button"
                    variant="outline"
                    size="sm"
                    className="h-12 rounded-lg px-5 text-base"
                    disabled={!templateTable.getCanPreviousPage()}
                    onClick={() => templateTable.previousPage()}
                  >
                    Previous
                  </Button>
                  <Button
                    type="button"
                    variant="outline"
                    size="sm"
                    className="h-12 rounded-lg border-brand px-5 text-base text-brand hover:bg-brand/10"
                    disabled={!templateTable.getCanNextPage()}
                    onClick={() => templateTable.nextPage()}
                  >
                    Next
                  </Button>
                </div>
              </div>
            </div>
          )}
        </TabsContent>

        <TabsContent value="coverage" className="space-y-4 outline-none">
          <div className="rounded-xl border border-border/60 bg-card p-4 shadow-sm">
            <div className="grid gap-3 @lg:grid-cols-4">
              <div className="relative @lg:col-span-2">
                <Search className="absolute left-3 top-1/2 size-4 -translate-y-1/2 text-muted-foreground" aria-hidden />
                <Input
                  className="h-11 min-h-11 pl-9"
                  placeholder="Search employee, ID, department…"
                  value={coverageSearch}
                  onChange={(e) => setCoverageSearch(e.target.value)}
                />
              </div>
              <select
                value={coverageDept}
                onChange={(e) => setCoverageDept(e.target.value)}
                className={cn(FIELD_SELECT_CLASS_H10, 'min-h-11')}
                aria-label="Department"
              >
                <option value="">All departments</option>
                {deptOptions.map((d) => (
                  <option key={d} value={d}>
                    {d}
                  </option>
                ))}
              </select>
              <select
                value={coverageBranch}
                onChange={(e) => setCoverageBranch(e.target.value)}
                className={cn(FIELD_SELECT_CLASS_H10, 'min-h-11')}
                aria-label="Branch"
              >
                <option value="">All branches</option>
                {branchOptions.map((b) => (
                  <option key={b} value={b}>
                    {b}
                  </option>
                ))}
              </select>
              <select
                value={coverageStatus}
                onChange={(e) => setCoverageStatus(e.target.value)}
                className={cn(FIELD_SELECT_CLASS_H10, 'min-h-11')}
                aria-label="Status"
              >
                <option value="active">Active employees</option>
                <option value="inactive">Inactive</option>
                <option value="all">All</option>
              </select>
            </div>
            <div className="mt-3 flex flex-wrap gap-2">
              <Button type="button" variant="outline" size="sm" className="min-h-11" onClick={exportCoverageCsv}>
                <Download className="size-4" />
                Export CSV
              </Button>
            </div>
          </div>

          <div className="overflow-x-auto rounded-xl border border-border/60 bg-card shadow-sm">
            <table className="w-full min-w-[800px] border-collapse text-sm">
              <thead>
                <tr className="border-b border-border/60 bg-muted/40">
                  <th className="px-3 py-3.5 text-left text-xs font-semibold tracking-tight text-muted-foreground">
                    Employee
                  </th>
                  <th className="px-3 py-3.5 text-left text-xs font-semibold tracking-tight text-muted-foreground">
                    Schedule
                  </th>
                  <th className="px-3 py-3.5 text-left text-xs font-semibold tracking-tight text-muted-foreground">
                    Shift
                  </th>
                  <th className="px-3 py-3.5 text-left text-xs font-semibold tracking-tight text-muted-foreground">
                    Status
                  </th>
                  <th className="px-3 py-3.5 text-right text-xs font-semibold tracking-tight text-muted-foreground">
                    Actions
                  </th>
                </tr>
              </thead>
              <tbody>
                {coverageRows.map((emp, covIdx) => {
                  const ws = emp.working_schedule_id ? schedules.find((s) => Number(s.id) === Number(emp.working_schedule_id)) : null
                  const firstCustom =
                    emp.schedule && typeof emp.schedule === 'object'
                      ? Object.values(emp.schedule).find((v) => v && v.in && v.out)
                      : null
                  const shiftLabel = ws
                    ? formatShiftRange12h(ws.time_in, ws.time_out)
                    : firstCustom
                      ? formatShiftRange12h(firstCustom.in, firstCustom.out)
                      : '—'
                  const nd =
                    ws &&
                    ndOverlapMinutes(toHhMm(ws.time_in) || ws.time_in, toHhMm(ws.time_out) || ws.time_out) > 0
                  const name =
                    ws?.name ||
                    (firstCustom ? 'Custom schedule' : hasSchedule(emp) ? 'Custom' : '—')
                  return (
                    <tr
                      key={emp.id}
                      className={cn(
                        'border-b border-border/50 transition-colors hover:bg-muted/35',
                        covIdx % 2 === 1 && 'bg-muted/20'
                      )}
                    >
                      <td className="px-3 py-3">
                        <div className="flex items-center gap-3">
                          <Avatar className="size-10">
                            <AvatarImage src={profileImageUrl(emp.profile_image)} alt="" className="object-cover" />
                            <AvatarFallback className="bg-primary/10 text-xs font-semibold text-primary">
                              {(emp.name || '?')
                                .split(/\s+/)
                                .map((n) => n[0])
                                .join('')
                                .toUpperCase()
                                .slice(0, 2)}
                            </AvatarFallback>
                          </Avatar>
                          <div>
                            <p className="font-medium text-foreground">{emp.name}</p>
                            <p className="text-[11px] text-muted-foreground">
                              {emp.department || '—'} · {emp.branch_name || '—'}
                            </p>
                          </div>
                        </div>
                      </td>
                      <td className="px-3 py-3">
                        <p className="font-medium">{name}</p>
                        <p className="text-[11px] text-muted-foreground">
                          {ws ? `Template #${ws.id}` : firstCustom ? 'Per-day JSON' : 'Unassigned'}
                        </p>
                      </td>
                      <td className="px-3 py-3">
                        <div className="flex flex-wrap items-center gap-1.5">
                          <span className="tabular-nums">{shiftLabel}</span>
                          {nd && <ShiftPill variant="nd">ND</ShiftPill>}
                        </div>
                      </td>
                      <td className="px-3 py-3">
                        {emp.is_active === false ? (
                          <Badge variant="secondary">Inactive</Badge>
                        ) : hasSchedule(emp) ? (
                          <Badge className="bg-emerald-600/15 text-emerald-800 dark:text-emerald-200">Scheduled</Badge>
                        ) : (
                          <Badge variant="destructive" className="font-normal">
                            No schedule
                          </Badge>
                        )}
                      </td>
                      <td className="px-3 py-3 text-right">
                        {ws && (
                          <Button variant="outline" size="sm" className="min-h-11" onClick={() => openAssign(ws)}>
                            Assign
                          </Button>
                        )}
                        {!ws && hasSchedule(emp) && (
                          <span className="text-xs text-muted-foreground">Edit in Employees</span>
                        )}
                        {!hasSchedule(emp) && (
                          <Button
                            variant="secondary"
                            size="sm"
                            className="min-h-11"
                            onClick={() => {
                              if (schedules[0]) openAssign(schedules[0])
                              else toast.message('Create a template first')
                            }}
                          >
                            Assign
                          </Button>
                        )}
                      </td>
                    </tr>
                  )
                })}
              </tbody>
            </table>
            {coverageRows.length === 0 && (
              <p className="px-4 py-10 text-center text-sm text-muted-foreground">No employees match these filters.</p>
            )}
          </div>
        </TabsContent>

        <TabsContent value="activity" className="space-y-4 outline-none">
          <div className="rounded-xl border border-border/60 bg-card p-5 shadow-sm">
            <div className="flex flex-col gap-3 @md:flex-row @md:items-center @md:justify-between">
              <div>
                <h2 className="text-lg font-black tracking-tight text-foreground">Recent schedule activity</h2>
                <p className="mt-1 text-sm text-muted-foreground">
                  Schedule adjustments, assignments, and template changes appear here as they happen.
                </p>
              </div>
              <Button
                type="button"
                variant="outline"
                className={workScheduleOutlineButtonClass}
                onClick={() => loadScheduleActivity({ fresh: true })}
                disabled={activityLoading}
              >
                {activityLoading ? <Loader2 className="size-4 animate-spin" /> : <Clock className="size-4" />}
                Refresh
              </Button>
            </div>
          </div>

          {activityError ? (
            <div className="flex items-start gap-2 rounded-xl border border-destructive/30 bg-destructive/10 px-4 py-3 text-sm text-destructive">
              <AlertTriangle className="mt-0.5 size-4" />
              <span>{activityError}</span>
            </div>
          ) : null}

          <div className="overflow-hidden rounded-xl border border-border/60 bg-card shadow-sm">
            {activityLoading && recentActivities.length === 0 ? (
              <div className="flex min-h-64 items-center justify-center">
                <Loader2 className="size-8 animate-spin text-muted-foreground" />
              </div>
            ) : recentActivities.length === 0 ? (
              <div className="flex min-h-64 flex-col items-center justify-center px-6 py-12 text-center">
                <Clock className="mb-3 size-10 text-muted-foreground/60" />
                <p className="text-base font-semibold text-foreground">No schedule activity yet</p>
                <p className="mt-1 max-w-md text-sm text-muted-foreground">
                  Create a schedule, update a template, or apply an adjustment to start the activity trail.
                </p>
              </div>
            ) : (
              <div className="divide-y divide-border/60 dark:divide-white/10">
                {recentActivities.map((activity) => {
                  const employees = Array.isArray(activity.employees) ? activity.employees : []
                  const extraCount = Math.max(0, Number(activity.employee_count || 0) - employees.length)
                  return (
                    <article key={activity.id} className="grid gap-4 px-5 py-4 transition-colors hover:bg-muted/25 @lg:grid-cols-[1fr_auto]">
                      <div className="min-w-0">
                        <div className="flex flex-wrap items-center gap-2">
                          <Badge variant="outline" className={cn('border-0 font-semibold ring-1', activityToneClass(activity.type))}>
                            {activity.type === 'adjustment'
                              ? 'Adjustment'
                              : activity.type === 'assignment'
                                ? 'Assignment'
                                : activity.type === 'template_updated'
                                  ? 'Template update'
                                  : 'Template'}
                          </Badge>
                          {activity.status ? (
                            <Badge variant="secondary" className="font-normal capitalize">{activity.status}</Badge>
                          ) : null}
                        </div>
                        <h3 className="mt-2 text-base font-black text-foreground">{activity.title}</h3>
                        <p className="mt-1 text-sm text-muted-foreground">{activity.description}</p>
                        <div className="mt-3 flex flex-wrap gap-x-4 gap-y-1 text-xs text-muted-foreground">
                          {activity.schedule_time ? <span>Shift: {activity.schedule_time}</span> : null}
                          {activity.effective_start_date ? <span>Effective: {activity.effective_start_date}</span> : null}
                          {activity.actor ? <span>By: {activity.actor}</span> : null}
                        </div>
                        {activity.reason ? (
                          <p className="mt-2 line-clamp-2 text-xs text-muted-foreground">Reason: {activity.reason}</p>
                        ) : null}
                        {employees.length > 0 ? (
                          <div className="mt-3 flex flex-wrap gap-2">
                            {employees.map((employee) => (
                              <span key={`${activity.id}-${employee.id}`} className="rounded-full bg-muted px-2.5 py-1 text-xs font-semibold text-foreground">
                                {employee.name}
                              </span>
                            ))}
                            {extraCount > 0 ? (
                              <span className="rounded-full bg-muted px-2.5 py-1 text-xs font-semibold text-muted-foreground">
                                +{extraCount} more
                              </span>
                            ) : null}
                          </div>
                        ) : null}
                      </div>
                      <div className="text-left text-xs font-semibold text-muted-foreground @lg:text-right">
                        {formatActivityTimestamp(activity.created_at)}
                      </div>
                    </article>
                  )
                })}
              </div>
            )}
          </div>
        </TabsContent>
      </Tabs>

      <ScheduleEditorDialog
        open={editOpen}
        onOpenChange={(open) => {
          if (!open) {
            setEditOpen(false)
            setEditingSchedule(null)
          }
        }}
        editingSchedule={editingSchedule}
        editForm={editForm}
        setEditForm={setEditForm}
        onSubmit={handleEditSubmit}
        submitting={editSubmitting}
        error={error}
      />

      {/* Delete schedule confirmation */}
      <Dialog
        open={!!deleteConfirmSchedule}
        onOpenChange={(open) => {
          if (!open) setDeleteConfirmSchedule(null)
        }}
      >
        <DialogContent
          showCloseButton
          className={adminFormDialogContentClass(ADMIN_FORM_DIALOG_MAX_W_MD)}
          aria-describedby="schedule-delete-desc"
        >
          <div className={ADMIN_FORM_DIALOG_HEADER_WRAP_CLASS}>
            <DialogHeader className={ADMIN_FORM_DIALOG_HEADER_INNER_CLASS}>
              <DialogTitle className={cn(ADMIN_FORM_DIALOG_TITLE_CLASS, 'flex items-center gap-2')}>
                <Trash2 className="size-5 text-destructive" />
                Delete schedule
              </DialogTitle>
              <p id="schedule-delete-desc" className={ADMIN_FORM_DIALOG_DESC_CLASS}>
                {deleteConfirmSchedule && (
                  <>
                    Are you sure you want to delete <strong className="text-foreground">{deleteConfirmSchedule.name}</strong>?
                    This cannot be undone.
                  </>
                )}
              </p>
            </DialogHeader>
          </div>
          <DialogFooter className={cn(ADMIN_FORM_DIALOG_FOOTER_CLASS, 'mt-auto')}>
            <Button
              type="button"
              variant="outline"
              onClick={() => setDeleteConfirmSchedule(null)}
              disabled={deleteSubmitting}
            >
              Cancel
            </Button>
            <Button
              type="button"
              variant="destructive"
              onClick={confirmDelete}
              disabled={deleteSubmitting}
              className="gap-2"
            >
              {deleteSubmitting ? <Loader2 className="size-4 animate-spin" /> : <Trash2 className="size-4" />}
              Delete schedule
            </Button>
          </DialogFooter>
        </DialogContent>
      </Dialog>

      {/* Assign schedule dialog */}
      <Dialog
        open={assignOpen}
        onOpenChange={(open) => {
          if (!open) {
            setAssignOpen(false)
            setAssignSchedule(null)
            setSelectedEmployeeIds([])
            setAssignSearch('')
            setAssignFilter('all')
            setAssignPage(1)
          }
        }}
      >
        <DialogContent
          showCloseButton
          surfaceStyle={{ width: 'min(98vw, 1280px)', maxWidth: 'min(98vw, 1280px)' }}
          overlayClassName="bg-black/55 backdrop-blur-sm dark:bg-black/70"
          closeButtonClassName="right-5 top-5 size-10 rounded-xl border-border/80 bg-background/90 text-foreground shadow-sm hover:bg-muted dark:border-white/10 dark:bg-card/90"
          className="max-h-[min(92vh,calc(100dvh-2rem))]! max-w-none! w-full overflow-hidden rounded-[18px] border-border/80 bg-card shadow-[0_24px_80px_-24px_rgba(0,0,0,0.5)] dark:border-white/10 dark:bg-card"
          innerClassName="flex min-h-0 flex-1 flex-col gap-0 overflow-hidden p-0!"
          aria-describedby="schedule-assign-desc"
        >
          <form onSubmit={handleAssignSubmit} className="flex max-h-[min(92vh,calc(100dvh-2rem))] min-h-[32rem] flex-col overflow-hidden">
            <DialogHeader className="shrink-0 border-b border-border/70 px-6 pb-4 pt-5 pr-16 text-left dark:border-white/10">
              <div className="flex items-start gap-4">
                <div className="flex size-11 shrink-0 items-center justify-center rounded-xl bg-brand/10 text-brand ring-1 ring-brand/15 dark:bg-brand/15 dark:ring-brand/25">
                  <Users className="size-6" aria-hidden />
                </div>
                <div className="min-w-0 space-y-1">
                  <DialogTitle className="text-xl font-black tracking-tight text-foreground">Assign schedule</DialogTitle>
                  <p id="schedule-assign-desc" className="text-sm leading-relaxed text-muted-foreground">
                    {assignSchedule ? (
                      <>
                        <span className="font-bold text-brand">{assignSchedule.name}</span>
                        <span className="mx-2 text-muted-foreground">·</span>
                        Select employees who should follow this schedule.
                      </>
                    ) : (
                      'Select employees who should follow this schedule.'
                    )}
                  </p>
                </div>
              </div>
            </DialogHeader>

            <div className="shrink-0 border-b border-border/70 px-6 py-4 dark:border-white/10">
              <div className="grid grid-cols-1 gap-3 lg:grid-cols-[minmax(0,1fr)_15rem_auto] lg:items-end">
                <div className="relative min-w-0">
                  <Search className="absolute left-3 top-1/2 size-4 -translate-y-1/2 text-muted-foreground" aria-hidden />
                  <Input
                    type="search"
                    placeholder="Search by name, employee ID, or department..."
                    value={assignSearch}
                    onChange={(e) => {
                      setAssignSearch(e.target.value)
                      setAssignPage(1)
                    }}
                    className="h-11 rounded-xl border-border/80 bg-background pl-10 text-sm shadow-sm dark:border-white/10 dark:bg-background/40"
                    aria-label="Search employees"
                  />
                </div>
                <label className="min-w-0 space-y-1.5 text-sm font-medium text-foreground">
                  <span>Filter</span>
                  <select
                    value={assignFilter}
                    onChange={(e) => {
                      setAssignFilter(e.target.value)
                      setAssignPage(1)
                    }}
                    className="h-11 w-full rounded-xl border border-border/80 bg-background px-3 text-sm font-semibold text-foreground shadow-sm outline-none transition focus:border-brand focus:ring-4 focus:ring-brand/15 dark:border-white/10 dark:bg-background/40"
                    aria-label="Filter by schedule status"
                  >
                    <option value="all">All others ({countNotOnThisShift})</option>
                    <option value="available_only">Available - no shift ({countTrulyAvailable})</option>
                    <option value="on_this_shift">This shift only ({countOnThisShift})</option>
                    <option value="without_schedule">Without schedule (same as Available)</option>
                    <option value="with_schedule">Has other shift</option>
                  </select>
                </label>
                {employeesWithoutSchedule.length > 0 ? (
                  <Button
                    type="button"
                    variant="outline"
                    className="h-11 justify-center gap-2 rounded-xl border-brand px-4 text-sm font-bold text-brand hover:bg-brand/10 dark:border-brand/60 dark:hover:bg-brand/15"
                    onClick={bulkSelectWithoutSchedule}
                  >
                    <Users className="size-4 shrink-0" aria-hidden />
                    Bulk: no schedule ({employeesWithoutSchedule.length})
                  </Button>
                ) : (
                  <div className="hidden lg:block" aria-hidden />
                )}
              </div>
            </div>

            <div className="flex min-h-0 flex-1 flex-col overflow-hidden px-6 py-4">
              {assignEmployeesLoading ? (
                <div className="flex min-h-80 flex-1 items-center justify-center">
                  <Loader2 className="size-8 animate-spin text-muted-foreground" aria-hidden />
                </div>
              ) : employees.length === 0 ? (
                <div className="rounded-xl border border-border/70 bg-background/70 px-5 py-10 text-center text-sm text-muted-foreground dark:border-white/10 dark:bg-background/35">
                  No employees found. Add employees first.
                </div>
              ) : (
                <div className="grid min-h-0 flex-1 grid-cols-1 gap-5 overflow-hidden lg:grid-cols-2">
                  {assignSchedule ? (
                    <section
                      className={cn(
                        'flex min-h-[280px] min-w-0 flex-col overflow-hidden rounded-xl border border-border/70 bg-background/60 shadow-sm dark:border-white/10 dark:bg-background/30 lg:min-h-0',
                        assignFilter === 'on_this_shift' && 'lg:col-span-2'
                      )}
                    >
                      <div className="flex shrink-0 items-center justify-between gap-3 border-b border-border/70 bg-muted/30 px-4 py-3 dark:border-white/10">
                        <h3 className="text-sm font-black tracking-tight text-foreground">
                          On this shift
                          <span className="ml-2 font-semibold text-muted-foreground">({employeesOnThisShiftFiltered.length})</span>
                        </h3>
                        <span className="text-xs text-muted-foreground">Uncheck or Unassign to remove</span>
                      </div>
                      <div className="min-h-0 flex-1 overflow-x-hidden overflow-y-auto">
                        {employeesOnThisShiftFiltered.length === 0 ? (
                          <p className="px-4 py-8 text-sm text-muted-foreground">
                            {countOnThisShift > 0 && assignSearchTrimmed
                              ? 'No one on this shift matches your search.'
                              : 'No employees assigned to this shift yet.'}
                          </p>
                        ) : (
                          <div className="divide-y divide-border/70 dark:divide-white/10">
                          {employeesOnThisShiftFiltered.map((emp) => {
                            const checked = selectedEmployeeIds.includes(emp.id)
                            const initials =
                              (emp.name || '?')
                                .trim()
                                .split(/\s+/)
                                .map((n) => n[0])
                                .join('')
                                .toUpperCase()
                                .slice(0, 2) || '?'
                            const isUnassigning = unassigningId === emp.id
                            return (
                              <div
                                key={`on-${emp.id}`}
                                role="row"
                                className="grid cursor-pointer grid-cols-[auto_auto_minmax(0,1fr)_auto] items-center gap-x-3 gap-y-1 px-4 py-2.5 transition-colors hover:bg-muted/35"
                                onClick={() => toggleEmployeeSelection(emp.id)}
                              >
                                <div className="flex items-center" onClick={(e) => e.stopPropagation()}>
                                  <Checkbox
                                    checked={checked}
                                    onCheckedChange={() => toggleEmployeeSelection(emp.id)}
                                    onClick={(e) => e.stopPropagation()}
                                    className="size-4 data-[state=checked]:border-foreground data-[state=checked]:bg-foreground data-[state=checked]:text-background"
                                  />
                                </div>
                                <Avatar className="size-9 rounded-full bg-brand/10">
                                  <AvatarImage src={profileImageUrl(emp.profile_image)} alt="" className="object-cover" />
                                  <AvatarFallback className="rounded-full bg-brand/10 text-[10px] font-bold text-brand dark:bg-brand/15">
                                    {initials}
                                  </AvatarFallback>
                                </Avatar>
                                <div className="min-w-0 overflow-hidden">
                                  <p className="truncate text-sm font-semibold text-foreground">{emp.name}</p>
                                  <p className="truncate text-xs text-muted-foreground">{emp.department || 'No department'}</p>
                                </div>
                                <Button
                                  type="button"
                                  variant="ghost"
                                  size="sm"
                                  className="h-8 shrink-0 px-2 text-xs font-semibold text-muted-foreground hover:text-brand"
                                  disabled={isUnassigning}
                                  onClick={(e) => handleUnassign(emp, e)}
                                >
                                  {isUnassigning ? <Loader2 className="size-3.5 animate-spin" /> : 'Unassign'}
                                </Button>
                              </div>
                            )
                          })}
                          </div>
                        )}
                      </div>
                    </section>
                  ) : null}

                  {assignFilter !== 'on_this_shift' ? (
                    <section className="flex min-h-[280px] min-w-0 flex-col overflow-hidden rounded-xl border border-border/70 bg-background/60 shadow-sm dark:border-white/10 dark:bg-background/30 lg:min-h-0">
                      <div className="flex shrink-0 items-center justify-between gap-3 border-b border-border/70 bg-muted/30 px-4 py-3 dark:border-white/10">
                        <h3 className="text-sm font-black tracking-tight text-foreground">
                          {assignFilter === 'available_only' || assignFilter === 'without_schedule'
                            ? 'Available - no shift assigned'
                            : assignFilter === 'with_schedule'
                              ? 'Has another shift'
                              : 'Other employees'}
                          <span className="ml-2 font-semibold text-muted-foreground">({assignFilteredOthers.length})</span>
                        </h3>
                        <span className="text-xs text-muted-foreground">Selected: {selectedEmployeeIds.length}</span>
                      </div>
                      <label className="flex shrink-0 cursor-pointer items-center gap-3 border-b border-border/70 px-4 py-2.5 text-xs font-semibold hover:bg-muted/35 dark:border-white/10">
                        <Checkbox
                          checked={
                            (() => {
                              const selectable = assignPaginatedOthers.filter((e) => !hasSchedule(e))
                              if (selectable.length === 0) return false
                              const allSelected = selectable.every((e) => selectedEmployeeIds.includes(e.id))
                              const someSelected = selectable.some((e) => selectedEmployeeIds.includes(e.id))
                              return allSelected ? true : someSelected ? 'indeterminate' : false
                            })()
                          }
                          onCheckedChange={(checked) => {
                            if (checked) {
                              const ids = new Set(selectedEmployeeIds)
                              assignPaginatedOthers.forEach((e) => {
                                if (hasSchedule(e)) return
                                ids.add(e.id)
                              })
                              setSelectedEmployeeIds(Array.from(ids))
                            } else {
                              const removeIds = new Set(assignPaginatedOthers.map((e) => e.id))
                              setSelectedEmployeeIds(selectedEmployeeIds.filter((id) => !removeIds.has(id)))
                            }
                          }}
                          aria-label="Select all with no shift on this page"
                          className="size-5 data-[state=checked]:border-foreground data-[state=checked]:bg-foreground data-[state=checked]:text-background"
                        />
                        <span className="text-foreground">Select all with no shift (this page)</span>
                      </label>
                      <div className="min-h-0 flex-1 overflow-x-hidden overflow-y-auto">
                        {assignFilteredOthers.length === 0 ? (
                          <p className="px-5 py-8 text-sm text-muted-foreground">No employees in this list for the current filter.</p>
                        ) : (
                          <div className="divide-y divide-border/70 dark:divide-white/10">
                          {assignPaginatedOthers.map((emp) => {
                            const checked = selectedEmployeeIds.includes(emp.id)
                            const blockedOther = isAssignedOtherShift(emp, assignSchedule)
                            const currentSchedule = emp.working_schedule_id
                              ? schedules.find((s) => Number(s.id) === Number(emp.working_schedule_id))
                              : null
                            const firstCustomDay =
                              emp.schedule && Object.values(emp.schedule || {}).find((v) => v?.in && v?.out)
                            const currentTime = currentSchedule
                              ? formatShiftRange12h(currentSchedule.time_in, currentSchedule.time_out, ' - ')
                              : firstCustomDay
                                ? formatShiftRange12h(firstCustomDay.in, firstCustomDay.out, ' - ')
                                : '-'
                            const initials =
                              (emp.name || '?')
                                .trim()
                                .split(/\s+/)
                                .map((n) => n[0])
                                .join('')
                                .toUpperCase()
                                .slice(0, 2) || '?'
                            const currentScheduleName = currentSchedule?.name || (firstCustomDay ? 'Custom schedule' : '-')
                            const tooltipText = blockedOther
                              ? `Already on:\n${currentScheduleName} (${currentTime})\n\nUnassign there first.`
                              : 'No schedule - click to assign to this shift'
                            const isUnassigning = unassigningId === emp.id
                            return (
                              <div
                                key={`other-${emp.id}`}
                                role="row"
                                className={cn(
                                  'grid grid-cols-[auto_auto_minmax(0,1fr)_auto] items-center gap-x-3 gap-y-1 px-4 py-2.5 transition-colors',
                                  blockedOther ? 'cursor-not-allowed opacity-60 hover:bg-muted/20' : 'cursor-pointer hover:bg-muted/35'
                                )}
                                onClick={() => {
                                  if (blockedOther) {
                                    toast.error('Cannot assign', {
                                      description: 'Employee already has a schedule. Please unassign from the current shift first.',
                                    })
                                  } else {
                                    toggleEmployeeSelection(emp.id)
                                  }
                                }}
                              >
                                <div className="flex items-center" onClick={(e) => e.stopPropagation()}>
                                  <Checkbox
                                    checked={checked}
                                    disabled={blockedOther}
                                    onCheckedChange={() => !blockedOther && toggleEmployeeSelection(emp.id)}
                                    onClick={(e) => e.stopPropagation()}
                                    className="size-4 data-[state=checked]:border-foreground data-[state=checked]:bg-foreground data-[state=checked]:text-background"
                                  />
                                </div>
                                <Avatar className="size-9 rounded-full bg-brand/10">
                                  <AvatarImage src={profileImageUrl(emp.profile_image)} alt="" className="object-cover" />
                                  <AvatarFallback className="rounded-full bg-brand/10 text-[10px] font-bold text-brand dark:bg-brand/15">
                                    {initials}
                                  </AvatarFallback>
                                </Avatar>
                                <div className="min-w-0 overflow-hidden">
                                  <p className="truncate text-sm font-semibold text-foreground">{emp.name}</p>
                                  <p className="truncate text-xs text-muted-foreground">{emp.department || 'No department'}</p>
                                </div>
                                {blockedOther ? (
                                  <div className="flex shrink-0 flex-col items-end gap-0.5">
                                    <span
                                      className="inline-flex items-center gap-1 rounded-md bg-amber-500/15 px-2 py-0.5 text-[10px] font-semibold text-amber-700 dark:text-amber-300"
                                      title={tooltipText}
                                    >
                                      <Lock className="size-3" />
                                      Assigned
                                    </span>
                                    <Button
                                      type="button"
                                      variant="ghost"
                                      size="sm"
                                      className="h-7 px-1 text-[10px] font-semibold text-muted-foreground hover:text-brand"
                                      disabled={isUnassigning}
                                      onClick={(e) => handleUnassign(emp, e)}
                                    >
                                      {isUnassigning ? <Loader2 className="size-3 animate-spin" /> : 'Unassign'}
                                    </Button>
                                  </div>
                                ) : (
                                  <span
                                    className="inline-flex shrink-0 items-center rounded-md bg-emerald-500/15 px-2 py-0.5 text-[10px] font-semibold text-emerald-700 dark:text-emerald-300"
                                    title={tooltipText}
                                  >
                                    Available
                                  </span>
                                )}
                              </div>
                            )
                          })}
                          </div>
                        )}
                      </div>
                      {assignFilteredOthers.length > ASSIGN_PAGE_SIZE ? (
                        <div className="flex shrink-0 items-center justify-between border-t border-border/70 px-5 py-3 text-sm dark:border-white/10">
                          <span className="text-muted-foreground">
                            Page {assignPage} of {assignTotalPages} ({assignFilteredOthers.length} employees)
                          </span>
                          <div className="flex gap-2">
                            <Button
                              type="button"
                              variant="outline"
                              className="h-9 rounded-lg px-3"
                              disabled={assignPage <= 1}
                              onClick={() => setAssignPage((p) => Math.max(1, p - 1))}
                            >
                              Previous
                            </Button>
                            <Button
                              type="button"
                              variant="outline"
                              className="h-9 rounded-lg border-brand px-3 text-brand hover:bg-brand/10"
                              disabled={assignPage >= assignTotalPages}
                              onClick={() => setAssignPage((p) => Math.min(assignTotalPages, p + 1))}
                            >
                              Next
                            </Button>
                          </div>
                        </div>
                      ) : null}
                    </section>
                  ) : null}
                </div>
              )}
            </div>

            <DialogFooter className="shrink-0 border-t border-border/70 bg-card px-6 py-4 dark:border-white/10">
              <Button
                type="button"
                variant="outline"
                className="h-10 min-w-32 rounded-xl border-border/80 bg-card px-6 text-sm font-semibold text-foreground hover:bg-muted dark:border-white/10"
                onClick={() => {
                  setAssignOpen(false)
                  setAssignSchedule(null)
                  setSelectedEmployeeIds([])
                  setAssignSearch('')
                }}
              >
                Cancel
              </Button>
              <Button
                type="submit"
                disabled={assignSubmitting || selectedEmployeeIds.length === 0}
                className="h-10 min-w-44 rounded-xl bg-brand px-6 text-sm font-semibold text-brand-foreground shadow-[0_14px_28px_-18px_rgba(234,88,12,0.95)] hover:bg-brand-strong dark:shadow-[0_14px_30px_-20px_rgba(251,146,60,0.8)]"
              >
                {assignSubmitting ? <Loader2 className="size-4 animate-spin" /> : 'Assign schedule'}
              </Button>
            </DialogFooter>
          </form>
        </DialogContent>
      </Dialog>

      <ScheduleAdjustmentDialog
        open={adjustmentOpen}
        onOpenChange={setAdjustmentOpen}
        schedules={schedules}
        onApplied={async () => {
          await loadSchedules({ silent: true, fresh: true })
          await loadEmployees({ fresh: true })
          await loadScheduleActivity({ fresh: true })
          notifySchedulePropagation()
        }}
      />
    </div>
  )
}
