import { useCallback, useEffect, useMemo, useRef, useState } from 'react'
import { Link } from 'react-router-dom'
import {
  Archive,
  Calculator,
  Check,
  ChevronLeft,
  ChevronRight,
  Copy,
  Pencil,
  PieChart,
  Plus,
  Search,
  Trash2,
  Users,
  WalletCards,
} from 'lucide-react'
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
import { Tooltip, TooltipContent, TooltipProvider, TooltipTrigger } from '@/components/ui/tooltip'
import { useToast } from '@/components/ui/use-toast'
import { createPayComponent, deletePayComponent, getPayComponents, updatePayComponent } from '@/api'
import { useHrBasePath } from '@/contexts/HrAppPathContext'
import { cn } from '@/lib/utils'
import {
  APP_MODAL_DESCRIPTION_CLASS,
  APP_MODAL_FORM_BODY,
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
  default_hourly_rate: '',
  default_hours: '',
  default_days: '',
  default_percent: '',
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

const CATEGORY_PRESETS = {
  'Basic Salary': {
    description: 'Recurring base monthly pay. One per employee, locked to BASIC_SALARY code.',
    defaults: { is_taxable: true, is_proratable: true, contributes_sss: false, contributes_philhealth: false, contributes_pagibig: false },
    suggestedCalc: 'fixed_amount',
  },
  'Fixed Allowance': {
    description: 'Recurring allowances paid as a fixed peso amount each cycle (e.g. transportation, meal).',
    defaults: { is_taxable: true, is_proratable: true, contributes_sss: false, contributes_philhealth: false, contributes_pagibig: false },
    suggestedCalc: 'fixed_amount',
  },
  'Variable Allowance': {
    description: 'Allowances that change per period (e.g. mobile load, field allowance). Use Daily/Hourly or Formula for variable amounts.',
    defaults: { is_taxable: true, is_proratable: true },
    suggestedCalc: 'daily_rate',
  },
  'Commission & Incentive': {
    description: 'Sales commissions and performance incentives. Typically computed as % of basic, % of gross, or formula.',
    defaults: { is_taxable: true, is_proratable: false },
    suggestedCalc: 'percent_basic',
  },
  Bonus: {
    description: '13th month, mid-year, year-end, performance bonus. PH treats up to ₱90,000/year as non-taxable.',
    defaults: { is_taxable: false, is_proratable: true },
    suggestedCalc: 'percent_basic',
  },
  'Hazard Pay': {
    description: 'Risk premium for hazardous work. Often non-taxable for minimum wage earners.',
    defaults: { is_taxable: true, is_proratable: true },
    suggestedCalc: 'hourly',
  },
  'Project Compensation': {
    description: 'Project-based or output-based pay (per delivery, per milestone). Use Formula or Daily for unit-based.',
    defaults: { is_taxable: true, is_proratable: false },
    suggestedCalc: 'formula',
  },
  'Deduction & Adjustment': {
    description: 'Earning-side adjustments (negative or positive). Use Fixed Amount or Formula.',
    defaults: { is_taxable: true, is_proratable: false },
    suggestedCalc: 'fixed_amount',
  },
  Loan: {
    description: 'Loanable pay component. Eligible for employee loan requests with optional installment schedules.',
    defaults: { is_taxable: false, is_proratable: false, is_loan: true },
    suggestedCalc: 'fixed_amount',
  },
  Deduction: {
    description: 'General deductions configured by HR (e.g. uniform fee, equipment refund).',
    defaults: { is_taxable: false, is_proratable: false },
    suggestedCalc: 'fixed_amount',
  },
  'Government Deduction': {
    description: 'Statutory deductions (SSS, PhilHealth, Pag-IBIG, Withholding Tax). System-managed; do not duplicate.',
    defaults: { is_taxable: false, is_proratable: false },
    suggestedCalc: 'formula',
  },
  'Loan Repayment': {
    description: 'Periodic repayment of an approved loan (auto-managed by Loan module after approval).',
    defaults: { is_taxable: false, is_proratable: false },
    suggestedCalc: 'fixed_amount',
  },
  'Penalty & Adjustment': {
    description: 'Disciplinary or correction deductions (e.g. tardiness penalty, overpayment recovery).',
    defaults: { is_taxable: false, is_proratable: false },
    suggestedCalc: 'fixed_amount',
  },
  'Other Deduction': {
    description: 'Any deduction not covered above (e.g. cooperative dues, voluntary contributions).',
    defaults: { is_taxable: false, is_proratable: false },
    suggestedCalc: 'fixed_amount',
  },
}

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

const PAGE_SIZE_OPTIONS = [10, 25, 50]

export default function AdminPayComponentsPage() {
  const hrBase = useHrBasePath()
  const { toast } = useToast()
  const [loading, setLoading] = useState(true)
  const [saving, setSaving] = useState(false)
  const [dialogOpen, setDialogOpen] = useState(false)
  const [components, setComponents] = useState([])
  const [query, setQuery] = useState('')
  const [activeFilter, setActiveFilter] = useState('all')
  const [page, setPage] = useState(1)
  const [pageSize, setPageSize] = useState(10)
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
    {
      label: 'Total Components',
      value: components.length,
      helper: 'All components',
      icon: 'wallet',
    },
    {
      label: 'Taxable Items',
      value: components.filter((item) => item.is_taxable).length,
      helper: 'Marked as taxable',
      icon: 'taxable',
    },
    {
      label: 'Contributory',
      value: components.filter((item) => item.contributes_sss || item.contributes_philhealth || item.contributes_pagibig).length,
      helper: 'Contributory items',
      icon: 'contributory',
    },
  ]), [components])

  const pageCount = Math.max(1, Math.ceil(filtered.length / pageSize))
  const safePage = Math.min(page, pageCount)
  const pageStart = filtered.length === 0 ? 0 : (safePage - 1) * pageSize + 1
  const pageEnd = Math.min(filtered.length, safePage * pageSize)
  const paginated = useMemo(() => {
    const start = (safePage - 1) * pageSize
    return filtered.slice(start, start + pageSize)
  }, [filtered, pageSize, safePage])

  useEffect(() => {
    setPage(1)
  }, [activeFilter, pageSize, query])

  useEffect(() => {
    if (page > pageCount) {
      setPage(pageCount)
    }
  }, [page, pageCount])

  function openCreateDialog() {
    setEditingId(null)
    setForm(EMPTY_FORM)
    setCodeTouched(false)
    setDialogOpen(true)
  }

  function openEditDialog(item) {
    setEditingId(item.id)
    const meta = item.metadata && typeof item.metadata === 'object' ? item.metadata : {}
    const calc = item.calculation_type || 'fixed_amount'
    setForm({
      name: item.name || '',
      code: item.code || '',
      type: item.type || 'earning',
      category: item.category || 'Fixed Allowance',
      calculation_type: calc,
      default_value: String(item.default_value ?? 0),
      default_hourly_rate: meta.default_hourly_rate != null && meta.default_hourly_rate !== ''
        ? String(meta.default_hourly_rate)
        : (calc === 'hourly' ? String(item.default_value ?? '') : ''),
      default_hours: meta.default_hours != null && meta.default_hours !== '' ? String(meta.default_hours) : '',
      default_days: meta.default_days != null && meta.default_days !== '' ? String(meta.default_days) : '',
      default_percent: meta.default_percent != null && meta.default_percent !== ''
        ? String(meta.default_percent)
        : (calc === 'percent_basic' || calc === 'percent_gross' ? String(item.default_value ?? '') : ''),
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

      const preset = CATEGORY_PRESETS[nextCategory] || {}
      const presetDefaults = preset.defaults || {}

      return {
        ...prev,
        type: value,
        category: nextCategory,
        is_taxable: presetDefaults.is_taxable !== undefined ? presetDefaults.is_taxable : prev.is_taxable,
        is_proratable: presetDefaults.is_proratable !== undefined ? presetDefaults.is_proratable : prev.is_proratable,
        contributes_sss: value === 'deduction' ? false : prev.contributes_sss,
        contributes_philhealth: value === 'deduction' ? false : prev.contributes_philhealth,
        contributes_pagibig: value === 'deduction' ? false : prev.contributes_pagibig,
        is_loan: value === 'earning' ? false : (presetDefaults.is_loan ?? prev.is_loan),
        is_amortized: value === 'earning' ? false : prev.is_amortized,
        default_term_months: value === 'earning' ? '' : prev.default_term_months,
      }
    })
  }

  function handleCategoryChange(category) {
    const preset = CATEGORY_PRESETS[category] || {}
    const presetDefaults = preset.defaults || {}
    setForm((prev) => ({
      ...prev,
      category,
      is_taxable: presetDefaults.is_taxable !== undefined ? presetDefaults.is_taxable : prev.is_taxable,
      is_proratable: presetDefaults.is_proratable !== undefined ? presetDefaults.is_proratable : prev.is_proratable,
      is_loan: prev.type === 'deduction' ? (presetDefaults.is_loan ?? prev.is_loan) : false,
      calculation_type: preset.suggestedCalc && prev.calculation_type === 'fixed_amount'
        ? preset.suggestedCalc
        : prev.calculation_type,
    }))
  }

  function handleCalculationTypeChange(nextCalc) {
    setForm((prev) => {
      const next = { ...prev, calculation_type: nextCalc }
      if (nextCalc === 'percent_basic' || nextCalc === 'percent_gross') {
        if (!prev.default_percent && Number(prev.default_value) > 0) {
          next.default_percent = String(prev.default_value)
        }
      } else if (nextCalc === 'hourly') {
        if (!prev.default_hourly_rate && Number(prev.default_value) > 0) {
          next.default_hourly_rate = String(prev.default_value)
        }
      } else if (nextCalc === 'daily_rate') {
        if (!prev.default_value || Number(prev.default_value) === 0) {
          next.default_value = prev.default_value || '0'
        }
      }
      return next
    })
  }

  async function onSubmit(e) {
    e.preventDefault()
    setSaving(true)
    try {
      const calc = form.calculation_type
      let defaultValueOut = Number(form.default_value || 0)
      if (calc === 'percent_basic' || calc === 'percent_gross') {
        defaultValueOut = Number(form.default_percent || form.default_value || 0)
      } else if (calc === 'hourly') {
        defaultValueOut = Number(form.default_hourly_rate || form.default_value || 0)
      }

      const metadata = {}
      if (calc === 'hourly') {
        if (form.default_hourly_rate !== '' && form.default_hourly_rate !== null) {
          metadata.default_hourly_rate = Number(form.default_hourly_rate)
        }
        if (form.default_hours !== '' && form.default_hours !== null) {
          metadata.default_hours = Number(form.default_hours)
        }
      } else if (calc === 'daily_rate') {
        if (form.default_days !== '' && form.default_days !== null) {
          metadata.default_days = Number(form.default_days)
        }
      } else if (calc === 'percent_basic' || calc === 'percent_gross') {
        if (form.default_percent !== '' && form.default_percent !== null) {
          metadata.default_percent = Number(form.default_percent)
        }
      }

      const isLoanComp = form.type === 'deduction' && Boolean(form.is_loan)
      const termMonthsRaw = String(form.default_term_months || '').trim()
      const termMonthsOut = isLoanComp && termMonthsRaw !== '' ? Number(termMonthsRaw) : null

      const payload = {
        ...form,
        code: form.code.trim().toUpperCase(),
        default_value: defaultValueOut,
        formula: form.formula || null,
        effective_from: form.effective_from || null,
        effective_to: form.effective_to || null,
        is_loan: isLoanComp,
        is_amortized: isLoanComp && termMonthsOut !== null && termMonthsOut > 0,
        default_term_months: termMonthsOut,
        metadata: Object.keys(metadata).length > 0 ? metadata : null,
      }
      delete payload.default_hourly_rate
      delete payload.default_hours
      delete payload.default_days
      delete payload.default_percent

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
    <div className="w-full min-w-0 max-w-none bg-background px-3 py-4 text-foreground sm:px-4 md:px-5 lg:px-6 lg:py-5 3xl:px-10 3xl:py-6">
      <section className="overflow-hidden rounded-[1.5rem] border border-border/70 bg-card shadow-sm">
        <div className="px-5 py-6 sm:px-7 lg:px-8 lg:py-8">
          <div className="flex flex-col gap-5 lg:flex-row lg:items-start lg:justify-between">
            <div className="min-w-0">
              <div className="inline-flex items-center gap-2 rounded-full bg-brand/10 px-3 py-1 text-xs font-semibold text-brand">
                <WalletCards className="size-3.5" aria-hidden />
                Compensation
              </div>
              <h1 className="mt-5 text-3xl font-bold tracking-tight text-foreground md:text-4xl">Pay Components</h1>
              <p className="mt-3 max-w-3xl text-sm leading-6 text-muted-foreground md:text-base">
                Manage the earning and deduction components used across employee compensation and payroll.
              </p>
            </div>

            <Button
              type="button"
              onClick={openCreateDialog}
              className="h-12 shrink-0 rounded-xl bg-brand px-5 font-semibold text-brand-foreground shadow-lg shadow-brand/20 hover:bg-brand-strong"
            >
              <Plus className="mr-2 size-4" aria-hidden />
              New Component
            </Button>
          </div>

          <div className="mt-8 grid gap-4 lg:grid-cols-3">
            {stats.map(({ label, value, helper, icon }) => (
              <div
                key={label}
                className="flex min-h-36 items-center gap-6 rounded-2xl border border-border/70 bg-background/70 px-6 py-5 shadow-sm dark:bg-muted/10"
              >
                <div className="flex size-16 shrink-0 items-center justify-center rounded-2xl border border-brand/15 bg-brand/10 text-brand shadow-inner">
                  {renderStatIcon(icon)}
                </div>
                <div className="min-w-0">
                  <p className="text-sm font-semibold uppercase tracking-wide text-muted-foreground">{label}</p>
                  <p className="mt-2 text-3xl font-bold text-foreground">{value}</p>
                  <p className="mt-2 text-sm text-muted-foreground">{helper}</p>
                </div>
              </div>
            ))}
          </div>
        </div>

        <div className="px-4 pb-5 sm:px-6 lg:px-7 lg:pb-7">
          <section className="rounded-2xl border border-border/70 bg-background/80 p-4 shadow-sm dark:bg-muted/10 sm:p-6">
            <div className="flex flex-col gap-4 xl:flex-row xl:items-center xl:justify-between">
              <div className="min-w-0">
                <h2 className="text-xl font-bold tracking-tight text-foreground">Component List</h2>
                <p className="mt-1 text-sm text-muted-foreground">Search and maintain your pay component catalog.</p>
              </div>

              <label className="relative w-full xl:max-w-md">
                <Search className="pointer-events-none absolute left-4 top-1/2 size-5 -translate-y-1/2 text-muted-foreground" aria-hidden />
                <input
                  value={query}
                  onChange={(e) => setQuery(e.target.value)}
                  className={cn(inputClass, 'h-12 rounded-xl pl-12 shadow-sm')}
                  placeholder="Search component name, code, category"
                />
              </label>
            </div>

            {duplicateWarnings.length > 0 ? (
              <div
                className="mt-5 rounded-xl border border-amber-300/70 bg-amber-50 px-4 py-3 text-sm text-amber-950 dark:border-amber-500/30 dark:bg-amber-500/10 dark:text-amber-100"
                role="status"
              >
                <p className="font-semibold">Duplicate pay components detected</p>
                <p className="mt-1 opacity-90">
                  Multiple catalog rows share the same code or duplicate &quot;Basic Salary&quot; names. Payroll uses a single Basic
                  Salary; merge duplicates in the database or contact support. Warnings: {duplicateWarnings.length}.
                </p>
                <ul className="mt-2 list-inside list-disc text-xs opacity-85">
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

            <div className="mt-5 flex flex-wrap gap-2">
              {FILTERS.map((filter) => (
                <button
                  key={filter.id}
                  type="button"
                  onClick={() => setActiveFilter(filter.id)}
                  className={cn(
                    'h-10 rounded-full border px-4 text-sm font-medium transition focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-ring/25',
                    activeFilter === filter.id
                      ? 'border-brand bg-brand text-brand-foreground shadow-md shadow-brand/20'
                      : 'border-border/70 bg-card text-foreground hover:border-brand/45 hover:bg-brand/5 hover:text-brand',
                  )}
                >
                  {filter.label}
                </button>
              ))}
            </div>

            <div className="mt-6 overflow-x-auto rounded-xl border border-border/60 bg-card">
              <TooltipProvider>
                <Table className="min-w-[1120px]">
                  <TableHeader className="[&_tr]:border-b-0">
                    <TableRow className="bg-muted/35 hover:bg-muted/35">
                      <TableHead className="min-w-[300px] px-5 py-4 text-xs font-bold uppercase tracking-wide text-muted-foreground">Component</TableHead>
                      <TableHead className="px-4 py-4 text-xs font-bold uppercase tracking-wide text-muted-foreground">Code</TableHead>
                      <TableHead className="px-4 py-4 text-xs font-bold uppercase tracking-wide text-muted-foreground">Type</TableHead>
                      <TableHead className="px-4 py-4 text-xs font-bold uppercase tracking-wide text-muted-foreground">Calculation</TableHead>
                      <TableHead className="px-4 py-4 text-xs font-bold uppercase tracking-wide text-muted-foreground">Taxability</TableHead>
                      <TableHead className="px-4 py-4 text-xs font-bold uppercase tracking-wide text-muted-foreground">Contributory</TableHead>
                      <TableHead className="px-4 py-4 text-xs font-bold uppercase tracking-wide text-muted-foreground">Status</TableHead>
                      <TableHead className="px-5 py-4 text-right text-xs font-bold uppercase tracking-wide text-muted-foreground">Actions</TableHead>
                    </TableRow>
                  </TableHeader>
                  <TableBody>
                    {loading ? (
                      Array.from({ length: 6 }).map((_, index) => (
                        <TableRow key={`skeleton-${index}`} className="border-b border-border/60">
                          {Array.from({ length: 8 }).map((__, cellIndex) => (
                            <TableCell key={cellIndex} className="px-4 py-5">
                              <div className="h-4 animate-pulse rounded bg-muted" />
                            </TableCell>
                          ))}
                        </TableRow>
                      ))
                    ) : filtered.length === 0 ? (
                      <TableRow>
                        <TableCell colSpan={8} className="px-5 py-16 text-center text-sm text-muted-foreground">
                          No pay components matched your filters.
                        </TableCell>
                      </TableRow>
                    ) : (
                      paginated.map((item) => (
                        <TableRow key={item.id} className="border-b border-border/60 transition hover:bg-muted/35">
                          <TableCell className="px-5 py-5">
                            <div className="flex items-start gap-4">
                              <div className="mt-0.5 flex size-12 shrink-0 items-center justify-center rounded-full bg-brand/10 text-brand">
                                <WalletCards className="size-5" aria-hidden />
                              </div>
                              <div className="min-w-0">
                                <div className="flex flex-wrap items-center gap-2">
                                  <span className="font-semibold text-foreground">{item.name}</span>
                                  {item.type === 'deduction' && (item.is_loan || String(item.category || '').toLowerCase() === 'loan') ? (
                                    <Badge className="h-5 bg-amber-100 text-amber-900 hover:bg-amber-100 dark:bg-amber-500/15 dark:text-amber-200">Loan</Badge>
                                  ) : null}
                                  {(item.component_type || 'user') === 'system' || item.is_system_protected ? (
                                    <Badge className="h-5 rounded-md bg-brand/10 px-1.5 text-[10px] font-bold uppercase tracking-wide text-brand hover:bg-brand/10">
                                      System
                                    </Badge>
                                  ) : null}
                                  {isCoreBasicSalaryComponent(item) ? (
                                    <Badge className="h-5 rounded-md bg-foreground px-1.5 text-[10px] font-bold uppercase tracking-wide text-background hover:bg-foreground">
                                      Core payroll
                                    </Badge>
                                  ) : null}
                                </div>
                                <p className="mt-2 text-sm text-muted-foreground">{item.category || 'Uncategorized'}</p>
                                <p className="mt-1 text-xs leading-relaxed text-muted-foreground">
                                  {(item.component_type || 'user') === 'system' || item.is_system_protected
                                    ? 'Integrated with payroll; code is fixed.'
                                    : 'User-defined component'}
                                </p>
                                <Link
                                  to={
                                    item.type === 'earning'
                                      ? `${hrBase}/compensation/deduction-schedule-settings#earnings`
                                      : `${hrBase}/compensation/deduction-schedule-settings`
                                  }
                                  className="mt-2 inline-flex text-xs font-medium text-muted-foreground underline-offset-2 hover:text-brand hover:underline"
                                >
                                  {item.type === 'earning' ? 'Earnings pay schedule' : 'Deduction pay schedule'}
                                </Link>
                              </div>
                            </div>
                          </TableCell>
                          <TableCell className="px-4 py-5">
                            <code className="rounded-md bg-muted px-2.5 py-1.5 text-xs font-semibold text-foreground">{item.code}</code>
                          </TableCell>
                          <TableCell className="px-4 py-5">
                            <Badge className={cn(
                              'rounded-md font-semibold',
                              item.type === 'deduction'
                                ? 'bg-rose-100 text-rose-700 hover:bg-rose-100 dark:bg-rose-500/15 dark:text-rose-200'
                                : 'bg-emerald-100 text-emerald-700 hover:bg-emerald-100 dark:bg-emerald-500/15 dark:text-emerald-200',
                            )}>
                              {item.type === 'deduction' ? 'Deduction' : 'Earning'}
                            </Badge>
                          </TableCell>
                          <TableCell className="px-4 py-5">
                            <div className="flex items-center gap-2 text-sm text-foreground">
                              <Calculator className="size-4 text-muted-foreground" aria-hidden />
                              <span>{formatCalculationType(item.calculation_type)}</span>
                            </div>
                          </TableCell>
                          <TableCell className="px-4 py-5 text-sm text-foreground">{item.is_taxable ? 'Taxable' : 'Non-taxable'}</TableCell>
                          <TableCell className="px-4 py-5 text-sm text-foreground">{describeContributions(item)}</TableCell>
                          <TableCell className="px-4 py-5">
                            <span className="inline-flex items-center gap-2 text-sm text-foreground">
                              <span className={cn('size-2 rounded-full', item.is_active ? 'bg-emerald-500' : 'bg-muted-foreground')} aria-hidden />
                              {item.is_active ? 'Active' : 'Inactive'}
                            </span>
                          </TableCell>
                          <TableCell className="px-5 py-5 text-right">
                            <div className="flex justify-end gap-2">
                              <IconAction label="Edit" onClick={() => openEditDialog(item)}>
                                <Pencil className="size-4" aria-hidden />
                              </IconAction>
                              <IconAction
                                label={isCoreBasicSalaryComponent(item) ? 'Basic Salary cannot be duplicated' : 'Duplicate'}
                                onClick={() => onDuplicate(item)}
                                disabled={isCoreBasicSalaryComponent(item)}
                              >
                                <Copy className="size-4" aria-hidden />
                              </IconAction>
                              <IconAction label="Archive" onClick={() => onArchive(item)} disabled={!item.is_active}>
                                <Archive className="size-4" aria-hidden />
                              </IconAction>
                              <IconAction
                                label={isCoreBasicSalaryComponent(item) ? 'Basic Salary cannot be deleted' : 'Delete'}
                                onClick={() => requestDelete(item)}
                                disabled={isCoreBasicSalaryComponent(item)}
                                destructive
                              >
                                <Trash2 className="size-4" aria-hidden />
                              </IconAction>
                            </div>
                          </TableCell>
                        </TableRow>
                      ))
                    )}
                  </TableBody>
                </Table>
              </TooltipProvider>
            </div>

            <div className="mt-5 flex flex-col gap-3 text-sm text-muted-foreground sm:flex-row sm:items-center sm:justify-between">
              <p>
                Showing {pageStart} to {pageEnd} of {filtered.length} component{filtered.length === 1 ? '' : 's'}
              </p>
              <div className="flex flex-wrap items-center gap-3">
                <div className="flex items-center gap-2">
                  <Button
                    type="button"
                    variant="outline"
                    size="icon"
                    className="size-10 rounded-lg border-border/70 bg-card"
                    onClick={() => setPage((prev) => Math.max(1, prev - 1))}
                    disabled={safePage <= 1}
                    aria-label="Previous page"
                  >
                    <ChevronLeft className="size-4" aria-hidden />
                  </Button>
                  <div className="flex size-10 items-center justify-center rounded-lg border border-brand/60 bg-brand/5 text-sm font-semibold text-brand">
                    {safePage}
                  </div>
                  <Button
                    type="button"
                    variant="outline"
                    size="icon"
                    className="size-10 rounded-lg border-border/70 bg-card"
                    onClick={() => setPage((prev) => Math.min(pageCount, prev + 1))}
                    disabled={safePage >= pageCount}
                    aria-label="Next page"
                  >
                    <ChevronRight className="size-4" aria-hidden />
                  </Button>
                </div>
                <select
                  value={pageSize}
                  onChange={(e) => setPageSize(Number(e.target.value))}
                  className="h-10 rounded-lg border border-border/70 bg-card px-3 text-sm font-medium text-foreground outline-none focus-visible:ring-2 focus-visible:ring-ring/25"
                  aria-label="Rows per page"
                >
                  {PAGE_SIZE_OPTIONS.map((option) => (
                    <option key={option} value={option}>
                      {option} / page
                    </option>
                  ))}
                </select>
              </div>
            </div>
          </section>
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
          overlayClassName="bg-black/60 backdrop-blur-[3px]"
          innerClassName={cn(APP_MODAL_INNER_FLUSH, 'min-h-0 overflow-y-hidden')}
          closeButtonClassName="right-4 top-4 border-border/70 bg-background/95 text-foreground hover:bg-muted"
          className={appModalDialogContentClass({
            size: 'md',
            className: 'rounded-[1.75rem] border-border/70',
          })}
        >
          <DialogHeader className="border-b border-border/60 bg-card px-5 py-5 pr-16 sm:px-8 sm:py-6">
            <div className="flex gap-4">
              <div className="hidden size-14 shrink-0 items-center justify-center rounded-full bg-brand/10 text-brand shadow-inner sm:flex">
                <WalletCards className="size-7" aria-hidden />
              </div>
              <div className="min-w-0">
                <div className="mb-2 flex flex-wrap items-center gap-1.5">
                  <Badge className="rounded-md bg-brand/10 px-2 py-0.5 text-[10px] font-semibold uppercase tracking-wide text-brand hover:bg-brand/10">
                    Payroll
                  </Badge>
                  <Badge variant="outline" className="rounded-md border-border/60 px-2 py-0.5 text-[10px] font-medium text-muted-foreground">
                    Component
                  </Badge>
                </div>
                <DialogTitle className={APP_MODAL_TITLE_CLASS}>{editingId ? 'Edit pay component' : 'New pay component'}</DialogTitle>
                <DialogDescription className={APP_MODAL_DESCRIPTION_CLASS}>
                  Configure a fixed amount, % of basic/gross, daily rate, hourly rate, or custom formula. Choose a category to align taxability and contributions.
                </DialogDescription>
              </div>
            </div>
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
                hint={CATEGORY_PRESETS[form.category]?.description || (form.type === 'deduction' ? 'Used for filters and reporting.' : 'Matches how payroll uses this item.')}
              >
                <select
                  value={form.category}
                  onChange={(e) => handleCategoryChange(e.target.value)}
                  className={inputClass}
                >
                  {categoryOptions.map((option) => (
                    <option key={option} value={option}>
                      {option}
                    </option>
                  ))}
                </select>
              </Field>

              <Field label="Calculation" hint={describeCalculationHint(form.calculation_type)}>
                <select
                  value={form.calculation_type}
                  onChange={(e) => handleCalculationTypeChange(e.target.value)}
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

            {/* Calculation-specific inputs */}
            {form.calculation_type === 'formula' ? (
              <Field label="Formula" hint="Allowed tokens: BASIC, GROSS, DEFAULT_VALUE, HOURS, HOURLY_RATE, DAILY_RATE. Operators: + - * / ( ).">
                <input
                  value={form.formula}
                  onChange={(e) => updateForm({ formula: e.target.value })}
                  className={`${inputClass} font-mono text-xs`}
                  placeholder="(BASIC * 0.05) + DEFAULT_VALUE"
                />
              </Field>
            ) : null}

            {form.calculation_type === 'percent_basic' || form.calculation_type === 'percent_gross' ? (
              <Field
                label={form.calculation_type === 'percent_basic' ? 'Percentage of Basic Salary' : 'Percentage of Gross Pay'}
                hint="Enter the rate (e.g. 5 for 5%). Applied per pay period."
              >
                <div className="relative">
                  <input
                    value={form.default_percent}
                    onChange={(e) => updateForm({ default_percent: e.target.value, default_value: e.target.value })}
                    className={`${inputClass} pr-10`}
                    inputMode="decimal"
                    placeholder="0.00"
                  />
                  <span className="pointer-events-none absolute right-3 top-1/2 -translate-y-1/2 text-xs font-semibold text-muted-foreground">%</span>
                </div>
              </Field>
            ) : null}

            {form.calculation_type === 'hourly' ? (
              <div className="grid gap-3 sm:grid-cols-2 sm:gap-4">
                <Field label="Hourly rate" hint="Default rate per hour. Editable per employee on assignment.">
                  <div className="relative">
                    <span className="pointer-events-none absolute left-3 top-1/2 -translate-y-1/2 text-xs font-semibold text-muted-foreground">PHP</span>
                    <input
                      value={form.default_hourly_rate}
                      onChange={(e) => updateForm({ default_hourly_rate: e.target.value, default_value: e.target.value })}
                      className={`${inputClass} pl-12`}
                      inputMode="decimal"
                      placeholder="0.00"
                    />
                  </div>
                </Field>
                <Field label="Default hours per pay period" hint="Used as fallback when no employee-specific hours are set.">
                  <input
                    value={form.default_hours}
                    onChange={(e) => updateForm({ default_hours: e.target.value })}
                    className={inputClass}
                    inputMode="decimal"
                    placeholder="e.g. 40"
                  />
                </Field>
              </div>
            ) : null}

            {form.calculation_type === 'daily_rate' ? (
              <div className="grid gap-3 sm:grid-cols-2 sm:gap-4">
                <Field label="Daily rate" hint="Amount per workday. Final amount = daily rate × days.">
                  <div className="relative">
                    <span className="pointer-events-none absolute left-3 top-1/2 -translate-y-1/2 text-xs font-semibold text-muted-foreground">PHP</span>
                    <input
                      value={form.default_value}
                      onChange={(e) => updateForm({ default_value: e.target.value })}
                      className={`${inputClass} pl-12`}
                      inputMode="decimal"
                      placeholder="0.00"
                    />
                  </div>
                </Field>
                <Field label="Default days per pay period" hint="Fallback days count when not set per employee.">
                  <input
                    value={form.default_days}
                    onChange={(e) => updateForm({ default_days: e.target.value })}
                    className={inputClass}
                    inputMode="decimal"
                    placeholder="e.g. 22"
                  />
                </Field>
              </div>
            ) : null}

            {form.calculation_type === 'fixed_amount' || form.calculation_type === 'formula' ? (
              <div className="flex flex-col gap-3 rounded-xl border border-border/60 bg-background/70 p-3 sm:flex-row sm:items-end sm:justify-between sm:gap-4 dark:bg-muted/10">
                <div className="min-w-0 flex-1">
                  <Field
                    label={form.calculation_type === 'formula' ? 'Default value (DEFAULT_VALUE token)' : 'Default amount'}
                    hint={form.calculation_type === 'formula' ? 'Used in the formula via the DEFAULT_VALUE token.' : 'Starting peso amount when assigned to an employee.'}
                  >
                    <div className="relative">
                      <span className="pointer-events-none absolute left-3 top-1/2 -translate-y-1/2 text-xs font-semibold text-muted-foreground">PHP</span>
                      <input
                        value={form.default_value}
                        onChange={(e) => updateForm({ default_value: e.target.value })}
                        className={`${inputClass} pl-12`}
                        inputMode="decimal"
                        placeholder="0.00"
                      />
                    </div>
                  </Field>
                </div>
                <div className="flex shrink-0 items-center justify-between gap-3 rounded-xl border border-border/60 bg-card px-3 py-2 sm:flex-col sm:items-stretch sm:py-2.5">
                  <div className="min-w-0 sm:text-right">
                    <p className="text-xs font-semibold text-foreground">Active</p>
                    <p className="hidden text-[10px] text-muted-foreground sm:block">Available for payroll</p>
                  </div>
                  <Switch checked={form.is_active} onCheckedChange={(checked) => updateForm({ is_active: checked })} />
                </div>
              </div>
            ) : (
              <div className="flex items-center justify-between rounded-xl border border-border/60 bg-card px-3 py-2.5">
                <div className="min-w-0">
                  <p className="text-xs font-semibold text-foreground">Active</p>
                  <p className="text-[10px] text-muted-foreground">Available for payroll</p>
                </div>
                <Switch checked={form.is_active} onCheckedChange={(checked) => updateForm({ is_active: checked })} />
              </div>
            )}

            {form.type === 'deduction' ? (
              <div className="grid gap-3 sm:grid-cols-1">
                <ToggleCard
                  title="Loan pay component"
                  description="Eligible for employee loan requests and payroll loan deductions. Amortization is enabled automatically when a default term is provided."
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
                {form.is_loan ? (
                  <Field
                    label="Default repayment term (months)"
                    hint="Optional. When set, repayments use an installment schedule on approved loans. Leave blank for one-off deductions."
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
                      description="When enabled, this component is reduced only for unpaid absences. Approved paid leaves, valid attendance, and approved attendance corrections still receive the full amount."
                      checked={form.is_proratable}
                      onChange={(checked) => updateForm({ is_proratable: checked })}
                    />
                  </div>

                  <div
                    className={cn(
                      'rounded-xl border p-3',
                      isBasicSalaryComponent
                        ? 'border-amber-300/70 bg-amber-50/90 dark:border-amber-500/30 dark:bg-amber-500/10'
                        : 'border-border/60 bg-background/70 dark:bg-muted/10',
                    )}
                  >
                    <div className="flex items-start justify-between gap-3">
                      <div className="min-w-0 space-y-1">
                        <p className="text-sm font-semibold text-foreground">Apply to all employees</p>
                        <p className="text-xs leading-relaxed text-muted-foreground">
                          Auto-assign with the default value. You can override per employee later.
                        </p>
                        {isBasicSalaryComponent ? (
                          <p className="text-xs font-medium text-amber-800 dark:text-amber-200">
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

      <DialogFooter className="flex flex-col gap-3 border-t border-border/60 bg-card px-5 py-4 sm:flex-row sm:items-center sm:justify-between sm:px-8">
        <Button type="button" variant="outline" className={APP_MODAL_OUTLINE_BUTTON_CLASS} onClick={() => setDialogOpen(false)}>
          Cancel
        </Button>
        <Button type="submit" disabled={saving} className={cn(APP_MODAL_PRIMARY_BUTTON_CLASS, 'bg-brand text-brand-foreground hover:bg-brand-strong dark:bg-brand dark:text-brand-foreground dark:hover:bg-brand-strong')}>
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
    <label className="block text-sm font-medium text-foreground">
      <span className="mb-1.5 block">
        {label}
        {required ? <span className="ml-1 text-rose-500">*</span> : null}
      </span>
      {children}
      {hint ? <span className="mt-1.5 block text-xs font-normal text-muted-foreground">{hint}</span> : null}
    </label>
  )
}

function IconAction({ label, onClick, disabled = false, destructive = false, children }) {
  return (
    <Tooltip>
      <TooltipTrigger asChild>
        <Button
          type="button"
          size="icon"
          variant="ghost"
          className={cn(
            'size-10 rounded-lg border border-border/70 bg-background text-foreground shadow-sm hover:bg-muted hover:text-foreground disabled:opacity-45',
            destructive && 'border-rose-200/80 bg-rose-50 text-rose-600 hover:bg-rose-100 hover:text-rose-700 dark:border-rose-500/25 dark:bg-rose-500/10 dark:text-rose-300 dark:hover:bg-rose-500/15',
          )}
          onClick={onClick}
          disabled={disabled}
          aria-label={label}
        >
          {children}
        </Button>
      </TooltipTrigger>
      <TooltipContent>{label}</TooltipContent>
    </Tooltip>
  )
}

function RadioCard({ id, value, title, description }) {
  return (
    <label
      htmlFor={id}
      className="flex cursor-pointer items-start gap-2.5 rounded-xl border border-border/60 bg-background px-3 py-2.5 transition hover:border-brand/40 hover:bg-brand/5 dark:bg-muted/10"
    >
      <RadioGroupItem id={id} value={value} className="mt-0.5 border-muted-foreground text-brand" />
      <div className="min-w-0">
        <div className="text-sm font-semibold text-foreground">{title}</div>
        {description ? <div className="mt-0.5 text-[11px] leading-snug text-muted-foreground">{description}</div> : null}
      </div>
    </label>
  )
}

function ToggleCard({ title, description, checked, onChange, disabled = false }) {
  return (
    <div
      className={cn(
        'rounded-xl border px-3 py-2.5 transition',
        disabled
          ? 'border-border/60 bg-muted/30 opacity-70'
          : checked
            ? 'border-brand/30 bg-brand/5'
            : 'border-border/60 bg-background dark:bg-muted/10',
      )}
    >
      <div className="flex items-start justify-between gap-3">
        <div className="min-w-0">
          <div className="flex items-center gap-1.5">
            <span className="text-sm font-semibold text-foreground">{title}</span>
            {checked && !disabled ? <Check className="size-3.5 text-emerald-600" /> : null}
          </div>
          {description ? <p className="mt-0.5 text-[11px] leading-snug text-muted-foreground">{description}</p> : null}
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

function describeCalculationHint(calc) {
  switch (calc) {
    case 'fixed_amount':
      return 'A flat peso amount applied each pay period.'
    case 'percent_basic':
      return 'Computed as a percentage of the basic salary.'
    case 'percent_gross':
      return 'Computed as a percentage of total gross earnings (basic + other earnings).'
    case 'daily_rate':
      return 'Daily rate × days worked. Used for per-diem, project days, holiday work.'
    case 'hourly':
      return 'Hourly rate × hours. Useful for overtime-like premiums or hourly-paid roles.'
    case 'formula':
      return 'Custom expression using BASIC, GROSS, DEFAULT_VALUE, HOURS, HOURLY_RATE, DAILY_RATE.'
    default:
      return 'Controls how the component amount is computed.'
  }
}

function renderStatIcon(icon) {
  if (icon === 'taxable') return <PieChart className="size-8" aria-hidden />
  if (icon === 'contributory') return <Users className="size-8" aria-hidden />
  return <WalletCards className="size-8" aria-hidden />
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
  'w-full rounded-xl border border-border/60 bg-background px-3 py-2.5 text-sm text-foreground shadow-sm outline-none transition placeholder:text-muted-foreground/75 focus-visible:border-brand/60 focus-visible:ring-2 focus-visible:ring-brand/20 dark:bg-muted/10'
