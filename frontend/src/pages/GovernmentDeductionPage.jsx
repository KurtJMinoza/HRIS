import { useCallback, useEffect, useMemo, useRef, useState } from 'react'
import {
  Calendar,
  CheckCircle2,
  ChevronRight,
  Clock3,
  FileClock,
  HeartPulse,
  History,
  Landmark,
  PiggyBank,
  ReceiptText,
  RefreshCw,
  Save,
  Scale,
  Search,
  ShieldCheck,
  X,
} from 'lucide-react'
import { Button } from '@/components/ui/button'
import {
  calculateStatutoryContributions,
  generateStatutoryRemittance,
  getStatutoryDashboardSummary,
  getStatutoryRateHistory,
  getStatutoryRates,
  getStoredUser,
  getTaxTables,
  listGovernmentDeductionExemptions,
  listStatutoryRemittances,
  previewWithholdingTax,
  updateEmployeeGovernmentDeductionExemption,
  upsertStatutoryRate,
} from '@/api'
import { cn } from '@/lib/utils'

const RATE_CODES = ['SSS', 'PHILHEALTH', 'PAGIBIG', 'EC']
const TABS = [
  { id: 'SSS', label: 'SSS' },
  { id: 'PHILHEALTH', label: 'PhilHealth' },
  { id: 'PAGIBIG', label: 'Pag-IBIG (HDMF)' },
  { id: 'WHT', label: 'Withholding Tax' },
  { id: 'EMPLOYEE_EXEMPTIONS', label: 'Employee Exemptions' },
  { id: 'AUDIT', label: 'Compliance Audit' },
]

const EXEMPTION_TYPES = [
  ['exempt_sss', 'SSS'],
  ['exempt_philhealth', 'PhilHealth'],
  ['exempt_pagibig', 'Pag-IBIG'],
  ['exempt_withholding_tax', 'Withholding Tax'],
]

const EMPTY_EXEMPTION_FORM = {
  exempt_sss: false,
  exempt_philhealth: false,
  exempt_pagibig: false,
  exempt_withholding_tax: false,
  exempt_all_government_deductions: false,
  exemption_reason: '',
  applies_to_regular_payroll: true,
  applies_to_execom_payroll: true,
  is_active: true,
}

const DEFAULT_ROWS = {
  SSS: {
    code: 'SSS',
    name: 'Social Security System (SSS)',
    employee_rate: 0.05,
    employer_rate: 0.1,
    min_salary: 5000,
    max_salary: 35000,
    compliance_reference: 'SSS Circular No. 2024-006',
  },
  PHILHEALTH: {
    code: 'PHILHEALTH',
    name: 'PhilHealth',
    employee_rate: 0.025,
    employer_rate: 0.025,
    salary_floor: 10000,
    salary_ceiling: 100000,
    compliance_reference: 'RA 11223',
  },
  PAGIBIG: {
    code: 'PAGIBIG',
    name: 'Pag-IBIG (HDMF)',
    employee_rate: 0.02,
    employer_rate: 0.02,
    tier_threshold: 1500,
    monthly_cap: 10000,
    metadata: { employee_rate_lower: 0.01, employee_rate_upper: 0.02 },
    compliance_reference: 'RA 9679',
  },
  EC: {
    code: 'EC',
    name: "Employees' Compensation (EC)",
    employee_rate: 0,
    employer_rate: 0,
    min_salary: 10,
    max_salary: 30,
    compliance_reference: 'Employer-only EC',
  },
}

const FALLBACK_TRAIN_ROWS = [
  ['0.00 - 250,000.00', '0%', 'No tax', 'Effective January 1, 2023 onward'],
  ['250,001.00 - 400,000.00', '15%', '15% of excess over 250,000.00', 'TRAIN table'],
  ['400,001.00 - 800,000.00', '20%', '22,500.00 + 20% of excess over 400,000.00', 'TRAIN table'],
  ['800,001.00 - 2,000,000.00', '25%', '102,500.00 + 25% of excess over 800,000.00', 'TRAIN table'],
  ['2,000,001.00 - 8,000,000.00', '30%', '402,500.00 + 30% of excess over 2,000,000.00', 'TRAIN table'],
  ['Over 8,000,000.00', '35%', '2,202,500.00 + 35% of excess over 8,000,000.00', 'TRAIN table'],
].map(([incomeRange, taxRate, formula, notes], index) => ({
  id: `fallback-${index}`,
  incomeRange,
  taxRate,
  formula,
  notes,
}))

const AUDIT_SSS_KEY = 'sss'

function todayYmd(timeZone = 'Asia/Manila') {
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

function toNumber(value, fallback = 0) {
  const n = Number(value)
  return Number.isFinite(n) ? n : fallback
}

function nonNegative(value) {
  return Math.max(0, toNumber(value, 0))
}

function formatMoney(value) {
  return nonNegative(value).toLocaleString('en-PH', {
    minimumFractionDigits: 2,
    maximumFractionDigits: 2,
  })
}

function formatPeso(value) {
  return `PHP ${formatMoney(value)}`
}

function formatPct(value) {
  return `${(toNumber(value) * 100).toFixed(2).replace(/\.00$/, '')}%`
}

function monthName(month) {
  const date = new Date(2026, Math.max(0, toNumber(month, 1) - 1), 1)
  return date.toLocaleString('en-PH', { month: 'short' })
}

function normalizeDateLabel(value) {
  if (!value) return ''
  const d = new Date(value)
  if (Number.isNaN(d.getTime())) return String(value)
  return d.toLocaleDateString('en-PH', { month: '2-digit', day: '2-digit', year: 'numeric' })
}

function buildSssCircular2024006Schedule() {
  const rows = [
    {
      min: 0,
      max: 5249.99,
      range_label: 'Below 5,250',
      msc: 5000,
      employee_ss: 250,
      employer_ss: 500,
      employer_ec: 30,
      ee_share: 250,
      er_share: 500,
      ec_amount: 30,
      overall_total: 780,
      total: 780,
    },
  ]

  for (let min = 5250, msc = 5500; msc <= 34500; min += 500, msc += 500) {
    const max = min + 499.99
    const employee = msc * 0.05
    const employer = msc * 0.1
    const ec = 30
    rows.push({
      min,
      max,
      range_label: `${formatMoney(min)} - ${formatMoney(max)}`,
      msc,
      employee_ss: employee,
      employer_ss: employer,
      employer_ec: ec,
      ee_share: employee,
      er_share: employer,
      ec_amount: ec,
      overall_total: employee + employer + ec,
      total: employee + employer + ec,
    })
  }

  rows.push({
    min: 34750,
    max: 35000,
    range_label: '34,750.00 - 35,000.00',
    msc: 35000,
    employee_ss: 1750,
    employer_ss: 3500,
    employer_ec: 30,
    ee_share: 1750,
    er_share: 3500,
    ec_amount: 30,
    overall_total: 5280,
    total: 5280,
  })

  return rows
}

function normalizeSssRows(rows, fallbackRows) {
  const source = Array.isArray(rows) ? rows : []
  const hasOfficialCoverage =
    source.length >= 61 &&
    source.some((row) => toNumber(row?.msc) <= 5000) &&
    source.some((row) => toNumber(row?.msc) >= 35000)

  return (hasOfficialCoverage ? source : fallbackRows)
    .map((row) => {
      const rangeFrom = row.range_from ?? row.range_start ?? row.salary_min ?? row.min ?? 0
      const rangeTo = row.range_to ?? row.range_end ?? row.salary_max ?? row.max ?? null
      const msc = toNumber(row.msc)
      const ee = toNumber(row.ee_share ?? row.employee_ss ?? row.employee_total ?? msc * 0.05)
      const er = toNumber(row.er_share ?? row.employer_ss ?? msc * 0.1)
      const ec = toNumber(row.ec_amount ?? row.employer_ec ?? 30)
      const total = toNumber(row.total ?? row.overall_total ?? ee + er + ec)
      return {
        ...row,
        range_from: toNumber(rangeFrom),
        range_to: rangeTo == null || rangeTo === '' ? null : toNumber(rangeTo),
        range_label: row.range_label || '',
        msc,
        ee_share: ee,
        er_share: er,
        ec_amount: ec,
        total,
      }
    })
    .sort((a, b) => a.range_from - b.range_from)
}

function readStoredCanManageRates() {
  try {
    const user = getStoredUser()
    const hrRole = String(user?.hr_role || '').toLowerCase()
    const role = String(user?.role || '').toLowerCase()
    return Boolean(user?.is_super_admin) || hrRole === 'admin_hr' || hrRole === 'company_head' || role === 'admin'
  } catch {
    return true
  }
}

function InfoBanner({ type = 'info', children, onDismiss }) {
  const tone =
    type === 'error'
      ? 'border-rose-200 bg-rose-50 text-rose-700 dark:border-rose-500/25 dark:bg-rose-950/30 dark:text-rose-200'
      : 'border-emerald-200 bg-emerald-50 text-emerald-800 dark:border-emerald-500/25 dark:bg-emerald-950/30 dark:text-emerald-100'

  return (
    <div className={cn('flex items-start justify-between gap-3 rounded-lg border px-4 py-3 text-sm shadow-sm', tone)}>
      <div className="flex min-w-0 items-start gap-2">
        {type === 'error' ? <X className="mt-0.5 size-4 shrink-0" /> : <CheckCircle2 className="mt-0.5 size-4 shrink-0" />}
        <span>{children}</span>
      </div>
      {onDismiss ? (
        <button type="button" onClick={onDismiss} className="rounded px-1.5 py-0.5 text-xs font-semibold opacity-80 hover:bg-current/10 hover:opacity-100">
          Dismiss
        </button>
      ) : null}
    </div>
  )
}

function Field({ label, value, onChange, disabled, type = 'number', step = '0.0001' }) {
  return (
    <label className="block min-w-0">
      <span className="text-[11px] font-semibold uppercase tracking-wide text-muted-foreground">{label}</span>
      <input
        type={type}
        step={step}
        value={value ?? ''}
        disabled={disabled}
        onChange={(event) => onChange(event.target.value)}
        className="mt-1 h-10 w-full rounded-md border border-border bg-background px-3 text-sm font-semibold text-foreground shadow-sm outline-none transition focus:border-brand focus:ring-2 focus:ring-brand/20 disabled:cursor-not-allowed disabled:opacity-60"
      />
    </label>
  )
}

function MetricCard({ label, value, caption, icon: Icon, accent = 'text-brand' }) {
  return (
    <div className="rounded-lg border border-border bg-card p-4 shadow-sm">
      <div className="flex items-start justify-between gap-3">
        <div className="min-w-0">
          <p className="text-[11px] font-semibold uppercase tracking-wide text-muted-foreground">{label}</p>
          <p className="mt-1 truncate text-xl font-bold tabular-nums text-foreground">{value}</p>
        </div>
        {Icon ? <Icon className={cn('size-5 shrink-0', accent)} aria-hidden /> : null}
      </div>
      {caption ? <p className="mt-2 text-xs leading-relaxed text-muted-foreground">{caption}</p> : null}
    </div>
  )
}

function RateSettings({ title, description, children }) {
  return (
    <div className="rounded-lg border border-border bg-muted/35 p-4">
      <div className="mb-3">
        <h3 className="text-sm font-bold text-foreground">{title}</h3>
        <p className="mt-1 text-xs leading-relaxed text-muted-foreground">{description}</p>
      </div>
      <div className="grid gap-3 sm:grid-cols-2 lg:grid-cols-4">{children}</div>
    </div>
  )
}

function ExemptionBadge({ active }) {
  return (
    <span className={cn(
      'inline-flex rounded-md px-2 py-1 text-xs font-bold',
      active
        ? 'bg-rose-500/10 text-rose-700 dark:text-rose-300'
        : 'bg-emerald-500/10 text-emerald-700 dark:text-emerald-300'
    )}>
      {active ? 'Exempt' : 'Deduct'}
    </span>
  )
}

function EmployeeExemptionsTab({ canManage }) {
  const [rows, setRows] = useState([])
  const [meta, setMeta] = useState(null)
  const [filters, setFilters] = useState({ search: '', payroll_type: '', employee_type: '', page: 1, per_page: 25 })
  const [loading, setLoading] = useState(false)
  const [saving, setSaving] = useState(false)
  const [error, setError] = useState('')
  const [notice, setNotice] = useState('')
  const [editing, setEditing] = useState(null)
  const [form, setForm] = useState(EMPTY_EXEMPTION_FORM)

  const loadRows = useCallback(async () => {
    setLoading(true)
    setError('')
    try {
      const data = await listGovernmentDeductionExemptions(filters)
      setRows(Array.isArray(data?.data) ? data.data : [])
      setMeta(data?.meta || null)
    } catch (err) {
      setError(err?.message || 'Failed to load employee exemptions.')
    } finally {
      setLoading(false)
    }
  }, [filters])

  useEffect(() => {
    void loadRows()
  }, [loadRows])

  function patchFilter(key, value) {
    setFilters((current) => ({ ...current, [key]: value, page: 1 }))
  }

  function openEditor(row) {
    const s = row?.settings || {}
    const next = {
      ...EMPTY_EXEMPTION_FORM,
      exempt_sss: Boolean(s.exempt_sss),
      exempt_philhealth: Boolean(s.exempt_philhealth),
      exempt_pagibig: Boolean(s.exempt_pagibig),
      exempt_withholding_tax: Boolean(s.exempt_withholding_tax),
      exempt_all_government_deductions: Boolean(s.exempt_all_government_deductions),
      exemption_reason: s.exemption_reason || '',
      applies_to_regular_payroll: s.applies_to_regular_payroll !== false,
      applies_to_execom_payroll: s.applies_to_execom_payroll !== false,
      is_active: s.is_active !== false,
    }
    setEditing(row)
    setForm(next)
    setError('')
    setNotice('')
  }

  function patchForm(key, value) {
    setForm((current) => {
      const next = { ...current, [key]: value }
      if (key === 'exempt_all_government_deductions' && value) {
        next.exempt_sss = true
        next.exempt_philhealth = true
        next.exempt_pagibig = true
        next.exempt_withholding_tax = true
      }
      if (EXEMPTION_TYPES.some(([field]) => field === key)) {
        next.exempt_all_government_deductions = EXEMPTION_TYPES.every(([field]) => Boolean(next[field]))
      }
      return next
    })
  }

  async function saveEditor(nextActive = form.is_active) {
    if (!editing?.id) return
    const hasExemption = EXEMPTION_TYPES.some(([field]) => Boolean(form[field]))
    if (nextActive && hasExemption && !String(form.exemption_reason || '').trim()) {
      setError('Exemption reason is required.')
      return
    }
    setSaving(true)
    setError('')
    setNotice('')
    try {
      const payload = {
        deduct_sss: !form.exempt_sss,
        deduct_philhealth: !form.exempt_philhealth,
        deduct_pagibig: !form.exempt_pagibig,
        deduct_withholding_tax: !form.exempt_withholding_tax,
        exempt_sss: form.exempt_sss,
        exempt_philhealth: form.exempt_philhealth,
        exempt_pagibig: form.exempt_pagibig,
        exempt_withholding_tax: form.exempt_withholding_tax,
        exempt_all_government_deductions: form.exempt_all_government_deductions,
        exemption_reason: form.exemption_reason,
        applies_to_regular_payroll: form.applies_to_regular_payroll,
        applies_to_execom_payroll: form.applies_to_execom_payroll,
        is_active: nextActive,
      }
      await updateEmployeeGovernmentDeductionExemption(editing.id, payload)
      setNotice(
        nextActive
          ? 'Employee exemption saved. Recompute draft payroll to refresh existing payslip previews.'
          : 'Employee exemption deactivated.'
      )
      setEditing(null)
      await loadRows()
    } catch (err) {
      setError(err?.message || 'Failed to save employee exemption.')
    } finally {
      setSaving(false)
    }
  }

  async function deactivateRow(row) {
    if (!row?.id) return
    const s = row.settings || {}
    setSaving(true)
    setError('')
    setNotice('')
    try {
      await updateEmployeeGovernmentDeductionExemption(row.id, {
        deduct_sss: s.deduct_sss !== false,
        deduct_philhealth: s.deduct_philhealth !== false,
        deduct_pagibig: s.deduct_pagibig !== false,
        deduct_withholding_tax: s.deduct_withholding_tax !== false,
        exemption_reason: s.exemption_reason || 'Deactivated by admin',
        applies_to_regular_payroll: s.applies_to_regular_payroll !== false,
        applies_to_execom_payroll: s.applies_to_execom_payroll !== false,
        is_active: false,
      })
      setNotice('Employee exemption deactivated.')
      await loadRows()
    } catch (err) {
      setError(err?.message || 'Failed to deactivate employee exemption.')
    } finally {
      setSaving(false)
    }
  }

  return (
    <section className="space-y-4 rounded-lg border border-border bg-card p-4 shadow-sm sm:p-5">
      <div className="flex flex-col gap-3 lg:flex-row lg:items-start lg:justify-between">
        <div>
          <h2 className="text-2xl font-bold tracking-tight text-foreground">Employee Exemptions</h2>
          <p className="mt-1 text-sm text-muted-foreground">Select employees whose SSS, PhilHealth, Pag-IBIG, or withholding tax deductions should be zeroed for active payroll periods.</p>
        </div>
        <Button type="button" variant="outline" onClick={loadRows} disabled={loading}>
          <RefreshCw className={cn('size-4', loading && 'animate-spin')} />
          Refresh
        </Button>
      </div>

      {error ? <InfoBanner type="error" onDismiss={() => setError('')}>{error}</InfoBanner> : null}
      {notice ? <InfoBanner onDismiss={() => setNotice('')}>{notice}</InfoBanner> : null}

      <div className="grid gap-3 md:grid-cols-[minmax(0,1fr)_180px_190px]">
        <label className="flex h-11 items-center gap-2 rounded-md border border-border bg-background px-3">
          <Search className="size-4 shrink-0 text-muted-foreground" aria-hidden />
          <input
            value={filters.search}
            onChange={(event) => patchFilter('search', event.target.value)}
            placeholder="Search name, employee no., company, department..."
            className="h-full min-w-0 flex-1 bg-transparent text-sm outline-none"
          />
        </label>
        <select value={filters.payroll_type} onChange={(event) => patchFilter('payroll_type', event.target.value)} className="h-11 rounded-md border border-border bg-background px-3 text-sm font-semibold">
          <option value="">Any payroll type</option>
          <option value="regular">Regular / Consultant</option>
          <option value="execom">EXECOM</option>
        </select>
        <select value={filters.employee_type} onChange={(event) => patchFilter('employee_type', event.target.value)} className="h-11 rounded-md border border-border bg-background px-3 text-sm font-semibold">
          <option value="">Any employee type</option>
          <option value="regular">Regular</option>
          <option value="probationary">Probationary</option>
          <option value="consultant">Consultant</option>
          <option value="contractual">Contractual</option>
          <option value="project_based">Project based</option>
        </select>
      </div>

      <div className="overflow-x-auto rounded-lg border border-border">
        <table className="w-full min-w-304 text-sm">
          <thead className="bg-background text-[11px] uppercase tracking-wide text-foreground">
            <tr>
              {['Employee', 'Company', 'Department', 'SSS', 'PhilHealth', 'Pag-IBIG', 'Withholding Tax', 'Status', 'Actions'].map((head) => (
                <th key={head} className="border-b border-r border-border px-3 py-3 text-left font-bold last:border-r-0">{head}</th>
              ))}
            </tr>
          </thead>
          <tbody>
            {loading ? (
              <tr><td colSpan={9} className="px-3 py-10 text-center text-muted-foreground">Loading employee exemptions...</td></tr>
            ) : null}
            {!loading && rows.length === 0 ? (
              <tr><td colSpan={9} className="px-3 py-10 text-center text-muted-foreground">No employees matched the current filters.</td></tr>
            ) : null}
            {!loading && rows.map((row) => {
              const s = row.settings || {}
              const active = s.is_active !== false
              return (
                <tr key={row.id} className="border-b border-border align-top last:border-b-0">
                  <td className="border-r border-border px-3 py-3">
                    <div className="font-bold text-foreground">{row.name}</div>
                    <div className="mt-0.5 text-xs text-muted-foreground">{row.employee_code || 'No employee no.'}{row.is_execom ? ' • EXECOM' : ''}</div>
                  </td>
                  <td className="border-r border-border px-3 py-3 text-foreground">{row.company || '-'}</td>
                  <td className="border-r border-border px-3 py-3 text-foreground">{row.department || '-'}</td>
                  <td className="border-r border-border px-3 py-3"><ExemptionBadge active={active && s.exempt_sss} /></td>
                  <td className="border-r border-border px-3 py-3"><ExemptionBadge active={active && s.exempt_philhealth} /></td>
                  <td className="border-r border-border px-3 py-3"><ExemptionBadge active={active && s.exempt_pagibig} /></td>
                  <td className="border-r border-border px-3 py-3"><ExemptionBadge active={active && s.exempt_withholding_tax} /></td>
                  <td className="border-r border-border px-3 py-3">
                    <span className={cn('rounded-md px-2 py-1 text-xs font-bold', active ? 'bg-emerald-500/10 text-emerald-700 dark:text-emerald-300' : 'bg-slate-500/10 text-slate-600 dark:text-slate-300')}>
                      {active ? 'Active' : 'Inactive'}
                    </span>
                  </td>
                  <td className="px-3 py-3">
                    <div className="flex flex-wrap gap-2">
                      <Button type="button" size="sm" variant="outline" onClick={() => openEditor(row)} disabled={!canManage}>Edit</Button>
                      <Button type="button" size="sm" variant="outline" onClick={() => deactivateRow(row)} disabled={!canManage || !active || saving}>Deactivate</Button>
                    </div>
                  </td>
                </tr>
              )
            })}
          </tbody>
        </table>
      </div>

      {meta ? (
        <div className="flex flex-col gap-3 text-sm text-muted-foreground sm:flex-row sm:items-center sm:justify-between">
          <span>Showing page {meta.current_page || 1} of {meta.last_page || 1} ({meta.total || 0} employees)</span>
          <div className="flex gap-2">
            <Button type="button" variant="outline" disabled={(meta.current_page || 1) <= 1} onClick={() => setFilters((f) => ({ ...f, page: Math.max(1, (meta.current_page || 1) - 1) }))}>Previous</Button>
            <Button type="button" variant="outline" disabled={(meta.current_page || 1) >= (meta.last_page || 1)} onClick={() => setFilters((f) => ({ ...f, page: (meta.current_page || 1) + 1 }))}>Next</Button>
          </div>
        </div>
      ) : null}

      {editing ? (
        <div className="fixed inset-0 z-50 flex items-center justify-center bg-black/45 p-3 backdrop-blur-sm">
          <div className="w-full max-w-3xl overflow-hidden rounded-lg border border-border bg-card text-foreground shadow-2xl">
            <div className="flex items-start justify-between gap-4 border-b border-border px-5 py-4">
              <div>
                <h3 className="text-lg font-bold">Government Deduction Exemptions</h3>
                <p className="mt-1 text-sm text-muted-foreground">{editing.name} - {editing.employee_code || 'No employee no.'}</p>
              </div>
              <button type="button" onClick={() => setEditing(null)} className="inline-flex size-9 items-center justify-center rounded-md border border-border bg-background hover:bg-muted">
                <X className="size-4" />
              </button>
            </div>
            <div className="max-h-[70vh] space-y-4 overflow-y-auto p-5">
              <label className="flex items-center gap-3 rounded-lg border border-border bg-background px-3 py-3 text-sm font-semibold">
                <input type="checkbox" checked={form.exempt_all_government_deductions} onChange={(event) => patchForm('exempt_all_government_deductions', event.target.checked)} />
                Exempt from all government deductions
              </label>
              <div className="grid gap-3 sm:grid-cols-2">
                {EXEMPTION_TYPES.map(([field, label]) => (
                  <label key={field} className="flex items-center gap-3 rounded-lg border border-border bg-background px-3 py-3 text-sm">
                    <input type="checkbox" checked={Boolean(form[field])} onChange={(event) => patchForm(field, event.target.checked)} />
                    <span className="font-semibold">Exempt from {label}</span>
                  </label>
                ))}
              </div>
              <p className="rounded-lg border border-emerald-200 bg-emerald-50 px-4 py-3 text-sm text-emerald-950 dark:border-emerald-900/50 dark:bg-emerald-950/30 dark:text-emerald-100">
                Active exemptions apply immediately to matching payroll scopes. Recompute existing draft batches to refresh saved payslip previews.
              </p>
              <label className="block">
                <span className="text-[11px] font-semibold uppercase tracking-wide text-muted-foreground">Exemption Reason</span>
                <textarea value={form.exemption_reason} onChange={(event) => patchForm('exemption_reason', event.target.value)} rows={3} className="mt-1 w-full rounded-md border border-border bg-background px-3 py-2 text-sm" placeholder="Required when any exemption is active." />
              </label>
              <div className="grid gap-3 sm:grid-cols-3">
                <label className="flex items-center gap-3 rounded-lg border border-border bg-background px-3 py-3 text-sm">
                  <input type="checkbox" checked={form.applies_to_regular_payroll} onChange={(event) => patchForm('applies_to_regular_payroll', event.target.checked)} />
                  Regular / Consultant payroll
                </label>
                <label className="flex items-center gap-3 rounded-lg border border-border bg-background px-3 py-3 text-sm">
                  <input type="checkbox" checked={form.applies_to_execom_payroll} onChange={(event) => patchForm('applies_to_execom_payroll', event.target.checked)} />
                  EXECOM payroll
                </label>
                <label className="flex items-center gap-3 rounded-lg border border-border bg-background px-3 py-3 text-sm">
                  <input type="checkbox" checked={form.is_active} onChange={(event) => patchForm('is_active', event.target.checked)} />
                  Active
                </label>
              </div>
            </div>
            <div className="flex flex-col gap-2 border-t border-border px-5 py-4 sm:flex-row sm:justify-end">
              <Button type="button" variant="outline" onClick={() => setEditing(null)}>Cancel</Button>
              <Button type="button" onClick={() => saveEditor(form.is_active)} disabled={saving || !canManage} className="bg-brand text-brand-foreground hover:bg-brand-strong">
                <Save className="size-4" />
                {saving ? 'Saving...' : 'Save Exemption'}
              </Button>
            </div>
          </div>
        </div>
      ) : null}
    </section>
  )
}

export default function GovernmentDeductionPage() {
  const [activeTab, setActiveTab] = useState('SSS')
  const [rowsByCode, setRowsByCode] = useState(DEFAULT_ROWS)
  const [effectiveFrom, setEffectiveFrom] = useState(todayYmd())
  const [basicSalary, setBasicSalary] = useState('25000')
  const [whtSalary, setWhtSalary] = useState('25000')
  const [thirteenthMonth, setThirteenthMonth] = useState('0')
  const [whtMethod, setWhtMethod] = useState('annualized')
  const [periodType, setPeriodType] = useState('monthly')
  const [sssSchedule, setSssSchedule] = useState([])
  const [sssQuery, setSssQuery] = useState('')
  const [sssPage, setSssPage] = useState(1)
  const [sssPageSize, setSssPageSize] = useState(20)
  const [auditPreview, setAuditPreview] = useState(null)
  const [withholdingPreview, setWithholdingPreview] = useState(null)
  const [summary, setSummary] = useState(null)
  const [taxTables, setTaxTables] = useState([])
  const [remittances, setRemittances] = useState([])
  const [historyOpen, setHistoryOpen] = useState(false)
  const [historyRows, setHistoryRows] = useState([])
  const [historyCodeFilter, setHistoryCodeFilter] = useState('')
  const [loading, setLoading] = useState(true)
  const [saving, setSaving] = useState(false)
  const [auditLoading, setAuditLoading] = useState(false)
  const [whtLoading, setWhtLoading] = useState(false)
  const [historyLoading, setHistoryLoading] = useState(false)
  const [remittanceLoading, setRemittanceLoading] = useState(false)
  const [error, setError] = useState('')
  const [notice, setNotice] = useState('')
  const [canManageRates] = useState(() => readStoredCanManageRates())
  const [remittanceDraft, setRemittanceDraft] = useState(() => {
    const now = new Date()
    return {
      agency: 'SSS',
      report_kind: 'r3',
      period_year: now.getFullYear(),
      period_month: now.getMonth() + 1,
    }
  })
  const auditDebounceRef = useRef(null)
  const whtDebounceRef = useRef(null)

  const sssFallbackRows = useMemo(() => buildSssCircular2024006Schedule(), [])
  const sssRows = useMemo(() => normalizeSssRows(sssSchedule, sssFallbackRows), [sssSchedule, sssFallbackRows])

  const loadAll = useCallback(async () => {
    setLoading(true)
    setError('')
    try {
      const [rateData, summaryData, remittanceData, taxData] = await Promise.all([
        getStatutoryRates(),
        getStatutoryDashboardSummary().catch(() => null),
        listStatutoryRemittances({ page: 1, per_page: 8 }).catch(() => null),
        getTaxTables({ year: new Date().getFullYear() }).catch(() => null),
      ])

      const nextRows = { ...DEFAULT_ROWS }
      for (const row of rateData?.rates || []) {
        const code = String(row?.code || '').toUpperCase()
        if (nextRows[code]) nextRows[code] = { ...nextRows[code], ...row }
      }
      setRowsByCode(nextRows)
      setSssSchedule(Array.isArray(rateData?.sss_schedule) ? rateData.sss_schedule : [])
      setSummary(summaryData)
      setRemittances(Array.isArray(remittanceData?.data) ? remittanceData.data : [])
      setTaxTables(Array.isArray(taxData?.tax_tables) ? taxData.tax_tables : [])
    } catch (err) {
      setError(err?.message || 'Failed to load Government Deductions data.')
    } finally {
      setLoading(false)
    }
  }, [])

  useEffect(() => {
    loadAll()
  }, [loadAll])

  const patchRate = useCallback((code, key, value) => {
    setRowsByCode((current) => ({
      ...current,
      [code]: {
        ...current[code],
        [key]: value,
      },
    }))
  }, [])

  const patchPagibigMetadata = useCallback((key, value) => {
    setRowsByCode((current) => ({
      ...current,
      PAGIBIG: {
        ...current.PAGIBIG,
        metadata: {
          ...(current.PAGIBIG?.metadata || {}),
          [key]: value,
        },
      },
    }))
  }, [])

  async function saveChanges() {
    setSaving(true)
    setError('')
    setNotice('')
    try {
      for (const code of RATE_CODES) {
        const row = rowsByCode[code] || DEFAULT_ROWS[code]
        await upsertStatutoryRate(code, {
          name: row.name || DEFAULT_ROWS[code].name,
          employee_rate: toNumber(row.employee_rate),
          employer_rate: toNumber(row.employer_rate),
          min_salary: row.min_salary === '' ? null : row.min_salary ?? null,
          max_salary: row.max_salary === '' ? null : row.max_salary ?? null,
          msc: row.msc === '' ? null : row.msc ?? null,
          salary_floor: row.salary_floor === '' ? null : row.salary_floor ?? null,
          salary_ceiling: row.salary_ceiling === '' ? null : row.salary_ceiling ?? null,
          tier_threshold: row.tier_threshold === '' ? null : row.tier_threshold ?? null,
          monthly_cap: row.monthly_cap === '' ? null : row.monthly_cap ?? null,
          metadata: row.metadata || null,
          brackets: code === 'SSS' ? sssRows : null,
          compliance_reference: row.compliance_reference || DEFAULT_ROWS[code].compliance_reference,
          effective_from: effectiveFrom,
          is_active: true,
        })
      }
      setNotice('Government deduction rates were saved.')
      await loadAll()
    } catch (err) {
      setError(err?.message || 'Failed to save statutory rates.')
    } finally {
      setSaving(false)
    }
  }

  async function loadHistory(code = historyCodeFilter) {
    setHistoryLoading(true)
    setError('')
    try {
      const data = await getStatutoryRateHistory({ code: code || undefined, page: 1, per_page: 60 })
      setHistoryRows(Array.isArray(data?.history) ? data.history : [])
    } catch (err) {
      setError(err?.message || 'Failed to load rate history.')
      setHistoryRows([])
    } finally {
      setHistoryLoading(false)
    }
  }

  async function openHistoryModal() {
    setHistoryOpen(true)
    await loadHistory()
  }

  const runAudit = useCallback(async () => {
    setAuditLoading(true)
    try {
      const data = await calculateStatutoryContributions({
        basic_salary: nonNegative(basicSalary),
        withholding_method: 'annualized',
        period_type: 'monthly',
      })
      setAuditPreview(data?.breakdown || null)
    } catch {
      setAuditPreview(null)
    } finally {
      setAuditLoading(false)
    }
  }, [basicSalary])

  const runWithholdingPreview = useCallback(async () => {
    setWhtLoading(true)
    try {
      const data = await previewWithholdingTax({
        monthly_taxable_compensation: nonNegative(whtSalary),
        thirteenth_month_amount: nonNegative(thirteenthMonth),
        method: whtMethod,
        period_type: periodType,
      })
      setWithholdingPreview(data?.withholding || null)
    } catch {
      setWithholdingPreview(null)
    } finally {
      setWhtLoading(false)
    }
  }, [periodType, thirteenthMonth, whtMethod, whtSalary])

  useEffect(() => {
    if (activeTab !== 'AUDIT') return undefined
    if (auditDebounceRef.current) clearTimeout(auditDebounceRef.current)
    auditDebounceRef.current = setTimeout(() => {
      void runAudit()
    }, 400)
    return () => clearTimeout(auditDebounceRef.current)
  }, [activeTab, runAudit])

  useEffect(() => {
    if (activeTab !== 'WHT') return undefined
    if (whtDebounceRef.current) clearTimeout(whtDebounceRef.current)
    whtDebounceRef.current = setTimeout(() => {
      void runWithholdingPreview()
    }, 400)
    return () => clearTimeout(whtDebounceRef.current)
  }, [activeTab, runWithholdingPreview])

  async function createRemittance() {
    setRemittanceLoading(true)
    setError('')
    setNotice('')
    try {
      const data = await generateStatutoryRemittance({
        ...remittanceDraft,
        period_year: toNumber(remittanceDraft.period_year, new Date().getFullYear()),
        period_month: toNumber(remittanceDraft.period_month, new Date().getMonth() + 1),
      })
      setNotice(`Generated ${data?.remittance?.agency || remittanceDraft.agency} remittance with ${data?.row_count ?? 0} rows.`)
      const next = await listStatutoryRemittances({ page: 1, per_page: 8 })
      setRemittances(Array.isArray(next?.data) ? next.data : [])
    } catch (err) {
      setError(err?.message || 'Failed to generate remittance.')
    } finally {
      setRemittanceLoading(false)
    }
  }

  const sssQueryNormalized = sssQuery.trim().toLowerCase()
  const sssFilteredRows = useMemo(() => {
    if (!sssQueryNormalized) return sssRows
    return sssRows.filter((row) => {
      const label = String(row.range_label || '').toLowerCase()
      const haystack = `${label} ${row.range_from} ${row.range_to ?? ''} ${row.msc} ${formatMoney(row.msc)}`.toLowerCase()
      return haystack.includes(sssQueryNormalized)
    })
  }, [sssQueryNormalized, sssRows])

  useEffect(() => {
    setSssPage(1)
  }, [sssQuery, sssPageSize])

  const sssTotalRows = sssFilteredRows.length
  const sssTotalPages = Math.max(1, Math.ceil(sssTotalRows / sssPageSize))
  const sssCurrentPage = Math.min(sssPage, sssTotalPages)
  const sssPageStart = sssTotalRows === 0 ? 0 : (sssCurrentPage - 1) * sssPageSize + 1
  const sssPageEnd = Math.min(sssCurrentPage * sssPageSize, sssTotalRows)
  const sssRowsPaged = useMemo(
    () => sssFilteredRows.slice((sssCurrentPage - 1) * sssPageSize, sssCurrentPage * sssPageSize),
    [sssFilteredRows, sssCurrentPage, sssPageSize]
  )

  const phRow = rowsByCode.PHILHEALTH || DEFAULT_ROWS.PHILHEALTH
  const phFloor = nonNegative(phRow.salary_floor ?? 10000)
  const phCeiling = nonNegative(phRow.salary_ceiling ?? 100000)
  const phEmployeeRate = nonNegative(phRow.employee_rate ?? 0.025)
  const phEmployerRate = nonNegative(phRow.employer_rate ?? 0.025)
  const phTotalRate = phEmployeeRate + phEmployerRate

  const pagibigRow = rowsByCode.PAGIBIG || DEFAULT_ROWS.PAGIBIG
  const pagThreshold = nonNegative(pagibigRow.tier_threshold ?? 1500)
  const pagCap = nonNegative(pagibigRow.monthly_cap ?? 10000)
  const pagEeLower = nonNegative(pagibigRow?.metadata?.employee_rate_lower ?? 0.01)
  const pagEeUpper = nonNegative(pagibigRow.employee_rate ?? pagibigRow?.metadata?.employee_rate_upper ?? 0.02)
  const pagErRate = nonNegative(pagibigRow.employer_rate ?? 0.02)

  const audit = useMemo(() => {
    if (auditPreview) return auditPreview
    const salary = nonNegative(basicSalary)
    const bracket =
      sssRows.find((row) => salary >= row.range_from && (row.range_to == null || salary <= row.range_to)) ||
      sssRows[sssRows.length - 1] ||
      { msc: 35000, range_from: 34750, range_to: 35000, ee_share: 1750, er_share: 3500, ec_amount: 30, total: 5280 }

    const phBase = Math.min(phCeiling, Math.max(phFloor, salary))
    const pagBase = Math.min(pagCap, salary)
    const pagRate = salary <= pagThreshold ? pagEeLower : pagEeUpper
    const sssEe = nonNegative(bracket.ee_share)
    const sssEr = nonNegative(bracket.er_share)
    const sssEc = nonNegative(bracket.ec_amount)
    const phEe = phBase * phEmployeeRate
    const phEr = phBase * phEmployerRate
    const pagEe = pagBase * pagRate
    const pagEr = pagBase * pagErRate

    return {
      sss: {
        employee_amount: sssEe,
        employer_amount: sssEr,
        ec_amount: sssEc,
        total_amount: sssEe + sssEr + sssEc,
        msc_used: bracket.msc,
        msc_bracket_range: bracket.range_label || `${formatMoney(bracket.range_from)} - ${formatMoney(bracket.range_to ?? bracket.msc)}`,
      },
      philhealth: {
        employee_amount: phEe,
        employer_amount: phEr,
        total_amount: phEe + phEr,
        metadata: { applied_salary: phBase },
      },
      pagibig: {
        employee_amount: pagEe,
        employer_amount: pagEr,
        total_amount: pagEe + pagEr,
        metadata: { applied_salary: pagBase, cap_applied: salary > pagCap },
      },
      totals: {
        employee_deduction: sssEe + phEe + pagEe,
        employer_liability: sssEr + sssEc + phEr + pagEr,
        overall_statutory: sssEe + phEe + pagEe + sssEr + sssEc + phEr + pagEr,
      },
    }
  }, [
    auditPreview,
    basicSalary,
    pagCap,
    pagEeLower,
    pagEeUpper,
    pagErRate,
    pagThreshold,
    phCeiling,
    phEmployeeRate,
    phEmployerRate,
    phFloor,
    sssRows,
  ])

  const taxTableRows = useMemo(() => {
    const rows = []
    for (const table of taxTables) {
      const payload = table?.payload || {}
      const candidates = Array.isArray(payload) ? payload : payload.rows || payload.brackets || payload.table
      if (Array.isArray(candidates)) {
        candidates.forEach((row, index) => {
          rows.push({
            id: `${table.id || table.code || 'tax'}-${index}`,
            incomeRange: row.incomeRange || row.income_range || row.range || row.label || table.label || 'Tax bracket',
            taxRate: row.taxRate || row.tax_rate || row.rate || '',
            formula: row.formula || row.description || row.rule || '',
            notes: table.source_reference || table.label || normalizeDateLabel(table.effective_from),
          })
        })
      }
    }
    return rows.length > 0 ? rows : FALLBACK_TRAIN_ROWS
  }, [taxTables])

  const withholdingMonthly = nonNegative(withholdingPreview?.withholding_per_month)
  const withholdingAnnual = nonNegative(withholdingPreview?.annual_income_tax_per_train ?? withholdingMonthly * 12)
  const withholdingRate = nonNegative(
    withholdingPreview?.effective_rate_percent_of_monthly_taxable ??
      (nonNegative(whtSalary) > 0 ? (withholdingMonthly / nonNegative(whtSalary)) * 100 : 0)
  )

  const remittanceKindOptions = useMemo(() => {
    if (remittanceDraft.agency === 'SSS') return ['r3', 'r5', 'monthly_listing']
    if (remittanceDraft.agency === 'PHILHEALTH') return ['rf1', 'premium_listing']
    if (remittanceDraft.agency === 'PAGIBIG') return ['mcrf', 'monthly_listing']
    return ['withholding_summary']
  }, [remittanceDraft.agency])

  useEffect(() => {
    if (!remittanceKindOptions.includes(remittanceDraft.report_kind)) {
      setRemittanceDraft((draft) => ({ ...draft, report_kind: remittanceKindOptions[0] }))
    }
  }, [remittanceDraft.report_kind, remittanceKindOptions])

  const headerDate = normalizeDateLabel(effectiveFrom)

  return (
    <div className="w-full min-w-0 bg-background px-3 py-4 text-foreground sm:px-4 md:px-5 lg:px-6 lg:py-5 3xl:px-10">
      <div className="mx-auto max-w-[112rem] space-y-5">
        <div className="flex flex-col gap-4 lg:flex-row lg:items-start lg:justify-between">
          <div className="min-w-0 flex-1">
            <div className="inline-flex items-center gap-2 rounded-md bg-brand/10 px-2.5 py-1 text-xs font-bold uppercase tracking-wide text-brand">
              <ReceiptText className="size-3.5" aria-hidden />
              Compensation
            </div>
            <h1 className="mt-4 text-3xl font-bold tracking-tight text-foreground sm:text-4xl">Government Deductions</h1>
            <p className="mt-2 max-w-4xl text-sm leading-relaxed text-muted-foreground">
              Statutory contributions: SSS including employer EC, PhilHealth, and Pag-IBIG. Rates reference RA 11199, RA 11223, RA 9679, and BIR TRAIN withholding.
            </p>
          </div>

          <div className="flex w-full flex-col gap-2 sm:w-auto sm:flex-row sm:flex-wrap sm:justify-end">
            <label className="flex h-11 items-center gap-2 rounded-md border border-border bg-card px-3 shadow-sm">
              <Calendar className="size-4 shrink-0 text-muted-foreground" aria-hidden />
              <span className="sr-only">Effective date</span>
              <input
                type="date"
                value={effectiveFrom}
                onChange={(event) => setEffectiveFrom(event.target.value)}
                className="h-full min-w-0 border-0 bg-transparent text-sm font-bold text-foreground outline-none [color-scheme:light] dark:[color-scheme:dark]"
              />
            </label>
            <Button type="button" variant="outline" onClick={openHistoryModal} className="h-11 border-border bg-card px-4 font-bold text-foreground shadow-sm">
              <History className="size-4" />
              History of Rates
            </Button>
            <Button
              type="button"
              onClick={saveChanges}
              disabled={saving || loading || !canManageRates}
              className="h-11 bg-brand px-4 font-bold text-brand-foreground shadow-sm hover:bg-brand-strong"
            >
              <Save className="size-4" />
              {saving ? 'Saving...' : canManageRates ? 'Save Changes' : 'View Only'}
            </Button>
          </div>
        </div>

        {error ? <InfoBanner type="error" onDismiss={() => setError('')}>{error}</InfoBanner> : null}
        {notice ? <InfoBanner onDismiss={() => setNotice('')}>{notice}</InfoBanner> : null}

        <div className="rounded-lg border border-border bg-card p-3 shadow-sm">
          <div className="flex gap-2 overflow-x-auto pb-1 sm:flex-wrap sm:overflow-visible sm:pb-0" role="tablist" aria-label="Government deduction tabs">
            {TABS.map((tab) => (
              <button
                key={tab.id}
                type="button"
                role="tab"
                aria-selected={activeTab === tab.id}
                onClick={() => setActiveTab(tab.id)}
                className={cn(
                  'h-12 shrink-0 rounded-md px-5 text-sm font-semibold transition-colors',
                  activeTab === tab.id
                    ? 'bg-brand text-brand-foreground shadow-sm'
                    : 'bg-muted text-foreground hover:bg-muted/80 dark:bg-muted/60'
                )}
              >
                {tab.label}
              </button>
            ))}
          </div>
        </div>

        {loading ? (
          <div className="rounded-lg border border-border bg-card p-8 text-center text-sm text-muted-foreground shadow-sm">
            Loading Government Deductions...
          </div>
        ) : null}

        {!loading && activeTab === 'SSS' ? (
          <section className="rounded-lg border border-border bg-card p-4 shadow-sm sm:p-5">
            <div className="flex flex-col gap-3 sm:flex-row sm:items-start sm:justify-between">
              <div>
                <h2 className="text-2xl font-bold tracking-tight text-foreground">SSS Contribution Schedule</h2>
                <p className="mt-1 text-sm text-muted-foreground">Social Security Act (RA 11199)</p>
              </div>
              <span className="w-fit rounded-md bg-brand/10 px-3 py-1.5 text-xs font-bold text-brand">Circular No. 2024-006</span>
            </div>

            <div className="mt-5 flex flex-col gap-3 lg:flex-row lg:items-center lg:justify-between">
              <label className="flex h-12 w-full max-w-md items-center gap-2 rounded-md border border-border bg-background px-3 shadow-sm">
                <Search className="size-4 shrink-0 text-muted-foreground" aria-hidden />
                <span className="sr-only">Search SSS table</span>
                <input
                  value={sssQuery}
                  onChange={(event) => setSssQuery(event.target.value)}
                  placeholder="Search by range or MSC..."
                  className="h-full min-w-0 flex-1 bg-transparent text-sm text-foreground outline-none placeholder:text-muted-foreground"
                />
              </label>
              <label className="flex items-center gap-2 text-sm text-muted-foreground">
                Rows per page
                <select
                  value={sssPageSize}
                  onChange={(event) => setSssPageSize(Number(event.target.value))}
                  className="h-10 rounded-md border border-border bg-background px-3 text-sm font-semibold text-foreground"
                >
                  <option value={10}>10</option>
                  <option value={20}>20</option>
                  <option value={50}>50</option>
                </select>
              </label>
            </div>

            <div className="mt-5 overflow-x-auto rounded-lg border border-border">
              <table className="w-full min-w-[58rem] text-sm">
                <thead className="bg-background text-[11px] uppercase tracking-wide text-foreground">
                  <tr>
                    <th className="border-b border-r border-border px-4 py-4 text-left font-bold">Range From</th>
                    <th className="border-b border-r border-border px-4 py-4 text-left font-bold">Range To</th>
                    <th className="border-b border-r border-border px-4 py-4 text-left font-bold">MSC Value</th>
                    <th className="border-b border-r border-border px-4 py-4 text-left font-bold">EE Share (Employee)</th>
                    <th className="border-b border-r border-border px-4 py-4 text-left font-bold">ER Share (Regular SS)</th>
                    <th className="border-b border-r border-border px-4 py-4 text-left font-bold">EC (ER)</th>
                    <th className="border-b border-border px-4 py-4 text-left font-bold">Total Contribution</th>
                  </tr>
                </thead>
                <tbody>
                  {sssRowsPaged.map((row, index) => {
                    const isBelowRow = String(row.range_label || '').toLowerCase().startsWith('below')
                    return (
                      <tr key={`${row.range_from}-${row.range_to}-${row.msc}-${index}`} className="border-b border-border last:border-b-0">
                        <td className="border-r border-border px-4 py-4 text-foreground">{isBelowRow ? row.range_label : formatMoney(row.range_from)}</td>
                        <td className="border-r border-border px-4 py-4 text-foreground">{isBelowRow ? formatMoney(row.msc) : formatMoney(row.range_to ?? row.msc)}</td>
                        <td className="border-r border-border px-4 py-4 text-foreground">{formatMoney(row.msc)}</td>
                        <td className="border-r border-border px-4 py-4 text-foreground">{formatMoney(row.ee_share)}</td>
                        <td className="border-r border-border px-4 py-4 text-foreground">{formatMoney(row.er_share)}</td>
                        <td className="border-r border-border px-4 py-4 text-foreground">{formatMoney(row.ec_amount)}</td>
                        <td className="px-4 py-4 font-bold text-brand">{formatMoney(row.total)}</td>
                      </tr>
                    )
                  })}
                  {sssRowsPaged.length === 0 ? (
                    <tr>
                      <td colSpan={7} className="px-4 py-10 text-center text-sm text-muted-foreground">
                        No SSS brackets matched your search.
                      </td>
                    </tr>
                  ) : null}
                </tbody>
              </table>
            </div>

            <div className="mt-5 flex flex-col gap-3 sm:flex-row sm:items-center sm:justify-between">
              <p className="text-sm text-muted-foreground">
                Showing {sssPageStart}-{sssPageEnd} of {sssTotalRows} entries
              </p>
              <div className="flex items-center gap-3">
                <Button type="button" variant="outline" disabled={sssCurrentPage <= 1} onClick={() => setSssPage((page) => Math.max(1, page - 1))}>
                  Previous
                </Button>
                <span className="text-sm font-medium text-foreground">Page {sssCurrentPage} of {sssTotalPages}</span>
                <Button
                  type="button"
                  disabled={sssCurrentPage >= sssTotalPages}
                  onClick={() => setSssPage((page) => Math.min(sssTotalPages, page + 1))}
                  className="bg-brand text-brand-foreground hover:bg-brand-strong"
                >
                  Next
                  <ChevronRight className="size-4" />
                </Button>
              </div>
            </div>
            <p className="mt-2 text-xs text-muted-foreground">Based on SSS Circular No. 2024-006 - Effective January 2025</p>

            <div className="mt-5">
              <RateSettings title="SSS rate settings" description={`Saved effective date: ${headerDate}. Rates are validated against RA 11199.`}>
                <Field label="Employee rate" value={rowsByCode.SSS?.employee_rate ?? ''} disabled={!canManageRates} onChange={(value) => patchRate('SSS', 'employee_rate', value)} />
                <Field label="Employer rate" value={rowsByCode.SSS?.employer_rate ?? ''} disabled={!canManageRates} onChange={(value) => patchRate('SSS', 'employer_rate', value)} />
                <Field label="Minimum salary" value={rowsByCode.SSS?.min_salary ?? ''} disabled={!canManageRates} onChange={(value) => patchRate('SSS', 'min_salary', value)} />
                <Field label="Maximum salary" value={rowsByCode.SSS?.max_salary ?? ''} disabled={!canManageRates} onChange={(value) => patchRate('SSS', 'max_salary', value)} />
              </RateSettings>
            </div>
          </section>
        ) : null}

        {!loading && activeTab === 'PHILHEALTH' ? (
          <section className="space-y-4 rounded-lg border border-border bg-card p-4 shadow-sm sm:p-5">
            <div className="flex flex-col gap-3 sm:flex-row sm:items-start sm:justify-between">
              <div>
                <h2 className="text-2xl font-bold tracking-tight text-foreground">PhilHealth Premium Table</h2>
                <p className="mt-1 text-sm text-muted-foreground">Universal Health Care Act (RA 11223)</p>
              </div>
              <span className="w-fit rounded-md bg-emerald-500/10 px-3 py-1.5 text-xs font-bold text-emerald-700 dark:text-emerald-300">5% total premium</span>
            </div>

            <div className="grid gap-3 md:grid-cols-3">
              <MetricCard label="Total Rate" value={formatPct(phTotalRate)} caption={`${formatPct(phEmployeeRate)} EE | ${formatPct(phEmployerRate)} ER`} icon={HeartPulse} accent="text-emerald-600 dark:text-emerald-300" />
              <MetricCard label="Salary Floor" value={formatPeso(phFloor)} caption="Minimum base used for premium computation" />
              <MetricCard label="Salary Ceiling" value={formatPeso(phCeiling)} caption="Maximum base used for premium computation" />
            </div>

            <div className="overflow-x-auto rounded-lg border border-border">
              <table className="w-full min-w-[48rem] text-sm">
                <thead className="bg-background text-[11px] uppercase tracking-wide text-foreground">
                  <tr>
                    <th className="border-b border-r border-border px-4 py-4 text-left font-bold">Monthly Basic Salary Range</th>
                    <th className="border-b border-r border-border px-4 py-4 text-left font-bold">Applied Salary</th>
                    <th className="border-b border-r border-border px-4 py-4 text-left font-bold">Total Premium</th>
                    <th className="border-b border-r border-border px-4 py-4 text-left font-bold">Employee Share</th>
                    <th className="border-b border-border px-4 py-4 text-left font-bold">Employer Share</th>
                  </tr>
                </thead>
                <tbody>
                  {[
                    [`0.00 - ${formatMoney(phFloor - 0.01)}`, formatPeso(phFloor), formatPeso(phFloor * phTotalRate), formatPeso(phFloor * phEmployeeRate), formatPeso(phFloor * phEmployerRate)],
                    [`${formatMoney(phFloor)} - ${formatMoney(phCeiling - 0.01)}`, 'Actual salary', `Salary x ${formatPct(phTotalRate)}`, `Salary x ${formatPct(phEmployeeRate)}`, `Salary x ${formatPct(phEmployerRate)}`],
                    [`${formatMoney(phCeiling)} and above`, formatPeso(phCeiling), formatPeso(phCeiling * phTotalRate), formatPeso(phCeiling * phEmployeeRate), formatPeso(phCeiling * phEmployerRate)],
                  ].map((row) => (
                    <tr key={row[0]} className="border-b border-border last:border-b-0">
                      {row.map((cell, index) => (
                        <td key={`${row[0]}-${index}`} className={cn('px-4 py-4 text-foreground', index < row.length - 1 && 'border-r border-border')}>{cell}</td>
                      ))}
                    </tr>
                  ))}
                </tbody>
              </table>
            </div>

            <RateSettings title="PhilHealth rate settings" description={`Saved effective date: ${headerDate}. The backend validates the statutory 2.5% / 2.5% split.`}>
              <Field label="Employee rate" value={phRow.employee_rate ?? ''} disabled={!canManageRates} onChange={(value) => patchRate('PHILHEALTH', 'employee_rate', value)} />
              <Field label="Employer rate" value={phRow.employer_rate ?? ''} disabled={!canManageRates} onChange={(value) => patchRate('PHILHEALTH', 'employer_rate', value)} />
              <Field label="Salary floor" value={phRow.salary_floor ?? ''} disabled={!canManageRates} onChange={(value) => patchRate('PHILHEALTH', 'salary_floor', value)} />
              <Field label="Salary ceiling" value={phRow.salary_ceiling ?? ''} disabled={!canManageRates} onChange={(value) => patchRate('PHILHEALTH', 'salary_ceiling', value)} />
            </RateSettings>
          </section>
        ) : null}

        {!loading && activeTab === 'PAGIBIG' ? (
          <section className="space-y-4 rounded-lg border border-border bg-card p-4 shadow-sm sm:p-5">
            <div className="flex flex-col gap-3 sm:flex-row sm:items-start sm:justify-between">
              <div>
                <h2 className="text-2xl font-bold tracking-tight text-foreground">Pag-IBIG Contribution Table</h2>
                <p className="mt-1 text-sm text-muted-foreground">Home Development Mutual Fund Law (RA 9679)</p>
              </div>
              <span className="w-fit rounded-md bg-amber-500/10 px-3 py-1.5 text-xs font-bold text-amber-700 dark:text-amber-300">HDMF</span>
            </div>

            <div className="grid gap-3 md:grid-cols-3">
              <MetricCard label="Employee Rate" value={`${formatPct(pagEeLower)} or ${formatPct(pagEeUpper)}`} caption={`Lower tier applies up to ${formatPeso(pagThreshold)}`} icon={PiggyBank} accent="text-amber-600 dark:text-amber-300" />
              <MetricCard label="Employer Rate" value={formatPct(pagErRate)} caption="Employer statutory share" />
              <MetricCard label="Monthly Cap" value={formatPeso(pagCap)} caption={`Max employee share ${formatPeso(pagCap * pagEeUpper)}`} />
            </div>

            <div className="overflow-x-auto rounded-lg border border-border">
              <table className="w-full min-w-[56rem] text-sm">
                <thead className="bg-background text-[11px] uppercase tracking-wide text-foreground">
                  <tr>
                    <th className="border-b border-r border-border px-4 py-4 text-left font-bold">Monthly Salary Range</th>
                    <th className="border-b border-r border-border px-4 py-4 text-left font-bold">EE Rate</th>
                    <th className="border-b border-r border-border px-4 py-4 text-left font-bold">ER Rate</th>
                    <th className="border-b border-r border-border px-4 py-4 text-left font-bold">Max EE Share</th>
                    <th className="border-b border-r border-border px-4 py-4 text-left font-bold">Max ER Share</th>
                    <th className="border-b border-r border-border px-4 py-4 text-left font-bold">Total At Cap</th>
                    <th className="border-b border-border px-4 py-4 text-left font-bold">Remarks</th>
                  </tr>
                </thead>
                <tbody>
                  {[
                    [`0.00 - ${formatMoney(pagThreshold)}`, formatPct(pagEeLower), formatPct(pagErRate), '-', '-', '-', 'Tier 1 employee rate'],
                    [`${formatMoney(pagThreshold + 0.01)} - ${formatMoney(pagCap)}`, formatPct(pagEeUpper), formatPct(pagErRate), '-', '-', '-', 'Tier 2 employee rate'],
                    [`Above ${formatMoney(pagCap)}`, formatPct(pagEeUpper), formatPct(pagErRate), formatPeso(pagCap * pagEeUpper), formatPeso(pagCap * pagErRate), formatPeso(pagCap * (pagEeUpper + pagErRate)), 'Capped fund salary'],
                  ].map((row) => (
                    <tr key={row[0]} className="border-b border-border last:border-b-0">
                      {row.map((cell, index) => (
                        <td key={`${row[0]}-${index}`} className={cn('px-4 py-4 text-foreground', index < row.length - 1 && 'border-r border-border')}>{cell}</td>
                      ))}
                    </tr>
                  ))}
                </tbody>
              </table>
            </div>

            <RateSettings title="Pag-IBIG rate settings" description={`Saved effective date: ${headerDate}. Employee rate may be 1% to 2%; employer rate is 2%.`}>
              <Field label="Lower EE rate" value={pagibigRow?.metadata?.employee_rate_lower ?? ''} disabled={!canManageRates} onChange={(value) => patchPagibigMetadata('employee_rate_lower', value)} />
              <Field label="Upper EE rate" value={pagibigRow.employee_rate ?? ''} disabled={!canManageRates} onChange={(value) => patchRate('PAGIBIG', 'employee_rate', value)} />
              <Field label="Employer rate" value={pagibigRow.employer_rate ?? ''} disabled={!canManageRates} onChange={(value) => patchRate('PAGIBIG', 'employer_rate', value)} />
              <Field label="Monthly cap" value={pagibigRow.monthly_cap ?? ''} disabled={!canManageRates} onChange={(value) => patchRate('PAGIBIG', 'monthly_cap', value)} />
            </RateSettings>
          </section>
        ) : null}

        {!loading && activeTab === 'WHT' ? (
          <section className="space-y-4 rounded-lg border border-border bg-card p-4 shadow-sm sm:p-5">
            <div className="flex flex-col gap-3 sm:flex-row sm:items-start sm:justify-between">
              <div>
                <h2 className="text-2xl font-bold tracking-tight text-foreground">BIR Withholding Tax Table</h2>
                <p className="mt-1 text-sm text-muted-foreground">TRAIN compensation income table and live payroll-aligned preview.</p>
              </div>
              <Button type="button" variant="outline" onClick={runWithholdingPreview} disabled={whtLoading}>
                <RefreshCw className={cn('size-4', whtLoading && 'animate-spin')} />
                Refresh Preview
              </Button>
            </div>

            <div className="grid gap-3 lg:grid-cols-4">
              <Field label="Monthly taxable compensation" value={whtSalary} onChange={setWhtSalary} />
              <Field label="13th month amount" value={thirteenthMonth} onChange={setThirteenthMonth} />
              <label className="block">
                <span className="text-[11px] font-semibold uppercase tracking-wide text-muted-foreground">Method</span>
                <select value={whtMethod} onChange={(event) => setWhtMethod(event.target.value)} className="mt-1 h-10 w-full rounded-md border border-border bg-background px-3 text-sm font-semibold text-foreground">
                  <option value="annualized">Annualized</option>
                  <option value="per_period_monthly">Per-period monthly</option>
                </select>
              </label>
              <label className="block">
                <span className="text-[11px] font-semibold uppercase tracking-wide text-muted-foreground">Period Type</span>
                <select value={periodType} onChange={(event) => setPeriodType(event.target.value)} className="mt-1 h-10 w-full rounded-md border border-border bg-background px-3 text-sm font-semibold text-foreground">
                  <option value="monthly">Monthly</option>
                  <option value="semimonthly">Semi-monthly</option>
                </select>
              </label>
            </div>

            <div className="grid gap-3 md:grid-cols-3">
              <MetricCard label="Monthly Withholding" value={formatPeso(withholdingMonthly)} caption="Estimated deduction per month" icon={Scale} accent="text-sky-600 dark:text-sky-300" />
              <MetricCard label="Annual Projection" value={formatPeso(withholdingAnnual)} caption="Projected annual income tax" />
              <MetricCard label="Effective Rate" value={`${withholdingRate.toFixed(2)}%`} caption="Against monthly taxable compensation" />
            </div>

            <div className="overflow-x-auto rounded-lg border border-border">
              <table className="w-full min-w-[56rem] text-sm">
                <thead className="bg-background text-[11px] uppercase tracking-wide text-foreground">
                  <tr>
                    <th className="border-b border-r border-border px-4 py-4 text-left font-bold">Annual Taxable Income Range</th>
                    <th className="border-b border-r border-border px-4 py-4 text-left font-bold">Tax Rate</th>
                    <th className="border-b border-r border-border px-4 py-4 text-left font-bold">Fixed Amount + Percent Of Excess</th>
                    <th className="border-b border-border px-4 py-4 text-left font-bold">Source / Notes</th>
                  </tr>
                </thead>
                <tbody>
                  {taxTableRows.map((row) => (
                    <tr key={row.id} className="border-b border-border last:border-b-0">
                      <td className="border-r border-border px-4 py-4 text-foreground">{row.incomeRange}</td>
                      <td className="border-r border-border px-4 py-4 font-semibold text-foreground">{row.taxRate || '-'}</td>
                      <td className="border-r border-border px-4 py-4 text-foreground">{row.formula || '-'}</td>
                      <td className="px-4 py-4 text-muted-foreground">{row.notes || '-'}</td>
                    </tr>
                  ))}
                </tbody>
              </table>
            </div>
          </section>
        ) : null}

        {!loading && activeTab === 'EMPLOYEE_EXEMPTIONS' ? (
          <EmployeeExemptionsTab canManage={canManageRates} />
        ) : null}

        {!loading && activeTab === 'AUDIT' ? (
          <section className="space-y-4">
            <div className="grid gap-3 md:grid-cols-4">
              <MetricCard label="Period" value={summary?.period_label || 'Current month'} caption="Dashboard estimate window" icon={Calendar} />
              <MetricCard label="Employees Included" value={String(summary?.headcount_included ?? 0)} caption="Active in-scope employees with salary" icon={ShieldCheck} accent="text-emerald-600 dark:text-emerald-300" />
              <MetricCard label="EE Statutory" value={formatPeso(summary?.estimated_total_employee_statutory ?? 0)} caption="SSS + PhilHealth + Pag-IBIG" />
              <MetricCard label="Monthly WHT" value={formatPeso(summary?.estimated_total_withholding_tax_monthly ?? 0)} caption="Estimated BIR withholding" />
            </div>

            <div className="rounded-lg border border-border bg-card p-4 shadow-sm sm:p-5">
              <div className="flex flex-col gap-3 lg:flex-row lg:items-end lg:justify-between">
                <div>
                  <h2 className="text-2xl font-bold tracking-tight text-foreground">Compliance Audit</h2>
                  <p className="mt-1 text-sm text-muted-foreground">Preview statutory contributions and withholding using the same calculator path as payroll.</p>
                </div>
                <div className="flex flex-col gap-2 sm:flex-row sm:items-end">
                  <Field label="Basic salary" value={basicSalary} onChange={setBasicSalary} />
                  <Button type="button" onClick={runAudit} disabled={auditLoading} className="h-10 bg-brand text-brand-foreground hover:bg-brand-strong">
                    <RefreshCw className={cn('size-4', auditLoading && 'animate-spin')} />
                    Refresh
                  </Button>
                </div>
              </div>

              <div className="mt-5 grid gap-4 lg:grid-cols-2">
                {[
                  {
                    title: 'SSS including EC',
                    subtitle: 'MSC bracket plus employer-only EC',
                    icon: Landmark,
                    rows: [
                      ['Employee', audit?.[AUDIT_SSS_KEY]?.employee_amount],
                      ['Employer', audit?.[AUDIT_SSS_KEY]?.employer_amount],
                      ['EC (employer only)', audit?.[AUDIT_SSS_KEY]?.ec_amount],
                      ['Total SSS + EC', audit?.[AUDIT_SSS_KEY]?.total_amount],
                    ],
                    foot: `MSC: ${audit?.[AUDIT_SSS_KEY]?.msc_bracket_range || '-'}${audit?.[AUDIT_SSS_KEY]?.msc_used ? ` | ${formatPeso(audit?.[AUDIT_SSS_KEY]?.msc_used)}` : ''}`,
                  },
                  {
                    title: 'PhilHealth',
                    subtitle: `${formatPct(phTotalRate)} total premium split equally`,
                    icon: HeartPulse,
                    rows: [
                      ['Employee', audit?.philhealth?.employee_amount],
                      ['Employer', audit?.philhealth?.employer_amount],
                      ['Total', audit?.philhealth?.total_amount],
                    ],
                    foot: `Applied salary: ${formatPeso(audit?.philhealth?.metadata?.applied_salary ?? 0)}`,
                  },
                  {
                    title: 'Pag-IBIG',
                    subtitle: `Fund salary capped at ${formatPeso(pagCap)}`,
                    icon: PiggyBank,
                    rows: [
                      ['Employee', audit?.pagibig?.employee_amount],
                      ['Employer', audit?.pagibig?.employer_amount],
                      ['Total', audit?.pagibig?.total_amount],
                    ],
                    foot: `Applied salary: ${formatPeso(audit?.pagibig?.metadata?.applied_salary ?? 0)}${audit?.pagibig?.metadata?.cap_applied ? ' (capped)' : ''}`,
                  },
                  {
                    title: 'Payroll Totals',
                    subtitle: 'Employee deductions and employer liabilities',
                    icon: Scale,
                    rows: [
                      ['Total employee deductions', audit?.totals?.employee_deduction],
                      ['Total employer liability', audit?.totals?.employer_liability],
                      ['Overall statutory', audit?.totals?.overall_statutory],
                    ],
                    foot: summary?.disclaimer || 'Actual payroll may differ because of adjustments and attendance-driven earnings.',
                  },
                ].map((card) => {
                  const Icon = card.icon
                  return (
                    <div key={card.title} className="rounded-lg border border-border bg-background p-4">
                      <div className="flex items-start gap-3 border-b border-border pb-3">
                        <Icon className="mt-0.5 size-5 shrink-0 text-brand" aria-hidden />
                        <div>
                          <h3 className="font-bold text-foreground">{card.title}</h3>
                          <p className="text-xs text-muted-foreground">{card.subtitle}</p>
                        </div>
                      </div>
                      <dl className="mt-4 space-y-2.5">
                        {card.rows.map(([label, value]) => (
                          <div key={label} className="flex items-center justify-between gap-3 text-sm">
                            <dt className="text-muted-foreground">{label}</dt>
                            <dd className="font-bold tabular-nums text-foreground">{formatPeso(value ?? 0)}</dd>
                          </div>
                        ))}
                      </dl>
                      <p className="mt-4 border-t border-border pt-3 text-xs text-muted-foreground">{card.foot}</p>
                    </div>
                  )
                })}
              </div>
            </div>

            <div className="grid gap-4 xl:grid-cols-[minmax(0,0.95fr)_minmax(0,1.25fr)]">
              <div className="rounded-lg border border-border bg-card p-4 shadow-sm sm:p-5">
                <h2 className="text-lg font-bold text-foreground">Generate Remittance</h2>
                <p className="mt-1 text-sm text-muted-foreground">Creates a pending batch for agency filing review.</p>
                <div className="mt-4 grid gap-3 sm:grid-cols-2">
                  <label className="block">
                    <span className="text-[11px] font-semibold uppercase tracking-wide text-muted-foreground">Agency</span>
                    <select
                      value={remittanceDraft.agency}
                      onChange={(event) => setRemittanceDraft((draft) => ({ ...draft, agency: event.target.value }))}
                      className="mt-1 h-10 w-full rounded-md border border-border bg-background px-3 text-sm font-semibold text-foreground"
                    >
                      <option value="SSS">SSS</option>
                      <option value="PHILHEALTH">PhilHealth</option>
                      <option value="PAGIBIG">Pag-IBIG</option>
                      <option value="BIR">BIR</option>
                    </select>
                  </label>
                  <label className="block">
                    <span className="text-[11px] font-semibold uppercase tracking-wide text-muted-foreground">Report Kind</span>
                    <select
                      value={remittanceDraft.report_kind}
                      onChange={(event) => setRemittanceDraft((draft) => ({ ...draft, report_kind: event.target.value }))}
                      className="mt-1 h-10 w-full rounded-md border border-border bg-background px-3 text-sm font-semibold text-foreground"
                    >
                      {remittanceKindOptions.map((kind) => <option key={kind} value={kind}>{kind}</option>)}
                    </select>
                  </label>
                  <Field label="Year" value={remittanceDraft.period_year} onChange={(value) => setRemittanceDraft((draft) => ({ ...draft, period_year: value }))} />
                  <Field label="Month" value={remittanceDraft.period_month} onChange={(value) => setRemittanceDraft((draft) => ({ ...draft, period_month: value }))} />
                </div>
                <Button type="button" onClick={createRemittance} disabled={remittanceLoading} className="mt-4 w-full bg-brand text-brand-foreground hover:bg-brand-strong">
                  <FileClock className="size-4" />
                  {remittanceLoading ? 'Generating...' : 'Generate Pending Batch'}
                </Button>
              </div>

              <div className="rounded-lg border border-border bg-card p-4 shadow-sm sm:p-5">
                <h2 className="text-lg font-bold text-foreground">Recent Remittances</h2>
                <div className="mt-4 overflow-x-auto rounded-lg border border-border">
                  <table className="w-full min-w-[42rem] text-sm">
                    <thead className="bg-background text-[11px] uppercase tracking-wide text-foreground">
                      <tr>
                        <th className="border-b border-r border-border px-3 py-3 text-left font-bold">Period</th>
                        <th className="border-b border-r border-border px-3 py-3 text-left font-bold">Agency</th>
                        <th className="border-b border-r border-border px-3 py-3 text-left font-bold">Kind</th>
                        <th className="border-b border-r border-border px-3 py-3 text-left font-bold">Status</th>
                        <th className="border-b border-r border-border px-3 py-3 text-right font-bold">Employee</th>
                        <th className="border-b border-border px-3 py-3 text-right font-bold">Employer</th>
                      </tr>
                    </thead>
                    <tbody>
                      {remittances.map((row) => (
                        <tr key={row.id} className="border-b border-border last:border-b-0">
                          <td className="border-r border-border px-3 py-3 text-foreground">{monthName(row.period_month)} {row.period_year}</td>
                          <td className="border-r border-border px-3 py-3 font-semibold text-foreground">{row.agency}</td>
                          <td className="border-r border-border px-3 py-3 text-muted-foreground">{row.report_kind}</td>
                          <td className="border-r border-border px-3 py-3">
                            <span className="rounded-md bg-amber-500/10 px-2 py-1 text-xs font-bold text-amber-700 dark:text-amber-300">{row.status}</span>
                          </td>
                          <td className="border-r border-border px-3 py-3 text-right font-semibold text-foreground">{formatPeso(row.total_employee_amount)}</td>
                          <td className="px-3 py-3 text-right font-semibold text-foreground">{formatPeso(row.total_employer_amount)}</td>
                        </tr>
                      ))}
                      {remittances.length === 0 ? (
                        <tr>
                          <td colSpan={6} className="px-3 py-8 text-center text-sm text-muted-foreground">No remittance batches yet.</td>
                        </tr>
                      ) : null}
                    </tbody>
                  </table>
                </div>
              </div>
            </div>
          </section>
        ) : null}
      </div>

      {historyOpen ? (
        <div className="fixed inset-0 z-50 flex items-center justify-center bg-black/45 p-3 backdrop-blur-sm">
          <div className="flex max-h-[88vh] w-full max-w-6xl flex-col overflow-hidden rounded-lg border border-border bg-card text-foreground shadow-2xl">
            <div className="flex items-start justify-between gap-4 border-b border-border px-5 py-4">
              <div>
                <h2 className="text-lg font-bold text-foreground">History of Rates</h2>
                <p className="mt-1 text-sm text-muted-foreground">Audit trail for SSS, PhilHealth, Pag-IBIG, and EC rate updates.</p>
              </div>
              <button type="button" onClick={() => setHistoryOpen(false)} className="inline-flex size-9 items-center justify-center rounded-md border border-border bg-background text-foreground hover:bg-muted">
                <X className="size-4" />
                <span className="sr-only">Close</span>
              </button>
            </div>
            <div className="min-h-0 flex-1 space-y-4 overflow-y-auto p-5">
              <div className="flex flex-wrap items-center gap-2">
                <select
                  value={historyCodeFilter}
                  onChange={(event) => setHistoryCodeFilter(event.target.value)}
                  className="h-10 rounded-md border border-border bg-background px-3 text-sm font-semibold text-foreground"
                >
                  <option value="">All Codes</option>
                  <option value="SSS">SSS</option>
                  <option value="PHILHEALTH">PhilHealth</option>
                  <option value="PAGIBIG">Pag-IBIG</option>
                  <option value="EC">EC</option>
                </select>
                <Button type="button" onClick={() => loadHistory(historyCodeFilter)} disabled={historyLoading} className="bg-brand text-brand-foreground hover:bg-brand-strong">
                  <Clock3 className={cn('size-4', historyLoading && 'animate-spin')} />
                  Apply Filter
                </Button>
              </div>

              <div className="overflow-x-auto rounded-lg border border-border">
                <table className="w-full min-w-[60rem] text-sm">
                  <thead className="bg-background text-[11px] uppercase tracking-wide text-foreground">
                    <tr>
                      <th className="border-b border-r border-border px-3 py-3 text-left font-bold">Date / Time</th>
                      <th className="border-b border-r border-border px-3 py-3 text-left font-bold">Code</th>
                      <th className="border-b border-r border-border px-3 py-3 text-left font-bold">Effective Date</th>
                      <th className="border-b border-r border-border px-3 py-3 text-left font-bold">Action</th>
                      <th className="border-b border-r border-border px-3 py-3 text-left font-bold">Changed By</th>
                      <th className="border-b border-border px-3 py-3 text-left font-bold">Changes</th>
                    </tr>
                  </thead>
                  <tbody>
                    {historyLoading ? (
                      <tr>
                        <td colSpan={6} className="px-3 py-8 text-center text-muted-foreground">Loading history...</td>
                      </tr>
                    ) : null}
                    {!historyLoading && historyRows.length === 0 ? (
                      <tr>
                        <td colSpan={6} className="px-3 py-8 text-center text-muted-foreground">No history records found.</td>
                      </tr>
                    ) : null}
                    {!historyLoading && historyRows.map((row) => (
                      <tr key={row.id} className="border-b border-border align-top last:border-b-0">
                        <td className="border-r border-border px-3 py-3 text-muted-foreground">{row.created_at || '-'}</td>
                        <td className="border-r border-border px-3 py-3 font-bold text-foreground">{row.code || '-'}</td>
                        <td className="border-r border-border px-3 py-3 text-foreground">{row.effective_from || '-'}</td>
                        <td className="border-r border-border px-3 py-3">
                          <span className="rounded-md bg-sky-500/10 px-2 py-1 text-xs font-bold text-sky-700 dark:text-sky-300">{row.action || 'updated'}</span>
                        </td>
                        <td className="border-r border-border px-3 py-3 text-foreground">{row.changed_by?.name || row.changed_by?.email || 'System'}</td>
                        <td className="px-3 py-3 text-xs text-muted-foreground">
                          {row.changed_fields && Object.keys(row.changed_fields).length > 0
                            ? Object.entries(row.changed_fields).map(([key, value]) => (
                                <div key={`${row.id}-${key}`} className="mb-1">
                                  <span className="font-bold text-foreground">{key}</span>: {String(value?.old ?? 'null')} to {String(value?.new ?? 'null')}
                                </div>
                              ))
                            : 'Initial values saved / no field diff captured'}
                        </td>
                      </tr>
                    ))}
                  </tbody>
                </table>
              </div>
            </div>
          </div>
        </div>
      ) : null}
    </div>
  )
}
