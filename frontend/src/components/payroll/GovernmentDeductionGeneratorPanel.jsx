import { useCallback, useEffect, useMemo, useRef, useState } from 'react'
import { Link } from 'react-router-dom'
import {
  AlertTriangle,
  Building2,
  CalendarClock,
  Clock3,
  CreditCard,
  Download,
  FileText,
  HeartPulse,
  Landmark,
  PiggyBank,
  RefreshCw,
  Save,
  Search,
  ShieldCheck,
  UserRound,
} from 'lucide-react'
import {
  generateGovernmentEmployeeDeductions,
  getBranches,
  getCompanies,
  getDepartments,
  getPayCycles,
  getStatutoryRemittance,
  listStatutoryRemittances,
  previewPayCycle,
  saveGovernmentEmployeeDeductions,
} from '@/api'
import { Avatar, AvatarFallback, AvatarImage } from '@/components/ui/avatar'
import { Badge } from '@/components/ui/badge'
import { Button } from '@/components/ui/button'
import { Card, CardContent, CardDescription, CardHeader, CardTitle } from '@/components/ui/card'
import { Input } from '@/components/ui/input'
import { Label } from '@/components/ui/label'
import { Select, SelectContent, SelectItem, SelectTrigger, SelectValue } from '@/components/ui/select'
import { Switch } from '@/components/ui/switch'
import { employeeAvatarSrc, getEmployeeAvatarColorClass } from '@/lib/employeeAvatar'
import { cn } from '@/lib/utils'
import { useToast } from '@/components/ui/use-toast'

const PER_PAGE = 25

const SELECT_TRIGGER =
  'h-11 rounded-md border border-border bg-background px-3 text-sm font-semibold text-foreground shadow-sm'
const SELECT_CONTENT = 'rounded-md border border-border bg-popover p-1 shadow-md'
const CARD_SHELL = 'rounded-lg border border-border bg-card text-card-foreground shadow-sm'
const TABLE_HEAD = 'bg-background text-[11px] uppercase tracking-wide text-foreground'
const TABLE_TH = 'border-b border-r border-border px-2 py-2.5 text-left font-bold last:border-r-0 lg:px-3 lg:py-3'
const TABLE_TD = 'border-b border-r border-border px-2 py-2.5 align-middle last:border-r-0 lg:px-3 lg:py-3'

function GovIdsInline({ ids, highlight }) {
  const items = [
    { key: 'sss', label: 'SSS', value: ids?.sss_number },
    { key: 'philhealth', label: 'PhilHealth', value: ids?.philhealth_number },
    { key: 'pagibig', label: 'Pag-IBIG', value: ids?.pagibig_number },
    { key: 'tin', label: 'TIN', value: ids?.tin_number },
  ]
  return (
    <div className="flex flex-wrap gap-x-3 gap-y-1 text-[11px] font-mono text-muted-foreground">
      {items.map((item) => (
        <span
          key={item.key}
          className={cn(
            highlight === item.key && 'rounded bg-brand/10 px-1.5 py-0.5 font-semibold text-brand',
          )}
        >
          {item.label}: {item.value || '—'}
        </span>
      ))}
    </div>
  )
}

const ROSTER_TABS = [
  { id: 'ALL', label: 'All Deductions', icon: FileText },
  { id: 'SSS', label: 'SSS', icon: ShieldCheck },
  { id: 'PHILHEALTH', label: 'PhilHealth', icon: HeartPulse },
  { id: 'PAGIBIG', label: 'Pag-IBIG', icon: PiggyBank },
  { id: 'WHT', label: 'Withholding Tax', icon: Landmark },
  { id: 'LOANS', label: 'Loans & Custom', icon: CreditCard },
  { id: 'GOV_IDS', label: 'Government IDs', icon: UserRound },
  { id: 'MISSING', label: 'Missing Info', icon: AlertTriangle },
]

function payCyclePeriodKey(period) {
  return `${period.cut_off_start_date}|${period.cut_off_end_date}|${period.pay_date || ''}`
}

function formatMoney(value) {
  const n = Number(value)
  if (!Number.isFinite(n)) return '0.00'
  return n.toLocaleString('en-PH', { minimumFractionDigits: 2, maximumFractionDigits: 2 })
}

function formatPeriodRange(from, to) {
  if (!from || !to) return '—'
  try {
    const a = new Date(`${from}T12:00:00`)
    const b = new Date(`${to}T12:00:00`)
    const opts = { month: 'short', day: 'numeric', year: 'numeric' }
    return `${a.toLocaleDateString(undefined, opts)} – ${b.toLocaleDateString(undefined, opts)}`
  } catch {
    return `${from} – ${to}`
  }
}

function MissingBadges({ items }) {
  if (!items?.length) {
    return <span className="text-xs text-emerald-600 dark:text-emerald-400">Complete</span>
  }
  return (
    <div className="flex flex-wrap gap-1">
      {items.map((item) => (
        <Badge
          key={item.field}
          variant="outline"
          className={cn(
            'text-[10px] font-semibold',
            item.severity === 'error'
              ? 'border-rose-300 bg-rose-50 text-rose-800 dark:border-rose-500/40 dark:bg-rose-950/30 dark:text-rose-200'
              : 'border-amber-300 bg-amber-50 text-amber-900 dark:border-amber-500/40 dark:bg-amber-950/30 dark:text-amber-100',
          )}
        >
          {item.label}
        </Badge>
      ))}
    </div>
  )
}

export default function GovernmentDeductionGeneratorPanel() {
  const { toast } = useToast()
  const suppressCycleApplyRef = useRef(false)
  const [companies, setCompanies] = useState([])
  const [branches, setBranches] = useState([])
  const [departments, setDepartments] = useState([])
  const [payCycles, setPayCycles] = useState([])
  const [cyclePeriods, setCyclePeriods] = useState([])

  const [companyId, setCompanyId] = useState('')
  const [branchId, setBranchId] = useState('')
  const [departmentId, setDepartmentId] = useState('')
  const [payCycleId, setPayCycleId] = useState('')
  const [selectedPeriodKey, setSelectedPeriodKey] = useState('')
  const [fromDate, setFromDate] = useState('')
  const [toDate, setToDate] = useState('')
  const [referenceDate, setReferenceDate] = useState('')

  const [rosterTab, setRosterTab] = useState('ALL')
  const [search, setSearch] = useState('')
  const [missingOnly, setMissingOnly] = useState(false)
  const [page, setPage] = useState(1)

  const [loadingScope, setLoadingScope] = useState(true)
  const [generating, setGenerating] = useState(false)
  const [saving, setSaving] = useState(false)
  const [error, setError] = useState('')
  const [allRows, setAllRows] = useState([])
  const [result, setResult] = useState(null)
  const [savedRosters, setSavedRosters] = useState([])
  const [loadingSavedRosters, setLoadingSavedRosters] = useState(false)
  const [loadingSavedRosterId, setLoadingSavedRosterId] = useState(null)
  const [exporting, setExporting] = useState(false)

  useEffect(() => {
    let cancelled = false
    ;(async () => {
      setLoadingScope(true)
      try {
        const [companyRes, cycleRes] = await Promise.all([
          getCompanies({ fresh: true }),
          getPayCycles(),
        ])
        if (cancelled) return
        setCompanies(Array.isArray(companyRes?.companies) ? companyRes.companies : [])
        setPayCycles(Array.isArray(cycleRes?.data) ? cycleRes.data : [])
      } catch (err) {
        if (!cancelled) setError(err?.message || 'Failed to load scope options.')
      } finally {
        if (!cancelled) setLoadingScope(false)
      }
    })()
    return () => { cancelled = true }
  }, [])

  useEffect(() => {
    if (!companyId) {
      setBranches([])
      setDepartments([])
      setBranchId('')
      setDepartmentId('')
      return
    }
    let cancelled = false
    ;(async () => {
      try {
        const res = await getBranches({ company_id: companyId })
        if (!cancelled) setBranches(Array.isArray(res?.data) ? res.data : res?.branches || [])
      } catch {
        if (!cancelled) setBranches([])
      }
    })()
    return () => { cancelled = true }
  }, [companyId])

  useEffect(() => {
    if (!branchId) {
      setDepartments([])
      setDepartmentId('')
      return
    }
    let cancelled = false
    ;(async () => {
      try {
        const res = await getDepartments({ branch_id: branchId })
        if (!cancelled) setDepartments(Array.isArray(res?.data) ? res.data : res?.departments || [])
      } catch {
        if (!cancelled) setDepartments([])
      }
    })()
    return () => { cancelled = true }
  }, [branchId])

  const selectedCycle = useMemo(
    () => payCycles.find((c) => String(c.id) === String(payCycleId)) || null,
    [payCycles, payCycleId],
  )

  const applyPeriod = useCallback((period) => {
    if (!period) return
    setSelectedPeriodKey(payCyclePeriodKey(period))
    setFromDate(period.cut_off_start_date || '')
    setToDate(period.cut_off_end_date || '')
    setReferenceDate(period.pay_date || '')
    setPage(1)
  }, [])

  useEffect(() => {
    if (!selectedCycle) {
      setCyclePeriods([])
      setSelectedPeriodKey('')
      return undefined
    }
    let cancelled = false
    const cycleSnapshot = {
      code: selectedCycle.code,
      name: selectedCycle.name,
      cut_off_type: selectedCycle.cut_off_type,
      cut_off_value: selectedCycle.cut_off_value,
      pay_day_type: selectedCycle.pay_day_type,
      pay_day_value: selectedCycle.pay_day_value,
      pay_day_offset: selectedCycle.pay_day_offset,
      pro_ration_type: selectedCycle.pro_ration_type,
      metadata: selectedCycle.metadata,
    }
    ;(async () => {
      try {
        const res = await previewPayCycle(cycleSnapshot)
        const periods = Array.isArray(res?.data?.preview_periods) ? res.data.preview_periods : []
        if (cancelled) return
        setCyclePeriods(periods)
        if (periods[0] && !suppressCycleApplyRef.current) {
          applyPeriod(periods[0])
        }
      } catch (err) {
        if (!cancelled) {
          setCyclePeriods([])
          setError(err?.message || 'Failed to load pay cycle periods.')
        }
      }
    })()
    return () => { cancelled = true }
  }, [applyPeriod, payCycleId, selectedCycle?.code, selectedCycle?.cut_off_type, selectedCycle?.pay_day_type, selectedCycle?.pay_day_offset])

  const canGenerate = Boolean(companyId && fromDate && toDate)
  const scopeHint = !companyId
    ? 'Select a company to continue.'
    : !fromDate || !toDate
      ? 'Select a pay cycle period, or enter a manual date range below.'
      : ''

  const loadSavedRosters = useCallback(async () => {
    if (!companyId) {
      setSavedRosters([])
      return
    }
    setLoadingSavedRosters(true)
    try {
      const data = await listStatutoryRemittances({
        agency: 'DEDUCTION_ROSTER',
        company_id: Number(companyId),
        page: 1,
        per_page: 12,
      })
      setSavedRosters(Array.isArray(data?.data) ? data.data : [])
    } catch {
      setSavedRosters([])
    } finally {
      setLoadingSavedRosters(false)
    }
  }, [companyId])

  useEffect(() => {
    void loadSavedRosters()
  }, [loadSavedRosters])

  const loadSavedRoster = useCallback(async (remittanceId) => {
    setLoadingSavedRosterId(remittanceId)
    setGenerating(false)
    setError('')
    try {
      const data = await getStatutoryRemittance(remittanceId)
      const payload = data?.payload
      if (!payload || !Array.isArray(payload?.data)) {
        throw new Error('Saved roster payload is empty.')
      }
      const scope = payload.scope || {}
      suppressCycleApplyRef.current = true
      if (scope.from_date) setFromDate(scope.from_date)
      if (scope.to_date) setToDate(scope.to_date)
      if (scope.reference_date) setReferenceDate(scope.reference_date)
      if (scope.pay_cycle_id) setPayCycleId(String(scope.pay_cycle_id))
      setAllRows(payload.data)
      setResult(payload)
      setPage(1)
      window.setTimeout(() => {
        suppressCycleApplyRef.current = false
      }, 0)
      toast({
        title: 'Saved roster loaded',
        description: `${payload.data.length} employees from ${formatPeriodRange(scope.from_date, scope.to_date)}.`,
      })
    } catch (err) {
      suppressCycleApplyRef.current = false
      setError(err?.message || 'Failed to load saved roster.')
    } finally {
      setLoadingSavedRosterId(null)
    }
  }, [toast])

  const runGenerate = useCallback(async () => {
    if (!canGenerate) {
      return
    }
    setGenerating(true)
    setError('')
    setPage(1)
    try {
      const data = await generateGovernmentEmployeeDeductions({
        company_id: Number(companyId),
        branch_id: branchId ? Number(branchId) : undefined,
        department_id: departmentId ? Number(departmentId) : undefined,
        from_date: fromDate,
        to_date: toDate,
        pay_cycle_id: payCycleId ? Number(payCycleId) : undefined,
        reference_date: referenceDate || undefined,
        return_all: true,
      })
      setAllRows(Array.isArray(data?.data) ? data.data : [])
      setResult(data)
    } catch (err) {
      setError(err?.message || 'Failed to generate employee deductions.')
      setAllRows([])
      setResult(null)
    } finally {
      setGenerating(false)
    }
  }, [branchId, canGenerate, companyId, departmentId, fromDate, payCycleId, referenceDate, toDate])

  const runSave = useCallback(async () => {
    if (!canGenerate || allRows.length === 0) {
      return
    }
    setSaving(true)
    setError('')
    try {
      const data = await saveGovernmentEmployeeDeductions({
        company_id: Number(companyId),
        branch_id: branchId ? Number(branchId) : undefined,
        department_id: departmentId ? Number(departmentId) : undefined,
        from_date: fromDate,
        to_date: toDate,
        pay_cycle_id: payCycleId ? Number(payCycleId) : undefined,
        reference_date: referenceDate || undefined,
        payload: result || undefined,
      })
      toast({
        title: 'Roster saved',
        description: `${data?.row_count ?? allRows.length} employee rows saved for ${formatPeriodRange(fromDate, toDate)}.`,
      })
      void loadSavedRosters()
    } catch (err) {
      const message = err?.message || 'Failed to save deductions roster.'
      setError(message)
      toast({ title: 'Save failed', description: message, variant: 'destructive' })
    } finally {
      setSaving(false)
    }
  }, [allRows.length, branchId, canGenerate, companyId, departmentId, fromDate, loadSavedRosters, payCycleId, referenceDate, result, toast, toDate])

  const filteredEmployees = useMemo(() => {
    let base = allRows
    if (missingOnly || rosterTab === 'MISSING') {
      base = base.filter((row) => row.has_missing_info)
    }
    const needle = search.trim().toLowerCase()
    if (needle) {
      base = base.filter((row) => {
        const name = String(row.name || '').toLowerCase()
        const code = String(row.employee_code || '').toLowerCase()
        return name.includes(needle) || code.includes(needle)
      })
    }
    return base
  }, [allRows, missingOnly, rosterTab, search])

  const exportToExcel = useCallback(async () => {
    if (filteredEmployees.length === 0) {
      toast({ title: 'Nothing to export', description: 'Generate or load a roster first.', variant: 'destructive' })
      return
    }
    setExporting(true)
    try {
      const employeeHeaders = [
        'Employee No.',
        'Employee Name',
        'Department',
        'SSS No.',
        'PhilHealth No.',
        'Pag-IBIG No.',
        'TIN',
        'SSS',
        'PhilHealth',
        'Pag-IBIG',
        'Withholding Tax',
        'Loans / Custom',
        'Missing Info',
      ]
      const employeeRows = filteredEmployees.map((row) => {
        const ids = row.government_ids || {}
        const d = row.deductions || {}
        const missing = (row.missing_info || []).map((m) => m.label).join('; ')
        return [
          row.employee_code || '',
          row.name || '',
          row.department || '',
          ids.sss_number || '',
          ids.philhealth_number || '',
          ids.pagibig_number || '',
          ids.tin_number || '',
          Number(d.sss || 0),
          Number(d.philhealth || 0),
          Number(d.pagibig || 0),
          Number(d.withholding_tax || 0),
          Number(d.custom_deductions || 0),
          missing || 'Complete',
        ]
      })

      const loanHeaders = [
        'Employee No.',
        'Employee Name',
        'Loan / Deduction',
        'Code',
        'Type',
        'This Period',
        'Balance',
        'Schedule',
      ]
      const loanRows = []
      for (const row of filteredEmployees) {
        for (const loan of row.loan_lines || []) {
          loanRows.push([
            row.employee_code || '',
            row.name || '',
            loan.name || '',
            loan.code || '',
            loan.category || '',
            Number(loan.amount_this_period || 0),
            loan.remaining_balance != null ? Number(loan.remaining_balance) : '',
            loan.schedule_type || '',
          ])
        }
      }

      const workbookName = `government-deductions-${fromDate || 'period'}-${toDate || ''}.xlsx`
      const ExcelJS = (await import('exceljs')).default
      const workbook = new ExcelJS.Workbook()
      const mainSheet = workbook.addWorksheet('Employee Deductions')
      mainSheet.addRow(employeeHeaders)
      employeeRows.forEach((r) => mainSheet.addRow(r))
      mainSheet.getRow(1).font = { bold: true }

      const loanSheet = workbook.addWorksheet('Loan Lines')
      loanSheet.addRow(loanHeaders)
      loanRows.forEach((r) => loanSheet.addRow(r))
      loanSheet.getRow(1).font = { bold: true }

      const buffer = await workbook.xlsx.writeBuffer()
      const blob = new Blob([buffer], {
        type: 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
      })
      const url = URL.createObjectURL(blob)
      const a = document.createElement('a')
      a.href = url
      a.download = workbookName
      document.body.appendChild(a)
      a.click()
      a.remove()
      URL.revokeObjectURL(url)

      toast({
        title: 'Excel downloaded',
        description: `${employeeRows.length} employees${loanRows.length ? `, ${loanRows.length} loan lines` : ''}.`,
      })
    } catch (err) {
      toast({ title: 'Export failed', description: err?.message || 'Could not export Excel file.', variant: 'destructive' })
    } finally {
      setExporting(false)
    }
  }, [filteredEmployees, fromDate, toast, toDate])

  const loanTableRows = useMemo(() => {
    const out = []
    for (const employee of filteredEmployees) {
      for (const loan of employee.loan_lines || []) {
        out.push({ employee, loan })
      }
    }
    return out
  }, [filteredEmployees])

  const paginationMeta = useMemo(() => {
    const total = rosterTab === 'LOANS' ? loanTableRows.length : filteredEmployees.length
    const lastPage = Math.max(1, Math.ceil(total / PER_PAGE))
    const safePage = Math.min(page, lastPage)
    return { total, page: safePage, per_page: PER_PAGE, last_page: lastPage }
  }, [filteredEmployees.length, loanTableRows.length, page, rosterTab])

  const rows = useMemo(() => {
    const { page: safePage } = paginationMeta
    const start = (safePage - 1) * PER_PAGE
    if (rosterTab === 'LOANS') {
      return loanTableRows.slice(start, start + PER_PAGE)
    }
    return filteredEmployees.slice(start, start + PER_PAGE)
  }, [filteredEmployees, loanTableRows, paginationMeta, rosterTab])

  const summary = result?.summary || null

  useEffect(() => {
    if (rosterTab === 'MISSING') {
      setMissingOnly(true)
    }
  }, [rosterTab])

  useEffect(() => {
    setPage(1)
  }, [missingOnly, rosterTab, search])

  function amountForTab(row, tab) {
    const d = row?.deductions || {}
    if (tab === 'SSS') return d.sss
    if (tab === 'PHILHEALTH') return d.philhealth
    if (tab === 'PAGIBIG') return d.pagibig
    if (tab === 'WHT') return d.withholding_tax
    if (tab === 'LOANS') return d.custom_deductions
    return d.employee_statutory
  }

  const govHighlight = rosterTab === 'SSS'
    ? 'sss'
    : rosterTab === 'PHILHEALTH'
      ? 'philhealth'
      : rosterTab === 'PAGIBIG'
        ? 'pagibig'
        : rosterTab === 'WHT'
          ? 'tin'
          : null

  const rosterLoading = generating
  const rosterLoadingMessage = 'Generating payroll-aligned deductions...'

  function renderEmployeeCell(row) {
    const profileHref = `/admin/profile/${row.user_id}?tab=government-ids`
    const avatarSrc = employeeAvatarSrc(row)
    return (
      <td className={TABLE_TD}>
        <div className="flex min-w-0 items-center gap-2.5">
          <Avatar className={cn('h-9 w-9 shrink-0', getEmployeeAvatarColorClass(row.user_id, row.name))}>
            <AvatarImage src={avatarSrc || undefined} alt="" />
            <AvatarFallback>{String(row.name || '?').slice(0, 1)}</AvatarFallback>
          </Avatar>
          <div className="min-w-0 flex-1">
            <Link to={profileHref} className="block truncate text-sm font-semibold text-brand hover:underline">
              {row.name}
            </Link>
            <p className="truncate text-[11px] text-muted-foreground">
              {row.employee_code || 'No employee no.'} · {row.department || 'No department'}
            </p>
            <GovIdsInline ids={row.government_ids} highlight={govHighlight} />
          </div>
        </div>
      </td>
    )
  }

  function renderTableHeaders() {
    const th = (label, align = 'left', className = '') => (
      <th className={cn(TABLE_TH, align === 'right' ? 'text-right' : 'text-left', className)}>{label}</th>
    )
    if (rosterTab === 'GOV_IDS') {
      return (
        <tr>
          {th('Employee', 'left', 'w-[28%]')}
          {th('SSS')}
          {th('PhilHealth')}
          {th('Pag-IBIG')}
          {th('TIN')}
          {th('Missing')}
        </tr>
      )
    }
    if (rosterTab === 'LOANS') {
      return (
        <tr>
          {th('Employee', 'left', 'w-[24%]')}
          {th('Loan / Deduction')}
          {th('Code')}
          {th('Type')}
          {th('This Period', 'right')}
          {th('Balance', 'right')}
          {th('Schedule')}
        </tr>
      )
    }
    if (rosterTab === 'ALL') {
      return (
        <tr>
          {th('Employee', 'left', 'w-[24%]')}
          {th('SSS', 'right')}
          {th('PhilHealth', 'right')}
          {th('Pag-IBIG', 'right')}
          {th('WHT', 'right')}
          {th('Loans')}
          {th('Missing')}
        </tr>
      )
    }
    return (
      <tr>
        {th('Employee', 'left', 'w-[32%]')}
        {th('This Period', 'right')}
        {th('Monthly', 'right')}
        {th('Missing')}
      </tr>
    )
  }

  function renderTableRow(entry) {
    if (rosterTab === 'LOANS') {
      const { employee, loan } = entry
      const rowKey = `${employee.user_id}-${loan.pay_component_id || loan.employee_deduction_id || loan.code || loan.name}`
      return (
        <tr key={rowKey} className="last:border-b-0">
          {renderEmployeeCell(employee)}
          <td className={cn(TABLE_TD, 'text-sm font-medium')}>{loan.name || '—'}</td>
          <td className={cn(TABLE_TD, 'font-mono text-[11px] text-muted-foreground')}>{loan.code || '—'}</td>
          <td className={TABLE_TD}>
            <Badge variant="outline" className="text-[10px] font-semibold">{loan.category || 'Deduction'}</Badge>
          </td>
          <td className={cn(TABLE_TD, 'text-right text-sm font-semibold tabular-nums')}>₱{formatMoney(loan.amount_this_period)}</td>
          <td className={cn(TABLE_TD, 'text-right text-sm tabular-nums text-muted-foreground')}>
            {loan.remaining_balance != null ? `₱${formatMoney(loan.remaining_balance)}` : '—'}
          </td>
          <td className={cn(TABLE_TD, 'text-[11px] text-muted-foreground')}>{loan.schedule_type || '—'}</td>
        </tr>
      )
    }

    const row = entry
    const ids = row.government_ids || {}
    const monthly = row.statutory_monthly || {}
    const loanLines = row.loan_lines || []

    if (rosterTab === 'GOV_IDS') {
      return (
        <tr key={row.user_id} className="last:border-b-0">
          {renderEmployeeCell(row)}
          <td className={cn(TABLE_TD, 'font-mono text-[11px]')}>{ids.sss_number || '—'}</td>
          <td className={cn(TABLE_TD, 'font-mono text-[11px]')}>{ids.philhealth_number || '—'}</td>
          <td className={cn(TABLE_TD, 'font-mono text-[11px]')}>{ids.pagibig_number || '—'}</td>
          <td className={cn(TABLE_TD, 'font-mono text-[11px]')}>{ids.tin_number || '—'}</td>
          <td className={TABLE_TD}><MissingBadges items={row.missing_info} /></td>
        </tr>
      )
    }

    if (rosterTab === 'ALL') {
      const d = row.deductions || {}
      return (
        <tr key={row.user_id} className="last:border-b-0">
          {renderEmployeeCell(row)}
          <td className={cn(TABLE_TD, 'text-right text-sm tabular-nums')}>₱{formatMoney(d.sss)}</td>
          <td className={cn(TABLE_TD, 'text-right text-sm tabular-nums')}>₱{formatMoney(d.philhealth)}</td>
          <td className={cn(TABLE_TD, 'text-right text-sm tabular-nums')}>₱{formatMoney(d.pagibig)}</td>
          <td className={cn(TABLE_TD, 'text-right text-sm tabular-nums')}>₱{formatMoney(d.withholding_tax)}</td>
          <td className={cn(TABLE_TD, 'text-[11px]')}>
            {loanLines.length ? (
              <div className="space-y-1">
                {loanLines.map((loan, idx) => (
                  <div key={`${row.user_id}-loan-${idx}`} className="flex items-start justify-between gap-2">
                    <span className="min-w-0 flex-1 text-muted-foreground">{loan.name}</span>
                    <span className="shrink-0 tabular-nums font-medium">₱{formatMoney(loan.amount_this_period)}</span>
                  </div>
                ))}
              </div>
            ) : (
              <span className="text-muted-foreground">—</span>
            )}
          </td>
          <td className={TABLE_TD}><MissingBadges items={row.missing_info} /></td>
        </tr>
      )
    }

    const tabKey = rosterTab === 'WHT' ? 'withholding_tax' : rosterTab.toLowerCase()
    return (
      <tr key={row.user_id} className="last:border-b-0">
        {renderEmployeeCell(row)}
        <td className={cn(TABLE_TD, 'text-right text-sm font-semibold tabular-nums')}>₱{formatMoney(amountForTab(row, rosterTab))}</td>
        <td className={cn(TABLE_TD, 'text-right text-sm tabular-nums text-muted-foreground')}>₱{formatMoney(monthly[tabKey])}</td>
        <td className={TABLE_TD}><MissingBadges items={row.missing_info} /></td>
      </tr>
    )
  }

  return (
    <div className="space-y-6">
      <Card className={CARD_SHELL}>
        <CardHeader className="pb-4">
          <div className="flex items-center gap-3">
            <div className="flex h-11 w-11 items-center justify-center rounded-xl bg-brand/10 text-brand">
              <Building2 className="h-5 w-5" />
            </div>
            <div>
              <CardTitle className="text-lg">Generation Scope</CardTitle>
              <CardDescription>Match payroll: pick company, optional branch/department, and pay cycle period.</CardDescription>
            </div>
          </div>
        </CardHeader>
        <CardContent className="grid gap-4 md:grid-cols-2 xl:grid-cols-3">
          <div className="space-y-2">
            <Label>Company</Label>
            <Select value={companyId || '__none__'} onValueChange={(v) => { setCompanyId(v === '__none__' ? '' : v); setPage(1) }} disabled={loadingScope}>
              <SelectTrigger className={SELECT_TRIGGER}><SelectValue placeholder="Select company" /></SelectTrigger>
              <SelectContent className={SELECT_CONTENT}>
                <SelectItem value="__none__">Select company...</SelectItem>
                {companies.map((c) => <SelectItem key={c.id} value={String(c.id)}>{c.name}</SelectItem>)}
              </SelectContent>
            </Select>
          </div>
          <div className="space-y-2">
            <Label>Branch</Label>
            <Select value={branchId || '__none__'} onValueChange={(v) => { setBranchId(v === '__none__' ? '' : v); setPage(1) }} disabled={!companyId}>
              <SelectTrigger className={SELECT_TRIGGER}><SelectValue placeholder="All branches" /></SelectTrigger>
              <SelectContent className={SELECT_CONTENT}>
                <SelectItem value="__none__">All branches</SelectItem>
                {branches.map((b) => <SelectItem key={b.id} value={String(b.id)}>{b.name}</SelectItem>)}
              </SelectContent>
            </Select>
          </div>
          <div className="space-y-2">
            <Label>Department</Label>
            <Select value={departmentId || '__none__'} onValueChange={(v) => { setDepartmentId(v === '__none__' ? '' : v); setPage(1) }} disabled={!branchId}>
              <SelectTrigger className={SELECT_TRIGGER}><SelectValue placeholder="All departments" /></SelectTrigger>
              <SelectContent className={SELECT_CONTENT}>
                <SelectItem value="__none__">All departments</SelectItem>
                {departments.map((d) => <SelectItem key={d.id} value={String(d.id)}>{d.name}</SelectItem>)}
              </SelectContent>
            </Select>
          </div>
          <div className="space-y-2">
            <Label>Pay cycle</Label>
            <Select value={payCycleId || '__none__'} onValueChange={(v) => { setPayCycleId(v === '__none__' ? '' : v); setPage(1) }}>
              <SelectTrigger className={SELECT_TRIGGER}><SelectValue placeholder="Select pay cycle" /></SelectTrigger>
              <SelectContent className={SELECT_CONTENT}>
                <SelectItem value="__none__">Select pay cycle...</SelectItem>
                {payCycles.map((pc) => <SelectItem key={pc.id} value={String(pc.id)}>{pc.name || pc.code}</SelectItem>)}
              </SelectContent>
            </Select>
          </div>
          <div className="space-y-2 md:col-span-2">
            <Label>Payroll period</Label>
            <Select
              value={selectedPeriodKey || '__none__'}
              onValueChange={(v) => {
                const period = cyclePeriods.find((p) => payCyclePeriodKey(p) === v)
                applyPeriod(period)
              }}
              disabled={!payCycleId || cyclePeriods.length === 0}
            >
              <SelectTrigger className={SELECT_TRIGGER}><SelectValue placeholder="Select cut-off period" /></SelectTrigger>
              <SelectContent className={SELECT_CONTENT}>
                <SelectItem value="__none__">Select period...</SelectItem>
                {cyclePeriods.map((period) => (
                  <SelectItem key={payCyclePeriodKey(period)} value={payCyclePeriodKey(period)}>
                    {formatPeriodRange(period.cut_off_start_date, period.cut_off_end_date)} · Pay {period.pay_date || '—'}
                  </SelectItem>
                ))}
              </SelectContent>
            </Select>
          </div>
          <div className="space-y-2">
            <Label htmlFor="gov-ded-from-date">Period start</Label>
            <Input
              id="gov-ded-from-date"
              type="date"
              value={fromDate}
              onChange={(e) => { setFromDate(e.target.value); setPage(1) }}
              className={SELECT_TRIGGER}
            />
          </div>
          <div className="space-y-2">
            <Label htmlFor="gov-ded-to-date">Period end</Label>
            <Input
              id="gov-ded-to-date"
              type="date"
              value={toDate}
              onChange={(e) => { setToDate(e.target.value); setPage(1) }}
              className={SELECT_TRIGGER}
            />
          </div>
          <div className="flex flex-col gap-3 md:col-span-2 xl:col-span-3">
            <div className="flex flex-wrap items-end gap-3">
              <Button type="button" onClick={() => void runGenerate()} disabled={!canGenerate || generating} className="bg-brand text-brand-foreground hover:bg-brand-strong disabled:opacity-50">
                <RefreshCw className={cn('mr-2 h-4 w-4', generating && 'animate-spin')} />
                Generate deductions
              </Button>
              <Button
                type="button"
                variant="outline"
                onClick={() => void runSave()}
                disabled={!canGenerate || saving || generating || allRows.length === 0}
              >
                <Save className={cn('mr-2 h-4 w-4', saving && 'animate-pulse')} />
                {saving ? 'Saving...' : 'Save roster'}
              </Button>
              <Button
                type="button"
                variant="outline"
                onClick={() => void exportToExcel()}
                disabled={exporting || generating || filteredEmployees.length === 0}
              >
                <Download className={cn('mr-2 h-4 w-4', exporting && 'animate-pulse')} />
                {exporting ? 'Exporting...' : 'Download Excel'}
              </Button>
              {fromDate && toDate ? (
                <Badge variant="outline" className="gap-1 border-border py-1.5">
                  <CalendarClock className="h-3.5 w-3.5" />
                  {formatPeriodRange(fromDate, toDate)}
                </Badge>
              ) : null}
            </div>
            {scopeHint ? (
              <p className="text-sm text-muted-foreground">{scopeHint}</p>
            ) : null}
          </div>
        </CardContent>
      </Card>

      {error ? (
        <div className="rounded-xl border border-rose-300 bg-rose-50 px-4 py-3 text-sm text-rose-900 dark:border-rose-500/40 dark:bg-rose-950/30 dark:text-rose-100">
          {error}
        </div>
      ) : null}

      {summary ? (
        <div className="grid gap-3 sm:grid-cols-2 xl:grid-cols-4">
          <Card className={CARD_SHELL}><CardContent className="p-4"><p className="text-xs text-muted-foreground">Employees</p><p className="text-2xl font-bold">{summary.employee_count ?? 0}</p></CardContent></Card>
          <Card className={CARD_SHELL}><CardContent className="p-4"><p className="text-xs text-muted-foreground">Missing info</p><p className="text-2xl font-bold text-amber-600">{summary.employees_with_missing_info ?? 0}</p></CardContent></Card>
          <Card className={CARD_SHELL}><CardContent className="p-4"><p className="text-xs text-muted-foreground">Statutory (period)</p><p className="text-2xl font-bold">₱{formatMoney(summary.totals?.employee_statutory)}</p></CardContent></Card>
          <Card className={CARD_SHELL}><CardContent className="p-4"><p className="text-xs text-muted-foreground">Loans (period)</p><p className="text-2xl font-bold">₱{formatMoney(summary.totals?.custom_deductions)}</p></CardContent></Card>
        </div>
      ) : null}

      {companyId ? (
        <Card className={CARD_SHELL}>
          <CardHeader className="pb-3">
            <div className="flex flex-col gap-3 sm:flex-row sm:items-center sm:justify-between">
              <div>
                <CardTitle className="text-base">Saved Rosters</CardTitle>
                <CardDescription>Previously saved employee deduction snapshots for this company.</CardDescription>
              </div>
              <Button type="button" variant="outline" size="sm" onClick={() => void loadSavedRosters()} disabled={loadingSavedRosters}>
                <RefreshCw className={cn('mr-2 h-4 w-4', loadingSavedRosters && 'animate-spin')} />
                Refresh
              </Button>
            </div>
          </CardHeader>
          <CardContent className="space-y-2">
            {loadingSavedRosters ? (
              <p className="text-sm text-muted-foreground">Loading saved rosters...</p>
            ) : null}
            {!loadingSavedRosters && savedRosters.length === 0 ? (
              <p className="text-sm text-muted-foreground">No saved rosters yet. Generate and click Save roster.</p>
            ) : null}
            {!loadingSavedRosters && savedRosters.map((item) => {
              const periodLabel = item.scope?.from_date && item.scope?.to_date
                ? formatPeriodRange(item.scope.from_date, item.scope.to_date)
                : `${item.period_month}/${item.period_year}`
              return (
                <div key={item.id} className="flex flex-col gap-2 rounded-lg border border-border bg-background p-3 sm:flex-row sm:items-center sm:justify-between">
                  <div className="min-w-0 space-y-1">
                    <p className="truncate text-sm font-semibold text-foreground">{periodLabel}</p>
                    <p className="text-xs text-muted-foreground">
                      Saved {item.created_at ? new Date(item.created_at).toLocaleString() : '—'}
                      {item.employee_count != null ? ` · ${item.employee_count} employees` : ''}
                      {' · '}
                      ₱{formatMoney(item.total_employee_amount)} employee total
                    </p>
                  </div>
                  <Button
                    type="button"
                    variant="outline"
                    size="sm"
                    className="shrink-0"
                    disabled={loadingSavedRosterId === item.id}
                    onClick={() => void loadSavedRoster(item.id)}
                  >
                    <Clock3 className={cn('mr-2 h-4 w-4', loadingSavedRosterId === item.id && 'animate-spin')} />
                    {loadingSavedRosterId === item.id ? 'Loading...' : 'Open'}
                  </Button>
                </div>
              )
            })}
          </CardContent>
        </Card>
      ) : null}

      <div className="rounded-lg border border-border bg-card p-3 shadow-sm space-y-4">
        <div className="flex gap-2 overflow-x-auto pb-1 sm:flex-wrap sm:overflow-visible sm:pb-0" role="tablist" aria-label="Deduction roster tabs">
          {ROSTER_TABS.map(({ id, label, icon: Icon }) => (
            <button
              key={id}
              type="button"
              role="tab"
              aria-selected={rosterTab === id}
              onClick={() => setRosterTab(id)}
              className={cn(
                'inline-flex h-11 shrink-0 items-center gap-2 rounded-md border px-4 text-sm font-semibold transition-colors',
                rosterTab === id
                  ? 'border-brand bg-brand text-brand-foreground shadow-sm'
                  : 'border-border bg-muted text-foreground hover:bg-muted/80 dark:bg-muted/60',
              )}
            >
              <Icon className="h-3.5 w-3.5" />
              {label}
            </button>
          ))}
        </div>

        <div className="flex flex-col gap-3 sm:flex-row sm:items-center sm:justify-between">
          <div className="relative max-w-md flex-1">
            <Search className="pointer-events-none absolute left-3 top-1/2 h-4 w-4 -translate-y-1/2 text-muted-foreground" />
            <Input
              value={search}
              onChange={(e) => setSearch(e.target.value)}
              placeholder="Filter by employee name or number..."
              className="pl-9"
            />
          </div>
          <label className="inline-flex items-center gap-2 text-sm">
            <Switch checked={missingOnly} onCheckedChange={(v) => { setMissingOnly(v); setPage(1) }} />
            Show missing info only
          </label>
        </div>

        <div className="rounded-lg border border-border">
          <table className="w-full table-fixed text-sm">
            <thead className={TABLE_HEAD}>{renderTableHeaders()}</thead>
            <tbody>
              {rosterLoading ? (
                <tr>
                  <td colSpan={8} className="px-3 py-10 text-center text-muted-foreground">
                    {rosterLoadingMessage}
                  </td>
                </tr>
              ) : null}
              {!rosterLoading && loadingSavedRosterId && allRows.length === 0 ? (
                <tr>
                  <td colSpan={8} className="px-3 py-10 text-center text-muted-foreground">
                    Loading saved roster...
                  </td>
                </tr>
              ) : null}
              {!rosterLoading && rows.length === 0 && !loadingSavedRosterId ? (
                <tr>
                  <td colSpan={8} className="px-3 py-10 text-center text-muted-foreground">
                    {allRows.length === 0
                      ? 'Select company and pay period, then generate.'
                      : 'No rows match the current filters.'}
                  </td>
                </tr>
              ) : null}
              {!rosterLoading && rows.map((row) => renderTableRow(row))}
            </tbody>
          </table>
        </div>

        {allRows.length > 0 ? (
          <div className="flex flex-col gap-2 text-sm text-muted-foreground sm:flex-row sm:items-center sm:justify-between">
            <span>
              Page {paginationMeta.page} of {paginationMeta.last_page}
              {' · '}
              {paginationMeta.total} {rosterTab === 'LOANS' ? 'loan lines' : 'employees'}
            </span>
            <div className="flex gap-2">
              <Button type="button" variant="outline" size="sm" disabled={paginationMeta.page <= 1} onClick={() => setPage((p) => Math.max(1, p - 1))}>Previous</Button>
              <Button type="button" variant="outline" size="sm" disabled={paginationMeta.page >= paginationMeta.last_page} onClick={() => setPage((p) => p + 1)}>Next</Button>
            </div>
          </div>
        ) : null}
      </div>
    </div>
  )
}
