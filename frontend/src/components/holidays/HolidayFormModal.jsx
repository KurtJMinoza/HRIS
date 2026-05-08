import { useState, useEffect, useMemo, useCallback } from 'react'
import { z } from 'zod'
import { format, parseISO, isValid } from 'date-fns'
import { BriefcaseBusiness, Building2, CalendarIcon, Gift, Info, Loader2, Megaphone, Save, Users } from 'lucide-react'
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
  ADMIN_FORM_DIALOG_PRIMARY_BUTTON_CLASS,
  ADMIN_FORM_DIALOG_TITLE_CLASS,
  adminFormDialogContentClass,
} from '@/lib/adminFormDialogStyles'
import {
  HOLIDAY_TYPE_OPTIONS,
  HOLIDAY_STATUS_OPTIONS,
  holidayImpactPreview,
} from '@/lib/holidayConstants'
import { HolidayPayReferenceAccordion } from '@/components/holidays/HolidayPayReferenceAccordion'
import {
  createAdminHoliday,
  companyLogoUrl,
  getBranchDepartments,
  getCompanies,
  getCompanyBranches,
  getEmployees,
  updateAdminHoliday,
  userProfileImageSrc,
} from '@/api'

const formSchema = z
  .object({
    name: z.string().trim().min(1, 'Holiday name is required').max(255),
    date: z.string().regex(/^\d{4}-\d{2}-\d{2}$/, 'Pick a valid date'),
    type: z.enum(['regular', 'special', 'special_working', 'company']),
    description: z.string().max(1000).optional().or(z.literal('')),
    scope: z.enum(['company', 'branch', 'department', 'employee']),
    companyIds: z.array(z.string()).default([]),
    branchIds: z.array(z.string()).default([]),
    departmentIds: z.array(z.string()).default([]),
    employeeIds: z.array(z.string()).default([]),
    isRecurring: z.boolean(),
    status: z.enum(['active', 'inactive', 'draft']),
  })
  .superRefine((data, ctx) => {
    if (data.companyIds.length === 0) {
      ctx.addIssue({ code: z.ZodIssueCode.custom, message: 'Select at least one company', path: ['companyIds'] })
    }
    if (data.scope === 'branch' && data.branchIds.length === 0) {
      ctx.addIssue({ code: z.ZodIssueCode.custom, message: 'Select at least one branch', path: ['branchIds'] })
    }
    if (data.scope === 'department' && data.departmentIds.length === 0) {
      ctx.addIssue({ code: z.ZodIssueCode.custom, message: 'Select at least one department', path: ['departmentIds'] })
    }
    if (data.scope === 'employee' && data.employeeIds.length === 0) {
      ctx.addIssue({ code: z.ZodIssueCode.custom, message: 'Select at least one employee', path: ['employeeIds'] })
    }
  })

function emptyForm() {
  return {
    name: '',
    date: '',
    type: 'regular',
    description: '',
    scope: 'company',
    companyIds: [],
    branchIds: [],
    departmentIds: [],
    employeeIds: [],
    isRecurring: false,
    status: 'active',
  }
}

const DEMO_ORG_NAME_PATTERN = /^(company\s+[ab]|acme\s+(corp|group))$/i

function isDemoOrganization(item) {
  return DEMO_ORG_NAME_PATTERN.test(String(item?.name || '').trim())
}

function orgSubtitle(item, fallback) {
  return item?.company_name || item?.branch_name || item?.employee_code || item?.office_location || fallback
}

function OrganizationLogo({ item, icon: Icon = Building2 }) {
  const profile = userProfileImageSrc(item)
  const logo = profile || item?.logo_url || item?.company_logo_url || companyLogoUrl(item)
  return logo ? (
    <img
      src={logo}
      alt=""
      className={cn(
        'size-10 shrink-0 rounded-2xl border border-border/60 bg-background dark:bg-background/40',
        profile ? 'object-cover' : 'object-contain p-1',
      )}
      loading="lazy"
    />
  ) : (
    <span className="flex size-10 shrink-0 items-center justify-center rounded-2xl border border-brand/15 bg-brand/10 text-brand dark:bg-brand/15">
      <Icon className="size-5" aria-hidden />
    </span>
  )
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
  const [organizationsLoading, setOrganizationsLoading] = useState(false)

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
      const scope = ['company', 'branch', 'department', 'employee'].includes(initial.scope) ? initial.scope : 'company'
      setValues({
        name: initial.name ?? '',
        date: typeof initial.date === 'string' ? initial.date.slice(0, 10) : '',
        type: ['regular', 'special', 'special_working', 'company'].includes(initial.type) ? initial.type : 'regular',
        description: initial.description ?? '',
        scope,
        companyIds: Array.isArray(initial.company_ids)
          ? initial.company_ids.map(String)
          : initial.company_id != null ? [String(initial.company_id)] : [],
        branchIds: Array.isArray(initial.branch_ids)
          ? initial.branch_ids.map(String)
          : initial.branch_id != null ? [String(initial.branch_id)] : [],
        departmentIds: Array.isArray(initial.department_ids)
          ? initial.department_ids.map(String)
          : initial.department_id != null ? [String(initial.department_id)] : [],
        employeeIds: Array.isArray(initial.employee_ids)
          ? initial.employee_ids.map(String)
          : initial.employee_id != null ? [String(initial.employee_id)] : [],
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

  const loadCompanies = useCallback(async (cancelledRef = { current: false }) => {
    setOrganizationsLoading(true)
    try {
      const data = await getCompanies({ fresh: true })
      if (!cancelledRef.current) {
        const liveCompanies = toList(data, 'companies')
          .filter((company) => company?.id != null && !isDemoOrganization(company))
          .sort((a, b) => String(a.name || '').localeCompare(String(b.name || '')))
        const liveIds = new Set(liveCompanies.map((company) => String(company.id)))
        setCompanies(liveCompanies)
        setValues((current) => {
          const companyIds = current.companyIds.filter((id) => liveIds.has(String(id)))
          if (companyIds.length === current.companyIds.length) return current
          return { ...current, companyIds, branchIds: [], departmentIds: [], employeeIds: [] }
        })
      }
    } catch {
      if (!cancelledRef.current) setCompanies([])
    } finally {
      if (!cancelledRef.current) setOrganizationsLoading(false)
    }
  }, [toList])

  useEffect(() => {
    if (!open) return
    const cancelledRef = { current: false }
    loadCompanies(cancelledRef)
    return () => {
      cancelledRef.current = true
    }
  }, [open, loadCompanies])

  useEffect(() => {
    if (!open || values.companyIds.length === 0) {
      setBranches([])
      setEmployees([])
      return
    }
    let cancelled = false
    Promise.all(values.companyIds.map((companyId) => getCompanyBranches(companyId).catch(() => ({ branches: [] }))))
      .then((results) => {
        if (cancelled) return
        const map = new Map()
        results.flatMap((data) => toList(data, 'branches')).forEach((branch) => {
          if (branch?.id != null) map.set(String(branch.id), branch)
        })
        setBranches(Array.from(map.values()))
      })
      .catch(() => {
        if (!cancelled) setBranches([])
      })
    Promise.all(values.companyIds.map((companyId) => getEmployees({ company_id: companyId, per_page: 100, lite: true, fresh: true }).catch(() => ({ employees: [] }))))
      .then((results) => {
        if (cancelled) return
        const map = new Map()
        results.flatMap((data) => toList(data, 'employees')).forEach((employee) => {
          if (employee?.id != null) map.set(String(employee.id), employee)
        })
        setEmployees(Array.from(map.values()))
      })
      .catch(() => {
        if (!cancelled) setEmployees([])
      })
    return () => {
      cancelled = true
    }
  }, [open, values.companyIds, toList])

  useEffect(() => {
    if (!open || values.branchIds.length === 0) {
      setDepartments([])
      return
    }
    let cancelled = false
    Promise.all(values.branchIds.map((branchId) => getBranchDepartments(branchId).catch(() => ({ departments: [] }))))
      .then((results) => {
        if (cancelled) return
        const map = new Map()
        results.flatMap((data) => toList(data, 'departments')).forEach((department) => {
          if (department?.id != null) map.set(String(department.id), department)
        })
        setDepartments(Array.from(map.values()))
      })
      .catch(() => {
        if (!cancelled) setDepartments([])
      })
    return () => {
      cancelled = true
    }
  }, [open, values.branchIds, toList])

  const set = useCallback((patch) => {
    setValues((v) => ({ ...v, ...patch }))
    setFieldErrors({})
    setSubmitError('')
  }, [])

  const toggleId = useCallback((field, id) => {
    setValues((v) => {
      const next = new Set(v[field] || [])
      const key = String(id)
      if (next.has(key)) next.delete(key)
      else next.add(key)
      return { ...v, [field]: Array.from(next) }
    })
    setFieldErrors({})
  }, [])

  const impact = holidayImpactPreview(values.type)
  const selectedCompanies = companies.filter((company) => values.companyIds.includes(String(company.id)))
  const selectedBranches = branches.filter((branch) => values.branchIds.includes(String(branch.id)))
  const selectedDepartments = departments.filter((department) => values.departmentIds.includes(String(department.id)))
  const selectedEmployees = employees.filter((employee) => values.employeeIds.includes(String(employee.id)))
  const selectedCoverage =
    values.scope === 'employee'
      ? selectedEmployees
      : values.scope === 'department'
        ? selectedDepartments
        : values.scope === 'branch'
          ? selectedBranches
          : selectedCompanies
  const selectedCoverageLabel = {
    company: 'companies',
    branch: 'branches',
    department: 'departments',
    employee: 'employees',
  }[values.scope]

  const typeIcon = {
    regular: CalendarIcon,
    special: Gift,
    special_working: BriefcaseBusiness,
    company: Megaphone,
  }

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
      company_ids: parsed.data.companyIds.map(Number),
      branch_ids: parsed.data.branchIds.map(Number),
      department_ids: parsed.data.departmentIds.map(Number),
      employee_ids: parsed.data.employeeIds.map(Number),
      company_id: parsed.data.companyIds[0] ? Number(parsed.data.companyIds[0]) : undefined,
      branch_id: parsed.data.branchIds[0] ? Number(parsed.data.branchIds[0]) : undefined,
      department_id: parsed.data.departmentIds[0] ? Number(parsed.data.departmentIds[0]) : undefined,
      employee_id: parsed.data.employeeIds[0] ? Number(parsed.data.employeeIds[0]) : undefined,
      is_recurring: parsed.data.isRecurring,
      status: parsed.data.status,
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
        className={adminFormDialogContentClass(
          'w-[min(100vw-1.5rem,44rem)] max-w-[min(100vw-1.5rem,44rem)] sm:max-w-[min(100vw-2rem,44rem)]',
          'max-h-[min(94vh,58rem)] rounded-3xl border-border/70 bg-card shadow-[0_28px_90px_rgba(15,23,42,0.26)] dark:border-border/50 dark:bg-card/95'
        )}
        innerClassName="gap-0 overflow-hidden p-0"
        closeButtonClassName="right-5 top-5 size-11 rounded-xl"
        aria-describedby="holiday-form-desc"
      >
        <div className="border-b border-border/60 bg-card px-6 py-5 dark:bg-card/95">
          <DialogHeader className={cn(ADMIN_FORM_DIALOG_HEADER_INNER_CLASS, 'pr-8')}>
            <div className="flex items-start gap-4">
              <div className="flex size-14 shrink-0 items-center justify-center rounded-2xl border border-brand/25 bg-brand/10 text-brand shadow-sm dark:bg-brand/15">
                <CalendarIcon className="size-7" aria-hidden />
              </div>
              <div className="min-w-0">
                <DialogTitle className={cn(ADMIN_FORM_DIALOG_TITLE_CLASS, 'text-2xl font-black')}>
                  {mode === 'edit' ? 'Edit Holiday' : 'Add Holiday'}
                </DialogTitle>
                <p id="holiday-form-desc" className={cn(ADMIN_FORM_DIALOG_DESC_CLASS, 'mt-1 max-w-xl')}>
                  Configure observance, coverage, and status. Reference panel summarizes DOLE-aligned pay factors.
                </p>
              </div>
            </div>
          </DialogHeader>
        </div>

        <TooltipProvider>
          <form onSubmit={onSubmit} className="flex min-h-0 flex-1 flex-col">
            <div className={cn(ADMIN_FORM_DIALOG_BODY_CLASS, 'max-h-none bg-background/35 px-6 py-5 dark:bg-background/20')}>
              <div className="mb-5 flex flex-wrap items-center justify-between gap-3 rounded-2xl border border-brand/20 bg-brand/4.5 px-4 py-3 dark:border-brand/25 dark:bg-brand/10">
                <div className="flex min-w-0 items-center gap-3">
                  <div className="flex size-8 shrink-0 items-center justify-center rounded-full bg-brand/10 text-brand dark:bg-brand/15">
                    <Info className="size-4" aria-hidden />
                  </div>
                  <Badge variant="outline" className="rounded-full border-brand/25 bg-card/60 text-[11px] font-black uppercase tracking-wide text-brand">
                    Pay impact (reference)
                  </Badge>
                  <span className="wrap-break-word text-xs font-medium text-foreground/85">{impact.label}</span>
                </div>
                <a
                  href="https://www.dole.gov.ph/"
                  target="_blank"
                  rel="noopener noreferrer"
                  className="text-xs font-bold text-brand hover:underline"
                >
                  View full reference
                </a>
              </div>

              <div className="flex min-h-0 flex-col gap-6">
                <div className="space-y-5">
                  <div className="space-y-2">
                    <Label htmlFor="h-name" className="text-sm font-bold">
                      Holiday name <span className="text-red-500">*</span>
                    </Label>
                    <div className="relative">
                      <CalendarIcon className="pointer-events-none absolute left-3 top-1/2 size-4 -translate-y-1/2 text-muted-foreground" aria-hidden />
                      <Input
                        id="h-name"
                        value={values.name}
                        onChange={(e) => set({ name: e.target.value })}
                        placeholder="e.g. Araw ng Kagitingan"
                        className={cn('h-11 rounded-xl border-border/70 bg-card pl-9 shadow-sm dark:bg-card/80', fieldErrors.name && 'border-red-500')}
                        autoComplete="off"
                        aria-invalid={!!fieldErrors.name}
                        aria-describedby={fieldErrors.name ? 'err-name' : undefined}
                      />
                    </div>
                    {fieldErrors.name?.[0] && (
                      <p id="err-name" className="text-xs text-red-600 dark:text-red-400">
                        {fieldErrors.name[0]}
                      </p>
                    )}
                  </div>

                  <div className="space-y-2">
                    <Label className="text-sm font-bold">
                      Date <span className="text-red-500">*</span>
                    </Label>
                    <Popover open={calOpen} onOpenChange={setCalOpen}>
                      <PopoverTrigger asChild>
                        <Button
                          type="button"
                          variant="outline"
                          className={cn(
                            'h-11 w-full justify-between rounded-xl border-border/70 bg-card text-left font-normal shadow-sm hover:bg-card/90 dark:bg-card/80',
                            !values.date && 'text-muted-foreground',
                            fieldErrors.date && 'border-red-500 ring-2 ring-red-500/10',
                          )}
                          aria-invalid={!!fieldErrors.date}
                        >
                          <span className="flex min-w-0 items-center gap-2">
                            <CalendarIcon className="size-4 shrink-0 opacity-70" aria-hidden />
                            <span className="truncate">{selectedDate ? format(selectedDate, 'MMMM d, yyyy') : 'Select date'}</span>
                          </span>
                          <span className="text-muted-foreground">⌄</span>
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
                      <Label className="text-sm font-bold">
                        Holiday type <span className="text-red-500">*</span>
                      </Label>
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
                    <RadioGroup value={values.type} onValueChange={(v) => set({ type: v })} className="grid gap-3" aria-label="Holiday type">
                      {HOLIDAY_TYPE_OPTIONS.map((opt) => {
                        const Icon = typeIcon[opt.value] || CalendarIcon
                        return (
                          <label
                            key={opt.value}
                            htmlFor={`ht-${opt.value}`}
                            className={cn(
                              'group flex cursor-pointer items-center gap-3 rounded-2xl border border-border/70 bg-card p-4 shadow-sm transition-all hover:-translate-y-0.5 hover:border-brand/35 hover:shadow-md dark:bg-card/80 dark:hover:shadow-none',
                              values.type === opt.value && 'border-brand/55 bg-brand/4.5 ring-2 ring-brand/10 dark:bg-brand/10',
                            )}
                          >
                            <RadioGroupItem id={`ht-${opt.value}`} value={opt.value} className="border-muted-foreground text-brand data-[state=checked]:border-brand" />
                            <div className="flex size-10 shrink-0 items-center justify-center rounded-2xl bg-brand/8 text-brand dark:bg-brand/12">
                              <Icon className="size-5" aria-hidden />
                            </div>
                            <span className="min-w-0 flex-1">
                              <span className="block text-sm font-black leading-tight text-foreground">{opt.label}</span>
                              <span className="mt-1 block text-[11px] leading-relaxed text-muted-foreground">{opt.hint}</span>
                            </span>
                          </label>
                        )
                      })}
                    </RadioGroup>
                  </div>

                  <div className="space-y-2">
                    <Label htmlFor="h-desc" className="text-sm font-bold">
                      Description / remarks <span className="font-medium text-muted-foreground">(optional)</span>
                    </Label>
                    <div className="relative">
                      <Textarea
                        id="h-desc"
                        value={values.description}
                        onChange={(e) => set({ description: e.target.value })}
                        placeholder="Add description or remarks about this holiday..."
                        rows={4}
                        maxLength={300}
                        className="resize-none rounded-xl border-border/70 bg-card pb-8 text-sm shadow-sm dark:bg-card/80"
                      />
                      <span className="absolute bottom-2 right-3 text-[11px] text-muted-foreground">{values.description.length}/300</span>
                    </div>
                  </div>

                  <div className="space-y-3">
                    <Label className="text-sm font-bold">
                      Coverage <span className="text-red-500">*</span>
                    </Label>
                    <RadioGroup
                      value={values.scope}
                      onValueChange={(v) =>
                        set({
                          scope: v,
                          branchIds: ['branch', 'department'].includes(v) ? values.branchIds : [],
                          departmentIds: v === 'department' ? values.departmentIds : [],
                          employeeIds: v === 'employee' ? values.employeeIds : [],
                        })
                      }
                      className="grid gap-2"
                    >
                      {[
                        { value: 'company', label: 'Company - all selected companies' },
                        { value: 'branch', label: 'Selected branches' },
                        { value: 'department', label: 'Selected departments' },
                        { value: 'employee', label: 'Selected employees only' },
                      ].map((scope) => (
                        <label key={scope.value} className="flex w-fit cursor-pointer items-center gap-2 text-sm">
                          <RadioGroupItem value={scope.value} id={`sc-${scope.value}`} className="text-brand data-[state=checked]:border-brand" />
                          <span>{scope.label}</span>
                        </label>
                      ))}
                    </RadioGroup>

                    <div className={cn('rounded-2xl border border-border/70 bg-card p-3 shadow-sm dark:bg-card/80', fieldErrors.companyIds && 'border-red-500')}>
                      <div className="mb-3 flex flex-wrap items-center gap-2">
                        <div className="flex size-8 items-center justify-center rounded-xl bg-brand/10 text-brand">
                          {values.scope === 'employee' ? <Users className="size-4" /> : <Building2 className="size-4" />}
                        </div>
                        <div className="min-w-0 flex-1">
                          <p className="text-xs font-bold text-muted-foreground">
                            Select {values.scope === 'company' ? 'companies' : values.scope === 'branch' ? 'companies and branches' : values.scope === 'department' ? 'companies, branches, and departments' : 'companies and employees'}
                          </p>
                          <p className="text-[11px] text-muted-foreground">
                            Live from Organizations module{companies.length > 0 ? ` · ${companies.length} companies loaded` : ''}
                          </p>
                        </div>
                        <Button
                          type="button"
                          variant="outline"
                          size="sm"
                          className="h-8 rounded-lg px-2.5 text-xs"
                          onClick={() => loadCompanies()}
                          disabled={organizationsLoading}
                        >
                          {organizationsLoading ? 'Refreshing...' : 'Refresh orgs'}
                        </Button>
                      </div>

                      {selectedCoverage.length > 0 && (
                        <div className="mb-3 grid grid-cols-1 gap-2 rounded-xl border border-border/60 bg-muted/25 p-2 dark:bg-muted/15">
                          {selectedCoverage.slice(0, 5).map((item) => (
                            <span key={item.id} className="inline-flex min-w-0 items-center gap-2 rounded-xl border border-border/60 bg-card px-2 py-1.5 text-xs font-semibold text-foreground dark:bg-card/80">
                              <OrganizationLogo item={item} icon={values.scope === 'employee' ? Users : Building2} />
                              <span className="min-w-0">
                                <span className="block truncate">{item.name || item.employee_code || `#${item.id}`}</span>
                                <span className="block truncate text-[10px] font-medium text-muted-foreground">{orgSubtitle(item, selectedCoverageLabel)}</span>
                              </span>
                            </span>
                          ))}
                          {selectedCoverage.length > 5 && <span className="rounded-lg px-2 py-1 text-xs text-muted-foreground">+{selectedCoverage.length - 5} more</span>}
                        </div>
                      )}

                      <div className="grid grid-cols-1 gap-3">
                        <div className="space-y-2">
                          <Label className="text-xs font-bold text-muted-foreground">Companies</Label>
                          <div className="grid max-h-56 grid-cols-1 gap-2 overflow-y-auto rounded-xl border border-border/50 bg-background/40 p-2 dark:bg-background/20">
                            {organizationsLoading && <p className="px-2 py-1.5 text-xs text-muted-foreground">Loading live organizations...</p>}
                            {companies.map((company) => (
                              <label
                                key={company.id}
                                className={cn(
                                  'flex cursor-pointer items-center gap-3 rounded-2xl border border-border/60 bg-card p-3 text-xs shadow-sm transition-colors hover:border-brand/30 hover:bg-brand/5 dark:bg-card/80',
                                  values.companyIds.includes(String(company.id)) && 'border-brand/50 bg-brand/8 ring-2 ring-brand/10'
                                )}
                              >
                                <Checkbox
                                  checked={values.companyIds.includes(String(company.id))}
                                  onCheckedChange={() => {
                                    toggleId('companyIds', company.id)
                                    set({ branchIds: [], departmentIds: [], employeeIds: [] })
                                  }}
                                  className="data-[state=checked]:border-brand data-[state=checked]:bg-brand"
                                />
                                <OrganizationLogo item={company} icon={Building2} />
                                <span className="min-w-0 leading-snug">
                                  <span className="block truncate font-bold text-foreground">{company.name}</span>
                                  <span className="block truncate text-[10px] text-muted-foreground">
                                    {Number(company.branches_count || 0)} branches · {Number(company.departments_count || 0)} departments
                                  </span>
                                </span>
                              </label>
                            ))}
                            {!organizationsLoading && companies.length === 0 && <p className="text-xs text-muted-foreground">No companies available from Organizations.</p>}
                          </div>
                          {fieldErrors.companyIds?.[0] && <p className="text-xs text-red-600 dark:text-red-400">{fieldErrors.companyIds[0]}</p>}
                        </div>

                        {['branch', 'department'].includes(values.scope) && (
                          <div className="space-y-2">
                            <Label className="text-xs font-bold text-muted-foreground">Branches</Label>
                            <div className={cn('grid max-h-56 grid-cols-1 gap-2 overflow-y-auto rounded-xl border border-border/50 bg-background/40 p-2 dark:bg-background/20', fieldErrors.branchIds && 'border-red-500')}>
                              {branches.map((branch) => (
                                <label
                                  key={branch.id}
                                  className={cn(
                                    'flex cursor-pointer items-center gap-3 rounded-2xl border border-border/60 bg-card p-3 text-xs shadow-sm transition-colors hover:border-brand/30 hover:bg-brand/5 dark:bg-card/80',
                                    values.branchIds.includes(String(branch.id)) && 'border-brand/50 bg-brand/8 ring-2 ring-brand/10'
                                  )}
                                >
                                  <Checkbox
                                    checked={values.branchIds.includes(String(branch.id))}
                                    onCheckedChange={() => {
                                      toggleId('branchIds', branch.id)
                                      set({ departmentIds: [], employeeIds: [] })
                                    }}
                                    disabled={values.companyIds.length === 0}
                                    className="data-[state=checked]:border-brand data-[state=checked]:bg-brand"
                                  />
                                  <OrganizationLogo item={branch} icon={Building2} />
                                  <span className="min-w-0 leading-snug">
                                    <span className="block truncate font-bold text-foreground">{branch.name}</span>
                                    <span className="block truncate text-[10px] text-muted-foreground">
                                      {branch.company_name || 'Branch'} · {Number(branch.departments_count || 0)} departments
                                    </span>
                                  </span>
                                </label>
                              ))}
                              {branches.length === 0 && <p className="text-xs text-muted-foreground">Select companies to load branches.</p>}
                            </div>
                            {fieldErrors.branchIds?.[0] && <p className="text-xs text-red-600 dark:text-red-400">{fieldErrors.branchIds[0]}</p>}
                          </div>
                        )}

                        {values.scope === 'department' && (
                          <div className="space-y-2">
                            <Label className="text-xs font-bold text-muted-foreground">Departments</Label>
                            <div className={cn('grid max-h-56 grid-cols-1 gap-2 overflow-y-auto rounded-xl border border-border/50 bg-background/40 p-2 dark:bg-background/20', fieldErrors.departmentIds && 'border-red-500')}>
                              {departments.map((department) => (
                                <label
                                  key={department.id}
                                  className={cn(
                                    'flex cursor-pointer items-center gap-3 rounded-2xl border border-border/60 bg-card p-3 text-xs shadow-sm transition-colors hover:border-brand/30 hover:bg-brand/5 dark:bg-card/80',
                                    values.departmentIds.includes(String(department.id)) && 'border-brand/50 bg-brand/8 ring-2 ring-brand/10'
                                  )}
                                >
                                  <Checkbox
                                    checked={values.departmentIds.includes(String(department.id))}
                                    onCheckedChange={() => toggleId('departmentIds', department.id)}
                                    disabled={values.branchIds.length === 0}
                                    className="data-[state=checked]:border-brand data-[state=checked]:bg-brand"
                                  />
                                  <OrganizationLogo item={department} icon={Users} />
                                  <span className="min-w-0 leading-snug">
                                    <span className="block truncate font-bold text-foreground">{department.name}</span>
                                    <span className="block truncate text-[10px] text-muted-foreground">
                                      {department.branch_name || department.company_name || 'Department'} · {Number(department.employees_count || 0)} employees
                                    </span>
                                  </span>
                                </label>
                              ))}
                              {departments.length === 0 && <p className="text-xs text-muted-foreground">Select branches to load departments.</p>}
                            </div>
                            {fieldErrors.departmentIds?.[0] && <p className="text-xs text-red-600 dark:text-red-400">{fieldErrors.departmentIds[0]}</p>}
                          </div>
                        )}

                        {values.scope === 'employee' && (
                          <div className="space-y-2">
                            <Label className="text-xs font-bold text-muted-foreground">Employees</Label>
                            <div className={cn('grid max-h-56 grid-cols-1 gap-2 overflow-y-auto rounded-xl border border-border/50 bg-background/40 p-2 dark:bg-background/20', fieldErrors.employeeIds && 'border-red-500')}>
                              {employees.map((employee) => (
                                <label
                                  key={employee.id}
                                  className={cn(
                                    'flex cursor-pointer items-center gap-3 rounded-2xl border border-border/60 bg-card p-3 text-xs shadow-sm transition-colors hover:border-brand/30 hover:bg-brand/5 dark:bg-card/80',
                                    values.employeeIds.includes(String(employee.id)) && 'border-brand/50 bg-brand/8 ring-2 ring-brand/10'
                                  )}
                                >
                                  <Checkbox
                                    checked={values.employeeIds.includes(String(employee.id))}
                                    onCheckedChange={() => toggleId('employeeIds', employee.id)}
                                    disabled={values.companyIds.length === 0}
                                    className="data-[state=checked]:border-brand data-[state=checked]:bg-brand"
                                  />
                                  <OrganizationLogo item={employee} icon={Users} />
                                  <span className="min-w-0 leading-snug">
                                    <span className="block truncate font-bold text-foreground">{employee.name || employee.employee_code || `Employee #${employee.id}`}</span>
                                    <span className="block truncate text-[10px] text-muted-foreground">
                                      {employee.employee_code || employee.position || 'Employee'}
                                    </span>
                                  </span>
                                </label>
                              ))}
                              {employees.length === 0 && <p className="text-xs text-muted-foreground">Select companies to load employees.</p>}
                            </div>
                            {fieldErrors.employeeIds?.[0] && <p className="text-xs text-red-600 dark:text-red-400">{fieldErrors.employeeIds[0]}</p>}
                          </div>
                        )}
                      </div>

                      <p className="mt-3 text-[11px] text-muted-foreground">
                        {selectedCoverage.length} selected {selectedCoverageLabel}. Multiple selections create matching scoped rows for payroll resolution.
                      </p>
                    </div>
                  </div>

                  <div className="flex flex-col gap-4">
                    <div className="flex items-center gap-3 rounded-2xl border border-border/70 bg-card p-4 shadow-sm dark:bg-card/80">
                      <Switch id="h-rec" checked={values.isRecurring} onCheckedChange={(c) => set({ isRecurring: c })} aria-label="Recurring yearly" />
                      <div>
                        <Label htmlFor="h-rec" className="text-sm font-bold">Recurring yearly</Label>
                        <p className="text-[11px] text-muted-foreground">Creates the same entry for next year if date is free.</p>
                      </div>
                    </div>
                    <div className="space-y-2">
                      <Label htmlFor="h-status" className="text-sm font-bold">
                        Status <span className="text-red-500">*</span>
                      </Label>
                      <select id="h-status" value={values.status} onChange={(e) => set({ status: e.target.value })} className={cn(FIELD_SELECT_CLASS, 'h-11 rounded-xl bg-card shadow-sm dark:bg-card/80')} aria-label="Holiday status">
                        {HOLIDAY_STATUS_OPTIONS.map((s) => (
                          <option key={s.value} value={s.value}>
                            {s.label}
                          </option>
                        ))}
                      </select>
                    </div>
                  </div>

                  {submitError && (
                    <p className="rounded-xl border border-red-200 bg-red-50 px-3 py-2 text-sm text-red-700 dark:border-red-900/60 dark:bg-red-950/40 dark:text-red-300" role="alert">
                      {submitError}
                    </p>
                  )}
                </div>

                <aside className="min-h-0 w-full rounded-3xl border border-brand/15 bg-linear-to-b from-brand/[0.035] to-transparent p-4 dark:border-brand/20 dark:from-brand/10">
                  <HolidayPayReferenceAccordion />
                </aside>
              </div>
            </div>

            <DialogFooter className={cn(ADMIN_FORM_DIALOG_FOOTER_CLASS, 'bg-card px-6 dark:bg-card/95')}>
              <Button type="button" variant="outline" className="h-10 min-w-28 rounded-xl" onClick={() => onOpenChange(false)} disabled={submitting}>
                Cancel
              </Button>
              <Button
                type="submit"
                disabled={submitting}
                className={cn(ADMIN_FORM_DIALOG_PRIMARY_BUTTON_CLASS, 'h-10 min-w-40 rounded-xl bg-brand text-brand-foreground hover:bg-brand-strong dark:bg-brand dark:hover:bg-brand-strong')}
              >
                {submitting ? (
                  <>
                    <Loader2 className="size-4 animate-spin" aria-hidden />
                    Saving...
                  </>
                ) : mode === 'edit' ? (
                  <>
                    <Save className="size-4" aria-hidden />
                    Save changes
                  </>
                ) : (
                  <>
                    <Save className="size-4" aria-hidden />
                    Save holiday
                  </>
                )}
              </Button>
            </DialogFooter>
          </form>
        </TooltipProvider>
      </DialogContent>
    </Dialog>
  )
}
