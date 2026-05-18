import { useState, useEffect, useMemo, useCallback } from 'react'
import { z } from 'zod'
import { format, parseISO, isValid } from 'date-fns'
import { ArrowRightLeft, Building2, CalendarIcon, Info, Loader2, Save, Users } from 'lucide-react'
import { toast } from 'sonner'
import { Button } from '@/components/ui/button'
import { Input } from '@/components/ui/input'
import { Label } from '@/components/ui/label'
import { Textarea } from '@/components/ui/textarea'
import { Dialog, DialogContent, DialogFooter, DialogHeader, DialogTitle } from '@/components/ui/dialog'
import { Popover, PopoverContent, PopoverTrigger } from '@/components/ui/popover'
import { Calendar } from '@/components/ui/calendar'
import { RadioGroup, RadioGroupItem } from '@/components/ui/radio-group'
import { Checkbox } from '@/components/ui/checkbox'
import { Badge } from '@/components/ui/badge'
import { Tooltip, TooltipContent, TooltipProvider, TooltipTrigger } from '@/components/ui/tooltip'
import { cn } from '@/lib/utils'
import { compareEmployeesByLastName } from '@/lib/employeeSort'
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
  SWAP_HOLIDAY_TYPE_OPTIONS,
  COVERAGE_TYPE_OPTIONS,
  HOLIDAY_STATUS_OPTIONS,
  holidayImpactPreview,
} from '@/lib/holidayConstants'
import {
  createSwapHoliday,
  updateSwapHoliday,
  companyLogoUrl,
  getBranchDepartments,
  getCompanies,
  getCompanyBranches,
  getEmployees,
  userProfileImageSrc,
} from '@/api'

const formSchema = z
  .object({
    name: z.string().trim().min(1, 'Holiday name is required').max(255),
    date: z.string().regex(/^\d{4}-\d{2}-\d{2}$/, 'Pick a valid date'),
    originalDate: z.string().regex(/^\d{4}-\d{2}-\d{2}$/, 'Pick the original date').or(z.literal('')),
    type: z.enum(['regular', 'special', 'company']),
    description: z.string().max(1000).optional().or(z.literal('')),
    coverageType: z.enum(['company', 'branches', 'departments', 'employees']),
    coverageIds: z.array(z.string()).min(1, 'Select at least one target'),
    status: z.enum(['active', 'inactive', 'draft']),
  })

function emptyForm() {
  return {
    name: '',
    date: '',
    originalDate: '',
    type: 'regular',
    description: '',
    coverageType: 'company',
    coverageIds: [],
    status: 'active',
  }
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
 *   initial: object | null,
 *   onSaved: () => Promise<void> | void,
 * }} props
 */
export function SwapHolidayModal({ open, onOpenChange, mode = 'create', editingId, initial, onSaved }) {
  const [values, setValues] = useState(emptyForm)
  const [fieldErrors, setFieldErrors] = useState({})
  const [submitError, setSubmitError] = useState('')
  const [submitting, setSubmitting] = useState(false)
  const [calOpen, setCalOpen] = useState(false)
  const [origCalOpen, setOrigCalOpen] = useState(false)
  const [companies, setCompanies] = useState([])
  const [branches, setBranches] = useState([])
  const [departments, setDepartments] = useState([])
  const [employees, setEmployees] = useState([])
  const [organizationsLoading, setOrganizationsLoading] = useState(false)

  const selectedDate = useMemo(() => {
    if (!values.date) return undefined
    try { const d = parseISO(values.date); return isValid(d) ? d : undefined } catch { return undefined }
  }, [values.date])

  const selectedOriginalDate = useMemo(() => {
    if (!values.originalDate) return undefined
    try { const d = parseISO(values.originalDate); return isValid(d) ? d : undefined } catch { return undefined }
  }, [values.originalDate])

  useEffect(() => {
    if (!open) return
    setSubmitError('')
    setFieldErrors({})
    if (initial && mode === 'edit') {
      setValues({
        name: initial.name ?? '',
        date: typeof initial.date === 'string' ? initial.date.slice(0, 10) : '',
        originalDate: initial.original_date ? String(initial.original_date).slice(0, 10) : '',
        type: ['regular', 'special', 'company'].includes(initial.type) ? initial.type : 'regular',
        description: initial.description ?? '',
        coverageType: initial.coverage_type || 'company',
        coverageIds: Array.isArray(initial.coverage_ids) ? initial.coverage_ids.map(String) : [],
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
        const live = toList(data, 'companies')
          .filter((c) => c?.id != null)
          .sort((a, b) => String(a.name || '').localeCompare(String(b.name || '')))
        setCompanies(live)
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
    return () => { cancelledRef.current = true }
  }, [open, loadCompanies])

  // Load branches when companies are selected and coverage is branches/departments
  useEffect(() => {
    if (!open || !['branches', 'departments'].includes(values.coverageType)) {
      setBranches([])
      return
    }
    if (companies.length === 0) return
    let cancelled = false
    const companyIds = companies.map((c) => String(c.id))
    Promise.all(companyIds.map((id) => getCompanyBranches(id).catch(() => ({ branches: [] }))))
      .then((results) => {
        if (cancelled) return
        const map = new Map()
        results.flatMap((d) => toList(d, 'branches')).forEach((b) => { if (b?.id != null) map.set(String(b.id), b) })
        setBranches(Array.from(map.values()))
      })
      .catch(() => { if (!cancelled) setBranches([]) })
    return () => { cancelled = true }
  }, [open, values.coverageType, companies, toList])

  // Load departments when branches exist and coverage is departments
  useEffect(() => {
    if (!open || values.coverageType !== 'departments') {
      setDepartments([])
      return
    }
    if (branches.length === 0) return
    let cancelled = false
    Promise.all(branches.map((b) => getBranchDepartments(b.id).catch(() => ({ departments: [] }))))
      .then((results) => {
        if (cancelled) return
        const map = new Map()
        results.flatMap((d) => toList(d, 'departments')).forEach((dept) => { if (dept?.id != null) map.set(String(dept.id), dept) })
        setDepartments(Array.from(map.values()))
      })
      .catch(() => { if (!cancelled) setDepartments([]) })
    return () => { cancelled = true }
  }, [open, values.coverageType, branches, toList])

  // Load employees when coverage is employees
  useEffect(() => {
    if (!open || values.coverageType !== 'employees') {
      setEmployees([])
      return
    }
    if (companies.length === 0) return
    let cancelled = false
    Promise.all(companies.map((c) => getEmployees({ company_id: c.id, per_page: 200, lite: true, fresh: true }).catch(() => ({ employees: [] }))))
      .then((results) => {
        if (cancelled) return
        const map = new Map()
        results.flatMap((d) => toList(d, 'employees')).forEach((emp) => { if (emp?.id != null) map.set(String(emp.id), emp) })
        setEmployees(Array.from(map.values()).sort(compareEmployeesByLastName))
      })
      .catch(() => { if (!cancelled) setEmployees([]) })
    return () => { cancelled = true }
  }, [open, values.coverageType, companies, toList])

  const set = useCallback((patch) => {
    setValues((v) => ({ ...v, ...patch }))
    setFieldErrors({})
    setSubmitError('')
  }, [])

  const toggleCoverageId = useCallback((id) => {
    setValues((v) => {
      const next = new Set(v.coverageIds)
      const key = String(id)
      if (next.has(key)) next.delete(key)
      else next.add(key)
      return { ...v, coverageIds: Array.from(next) }
    })
    setFieldErrors({})
  }, [])

  const selectAllCoverage = useCallback((items) => {
    setValues((v) => ({ ...v, coverageIds: items.map((i) => String(i.id)) }))
    setFieldErrors({})
  }, [])

  const clearCoverage = useCallback(() => {
    setValues((v) => ({ ...v, coverageIds: [] }))
  }, [])

  const coverageItems = useMemo(() => {
    switch (values.coverageType) {
      case 'company': return companies
      case 'branches': return branches
      case 'departments': return departments
      case 'employees': return employees
      default: return []
    }
  }, [values.coverageType, companies, branches, departments, employees])

  const impact = holidayImpactPreview(values.type)

  async function onSubmit(e) {
    e.preventDefault()
    setSubmitError('')
    const parsed = formSchema.safeParse(values)
    if (!parsed.success) {
      const flat = parsed.error.flatten()
      setFieldErrors({ ...flat.fieldErrors, _form: flat.formErrors })
      return
    }

    const payload = {
      name: parsed.data.name,
      date: parsed.data.date,
      original_date: parsed.data.originalDate || undefined,
      type: parsed.data.type,
      coverage_type: parsed.data.coverageType,
      coverage_ids: parsed.data.coverageIds.map(Number),
      description: parsed.data.description?.trim() || undefined,
      status: parsed.data.status,
    }

    setSubmitting(true)
    try {
      if (mode === 'edit' && editingId) {
        await updateSwapHoliday(editingId, payload)
        toast.success('Swap holiday updated', { description: parsed.data.name })
      } else {
        await createSwapHoliday(payload)
        toast.success('Swap holiday created', { description: parsed.data.name })
      }
      await onSaved?.()
      onOpenChange(false)
    } catch (err) {
      const msg = err?.message || 'Failed to save'
      setSubmitError(msg)
      toast.error('Could not save swap holiday', { description: msg })
    } finally {
      setSubmitting(false)
    }
  }

  return (
    <Dialog open={open} onOpenChange={onOpenChange}>
      <DialogContent
        showCloseButton
        className={adminFormDialogContentClass(
          'w-[min(100vw-1.5rem,48rem)] max-w-[min(100vw-1.5rem,48rem)] sm:max-w-[min(100vw-2rem,48rem)]',
          'max-h-[min(94vh,62rem)] rounded-3xl border-border/70 bg-card shadow-[0_28px_90px_rgba(15,23,42,0.26)] dark:border-border/50 dark:bg-card/95'
        )}
        innerClassName="gap-0 overflow-hidden p-0"
        closeButtonClassName="right-5 top-5 size-11 rounded-xl"
        aria-describedby="swap-holiday-form-desc"
      >
        <div className="border-b border-border/60 bg-card px-6 py-5 dark:bg-card/95">
          <DialogHeader className={cn(ADMIN_FORM_DIALOG_HEADER_INNER_CLASS, 'pr-8')}>
            <div className="flex items-start gap-4">
              <div className="flex size-14 shrink-0 items-center justify-center rounded-2xl border border-amber-500/25 bg-amber-500/10 text-amber-600 shadow-sm dark:bg-amber-500/15">
                <ArrowRightLeft className="size-7" aria-hidden />
              </div>
              <div className="min-w-0">
                <DialogTitle className={cn(ADMIN_FORM_DIALOG_TITLE_CLASS, 'text-2xl font-black')}>
                  {mode === 'edit' ? 'Edit Swap Holiday' : 'Create Swap Holiday'}
                </DialogTitle>
                <p id="swap-holiday-form-desc" className={cn(ADMIN_FORM_DIALOG_DESC_CLASS, 'mt-1 max-w-xl')}>
                  Swap a working day into a holiday with targeted coverage. Affects attendance, payroll, and reports for covered employees.
                </p>
              </div>
            </div>
          </DialogHeader>
        </div>

        <TooltipProvider>
          <form onSubmit={onSubmit} className="flex min-h-0 flex-1 flex-col">
            <div className={cn(ADMIN_FORM_DIALOG_BODY_CLASS, 'max-h-none bg-background/35 px-6 py-5 dark:bg-background/20')}>
              {/* Pay impact banner */}
              <div className="mb-5 flex flex-wrap items-center justify-between gap-3 rounded-2xl border border-amber-500/20 bg-amber-500/5 px-4 py-3 dark:border-amber-500/25 dark:bg-amber-500/10">
                <div className="flex min-w-0 items-center gap-3">
                  <div className="flex size-8 shrink-0 items-center justify-center rounded-full bg-amber-500/10 text-amber-600 dark:bg-amber-500/15">
                    <ArrowRightLeft className="size-4" aria-hidden />
                  </div>
                  <Badge variant="outline" className="rounded-full border-amber-500/25 bg-card/60 text-[11px] font-black uppercase tracking-wide text-amber-600">
                    Swap Holiday
                  </Badge>
                  <span className="wrap-break-word text-xs font-medium text-foreground/85">{impact.label}</span>
                </div>
              </div>

              <div className="flex min-h-0 flex-col gap-5">
                {/* Name */}
                <div className="space-y-2">
                  <Label htmlFor="sh-name" className="text-sm font-bold">
                    Holiday name <span className="text-red-500">*</span>
                  </Label>
                  <div className="relative">
                    <ArrowRightLeft className="pointer-events-none absolute left-3 top-1/2 size-4 -translate-y-1/2 text-muted-foreground" aria-hidden />
                    <Input
                      id="sh-name"
                      value={values.name}
                      onChange={(e) => set({ name: e.target.value })}
                      placeholder="e.g. Swap Holiday - Labor Day moved to Monday"
                      className={cn('h-11 rounded-xl border-border/70 bg-card pl-9 shadow-sm dark:bg-card/80', fieldErrors.name && 'border-red-500')}
                      autoComplete="off"
                    />
                  </div>
                  {fieldErrors.name?.[0] && <p className="text-xs text-red-600 dark:text-red-400">{fieldErrors.name[0]}</p>}
                </div>

                {/* Date pickers row */}
                <div className="grid grid-cols-1 gap-4 sm:grid-cols-2">
                  <div className="space-y-2">
                    <Label className="text-sm font-bold">
                      New Holiday Date <span className="text-red-500">*</span>
                    </Label>
                    <Popover open={calOpen} onOpenChange={setCalOpen}>
                      <PopoverTrigger asChild>
                        <Button
                          type="button"
                          variant="outline"
                          className={cn(
                            'h-11 w-full justify-between rounded-xl border-border/70 bg-card text-left font-normal shadow-sm dark:bg-card/80',
                            !values.date && 'text-muted-foreground',
                            fieldErrors.date && 'border-red-500',
                          )}
                        >
                          <span className="flex items-center gap-2">
                            <CalendarIcon className="size-4 opacity-70" aria-hidden />
                            <span className="truncate">{selectedDate ? format(selectedDate, 'MMMM d, yyyy') : 'Select new date'}</span>
                          </span>
                        </Button>
                      </PopoverTrigger>
                      <PopoverContent className="w-auto p-0" align="start">
                        <Calendar
                          mode="single"
                          selected={selectedDate}
                          onSelect={(d) => { if (d) { set({ date: format(d, 'yyyy-MM-dd') }); setCalOpen(false) } }}
                          defaultMonth={selectedDate ?? new Date()}
                        />
                      </PopoverContent>
                    </Popover>
                    {fieldErrors.date?.[0] && <p className="text-xs text-red-600 dark:text-red-400">{fieldErrors.date[0]}</p>}
                  </div>

                  <div className="space-y-2">
                    <Label className="text-sm font-bold">
                      Original Date <span className="font-medium text-muted-foreground">(optional)</span>
                    </Label>
                    <Popover open={origCalOpen} onOpenChange={setOrigCalOpen}>
                      <PopoverTrigger asChild>
                        <Button
                          type="button"
                          variant="outline"
                          className={cn(
                            'h-11 w-full justify-between rounded-xl border-border/70 bg-card text-left font-normal shadow-sm dark:bg-card/80',
                            !values.originalDate && 'text-muted-foreground',
                          )}
                        >
                          <span className="flex items-center gap-2">
                            <CalendarIcon className="size-4 opacity-70" aria-hidden />
                            <span className="truncate">{selectedOriginalDate ? format(selectedOriginalDate, 'MMMM d, yyyy') : 'Original holiday date'}</span>
                          </span>
                        </Button>
                      </PopoverTrigger>
                      <PopoverContent className="w-auto p-0" align="start">
                        <Calendar
                          mode="single"
                          selected={selectedOriginalDate}
                          onSelect={(d) => { if (d) { set({ originalDate: format(d, 'yyyy-MM-dd') }); setOrigCalOpen(false) } }}
                          defaultMonth={selectedOriginalDate ?? new Date()}
                        />
                      </PopoverContent>
                    </Popover>
                    <p className="text-[11px] text-muted-foreground">The date this holiday was originally scheduled</p>
                  </div>
                </div>

                {/* Holiday type */}
                <div className="space-y-3">
                  <div className="flex items-center gap-2">
                    <Label className="text-sm font-bold">Holiday type <span className="text-red-500">*</span></Label>
                    <Tooltip>
                      <TooltipTrigger asChild>
                        <button type="button" className="rounded-full text-muted-foreground hover:text-foreground" aria-label="About types">
                          <Info className="size-4" />
                        </button>
                      </TooltipTrigger>
                      <TooltipContent className="max-w-xs">Determines pay multiplier applied to covered employees on this swapped date.</TooltipContent>
                    </Tooltip>
                  </div>
                  <RadioGroup value={values.type} onValueChange={(v) => set({ type: v })} className="grid gap-2">
                    {SWAP_HOLIDAY_TYPE_OPTIONS.map((opt) => (
                      <label
                        key={opt.value}
                        className={cn(
                          'group flex cursor-pointer items-center gap-3 rounded-2xl border border-border/70 bg-card p-3 shadow-sm transition-all hover:border-brand/35 dark:bg-card/80',
                          values.type === opt.value && 'border-brand/55 bg-brand/4.5 ring-2 ring-brand/10 dark:bg-brand/10',
                        )}
                      >
                        <RadioGroupItem value={opt.value} className="border-muted-foreground text-brand data-[state=checked]:border-brand" />
                        <span className="min-w-0 flex-1">
                          <span className="text-sm font-bold text-foreground">{opt.label}</span>
                          <span className="ml-2 text-[11px] text-muted-foreground">{opt.hint}</span>
                        </span>
                      </label>
                    ))}
                  </RadioGroup>
                </div>

                {/* Coverage type */}
                <div className="space-y-3">
                  <Label className="text-sm font-bold">Coverage <span className="text-red-500">*</span></Label>
                  <RadioGroup
                    value={values.coverageType}
                    onValueChange={(v) => set({ coverageType: v, coverageIds: [] })}
                    className="grid grid-cols-2 gap-2"
                  >
                    {COVERAGE_TYPE_OPTIONS.map((opt) => (
                      <label
                        key={opt.value}
                        className={cn(
                          'flex cursor-pointer items-center gap-2 rounded-xl border border-border/70 bg-card p-3 text-sm shadow-sm transition-colors hover:border-brand/30 dark:bg-card/80',
                          values.coverageType === opt.value && 'border-brand/55 bg-brand/5 ring-2 ring-brand/10',
                        )}
                      >
                        <RadioGroupItem value={opt.value} className="text-brand data-[state=checked]:border-brand" />
                        <span className="min-w-0">
                          <span className="block font-bold text-foreground">{opt.label}</span>
                          <span className="block text-[10px] text-muted-foreground">{opt.desc}</span>
                        </span>
                      </label>
                    ))}
                  </RadioGroup>
                </div>

                {/* Coverage selection */}
                <div className={cn('rounded-2xl border border-border/70 bg-card p-3 shadow-sm dark:bg-card/80', fieldErrors.coverageIds && 'border-red-500')}>
                  <div className="mb-3 flex items-center justify-between">
                    <div className="flex items-center gap-2">
                      <div className="flex size-8 items-center justify-center rounded-xl bg-amber-500/10 text-amber-600">
                        {values.coverageType === 'employees' ? <Users className="size-4" /> : <Building2 className="size-4" />}
                      </div>
                      <div>
                        <p className="text-xs font-bold text-muted-foreground">
                          Select {values.coverageType === 'company' ? 'companies' : values.coverageType}
                        </p>
                        <p className="text-[11px] text-muted-foreground">
                          {values.coverageIds.length} selected · {coverageItems.length} available
                        </p>
                      </div>
                    </div>
                    <div className="flex gap-1.5">
                      <Button type="button" variant="ghost" size="sm" className="h-7 px-2 text-[11px]" onClick={() => selectAllCoverage(coverageItems)}>
                        Select all
                      </Button>
                      <Button type="button" variant="ghost" size="sm" className="h-7 px-2 text-[11px]" onClick={clearCoverage}>
                        Clear
                      </Button>
                    </div>
                  </div>

                  <div className="grid max-h-52 grid-cols-1 gap-1.5 overflow-y-auto rounded-xl border border-border/50 bg-background/40 p-2 dark:bg-background/20">
                    {organizationsLoading && <p className="px-2 py-1.5 text-xs text-muted-foreground">Loading...</p>}
                    {coverageItems.map((item) => (
                      <label
                        key={item.id}
                        className={cn(
                          'flex cursor-pointer items-center gap-3 rounded-xl border border-border/60 bg-card px-3 py-2 text-xs transition-colors hover:border-brand/30 dark:bg-card/80',
                          values.coverageIds.includes(String(item.id)) && 'border-amber-500/50 bg-amber-500/5 ring-1 ring-amber-500/10',
                        )}
                      >
                        <Checkbox
                          checked={values.coverageIds.includes(String(item.id))}
                          onCheckedChange={() => toggleCoverageId(item.id)}
                          className="data-[state=checked]:border-amber-500 data-[state=checked]:bg-amber-500"
                        />
                        <OrganizationLogo item={item} icon={values.coverageType === 'employees' ? Users : Building2} />
                        <span className="min-w-0 leading-snug">
                          <span className="block truncate font-bold text-foreground">{item.name || item.employee_code || `#${item.id}`}</span>
                          <span className="block truncate text-[10px] text-muted-foreground">
                            {item.company_name || item.branch_name || item.employee_code || item.position || ''}
                          </span>
                        </span>
                      </label>
                    ))}
                    {!organizationsLoading && coverageItems.length === 0 && (
                      <p className="px-2 py-3 text-center text-xs text-muted-foreground">
                        {values.coverageType === 'departments' ? 'Load branches first to see departments' : 'No items available'}
                      </p>
                    )}
                  </div>
                  {fieldErrors.coverageIds?.[0] && <p className="mt-1 text-xs text-red-600 dark:text-red-400">{fieldErrors.coverageIds[0]}</p>}
                </div>

                {/* Description */}
                <div className="space-y-2">
                  <Label htmlFor="sh-desc" className="text-sm font-bold">
                    Description <span className="font-medium text-muted-foreground">(optional)</span>
                  </Label>
                  <Textarea
                    id="sh-desc"
                    value={values.description}
                    onChange={(e) => set({ description: e.target.value })}
                    placeholder="Reason for this swap holiday..."
                    rows={3}
                    maxLength={1000}
                    className="resize-none rounded-xl border-border/70 bg-card text-sm shadow-sm dark:bg-card/80"
                  />
                </div>

                {/* Status */}
                <div className="space-y-2">
                  <Label htmlFor="sh-status" className="text-sm font-bold">Status</Label>
                  <select
                    id="sh-status"
                    value={values.status}
                    onChange={(e) => set({ status: e.target.value })}
                    className={cn(FIELD_SELECT_CLASS, 'h-11 rounded-xl bg-card shadow-sm dark:bg-card/80')}
                  >
                    {HOLIDAY_STATUS_OPTIONS.map((s) => (
                      <option key={s.value} value={s.value}>{s.label}</option>
                    ))}
                  </select>
                </div>

                {submitError && (
                  <p className="rounded-xl border border-red-200 bg-red-50 px-3 py-2 text-sm text-red-700 dark:border-red-900/60 dark:bg-red-950/40 dark:text-red-300" role="alert">
                    {submitError}
                  </p>
                )}
              </div>
            </div>

            <DialogFooter className={cn(ADMIN_FORM_DIALOG_FOOTER_CLASS, 'bg-card px-6 dark:bg-card/95')}>
              <Button type="button" variant="outline" className="h-10 min-w-28 rounded-xl" onClick={() => onOpenChange(false)} disabled={submitting}>
                Cancel
              </Button>
              <Button
                type="submit"
                disabled={submitting}
                className={cn(ADMIN_FORM_DIALOG_PRIMARY_BUTTON_CLASS, 'h-10 min-w-40 rounded-xl bg-amber-600 text-white hover:bg-amber-700 dark:bg-amber-600 dark:hover:bg-amber-700')}
              >
                {submitting ? (
                  <><Loader2 className="size-4 animate-spin" aria-hidden /> Saving...</>
                ) : mode === 'edit' ? (
                  <><Save className="size-4" aria-hidden /> Save changes</>
                ) : (
                  <><ArrowRightLeft className="size-4" aria-hidden /> Create Swap Holiday</>
                )}
              </Button>
            </DialogFooter>
          </form>
        </TooltipProvider>
      </DialogContent>
    </Dialog>
  )
}
