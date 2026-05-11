import { useCallback, useEffect, useMemo, useState } from 'react'
import { useNavigate, useSearchParams } from 'react-router-dom'
import {
  BriefcaseBusiness,
  Building2,
  Calculator,
  CheckCircle2,
  ChevronDown,
  CirclePlus,
  Eye,
  Info,
  Pencil,
  RefreshCw,
  Save,
  Search,
  Trash2,
  UserCircle2,
  UsersRound,
  Wallet,
} from 'lucide-react'
import { Avatar, AvatarFallback, AvatarImage } from '@/components/ui/avatar'
import { Badge } from '@/components/ui/badge'
import { Button } from '@/components/ui/button'
import {
  Dialog,
  DialogContent,
  DialogDescription,
  DialogFooter,
  DialogHeader,
  DialogTitle,
} from '@/components/ui/dialog'
import { Tabs, TabsContent, TabsList, TabsTrigger } from '@/components/ui/tabs'
import {
  Table,
  TableBody,
  TableCell,
  TableHead,
  TableHeader,
  TableRow,
} from '@/components/ui/table'
import { useToast } from '@/components/ui/use-toast'
import { useHrBasePath } from '@/contexts/HrAppPathContext'
import { employeeAvatarSrc, getEmployeeAvatarColorClass } from '@/lib/employeeAvatar'
import { hrPanelPath } from '@/lib/hrRoutes'
import { cn } from '@/lib/utils'
import {
  assignEmployeeCompensation,
  deleteEmployeeCompensation,
  getEmployeeCompensation,
  getEmployees,
  getPayComponents,
  updateEmployeeCompensation,
} from '@/api'

const EMPTY_FORM = {
  pay_component_id: '',
  name: '',
  code: '',
  type: 'earning',
  category: 'Fixed Allowance',
  calculation_type: 'fixed_amount',
  value: '0',
  hourly_rate: '',
  hours: '',
  formula: '',
  is_taxable: true,
  contributes_sss: false,
  contributes_philhealth: false,
  contributes_pagibig: false,
  is_proratable: false,
  show_on_payslip: true,
  is_custom: false,
  schedule_override: 'default',
}

/** Options for pay-component schedule override (PATCH / assign); must stay aligned with backend validation. */
const PAY_COMPONENT_SCHEDULE_OPTIONS = [
  { value: 'default', label: 'Default — from Deduction Schedule Settings' },
  { value: 'first_run', label: '15th' },
  { value: 'split', label: 'Split 15/30' },
  { value: 'second_run', label: 'End of month' },
]

function normalizedScheduleSelectValue(scheduleOverride) {
  const v = scheduleOverride
  if (v == null || v === '' || v === 'default') return 'default'
  const s = String(v).trim()
  // Legacy DB value — same timing as split; keep select controlled without a duplicate option.
  if (s === 'monthly') return 'split'
  return s
}

/** YYYY-MM-DD for company payroll context (must match typical API as_of_date); avoids UTC drift from toISOString().slice. */
function payrollCalendarDateYmd(timeZone = 'Asia/Manila') {
  try {
    return new Intl.DateTimeFormat('en-CA', {
      timeZone,
      year: 'numeric',
      month: '2-digit',
      day: '2-digit',
    }).format(new Date())
  } catch {
    return new Date().toISOString().slice(0, 10)
  }
}

/** Mirrors backend PayComponentSchedule → DeductionScheduleSetting schedule_type tokens on summary lines */
function resolvedScheduleFromStoredOverride(slug) {
  const s = slug == null ? '' : String(slug).trim()
  if (!s) return null
  if (s === 'first_run') return '15th'
  if (s === 'second_run') return '30th'
  if (s === 'split' || s === 'monthly') return 'both'
  return null
}

function patchCompensationRowSchedule(row, assignment) {
  const raw = assignment?.schedule_override
  const hasExplicit = raw != null && String(raw).trim() !== ''
  const resolved = hasExplicit ? resolvedScheduleFromStoredOverride(raw) : null
  const defaultSchedule = row.default_schedule
  const nextResolved = resolved ?? defaultSchedule ?? row.resolved_schedule ?? row.pay_schedule_type
  return {
    ...row,
    schedule_override: hasExplicit ? raw : null,
    schedule_source: hasExplicit ? 'employee_override' : 'default_schedule',
    resolved_schedule: nextResolved,
    pay_schedule_type: nextResolved,
  }
}

/** Inline schedule PATCH: merge server assignment into cached summary so the UI updates before the next fetch finishes. */
function mergeSchedulePatchIntoCompensationData(prevEmployees, employeeId, assignment) {
  const aid = Number(assignment?.id)
  const eid = Number(employeeId)
  if (!Number.isFinite(aid) || aid <= 0 || !Number.isFinite(eid)) return prevEmployees
  if (!Array.isArray(prevEmployees)) return prevEmployees

  return prevEmployees.map((entry) => {
    if (Number(entry?.employee?.id) !== eid) return entry
    const summary = entry.summary
    if (!summary || typeof summary !== 'object') return entry

    const patchLines = (lines) => {
      if (!Array.isArray(lines)) return lines
      return lines.map((line) => (Number(line?.id) === aid ? patchCompensationRowSchedule(line, assignment) : line))
    }

    return {
      ...entry,
      summary: {
        ...summary,
        earnings: patchLines(summary.earnings),
        deductions: patchLines(summary.deductions),
      },
    }
  })
}

export default function AdminEmployeeCompensationPage() {
  const { toast } = useToast()
  const navigate = useNavigate()
  const [searchParams] = useSearchParams()
  const hrBase = useHrBasePath()
  const [employees, setEmployees] = useState([])
  const [components, setComponents] = useState([])
  const [compensationData, setCompensationData] = useState([])
  const [employeeSearch, setEmployeeSearch] = useState('')
  const [effectiveFrom] = useState(() => payrollCalendarDateYmd())
  const [loading, setLoading] = useState(true)
  const [saving, setSaving] = useState(false)
  const [activeEmployeeId, setActiveEmployeeId] = useState(null)
  const [dialogOpen, setDialogOpen] = useState(false)
  const [draftForm, setDraftForm] = useState(EMPTY_FORM)
  const [pendingAssignments, setPendingAssignments] = useState([])
  const [removeDialogOpen, setRemoveDialogOpen] = useState(false)
  const [assignmentToRemove, setAssignmentToRemove] = useState(null)
  const [removing, setRemoving] = useState(false)
  const [editingId, setEditingId] = useState(null)
  const [editValue, setEditValue] = useState('')
  const [updatingValue, setUpdatingValue] = useState(false)
  const [updatingScheduleId, setUpdatingScheduleId] = useState(null)
  const preselectedEmployeeId = Number(searchParams.get('employee_id') || 0)

  const loadLookups = useCallback(async (searchTerm = employeeSearch) => {
    setLoading(true)
    try {
      const [employeeRes, componentRes] = await Promise.all([
        getEmployees({ q: searchTerm, per_page: 100 }),
        getPayComponents({ all: true }),
      ])
      const employeeRows = Array.isArray(employeeRes?.employees) ? employeeRes.employees : []
      setEmployees(employeeRows)
      setComponents(Array.isArray(componentRes?.components) ? componentRes.components : [])
      if (employeeRows.length > 0) {
        if (preselectedEmployeeId > 0 && employeeRows.some((row) => Number(row.id) === preselectedEmployeeId)) {
          setActiveEmployeeId(preselectedEmployeeId)
        } else if (activeEmployeeId == null) {
          setActiveEmployeeId(employeeRows[0].id)
        }
      }
    } catch (error) {
      toast({
        title: 'Employee compensation',
        description: error.message || 'Failed to load compensation data',
        variant: 'destructive',
      })
    } finally {
      setLoading(false)
    }
  }, [activeEmployeeId, employeeSearch, preselectedEmployeeId, toast])

  const refreshCompensation = useCallback(async (employeeId = activeEmployeeId) => {
    const ids = employeeId ? [employeeId] : []
    if (ids.length === 0) {
      setCompensationData([])
      return
    }
    try {
      const data = await getEmployeeCompensation({
        employee_ids: ids,
        as_of_date: effectiveFrom || undefined,
      })
      setCompensationData(Array.isArray(data?.employees) ? data.employees : [])
      if (data?.recalculation_queued) {
        toast({
          title: 'Recalculating compensation',
          description: 'Updated totals will appear shortly after the background job finishes.',
        })
        const id = ids[0]
        setTimeout(() => {
          void getEmployeeCompensation({
            employee_ids: [id],
            as_of_date: effectiveFrom || undefined,
          })
            .then((d) => {
              if (Array.isArray(d?.employees)) setCompensationData(d.employees)
            })
            .catch(() => {})
        }, 3500)
      }
    } catch (error) {
      toast({
        title: 'Employee compensation',
        description: error.message || 'Failed to load selected employee compensation',
        variant: 'destructive',
      })
    }
  }, [activeEmployeeId, effectiveFrom, toast])

  useEffect(() => {
    loadLookups()
  }, [loadLookups])

  useEffect(() => {
    const timer = setTimeout(() => {
      loadLookups(employeeSearch)
    }, 260)
    return () => clearTimeout(timer)
  }, [employeeSearch, loadLookups])

  useEffect(() => {
    refreshCompensation()
  }, [activeEmployeeId, effectiveFrom, refreshCompensation])

  useEffect(() => {
    setEditingId(null)
    setEditValue('')
    setUpdatingScheduleId(null)
  }, [activeEmployeeId])

  const activeEmployee = useMemo(
    () => employees.find((employee) => employee.id === activeEmployeeId) || null,
    [activeEmployeeId, employees],
  )

  const activeCompensation = useMemo(
    () => compensationData.find((entry) => entry.employee?.id === activeEmployeeId) || null,
    [activeEmployeeId, compensationData],
  )
  const activeEmployeePhoto = employeeAvatarSrc(activeEmployee)
  const activeEmployeeFallbackClass = getEmployeeAvatarColorClass(activeEmployee?.id, activeEmployee?.name)

  const summary = activeCompensation?.summary || {}
  const earnings = summary.earnings || []
  const deductions = summary.deductions || []
  const gross = summary?.totals?.gross_earnings || 0
  const summaryPending = Boolean(summary?._summary_pending) || (Array.isArray(earnings) && earnings.length === 0 && Number(gross) === 0)

  async function handleRefreshPreview() {
    await refreshCompensation()
    toast({
      title: 'Preview refreshed',
      description: 'Gross pay preview updated from the latest compensation assignments.',
    })
  }
  function selectEmployee(employee) {
    setActiveEmployeeId(employee.id)
  }

  function openNewAssignmentDialog() {
    if (!activeEmployee) {
      toast({ title: 'Employee compensation', description: 'Select an employee first.', variant: 'destructive' })
      return
    }
    setDraftForm(EMPTY_FORM)
    setDialogOpen(true)
  }

  const debugSchedule = useCallback((selection, payload) => {
    const dev =
      typeof import.meta !== 'undefined' &&
      import.meta.env !== undefined &&
      Boolean(import.meta.env.DEV)
    if (!dev) return
    console.debug('[hr] compensation.schedule', selection, payload)
  }, [])

  function applyMasterComponent(componentId) {
    const master = components.find((item) => String(item.id) === String(componentId))
    if (!master) {
      setDraftForm(EMPTY_FORM)
      return
    }
    const meta = master.metadata && typeof master.metadata === 'object' ? master.metadata : {}
    const calc = master.calculation_type || 'fixed_amount'
    let initValue = String(master.default_value ?? 0)
    let initHourlyRate = ''
    let initHours = ''
    if (calc === 'hourly') {
      initHourlyRate = meta.default_hourly_rate != null ? String(meta.default_hourly_rate) : String(master.default_value ?? '')
      initHours = meta.default_hours != null ? String(meta.default_hours) : ''
      initValue = initHourlyRate || '0'
    } else if (calc === 'daily_rate') {
      initHours = meta.default_days != null ? String(meta.default_days) : ''
    } else if (calc === 'percent_basic' || calc === 'percent_gross') {
      initValue = meta.default_percent != null ? String(meta.default_percent) : String(master.default_value ?? 0)
    }
    debugSchedule('apply_master_prefill_default', {
      draft_schedule_override: 'default',
      pay_component_id: master.id,
    })
    setDraftForm({
      pay_component_id: master.id,
      name: master.name,
      code: master.code,
      type: master.type,
      category: master.category || 'Fixed Allowance',
      calculation_type: calc,
      value: initValue,
      hourly_rate: initHourlyRate,
      hours: initHours,
      formula: master.formula || '',
      is_taxable: Boolean(master.is_taxable),
      contributes_sss: Boolean(master.contributes_sss),
      contributes_philhealth: Boolean(master.contributes_philhealth),
      contributes_pagibig: Boolean(master.contributes_pagibig),
      is_proratable: Boolean(master.is_proratable),
      show_on_payslip: true,
      is_custom: false,
      schedule_override: 'default',
    })
  }

  function addPendingAssignment(e) {
    e.preventDefault()
    if (!activeEmployee) return
    if (!draftForm.pay_component_id) {
      toast({
        title: 'Employee compensation',
        description: 'Select a pay component before adding it to pending changes.',
        variant: 'destructive',
      })
      return
    }
    setPendingAssignments((prev) => [
      ...prev,
      {
        employeeId: activeEmployee.id,
        employeeName: activeEmployee.name,
        ...draftForm,
        value: Number(draftForm.value || 0),
        hourly_rate: draftForm.hourly_rate !== '' && draftForm.hourly_rate !== null
          ? Number(draftForm.hourly_rate)
          : null,
        hours: draftForm.hours !== '' && draftForm.hours !== null
          ? Number(draftForm.hours)
          : null,
        formula: draftForm.formula || null,
        pay_component_id: draftForm.pay_component_id || null,
        show_on_payslip: true,
        is_custom: false,
        schedule_override: draftForm.schedule_override,
      },
    ])
    setDialogOpen(false)
    setDraftForm(EMPTY_FORM)
  }

  async function savePendingAssignments() {
    if (pendingAssignments.length === 0) return
    setSaving(true)
    try {
      const grouped = pendingAssignments.reduce((acc, item) => {
        const key = [
          item.employeeId,
          item.effective_from || '',
          item.effective_to || '',
        ].join(':')
        acc[key] = acc[key] || {
          employeeId: item.employeeId,
          effective_from: item.effective_from || null,
          effective_to: item.effective_to || null,
          items: [],
        }
        acc[key].items.push(item)
        return acc
      }, {})

      for (const group of Object.values(grouped)) {
        debugSchedule('employee_compensation.schedule_assign_payload', {
          employee_id: Number(group.employeeId),
          items: group.items.map((item) => ({
            pay_component_id: item.pay_component_id,
            schedule_override: item.schedule_override !== 'default' ? item.schedule_override : null,
          })),
        })
        await assignEmployeeCompensation({
          employee_ids: [Number(group.employeeId)],
          structure_name: null,
          effective_from: group.effective_from,
          effective_to: group.effective_to,
          components: group.items.map((item) => ({
            pay_component_id: item.pay_component_id,
            name: item.name,
            code: item.code,
            type: item.type,
            category: item.category,
            calculation_type: item.calculation_type,
            value: Number(item.value || 0),
            hourly_rate: item.hourly_rate != null ? Number(item.hourly_rate) : null,
            hours: item.hours != null ? Number(item.hours) : null,
            formula: item.formula || null,
            is_taxable: item.is_taxable,
            contributes_sss: item.contributes_sss,
            contributes_philhealth: item.contributes_philhealth,
            contributes_pagibig: item.contributes_pagibig,
            is_proratable: item.is_proratable,
            is_custom: item.is_custom,
            schedule_override: item.schedule_override !== 'default' ? item.schedule_override : null,
          })),
        })
      }

      toast({ title: 'Employee compensation', description: 'Compensation changes saved successfully.' })
      setPendingAssignments([])
      await refreshCompensation()
      window.dispatchEvent(new CustomEvent('hr:employee-compensation-changed'))
    } catch (error) {
      toast({
        title: 'Employee compensation',
        description: error.message || 'Failed to save pending compensation changes',
        variant: 'destructive',
      })
    } finally {
      setSaving(false)
    }
  }

  function requestRemoveAssignment(employeeId, assignment) {
    setAssignmentToRemove({ employeeId, assignment })
    setRemoveDialogOpen(true)
  }

  async function confirmRemoveAssignment() {
    if (!assignmentToRemove) return
    const { employeeId, assignment } = assignmentToRemove
    setRemoving(true)
    try {
      await deleteEmployeeCompensation(employeeId, assignment.id)
      toast({ title: 'Employee compensation', description: 'Assignment removed.' })
      setRemoveDialogOpen(false)
      setAssignmentToRemove(null)
      await refreshCompensation()
      window.dispatchEvent(new CustomEvent('hr:employee-compensation-changed'))
    } catch (error) {
      const msg = String(error?.message || '')
      toast({
        title: 'Employee compensation',
        description: msg || 'Failed to remove assignment',
        variant: 'destructive',
      })
      if (msg.includes('no longer available') || msg.includes('No query results')) {
        await refreshCompensation(employeeId)
      }
    } finally {
      setRemoving(false)
    }
  }

  function startEditing(item) {
    if (updatingScheduleId === item?.id) return
    setEditingId(item.id)
    setEditValue(String(item.configured_value ?? item.computed_amount ?? 0))
  }

  function cancelEditing() {
    setEditingId(null)
    setEditValue('')
  }

  const saveScheduleOverride = useCallback(async (item, selectValue) => {
    if (!activeEmployee?.id || !item?.id || !item.pay_component_id) return
    const incoming = normalizedScheduleSelectValue(selectValue)
    const current = normalizedScheduleSelectValue(item.schedule_override)
    if (incoming === current) return

    const payloadSlug = incoming === 'default' ? 'default' : incoming
    setUpdatingScheduleId(item.id)
    try {
      debugSchedule('employee_compensation.schedule_patch', {
        employee_id: activeEmployee.id,
        assignment_id: item.id,
        pay_component_id: item.pay_component_id,
        payload_schedule_override: payloadSlug,
        previous_normalized: current,
      })
      const patchRes = await updateEmployeeCompensation(activeEmployee.id, item.id, {
        schedule_override: payloadSlug,
      })
      const assignment = patchRes?.assignment
      toast({
        title: 'Schedule updated',
        description: `${item.name}: ${PAY_COMPONENT_SCHEDULE_OPTIONS.find((o) => o.value === incoming)?.label ?? incoming}.`,
      })
      await refreshCompensation(activeEmployee.id)
      // Merge after GET: cached compensation summaries may still be stale briefly; PATCH `assignment` is source of truth.
      if (assignment && typeof assignment === 'object') {
        setCompensationData((prev) => mergeSchedulePatchIntoCompensationData(prev, activeEmployee.id, assignment))
      }
      window.dispatchEvent(new CustomEvent('hr:employee-compensation-changed'))
    } catch (error) {
      toast({
        title: 'Schedule update failed',
        description: error.message || 'Failed to update pay component schedule',
        variant: 'destructive',
      })
    } finally {
      setUpdatingScheduleId(null)
    }
  }, [activeEmployee, debugSchedule, refreshCompensation, toast])

  async function saveEditedValue(item) {
    if (!activeEmployee || !item?.id) return
    if (updatingScheduleId === item.id) return
    const newValue = Number(editValue || 0)
    setUpdatingValue(true)
    try {
      const payload = { value: newValue }
      if (item.calculation_type === 'hourly') {
        payload.hourly_rate = newValue
      }
      await updateEmployeeCompensation(activeEmployee.id, item.id, payload)
      const formatted = `₱${newValue.toLocaleString('en-PH', { minimumFractionDigits: 2 })}`
      const calc = item.calculation_type
      const label = calc === 'percent_basic' || calc === 'percent_gross'
        ? `${newValue}%`
        : (calc === 'hourly' ? `${formatted}/hr` : formatted)
      toast({ title: 'Compensation updated', description: `${item.name} value updated to ${label}.` })
      setEditingId(null)
      setEditValue('')
      await refreshCompensation()
      window.dispatchEvent(new CustomEvent('hr:employee-compensation-changed'))
    } catch (error) {
      toast({
        title: 'Update failed',
        description: error.message || 'Failed to update compensation value',
        variant: 'destructive',
      })
    } finally {
      setUpdatingValue(false)
    }
  }

  const pendingForActive = pendingAssignments.filter((item) => item.employeeId === activeEmployeeId)

  return (
    <div className="w-full min-w-0 max-w-none space-y-4 bg-background px-3 py-4 text-foreground sm:space-y-5 sm:px-4 md:px-5 lg:space-y-6 lg:px-6 lg:py-5 3xl:space-y-8 3xl:px-10 3xl:py-6">
      <section className="rounded-[1.75rem] border border-border/70 bg-card p-6 shadow-sm dark:shadow-[0_14px_45px_rgba(0,0,0,0.28)]">
        <div className="flex flex-col gap-4 lg:flex-row lg:items-start lg:justify-between">
          <div>
            <div className="inline-flex items-center gap-2 rounded-full bg-brand/10 px-3 py-1 text-xs font-semibold text-brand">
              <Wallet className="size-3.5" />
              Compensation
            </div>
            <h1 className="hr-page-title mt-3 text-foreground">Employee Compensation</h1>
            <p className="mt-2 max-w-2xl text-sm text-muted-foreground">
              Select employees, review their assigned compensation, and queue updates before saving.
            </p>
          </div>

          <div className="grid gap-3 sm:grid-cols-3">
            <SimpleStat label="Selected" value={activeEmployee ? 1 : 0} />
            <SimpleStat label="Pending" value={pendingAssignments.length} />
            <SimpleStat label="Effective Date" value={effectiveFrom || '—'} compact />
          </div>
        </div>
      </section>

      {pendingAssignments.length > 0 ? (
        <section className="rounded-[1.25rem] border border-amber-300/45 bg-amber-500/10 p-4 text-foreground">
          <div className="flex flex-col gap-3 lg:flex-row lg:items-center lg:justify-between">
            <div>
              <p className="text-sm font-semibold text-foreground">Pending Changes</p>
              <p className="mt-1 text-sm text-muted-foreground">
                {pendingAssignments.length} item{pendingAssignments.length === 1 ? '' : 's'} queued across {new Set(pendingAssignments.map((item) => item.employeeId)).size} employee{new Set(pendingAssignments.map((item) => item.employeeId)).size === 1 ? '' : 's'}.
              </p>
            </div>
            <div className="flex gap-2">
              <Button type="button" variant="outline" className="rounded-xl" onClick={() => setPendingAssignments([])}>
                Clear
              </Button>
              <Button type="button" className="rounded-xl bg-brand text-brand-foreground shadow-lg shadow-brand/20 hover:bg-brand-strong" onClick={savePendingAssignments} disabled={saving}>
                <Save className="mr-2 size-4" />
                {saving ? 'Saving...' : 'Save Changes'}
              </Button>
            </div>
          </div>
        </section>
      ) : null}

      <div className="grid gap-6 lg:grid-cols-[300px_minmax(0,1fr)]">
        <aside className="rounded-[1.5rem] border border-border/70 bg-card p-4 shadow-sm dark:shadow-[0_14px_45px_rgba(0,0,0,0.22)]">
          <div className="flex items-center justify-between gap-3">
            <div>
              <h2 className="text-lg font-semibold text-foreground">Employees</h2>
              <p className="text-sm text-muted-foreground">Search and choose one</p>
            </div>
            <Badge className="rounded-full bg-brand/10 text-brand hover:bg-brand/10">{activeEmployee ? 1 : 0}</Badge>
          </div>

          <label className="relative mt-4 block">
            <Search className="pointer-events-none absolute left-3 top-1/2 size-4 -translate-y-1/2 text-muted-foreground" />
            <input
              value={employeeSearch}
              onChange={(e) => setEmployeeSearch(e.target.value)}
              className={`${inputClass} pl-10`}
              placeholder="Search employee"
            />
          </label>

          <div className="mt-4 max-h-[720px] space-y-2 overflow-y-auto pr-1">
            {loading ? (
              Array.from({ length: 8 }).map((_, index) => (
                <div key={index} className="h-20 animate-pulse rounded-xl bg-muted" />
              ))
            ) : employees.length === 0 ? (
              <div className="rounded-xl border border-dashed border-border px-4 py-10 text-center text-sm text-muted-foreground">
                No employees matched your search.
              </div>
            ) : (
              employees.map((employee) => {
                const active = activeEmployeeId === employee.id
                const photo = employeeAvatarSrc(employee)
                const fallbackClass = getEmployeeAvatarColorClass(employee.id, employee.name)
                return (
                  <button
                    key={employee.id}
                    type="button"
                    onClick={() => selectEmployee(employee)}
                    className={`w-full rounded-xl border px-3 py-3 text-left transition ${
                      active
                        ? 'border-brand/70 bg-brand/10 shadow-sm ring-2 ring-brand/10'
                        : 'border-border/70 bg-background/65 hover:border-brand/40 hover:bg-brand/5'
                    }`}
                  >
                    <div className="flex items-center gap-3">
                      <Avatar className="size-11 border border-border/70">
                        <AvatarImage src={photo || undefined} alt={employee.name} className="object-cover" />
                        <AvatarFallback className={cn('font-semibold', fallbackClass)}>
                          {initials(employee.name)}
                        </AvatarFallback>
                      </Avatar>
                      <div className="min-w-0 flex-1">
                        <p className="truncate text-sm font-semibold text-foreground">{employee.name}</p>
                        <p className="mt-1 truncate text-xs text-muted-foreground">
                          {employee.position || 'No position'}
                        </p>
                        <p className="mt-1 truncate text-[11px] text-muted-foreground">
                          {employee.employee_code || 'No code'}
                        </p>
                      </div>
                      {active ? (
                        <span className="flex size-6 shrink-0 items-center justify-center rounded-full bg-brand text-brand-foreground shadow-sm">
                          <CheckCircle2 className="size-4" aria-hidden />
                        </span>
                      ) : null}
                    </div>
                  </button>
                )
              })
            )}
          </div>
        </aside>

        <main className="space-y-6">
          {activeEmployee ? (
            <>
              <section className="rounded-[1.5rem] border border-border/70 bg-card p-6 shadow-sm dark:shadow-[0_14px_45px_rgba(0,0,0,0.22)]">
                <div className="flex flex-col gap-5 xl:flex-row xl:items-start xl:justify-between">
                  <div className="flex items-center gap-4">
                    <Avatar className="size-16 border border-border/70">
                      <AvatarImage src={activeEmployeePhoto || undefined} alt={activeEmployee.name} className="object-cover" />
                      <AvatarFallback className={cn('text-lg font-bold', activeEmployeeFallbackClass)}>
                        {initials(activeEmployee.name)}
                      </AvatarFallback>
                    </Avatar>
                    <div className="min-w-0">
                      <div className="flex flex-wrap items-center gap-2">
                        <h2 className="text-2xl font-semibold text-foreground">{activeEmployee.name}</h2>
                        <Badge className="rounded-full bg-muted text-muted-foreground hover:bg-muted">{activeEmployee.employee_code || 'No code'}</Badge>
                      </div>
                      <p className="mt-1 text-sm text-muted-foreground">{activeEmployee.position || 'No position assigned'}</p>
                      <div className="mt-2 flex items-center gap-2 text-sm text-muted-foreground">
                        <Building2 className="size-4" />
                        <span>{activeEmployee.department || 'No department info'}</span>
                      </div>
                    </div>
                  </div>

                  <div className="grid gap-3 sm:grid-cols-2">
                    <SummaryCard label="Basic Salary" value={summary.basic_salary} />
                    <SummaryCard label="Gross Pay" value={gross} />
                  </div>
                </div>

                <div className="mt-6 flex items-center justify-between gap-3">
                  <div className="flex flex-wrap items-center gap-2">
                    <Button
                      type="button"
                      variant="outline"
                      className="rounded-xl"
                      onClick={handleRefreshPreview}
                      disabled={loading}
                    >
                      <RefreshCw className="mr-2 size-4" />
                      Refresh Preview
                    </Button>
                    {summaryPending ? (
                      <span className="text-xs text-muted-foreground">
                        Preview is warming up. Click Refresh if values still show ₱0.00.
                      </span>
                    ) : null}
                  </div>
                  <div className="flex flex-wrap items-center justify-end gap-2">
                    <Button
                      type="button"
                      variant="outline"
                      className="rounded-xl"
                      onClick={() => navigate(hrPanelPath(hrBase, `employees/${activeEmployee.id}`))}
                    >
                      <Eye className="mr-2 size-4" />
                      View Profile
                    </Button>
                    <Button type="button" onClick={openNewAssignmentDialog} className="rounded-xl bg-brand text-brand-foreground shadow-lg shadow-brand/20 hover:bg-brand-strong">
                      <CirclePlus className="mr-2 size-4" />
                      Add Component
                    </Button>
                  </div>
                </div>
              </section>

              {pendingForActive.length > 0 ? (
                <section className="rounded-[1.25rem] border border-border/70 bg-card p-4 shadow-sm">
                  <p className="text-sm font-semibold text-foreground">Pending for {activeEmployee.name}</p>
                  <div className="mt-3 flex flex-wrap gap-2">
                    {pendingForActive.map((item, index) => (
                      <Badge key={`${item.code}-${index}`} className="rounded-full bg-brand/10 text-brand hover:bg-brand/10">
                        {item.name}
                      </Badge>
                    ))}
                  </div>
                </section>
              ) : null}

              <section className="rounded-[1.5rem] border border-border/70 bg-card p-5 shadow-sm dark:shadow-[0_14px_45px_rgba(0,0,0,0.22)]">
                <Tabs defaultValue="earnings" className="w-full">
                  <div className="flex flex-col gap-3 border-b border-border/70 pb-4 sm:flex-row sm:items-center sm:justify-between">
                    <div>
                      <h3 className="text-lg font-semibold text-foreground">Compensation Components</h3>
                      <p className="mt-1 text-sm text-muted-foreground">Review earnings and deductions for the selected employee.</p>
                    </div>
                    <TabsList className="h-auto rounded-xl bg-muted p-1">
                      <TabsTrigger value="earnings" className="rounded-lg px-4 py-2 text-sm font-medium data-[state=active]:bg-card data-[state=active]:text-brand">
                        Earnings
                      </TabsTrigger>
                      <TabsTrigger value="deductions" className="rounded-lg px-4 py-2 text-sm font-medium data-[state=active]:bg-card data-[state=active]:text-brand">
                        Deductions
                      </TabsTrigger>
                    </TabsList>
                  </div>

                  <TabsContent value="earnings" className="mt-5">
                    <CompTable
                      items={earnings}
                      emptyLabel="No earning components assigned yet."
                      amountTone="earning"
                      onRemove={(assignment) => requestRemoveAssignment(activeEmployee.id, assignment)}
                      editingId={editingId}
                      editValue={editValue}
                      onEditStart={startEditing}
                      onEditChange={setEditValue}
                      onEditSave={saveEditedValue}
                      onEditCancel={cancelEditing}
                      updatingValue={updatingValue}
                      updatingScheduleId={updatingScheduleId}
                      onScheduleChange={saveScheduleOverride}
                    />
                  </TabsContent>

                  <TabsContent value="deductions" className="mt-5">
                    <CompTable
                      items={deductions}
                      emptyLabel="No deduction components assigned yet."
                      amountTone="deduction"
                      onRemove={(assignment) => requestRemoveAssignment(activeEmployee.id, assignment)}
                      editingId={editingId}
                      editValue={editValue}
                      onEditStart={startEditing}
                      onEditChange={setEditValue}
                      onEditSave={saveEditedValue}
                      onEditCancel={cancelEditing}
                      updatingValue={updatingValue}
                      updatingScheduleId={updatingScheduleId}
                      onScheduleChange={saveScheduleOverride}
                    />
                  </TabsContent>
                </Tabs>
              </section>
            </>
          ) : (
            <section className="rounded-[1.5rem] border border-dashed border-border bg-card px-6 py-16 text-center shadow-sm">
              <UserCircle2 className="mx-auto size-12 text-muted-foreground/50" />
              <h2 className="mt-4 text-xl font-semibold text-foreground">Select an employee</h2>
              <p className="mt-2 text-sm text-muted-foreground">
                Choose someone from the list to review and update compensation.
              </p>
            </section>
          )}
        </main>
      </div>

      <Dialog
        open={dialogOpen}
        onOpenChange={(open) => {
          setDialogOpen(open)
          if (!open) setDraftForm(EMPTY_FORM)
        }}
      >
        <DialogContent
          overlayClassName="bg-black/60 backdrop-blur-[3px]"
          innerClassName="overflow-hidden p-0"
          closeButtonClassName="right-4 top-4 size-10 rounded-xl border-border/70 bg-background/95 text-foreground shadow-sm hover:bg-muted sm:right-6 sm:top-6 sm:size-11"
          className="max-h-[88vh] !w-[94vw] !max-w-3xl overflow-hidden rounded-[1.25rem] border border-border/70 bg-card p-0 text-card-foreground shadow-2xl sm:rounded-[1.5rem]"
        >
          <div className="border-b border-border/60 bg-card px-5 py-5 pr-16 sm:px-8 sm:py-6 sm:pr-24">
            <DialogHeader>
              <div className="flex gap-4">
                <div className="flex size-11 shrink-0 items-center justify-center rounded-xl bg-brand/10 text-brand sm:size-12 sm:bg-transparent">
                  <UsersRound className="size-7 fill-brand/20 stroke-[2.2] sm:size-8" aria-hidden />
                </div>
                <div>
                  <DialogTitle className="text-xl font-bold tracking-tight text-foreground sm:text-2xl">
                    Add Component to {activeEmployee?.name || 'Employee'}
                  </DialogTitle>
                  <DialogDescription className="mt-2 max-w-2xl text-sm leading-relaxed text-muted-foreground sm:text-base">
                    Select a pay component from the catalog, then set the employee-specific amount.
                  </DialogDescription>
                </div>
              </div>
            </DialogHeader>
          </div>

          <form onSubmit={addPendingAssignment} className="flex min-h-0 flex-1 flex-col">
            <div className="min-h-0 flex-1 space-y-5 overflow-y-auto px-5 py-5 sm:px-8 sm:py-6">
              <Field label="Pay Component" required>
                <div className="relative">
                  <select
                    value={draftForm.pay_component_id}
                    onChange={(e) => applyMasterComponent(e.target.value)}
                    className={componentModalSelectClass}
                    required
                  >
                    <option value="" disabled>Select a pay component</option>
                    {components.map((component) => (
                      <option key={component.id} value={component.id}>
                        {component.name} ({component.code})
                      </option>
                    ))}
                  </select>
                  <ChevronDown className="pointer-events-none absolute right-5 top-1/2 size-5 -translate-y-1/2 text-foreground" aria-hidden />
                </div>
              </Field>

              {draftForm.pay_component_id ? (
                <ComponentDefinitionStrip draftForm={draftForm} />
              ) : null}

              {draftForm.calculation_type === 'fixed_amount' ? (
                <Field label="Amount (₱)" required>
                  <CurrencyInput
                    value={draftForm.value}
                    onChange={(value) => setDraftForm((prev) => ({ ...prev, value }))}
                    required
                  />
                </Field>
              ) : null}

              {draftForm.calculation_type === 'percent_basic' || draftForm.calculation_type === 'percent_gross' ? (
                <Field label={draftForm.calculation_type === 'percent_basic' ? 'Percentage of Basic (%)' : 'Percentage of Gross (%)'} required>
                  <input
                    value={draftForm.value}
                    onChange={(e) => setDraftForm((prev) => ({ ...prev, value: e.target.value }))}
                    className={componentModalInputClass}
                    inputMode="decimal"
                    placeholder="e.g. 5"
                    required
                  />
                </Field>
              ) : null}

              {draftForm.calculation_type === 'hourly' ? (
                <div className="grid gap-3 sm:grid-cols-2">
                  <Field label="Hourly Rate (₱)" required>
                    <CurrencyInput
                      value={draftForm.hourly_rate}
                      onChange={(value) => setDraftForm((prev) => ({ ...prev, hourly_rate: value, value }))}
                      required
                    />
                  </Field>
                  <Field label="Hours per Pay Period" required>
                    <input
                      value={draftForm.hours}
                      onChange={(e) => setDraftForm((prev) => ({ ...prev, hours: e.target.value }))}
                      className={componentModalInputClass}
                      inputMode="decimal"
                      placeholder="e.g. 40"
                      required
                    />
                  </Field>
                </div>
              ) : null}

              {draftForm.calculation_type === 'daily_rate' ? (
                <div className="grid gap-3 sm:grid-cols-2">
                  <Field label="Daily Rate (₱)" required>
                    <CurrencyInput
                      value={draftForm.value}
                      onChange={(value) => setDraftForm((prev) => ({ ...prev, value }))}
                      required
                    />
                  </Field>
                  <Field label="Days per Pay Period">
                    <input
                      value={draftForm.hours}
                      onChange={(e) => setDraftForm((prev) => ({ ...prev, hours: e.target.value }))}
                      className={componentModalInputClass}
                      inputMode="decimal"
                      placeholder="e.g. 22"
                    />
                  </Field>
                </div>
              ) : null}

              {draftForm.calculation_type === 'formula' ? (
                <>
                  <Field label="Default value (DEFAULT_VALUE token)">
                    <CurrencyInput
                      value={draftForm.value}
                      onChange={(value) => setDraftForm((prev) => ({ ...prev, value }))}
                    />
                  </Field>
                  <Field label="Formula">
                    <input
                      value={draftForm.formula}
                      onChange={(e) => setDraftForm((prev) => ({ ...prev, formula: e.target.value }))}
                      className={`${componentModalInputClass} font-mono text-sm`}
                      placeholder="(BASIC * 0.05) + DEFAULT_VALUE"
                    />
                  </Field>
                </>
              ) : null}

              {draftForm.type === 'earning' || draftForm.type === 'deduction' ? (
                <Field label="Pay Component Schedule" required>
                  <div className="relative">
                    <select
                      value={draftForm.schedule_override}
                      onChange={(e) => {
                        const value = e.target.value
                        debugSchedule('employee_compensation.schedule_selected', {
                          schedule_override: value,
                          employee_id: activeEmployee?.id,
                        })
                        setDraftForm((prev) => ({ ...prev, schedule_override: value }))
                      }}
                      className={componentModalSelectClass}
                    >
                      <option value="default">
                        Default — from Deduction Schedule Settings (this allowance)
                      </option>
                      {PAY_COMPONENT_SCHEDULE_OPTIONS.filter((o) => o.value !== 'default').map((opt) => (
                        <option key={opt.value} value={opt.value}>
                          {opt.label}
                        </option>
                      ))}
                    </select>
                    <ChevronDown className="pointer-events-none absolute right-5 top-1/2 size-5 -translate-y-1/2 text-foreground" aria-hidden />
                  </div>
                  <p className="mt-3 max-w-2xl text-sm leading-relaxed text-muted-foreground">
                    Default follows Compensation → Deduction Schedule Settings for the selected pay component. Other options override that schedule for this employee only.
                  </p>
                </Field>
              ) : null}

              <div className="rounded-2xl border border-brand/25 bg-brand/5 px-4 py-4 text-foreground dark:bg-brand/10">
                <div className="flex gap-3">
                  <div className="flex size-8 shrink-0 items-center justify-center rounded-full border-2 border-brand text-brand">
                    <Info className="size-4" aria-hidden />
                  </div>
                  <p className="text-sm leading-relaxed">
                    Component definition (calculation type, taxability, contributions) is set in the Pay Components catalog. Use this dialog to capture the employee-specific amount or rate.
                  </p>
                </div>
              </div>
            </div>

            <DialogFooter className="border-t border-border/60 bg-card px-5 py-4 sm:px-8">
              <Button type="button" variant="outline" className="h-11 w-full rounded-xl px-6 text-sm sm:w-auto" onClick={() => setDialogOpen(false)}>
                Cancel
              </Button>
              <Button type="submit" className="h-11 w-full rounded-xl bg-brand px-6 text-sm font-semibold text-brand-foreground shadow-lg shadow-brand/20 hover:bg-brand-strong sm:w-auto">
                <CirclePlus className="mr-2 size-4" />
                Add to Pending Changes
              </Button>
            </DialogFooter>
          </form>
        </DialogContent>
      </Dialog>

      <Dialog
        open={removeDialogOpen}
        onOpenChange={(open) => {
          if (!removing) {
            setRemoveDialogOpen(open)
            if (!open) setAssignmentToRemove(null)
          }
        }}
      >
        <DialogContent className="max-w-md rounded-2xl border-border/70">
          <DialogHeader>
            <DialogTitle className="text-lg font-semibold text-foreground">Remove compensation component</DialogTitle>
            <DialogDescription className="text-sm text-muted-foreground">
              Remove "{assignmentToRemove?.assignment?.name || 'this component'}" from {activeEmployee?.name || 'this employee'}?
            </DialogDescription>
          </DialogHeader>

          <div className="rounded-xl border border-border/70 bg-muted/35 px-4 py-3 text-sm text-muted-foreground">
            This action removes the assigned component from the employee compensation record.
          </div>

          <DialogFooter>
            <Button
              type="button"
              variant="outline"
              className="rounded-xl"
              onClick={() => {
                setRemoveDialogOpen(false)
                setAssignmentToRemove(null)
              }}
              disabled={removing}
            >
              Cancel
            </Button>
            <Button
              type="button"
              className="rounded-xl bg-rose-600 text-white hover:bg-rose-700"
              onClick={confirmRemoveAssignment}
              disabled={removing}
            >
              {removing ? 'Removing...' : 'Remove'}
            </Button>
          </DialogFooter>
        </DialogContent>
      </Dialog>
    </div>
  )
}

function SimpleStat({ label, value, compact = false }) {
  return (
    <div className="rounded-xl border border-border/70 bg-background/65 px-4 py-3 shadow-sm">
      <p className="text-xs font-medium uppercase tracking-wide text-muted-foreground">{label}</p>
      <p className={`mt-2 font-semibold text-foreground ${compact ? 'text-base' : 'text-2xl'}`}>{value}</p>
    </div>
  )
}

function SummaryCard({ label, value }) {
  return (
    <div className="rounded-xl border border-border/70 bg-background/65 px-4 py-3 shadow-sm">
      <p className="text-xs font-medium uppercase tracking-wide text-muted-foreground">{label}</p>
      <p className="mt-2 text-2xl font-semibold text-foreground">{formatPeso(value)}</p>
    </div>
  )
}

function CompTable({
  items,
  emptyLabel,
  amountTone,
  onRemove,
  editingId,
  editValue,
  onEditStart,
  onEditChange,
  onEditSave,
  onEditCancel,
  updatingValue,
  updatingScheduleId = null,
  onScheduleChange,
}) {
  return (
    <div className="overflow-x-auto">
      <Table className="min-w-[820px]">
        <TableHeader className="[&_tr]:border-b-0">
          <TableRow>
            <TableHead className="px-3 py-3 text-xs font-semibold uppercase tracking-wide text-muted-foreground">Component</TableHead>
            <TableHead className="px-3 py-3 text-xs font-semibold uppercase tracking-wide text-muted-foreground">Category</TableHead>
            <TableHead className="px-3 py-3 text-xs font-semibold uppercase tracking-wide text-muted-foreground">Calculation</TableHead>
            <TableHead className="min-w-48 px-3 py-3 text-xs font-semibold uppercase tracking-wide text-muted-foreground">
              Schedule
            </TableHead>
            <TableHead className="px-3 py-3 text-xs font-semibold uppercase tracking-wide text-muted-foreground">Taxability</TableHead>
            <TableHead className="px-3 py-3 text-xs font-semibold uppercase tracking-wide text-muted-foreground">Contributory</TableHead>
            <TableHead className="px-3 py-3 text-xs font-semibold uppercase tracking-wide text-muted-foreground">Amount</TableHead>
            <TableHead className="px-3 py-3 text-right text-xs font-semibold uppercase tracking-wide text-muted-foreground">Actions</TableHead>
          </TableRow>
        </TableHeader>
        <TableBody>
          {items.length === 0 ? (
            <TableRow>
              <TableCell colSpan={8} className="px-3 py-10 text-center text-sm text-muted-foreground">
                {emptyLabel}
              </TableCell>
            </TableRow>
          ) : (
            items.map((item) => {
              const isEditing = editingId === item.id
              return (
                <TableRow key={item.id} className="border-b border-border/60 transition hover:bg-muted/35">
                  <TableCell className="px-3 py-3.5">
                    <div className="font-medium text-foreground">{item.name}</div>
                    <div className="mt-1 flex flex-wrap items-center gap-2 text-xs text-muted-foreground">
                      <span>{item.code}{item.structure_name ? ` • ${item.structure_name}` : ''}</span>
                      <span className={`inline-flex rounded-full px-2 py-0.5 font-medium ${getAssignmentSourceStyles(item).className}`}>
                        {getAssignmentSourceStyles(item).label}
                      </span>
                    </div>
                  </TableCell>
                  <TableCell className="px-3 py-3.5 text-muted-foreground">{item.category || '—'}</TableCell>
                  <TableCell className="px-3 py-3.5">{formatCalculationType(item.calculation_type)}</TableCell>
                  <TableCell className="px-3 py-3.5">
                    {item.pay_component_id ? (
                      <select
                        aria-label={`Pay component schedule for ${item.name ?? 'component'}`}
                        className="max-w-[min(260px,calc(100vw-220px))] rounded-lg border border-border/70 bg-background px-2 py-1.5 text-xs text-foreground outline-none focus:border-brand/60 focus:ring-2 focus:ring-brand/15 disabled:opacity-60"
                        value={normalizedScheduleSelectValue(item.schedule_override)}
                        disabled={!item?.id || updatingScheduleId === item.id || (updatingValue && editingId === item.id)}
                        title={item?.id ? undefined : 'System line — edit schedule via employee assignment'}
                        onChange={(e) => onScheduleChange?.(item, e.target.value)}
                      >
                        {PAY_COMPONENT_SCHEDULE_OPTIONS.map((opt) => (
                          <option key={opt.value} value={opt.value}>
                            {opt.value === 'default' ? 'Use default (company settings)' : opt.label}
                          </option>
                        ))}
                      </select>
                    ) : (
                      <span className="text-xs text-muted-foreground/70">—</span>
                    )}
                    {item?.id && item.pay_component_id && updatingScheduleId === item.id ? (
                      <span className="mt-1 block text-[11px] text-muted-foreground">Saving…</span>
                    ) : null}
                  </TableCell>
                  <TableCell className="px-3 py-3.5">{item.is_taxable ? 'Taxable' : 'Non-taxable'}</TableCell>
                  <TableCell className="px-3 py-3.5">{describeContributions(item)}</TableCell>
                  <TableCell className="px-3 py-3.5">
                    {isEditing ? (
                      <div className="flex items-center gap-1.5">
                        <span className="text-sm text-muted-foreground">₱</span>
                        <input
                          autoFocus
                          value={editValue}
                          onChange={(e) => onEditChange(e.target.value)}
                          onKeyDown={(e) => {
                            if (e.key === 'Enter') onEditSave(item)
                            if (e.key === 'Escape') onEditCancel()
                          }}
                          className="w-28 rounded-lg border border-border bg-background px-2 py-1.5 text-sm text-foreground outline-none focus:border-brand/60 focus:ring-2 focus:ring-brand/15"
                          inputMode="decimal"
                          disabled={updatingValue || updatingScheduleId === item.id}
                        />
                      </div>
                    ) : (
                      <button
                        type="button"
                        onClick={() => item?.id && onEditStart(item)}
                        className={`group inline-flex items-center gap-1.5 rounded-lg px-2 py-1 transition ${item?.id ? 'cursor-pointer hover:bg-muted' : 'cursor-default'}`}
                        disabled={!item?.id || updatingScheduleId === item.id}
                        title={
                          !item?.id
                            ? undefined
                            : updatingScheduleId === item.id
                              ? 'Saving schedule…'
                              : 'Click to edit value'
                        }
                      >
                        <span className={amountTone === 'deduction' ? 'font-medium text-rose-600 dark:text-rose-300' : 'font-medium text-emerald-600 dark:text-emerald-300'}>
                          {formatPeso(item.computed_amount)}
                        </span>
                        {item?.id ? (
                          <Pencil className="size-3.5 text-muted-foreground opacity-0 transition group-hover:opacity-100" aria-hidden />
                        ) : null}
                      </button>
                    )}
                  </TableCell>
                  <TableCell className="px-3 py-3.5 text-right">
                    {isEditing ? (
                      <div className="flex items-center justify-end gap-1.5">
                        <Button
                          type="button"
                          variant="ghost"
                          size="sm"
                          className="rounded-lg px-3 text-muted-foreground hover:bg-muted"
                          onClick={onEditCancel}
                          disabled={updatingValue || updatingScheduleId === item.id}
                        >
                          Cancel
                        </Button>
                        <Button
                          type="button"
                          size="sm"
                          className="rounded-lg bg-brand px-3 text-brand-foreground hover:bg-brand-strong"
                          onClick={() => onEditSave(item)}
                          disabled={updatingValue || updatingScheduleId === item.id}
                        >
                          {updatingValue ? 'Saving...' : 'Save'}
                        </Button>
                      </div>
                    ) : item?.id ? (
                      <Button
                        type="button"
                        variant="ghost"
                        size="sm"
                        className="rounded-lg border-0 bg-rose-50 px-3 text-rose-600 hover:bg-rose-100 hover:text-rose-700"
                        onClick={() => onRemove(item)}
                        disabled={updatingScheduleId === item.id || updatingValue}
                      >
                        <Trash2 className="mr-2 size-4" />
                        Remove
                      </Button>
                    ) : (
                      <span className="inline-flex rounded-lg bg-muted px-3 py-2 text-xs font-medium text-muted-foreground">
                        System item
                      </span>
                    )}
                  </TableCell>
                </TableRow>
              )
            })
          )}
        </TableBody>
      </Table>
    </div>
  )
}

function ComponentDefinitionStrip({ draftForm }) {
  const items = [
    { label: 'Type', value: draftForm.type || '—', Icon: Wallet, valueClassName: 'capitalize' },
    { label: 'Category', value: draftForm.category || '—', Icon: BriefcaseBusiness },
    { label: 'Calculation', value: formatCalculationType(draftForm.calculation_type), Icon: Calculator },
  ]

  return (
    <div className="grid grid-cols-1 gap-4 rounded-2xl border border-brand/20 bg-brand/5 px-4 py-4 text-sm dark:bg-brand/10 sm:grid-cols-3">
      {items.map((item) => {
        const DefinitionIcon = item.Icon
        return (
          <div key={item.label} className="min-w-0">
            <p className="text-[11px] font-semibold uppercase tracking-wide text-muted-foreground">{item.label}</p>
            <div className="mt-3 flex items-center gap-3">
              <span className="flex size-8 shrink-0 items-center justify-center rounded-lg bg-brand/10 text-brand shadow-inner">
                <DefinitionIcon className="size-4" aria-hidden />
              </span>
              <p className={cn('truncate text-base font-semibold text-foreground', item.valueClassName)}>{item.value}</p>
            </div>
          </div>
        )
      })}
    </div>
  )
}

function CurrencyInput({ value, onChange, required = false }) {
  return (
    <div className="relative">
      <span className="pointer-events-none absolute left-4 top-1/2 -translate-y-1/2 text-base font-semibold text-foreground/85">₱</span>
      <input
        value={value}
        onChange={(e) => onChange?.(e.target.value)}
        className={cn(componentModalInputClass, 'pl-10')}
        inputMode="decimal"
        placeholder="0.00"
        required={required}
      />
    </div>
  )
}

function Field({ label, children, required = false }) {
  return (
    <label className="block text-base font-semibold text-foreground">
      <span className="mb-2.5 block">
        {label}
        {required ? <span className="ml-1 text-brand">*</span> : null}
      </span>
      {children}
    </label>
  )
}

function initials(name) {
  const parts = String(name || '').trim().split(/\s+/).filter(Boolean)
  if (parts.length === 0) return 'HR'
  const first = parts[0]?.[0] || ''
  const second = parts[1]?.[0] || first
  return `${first}${second}`.toUpperCase()
}

function describeContributions(item) {
  const values = []
  if (item.contributes_sss) values.push('SSS')
  if (item.contributes_philhealth) values.push('PhilHealth')
  if (item.contributes_pagibig) values.push('Pag-IBIG')
  return values.length > 0 ? values.join(', ') : 'None'
}

function formatCalculationType(value) {
  const map = {
    fixed_amount: 'Fixed Amount',
    percent_basic: '% of Basic',
    percent_gross: '% of Gross',
    daily_rate: 'Daily Rate',
    formula: 'Formula',
    hourly: 'Hourly',
  }
  return map[value] || value
}

function formatPeso(value) {
  return `₱${Number(value || 0).toLocaleString('en-PH', {
    minimumFractionDigits: 2,
    maximumFractionDigits: 2,
  })}`
}

function getAssignmentSourceStyles(item) {
  const source = item?.metadata?.assignment_source

  if (source === 'auto_apply_all') {
    return {
      label: 'Auto-applied',
      className: 'bg-indigo-500/10 text-indigo-700 dark:text-indigo-200',
    }
  }

  if (source === 'manual_override') {
    return {
      label: 'Manual override',
      className: 'bg-amber-500/10 text-amber-700 dark:text-amber-200',
    }
  }

  if (!item?.id) {
    return {
      label: 'System item',
      className: 'bg-muted text-muted-foreground',
    }
  }

  return {
    label: 'Manual',
    className: 'bg-emerald-500/10 text-emerald-700 dark:text-emerald-200',
  }
}

const inputClass = 'w-full rounded-xl border border-border/70 bg-background px-3 py-2.5 text-sm text-foreground shadow-sm outline-none transition placeholder:text-muted-foreground/75 focus:border-brand/60 focus:ring-4 focus:ring-brand/15 dark:bg-muted/10'
const componentModalInputClass = 'h-12 w-full min-w-0 rounded-xl border border-border/70 bg-background px-4 text-base font-medium text-foreground shadow-sm outline-none transition placeholder:text-muted-foreground/70 focus:border-brand focus:ring-4 focus:ring-brand/15 dark:bg-muted/10 sm:h-14'
const componentModalSelectClass = `${componentModalInputClass} appearance-none truncate border-brand pr-12 focus:border-brand`
