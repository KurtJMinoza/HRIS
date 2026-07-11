import { useState, useMemo, useEffect } from 'react'
import {
  Loader2, ChevronRight, ChevronLeft, Check, Search, Users, Building2,
  FileSpreadsheet, UserCheck, Calendar, ClipboardList,
} from 'lucide-react'
import { Button } from '@/components/ui/button'
import { Input } from '@/components/ui/input'
import { Label } from '@/components/ui/label'
import { Checkbox } from '@/components/ui/checkbox'
import { Badge } from '@/components/ui/badge'
import { Avatar, AvatarFallback, AvatarImage } from '@/components/ui/avatar'
import {
  Select, SelectContent, SelectItem, SelectTrigger, SelectValue,
} from '@/components/ui/select'
import {
  Dialog, DialogContent, DialogFooter, DialogHeader, DialogTitle, DialogDescription,
} from '@/components/ui/dialog'
import { AgcBrandLogo } from '@/components/AgcBrandLogo'
import { cn } from '@/lib/utils'
import { createEvaluationAssignment, getEvaluationEvaluatorPreview, profileImageUrl } from '@/api'
import { ADMIN_FORM_DIALOG_PRIMARY_BUTTON_CLASS } from '@/lib/adminFormDialogStyles'
import { resetRadixModalLock } from '@/lib/radixModalLock'

const CLOSE_ANIMATION_MS = 300

const MODAL_SHELL_CLASS =
  'max-h-[92vh] !w-[min(96vw,100rem)] !min-w-[min(96vw,100rem)] !max-w-none sm:!max-w-none lg:!max-w-none xl:!max-w-none rounded-[18px] border-border/80 bg-card shadow-[0_24px_80px_-24px_rgba(0,0,0,0.5)] dark:border-white/10 dark:bg-card'

const modalFieldClass =
  'h-12 rounded-xl border-border/70 bg-background px-4 text-sm shadow-sm transition-colors focus-visible:ring-[3px] focus-visible:ring-brand/25 dark:border-border/60 dark:bg-input/25'

const modalLabelClass = 'text-base font-semibold tracking-tight text-foreground'

const modalHintClass = 'text-[13px] leading-relaxed text-muted-foreground'

const EVALUATOR_ROLE_LABELS = {
  immediate_supervisor: 'Immediate Supervisor',
  section_head: 'Section Head',
  department_head: 'Department Head',
  division_head: 'Division Head',
  area_head: 'Area Head',
  branch_head: 'Branch Head',
  company_head: 'Company Head',
  hr: 'HR',
  self: 'Self Evaluation',
  custom: 'Custom Employee',
}

const REMINDER_OPTIONS = [7, 3, 1]

const STEPS = [
  { key: 'employees', label: 'Employees', icon: Users },
  { key: 'template', label: 'Template', icon: FileSpreadsheet },
  { key: 'evaluators', label: 'Evaluators', icon: UserCheck },
  { key: 'deadline', label: 'Deadline', icon: Calendar },
  { key: 'review', label: 'Review', icon: ClipboardList },
]

function employeeName(emp) {
  return [emp.first_name, emp.middle_name, emp.last_name, emp.suffix].filter(Boolean).join(' ')
}

function initials(name) {
  if (!name) return '?'
  return name.split(' ').map(w => w[0]).join('').toUpperCase().slice(0, 2)
}

function EmployeeAvatar({ employee, size = 'md' }) {
  const name = employeeName(employee)
  const sizeClass = size === 'sm' ? 'size-9' : size === 'lg' ? 'size-12' : 'size-11'
  return (
    <Avatar className={cn(sizeClass, 'shrink-0 ring-2 ring-background shadow-sm')}>
      <AvatarImage src={profileImageUrl(employee.profile_image)} alt={name} className="object-cover" />
      <AvatarFallback className="bg-linear-to-br from-teal-100 to-slate-200 text-xs font-bold text-teal-800 dark:from-teal-900/40 dark:to-slate-800 dark:text-teal-200">
        {initials(name)}
      </AvatarFallback>
    </Avatar>
  )
}

function EmployeePickerCard({ employee, selected, onToggle }) {
  const name = employeeName(employee)
  return (
    <button
      type="button"
      onClick={onToggle}
      className={cn(
        'flex w-full items-center gap-3 rounded-xl border px-4 py-3 text-left transition-all',
        selected
          ? 'border-brand/40 bg-brand/[0.04] ring-1 ring-brand/25 shadow-sm'
          : 'border-border/70 bg-card hover:border-border hover:bg-muted/20',
      )}
    >
      <Checkbox checked={selected} className="pointer-events-none" tabIndex={-1} />
      <EmployeeAvatar employee={employee} size="lg" />
      <div className="min-w-0 flex-1">
        <p className="truncate text-sm font-semibold text-foreground">{name}</p>
        <p className="truncate text-xs text-muted-foreground">
          {[employee.position || 'No position', employee.employee_code].filter(Boolean).join(' · ')}
        </p>
        {(employee.department_name || employee.branch_name) && (
          <p className="mt-0.5 truncate text-[11px] text-muted-foreground/80">
            {[employee.department_name, employee.branch_name].filter(Boolean).join(' · ')}
          </p>
        )}
      </div>
      {selected && (
        <Badge variant="secondary" className="shrink-0 rounded-full text-[10px] font-semibold">
          Selected
        </Badge>
      )}
    </button>
  )
}

export default function EvaluationAssignWizard({
  open,
  onOpenChange,
  forms = [],
  employees = [],
  companies = [],
  onAssigned,
}) {
  const [step, setStep] = useState(0)
  const [saving, setSaving] = useState(false)
  const [error, setError] = useState('')

  const [selectionMode, setSelectionMode] = useState('employees')
  const [selectedEmployeeIds, setSelectedEmployeeIds] = useState([])
  const [orgFilter, setOrgFilter] = useState({ company_id: '', branch_id: '', department_id: '' })
  const [employeeSearch, setEmployeeSearch] = useState('')

  const [formId, setFormId] = useState('')
  const [evaluatorRoles, setEvaluatorRoles] = useState([])
  const [customEvaluatorIds, setCustomEvaluatorIds] = useState([])
  const [customSearch, setCustomSearch] = useState('')
  const [evaluatorPreview, setEvaluatorPreview] = useState(null)
  const [previewLoading, setPreviewLoading] = useState(false)
  const [previewError, setPreviewError] = useState('')

  const [startDate, setStartDate] = useState('')
  const [endDate, setEndDate] = useState('')
  const [reminderDays, setReminderDays] = useState([7, 3, 1])
  const [keepMounted, setKeepMounted] = useState(false)

  const activeForms = useMemo(() => forms.filter(f => f.is_active !== false), [forms])

  const filteredEmployees = useMemo(() => {
    const q = employeeSearch.trim().toLowerCase()
    if (!q) return employees
    return employees.filter(emp => {
      const name = employeeName(emp).toLowerCase()
      const pos = (emp.position || '').toLowerCase()
      const code = (emp.employee_code || '').toLowerCase()
      return name.includes(q) || pos.includes(q) || code.includes(q)
    })
  }, [employees, employeeSearch])

  const filteredCustomEmployees = useMemo(() => {
    const q = customSearch.trim().toLowerCase()
    if (!q) return employees.slice(0, 30)
    return employees.filter(emp => employeeName(emp).toLowerCase().includes(q)).slice(0, 30)
  }, [employees, customSearch])

  const selectedForm = activeForms.find(f => String(f.id) === String(formId))
  const selectedEmployees = employees.filter(e => selectedEmployeeIds.includes(e.id))

  const reset = () => {
    setStep(0)
    setError('')
    setSelectionMode('employees')
    setSelectedEmployeeIds([])
    setOrgFilter({ company_id: '', branch_id: '', department_id: '' })
    setEmployeeSearch('')
    setFormId('')
    setEvaluatorRoles([])
    setCustomEvaluatorIds([])
    setCustomSearch('')
    setEvaluatorPreview(null)
    setPreviewLoading(false)
    setPreviewError('')
    setStartDate('')
    setEndDate('')
    setReminderDays([7, 3, 1])
  }

  const buildScopePayload = () => {
    if (selectionMode === 'employees') {
      return { employee_ids: selectedEmployeeIds }
    }
    const payload = {}
    if (orgFilter.company_id) payload.company_id = Number(orgFilter.company_id)
    if (orgFilter.branch_id) payload.branch_id = Number(orgFilter.branch_id)
    if (orgFilter.department_id) payload.department_id = Number(orgFilter.department_id)
    return payload
  }

  const loadEvaluatorPreview = async () => {
    setPreviewLoading(true)
    setPreviewError('')
    try {
      const data = await getEvaluationEvaluatorPreview(buildScopePayload())
      setEvaluatorPreview(data)
      const available = new Set([
        ...(data.hierarchy || []).map(h => h.role),
        ...(data.special || []).map(s => s.role),
      ])
      setEvaluatorRoles(prev => {
        const kept = prev.filter(r => available.has(r))
        if (kept.length > 0) return kept
        const first = data.hierarchy?.[0]?.role
        return first ? [first] : []
      })
    } catch (e) {
      setPreviewError(e.message)
      setEvaluatorPreview({ hierarchy: [], special: [], employee_count: 0 })
    } finally {
      setPreviewLoading(false)
    }
  }

  const handleClose = (nextOpen) => {
    onOpenChange(nextOpen)
    if (!nextOpen) {
      window.requestAnimationFrame(resetRadixModalLock)
    }
  }

  useEffect(() => {
    if (open) {
      setKeepMounted(true)
      return undefined
    }
    if (!keepMounted) return undefined
    const timer = window.setTimeout(() => {
      setKeepMounted(false)
      resetRadixModalLock()
    }, CLOSE_ANIMATION_MS)
    return () => window.clearTimeout(timer)
  }, [open, keepMounted])

  useEffect(() => {
    if (open) reset()
  }, [open])

  if (!open && !keepMounted) return null

  const toggleEmployee = (id) => {
    setSelectedEmployeeIds(prev =>
      prev.includes(id) ? prev.filter(x => x !== id) : [...prev, id],
    )
  }

  const selectAllFiltered = () => {
    setSelectedEmployeeIds(prev => {
      const ids = filteredEmployees.map(e => e.id)
      return [...new Set([...prev, ...ids])]
    })
  }

  const toggleRole = (role) => {
    setEvaluatorRoles(prev =>
      prev.includes(role) ? prev.filter(r => r !== role) : [...prev, role],
    )
  }

  const toggleReminder = (day) => {
    setReminderDays(prev =>
      prev.includes(day) ? prev.filter(d => d !== day) : [...prev, day].sort((a, b) => b - a),
    )
  }

  const buildPayload = () => {
    const payload = {
      evaluation_form_id: Number(formId),
      evaluator_roles: evaluatorRoles,
      start_date: startDate,
      end_date: endDate,
      reminder_days: reminderDays,
    }
    if (selectionMode === 'employees') {
      payload.employee_ids = selectedEmployeeIds
    } else {
      if (orgFilter.company_id) payload.company_id = Number(orgFilter.company_id)
      if (orgFilter.branch_id) payload.branch_id = Number(orgFilter.branch_id)
      if (orgFilter.department_id) payload.department_id = Number(orgFilter.department_id)
    }
    if (evaluatorRoles.includes('custom') && customEvaluatorIds.length) {
      payload.custom_evaluator_ids = customEvaluatorIds
    }
    return payload
  }

  const canNext = () => {
    if (step === 0) {
      if (selectionMode === 'employees') return selectedEmployeeIds.length > 0
      return orgFilter.company_id || orgFilter.branch_id || orgFilter.department_id
    }
    if (step === 1) return !!formId
    if (step === 2) {
      if (evaluatorRoles.length === 0) return false
      if (evaluatorRoles.includes('custom') && customEvaluatorIds.length === 0) return false
      return true
    }
    if (step === 3) return startDate && endDate && endDate >= startDate
    return true
  }

  const goNext = () => {
    const next = step + 1
    // Entering the evaluators step: analyze the selected employees' reporting chains.
    if (next === 2) loadEvaluatorPreview()
    setStep(next)
  }

  const handleAssign = async () => {
    setSaving(true)
    setError('')
    try {
      await createEvaluationAssignment(buildPayload())
      onAssigned?.()
      handleClose(false)
    } catch (e) {
      setError(e.message)
    } finally {
      setSaving(false)
    }
  }

  return (
    <Dialog open={open} onOpenChange={handleClose}>
      <DialogContent
        showCloseButton
        overlayClassName="bg-black/55 backdrop-blur-sm dark:bg-black/70"
        closeButtonClassName="right-7 top-7 size-14 rounded-xl border-border/80 bg-background/90 text-foreground shadow-sm hover:bg-muted dark:border-white/10 dark:bg-card/90"
        className={MODAL_SHELL_CLASS}
        innerClassName="gap-0 overflow-hidden p-0 pr-0"
        aria-describedby="eval-assign-desc"
      >
        <div className="min-h-0 flex-1 overflow-y-auto">
          {/* Header — matches AdminLeave "File new leave" modal */}
          <DialogHeader className="relative overflow-hidden border-b border-border/70 bg-linear-to-br from-card via-card to-brand/5 px-8 pb-6 pt-8 text-left dark:to-brand/10 @md:px-12">
            <AgcBrandLogo className="mb-7 h-9 @md:h-10" />
            <div className="relative z-10 space-y-3 pr-14 @md:pr-0">
              <DialogTitle className="text-2xl font-bold tracking-tight text-foreground @md:text-3xl">
                Assign Performance Evaluation
              </DialogTitle>
              <DialogDescription id="eval-assign-desc" className="max-w-4xl text-base leading-relaxed text-muted-foreground @md:text-lg">
                Step {step + 1} of {STEPS.length} — {STEPS[step].label}. Choose employees, template, evaluators, and deadline.
              </DialogDescription>
            </div>

            {/* Stepper */}
            <div className="relative z-10 mt-8 flex flex-wrap gap-2">
              {STEPS.map(({ label, icon: Icon }, i) => (
                <button
                  key={label}
                  type="button"
                  onClick={() => i < step && setStep(i)}
                  disabled={i > step}
                  className={cn(
                    'inline-flex items-center gap-2 rounded-xl border px-4 py-2.5 text-sm font-semibold transition-all',
                    i === step && 'border-brand/30 bg-card text-foreground shadow-sm ring-1 ring-brand/20',
                    i < step && 'border-border/60 bg-card/80 text-foreground hover:bg-muted/40',
                    i > step && 'cursor-not-allowed border-transparent bg-muted/30 text-muted-foreground',
                  )}
                >
                  <span className={cn(
                    'flex size-6 items-center justify-center rounded-full text-xs font-bold',
                    i <= step ? 'bg-brand text-brand-foreground' : 'bg-muted text-muted-foreground',
                  )}>
                    {i < step ? <Check className="size-3.5" /> : i + 1}
                  </span>
                  <Icon className="size-4 shrink-0 opacity-70" />
                  <span className="hidden @sm:inline">{label}</span>
                </button>
              ))}
            </div>
          </DialogHeader>

          <div className="px-8 py-7 @md:px-12">
            {step === 0 && (
              <div className="space-y-6">
                <div className="inline-flex rounded-xl border border-border/60 bg-muted/20 p-1">
                  {[
                    { value: 'employees', label: 'Select Employees', icon: Users },
                    { value: 'org', label: 'By Organization', icon: Building2 },
                  ].map(opt => {
                    const Icon = opt.icon
                    return (
                      <button
                        key={opt.value}
                        type="button"
                        onClick={() => setSelectionMode(opt.value)}
                        className={cn(
                          'flex items-center gap-2 rounded-lg px-5 py-2.5 text-sm font-semibold transition-all',
                          selectionMode === opt.value
                            ? 'bg-card text-foreground shadow-sm ring-1 ring-border/60'
                            : 'text-muted-foreground hover:text-foreground',
                        )}
                      >
                        <Icon className="size-4" />
                        {opt.label}
                      </button>
                    )
                  })}
                </div>

                {selectionMode === 'employees' ? (
                  <>
                    <div className="flex flex-col gap-4 @lg:flex-row @lg:items-end @lg:justify-between">
                      <div className="min-w-0 flex-1 space-y-2">
                        <Label className={modalLabelClass}>Search employees</Label>
                        <div className="relative">
                          <Search className="pointer-events-none absolute left-4 top-1/2 size-4 -translate-y-1/2 text-muted-foreground" />
                          <Input
                            placeholder="Name, position, or employee ID..."
                            value={employeeSearch}
                            onChange={e => setEmployeeSearch(e.target.value)}
                            className={cn(modalFieldClass, 'h-12 pl-11')}
                          />
                        </div>
                      </div>
                      <div className="flex shrink-0 gap-2">
                        <Button type="button" variant="outline" className="h-12 rounded-xl px-5" onClick={selectAllFiltered}>
                          Select all
                        </Button>
                        <Button type="button" variant="outline" className="h-12 rounded-xl px-5" onClick={() => setSelectedEmployeeIds([])} disabled={!selectedEmployeeIds.length}>
                          Clear
                        </Button>
                      </div>
                    </div>

                    <div className="grid max-h-[min(48vh,460px)] gap-3 overflow-y-auto rounded-2xl border border-border/70 bg-muted/10 p-4 @md:grid-cols-2 @xl:grid-cols-3 @2xl:grid-cols-4">
                      {filteredEmployees.map(emp => (
                        <EmployeePickerCard
                          key={emp.id}
                          employee={emp}
                          selected={selectedEmployeeIds.includes(emp.id)}
                          onToggle={() => toggleEmployee(emp.id)}
                        />
                      ))}
                      {filteredEmployees.length === 0 && (
                        <div className="col-span-full flex min-h-[220px] flex-col items-center justify-center text-muted-foreground">
                          <Users className="mb-3 size-12 opacity-25" />
                          <p className="font-medium">No employees found</p>
                          <p className={cn('mt-1', modalHintClass)}>Try a different search term</p>
                        </div>
                      )}
                    </div>

                    {selectedEmployeeIds.length > 0 && (
                      <div className="rounded-xl border border-brand/20 bg-brand/[0.04] px-5 py-4">
                        <p className="text-[11px] font-bold uppercase tracking-[0.16em] text-brand">
                          {selectedEmployeeIds.length} employee{selectedEmployeeIds.length !== 1 ? 's' : ''} selected
                        </p>
                        <div className="mt-3 flex flex-wrap gap-2">
                          {selectedEmployees.map(emp => (
                            <div key={emp.id} className="flex items-center gap-2 rounded-full border border-border/60 bg-card py-1 pl-1 pr-4 text-sm font-medium shadow-sm">
                              <EmployeeAvatar employee={emp} size="sm" />
                              <span className="max-w-[160px] truncate">{employeeName(emp)}</span>
                            </div>
                          ))}
                        </div>
                      </div>
                    )}
                  </>
                ) : (
                  <div className="grid gap-6 rounded-2xl border border-border/70 bg-muted/10 p-6 @md:grid-cols-2">
                    <div className="space-y-2">
                      <Label className={modalLabelClass}>Company</Label>
                      <Select value={orgFilter.company_id} onValueChange={v => setOrgFilter(f => ({ ...f, company_id: v }))}>
                        <SelectTrigger className={cn(modalFieldClass, 'w-full')}><SelectValue placeholder="Select company" /></SelectTrigger>
                        <SelectContent>
                          {companies.map(c => <SelectItem key={c.id} value={String(c.id)}>{c.name}</SelectItem>)}
                        </SelectContent>
                      </Select>
                    </div>
                    <div className="flex items-center">
                      <p className={modalHintClass}>
                        All active employees in the selected organization scope will receive this assignment.
                      </p>
                    </div>
                  </div>
                )}
              </div>
            )}

            {step === 1 && (
              <div className="space-y-4">
                <Label className={modalLabelClass}>Evaluation template</Label>
                <div className="grid gap-4 @md:grid-cols-2 @xl:grid-cols-3">
                  {activeForms.map(form => (
                    <button
                      key={form.id}
                      type="button"
                      onClick={() => setFormId(String(form.id))}
                      className={cn(
                        'flex items-start gap-4 rounded-2xl border p-5 text-left transition-all',
                        String(form.id) === formId
                          ? 'border-brand/40 bg-brand/[0.04] ring-2 ring-brand/20 shadow-sm'
                          : 'border-border/70 bg-card hover:border-border hover:shadow-sm',
                      )}
                    >
                      <div className="flex size-11 shrink-0 items-center justify-center rounded-xl bg-brand/10 text-brand">
                        <FileSpreadsheet className="size-5" />
                      </div>
                      <div className="min-w-0">
                        <p className="font-semibold leading-snug text-foreground">{form.title}</p>
                        {form.description && (
                          <p className={cn('mt-1.5 line-clamp-2', modalHintClass)}>{form.description}</p>
                        )}
                      </div>
                    </button>
                  ))}
                </div>
                {activeForms.length === 0 && (
                  <p className={cn('py-12 text-center', modalHintClass)}>No active templates. Create one in the Templates tab first.</p>
                )}
              </div>
            )}

            {step === 2 && (
              <div className="space-y-6">
                <div>
                  <Label className={modalLabelClass}>Who will evaluate the selected employee(s)?</Label>
                  <p className={cn('mt-1', modalHintClass)}>
                    Only reporting levels that exist in the selected employees&apos; chains are shown. Each employee is auto-matched to their own evaluator at that level.
                  </p>
                </div>

                {previewLoading ? (
                  <div className="flex items-center justify-center gap-3 py-12 text-sm text-muted-foreground">
                    <Loader2 className="size-5 animate-spin" />
                    Analyzing reporting hierarchy…
                  </div>
                ) : (
                  <>
                    {(evaluatorPreview?.hierarchy?.length ?? 0) > 0 && (
                      <div className="space-y-3">
                        <p className="flex items-center gap-2 text-[11px] font-bold uppercase tracking-[0.16em] text-muted-foreground">
                          <UserCheck className="size-3.5" /> Automatic evaluators
                        </p>
                        <div className="grid gap-3 @sm:grid-cols-2 @lg:grid-cols-3">
                          {evaluatorPreview.hierarchy.map(item => (
                            <label
                              key={item.role}
                              className={cn(
                                'flex cursor-pointer items-start gap-3 rounded-xl border px-4 py-3.5 transition-all',
                                evaluatorRoles.includes(item.role)
                                  ? 'border-brand/35 bg-brand/[0.04] ring-1 ring-brand/15'
                                  : 'border-border/70 hover:bg-muted/20',
                              )}
                            >
                              <Checkbox className="mt-0.5" checked={evaluatorRoles.includes(item.role)} onCheckedChange={() => toggleRole(item.role)} />
                              <div className="min-w-0">
                                <span className="block text-sm font-medium">{item.label}</span>
                                <span className="mt-0.5 block text-xs text-muted-foreground">
                                  {item.evaluator_count} evaluator{item.evaluator_count !== 1 ? 's' : ''}
                                  {item.employee_count < (evaluatorPreview.employee_count || 0)
                                    ? ` · covers ${item.employee_count} of ${evaluatorPreview.employee_count}`
                                    : ''}
                                </span>
                                <span className="mt-0.5 block truncate text-xs text-muted-foreground/80" title={item.evaluators.join(', ')}>
                                  {item.evaluators.join(', ')}
                                </span>
                              </div>
                            </label>
                          ))}
                        </div>
                      </div>
                    )}

                    {(evaluatorPreview?.hierarchy?.length ?? 0) === 0 && !previewError && (
                      <p className={cn('rounded-xl border border-dashed border-border/70 px-4 py-5 text-center', modalHintClass)}>
                        No reporting-chain evaluators were found for the selected employee(s). Use the options below instead.
                      </p>
                    )}

                    {previewError && (
                      <p className="rounded-xl border border-destructive/30 bg-destructive/5 px-4 py-3 text-sm text-destructive">{previewError}</p>
                    )}

                    <div className="space-y-3">
                      <p className="text-[11px] font-bold uppercase tracking-[0.16em] text-muted-foreground">Other options</p>
                      <div className="grid gap-3 @sm:grid-cols-2 @lg:grid-cols-3">
                        {(evaluatorPreview?.special ?? [{ role: 'hr' }, { role: 'self' }, { role: 'custom' }]).map(item => (
                          <label
                            key={item.role}
                            className={cn(
                              'flex cursor-pointer items-center gap-3 rounded-xl border px-4 py-3.5 transition-all',
                              evaluatorRoles.includes(item.role)
                                ? 'border-brand/35 bg-brand/[0.04] ring-1 ring-brand/15'
                                : 'border-border/70 hover:bg-muted/20',
                            )}
                          >
                            <Checkbox checked={evaluatorRoles.includes(item.role)} onCheckedChange={() => toggleRole(item.role)} />
                            <span className="text-sm font-medium">{item.label || EVALUATOR_ROLE_LABELS[item.role] || item.role}</span>
                          </label>
                        ))}
                      </div>
                    </div>
                  </>
                )}

                {evaluatorRoles.includes('custom') && (
                  <div className="space-y-4 rounded-2xl border border-border/70 bg-muted/10 p-5">
                    <Label className={modalLabelClass}>Custom evaluators</Label>
                    <div className="relative">
                      <Search className="pointer-events-none absolute left-4 top-1/2 size-4 -translate-y-1/2 text-muted-foreground" />
                      <Input placeholder="Search employees..." value={customSearch} onChange={e => setCustomSearch(e.target.value)} className={cn(modalFieldClass, 'pl-11')} />
                    </div>
                    <div className="grid max-h-52 gap-2 overflow-y-auto @md:grid-cols-2 @lg:grid-cols-3">
                      {filteredCustomEmployees.map(emp => (
                        <button
                          key={emp.id}
                          type="button"
                          onClick={() => setCustomEvaluatorIds(prev =>
                            prev.includes(emp.id) ? prev.filter(x => x !== emp.id) : [...prev, emp.id],
                          )}
                          className={cn(
                            'flex items-center gap-2 rounded-xl border px-3 py-2.5 text-left transition',
                            customEvaluatorIds.includes(emp.id) ? 'border-brand/35 bg-brand/[0.04]' : 'border-border/60 hover:bg-muted/20',
                          )}
                        >
                          <Checkbox checked={customEvaluatorIds.includes(emp.id)} className="pointer-events-none" />
                          <EmployeeAvatar employee={emp} size="sm" />
                          <span className="truncate text-sm font-medium">{employeeName(emp)}</span>
                        </button>
                      ))}
                    </div>
                  </div>
                )}
              </div>
            )}

            {step === 3 && (
              <div className="grid gap-8 @xl:grid-cols-2">
                <div className="space-y-5">
                  <div className="space-y-2">
                    <Label htmlFor="eval-start" className={modalLabelClass}>Start date</Label>
                    <Input id="eval-start" type="date" value={startDate} onChange={e => setStartDate(e.target.value)} className={cn(modalFieldClass, 'h-[4.25rem] px-4 pb-3 pt-7 [color-scheme:light] dark:[color-scheme:dark]')} />
                  </div>
                  <div className="space-y-2">
                    <Label htmlFor="eval-end" className={modalLabelClass}>End date</Label>
                    <Input id="eval-end" type="date" value={endDate} onChange={e => setEndDate(e.target.value)} className={cn(modalFieldClass, 'h-[4.25rem] px-4 pb-3 pt-7 [color-scheme:light] dark:[color-scheme:dark]')} />
                  </div>
                </div>
                <div className="space-y-3">
                  <Label className={modalLabelClass}>Reminder before deadline</Label>
                  <p className={modalHintClass}>Send reminders this many days before the end date.</p>
                  <div className="flex flex-wrap gap-3 pt-1">
                    {REMINDER_OPTIONS.map(day => (
                      <label
                        key={day}
                        className={cn(
                          'flex cursor-pointer items-center gap-2.5 rounded-xl border px-5 py-3.5 transition',
                          reminderDays.includes(day) ? 'border-brand/35 bg-brand/[0.04]' : 'border-border/70',
                        )}
                      >
                        <Checkbox checked={reminderDays.includes(day)} onCheckedChange={() => toggleReminder(day)} />
                        <span className="text-sm font-semibold">{day} day{day !== 1 ? 's' : ''}</span>
                      </label>
                    ))}
                  </div>
                </div>
              </div>
            )}

            {step === 4 && (
              <div className="grid gap-6 @xl:grid-cols-2">
                <div className="rounded-2xl border border-border/70 bg-muted/10 p-6">
                  <p className="text-[11px] font-bold uppercase tracking-[0.16em] text-muted-foreground">Assignment summary</p>
                  <dl className="mt-4 space-y-3 text-sm">
                    <div className="flex justify-between gap-4">
                      <dt className="text-muted-foreground">Employees</dt>
                      <dd className="font-semibold text-right">
                        {selectionMode === 'employees' ? `${selectedEmployeeIds.length} selected` : 'Organization scope'}
                      </dd>
                    </div>
                    <div className="flex justify-between gap-4">
                      <dt className="text-muted-foreground">Template</dt>
                      <dd className="max-w-[60%] text-right font-semibold">{selectedForm?.title || '—'}</dd>
                    </div>
                    <div className="flex justify-between gap-4">
                      <dt className="text-muted-foreground">Deadline</dt>
                      <dd className="font-semibold">{endDate || '—'}</dd>
                    </div>
                  </dl>
                </div>
                <div className="rounded-2xl border border-border/70 bg-muted/10 p-6">
                  <p className="text-[11px] font-bold uppercase tracking-[0.16em] text-muted-foreground">Evaluators</p>
                  <div className="mt-4 flex flex-wrap gap-2">
                    {evaluatorRoles.map(r => (
                      <Badge key={r} variant="secondary" className="rounded-full px-3 py-1 font-medium">
                        {EVALUATOR_ROLE_LABELS[r] || r}
                      </Badge>
                    ))}
                  </div>
                  {selectionMode === 'employees' && selectedEmployees.length > 0 && (
                    <>
                      <p className="mt-6 text-[11px] font-bold uppercase tracking-[0.16em] text-muted-foreground">Selected employees</p>
                      <div className="mt-3 flex flex-wrap gap-2">
                        {selectedEmployees.map(emp => (
                          <div key={emp.id} className="flex items-center gap-2 rounded-full border border-border/60 bg-card py-1 pl-1 pr-4 text-sm shadow-sm">
                            <EmployeeAvatar employee={emp} size="sm" />
                            <span className="max-w-[140px] truncate font-medium">{employeeName(emp)}</span>
                          </div>
                        ))}
                      </div>
                    </>
                  )}
                </div>
              </div>
            )}

            {error && (
              <p className="mt-6 rounded-xl border border-destructive/30 bg-destructive/5 px-4 py-3 text-sm text-destructive">{error}</p>
            )}
          </div>
        </div>

        <DialogFooter className="shrink-0 border-t border-border/70 bg-card px-8 py-5 @md:px-12">
          <div className="flex w-full flex-col-reverse items-stretch gap-3 @sm:flex-row @sm:items-center @sm:justify-between">
            <p className={modalHintClass}>
              {step === 0 && selectionMode === 'employees'
                ? `${selectedEmployeeIds.length} employee${selectedEmployeeIds.length !== 1 ? 's' : ''} selected`
                : `Step ${step + 1} of ${STEPS.length}`}
            </p>
            <div className="flex flex-wrap justify-end gap-3">
              {step > 0 && (
                <Button
                  type="button"
                  variant="outline"
                  className="h-12 min-w-32 rounded-xl border-border/80 px-6 text-base font-semibold"
                  onClick={() => setStep(s => s - 1)}
                >
                  <ChevronLeft className="size-4" />
                  Back
                </Button>
              )}
              {step < STEPS.length - 1 ? (
                <Button
                  type="button"
                  className={cn(ADMIN_FORM_DIALOG_PRIMARY_BUTTON_CLASS, 'h-12 min-w-32 rounded-xl px-8 text-base font-semibold')}
                  disabled={!canNext()}
                  onClick={goNext}
                >
                  Next
                  <ChevronRight className="size-4" />
                </Button>
              ) : (
                <Button
                  type="button"
                  className={cn(ADMIN_FORM_DIALOG_PRIMARY_BUTTON_CLASS, 'h-12 min-w-40 rounded-xl px-8 text-base font-semibold')}
                  disabled={saving}
                  onClick={handleAssign}
                >
                  {saving ? <Loader2 className="size-4 animate-spin" /> : <Check className="size-4" />}
                  Assign evaluation
                </Button>
              )}
            </div>
          </div>
        </DialogFooter>
      </DialogContent>
    </Dialog>
  )
}
