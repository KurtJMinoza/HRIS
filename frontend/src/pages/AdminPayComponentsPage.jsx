import { useCallback, useEffect, useMemo, useRef, useState } from 'react'
import { Link } from 'react-router-dom'
import { Archive, Calculator, Check, Copy, Pencil, Plus, Search, Trash2, WalletCards } from 'lucide-react'
import { Badge } from '@/components/ui/badge'
import { Button } from '@/components/ui/button'
import { Accordion, AccordionContent, AccordionItem, AccordionTrigger } from '@/components/ui/accordion'
import {
  Dialog,
  DialogContent,
  DialogDescription,
  DialogFooter,
  DialogHeader,
  DialogTitle,
} from '@/components/ui/dialog'
import { RadioGroup, RadioGroupItem } from '@/components/ui/radio-group'
import { Switch } from '@/components/ui/switch'
import {
  Table,
  TableBody,
  TableCell,
  TableHead,
  TableHeader,
  TableRow,
} from '@/components/ui/table'
import { useToast } from '@/components/ui/use-toast'
import { createPayComponent, deletePayComponent, getPayComponents, updatePayComponent } from '@/api'
import { useHrBasePath } from '@/contexts/HrAppPathContext'
import { cn } from '@/lib/utils'
import {
  APP_MODAL_DESCRIPTION_CLASS,
  APP_MODAL_FORM_BODY,
  APP_MODAL_HEADER,
  APP_MODAL_INNER_FLUSH,
  APP_MODAL_OUTLINE_BUTTON_CLASS,
  APP_MODAL_PRIMARY_BUTTON_CLASS,
  APP_MODAL_TITLE_CLASS,
  appModalDialogContentClass,
} from '@/lib/appModalStyles'

const EMPTY_FORM = {
  name: '',
  code: '',
  type: 'earning',
  category: 'Fixed Allowance',
  calculation_type: 'fixed_amount',
  default_value: '0',
  formula: '',
  is_taxable: true,
  contributes_sss: false,
  contributes_philhealth: false,
  contributes_pagibig: false,
  is_proratable: false,
  apply_to_all: false,
  component_type: 'user',
  is_system_protected: false,
  effective_from: '',
  effective_to: '',
  is_active: true,
  is_loan: false,
  is_amortized: false,
  default_term_months: '',
}

const CATEGORY_OPTIONS = [
  'Basic Salary',
  'Fixed Allowance',
  'Variable Allowance',
  'Commission & Incentive',
  'Bonus',
  'Hazard Pay',
  'Project Compensation',
  'Deduction & Adjustment',
]

const DEDUCTION_CATEGORY_OPTIONS = [
  'Loan',
  'Deduction',
  'Government Deduction',
  'Loan Repayment',
  'Penalty & Adjustment',
  'Other Deduction',
]

const FILTERS = [
  { id: 'all', label: 'All' },
  { id: 'system', label: 'System' },
  { id: 'user', label: 'User-defined' },
  { id: 'earning', label: 'Earnings' },
  { id: 'deduction', label: 'Deductions' },
  { id: 'loan', label: 'Loans' },
  { id: 'taxable', label: 'Taxable' },
  { id: 'contributory', label: 'Contributory' },
]

export default function AdminPayComponentsPage() {
  const hrBase = useHrBasePath()
  const { toast } = useToast()
  const [loading, setLoading] = useState(true)
  const [saving, setSaving] = useState(false)
  const [dialogOpen, setDialogOpen] = useState(false)
  const [components, setComponents] = useState([])
  const [query, setQuery] = useState('')
  const [activeFilter, setActiveFilter] = useState('all')
  const [editingId, setEditingId] = useState(null)
  const [form, setForm] = useState(EMPTY_FORM)
  const [codeTouched, setCodeTouched] = useState(false)
  const [deleteDialogOpen, setDeleteDialogOpen] = useState(false)
  const [componentToDelete, setComponentToDelete] = useState(null)
  const [deleteAwaitingForce, setDeleteAwaitingForce] = useState(false)
  const [deleteAssignmentCount, setDeleteAssignmentCount] = useState(0)
  const [deleting, setDeleting] = useState(false)
  const [duplicateWarnings, setDuplicateWarnings] = useState([])
  const dialogBodyRef = useRef(null)

  const loadComponents = useCallback(async () => {
    setLoading(true)
    try {
      const data = await getPayComponents({ all: true })
      setComponents(Array.isArray(data?.components) ? data.components : [])
      setDuplicateWarnings(Array.isArray(data?.duplicate_warnings) ? data.duplicate_warnings : [])
    } catch (error) {
      toast({
        title: 'Pay components',
        description: error.message || 'Failed to load pay components',
        variant: 'destructive',
      })
    } finally {
      setLoading(false)
    }
  }, [toast])

  useEffect(() => {
    loadComponents()
  }, [loadComponents])

  useEffect(() => {
    if (!dialogOpen) return
    const id = window.requestAnimationFrame(() => {
      dialogBodyRef.current?.scrollTo({ top: 0, behavior: 'auto' })
    })
    return () => window.cancelAnimationFrame(id)
  }, [dialogOpen, editingId])

  const filtered = useMemo(() => {
    const needle = query.trim().toLowerCase() 
    return components.filter((item) => {
      const matchesText = !needle || `${item.name} ${item.code} ${item.category} ${item.calculation_type}`.toLowerCase().includes(needle)
      const matchesFilter = (() => {
        if (activeFilter === 'all') return true
        if (activeFilter === 'system' || activeFilter === 'user') return (item.component_type || 'user') === activeFilter
        if (activeFilter === 'earning' || activeFilter === 'deduction') return item.type === activeFilter
        if (activeFilter === 'loan') return item.type === 'deduction' && (item.is_loan || String(item.category || '').toLowerCase() === 'loan')
        if (activeFilter === 'taxable') return Boolean(item.is_taxable)
        if (activeFilter === 'contributory') return Boolean(item.contributes_sss || item.contributes_philhealth || item.contributes_pagibig)
        return true
      })()
      return matchesText && matchesFilter
    })
  }, [activeFilter, components, query])

  const stats = useMemo(() => ([
    { label: 'Total Components', value: components.length },
    { label: 'Taxable Items', value: components.filter((item) => item.is_taxable).length },
    { label: 'Contributory', value: components.filter((item) => item.contributes_sss || item.contributes_philhealth || item.contributes_pagibig).length },
  ]), [components])

  function openCreateDialog() {
    setEditingId(null)
    setForm(EMPTY_FORM)
    setCodeTouched(false)
    setDialogOpen(true)
  }

  function openEditDialog(item) {
    setEditingId(item.id)
    setForm({
      name: item.name || '',
      code: item.code || '',
      type: item.type || 'earning',
      category: item.category || 'Fixed Allowance',
      calculation_type: item.calculation_type || 'fixed_amount',
      default_value: String(item.default_value ?? 0),
      formula: item.formula || '',
      is_taxable: Boolean(item.is_taxable),
      contributes_sss: Boolean(item.contributes_sss),
      contributes_philhealth: Boolean(item.contributes_philhealth),
      contributes_pagibig: Boolean(item.contributes_pagibig),
      is_proratable: Boolean(item.is_proratable),
      apply_to_all: Boolean(item.apply_to_all),
      component_type: item.component_type || 'user',
      is_system_protected: Boolean(item.is_system_protected),
      effective_from: item.effective_from || '',
      effective_to: item.effective_to || '',
      is_active: Boolean(item.is_active),
      is_loan: Boolean(item.is_loan),
      is_amortized: Boolean(item.is_amortized),
      default_term_months: item.default_term_months != null && item.default_term_months !== '' ? String(item.default_term_months) : '',
    })
    setCodeTouched(true)
    setDialogOpen(true)
  }

  function resetFormState() {
    setEditingId(null)
    setForm(EMPTY_FORM)
    setCodeTouched(false)
  }

  function updateForm(patch) {
    setForm((prev) => ({ ...prev, ...patch }))
  }

  function handleNameChange(value) {
    setForm((prev) => {
      const next = { ...prev, name: value }
      const previousGenerated = generateComponentCode(prev.name)
      if (!codeTouched || String(prev.code || '').trim().toUpperCase() === previousGenerated) {
        next.code = generateComponentCode(value)
      }
      return next
    })
  }

  function handleCodeChange(value) {
    setCodeTouched(true)
    updateForm({ code: value.toUpperCase() })
  }

  function handleTypeChange(value) {
    setForm((prev) => {
      const nextCategory = value === 'deduction'
        ? (DEDUCTION_CATEGORY_OPTIONS.includes(prev.category) ? prev.category : 'Deduction')
        : (CATEGORY_OPTIONS.includes(prev.category) ? prev.category : 'Fixed Allowance')

      return {
        ...prev,
        type: value,
        category: nextCategory,
        contributes_sss: value === 'deduction' ? false : prev.contributes_sss,
        contributes_philhealth: value === 'deduction' ? false : prev.contributes_philhealth,
        contributes_pagibig: value === 'deduction' ? false : prev.contributes_pagibig,
        is_loan: value === 'earning' ? false : prev.is_loan,
        is_amortized: value === 'earning' ? false : prev.is_amortized,
        default_term_months: value === 'earning' ? '' : prev.default_term_months,
      }
    })
  }

  async function onSubmit(e) {
    e.preventDefault()
    setSaving(true)
    try {
      const payload = {
        ...form,
        code: form.code.trim().toUpperCase(),
        default_value: Number(form.default_value || 0),
        formula: form.formula || null,
        effective_from: form.effective_from || null,
        effective_to: form.effective_to || null,
        is_loan: form.type === 'deduction' ? Boolean(form.is_loan) : false,
        is_amortized: form.type === 'deduction' ? Boolean(form.is_amortized) : false,
        default_term_months:
          form.type === 'deduction' && form.is_loan && String(form.default_term_months || '').trim() !== ''
            ? Number(form.default_term_months)
            : null,
      }
      const response = editingId
        ? await updatePayComponent(editingId, payload)
        : await createPayComponent(payload)
      toast({
        title: 'Pay components',
        description: response?.message || (editingId ? 'Component updated successfully.' : 'Component created successfully.'),
      })
      setDialogOpen(false)
      resetFormState()
      await loadComponents()
      window.dispatchEvent(new CustomEvent('hr:pay-components-changed'))
    } catch (error) {
      toast({
        title: 'Pay components',
        description: error.message || 'Unable to save pay component',
        variant: 'destructive',
      })
    } finally {
      setSaving(false)
    }
  }

  function requestDelete(item) {
    if (isCoreBasicSalaryComponent(item)) {
      toast({
        title: 'Pay components',
        description: 'The Basic Salary component cannot be deleted. Archive it if you need to disable it.',
        variant: 'destructive',
      })
      return
    }
    setComponentToDelete(item)
    setDeleteAwaitingForce(false)
    setDeleteAssignmentCount(0)
    setDeleteDialogOpen(true)
  }

  async function onDelete(forceUnassign = false) {
    if (!componentToDelete) return
    setDeleting(true)
    try {
      await deletePayComponent(componentToDelete.id, { forceUnassign })
      toast({
        title: 'Pay components',
        description: forceUnassign
          ? 'Component deleted and linked assignments were deactivated.'
          : 'Component removed successfully.',
      })
      setDeleteDialogOpen(false)
      setComponentToDelete(null)
      setDeleteAwaitingForce(false)
      setDeleteAssignmentCount(0)
      await loadComponents()
      window.dispatchEvent(new CustomEvent('hr:pay-components-changed'))
    } catch (error) {
      if (error.status === 409 && error.requires_confirmation && !forceUnassign) {
        setDeleteAwaitingForce(true)
        setDeleteAssignmentCount(Number(error.active_assignment_count) || 0)
      } else {
        toast({
          title: 'Pay components',
          description: error.message || 'Unable to delete component',
          variant: 'destructive',
        })
      }
    } finally {
      setDeleting(false)
    }
  }

  async function onDuplicate(item) {
    if (isCoreBasicSalaryComponent(item)) {
      toast({
        title: 'Pay components',
        description: 'The Basic Salary system component cannot be duplicated.',
        variant: 'destructive',
      })
      return
    }
    try {
      await createPayComponent({
        name: `${item.name} Copy`,
        code: `${String(item.code || '').toUpperCase()}_COPY`,
        type: item.type || 'earning',
        category: item.category || 'Fixed Allowance',
        calculation_type: item.calculation_type || 'fixed_amount',
        default_value: Number(item.default_value || 0),
        formula: item.formula || null,
        is_taxable: Boolean(item.is_taxable),
        contributes_sss: Boolean(item.contributes_sss),
        contributes_philhealth: Boolean(item.contributes_philhealth),
        contributes_pagibig: Boolean(item.contributes_pagibig),
        is_proratable: Boolean(item.is_proratable),
        apply_to_all: false,
        effective_from: item.effective_from || null,
        effective_to: item.effective_to || null,
        is_active: Boolean(item.is_active),
        is_loan: Boolean(item.is_loan),
        is_amortized: Boolean(item.is_amortized),
        default_term_months:
          item.default_term_months != null && item.default_term_months !== '' ? Number(item.default_term_months) : null,
      })
      toast({ title: 'Pay components', description: 'Component duplicated successfully.' })
      await loadComponents()
    } catch (error) {
      toast({
        title: 'Pay components',
        description: error.message || 'Unable to duplicate component',
        variant: 'destructive',
      })
    }
  }

  async function onArchive(item) {
    if (!item.is_active) return
    try {
      await updatePayComponent(item.id, {
        name: item.name,
        code: item.code,
        type: item.type || 'earning',
        category: item.category || 'Fixed Allowance',
        calculation_type: item.calculation_type || 'fixed_amount',
        default_value: Number(item.default_value || 0),
        formula: item.formula || null,
        is_taxable: Boolean(item.is_taxable),
        contributes_sss: Boolean(item.contributes_sss),
        contributes_philhealth: Boolean(item.contributes_philhealth),
        contributes_pagibig: Boolean(item.contributes_pagibig),
        is_proratable: Boolean(item.is_proratable),
        apply_to_all: Boolean(item.apply_to_all),
        effective_from: item.effective_from || null,
        effective_to: item.effective_to || null,
        is_active: false,
        is_loan: Boolean(item.is_loan),
        is_amortized: Boolean(item.is_amortized),
        default_term_months:
          item.default_term_months != null && item.default_term_months !== '' ? Number(item.default_term_months) : null,
      })
      toast({ title: 'Pay components', description: 'Component archived successfully.' })
      await loadComponents()
      window.dispatchEvent(new CustomEvent('hr:pay-components-changed'))
    } catch (error) {
      toast({
        title: 'Pay components',
        description: error.message || 'Unable to archive component',
        variant: 'destructive',
      })
    }
  }

  const isBasicSalaryComponent = useMemo(() => {
    return isBasicSalaryForm(form)
  }, [form])

  const categoryOptions = form.type === 'deduction' ? DEDUCTION_CATEGORY_OPTIONS : CATEGORY_OPTIONS

  return (
    <div className="w-full min-w-0 max-w-none space-y-4 bg-white px-3 py-4 text-[#0A0A0A] sm:space-y-5 sm:px-4 md:px-5 lg:space-y-6 lg:px-6 lg:py-5 3xl:space-y-8 3xl:px-10 3xl:py-6 dark:bg-background dark:text-foreground">
      <section className="rounded-2xl border border-slate-200 bg-white p-6 shadow-sm dark:border-border dark:bg-card">
        <div className="flex flex-col gap-4 lg:flex-row lg:items-start lg:justify-between">
          <div>
            <div className="inline-flex items-center gap-2 rounded-full bg-slate-100 px-3 py-1 text-xs font-medium text-slate-600">
              <WalletCards className="size-3.5" />
              Compensation
            </div>
            <h1 className="hr-page-title mt-3 text-slate-900">Pay Components</h1>
            <p className="mt-2 max-w-2xl text-sm text-slate-600">
              Manage the earning and deduction components used across employee compensation and payroll.
            </p>
          </div>

          <Button type="button" onClick={openCreateDialog} className="rounded-xl bg-slate-900 text-white hover:bg-slate-800">
            <Plus className="mr-2 size-4" />
            New Component
          </Button>
        </div>

        <div className="mt-6 grid gap-3 sm:grid-cols-3">
          {stats.map((card) => (
            <div key={card.label} className="rounded-xl border border-slate-200 bg-slate-50 px-4 py-3">
              <p className="text-xs font-medium uppercase tracking-wide text-slate-500">{card.label}</p>
              <p className="mt-2 text-2xl font-semibold text-slate-900">{card.value}</p>
            </div>
          ))}
        </div>
      </section>

      <section className="rounded-2xl border border-slate-200 bg-white p-5 shadow-sm dark:border-border dark:bg-card">
        <div className="flex flex-col gap-4 lg:flex-row lg:items-center lg:justify-between">
          <div>
            <h2 className="text-lg font-semibold text-slate-900">Component List</h2>
            <p className="mt-1 text-sm text-slate-500">Search and maintain your pay component catalog.</p>
          </div>

          <div className="flex w-full flex-col gap-3 lg:w-auto lg:min-w-[420px] lg:flex-row">
            <label className="relative min-w-0 flex-1">
              <Search className="pointer-events-none absolute left-3 top-1/2 size-4 -translate-y-1/2 text-slate-400" />
              <input
                value={query}
                onChange={(e) => setQuery(e.target.value)}
                className={`${inputClass} pl-10`}
                placeholder="Search component name, code, category"
              />
            </label>
          </div>
        </div>

        {duplicateWarnings.length > 0 ? (
          <div
            className="mt-4 rounded-xl border border-amber-200 bg-amber-50 px-4 py-3 text-sm text-amber-950"
            role="status"
          >
            <p className="font-semibold text-amber-900">Duplicate pay components detected</p>
            <p className="mt-1 text-amber-900/90">
              Multiple catalog rows share the same code or duplicate &quot;Basic Salary&quot; names. Payroll uses a single Basic
              Salary; merge duplicates in the database or contact support. Warnings: {duplicateWarnings.length}.
            </p>
            <ul className="mt-2 list-inside list-disc text-xs text-amber-900/85">
              {duplicateWarnings.map((w, i) => (
                <li key={i}>
                  {w.kind === 'duplicate_basic_salary_name'
                    ? `Duplicate "Basic Salary" name (ids: ${(w.ids || []).join(', ')})`
                    : `Duplicate code ${w.code} (ids: ${(w.ids || []).join(', ')})`}
                </li>
              ))}
            </ul>
          </div>
        ) : null}

        <div className="mt-4 flex flex-wrap gap-2">
          {FILTERS.map((filter) => (
            <button
              key={filter.id}
              type="button"
              onClick={() => setActiveFilter(filter.id)}
              className={`rounded-full px-3 py-2 text-sm transition ${
                activeFilter === filter.id
                  ? 'bg-slate-900 text-white'
                  : 'bg-slate-100 text-slate-600 hover:bg-slate-200'
              }`}
            >
              {filter.label}
            </button>
          ))}
        </div>

        <div className="mt-5 overflow-x-auto">
          <Table className="min-w-[960px]">
            <TableHeader className="[&_tr]:border-b-0">
              <TableRow>
                <TableHead className="min-w-[240px] px-3 py-3 text-xs font-semibold uppercase tracking-wide text-slate-500">Component</TableHead>
                <TableHead className="px-3 py-3 text-xs font-semibold uppercase tracking-wide text-slate-500">Code</TableHead>
                <TableHead className="px-3 py-3 text-xs font-semibold uppercase tracking-wide text-slate-500">Type</TableHead>
                <TableHead className="px-3 py-3 text-xs font-semibold uppercase tracking-wide text-slate-500">Calculation</TableHead>
                <TableHead className="px-3 py-3 text-xs font-semibold uppercase tracking-wide text-slate-500">Taxability</TableHead>
                <TableHead className="px-3 py-3 text-xs font-semibold uppercase tracking-wide text-slate-500">Contributory</TableHead>
                <TableHead className="px-3 py-3 text-xs font-semibold uppercase tracking-wide text-slate-500">Status</TableHead>
                <TableHead className="px-3 py-3 text-right text-xs font-semibold uppercase tracking-wide text-slate-500">Actions</TableHead>
              </TableRow>
            </TableHeader>
            <TableBody>
              {loading ? (
                Array.from({ length: 6 }).map((_, index) => (
                  <TableRow key={`skeleton-${index}`} className="border-b border-slate-100">
                    {Array.from({ length: 8 }).map((__, cellIndex) => (
                      <TableCell key={cellIndex} className="px-3 py-3">
                        <div className="h-4 animate-pulse rounded bg-slate-100" />
                      </TableCell>
                    ))}
                  </TableRow>
                ))
              ) : filtered.length === 0 ? (
                <TableRow>
                  <TableCell colSpan={8} className="px-3 py-12 text-center text-sm text-slate-500">
                    No pay components matched your filters.
                  </TableCell>
                </TableRow>
              ) : (
                filtered.map((item) => (
                  <TableRow key={item.id} className="border-b border-slate-100 transition hover:bg-slate-50/80">
                    <TableCell className="px-3 py-3.5">
                      <div className="flex flex-wrap items-center gap-2">
                        <span className="font-medium text-slate-900">{item.name}</span>
                        {item.type === 'deduction' && (item.is_loan || String(item.category || '').toLowerCase() === 'loan') ? (
                          <Badge className="h-5 bg-amber-100 text-amber-900">Loan</Badge>
                        ) : null}
                        {(item.component_type || 'user') === 'system' || item.is_system_protected ? (
                          <Badge
                            variant="secondary"
                            className="h-5 rounded border border-slate-200 bg-slate-100 px-1.5 text-[10px] font-semibold uppercase tracking-wide text-slate-700"
                          >
                            System
                          </Badge>
                        ) : null}
                        {isCoreBasicSalaryComponent(item) ? (
                          <Badge className="h-5 border border-slate-300 bg-slate-900 px-1.5 text-[10px] font-semibold uppercase tracking-wide text-white hover:bg-slate-900">
                            Core payroll
                          </Badge>
                        ) : null}
                      </div>
                      <div className="mt-1 text-xs text-slate-500">{item.category || 'Uncategorized'}</div>
                      <div className="mt-1 text-[11px] text-slate-500">
                        {(item.component_type || 'user') === 'system' || item.is_system_protected
                          ? 'Integrated with payroll; code is fixed.'
                          : 'User-defined component'}
                      </div>
                      <div className="mt-1.5">
                        <Link
                          to={
                            item.type === 'earning'
                              ? `${hrBase}/compensation/deduction-schedule-settings#earnings`
                              : `${hrBase}/compensation/deduction-schedule-settings`
                          }
                          className="text-[11px] font-medium text-slate-600 underline-offset-2 hover:text-slate-900 hover:underline dark:text-slate-400 dark:hover:text-slate-200"
                        >
                          {item.type === 'earning' ? 'Earnings pay schedule' : 'Deduction pay schedule'}
                        </Link>
                      </div>
                    </TableCell>
                    <TableCell className="px-3 py-3.5">
                      <code className="rounded bg-slate-100 px-2 py-1 text-xs text-slate-700">{item.code}</code>
                    </TableCell>
                    <TableCell className="px-3 py-3.5">
                      <Badge className={item.type === 'deduction' ? 'bg-rose-100 text-rose-700' : 'bg-emerald-100 text-emerald-700'}>
                        {item.type === 'deduction' ? 'Deduction' : 'Earning'}
                      </Badge>
                    </TableCell>
                    <TableCell className="px-3 py-3.5">
                      <div className="flex items-center gap-2 text-slate-700">
                        <Calculator className="size-4 text-slate-400" />
                        <span>{formatCalculationType(item.calculation_type)}</span>
                      </div>
                    </TableCell>
                    <TableCell className="px-3 py-3.5">{item.is_taxable ? 'Taxable' : 'Non-taxable'}</TableCell>
                    <TableCell className="px-3 py-3.5">{describeContributions(item)}</TableCell>
                    <TableCell className="px-3 py-3.5">{item.is_active ? 'Active' : 'Inactive'}</TableCell>
                    <TableCell className="px-3 py-3.5 text-right">
                      <div className="flex justify-end gap-2">
                        <Button
                          type="button"
                          size="sm"
                          variant="ghost"
                          className="rounded-lg border-0 bg-slate-100 px-3 text-slate-600 hover:bg-slate-200 hover:text-slate-900"
                          onClick={() => openEditDialog(item)}
                        >
                          <Pencil className="mr-2 size-4" />
                          Edit
                        </Button>
                        <Button
                          type="button"
                          size="sm"
                          variant="ghost"
                          className="rounded-lg border-0 bg-slate-100 px-3 text-slate-600 hover:bg-slate-200 hover:text-slate-900"
                          onClick={() => onDuplicate(item)}
                          disabled={isCoreBasicSalaryComponent(item)}
                          title={isCoreBasicSalaryComponent(item) ? 'Basic Salary cannot be duplicated' : undefined}
                        >
                          <Copy className="mr-2 size-4" />
                          Duplicate
                        </Button>
                        <Button
                          type="button"
                          size="sm"
                          variant="ghost"
                          className="rounded-lg border-0 bg-slate-100 px-3 text-slate-600 hover:bg-slate-200 hover:text-slate-900"
                          onClick={() => onArchive(item)}
                          disabled={!item.is_active}
                        >
                          <Archive className="mr-2 size-4" />
                          Archive
                        </Button>
                        <Button
                          type="button"
                          size="sm"
                          variant="ghost"
                          className="rounded-lg border-0 bg-rose-50 px-3 text-rose-600 hover:bg-rose-100 hover:text-rose-700"
                          onClick={() => requestDelete(item)}
                          disabled={isCoreBasicSalaryComponent(item)}
                          title={isCoreBasicSalaryComponent(item) ? 'Basic Salary cannot be deleted (archive instead)' : undefined}
                        >
                          <Trash2 className="mr-2 size-4" />
                          Delete
                        </Button>
                      </div>
                    </TableCell>
                  </TableRow>
                ))
              )}
            </TableBody>
          </Table>
        </div>
      </section>

      <Dialog
  open={dialogOpen}
  onOpenChange={(open) => {
    setDialogOpen(open)
    if (!open) resetFormState()
  }}
>
  <DialogContent
    innerClassName={cn(APP_MODAL_INNER_FLUSH, 'min-h-0 overflow-y-hidden')}
    className={appModalDialogContentClass({ size: 'md' })}
  >
    <DialogHeader className={APP_MODAL_HEADER}>
      <div className="flex flex-wrap items-center gap-1.5">
        <Badge className="rounded-md bg-foreground px-2 py-0.5 text-[10px] font-semibold uppercase tracking-wide text-background hover:bg-foreground">
          Payroll
        </Badge>
        <Badge variant="outline" className="rounded-md border-border/60 px-2 py-0.5 text-[10px] font-medium text-muted-foreground">
          Component
        </Badge>
      </div>
      <DialogTitle className={APP_MODAL_TITLE_CLASS}>{editingId ? 'Edit pay component' : 'New pay component'}</DialogTitle>
      <DialogDescription className={APP_MODAL_DESCRIPTION_CLASS}>
        Fixed amount, effective dates, and optional tax, contributions, and assignment rules.
      </DialogDescription>
    </DialogHeader>

    <form onSubmit={onSubmit} className="flex max-h-[min(78vh,820px)] min-h-0 flex-col">
      <div ref={dialogBodyRef} className={cn(APP_MODAL_FORM_BODY, 'space-y-4 bg-muted/15')}>
        <section className="overflow-hidden rounded-xl border border-border/60 bg-card shadow-sm">
          <div className="border-b border-border/50 px-4 py-2.5 sm:px-5">
            <p className="text-[11px] font-semibold uppercase tracking-wide text-muted-foreground">Basic information</p>
            <p className="mt-0.5 text-xs text-muted-foreground">Name, code, type, category, default value, and period.</p>
          </div>

          <div className="space-y-4 p-4 sm:p-5">
            <div className="grid gap-3 sm:grid-cols-2 sm:gap-4">
              <Field label="Component name" hint="Shown in payroll and compensation." required>
                <input
                  value={form.name}
                  onChange={(e) => handleNameChange(e.target.value)}
                  className={inputClass}
                  placeholder="e.g. Rice subsidy"
                  required
                />
              </Field>

              <Field
                label="Code"
                hint={
                  form.is_system_protected
                    ? 'Fixed for payroll integrations (SSS, BIR, basic salary). Name and calculation can still be customized.'
                    : 'Auto-generated; editable.'
                }
                required
              >
                <input
                  value={form.code}
                  onChange={(e) => handleCodeChange(e.target.value)}
                  className={cn(
                    `${inputClass} font-mono text-xs`,
                    form.is_system_protected && 'cursor-not-allowed bg-muted/60 text-muted-foreground',
                  )}
                  placeholder="RICE_SUBSIDY"
                  required
                  readOnly={Boolean(form.is_system_protected)}
                  aria-readonly={Boolean(form.is_system_protected)}
                />
              </Field>
            </div>

            <Field label="Type" required>
              <RadioGroup
                value={form.type}
                onValueChange={handleTypeChange}
                className="grid gap-2 sm:grid-cols-2"
              >
                <RadioCard
                  id="pay-component-type-earning"
                  value="earning"
                  title="Earning"
                  description="Additions to pay"
                />
                <RadioCard
                  id="pay-component-type-deduction"
                  value="deduction"
                  title="Deduction"
                  description="Reductions from pay"
                />
              </RadioGroup>
            </Field>

            <div className="grid gap-3 sm:grid-cols-2 sm:gap-4">
              <Field
                label="Category"
                hint={form.type === 'deduction' ? 'Used for filters and reporting.' : 'Matches how payroll uses this item.'}
              >
                <select
                  value={form.category}
                  onChange={(e) => updateForm({ category: e.target.value })}
                  className={inputClass}
                >
                  {categoryOptions.map((option) => (
                    <option key={option} value={option}>
                      {option}
                    </option>
                  ))}
                </select>
              </Field>

              <Field label="Calculation" hint="Controls how the component amount is computed.">
                <select
                  value={form.calculation_type}
                  onChange={(e) => updateForm({ calculation_type: e.target.value })}
                  className={inputClass}
                >
                  {['fixed_amount', 'percent_basic', 'percent_gross', 'daily_rate', 'hourly', 'formula'].map((value) => (
                    <option key={value} value={value}>
                      {formatCalculationType(value)}
                    </option>
                  ))}
                </select>
              </Field>
            </div>

            {form.calculation_type === 'formula' ? (
              <Field label="Formula" hint="Allowed tokens: BASIC, GROSS, DEFAULT_VALUE, HOURS, HOURLY_RATE, DAILY_RATE.">
                <input
                  value={form.formula}
                  onChange={(e) => updateForm({ formula: e.target.value })}
                  className={inputClass}
                  placeholder="(BASIC * 0.05) + DEFAULT_VALUE"
                />
              </Field>
            ) : null}

            <div className="flex flex-col gap-3 rounded-lg border border-slate-100 bg-slate-50/80 p-3 sm:flex-row sm:items-end sm:justify-between sm:gap-4">
              <div className="min-w-0 flex-1">
                <Field label="Default value" hint="Starting amount when assigned.">
                  <div className="relative">
                    <span className="pointer-events-none absolute left-3 top-1/2 -translate-y-1/2 text-sm font-medium text-slate-500">
                      ₱
                    </span>
                    <input
                      value={form.default_value}
                      onChange={(e) => updateForm({ default_value: e.target.value })}
                      className={`${inputClass} pl-9`}
                      inputMode="decimal"
                      placeholder="0.00"
                    />
                  </div>
                </Field>
              </div>
              <div className="flex shrink-0 items-center justify-between gap-3 rounded-lg border border-slate-200 bg-white px-3 py-2 sm:flex-col sm:items-stretch sm:py-2.5">
                <div className="min-w-0 sm:text-right">
                  <p className="text-xs font-semibold text-slate-800">Active</p>
                  <p className="hidden text-[10px] text-slate-500 sm:block">Available for payroll</p>
                </div>
                <Switch checked={form.is_active} onCheckedChange={(checked) => updateForm({ is_active: checked })} />
              </div>
            </div>

            {form.type === 'deduction' ? (
              <div className="grid gap-3 sm:grid-cols-2">
                <ToggleCard
                  title="Loan pay component"
                  description="Eligible for employee loan requests and payroll loan deductions."
                  checked={Boolean(form.is_loan)}
                  onChange={(checked) =>
                    updateForm({
                      is_loan: checked,
                      is_amortized: checked ? form.is_amortized : false,
                      default_term_months: checked ? form.default_term_months : '',
                      category: checked ? 'Loan' : form.category,
                    })
                  }
                />
                <ToggleCard
                  title="Amortized schedule (Phase 2)"
                  description="Reserved for future amortization rules."
                  checked={Boolean(form.is_amortized)}
                  disabled={!form.is_loan}
                  onChange={(checked) => updateForm({ is_amortized: checked })}
                />
                {form.is_loan ? (
                  <div className="sm:col-span-2">
                    <Field
                      label="Suggested term (months)"
                      hint="Optional. Shown as a hint on loan requests; does not auto-enforce repayment."
                    >
                      <input
                        type="number"
                        min="1"
                        max="600"
                        value={form.default_term_months}
                        onChange={(e) => updateForm({ default_term_months: e.target.value })}
                        className={inputClass}
                        placeholder="e.g. 12"
                      />
                    </Field>
                  </div>
                ) : null}
              </div>
            ) : null}

            <div className="grid gap-3 sm:grid-cols-2 sm:gap-4">
              <Field label="Effective from" hint="Blank = available immediately.">
                <input
                  type="date"
                  value={form.effective_from}
                  onChange={(e) => updateForm({ effective_from: e.target.value })}
                  className={inputClass}
                />
              </Field>

              <Field label="Effective to" hint="Optional end date.">
                <input
                  type="date"
                  value={form.effective_to}
                  onChange={(e) => updateForm({ effective_to: e.target.value })}
                  className={inputClass}
                />
              </Field>
            </div>
          </div>
        </section>

        <section className="overflow-hidden rounded-xl border border-border/60 bg-card shadow-sm">
          <Accordion type="single" collapsible className="w-full">
            <AccordionItem value="advanced" className="border-b-0">
              <AccordionTrigger className="border-b border-border/50 px-4 py-3 hover:no-underline sm:px-5">
                <div className="flex flex-col items-start gap-0.5 text-left">
                  <span className="text-[11px] font-semibold uppercase tracking-wide text-muted-foreground">
                    Advanced settings
                  </span>
                  <span className="text-xs font-normal text-muted-foreground">
                    Tax, pro-ration, and bulk assignment
                  </span>
                </div>
              </AccordionTrigger>

              <AccordionContent className="px-4 pb-4 pt-1 sm:px-5 sm:pb-5">
                <div className="space-y-4">
                  <div className="grid gap-2.5 sm:grid-cols-2">
                    <ToggleCard
                      title="Taxable"
                      description="Include in taxable compensation."
                      checked={form.is_taxable}
                      onChange={(checked) => updateForm({ is_taxable: checked })}
                    />

                    <ToggleCard
                      title="Pro-ratable"
                      description="Adjust with days worked."
                      checked={form.is_proratable}
                      onChange={(checked) => updateForm({ is_proratable: checked })}
                    />
                  </div>

                  <div
                    className={`rounded-lg border p-3 ${
                      isBasicSalaryComponent ? 'border-amber-200 bg-amber-50/90' : 'border-slate-200 bg-slate-50/80'
                    }`}
                  >
                    <div className="flex items-start justify-between gap-3">
                      <div className="min-w-0 space-y-1">
                        <p className="text-sm font-semibold text-slate-900">Apply to all employees</p>
                        <p className="text-xs leading-relaxed text-slate-600">
                          Auto-assign with the default value. You can override per employee later.
                        </p>
                        {isBasicSalaryComponent ? (
                          <p className="text-xs font-medium text-amber-800">
                            Basic Salary must be assigned per employee.
                          </p>
                        ) : null}
                      </div>
                      <Switch
                        checked={!isBasicSalaryComponent && form.apply_to_all}
                        disabled={isBasicSalaryComponent}
                        onCheckedChange={(checked) => updateForm({ apply_to_all: checked })}
                        className="shrink-0"
                      />
                    </div>
                  </div>
                </div>
              </AccordionContent>
            </AccordionItem>
          </Accordion>
        </section>
      </div>

      <DialogFooter className="flex flex-col gap-3 border-t border-border/60 bg-muted/15 px-8 py-6 sm:flex-row sm:items-center sm:justify-between dark:border-border/50">
        <Button type="button" variant="outline" className={APP_MODAL_OUTLINE_BUTTON_CLASS} onClick={() => setDialogOpen(false)}>
          Cancel
        </Button>
        <Button type="submit" disabled={saving} className={APP_MODAL_PRIMARY_BUTTON_CLASS}>
          {saving ? 'Saving...' : editingId ? 'Save changes' : 'Create component'}
        </Button>
      </DialogFooter>
    </form>
  </DialogContent>
</Dialog>

      <Dialog
        open={deleteDialogOpen}
        onOpenChange={(open) => {
          if (!deleting) {
            setDeleteDialogOpen(open)
            if (!open) {
              setComponentToDelete(null)
              setDeleteAwaitingForce(false)
              setDeleteAssignmentCount(0)
            }
          }
        }}
      >
        <DialogContent className={appModalDialogContentClass({ size: 'sm', className: 'max-h-[90vh]' })}>
          <DialogHeader>
            <DialogTitle>{deleteAwaitingForce ? 'Confirm deletion' : 'Delete pay component'}</DialogTitle>
            <DialogDescription>
              {deleteAwaitingForce
                ? `"${componentToDelete?.name || 'This component'}" is linked to ${deleteAssignmentCount} active assignment(s). You can cancel or delete the component and deactivate those assignments.`
                : `Delete pay component "${componentToDelete?.name || 'this component'}"?`}
            </DialogDescription>
          </DialogHeader>

          <div className="rounded-xl border border-border/60 bg-muted/20 px-4 py-3 text-sm text-muted-foreground">
            {deleteAwaitingForce ? (
              <span>
                This component is used by <strong className="text-foreground">{deleteAssignmentCount}</strong> employee record(s). Deleting may affect payroll and payslip
                lines until compensation is updated.
              </span>
            ) : (
              <span>
                This removes the pay component from the master list. If employees still use it, you will be asked to confirm before assignments are deactivated.
              </span>
            )}
          </div>

          <DialogFooter className="mt-2 flex flex-col gap-3 sm:flex-row sm:items-center sm:justify-between">
            <Button
              type="button"
              variant="outline"
              className={APP_MODAL_OUTLINE_BUTTON_CLASS}
              onClick={() => {
                setDeleteDialogOpen(false)
                setComponentToDelete(null)
                setDeleteAwaitingForce(false)
                setDeleteAssignmentCount(0)
              }}
              disabled={deleting}
            >
              Cancel
            </Button>
            <Button
              type="button"
              className="rounded-lg bg-rose-600 text-white hover:bg-rose-700"
              onClick={() => onDelete(deleteAwaitingForce)}
              disabled={deleting}
            >
              {deleting ? 'Deleting...' : deleteAwaitingForce ? 'Delete and deactivate assignments' : 'Delete'}
            </Button>
          </DialogFooter>
        </DialogContent>
      </Dialog>
    </div>
  )
}

function Field({ label, hint, required = false, children }) {
  return (
    <label className="block text-sm font-medium text-slate-700">
      <span className="mb-1.5 block">
        {label}
        {required ? <span className="ml-1 text-rose-500">*</span> : null}
      </span>
      {children}
      {hint ? <span className="mt-1.5 block text-xs font-normal text-slate-500">{hint}</span> : null}
    </label>
  )
}

function RadioCard({ id, value, title, description }) {
  return (
    <label
      htmlFor={id}
      className="flex cursor-pointer items-start gap-2.5 rounded-lg border border-slate-200 bg-white px-3 py-2.5 transition hover:border-slate-300 hover:bg-slate-50/80"
    >
      <RadioGroupItem id={id} value={value} className="mt-0.5 border-slate-400 text-slate-900" />
      <div className="min-w-0">
        <div className="text-sm font-semibold text-slate-900">{title}</div>
        {description ? <div className="mt-0.5 text-[11px] leading-snug text-slate-500">{description}</div> : null}
      </div>
    </label>
  )
}

function ToggleCard({ title, description, checked, onChange, disabled = false }) {
  return (
    <div
      className={`rounded-lg border px-3 py-2.5 transition ${
        disabled ? 'border-slate-200 bg-slate-50 opacity-70' : checked ? 'border-slate-900/20 bg-slate-50' : 'border-slate-200 bg-white'
      }`}
    >
      <div className="flex items-start justify-between gap-3">
        <div className="min-w-0">
          <div className="flex items-center gap-1.5">
            <span className="text-sm font-semibold text-slate-900">{title}</span>
            {checked && !disabled ? <Check className="size-3.5 text-emerald-600" /> : null}
          </div>
          {description ? <p className="mt-0.5 text-[11px] leading-snug text-slate-500">{description}</p> : null}
        </div>
        <Switch checked={checked} onCheckedChange={onChange} disabled={disabled} />
      </div>
    </div>
  )
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

function describeContributions(item) {
  const values = []
  if (item.contributes_sss) values.push('SSS')
  if (item.contributes_philhealth) values.push('PhilHealth')
  if (item.contributes_pagibig) values.push('Pag-IBIG')
  return values.length > 0 ? values.join(', ') : 'None'
}

function isBasicSalaryForm(form) {
  return String(form.code || '').trim().toUpperCase() === 'BASIC_SALARY'
    || String(form.category || '').trim().toLowerCase() === 'basic salary'
    || String(form.name || '').trim().toLowerCase() === 'basic salary'
}

function isCoreBasicSalaryComponent(item) {
  if (!item) return false
  if (item.is_core_basic_salary) return true
  return String(item.code || '').trim().toUpperCase() === 'BASIC_SALARY'
}

function generateComponentCode(name) {
  return String(name || '')
    .trim()
    .toUpperCase()
    .replace(/[^A-Z0-9]+/g, '_')
    .replace(/^_+|_+$/g, '')
    .replace(/_{2,}/g, '_')
}

const inputClass =
  'w-full rounded-lg border border-border/60 bg-background px-3 py-2 text-sm text-foreground outline-none transition focus-visible:border-ring focus-visible:ring-2 focus-visible:ring-ring/20'
