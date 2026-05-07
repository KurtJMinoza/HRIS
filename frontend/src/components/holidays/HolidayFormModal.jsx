import { useState, useEffect, useMemo, useCallback } from 'react'
import { z } from 'zod'
import { format, parseISO, isValid } from 'date-fns'
import { CalendarIcon, Info, Loader2 } from 'lucide-react'
import { toast } from 'sonner'
import { Button } from '@/components/ui/button'
import { Input } from '@/components/ui/input'
import { Label } from '@/components/ui/label'
import { Textarea } from '@/components/ui/textarea'
import { Dialog, DialogContent, DialogFooter, DialogHeader, DialogTitle } from '@/components/ui/dialog'
import { Popover, PopoverContent, PopoverTrigger } from '@/components/ui/popover'
import { Calendar } from '@/components/ui/calendar'
import { RadioGroup, RadioGroupItem } from '@/components/ui/radio-group'
import { Switch } from '@/components/ui/switch'
import { Checkbox } from '@/components/ui/checkbox'
import { Badge } from '@/components/ui/badge'
import { Tooltip, TooltipContent, TooltipProvider, TooltipTrigger } from '@/components/ui/tooltip'
import { cn } from '@/lib/utils'
import { FIELD_SELECT_CLASS } from '@/lib/fieldClasses'
import {
  ADMIN_FORM_DIALOG_BODY_CLASS,
  ADMIN_FORM_DIALOG_DESC_CLASS,
  ADMIN_FORM_DIALOG_FOOTER_CLASS,
  ADMIN_FORM_DIALOG_HEADER_INNER_CLASS,
  ADMIN_FORM_DIALOG_HEADER_WRAP_CLASS,
  ADMIN_FORM_DIALOG_PRIMARY_BUTTON_CLASS,
  ADMIN_FORM_DIALOG_TITLE_CLASS,
  adminFormDialogContentClass,
} from '@/lib/adminFormDialogStyles'
import {
  PH_REGION_OPTIONS,
  HOLIDAY_TYPE_OPTIONS,
  HOLIDAY_STATUS_OPTIONS,
  holidayImpactPreview,
} from '@/lib/holidayConstants'
import { HolidayPayReferenceAccordion } from '@/components/holidays/HolidayPayReferenceAccordion'
import {
  createAdminHoliday,
  getBranchDepartments,
  getCompanies,
  getCompanyBranches,
  getEmployees,
  updateAdminHoliday,
} from '@/api'

const formSchema = z
  .object({
    name: z.string().trim().min(1, 'Holiday name is required').max(255),
    date: z.string().regex(/^\d{4}-\d{2}-\d{2}$/, 'Pick a valid date'),
    type: z.enum(['regular', 'special', 'special_working', 'company']),
    description: z.string().max(1000).optional().or(z.literal('')),
    scope: z.enum(['nationwide', 'regional', 'company', 'branch', 'department', 'employee']),
    companyId: z.string().optional().or(z.literal('')),
    branchId: z.string().optional().or(z.literal('')),
    departmentId: z.string().optional().or(z.literal('')),
    employeeId: z.string().optional().or(z.literal('')),
    regions: z.array(z.string()).optional(),
    isRecurring: z.boolean(),
    status: z.enum(['active', 'inactive', 'draft']),
  })
  .superRefine((data, ctx) => {
    if (data.scope === 'regional' && (!data.regions || data.regions.length === 0)) {
      ctx.addIssue({
        code: z.ZodIssueCode.custom,
        message: 'Select at least one region for a regional holiday',
        path: ['regions'],
      })
    }
    if (['company', 'branch', 'department', 'employee'].includes(data.scope) && !data.companyId) {
      ctx.addIssue({ code: z.ZodIssueCode.custom, message: 'Select a company', path: ['companyId'] })
    }
    if (data.scope === 'branch' && !data.branchId) {
      ctx.addIssue({ code: z.ZodIssueCode.custom, message: 'Select a branch', path: ['branchId'] })
    }
    if (data.scope === 'department' && !data.departmentId) {
      ctx.addIssue({ code: z.ZodIssueCode.custom, message: 'Select a department', path: ['departmentId'] })
    }
    if (data.scope === 'employee' && !data.employeeId) {
      ctx.addIssue({ code: z.ZodIssueCode.custom, message: 'Select an employee', path: ['employeeId'] })
    }
  })

function emptyForm() {
  return {
    name: '',
    date: '',
    type: 'regular',
    description: '',
    scope: 'nationwide',
    companyId: '',
    branchId: '',
    departmentId: '',
    employeeId: '',
    regions: [],
    isRecurring: false,
    status: 'active',
  }
}

/**
 * @param {{
 *   open: boolean,
 *   onOpenChange: (open: boolean) => void,
 *   mode: 'create' | 'edit',
 *   editingId: number | null,
 *   initial: Partial<Record<string, unknown>> | null,
 *   onSaved: () => Promise<void> | void,
 * }} props
 */
export function HolidayFormModal({ open, onOpenChange, mode, editingId, initial, onSaved }) {
  const [values, setValues] = useState(emptyForm)
  const [fieldErrors, setFieldErrors] = useState({})
  const [submitError, setSubmitError] = useState('')
  const [submitting, setSubmitting] = useState(false)
  const [calOpen, setCalOpen] = useState(false)
  const [companies, setCompanies] = useState([])
  const [branches, setBranches] = useState([])
  const [departments, setDepartments] = useState([])
  const [employees, setEmployees] = useState([])

  const selectedDate = useMemo(() => {
    if (!values.date) return undefined
    try {
      const d = parseISO(values.date)
      return isValid(d) ? d : undefined
    } catch {
      return undefined
    }
  }, [values.date])

  useEffect(() => {
    if (!open) return
    setSubmitError('')
    setFieldErrors({})
    if (initial && (mode === 'edit' || Object.keys(initial).length)) {
      const scope = ['regional', 'company', 'branch', 'department', 'employee'].includes(initial.scope) ? initial.scope : 'nationwide'
      setValues({
        name: initial.name ?? '',
        date: typeof initial.date === 'string' ? initial.date.slice(0, 10) : '',
        type: ['regular', 'special', 'special_working', 'company'].includes(initial.type) ? initial.type : 'regular',
        description: initial.description ?? '',
        scope,
        companyId: initial.company_id != null ? String(initial.company_id) : '',
        branchId: initial.branch_id != null ? String(initial.branch_id) : '',
        departmentId: initial.department_id != null ? String(initial.department_id) : '',
        employeeId: initial.employee_id != null ? String(initial.employee_id) : '',
        regions: Array.isArray(initial.regions) ? initial.regions : [],
        isRecurring: Boolean(initial.is_recurring),
        status: ['active', 'inactive', 'draft'].includes(initial.status) ? initial.status : 'active',
      })
    } else {
      setValues(emptyForm())
    }
  }, [open, mode, initial])

  const toList = useCallback((data, key) => {
    if (Array.isArray(data)) return data
    if (Array.isArray(data?.[key])) return data[key]
    if (Array.isArray(data?.data)) return data.data
    return []
  }, [])

  useEffect(() => {
    if (!open) return
    let cancelled = false
    getCompanies()
      .then((data) => {
        if (!cancelled) setCompanies(toList(data, 'companies'))
      })
      .catch(() => {
        if (!cancelled) setCompanies([])
      })
    return () => {
      cancelled = true
    }
  }, [open, toList])

  useEffect(() => {
    if (!open || !values.companyId) {
      setBranches([])
      setEmployees([])
      return
    }
    let cancelled = false
    getCompanyBranches(values.companyId)
      .then((data) => {
        if (!cancelled) setBranches(toList(data, 'branches'))
      })
      .catch(() => {
        if (!cancelled) setBranches([])
      })
    getEmployees({ company_id: values.companyId, per_page: 100, lite: true })
      .then((data) => {
        if (!cancelled) setEmployees(toList(data, 'employees'))
      })
      .catch(() => {
        if (!cancelled) setEmployees([])
      })
    return () => {
      cancelled = true
    }
  }, [open, values.companyId, toList])

  useEffect(() => {
    if (!open || !values.branchId) {
      setDepartments([])
      return
    }
    let cancelled = false
    getBranchDepartments(values.branchId)
      .then((data) => {
        if (!cancelled) setDepartments(toList(data, 'departments'))
      })
      .catch(() => {
        if (!cancelled) setDepartments([])
      })
    return () => {
      cancelled = true
    }
  }, [open, values.branchId, toList])

  const set = useCallback((patch) => {
    setValues((v) => ({ ...v, ...patch }))
    setFieldErrors({})
    setSubmitError('')
  }, [])

  const toggleRegion = useCallback((label) => {
    setValues((v) => {
      const setR = new Set(v.regions || [])
      if (setR.has(label)) setR.delete(label)
      else setR.add(label)
      return { ...v, regions: Array.from(setR) }
    })
    setFieldErrors({})
  }, [])

  const impact = holidayImpactPreview(values.type)

  async function onSubmit(e) {
    e.preventDefault()
    setSubmitError('')
    const parsed = formSchema.safeParse(values)
    if (!parsed.success) {
      const flat = parsed.error.flatten()
      setFieldErrors({
        ...flat.fieldErrors,
        _form: flat.formErrors,
      })
      return
    }

    const dateStr = parsed.data.date
    const payload = {
      name: parsed.data.name,
      date: dateStr,
      type: parsed.data.type,
      description: parsed.data.description?.trim() || undefined,
      scope: parsed.data.scope,
      company_id: parsed.data.companyId ? Number(parsed.data.companyId) : undefined,
      branch_id: parsed.data.branchId ? Number(parsed.data.branchId) : undefined,
      department_id: parsed.data.departmentId ? Number(parsed.data.departmentId) : undefined,
      employee_id: parsed.data.employeeId ? Number(parsed.data.employeeId) : undefined,
      is_recurring: parsed.data.isRecurring,
      status: parsed.data.status,
      ...(parsed.data.scope === 'regional' ? { regions: parsed.data.regions ?? [] } : {}),
    }

    setSubmitting(true)
    try {
      if (mode === 'edit' && editingId) {
        await updateAdminHoliday(editingId, payload)
        toast.success('Holiday updated', { description: parsed.data.name })
      } else {
        await createAdminHoliday(payload)
        toast.success('Holiday saved', { description: parsed.data.name })
      }
      await onSaved?.()
      onOpenChange(false)
    } catch (err) {
      const msg = err?.message || 'Failed to save'
      setSubmitError(msg)
      toast.error('Could not save holiday', { description: msg })
    } finally {
      setSubmitting(false)
    }
  }

  return (
    <Dialog open={open} onOpenChange={onOpenChange}>
      <DialogContent
        showCloseButton
        className={adminFormDialogContentClass()}
        aria-describedby="holiday-form-desc"
      >
        <div className={ADMIN_FORM_DIALOG_HEADER_WRAP_CLASS}>
          <DialogHeader className={ADMIN_FORM_DIALOG_HEADER_INNER_CLASS}>
            <DialogTitle className={ADMIN_FORM_DIALOG_TITLE_CLASS}>
              {mode === 'edit' ? 'Edit holiday' : 'Add holiday'}
            </DialogTitle>
            <p id="holiday-form-desc" className={ADMIN_FORM_DIALOG_DESC_CLASS}>
              Configure observance, coverage, and status. Reference panel below summarizes DOLE-aligned pay factors.
            </p>
          </DialogHeader>
        </div>

        <TooltipProvider>
          <form onSubmit={onSubmit} className="flex min-h-0 flex-1 flex-col">
            <div className={ADMIN_FORM_DIALOG_BODY_CLASS}>
              <div className="space-y-5">
                {/* Impact preview */}
                <div className="flex flex-wrap items-center gap-2 rounded-lg border border-dashed border-indigo-500/25 bg-indigo-500/[0.04] px-3 py-2 dark:border-teal-500/20 dark:bg-teal-950/20">
                  <Badge
                    variant="outline"
                    className={cn(
                      'text-[11px] font-semibold',
                      impact.tone === 'teal' && 'border-teal-500/40 text-teal-800 dark:text-teal-200',
                      impact.tone === 'amber' && 'border-amber-500/40 text-amber-900 dark:text-amber-100',
                      impact.tone === 'slate' && 'border-slate-500/40 text-slate-800 dark:text-slate-200',
                      impact.tone === 'violet' && 'border-violet-500/40 text-violet-900 dark:text-violet-100',
                      impact.tone === 'muted' && 'text-muted-foreground',
                    )}
                  >
                    Pay impact (reference)
                  </Badge>
                  <span className="text-xs text-muted-foreground">{impact.label}</span>
                </div>

                <div className="space-y-2">
                  <Label htmlFor="h-name" className="text-sm font-medium">
                    Holiday name <span className="text-red-500">*</span>
                  </Label>
                  <Input
                    id="h-name"
                    value={values.name}
                    onChange={(e) => set({ name: e.target.value })}
                    placeholder='e.g. Araw ng Kagitingan'
                    className={cn(fieldErrors.name && 'border-red-500')}
                    autoComplete="off"
                    aria-invalid={!!fieldErrors.name}
                    aria-describedby={fieldErrors.name ? 'err-name' : undefined}
                  />
                  {fieldErrors.name?.[0] && (
                    <p id="err-name" className="text-xs text-red-600 dark:text-red-400">
                      {fieldErrors.name[0]}
                    </p>
                  )}
                </div>

                <div className="space-y-2">
                  <Label className="text-sm font-medium">
                    Date <span className="text-red-500">*</span>
                  </Label>
                  <Popover open={calOpen} onOpenChange={setCalOpen}>
                    <PopoverTrigger asChild>
                      <Button
                        type="button"
                        variant="outline"
                        className={cn(
                          'h-11 w-full justify-start text-left font-normal',
                          !values.date && 'text-muted-foreground',
                          fieldErrors.date && 'border-red-500',
                        )}
                        aria-invalid={!!fieldErrors.date}
                      >
                        <CalendarIcon className="mr-2 size-4 opacity-70" aria-hidden />
                        {selectedDate ? format(selectedDate, 'MMMM d, yyyy') : 'Select date'}
                      </Button>
                    </PopoverTrigger>
                    <PopoverContent className="w-auto p-0" align="start">
                      <Calendar
                        mode="single"
                        selected={selectedDate}
                        onSelect={(d) => {
                          if (d) {
                            set({ date: format(d, 'yyyy-MM-dd') })
                            setCalOpen(false)
                          }
                        }}
                        defaultMonth={selectedDate ?? new Date()}
                      />
                    </PopoverContent>
                  </Popover>
                  {fieldErrors.date?.[0] && <p className="text-xs text-red-600 dark:text-red-400">{fieldErrors.date[0]}</p>}
                </div>

                <div className="space-y-3">
                  <div className="flex items-center gap-2">
                    <Label className="text-sm font-medium">Holiday type</Label>
                    <Tooltip>
                      <TooltipTrigger asChild>
                        <button type="button" className="rounded-full text-muted-foreground hover:text-foreground" aria-label="About holiday types">
                          <Info className="size-4" />
                        </button>
                      </TooltipTrigger>
                      <TooltipContent className="max-w-xs">
                        Regular vs Special vs Special Working follow DOLE proclamations. Company events use internal policy only.
                      </TooltipContent>
                    </Tooltip>
                  </div>
                  <RadioGroup
                    value={values.type}
                    onValueChange={(v) => set({ type: v })}
                    className="grid gap-2 @sm:grid-cols-2"
                    aria-label="Holiday type"
                  >
                    {HOLIDAY_TYPE_OPTIONS.map((opt) => (
                      <label
                        key={opt.value}
                        htmlFor={`ht-${opt.value}`}
                        className={cn(
                          'flex cursor-pointer items-start gap-3 rounded-xl border border-border/60 bg-muted/20 p-3 transition-colors hover:bg-muted/35 dark:bg-muted/10',
                          values.type === opt.value && 'border-indigo-500/50 bg-indigo-500/[0.06] dark:border-teal-500/40 dark:bg-teal-950/25',
                        )}
                      >
                        <RadioGroupItem id={`ht-${opt.value}`} value={opt.value} className="mt-0.5" />
                        <span className="min-w-0">
                          <span className="block text-sm font-semibold leading-tight">{opt.label}</span>
                          <span className="mt-0.5 block text-[11px] text-muted-foreground">{opt.hint}</span>
                        </span>
                      </label>
                    ))}
                  </RadioGroup>
                </div>

                <div className="space-y-2">
                  <Label htmlFor="h-desc" className="text-sm font-medium">
                    Description / remarks <span className="text-muted-foreground">(optional)</span>
                  </Label>
                  <Textarea
                    id="h-desc"
                    value={values.description}
                    onChange={(e) => set({ description: e.target.value })}
                    placeholder="National observance, no work no pay unless declared otherwise"
                    rows={3}
                    className="resize-none text-sm"
                  />
                </div>

                <div className="space-y-3 rounded-xl border border-border/50 bg-muted/15 p-4 dark:bg-muted/10">
                  <Label className="text-sm font-medium">Coverage</Label>
                  <RadioGroup
                    value={values.scope}
                    onValueChange={(v) =>
                      set({
                        scope: v,
                        regions: v === 'regional' ? values.regions : [],
                        companyId: ['company', 'branch', 'department', 'employee'].includes(v) ? values.companyId : '',
                        branchId: ['branch', 'department', 'employee'].includes(v) ? values.branchId : '',
                        departmentId: ['department', 'employee'].includes(v) ? values.departmentId : '',
                        employeeId: v === 'employee' ? values.employeeId : '',
                      })
                    }
                    className="grid gap-2"
                  >
                    <label className="flex cursor-pointer items-center gap-3">
                      <RadioGroupItem value="nationwide" id="sc-nw" />
                      <span className="text-sm">Nationwide</span>
                    </label>
                    <label className="flex cursor-pointer items-center gap-3">
                      <RadioGroupItem value="regional" id="sc-rg" />
                      <span className="text-sm">Regional (select provinces/regions)</span>
                    </label>
                    <label className="flex cursor-pointer items-center gap-3">
                      <RadioGroupItem value="company" id="sc-co" />
                      <span className="text-sm">Company - all branches</span>
                    </label>
                    <label className="flex cursor-pointer items-center gap-3">
                      <RadioGroupItem value="branch" id="sc-br" />
                      <span className="text-sm">Branch</span>
                    </label>
                    <label className="flex cursor-pointer items-center gap-3">
                      <RadioGroupItem value="department" id="sc-de" />
                      <span className="text-sm">Department</span>
                    </label>
                    <label className="flex cursor-pointer items-center gap-3">
                      <RadioGroupItem value="employee" id="sc-em" />
                      <span className="text-sm">Employee only</span>
                    </label>
                  </RadioGroup>

                  {['company', 'branch', 'department', 'employee'].includes(values.scope) && (
                    <div className="grid gap-3 rounded-lg border border-border/40 bg-card/80 p-3 @sm:grid-cols-2">
                      <div className="space-y-1.5">
                        <Label htmlFor="h-company" className="text-xs font-medium text-muted-foreground">
                          Company
                        </Label>
                        <select
                          id="h-company"
                          value={values.companyId}
                          onChange={(e) => set({ companyId: e.target.value, branchId: '', departmentId: '', employeeId: '' })}
                          className={cn(FIELD_SELECT_CLASS, fieldErrors.companyId && 'border-red-500')}
                        >
                          <option value="">Select company</option>
                          {companies.map((company) => (
                            <option key={company.id} value={company.id}>
                              {company.name}
                            </option>
                          ))}
                        </select>
                        {fieldErrors.companyId?.[0] && <p className="text-xs text-red-600 dark:text-red-400">{fieldErrors.companyId[0]}</p>}
                      </div>

                      {['branch', 'department'].includes(values.scope) && (
                        <div className="space-y-1.5">
                          <Label htmlFor="h-branch" className="text-xs font-medium text-muted-foreground">
                            Branch
                          </Label>
                          <select
                            id="h-branch"
                            value={values.branchId}
                            onChange={(e) => set({ branchId: e.target.value, departmentId: '', employeeId: '' })}
                            className={cn(FIELD_SELECT_CLASS, fieldErrors.branchId && 'border-red-500')}
                            disabled={!values.companyId}
                          >
                            <option value="">Select branch</option>
                            {branches.map((branch) => (
                              <option key={branch.id} value={branch.id}>
                                {branch.name}
                              </option>
                            ))}
                          </select>
                          {fieldErrors.branchId?.[0] && <p className="text-xs text-red-600 dark:text-red-400">{fieldErrors.branchId[0]}</p>}
                        </div>
                      )}

                      {values.scope === 'department' && (
                        <div className="space-y-1.5">
                          <Label htmlFor="h-department" className="text-xs font-medium text-muted-foreground">
                            Department
                          </Label>
                          <select
                            id="h-department"
                            value={values.departmentId}
                            onChange={(e) => set({ departmentId: e.target.value, employeeId: '' })}
                            className={cn(FIELD_SELECT_CLASS, fieldErrors.departmentId && 'border-red-500')}
                            disabled={!values.branchId}
                          >
                            <option value="">Select department</option>
                            {departments.map((department) => (
                              <option key={department.id} value={department.id}>
                                {department.name}
                              </option>
                            ))}
                          </select>
                          {fieldErrors.departmentId?.[0] && <p className="text-xs text-red-600 dark:text-red-400">{fieldErrors.departmentId[0]}</p>}
                        </div>
                      )}

                      {values.scope === 'employee' && (
                        <div className="space-y-1.5 @sm:col-span-2">
                          <Label htmlFor="h-employee" className="text-xs font-medium text-muted-foreground">
                            Employee
                          </Label>
                          <select
                            id="h-employee"
                            value={values.employeeId}
                            onChange={(e) => set({ employeeId: e.target.value })}
                            className={cn(FIELD_SELECT_CLASS, fieldErrors.employeeId && 'border-red-500')}
                            disabled={!values.companyId}
                          >
                            <option value="">Select employee</option>
                            {employees.map((employee) => (
                              <option key={employee.id} value={employee.id}>
                                {employee.name || employee.employee_code || `Employee #${employee.id}`}
                              </option>
                            ))}
                          </select>
                          {fieldErrors.employeeId?.[0] && <p className="text-xs text-red-600 dark:text-red-400">{fieldErrors.employeeId[0]}</p>}
                        </div>
                      )}
                    </div>
                  )}

                  {values.scope === 'regional' && (
                    <div className="max-h-48 space-y-2 overflow-y-auto rounded-lg border border-border/40 bg-card/80 p-3">
                      <p className="text-[11px] font-medium text-muted-foreground">Affected regions</p>
                      <div className="grid gap-2 @sm:grid-cols-2">
                        {PH_REGION_OPTIONS.map((r) => (
                          <label key={r} className="flex cursor-pointer items-center gap-2 text-xs">
                            <Checkbox
                              checked={values.regions?.includes(r)}
                              onCheckedChange={() => toggleRegion(r)}
                            />
                            <span className="leading-snug">{r}</span>
                          </label>
                        ))}
                      </div>
                      {fieldErrors.regions?.[0] && (
                        <p className="text-xs text-red-600 dark:text-red-400">{fieldErrors.regions[0]}</p>
                      )}
                    </div>
                  )}
                </div>

                <div className="flex flex-col gap-4 rounded-xl border border-border/50 p-4 @sm:flex-row @sm:items-center @sm:justify-between">
                  <div className="flex items-center gap-3">
                    <Switch
                      id="h-rec"
                      checked={values.isRecurring}
                      onCheckedChange={(c) => set({ isRecurring: c })}
                      aria-label="Recurring yearly"
                    />
                    <div>
                      <Label htmlFor="h-rec" className="text-sm font-medium">
                        Recurring yearly
                      </Label>
                      <p className="text-[11px] text-muted-foreground">Creates the same entry for next year if date is free.</p>
                    </div>
                  </div>
                  <div className="min-w-[10rem] space-y-1">
                    <Label htmlFor="h-status" className="text-sm font-medium">
                      Status
                    </Label>
                    <select
                      id="h-status"
                      value={values.status}
                      onChange={(e) => set({ status: e.target.value })}
                      className={FIELD_SELECT_CLASS}
                      aria-label="Holiday status"
                    >
                      {HOLIDAY_STATUS_OPTIONS.map((s) => (
                        <option key={s.value} value={s.value}>
                          {s.label}
                        </option>
                      ))}
                    </select>
                  </div>
                </div>

                {submitError && (
                  <p className="rounded-md bg-red-50 px-3 py-2 text-sm text-red-700 dark:bg-red-950/40 dark:text-red-300" role="alert">
                    {submitError}
                  </p>
                )}
              </div>

              <div className="mt-8 border-t border-border/50 pt-6">
                <HolidayPayReferenceAccordion />
              </div>
            </div>

            <DialogFooter className={ADMIN_FORM_DIALOG_FOOTER_CLASS}>
              <Button type="button" variant="outline" onClick={() => onOpenChange(false)} disabled={submitting}>
                Cancel
              </Button>
              <Button
                type="submit"
                disabled={submitting}
                className={ADMIN_FORM_DIALOG_PRIMARY_BUTTON_CLASS}
              >
                {submitting ? (
                  <>
                    <Loader2 className="size-4 animate-spin" aria-hidden />
                    Saving…
                  </>
                ) : mode === 'edit' ? (
                  'Save changes'
                ) : (
                  'Save holiday'
                )}
              </Button>
            </DialogFooter>
          </form>
        </TooltipProvider>
      </DialogContent>
    </Dialog>
  )
}
