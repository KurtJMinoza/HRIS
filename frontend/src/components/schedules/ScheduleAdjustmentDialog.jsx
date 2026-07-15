import { useCallback, useEffect, useMemo, useRef, useState } from 'react'
import {
  AlertTriangle,
  BriefcaseBusiness,
  Building2,
  CalendarCheck,
  CalendarDays,
  Factory,
  Info,
  Loader2,
  MapPin,
  Network,
  Plus,
  Search,
  ShieldCheck,
  Trash2,
  UserRoundCog,
  Users,
} from 'lucide-react'
import { Dialog, DialogContent, DialogFooter, DialogHeader, DialogTitle } from '@/components/ui/dialog'
import { Button } from '@/components/ui/button'
import { Badge } from '@/components/ui/badge'
import { Input } from '@/components/ui/input'
import { Checkbox } from '@/components/ui/checkbox'
import { Label } from '@/components/ui/label'
import { Avatar, AvatarFallback, AvatarImage } from '@/components/ui/avatar'
import { FIELD_SELECT_CLASS_H10 } from '@/lib/fieldClasses'
import { applyScheduleAdjustment, getAreas, getBranches, getCompanies, getDepartments, getDivisions, getEmployees, getSectionsOrUnits, previewScheduleAdjustment, profileImageUrl } from '@/api'
import { createDefaultScheduleForm, buildWorkingSchedulePayload } from '@/lib/workScheduleForm'
import { formatShiftRange12h } from '@/lib/timeFormat'
import { SHIFT_TYPES, computePaidMinutes, detectCrossesMidnight, formatPaidHours, halfDayThresholdMinutes } from '@/lib/scheduleLib'
import { toast } from 'sonner'

const SCOPE_OPTIONS = [
  { value: 'employee', label: 'Specific Employees', icon: Users },
  { value: 'company', label: 'Selected Companies', icon: Building2 },
  { value: 'area', label: 'Selected Areas', icon: MapPin },
  { value: 'branch', label: 'Selected Branches', icon: Factory },
  { value: 'division', label: 'Selected Divisions', icon: Network },
  { value: 'department', label: 'Selected Departments', icon: BriefcaseBusiness },
  { value: 'section_unit', label: 'Selected Sections / Teams', icon: UserRoundCog },
]

const sectionPanelClass =
  'rounded-xl border border-border/70 bg-background/95 p-5 shadow-sm dark:border-white/10 dark:bg-background/35'
const sectionHeadingClass = 'text-sm font-black uppercase tracking-[0.16em] text-foreground'
const rowButtonClass =
  'grid w-full grid-cols-[auto_minmax(0,1fr)_auto] items-center gap-3 border-b border-border/60 px-3 py-2.5 text-left transition-colors last:border-b-0 hover:bg-muted/40 dark:border-white/10'

const todayYmd = () => new Date().toISOString().slice(0, 10)

const DAY_OPTIONS = [
  { key: 'mon', label: 'M', full: 'Monday' },
  { key: 'tue', label: 'T', full: 'Tuesday' },
  { key: 'wed', label: 'W', full: 'Wednesday' },
  { key: 'thu', label: 'Th', full: 'Thursday' },
  { key: 'fri', label: 'F', full: 'Friday' },
  { key: 'sat', label: 'S', full: 'Saturday' },
  { key: 'sun', label: 'Su', full: 'Sunday' },
]

function scopeTileClass(active) {
  return [
    'relative flex min-h-[6.05rem] cursor-pointer flex-col justify-between rounded-xl border bg-background px-4 py-3 text-left transition-all',
    'hover:border-brand/45 hover:bg-brand/5 dark:bg-card/35',
    active
      ? 'border-brand bg-brand/[0.04] text-foreground shadow-[0_10px_26px_-18px_rgba(255,104,27,0.65)] ring-1 ring-brand/35'
      : 'border-border/70 text-foreground shadow-sm dark:border-white/10',
  ].join(' ')
}

function miniCheckClass(active) {
  return [
    'grid size-4 place-items-center rounded-full border transition-colors',
    active ? 'border-brand bg-brand text-white' : 'border-border bg-background dark:border-white/20',
  ].join(' ')
}

function toggleRestDay(restDays, dayKey) {
  const current = new Set(restDays || [])
  if (current.has(dayKey)) current.delete(dayKey)
  else current.add(dayKey)
  return Array.from(current)
}

function initials(name) {
  return String(name || '?')
    .trim()
    .split(/\s+/)
    .map((part) => part[0])
    .join('')
    .toUpperCase()
    .slice(0, 2) || '?'
}

function optionLabel(item, scope) {
  if (scope === 'branch') return `${item.name || 'Branch'} - ${item.company_name || item.company?.name || 'Company'}`
  if (scope === 'department') return `${item.name || 'Department'}${item.branch_name ? ` - ${item.branch_name}` : ''}`
  return item.name || item.label || `#${item.id}`
}

export function ScheduleAdjustmentDialog({ open, onOpenChange, schedules = [], onApplied }) {
  const [scopeType, setScopeType] = useState('employee')
  const [scopeIds, setScopeIds] = useState([])
  const [employees, setEmployees] = useState([])
  const [orgOptions, setOrgOptions] = useState([])
  const [employeeSearch, setEmployeeSearch] = useState('')
  const [orgSearch, setOrgSearch] = useState('')
  const [selectedEmployeeIds, setSelectedEmployeeIds] = useState([])
  const [excludedEmployeeIds, setExcludedEmployeeIds] = useState([])
  const [scheduleSource, setScheduleSource] = useState('template')
  const [scheduleTemplateId, setScheduleTemplateId] = useState('')
  const [customForm, setCustomForm] = useState(createDefaultScheduleForm)
  const [effectiveStart, setEffectiveStart] = useState(todayYmd)
  const [endMode, setEndMode] = useState('open')
  const [effectiveEnd, setEffectiveEnd] = useState('')
  const [reason, setReason] = useState('')
  const [preview, setPreview] = useState(null)
  const [loadingOptions, setLoadingOptions] = useState(false)
  const [previewing, setPreviewing] = useState(false)
  const [submitting, setSubmitting] = useState(false)
  const [error, setError] = useState(null)
  const previewRequestRef = useRef(0)

  useEffect(() => {
    if (!open) return
    setError(null)
    setPreview(null)
    setExcludedEmployeeIds([])
    if (!scheduleTemplateId && schedules[0]?.id) setScheduleTemplateId(String(schedules[0].id))
  }, [open, scheduleTemplateId, schedules])

  useEffect(() => {
    if (!open) return
    let cancelled = false
    async function load() {
      setLoadingOptions(true)
      try {
        if (scopeType === 'employee') {
          const data = await getEmployees({ for_schedule_assignment: true, fresh: true })
          if (!cancelled) setEmployees(data.employees || [])
          return
        }
        const loaders = {
          company: getCompanies,
          area: getAreas,
          branch: getBranches,
          division: getDivisions,
          department: getDepartments,
          section_unit: getSectionsOrUnits,
        }
        const data = await loaders[scopeType]?.({ fresh: true, lite: true })
        if (cancelled) return
        setOrgOptions(data?.[`${scopeType}s`] || data?.sections_or_units || data?.areas || data?.branches || data?.companies || data?.departments || data?.divisions || [])
      } catch (e) {
        if (!cancelled) setError(e instanceof Error ? e.message : 'Failed to load options')
      } finally {
        if (!cancelled) setLoadingOptions(false)
      }
    }
    load()
    return () => { cancelled = true }
  }, [open, scopeType])

  const filteredEmployees = useMemo(() => {
    const q = employeeSearch.trim().toLowerCase()
    if (!q) return employees.slice(0, 120)
    return employees
      .filter((emp) => [emp.name, emp.employee_code, emp.username, emp.department, emp.company_name, emp.branch_name].filter(Boolean).some((v) => String(v).toLowerCase().includes(q)))
      .slice(0, 120)
  }, [employees, employeeSearch])

  const filteredOrgOptions = useMemo(() => {
    const q = orgSearch.trim().toLowerCase()
    if (!q) return orgOptions.slice(0, 160)
    return orgOptions
      .filter((item) => [optionLabel(item, scopeType), item.code, item.company_name, item.branch_name, item.department_name].filter(Boolean).some((v) => String(v).toLowerCase().includes(q)))
      .slice(0, 160)
  }, [orgOptions, orgSearch, scopeType])

  const activeScope = SCOPE_OPTIONS.find((scope) => scope.value === scopeType) || SCOPE_OPTIONS[0]

  const selectedSchedule = schedules.find((s) => String(s.id) === String(scheduleTemplateId))
  const affected = preview?.employees || []
  const hasReviewed = preview !== null
  const affectedCount = preview?.affected_count ?? null
  const finalAffectedCount = hasReviewed ? Math.max(0, Number(affectedCount || 0) - excludedEmployeeIds.length) : null
  const customShiftType = customForm.shift_type || 'fixed'
  const customIsSplit = customShiftType === 'split'
  const customIsFlexible = customShiftType === 'flexible'
  const customCrossesMidnight = detectCrossesMidnight(customForm.time_in, customForm.time_out) || customShiftType === 'overnight'
  const customPaidMinutes = computePaidMinutes({
    ...customForm,
    breaks: customForm.breaks || [],
    work_blocks: customForm.work_blocks || [],
    rest_days: customForm.rest_days || [],
  })
  const customHalfDayMinutes = halfDayThresholdMinutes({
    ...customForm,
    breaks: customForm.breaks || [],
    work_blocks: customForm.work_blocks || [],
    rest_days: customForm.rest_days || [],
  })

  function toggleValue(list, value) {
    return list.includes(value) ? list.filter((v) => v !== value) : [...list, value]
  }

  function basePayload() {
    return {
      scope_type: scopeType,
      scope_ids: scopeType === 'employee' ? [] : scopeIds.map(Number),
      employee_ids: scopeType === 'employee' ? selectedEmployeeIds.map(Number) : [],
      exclude_employee_ids: excludedEmployeeIds.map(Number),
    }
  }

  const previewPayload = useCallback(() => ({
    scope_type: scopeType,
    scope_ids: scopeType === 'employee' ? [] : scopeIds.map(Number),
    employee_ids: scopeType === 'employee' ? selectedEmployeeIds.map(Number) : [],
    exclude_employee_ids: [],
  }), [scopeIds, scopeType, selectedEmployeeIds])

  const hasPreviewSelection = scopeType === 'employee'
    ? selectedEmployeeIds.length > 0
    : scopeIds.length > 0

  const previewSelectionKey = useMemo(() => {
    const ids = scopeType === 'employee' ? selectedEmployeeIds : scopeIds
    return `${scopeType}:${ids.map(Number).sort((a, b) => a - b).join(',')}`
  }, [scopeIds, scopeType, selectedEmployeeIds])

  const loadPreview = useCallback(async ({ showErrors = false } = {}) => {
    if (scopeType === 'employee' && selectedEmployeeIds.length === 0) {
      setPreview(null)
      if (showErrors) setError('Select at least one employee before reviewing affected employees.')
      return
    }
    if (scopeType !== 'employee' && scopeIds.length === 0) {
      setPreview(null)
      if (showErrors) setError(`Select at least one ${activeScope.label.toLowerCase()} before reviewing affected employees.`)
      return
    }

    const requestId = previewRequestRef.current + 1
    previewRequestRef.current = requestId
    setPreviewing(true)
    setError(null)
    try {
      const data = await previewScheduleAdjustment(previewPayload())
      if (previewRequestRef.current === requestId) {
        setPreview(data)
      }
    } catch (e) {
      if (previewRequestRef.current === requestId) {
        setPreview(null)
        setError(e instanceof Error ? e.message : 'Failed to preview adjustment')
      }
    } finally {
      if (previewRequestRef.current === requestId) {
        setPreviewing(false)
      }
    }
  }, [activeScope.label, previewPayload, scopeIds.length, scopeType, selectedEmployeeIds.length])

  useEffect(() => {
    if (!open) return
    setPreview(null)
    setExcludedEmployeeIds([])
    if (!hasPreviewSelection) {
      previewRequestRef.current += 1
      setPreviewing(false)
      return
    }

    const timer = window.setTimeout(() => {
      void loadPreview({ showErrors: false })
    }, 250)

    return () => window.clearTimeout(timer)
  }, [hasPreviewSelection, loadPreview, open, previewSelectionKey])

  function addCustomBreak() {
    setCustomForm((form) => ({
      ...form,
      breaks: [...(form.breaks || []), { start: '', end: '', is_paid: false }],
    }))
  }

  function updateCustomBreak(index, field, value) {
    setCustomForm((form) => {
      const breaks = [...(form.breaks || [])]
      breaks[index] = { ...breaks[index], [field]: value }
      return { ...form, breaks }
    })
  }

  function removeCustomBreak(index) {
    setCustomForm((form) => ({
      ...form,
      breaks: (form.breaks || []).filter((_, i) => i !== index),
    }))
  }

  function addCustomWorkBlock() {
    setCustomForm((form) => ({
      ...form,
      work_blocks: [...(form.work_blocks || []), { start: '', end: '' }],
    }))
  }

  function updateCustomWorkBlock(index, field, value) {
    setCustomForm((form) => {
      const work_blocks = [...(form.work_blocks || [])]
      work_blocks[index] = { ...work_blocks[index], [field]: value }
      return { ...form, work_blocks }
    })
  }

  function removeCustomWorkBlock(index) {
    setCustomForm((form) => ({
      ...form,
      work_blocks: (form.work_blocks || []).filter((_, i) => i !== index),
    }))
  }

  async function handlePreview() {
    await loadPreview({ showErrors: true })
  }

  async function submit(saveAsDraft = false) {
    if (!reason.trim()) {
      setError('Adjustment reason is required.')
      return
    }
    if (scheduleSource === 'template' && !scheduleTemplateId) {
      setError('Select a schedule template.')
      return
    }
    setSubmitting(true)
    setError(null)
    try {
      const payload = {
        ...basePayload(),
        schedule_source: scheduleSource,
        schedule_template_id: scheduleSource === 'template' ? Number(scheduleTemplateId) : null,
        custom_schedule: scheduleSource === 'custom' ? buildWorkingSchedulePayload(customForm) : null,
        effective_start_date: effectiveStart,
        effective_end_date: endMode === 'specific' ? effectiveEnd : null,
        adjustment_reason: reason.trim(),
        replace_overlaps: true,
        save_as_draft: saveAsDraft,
      }
      const data = await applyScheduleAdjustment(payload)
      toast.success(saveAsDraft ? 'Draft saved' : 'Schedule adjustment applied', {
        description: data.message || `${finalAffectedCount ?? 0} employee(s) affected.`,
      })
      onApplied?.()
      onOpenChange(false)
    } catch (e) {
      const failed = e.failed?.length ? ` ${e.failed[0].reason}` : ''
      setError(`${e instanceof Error ? e.message : 'Failed to save adjustment'}${failed}`)
    } finally {
      setSubmitting(false)
    }
  }

  return (
    <Dialog open={open} onOpenChange={onOpenChange}>
      <DialogContent
        showCloseButton
        surfaceStyle={{ width: 'min(94vw, 1360px)', maxWidth: 'min(94vw, 1360px)' }}
        overlayClassName="bg-black/55 backdrop-blur-sm dark:bg-black/70"
        closeButtonClassName="right-5 top-5 size-10 rounded-xl border-border/80 bg-background/90 text-foreground shadow-sm hover:bg-muted dark:border-white/10 dark:bg-card/90"
        className="h-[min(92vh,calc(100dvh-1.5rem))] max-w-none! overflow-hidden rounded-xl border-border/80 bg-card p-0 shadow-[0_24px_80px_-24px_rgba(0,0,0,0.5)] dark:border-white/10"
        innerClassName="gap-0 overflow-hidden p-0! pr-0!"
      >
        <DialogHeader className="shrink-0 bg-card px-6 py-6 pr-20 dark:border-white/10">
          <div className="flex items-center gap-4">
            <span className="flex size-16 shrink-0 items-center justify-center rounded-[1.35rem] bg-brand/10 text-brand ring-1 ring-brand/15">
              <CalendarCheck className="size-5" />
            </span>
            <div className="min-w-0">
              <DialogTitle className="text-2xl font-black tracking-tight">
                Add Schedule Adjustment
              </DialogTitle>
              <p className="mt-2 max-w-4xl text-base leading-relaxed text-muted-foreground">
                Assign a new shift, workweek, or rest-day arrangement without changing historical schedules.
              </p>
            </div>
          </div>
        </DialogHeader>

        <div className="min-h-0 flex-1 overflow-hidden">
          {error ? (
            <div className="mx-6 mt-5 flex items-start gap-2 rounded-lg border border-destructive/30 bg-destructive/10 px-4 py-3 text-sm text-destructive">
              <AlertTriangle className="mt-0.5 size-4" />
              <span>{error}</span>
            </div>
          ) : null}

          <div className="grid h-full min-h-0 grid-cols-1 gap-5 overflow-hidden px-6 pb-5 xl:grid-cols-[minmax(0,1.6fr)_minmax(360px,0.9fr)]">
            <section className="min-h-0 space-y-5 overflow-y-auto pr-1">
              <div className={sectionPanelClass}>
                <h3 className={sectionHeadingClass}>Adjustment Scope</h3>
                <div className="mt-5 grid gap-4 sm:grid-cols-2 lg:grid-cols-3 2xl:grid-cols-4">
                  {SCOPE_OPTIONS.map((scope) => {
                    const Icon = scope.icon
                    const active = scopeType === scope.value
                    return (
                      <button
                        key={scope.value}
                        type="button"
                        className={scopeTileClass(active)}
                        onClick={() => {
                          setScopeType(scope.value)
                          setScopeIds([])
                          setSelectedEmployeeIds([])
                          setOrgSearch('')
                          setPreview(null)
                        }}
                      >
                        <span className="flex items-center gap-4">
                          <span className={miniCheckClass(active)}>
                            {active ? <span className="size-1.5 rounded-full bg-white" /> : null}
                          </span>
                          <span className={`grid size-9 place-items-center rounded-lg ${active ? 'bg-brand/10 text-brand' : 'bg-muted text-foreground'}`}>
                            <Icon className="size-5" />
                          </span>
                        </span>
                        <span className="mt-4 block min-w-0 text-sm font-black leading-snug">{scope.label}</span>
                      </button>
                    )
                  })}
                </div>

              {scopeType === 'employee' ? (
                <div className="mt-6 border-t border-border/70 pt-5 dark:border-white/10">
                  <div className="mb-3 flex flex-wrap items-center justify-between gap-3">
                    <h3 className={sectionHeadingClass}>Specific Employees</h3>
                    <Badge variant="outline">{selectedEmployeeIds.length} selected</Badge>
                  </div>
                  <div className="relative">
                    <Search className="pointer-events-none absolute left-3 top-1/2 size-4 -translate-y-1/2 text-muted-foreground" />
                    <Input className="pl-9" value={employeeSearch} onChange={(e) => setEmployeeSearch(e.target.value)} placeholder="Search employee name or employee number" />
                  </div>
                  <div className="mt-3 max-h-[22rem] overflow-y-auto rounded-lg border border-border/70 dark:border-white/10">
                    {loadingOptions ? <div className="p-5 text-sm text-muted-foreground">Loading employees...</div> : filteredEmployees.map((emp) => {
                      const checked = selectedEmployeeIds.includes(Number(emp.id))
                      return (
                        <button
                          key={emp.id}
                          type="button"
                          className={rowButtonClass}
                          onClick={() => {
                            setSelectedEmployeeIds((ids) => toggleValue(ids, Number(emp.id)))
                            setPreview(null)
                          }}
                        >
                          <Avatar className="size-9"><AvatarImage src={profileImageUrl(emp.profile_image)} /><AvatarFallback>{initials(emp.name)}</AvatarFallback></Avatar>
                          <span className="min-w-0">
                            <span className="block truncate text-sm font-semibold">{emp.name}</span>
                            <span className="block truncate text-xs text-muted-foreground">{emp.employee_code || emp.username || `#${emp.id}`} - {emp.company_name || emp.company || 'Company'} - {emp.branch_name || emp.branch || 'Branch'} - {emp.department || 'Department'}</span>
                          </span>
                          <Checkbox checked={checked} />
                        </button>
                      )
                    })}
                  </div>
                </div>
              ) : (
                <div className="mt-6 border-t border-border/70 pt-5 dark:border-white/10">
                  <div className="mb-4 flex flex-wrap items-center justify-between gap-3">
                    <h3 className={sectionHeadingClass}>Organization Scope</h3>
                    <Badge className="border-brand/30 bg-brand/10 text-brand hover:bg-brand/10" variant="outline">{scopeIds.length} selected</Badge>
                  </div>
                  <div className="relative">
                    <Search className="pointer-events-none absolute right-3 top-1/2 size-4 -translate-y-1/2 text-muted-foreground" />
                    <Input
                      className="h-11 pr-9"
                      value={orgSearch}
                      onChange={(e) => setOrgSearch(e.target.value)}
                      placeholder={`Search ${activeScope.label.toLowerCase()}...`}
                    />
                  </div>
                  <div className="mt-3 max-h-[21rem] overflow-y-auto rounded-xl border border-border/70 bg-background dark:border-white/10">
                    {loadingOptions ? (
                      <div className="p-5 text-sm text-muted-foreground">Loading options...</div>
                    ) : filteredOrgOptions.length === 0 ? (
                      <div className="p-5 text-sm text-muted-foreground">No matching options found.</div>
                    ) : filteredOrgOptions.map((item) => {
                      const checked = scopeIds.includes(Number(item.id))
                      return (
                        <button
                          key={item.id}
                          type="button"
                          className={[
                            'grid w-full grid-cols-[auto_minmax(0,1fr)] items-center gap-3 border-b border-border/60 px-4 py-3 text-left text-sm transition-colors last:border-b-0 dark:border-white/10',
                            checked ? 'bg-brand/[0.08] text-foreground' : 'hover:bg-muted/40',
                          ].join(' ')}
                          onClick={() => {
                            setScopeIds((ids) => toggleValue(ids, Number(item.id)))
                            setPreview(null)
                          }}
                        >
                          <Checkbox checked={checked} className="size-5 rounded-md" />
                          <span className="min-w-0 truncate font-semibold uppercase tracking-wide">{optionLabel(item, scopeType)}</span>
                        </button>
                      )
                    })}
                  </div>
                  <p className="mt-3 text-xs text-muted-foreground">Hold Ctrl or Shift to select multiple {activeScope.label.toLowerCase()}.</p>
                </div>
              )}
              </div>

              <div className={sectionPanelClass}>
                <h3 className={sectionHeadingClass}>Schedule Source</h3>
                <div className="mt-3 grid gap-4 lg:grid-cols-2">
                  <label className="space-y-1.5 text-sm font-semibold">
                    Source
                    <select className={FIELD_SELECT_CLASS_H10} value={scheduleSource} onChange={(e) => setScheduleSource(e.target.value)}>
                      <option value="template">Use Existing Schedule Template</option>
                      <option value="custom">Create Custom Schedule for This Adjustment</option>
                    </select>
                  </label>
                  {scheduleSource === 'template' ? (
                    <label className="space-y-1.5 text-sm font-semibold">
                      Active Schedule
                      <select className={FIELD_SELECT_CLASS_H10} value={scheduleTemplateId} onChange={(e) => setScheduleTemplateId(e.target.value)}>
                        {schedules.map((schedule) => <option key={schedule.id} value={String(schedule.id)}>{schedule.name} ({formatShiftRange12h(schedule.time_in, schedule.time_out, ' - ')})</option>)}
                      </select>
                    </label>
                  ) : null}
                </div>

                {scheduleSource === 'custom' ? (
                  <div className="mt-5 space-y-5 rounded-lg border border-border/60 bg-muted/20 p-4 dark:border-white/10 dark:bg-muted/10">
                    <div className="grid gap-4 lg:grid-cols-[minmax(0,1.3fr)_minmax(12rem,0.7fr)]">
                      <label className="space-y-1.5 text-sm font-semibold">
                        Schedule Name
                        <Input value={customForm.name} onChange={(e) => setCustomForm((f) => ({ ...f, name: e.target.value }))} placeholder="e.g. Night Shift - Production" />
                      </label>
                      <label className="space-y-1.5 text-sm font-semibold">
                        Schedule Code
                        <Input value={customForm.schedule_code || ''} onChange={(e) => setCustomForm((f) => ({ ...f, schedule_code: e.target.value }))} placeholder="Optional" maxLength={32} />
                      </label>
                    </div>

                    <div className="grid gap-4 lg:grid-cols-3">
                      <label className="space-y-1.5 text-sm font-semibold">
                        Schedule Type
                        <select className={FIELD_SELECT_CLASS_H10} value={customShiftType} onChange={(e) => setCustomForm((f) => ({ ...f, shift_type: e.target.value }))}>
                          {SHIFT_TYPES.map((type) => <option key={type.value} value={type.value}>{type.label}</option>)}
                        </select>
                      </label>
                      {!customIsSplit ? (
                        <>
                          <label className="space-y-1.5 text-sm font-semibold">
                            {customIsFlexible ? 'Earliest Clock-In' : 'Start Time'}
                            <Input type="time" value={customForm.time_in} onChange={(e) => setCustomForm((f) => ({ ...f, time_in: e.target.value }))} />
                          </label>
                          <label className="space-y-1.5 text-sm font-semibold">
                            {customIsFlexible ? 'Latest Clock-Out' : 'End Time'}
                            <Input type="time" value={customForm.time_out} onChange={(e) => setCustomForm((f) => ({ ...f, time_out: e.target.value }))} />
                          </label>
                        </>
                      ) : null}
                    </div>

                    {customCrossesMidnight && !customIsSplit ? (
                      <div className="flex items-center gap-2 rounded-md border border-amber-300/50 bg-amber-50/80 px-3 py-2 text-xs text-amber-800 dark:border-amber-700/50 dark:bg-amber-950/30 dark:text-amber-300">
                        <Info className="size-4 shrink-0" />
                        <span>Overnight shift detected. This custom adjustment will cross midnight.</span>
                      </div>
                    ) : null}

                    {customIsFlexible ? (
                      <div className="grid gap-4 rounded-lg border border-border/50 bg-background/70 p-3 dark:border-white/10 dark:bg-background/30 lg:grid-cols-3">
                        <label className="space-y-1.5 text-sm font-semibold">
                          Required Hours / Day
                          <Input
                            type="number"
                            min={0}
                            max={24}
                            step={0.5}
                            value={customForm.flexible_required_minutes ? Number(customForm.flexible_required_minutes) / 60 : ''}
                            onChange={(e) => setCustomForm((f) => ({ ...f, flexible_required_minutes: e.target.value ? Math.round(Number(e.target.value) * 60) : '' }))}
                            placeholder="8"
                          />
                        </label>
                        <label className="space-y-1.5 text-sm font-semibold">
                          Core Hours Start
                          <Input type="time" value={customForm.core_hours_start || ''} onChange={(e) => setCustomForm((f) => ({ ...f, core_hours_start: e.target.value }))} />
                        </label>
                        <label className="space-y-1.5 text-sm font-semibold">
                          Core Hours End
                          <Input type="time" value={customForm.core_hours_end || ''} onChange={(e) => setCustomForm((f) => ({ ...f, core_hours_end: e.target.value }))} />
                        </label>
                      </div>
                    ) : null}

                    {customIsSplit ? (
                      <div className="space-y-3 rounded-lg border border-border/50 bg-background/70 p-3 dark:border-white/10 dark:bg-background/30">
                        <div className="flex items-center justify-between gap-3">
                          <h4 className="text-xs font-black uppercase tracking-[0.16em] text-muted-foreground">Work Blocks</h4>
                          <Button type="button" variant="ghost" size="sm" className="h-8 gap-1" onClick={addCustomWorkBlock}>
                            <Plus className="size-3.5" /> Add Block
                          </Button>
                        </div>
                        {(customForm.work_blocks || []).length === 0 ? <p className="text-xs text-muted-foreground">Add at least two blocks for a split shift.</p> : null}
                        {(customForm.work_blocks || []).map((block, index) => (
                          <div key={index} className="grid items-end gap-3 md:grid-cols-[1fr_1fr_auto]">
                            <label className="space-y-1.5 text-xs font-semibold">Block {index + 1} Start<Input type="time" value={block.start || ''} onChange={(e) => updateCustomWorkBlock(index, 'start', e.target.value)} /></label>
                            <label className="space-y-1.5 text-xs font-semibold">Block {index + 1} End<Input type="time" value={block.end || ''} onChange={(e) => updateCustomWorkBlock(index, 'end', e.target.value)} /></label>
                            <Button type="button" variant="ghost" size="icon" className="size-10 text-destructive" onClick={() => removeCustomWorkBlock(index)}><Trash2 className="size-4" /></Button>
                          </div>
                        ))}
                      </div>
                    ) : (
                      <div className="grid gap-4 lg:grid-cols-2">
                        <label className="space-y-1.5 text-sm font-semibold">Break Start<Input type="time" value={customForm.break_start} onChange={(e) => setCustomForm((f) => ({ ...f, break_start: e.target.value }))} /></label>
                        <label className="space-y-1.5 text-sm font-semibold">Break End<Input type="time" value={customForm.break_end} onChange={(e) => setCustomForm((f) => ({ ...f, break_end: e.target.value }))} /></label>
                      </div>
                    )}

                    <div className="space-y-3 rounded-lg border border-border/50 bg-background/70 p-3 dark:border-white/10 dark:bg-background/30">
                      <div className="flex items-center justify-between gap-3">
                        <h4 className="text-xs font-black uppercase tracking-[0.16em] text-muted-foreground">Additional Breaks</h4>
                        <Button type="button" variant="ghost" size="sm" className="h-8 gap-1" onClick={addCustomBreak}>
                          <Plus className="size-3.5" /> Add Break
                        </Button>
                      </div>
                      {(customForm.breaks || []).length === 0 ? <p className="text-xs text-muted-foreground">No additional breaks. Use this for multiple break windows or paid breaks.</p> : null}
                      {(customForm.breaks || []).map((br, index) => (
                        <div key={index} className="grid items-end gap-3 md:grid-cols-[1fr_1fr_auto_auto]">
                          <label className="space-y-1.5 text-xs font-semibold">Start<Input type="time" value={br.start || ''} onChange={(e) => updateCustomBreak(index, 'start', e.target.value)} /></label>
                          <label className="space-y-1.5 text-xs font-semibold">End<Input type="time" value={br.end || ''} onChange={(e) => updateCustomBreak(index, 'end', e.target.value)} /></label>
                          <label className="flex h-10 items-center gap-2 text-xs font-semibold"><Checkbox checked={!!br.is_paid} onCheckedChange={(checked) => updateCustomBreak(index, 'is_paid', checked === true)} /> Paid</label>
                          <Button type="button" variant="ghost" size="icon" className="size-10 text-destructive" onClick={() => removeCustomBreak(index)}><Trash2 className="size-4" /></Button>
                        </div>
                      ))}
                    </div>

                    <div className="grid gap-4 lg:grid-cols-4">
                      <label className="space-y-1.5 text-sm font-semibold">Expected Paid Hours<Input type="number" min={0} max={24} step={0.5} value={customForm.expected_paid_minutes ? Number(customForm.expected_paid_minutes) / 60 : ''} onChange={(e) => setCustomForm((f) => ({ ...f, expected_paid_minutes: e.target.value ? Math.round(Number(e.target.value) * 60) : '' }))} placeholder="Auto" /></label>
                      <label className="space-y-1.5 text-sm font-semibold">Half-Day Threshold<Input type="number" min={0} max={12} step={0.25} value={customForm.half_day_threshold_minutes ? Number(customForm.half_day_threshold_minutes) / 60 : ''} onChange={(e) => setCustomForm((f) => ({ ...f, half_day_threshold_minutes: e.target.value ? Math.round(Number(e.target.value) * 60) : '' }))} placeholder="Auto" /></label>
                      <label className="space-y-1.5 text-sm font-semibold">Grace Period (min)<Input type="number" min={0} max={240} value={customForm.grace_period_minutes} onChange={(e) => setCustomForm((f) => ({ ...f, grace_period_minutes: e.target.value }))} /></label>
                      <label className="space-y-1.5 text-sm font-semibold">Overtime Buffer (min)<Input type="number" min={0} max={480} value={customForm.overtime_buffer_minutes} onChange={(e) => setCustomForm((f) => ({ ...f, overtime_buffer_minutes: e.target.value }))} /></label>
                    </div>

                    <div className="grid gap-4 lg:grid-cols-3">
                      <label className="space-y-1.5 text-sm font-semibold">Early Time-In (min)<Input type="number" min={0} max={480} value={customForm.early_timein_minutes} onChange={(e) => setCustomForm((f) => ({ ...f, early_timein_minutes: e.target.value }))} /></label>
                      <label className="space-y-1.5 text-sm font-semibold">Late Allowance (min)<Input type="number" min={0} max={240} value={customForm.late_allowance_minutes} onChange={(e) => setCustomForm((f) => ({ ...f, late_allowance_minutes: e.target.value }))} placeholder="Optional" /></label>
                      <label className="space-y-1.5 text-sm font-semibold">Early Time-Out (min)<Input type="number" min={0} max={240} value={customForm.early_timeout_minutes} onChange={(e) => setCustomForm((f) => ({ ...f, early_timeout_minutes: e.target.value }))} placeholder="Optional" /></label>
                    </div>

                    <div className="rounded-lg border border-primary/20 bg-primary/5 px-3 py-2.5 text-sm dark:bg-primary/10">
                      <p className="font-semibold">Paid hours: <span className="text-primary">{formatPaidHours(customPaidMinutes)}</span></p>
                      <p className="text-xs text-muted-foreground">Half-day threshold: {formatPaidHours(customHalfDayMinutes)}</p>
                    </div>

                    <div className="space-y-2">
                      <Label className="text-sm font-semibold">Rest Days</Label>
                      <div className="flex flex-wrap gap-2">
                        {DAY_OPTIONS.map((day) => {
                          const isOff = customForm.rest_days?.includes(day.key)
                          return (
                            <button
                              key={day.key}
                              type="button"
                              onClick={() => setCustomForm((f) => ({ ...f, rest_days: toggleRestDay(f.rest_days, day.key) }))}
                              className={`inline-flex min-h-11 min-w-11 items-center justify-center rounded-full border text-sm font-semibold transition-colors ${isOff ? 'border-primary bg-primary/15 text-foreground shadow-sm' : 'border-border bg-background text-muted-foreground hover:bg-muted/60'}`}
                              title={`${day.full}: ${isOff ? 'rest day' : 'working day'}`}
                              aria-pressed={isOff}
                            >
                              {day.label}
                            </button>
                          )
                        })}
                      </div>
                    </div>

                    <label className="block space-y-1.5 text-sm font-semibold">
                      Description
                      <textarea
                        value={customForm.description || ''}
                        onChange={(e) => setCustomForm((f) => ({ ...f, description: e.target.value }))}
                        placeholder="Optional notes about this custom schedule adjustment"
                        className="min-h-20 w-full rounded-lg border border-input bg-background px-3 py-2 text-sm"
                        maxLength={1000}
                      />
                    </label>
                  </div>
                ) : null}
              </div>

              <div className={sectionPanelClass}>
                <h3 className={sectionHeadingClass}>Effective Period</h3>
                <div className="mt-3 grid gap-4 lg:grid-cols-3">
                  <label className="space-y-1.5 text-sm font-semibold">Effective Start Date<Input type="date" value={effectiveStart} onChange={(e) => setEffectiveStart(e.target.value)} /></label>
                  <label className="space-y-1.5 text-sm font-semibold">End Date Option<select className={FIELD_SELECT_CLASS_H10} value={endMode} onChange={(e) => setEndMode(e.target.value)}><option value="open">Until Further Notice</option><option value="specific">End on Specific Date</option></select></label>
                  {endMode === 'specific' ? <label className="space-y-1.5 text-sm font-semibold">Effective End Date<Input type="date" value={effectiveEnd} onChange={(e) => setEffectiveEnd(e.target.value)} /></label> : null}
                </div>
              </div>

              <label className={`${sectionPanelClass} block space-y-2 text-sm font-semibold`}>
                <span className={sectionHeadingClass}>Adjustment Reason</span>
                <textarea className="min-h-24 w-full rounded-lg border border-input bg-background px-3 py-2 text-sm" value={reason} onChange={(e) => setReason(e.target.value)} placeholder="Branch transfer, temporary shift change, operational requirement, rest-day rotation..." />
              </label>
            </section>

            <section className="min-h-0 space-y-3 overflow-y-auto">
              <div className="rounded-xl border border-border/70 bg-background/95 p-4 shadow-sm dark:border-white/10 dark:bg-background/35">
                <div className="flex items-center justify-between gap-3">
                  <div>
                    <h3 className="text-sm font-black uppercase tracking-[0.05em]">Conflict Review</h3>
                    <p className="mt-2 text-sm leading-relaxed text-muted-foreground">Existing open assignments will be closed one day before the new start date. Historical schedule values are not overwritten.</p>
                  </div>
                  <Button type="button" variant="outline" size="sm" className="shrink-0 gap-2 rounded-lg font-black" onClick={handlePreview} disabled={previewing || loadingOptions}>
                    {previewing ? <Loader2 className="size-4 animate-spin" /> : <Users className="size-4" />}
                    Review
                  </Button>
                </div>
                <div className="mt-4 grid grid-cols-1 gap-3 text-sm sm:grid-cols-2">
                  <div className="rounded-lg border border-border/60 bg-background/80 p-4 dark:border-white/10">
                    <span className="flex items-center gap-2 text-xs font-semibold text-muted-foreground"><Users className="size-4" />Affected Employees</span>
                    <p className="mt-2 text-3xl font-black tabular-nums">{hasReviewed ? finalAffectedCount : '-'}</p>
                    <p className="text-sm text-muted-foreground">{hasReviewed ? 'employees' : 'review required'}</p>
                  </div>
                  <div className="rounded-lg border border-emerald-200 bg-emerald-50 p-4 text-emerald-950 dark:border-emerald-900/60 dark:bg-emerald-950/25 dark:text-emerald-100">
                    <span className="flex items-center gap-2 text-xs font-semibold text-emerald-800 dark:text-emerald-300"><ShieldCheck className="size-4" />Historical Attendance</span>
                    <p className="mt-5 text-center font-black leading-tight">Will remain<br />unchanged</p>
                  </div>
                  <div className="rounded-lg border border-border/60 bg-background/80 p-4 dark:border-white/10">
                    <span className="flex items-center gap-2 text-xs font-semibold text-muted-foreground"><CalendarDays className="size-4" />New Schedule</span>
                    <p className="mt-3 truncate font-black uppercase">{scheduleSource === 'template' ? selectedSchedule?.name || '-' : customForm.name || 'Custom schedule'}</p>
                    <p className="text-sm text-muted-foreground">{scheduleSource === 'template' && selectedSchedule ? formatShiftRange12h(selectedSchedule.time_in, selectedSchedule.time_out, ' - ') : formatPaidHours(customPaidMinutes)}</p>
                  </div>
                  <div className="rounded-lg border border-border/60 bg-background/80 p-4 dark:border-white/10">
                    <span className="flex items-center gap-2 text-xs font-semibold text-muted-foreground"><CalendarCheck className="size-4" />Effective Date</span>
                    <p className="mt-3 font-black">{effectiveStart || '-'}</p>
                    <p className="text-sm text-muted-foreground">{effectiveStart ? new Date(`${effectiveStart}T00:00:00`).toLocaleDateString(undefined, { weekday: 'long' }) : 'Select date'}</p>
                  </div>
                </div>
              </div>

              <div className="overflow-hidden rounded-xl border border-border/70 bg-background/95 shadow-sm dark:border-white/10 dark:bg-background/35">
                <div className="flex items-center justify-between gap-3 border-b border-border/70 px-4 py-3 dark:border-white/10">
                  <div>
                    <h3 className="text-sm font-black uppercase tracking-[0.05em]">Affected Employees</h3>
                    <p className="text-xs text-muted-foreground">Review and exclude employees before applying.</p>
                  </div>
                  <Badge variant="outline">{excludedEmployeeIds.length} excluded</Badge>
                </div>
                <div className="max-h-[42vh] overflow-y-auto">
                {affected.length === 0 ? (
                  <div className="m-4 grid min-h-40 place-items-center rounded-lg border border-dashed border-border/80 px-4 py-8 text-center dark:border-white/15">
                    <div>
                      <Users className="mx-auto size-7 text-muted-foreground" />
                      <p className="mt-3 font-black">{hasReviewed ? 'No employees excluded' : 'Review affected employees'}</p>
                      <p className="mt-1 text-sm text-muted-foreground">
                        {hasReviewed ? 'All selected employees will be affected.' : 'Select an organization item, then run review before applying.'}
                      </p>
                    </div>
                  </div>
                ) : affected.map((emp) => {
                  const excluded = excludedEmployeeIds.includes(Number(emp.id))
                  return (
                    <button key={emp.id} type="button" className={rowButtonClass} onClick={() => setExcludedEmployeeIds((ids) => toggleValue(ids, Number(emp.id)))}>
                      <Avatar className="size-9"><AvatarImage src={profileImageUrl(emp.profile_image)} /><AvatarFallback>{initials(emp.name)}</AvatarFallback></Avatar>
                      <span className="min-w-0">
                        <span className="block truncate text-sm font-semibold">{emp.name}</span>
                        <span className="block truncate text-xs text-muted-foreground">{emp.employee_number || `#${emp.id}`} - {emp.company || 'Company'} - {emp.branch || 'Branch'} - {emp.department || 'Department'}</span>
                        <span className="block truncate text-xs text-muted-foreground">Current schedule: {emp.current_schedule || 'None'}</span>
                      </span>
                      <Badge variant={excluded ? 'destructive' : 'outline'}>{excluded ? 'Excluded' : 'Included'}</Badge>
                    </button>
                  )
                })}
                </div>
              </div>

              <div className="flex items-center gap-4 rounded-lg border border-emerald-200 bg-emerald-50 px-5 py-4 text-sm font-semibold leading-relaxed text-emerald-900">
                <ShieldCheck className="size-5 shrink-0" />
                <span>Attendance and payroll will resolve schedules by employee and date.</span>
              </div>
            </section>
          </div>
        </div>

        <DialogFooter className="shrink-0 bg-card px-6 py-5">
          <Button type="button" variant="ghost" className="min-w-28 rounded-lg border-0 bg-muted/50 hover:bg-muted" onClick={() => onOpenChange(false)} disabled={submitting}>Cancel</Button>
          <Button type="button" className="min-w-44 rounded-lg bg-brand font-black hover:bg-brand/90" onClick={() => submit(false)} disabled={submitting || !hasReviewed || (finalAffectedCount ?? 0) <= 0}>{submitting ? <Loader2 className="size-4 animate-spin" /> : null}Apply Adjustment</Button>
        </DialogFooter>
      </DialogContent>
    </Dialog>
  )
}
