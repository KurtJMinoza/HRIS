import { useEffect, useMemo, useRef, useState } from 'react'
import {
  Calendar,
  Heart,
  Landmark,
  PiggyBank,
  Scale,
} from 'lucide-react'
import { Button } from '@/components/ui/button'
import {
  calculateStatutoryContributions,
  getStoredUser,
  getStatutoryRateHistory,
  getStatutoryRates,
  upsertStatutoryRate,
} from '@/api'

const CODES = ['SSS', 'PHILHEALTH', 'PAGIBIG', 'EC']
const TABS = [
  { id: 'SSS', label: 'SSS' },
  { id: 'PHILHEALTH', label: 'PhilHealth' },
  { id: 'PAGIBIG', label: 'Pag-IBIG (HDMF)' },
  { id: 'WHT', label: 'Withholding Tax' },
  { id: 'AUDIT', label: 'Compliance Audit' },
]

const DEFAULT_ROWS = {
  SSS: { code: 'SSS', name: 'Social Security System (SSS)', employee_rate: 0.05, employer_rate: 0.10, min_salary: 5000, max_salary: 35000 },
  PHILHEALTH: { code: 'PHILHEALTH', name: 'PhilHealth', employee_rate: 0.025, employer_rate: 0.025, salary_floor: 10000, salary_ceiling: 100000 },
  PAGIBIG: { code: 'PAGIBIG', name: 'Pag-IBIG (HDMF)', employee_rate: 0.02, employer_rate: 0.02, tier_threshold: 1500, monthly_cap: 10000 },
  EC: { code: 'EC', name: "Employees' Compensation (EC)", employee_rate: 0, employer_rate: 0, min_salary: 10, max_salary: 30 },
}

const TRAIN_WITHHOLDING_ROWS = [
  {
    id: 'train-1',
    incomeRange: '₱0.00 - ₱250,000.00',
    taxRate: '0%',
    formula: 'No tax',
    notes: 'Effective Jan 1, 2023 onwards',
  },
  {
    id: 'train-2',
    incomeRange: '₱250,001.00 - ₱400,000.00',
    taxRate: '15%',
    formula: '15% of excess over ₱250,000.00',
    notes: 'Effective Jan 1, 2023 onwards',
  },
  {
    id: 'train-3',
    incomeRange: '₱400,001.00 - ₱800,000.00',
    taxRate: '20%',
    formula: '₱22,500.00 + 20% of excess over ₱400,000.00',
    notes: 'Effective Jan 1, 2023 onwards',
  },
  {
    id: 'train-4',
    incomeRange: '₱800,001.00 - ₱2,000,000.00',
    taxRate: '25%',
    formula: '₱102,500.00 + 25% of excess over ₱800,000.00',
    notes: 'Effective Jan 1, 2023 onwards',
  },
  {
    id: 'train-5',
    incomeRange: '₱2,000,001.00 - ₱8,000,000.00',
    taxRate: '30%',
    formula: '₱402,500.00 + 30% of excess over ₱2,000,000.00',
    notes: 'Effective Jan 1, 2023 onwards',
  },
  {
    id: 'train-6',
    incomeRange: 'Over ₱8,000,000.00',
    taxRate: '35%',
    formula: '₱2,202,500.00 + 35% of excess over ₱8,000,000.00',
    notes: 'Effective Jan 1, 2023 onwards',
  },
]

const formatMoney = (v) => Number(v || 0).toLocaleString('en-PH', { minimumFractionDigits: 2, maximumFractionDigits: 2 })
const formatPct = (v) => `${(Number(v || 0) * 100).toFixed(1)}%`
const formatPeso = (v) => `₱${formatMoney(v)}`

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
    const employer = msc * 0.10
    const ec = 30
    const total = employee + employer + ec
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
      overall_total: total,
      total,
    })
  }

  rows.push({
    min: 34750,
    max: 35000,
    range_label: '34,750 - 35,000',
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

/** Avoid NaN in JSON payloads (JSON.stringify(NaN) → null, which broke statutory/calculate). */
const safeNonNegativeNumber = (v) => {
  const n = Number(v)
  return Number.isFinite(n) && n >= 0 ? n : 0
}

/** Same JSON key as PHP `calculateAllStatutoryContributions` (three `s` characters — SSS block, not "tts"). */
const AUDIT_SSS_KEY = '\x73\x73\x73'

export default function AdminGovernmentContributions() {
  const [activeTab, setActiveTab] = useState('SSS')
  const [rowsByCode, setRowsByCode] = useState(DEFAULT_ROWS)
  const [basicSalary, setBasicSalary] = useState('25000')
  const [effectiveFrom, setEffectiveFrom] = useState(new Date().toISOString().slice(0, 10))
  const [preview, setPreview] = useState(null)
  const [saving, setSaving] = useState(false)
  const [auditLoading, setAuditLoading] = useState(false)
  const [loading, setLoading] = useState(true)
  const [error, setError] = useState('')
  const [notice, setNotice] = useState('')
  const [showNotice, setShowNotice] = useState(true)
  const [sssSchedule, setSssSchedule] = useState([])
  const [sssQuery, setSssQuery] = useState('')
  const [sssPage, setSssPage] = useState(1)
  const [sssPageSize, setSssPageSize] = useState(20)
  const [canManageRates, setCanManageRates] = useState(true)
  const [historyOpen, setHistoryOpen] = useState(false)
  const [historyLoading, setHistoryLoading] = useState(false)
  const [historyError, setHistoryError] = useState('')
  const [historyRows, setHistoryRows] = useState([])
  const [historyCodeFilter, setHistoryCodeFilter] = useState('')
  const auditDebounceRef = useRef(null)

  const sssDefaultBrackets = useMemo(() => buildSssCircular2024006Schedule(), [])

  async function loadRates() {
    setLoading(true)
    setError('')
    try {
      const data = await getStatutoryRates()
      const map = { ...DEFAULT_ROWS }
      for (const row of data?.rates || []) {
        const code = String(row.code || '').toUpperCase()
        if (!map[code]) continue
        map[code] = { ...map[code], ...row }
      }
      setRowsByCode(map)
      const sched = data?.sss_schedule
      setSssSchedule(Array.isArray(sched) ? sched : [])
    } catch (e) {
      setError(e?.message || 'Failed to load statutory rates')
    } finally {
      setLoading(false)
    }
  }

  useEffect(() => {
    loadRates()
  }, [])

  /** Compliance Audit: keep preview aligned with PayrollCalculatorService (RA 11199 / Circular 2024-006). */
  useEffect(() => {
    if (activeTab !== 'AUDIT') return undefined
    if (auditDebounceRef.current) clearTimeout(auditDebounceRef.current)
    auditDebounceRef.current = setTimeout(async () => {
      try {
        const data = await calculateStatutoryContributions({
          basic_salary: safeNonNegativeNumber(basicSalary),
          withholding_method: 'annualized',
          period_type: 'monthly',
        })
        setPreview(data?.breakdown ?? null)
      } catch {
        // Local fallback (computedAudit) remains if API fails
      }
    }, 450)
    return () => {
      if (auditDebounceRef.current) clearTimeout(auditDebounceRef.current)
    }
  }, [activeTab, basicSalary])

  useEffect(() => {
    let allowed = true
    try {
      const user = getStoredUser()
      if (user) {
        const hrRole = String(user?.hr_role || '').toLowerCase()
        const role = String(user?.role || '').toLowerCase()
        const isSuperAdmin = Boolean(user?.is_super_admin)
        allowed = isSuperAdmin || hrRole === 'admin_hr' || hrRole === 'company_head' || role === 'admin'
      }
    } catch {
      allowed = true
    }
    setCanManageRates(allowed)
  }, [])

  async function saveChanges() {
    setSaving(true)
    setError('')
    setNotice('')
    try {
      for (const code of CODES) {
        const row = rowsByCode[code]
        await upsertStatutoryRate(code, {
          name: row.name,
          employee_rate: Number(row.employee_rate || 0),
          employer_rate: Number(row.employer_rate || 0),
          min_salary: row.min_salary ?? null,
          max_salary: row.max_salary ?? null,
          msc: row.msc ?? null,
          salary_floor: row.salary_floor ?? null,
          salary_ceiling: row.salary_ceiling ?? null,
          tier_threshold: row.tier_threshold ?? null,
          monthly_cap: row.monthly_cap ?? null,
          brackets: code === 'SSS' ? (Array.isArray(row.brackets) && row.brackets.length > 0 ? row.brackets : sssDefaultBrackets) : null,
          effective_from: effectiveFrom,
          is_active: true,
        })
      }
      setNotice('Rates saved successfully.')
      setShowNotice(true)
      await loadRates()
    } catch (e) {
      setError(e?.message || 'Failed to save changes')
    } finally {
      setSaving(false)
    }
  }

  async function runAudit() {
    if (auditLoading) return
    setError('')
    setNotice('')
    setAuditLoading(true)
    try {
      const data = await calculateStatutoryContributions({
        basic_salary: safeNonNegativeNumber(basicSalary),
        withholding_method: 'annualized',
        period_type: 'monthly',
      })
      setPreview(data?.breakdown || null)
      setNotice('Compliance audit preview generated.')
      setShowNotice(true)
    } catch (e) {
      setError(e?.message || 'Failed to run audit')
    } finally {
      setAuditLoading(false)
    }
  }

  async function openHistoryModal() {
    setHistoryOpen(true)
    await loadHistory()
  }

  async function loadHistory(code = historyCodeFilter) {
    setHistoryLoading(true)
    setHistoryError('')
    try {
      const data = await getStatutoryRateHistory({ code: code || undefined, page: 1, per_page: 50 })
      setHistoryRows(Array.isArray(data?.history) ? data.history : [])
    } catch (e) {
      setHistoryError(e?.message || 'Failed to load history')
    } finally {
      setHistoryLoading(false)
    }
  }

  const sssRow = rowsByCode.SSS || DEFAULT_ROWS.SSS
  const phRow = rowsByCode.PHILHEALTH || DEFAULT_ROWS.PHILHEALTH
  const pagibigRow = rowsByCode.PAGIBIG || DEFAULT_ROWS.PAGIBIG
  const ecFixed = 30
  const sssBrackets = sssSchedule.length > 0
    ? sssSchedule
    : (Array.isArray(sssRow.brackets) && sssRow.brackets.length > 0 ? sssRow.brackets : sssDefaultBrackets)
  const sssBracketsNormalized = useMemo(() => {
    const source = Array.isArray(sssBrackets) ? sssBrackets : []
    const hasOfficialCoverage = source.length >= 61
      && source.some((row) => Number(row?.msc ?? 0) <= 5000)
      && source.some((row) => Number(row?.msc ?? 0) >= 35000)
    return hasOfficialCoverage ? source : sssDefaultBrackets
  }, [sssBrackets, sssDefaultBrackets])
  const sssRowsSorted = useMemo(
    () =>
      [...sssBracketsNormalized].sort((a, b) => {
        const aFrom = Number(a.range_from ?? a.range_start ?? a.min ?? 0)
        const bFrom = Number(b.range_from ?? b.range_start ?? b.min ?? 0)
        return aFrom - bFrom
      }),
    [sssBracketsNormalized]
  )
  const normalizedSssQuery = useMemo(() => sssQuery.trim().toLowerCase(), [sssQuery])
  const sssFilteredRows = useMemo(
    () =>
      sssRowsSorted.filter((row) => {
        const label = String(row.range_label || '').toLowerCase()
        const rangeFrom = Number(row.range_from ?? row.range_start ?? row.min ?? 0)
        const rangeToRaw = row.range_to ?? row.range_end ?? row.max ?? null
        const rangeTo = rangeToRaw == null ? '' : String(rangeToRaw)
        const msc = Number(row.msc ?? 0)
        const haystack = `${label} ${rangeFrom} ${rangeTo} ${msc} ${formatMoney(rangeFrom)} ${formatMoney(msc)}`.toLowerCase()
        return haystack.includes(normalizedSssQuery)
      }),
    [sssRowsSorted, normalizedSssQuery]
  )
  const sssTotalRows = sssFilteredRows.length
  const sssTotalPages = Math.max(1, Math.ceil(sssTotalRows / sssPageSize))
  const sssCurrentPage = Math.min(sssPage, sssTotalPages)
  const sssPageStart = sssTotalRows === 0 ? 0 : (sssCurrentPage - 1) * sssPageSize + 1
  const sssPageEnd = Math.min(sssCurrentPage * sssPageSize, sssTotalRows)
  const sssRowsPaged = useMemo(
    () => sssFilteredRows.slice((sssCurrentPage - 1) * sssPageSize, sssCurrentPage * sssPageSize),
    [sssFilteredRows, sssCurrentPage, sssPageSize]
  )

  useEffect(() => {
    setSssPage(1)
  }, [sssQuery, sssPageSize])
  const phFloor = Number(phRow.salary_floor ?? 10000)
  const phCeiling = Number(phRow.salary_ceiling ?? 100000)
  const phTotalRate = Number(phRow.employee_rate || 0) + Number(phRow.employer_rate || 0)
  const phEmployeeRate = Number(phRow.employee_rate || 0)
  const phEmployerRate = Number(phRow.employer_rate || 0)
  const phRows = useMemo(
    () => [
      {
        salaryRange: `${formatPeso(0)} – ${formatPeso(phFloor - 0.01)}`,
        appliedSalary: phFloor,
        totalPremium: phFloor * phTotalRate,
        eeShare: phFloor * phEmployeeRate,
        erShare: phFloor * phEmployerRate,
      },
      {
        salaryRange: `${formatPeso(phFloor)} – ${formatPeso(phCeiling - 0.01)}`,
        appliedSalary: 'Actual Salary',
        totalPremium: `Salary × ${formatPct(phTotalRate)}`,
        eeShare: `${formatPct(phEmployeeRate)} of salary`,
        erShare: `${formatPct(phEmployerRate)} of salary`,
      },
      {
        salaryRange: `${formatPeso(phCeiling)} and above`,
        appliedSalary: phCeiling,
        totalPremium: phCeiling * phTotalRate,
        eeShare: phCeiling * phEmployeeRate,
        erShare: phCeiling * phEmployerRate,
      },
    ],
    [phFloor, phTotalRate, phEmployeeRate, phEmployerRate, phCeiling]
  )

  const pagThreshold = Number(pagibigRow.tier_threshold ?? 1500)
  const pagCap = Number(pagibigRow.monthly_cap ?? 10000)
  const pagErRate = Number(pagibigRow.employer_rate ?? 0.02)

  const pagRows = useMemo(
    () => [
      {
        salaryRange: `${formatPeso(0)} – ${formatPeso(pagThreshold)}`,
        eeRate: '1.0%',
        erRate: formatPct(pagErRate),
        eeShare: 'Salary × 1%',
        erShare: `Salary × ${formatPct(pagErRate)}`,
        total: `Salary × ${(1 + (pagErRate * 100)).toFixed(1)}%`,
        remarks: 'Tier 1 rate applies',
      },
      {
        salaryRange: `${formatPeso(pagThreshold + 1)} – ${formatPeso(pagCap)}`,
        eeRate: formatPct(pagibigRow.employee_rate || 0.02),
        erRate: formatPct(pagErRate),
        eeShare: `Salary × ${formatPct(pagibigRow.employee_rate || 0.02)}`,
        erShare: `Salary × ${formatPct(pagErRate)}`,
        total: `Salary × ${((Number(pagibigRow.employee_rate || 0.02) + pagErRate) * 100).toFixed(1)}%`,
        remarks: 'Tier 2 rate applies',
      },
      {
        salaryRange: `Above ${formatPeso(pagCap)}`,
        eeRate: formatPct(pagibigRow.employee_rate || 0.02),
        erRate: formatPct(pagErRate),
        eeShare: formatPeso(pagCap * Number(pagibigRow.employee_rate || 0.02)),
        erShare: formatPeso(pagCap * pagErRate),
        total: formatPeso((pagCap * Number(pagibigRow.employee_rate || 0.02)) + (pagCap * pagErRate)),
        remarks: `Capped at ${formatPeso(pagCap)} fund salary`,
      },
    ],
    [pagThreshold, pagErRate, pagCap, pagibigRow.employee_rate]
  )
  const computedAudit = useMemo(() => {
    const salary = Math.max(0, Number(basicSalary || 0))
    const bracket = sssRowsSorted.find((row) => {
      const min = Number(row.range_from ?? row.range_start ?? row.min ?? 0)
      const maxRaw = row.range_to ?? row.range_end ?? row.max ?? null
      const max = maxRaw == null ? Number.POSITIVE_INFINITY : Number(maxRaw)
      return salary >= min && salary <= max
    }) || sssRowsSorted[sssRowsSorted.length - 1] || { msc: 35000, range_label: '34,750 and above', range_from: 34750, range_to: null }
    const msc = Number(bracket.msc ?? 35000)
    const sssEe = Number(bracket.ee_share ?? bracket.employee_ss ?? (msc * 0.05))
    const sssEr = Number(bracket.er_share ?? bracket.employer_ss ?? (msc * 0.1))
    const sssEc = Number(bracket.ec_amount ?? bracket.employer_ec ?? ecFixed)
    const brMin = Number(bracket.range_from ?? bracket.range_start ?? bracket.min ?? 0)
    const brMaxRaw = bracket.range_to ?? bracket.range_end ?? bracket.max ?? null
    const brMax = brMaxRaw == null || Number(brMaxRaw) >= 999999 ? null : Number(brMaxRaw)
    const mscBracketRange =
      brMax == null ? `${formatMoney(brMin)} and above` : `${formatMoney(brMin)} – ${formatMoney(brMax)}`

    const phBase = Math.min(phCeiling, Math.max(phFloor, salary))
    const phEe = phBase * phEmployeeRate
    const phEr = phBase * phEmployerRate

    const pagEeLower = Number(pagibigRow?.metadata?.employee_rate_lower ?? 0.01)
    const pagEeUpper = Number(pagibigRow?.employee_rate ?? pagibigRow?.metadata?.employee_rate_upper ?? 0.02)
    const pagApplied = Math.min(pagCap, salary)
    const pagEeRate = salary <= pagThreshold ? pagEeLower : pagEeUpper
    const pagEe = pagApplied * pagEeRate
    const pagEr = pagApplied * pagErRate

    const employeeDeduction = sssEe + phEe + pagEe
    const employerLiability = sssEr + sssEc + phEr + pagEr

    return {
      [AUDIT_SSS_KEY]: {
        employee_amount: sssEe,
        employer_amount: sssEr,
        ec_amount: sssEc,
        total_amount: sssEe + sssEr + sssEc,
        bracket_range: String(bracket.range_label || `SSS MSC ${formatMoney(msc)}`),
        msc_bracket_range: mscBracketRange,
        msc_used: msc,
      },
      philhealth: {
        employee_amount: phEe,
        employer_amount: phEr,
        total_amount: phEe + phEr,
        metadata: {
          applied_salary: phBase,
          floor_applied: salary < phFloor,
          ceiling_applied: salary > phCeiling,
        },
      },
      pagibig: {
        employee_amount: pagEe,
        employer_amount: pagEr,
        total_amount: pagEe + pagEr,
        metadata: {
          applied_salary: pagApplied,
          cap_applied: salary > pagCap,
        },
      },
      totals: {
        employee_deduction: employeeDeduction,
        employer_liability: employerLiability,
        overall_statutory: employeeDeduction + employerLiability,
      },
    }
  }, [basicSalary, sssRowsSorted, ecFixed, phFloor, phCeiling, phEmployeeRate, phEmployerRate, pagibigRow?.metadata?.employee_rate_lower, pagibigRow?.employee_rate, pagibigRow?.metadata?.employee_rate_upper, pagCap, pagThreshold, pagErRate])
  const audit = preview ?? computedAudit
  const auditSss = audit?.[AUDIT_SSS_KEY]
  const employeeTotal = Number(audit?.totals?.employee_deduction ?? 0)
  const employerTotal = Number(audit?.totals?.employer_liability ?? 0)
  const overallTotal = Number(audit?.totals?.overall_statutory ?? (employeeTotal + employerTotal))
  const auditWithholding = audit?.withholding
  const withholdingMonthlyEstimate = Number(auditWithholding?.withholding_per_month ?? 0)
  const withholdingAnnualProjection = Number(auditWithholding?.annual_income_tax_per_train ?? (withholdingMonthlyEstimate * 12))
  const withholdingEffectiveRate = Number(
    auditWithholding?.effective_rate_percent_of_monthly_taxable
      ?? (Number(basicSalary || 0) > 0 ? ((withholdingMonthlyEstimate / Number(basicSalary || 0)) * 100) : 0)
  )

  return (
    <div className="mx-auto w-full min-w-0 max-w-7xl space-y-4 bg-slate-50 px-3 py-4 sm:space-y-5 sm:px-4 md:px-5 lg:space-y-6 lg:px-6 lg:py-5 2xl:max-w-[min(90rem,100%)] 3xl:max-w-[min(100rem,100%)] 3xl:space-y-8 3xl:px-10 3xl:py-6 3xl:text-[1.0625rem] 4xl:px-12 print:max-w-none print:bg-white">
      {/* Fluid inside layout outlet; mobile-first; 3xl/4xl scales type for TV / screen share. */}
      <div className="mb-1 flex flex-col gap-4 sm:mb-0 lg:flex-row lg:items-start lg:justify-between lg:gap-6">
        <div className="min-w-0 flex-1">
          <p className="text-xs font-medium uppercase tracking-wide text-slate-400 3xl:text-sm">Compensation</p>
          <h1 className="mt-1 text-2xl font-bold tracking-tight text-slate-900 sm:text-3xl 2xl:text-4xl 3xl:text-5xl">Government Deductions</h1>
          <p className="mt-2 max-w-4xl text-sm text-slate-600 3xl:text-base">
            Statutory contributions: SSS (including employer EC), PhilHealth, and Pag-IBIG. Rates reference RA 11199, RA 11223, and RA 9679.
          </p>
        </div>
        {/* Toolbar: compact h-9 controls, aligned end on desktop / TV — matches other admin list pages */}
        <div className="flex w-full min-w-0 flex-col gap-2 sm:w-auto sm:flex-row sm:items-center sm:justify-end sm:gap-3">
          <div className="flex h-9 w-full max-w-[10.75rem] items-center gap-1.5 rounded-md border border-slate-200 bg-white px-2 shadow-sm sm:w-auto sm:max-w-[11rem] sm:shrink-0">
            <Calendar className="size-3.5 shrink-0 text-slate-400" aria-hidden />
            <label htmlFor="gov-deduction-effective-from" className="sr-only">
              Effective date for statutory rates
            </label>
            <input
              id="gov-deduction-effective-from"
              type="date"
              value={effectiveFrom}
              onChange={(e) => setEffectiveFrom(e.target.value)}
              className="h-full min-w-0 flex-1 border-0 bg-transparent py-0 pr-0 text-xs font-medium text-slate-900 focus:outline-none focus:ring-0 sm:text-sm"
            />
          </div>
          <Button
            type="button"
            variant="outline"
            size="sm"
            onClick={openHistoryModal}
            className="h-9 w-full border-slate-300 px-3 text-[11px] font-semibold uppercase tracking-wide text-slate-700 shadow-sm sm:w-auto sm:min-w-0 sm:px-3.5"
          >
            HISTORY OF RATES
          </Button>
          <Button
            type="button"
            variant="default"
            size="sm"
            onClick={saveChanges}
            disabled={saving || loading || !canManageRates}
            className="h-9 w-full bg-slate-900 px-3 text-[11px] font-semibold uppercase tracking-wide text-white hover:bg-slate-800 sm:w-auto sm:px-3.5"
          >
            {saving ? 'SAVING…' : canManageRates ? 'SAVE CHANGES' : 'VIEW ONLY'}
          </Button>
        </div>
      </div>

      {error ? <div className="rounded-md bg-rose-50 p-3 text-sm text-rose-700">{error}</div> : null}
      {notice && showNotice ? (
        <div className="flex flex-col gap-3 rounded-lg border border-emerald-200 bg-emerald-50 px-4 py-3 text-sm text-emerald-700 shadow-sm sm:flex-row sm:items-center sm:justify-between sm:gap-4">
          <div className="flex min-w-0 items-center gap-2">
            <span className="inline-block h-2 w-2 rounded-full bg-emerald-500" />
            <span>{notice}</span>
          </div>
          <div className="flex shrink-0 items-center gap-2 self-start sm:self-auto">
            <span className="rounded-full bg-emerald-100 px-2 py-0.5 text-xs font-semibold text-emerald-700">Success</span>
            <button type="button" onClick={() => setShowNotice(false)} className="rounded px-1.5 py-0.5 text-xs font-semibold text-emerald-700 hover:bg-emerald-100">Dismiss</button>
          </div>
        </div>
      ) : null}

      <div className="rounded-xl border border-slate-200 bg-white p-2 shadow-sm sm:p-3">
        {/* Tabs: horizontal scroll on phones; wrap on sm+ to avoid overflow when mirroring to TV from narrow source. */}
        <div
          className="-mx-1 flex gap-2 overflow-x-auto overscroll-x-contain px-1 pb-1 pt-0.5 [-webkit-overflow-scrolling:touch] sm:flex-wrap sm:overflow-visible sm:pb-0 sm:pt-0"
          role="tablist"
          aria-label="Government deductions sections"
        >
          {TABS.map((tab) => (
            <button
              key={tab.id}
              type="button"
              role="tab"
              aria-selected={activeTab === tab.id}
              onClick={() => setActiveTab(tab.id)}
              className={`shrink-0 whitespace-nowrap rounded-lg px-3 py-2.5 text-xs font-semibold sm:py-2 3xl:px-4 3xl:py-3 3xl:text-sm ${activeTab === tab.id ? 'bg-slate-900 text-white' : 'bg-slate-100 text-slate-700'}`}
            >
              {tab.label}
            </button>
          ))}
        </div>
      </div>

      <div className="grid min-w-0 gap-5 lg:grid-cols-1">
        <div className="min-w-0 space-y-5 3xl:space-y-6">
          {activeTab === 'SSS' ? (
            <div className="rounded-2xl border border-slate-200 bg-white p-4 shadow-sm sm:p-5 3xl:p-6">
              <div className="flex flex-col gap-3 sm:flex-row sm:flex-wrap sm:items-center sm:justify-between">
                <div className="min-w-0">
                  <h2 className="text-xl font-semibold text-slate-900 sm:text-2xl 2xl:text-3xl 3xl:text-4xl">SSS Contribution Schedule</h2>
                  <p className="mt-1 text-xs text-slate-500 3xl:text-sm">Social Security Act (RA 11199)</p>
                </div>
                <span className="w-fit shrink-0 rounded-full border border-indigo-200 bg-indigo-50 px-3 py-1 text-xs font-semibold text-indigo-700 3xl:px-4 3xl:py-1.5 3xl:text-sm">Circular No. 2024-006</span>
              </div>
              <div className="mt-4 flex flex-col gap-3 sm:flex-row sm:flex-wrap sm:items-center sm:justify-between">
                <input
                  value={sssQuery}
                  onChange={(e) => setSssQuery(e.target.value)}
                  placeholder="Search by range or MSC..."
                  className="w-full min-w-0 max-w-full rounded-md border border-slate-300 px-3 py-2 text-sm text-slate-700 sm:max-w-sm 3xl:py-2.5 3xl:text-base"
                />
                <div className="flex flex-wrap items-center gap-2 text-xs text-slate-600 3xl:text-sm">
                  <span>Rows per page</span>
                  <select
                    value={sssPageSize}
                    onChange={(e) => setSssPageSize(Number(e.target.value))}
                    className="rounded-md border border-slate-300 px-2 py-1 text-sm"
                  >
                    <option value={10}>10</option>
                    <option value={20}>20</option>
                    <option value={50}>50</option>
                  </select>
                </div>
              </div>
              <div className="relative mt-4 -mx-1 overflow-x-auto rounded-lg border border-slate-100 sm:mx-0">
                <table className="w-full min-w-[52rem] text-sm md:text-base lg:min-w-full 3xl:text-lg">
                  <thead>
                    <tr className="bg-slate-100 text-[11px] font-semibold uppercase tracking-wide text-slate-500 md:text-xs 3xl:text-sm">
                      <th className="px-3 py-2.5 text-left sm:px-4 sm:py-3">Range From</th>
                      <th className="px-3 py-2.5 text-left sm:px-4 sm:py-3">Range To</th>
                      <th className="px-3 py-2.5 text-left sm:px-4 sm:py-3">MSC Value</th>
                      <th className="px-3 py-2.5 text-left sm:px-4 sm:py-3">EE Share (Employee)</th>
                      <th className="px-3 py-2.5 text-left sm:px-4 sm:py-3">ER Share (Regular SS)</th>
                      <th className="px-3 py-2.5 text-left sm:px-4 sm:py-3">EC (ER)</th>
                      <th className="px-3 py-2.5 text-left sm:px-4 sm:py-3">Total Contribution</th>
                    </tr>
                  </thead>
                  <tbody>
                    {sssRowsPaged.map((b, i) => {
                      const ee = Number(b.ee_share ?? b.employee_ss ?? (Number(b.msc) * Number(sssRow.employee_rate || 0)))
                      const er = Number(b.er_share ?? b.employer_ss ?? (Number(b.msc) * Number(sssRow.employer_rate || 0)))
                      const ec = Number(b.ec_amount ?? b.employer_ec ?? ecFixed)
                      const total = Number(b.total ?? b.overall_total ?? (ee + er + ec))
                      const label = String(b.range_label || '').trim()
                      const min = Number(b.range_from ?? b.range_start ?? b.min ?? 0)
                      const maxRaw = b.range_to ?? b.range_end ?? b.max ?? null
                      const max = maxRaw === null || maxRaw === '' ? null : Number(maxRaw)
                      const isBelowRow = label.toLowerCase().startsWith('below')
                      const rangeToDisplay = isBelowRow ? formatMoney(b.msc) : (max === null || Number.isNaN(max) ? formatMoney(b.msc) : formatMoney(max))
                      return (
                        <tr className="border-b border-slate-100" key={`${label}-${min}-${max}-${i}`}>
                          <td className="whitespace-nowrap px-3 py-2.5 sm:px-4 sm:py-3">{label !== '' ? (isBelowRow ? label : formatMoney(min)) : formatMoney(min)}</td>
                          <td className="whitespace-nowrap px-3 py-2.5 sm:px-4 sm:py-3">{rangeToDisplay}</td>
                          <td className="whitespace-nowrap px-3 py-2.5 sm:px-4 sm:py-3">{formatMoney(b.msc)}</td>
                          <td className="whitespace-nowrap px-3 py-2.5 sm:px-4 sm:py-3">{formatMoney(ee)}</td>
                          <td className="whitespace-nowrap px-3 py-2.5 sm:px-4 sm:py-3">{formatMoney(er)}</td>
                          <td className="whitespace-nowrap px-3 py-2.5 sm:px-4 sm:py-3">{formatMoney(ec)}</td>
                          <td className="whitespace-nowrap px-3 py-2.5 font-semibold sm:px-4 sm:py-3">{formatMoney(total)}</td>
                        </tr>
                      )
                    })}
                    {sssRowsPaged.length === 0 ? (
                      <tr>
                        <td colSpan={7} className="px-4 py-6 text-center text-sm text-slate-500 md:text-base">No SSS brackets matched your search.</td>
                      </tr>
                    ) : null}
                  </tbody>
                </table>
              </div>
              <div className="mt-4 flex flex-col gap-3 sm:flex-row sm:flex-wrap sm:items-center sm:justify-between">
                <p className="text-xs text-slate-500 3xl:text-sm">Showing {sssPageStart}-{sssPageEnd} of {sssTotalRows} entries</p>
                <div className="flex flex-wrap items-center gap-2">
                  <button
                    type="button"
                    onClick={() => setSssPage((p) => Math.max(1, p - 1))}
                    disabled={sssCurrentPage <= 1}
                    className="rounded-md border border-slate-300 px-3 py-2 text-xs font-semibold text-slate-700 disabled:opacity-50 3xl:px-4 3xl:py-2.5 3xl:text-sm"
                  >
                    Previous
                  </button>
                  <span className="text-xs text-slate-600 3xl:text-sm">Page {sssCurrentPage} of {sssTotalPages}</span>
                  <button
                    type="button"
                    onClick={() => setSssPage((p) => Math.min(sssTotalPages, p + 1))}
                    disabled={sssCurrentPage >= sssTotalPages}
                    className="rounded-md border border-slate-300 px-3 py-2 text-xs font-semibold text-slate-700 disabled:opacity-50 3xl:px-4 3xl:py-2.5 3xl:text-sm"
                  >
                    Next
                  </button>
                </div>
              </div>
              <p className="mt-2 text-xs text-slate-500 3xl:text-sm">Based on SSS Circular No. 2024-006 • Effective January 2025</p>
            </div>
          ) : null}

          {activeTab === 'PHILHEALTH' ? (
            <div className="rounded-2xl border border-slate-200 bg-white p-4 shadow-sm sm:p-5 3xl:p-6">
              <div className="flex flex-col gap-3 sm:flex-row sm:flex-wrap sm:items-center sm:justify-between">
                <div className="min-w-0">
                  <h3 className="text-xl font-semibold text-slate-900 sm:text-2xl 2xl:text-3xl 3xl:text-4xl">PhilHealth Premium Table</h3>
                  <p className="mt-1 text-xs text-slate-500 3xl:text-sm">PhilHealth – Universal Health Care Act (RA 11223)</p>
                </div>
                <span className="w-fit shrink-0 rounded-full border border-emerald-200 bg-emerald-50 px-3 py-1 text-xs font-semibold text-emerald-700 3xl:text-sm">RA 11223</span>
              </div>
              <div className="mt-4 grid grid-cols-1 gap-3 sm:grid-cols-2 lg:grid-cols-3">
                <div className="rounded-lg border border-slate-200 bg-slate-50 p-3 3xl:p-4">
                  <p className="text-xs uppercase tracking-wide text-slate-500 3xl:text-sm">Total Rate</p>
                  <p className="mt-1 text-xl font-bold text-slate-900 sm:text-2xl 3xl:text-3xl">{formatPct(phTotalRate)}</p>
                  <p className="text-xs text-slate-500 3xl:text-sm">{formatPct(phEmployeeRate)} EE | {formatPct(phEmployerRate)} ER</p>
                </div>
                <div className="rounded-lg border border-slate-200 bg-slate-50 p-3 3xl:p-4">
                  <p className="text-xs uppercase tracking-wide text-slate-500 3xl:text-sm">Salary Floor</p>
                  <p className="mt-1 text-xl font-bold text-slate-900 sm:text-2xl 3xl:text-3xl">{formatPeso(phFloor)}</p>
                </div>
                <div className="rounded-lg border border-slate-200 bg-slate-50 p-3 sm:col-span-2 lg:col-span-1 3xl:p-4">
                  <p className="text-xs uppercase tracking-wide text-slate-500 3xl:text-sm">Salary Ceiling</p>
                  <p className="mt-1 text-xl font-bold text-slate-900 sm:text-2xl 3xl:text-3xl">{formatPeso(phCeiling)}</p>
                </div>
              </div>
              <div className="relative mt-4 -mx-1 overflow-x-auto rounded-lg border border-slate-100 sm:mx-0">
                <table className="w-full min-w-[44rem] text-sm md:text-base lg:min-w-full 3xl:text-lg">
                  <thead>
                    <tr className="bg-slate-100 text-[11px] font-semibold uppercase tracking-wide text-slate-500 md:text-xs 3xl:text-sm">
                      <th className="px-3 py-2.5 text-left sm:px-4 sm:py-3">Monthly Basic Salary Range</th>
                      <th className="px-3 py-2.5 text-left sm:px-4 sm:py-3" title="Floor/Ceiling-adjusted salary used for premium computation">Applied Salary (i)</th>
                      <th className="px-3 py-2.5 text-left sm:px-4 sm:py-3" title="Monthly basic salary multiplied by total premium rate">Total Premium ({formatPct(phTotalRate)})</th>
                      <th className="px-3 py-2.5 text-left sm:px-4 sm:py-3">Employee Share (EE)</th>
                      <th className="px-3 py-2.5 text-left sm:px-4 sm:py-3">Employer Share (ER)</th>
                    </tr>
                  </thead>
                  <tbody>
                    {phRows.map((row) => (
                      <tr key={row.salaryRange} className="border-b border-slate-100">
                        <td className="px-3 py-2.5 sm:px-4 sm:py-3">{row.salaryRange}</td>
                        <td className="px-3 py-2.5 sm:px-4 sm:py-3">{typeof row.appliedSalary === 'number' ? formatPeso(row.appliedSalary) : row.appliedSalary}</td>
                        <td className="px-3 py-2.5 sm:px-4 sm:py-3">{typeof row.totalPremium === 'number' ? formatPeso(row.totalPremium) : row.totalPremium}</td>
                        <td className="px-3 py-2.5 sm:px-4 sm:py-3">{typeof row.eeShare === 'number' ? formatPeso(row.eeShare) : row.eeShare}</td>
                        <td className="px-3 py-2.5 sm:px-4 sm:py-3">{typeof row.erShare === 'number' ? formatPeso(row.erShare) : row.erShare}</td>
                      </tr>
                    ))}
                  </tbody>
                </table>
              </div>
              <div className="mt-4 space-y-1 text-xs text-slate-600 3xl:text-sm">
                <p><strong>Salary Floor:</strong> {formatPeso(phFloor)}</p>
                <p><strong>Salary Ceiling:</strong> {formatPeso(phCeiling)}</p>
                <p>Premium is computed on Monthly Basic Salary only (excluding allowances, OT, bonuses, etc.).</p>
              </div>
            </div>
          ) : null}

          {activeTab === 'PAGIBIG' ? (
            <div className="rounded-2xl border border-slate-200 bg-white p-4 shadow-sm sm:p-5 3xl:p-6">
              <div className="flex flex-col gap-3 sm:flex-row sm:flex-wrap sm:items-center sm:justify-between">
                <div className="min-w-0">
                  <h3 className="text-xl font-semibold text-slate-900 sm:text-2xl 2xl:text-3xl 3xl:text-4xl">Pag-IBIG Contribution Table</h3>
                  <p className="mt-1 text-xs text-slate-500 3xl:text-sm">Pag-IBIG – HDMF Law (RA 9679)</p>
                </div>
                <span className="w-fit shrink-0 rounded-full border border-amber-200 bg-amber-50 px-3 py-1 text-xs font-semibold text-amber-700 3xl:text-sm">RA 9679</span>
              </div>
              <div className="mt-4 grid grid-cols-1 gap-3 sm:grid-cols-2 lg:grid-cols-3">
                <div className="rounded-lg border border-slate-200 bg-slate-50 p-3 3xl:p-4">
                  <p className="text-xs uppercase tracking-wide text-slate-500 3xl:text-sm">Employee Rate</p>
                  <p className="mt-1 text-sm font-semibold text-slate-900 3xl:text-base">1% (≤ {formatPeso(pagThreshold)}) or 2% (&gt; {formatPeso(pagThreshold)})</p>
                </div>
                <div className="rounded-lg border border-slate-200 bg-slate-50 p-3 3xl:p-4">
                  <p className="text-xs uppercase tracking-wide text-slate-500 3xl:text-sm">Employer Rate</p>
                  <p className="mt-1 text-xl font-bold text-slate-900 sm:text-2xl 3xl:text-3xl">{formatPct(pagErRate)}</p>
                </div>
                <div className="rounded-lg border border-slate-200 bg-slate-50 p-3 sm:col-span-2 lg:col-span-1 3xl:p-4">
                  <p className="text-xs uppercase tracking-wide text-slate-500 3xl:text-sm">Monthly Cap</p>
                  <p className="mt-1 text-xl font-bold text-slate-900 sm:text-2xl 3xl:text-3xl">{formatPeso(pagCap)}</p>
                  <p className="text-xs text-slate-500 3xl:text-sm">Max {formatPeso(200)} EE / {formatPeso(200)} ER</p>
                </div>
              </div>
              <div className="relative mt-4 -mx-1 overflow-x-auto rounded-lg border border-slate-100 sm:mx-0">
                <table className="w-full min-w-[56rem] text-sm md:text-base lg:min-w-full 3xl:text-lg">
                  <thead>
                    <tr className="bg-slate-100 text-[11px] font-semibold uppercase tracking-wide text-slate-500 md:text-xs 3xl:text-sm">
                      <th className="px-3 py-2.5 text-left sm:px-4 sm:py-3">Monthly Salary Range</th>
                      <th className="px-3 py-2.5 text-left sm:px-4 sm:py-3">EE Rate</th>
                      <th className="px-3 py-2.5 text-left sm:px-4 sm:py-3">ER Rate</th>
                      <th className="px-3 py-2.5 text-left sm:px-4 sm:py-3">Max EE Share</th>
                      <th className="px-3 py-2.5 text-left sm:px-4 sm:py-3">Max ER Share</th>
                      <th className="px-3 py-2.5 text-left sm:px-4 sm:py-3">Total (at cap)</th>
                      <th className="px-3 py-2.5 text-left sm:px-4 sm:py-3" title="Shows whether cap or tier rule is applied">Remarks (i)</th>
                    </tr>
                  </thead>
                  <tbody>
                    {pagRows.map((row) => (
                      <tr key={row.salaryRange} className="border-b border-slate-100">
                        <td className="px-3 py-2.5 sm:px-4 sm:py-3">{row.salaryRange}</td>
                        <td className="px-3 py-2.5 sm:px-4 sm:py-3">{row.eeRate}</td>
                        <td className="px-3 py-2.5 sm:px-4 sm:py-3">{row.erRate}</td>
                        <td className="px-3 py-2.5 sm:px-4 sm:py-3">{String(row.salaryRange).startsWith('Above') ? row.eeShare : '—'}</td>
                        <td className="px-3 py-2.5 sm:px-4 sm:py-3">{String(row.salaryRange).startsWith('Above') ? row.erShare : '—'}</td>
                        <td className="px-3 py-2.5 sm:px-4 sm:py-3">{String(row.salaryRange).startsWith('Above') ? row.total : '—'}</td>
                        <td className="px-3 py-2.5 sm:px-4 sm:py-3">{row.remarks}</td>
                      </tr>
                    ))}
                  </tbody>
                </table>
              </div>
              <div className="mt-4 space-y-1 text-xs text-slate-600 3xl:text-sm">
                <p><strong>Maximum Monthly Fund Salary (Cap):</strong> {formatPeso(pagCap)}</p>
                <p>Tiered employee rates apply first, then contribution base is capped regardless of actual salary.</p>
              </div>
            </div>
          ) : null}

          {activeTab === 'WHT' ? (
            <div className="rounded-2xl border border-slate-200 bg-white p-4 shadow-sm sm:p-5 3xl:p-6">
              <div className="min-w-0">
                <h3 className="text-xl font-semibold text-slate-900 sm:text-2xl 2xl:text-3xl 3xl:text-4xl">BIR Withholding Tax Table (TRAIN Law - RA 10963)</h3>
                <p className="mt-1 text-xs text-slate-500 3xl:text-sm">Graduated annual income tax rates for compensation income.</p>
              </div>

              <div className="relative mt-4 -mx-1 overflow-x-auto rounded-lg border border-slate-100 sm:mx-0">
                <table className="w-full min-w-[56rem] text-sm md:text-base lg:min-w-full 3xl:text-lg">
                  <thead>
                    <tr className="bg-slate-100 text-[11px] font-semibold uppercase tracking-wide text-slate-500 md:text-xs 3xl:text-sm">
                      <th className="px-3 py-2.5 text-left sm:px-4 sm:py-3">Annual Taxable Income Range</th>
                      <th className="px-3 py-2.5 text-left sm:px-4 sm:py-3">Tax Rate</th>
                      <th className="px-3 py-2.5 text-left sm:px-4 sm:py-3">Fixed Amount + % of Excess over Base</th>
                      <th className="px-3 py-2.5 text-left sm:px-4 sm:py-3">Effective Date / Notes</th>
                    </tr>
                  </thead>
                  <tbody>
                    {TRAIN_WITHHOLDING_ROWS.map((row) => (
                      <tr key={row.id} className="border-b border-slate-100">
                        <td className="px-3 py-2.5 sm:px-4 sm:py-3">{row.incomeRange}</td>
                        <td className="px-3 py-2.5 sm:px-4 sm:py-3">{row.taxRate}</td>
                        <td className="px-3 py-2.5 sm:px-4 sm:py-3">{row.formula}</td>
                        <td className="px-3 py-2.5 sm:px-4 sm:py-3">{row.notes}</td>
                      </tr>
                    ))}
                  </tbody>
                </table>
              </div>
              <p className="mt-3 text-xs text-slate-500 3xl:text-sm">
                Based on TRAIN Law. First ₱250,000 annual income is tax-free. 13th month pay up to ₱90,000 is exempt.
              </p>
            </div>
          ) : null}

          {activeTab === 'AUDIT' ? (
            <div className="space-y-4">
              <div className="flex flex-col gap-3 sm:flex-row sm:flex-wrap sm:items-end sm:justify-between">
                <div>
                  <h3 className="text-xl font-semibold text-slate-900 sm:text-2xl 3xl:text-3xl">Compliance Audit</h3>
                  <p className="mt-1 text-sm text-slate-600 3xl:text-base">
                    Preview statutory contributions and withholding using payroll-aligned rules (SSS including EC, PhilHealth, Pag-IBIG, TRAIN annualized tax).
                  </p>
                </div>
                <div className="flex flex-wrap items-center gap-2">
                  <Button
                    type="button"
                    onClick={runAudit}
                    disabled={auditLoading}
                    className="h-9 bg-slate-900 text-white hover:bg-slate-800"
                  >
                    {auditLoading ? 'Refreshing…' : 'Refresh'}
                  </Button>
                </div>
              </div>

              <div className="rounded-2xl border border-slate-200 bg-white p-5 shadow-sm sm:p-6 3xl:p-7">
                <p className="text-xs font-semibold uppercase tracking-wide text-slate-500">Basic salary</p>
                <div className="mt-3 flex flex-col gap-3 sm:flex-row sm:items-center sm:justify-between">
                  <p className="text-3xl font-bold tabular-nums text-slate-900 3xl:text-4xl">{formatPeso(Number(basicSalary || 0))}</p>
                  <div className="flex w-full max-w-md items-center gap-2 sm:w-auto">
                    <span className="text-slate-400" aria-hidden>
                      ₱
                    </span>
                    <input
                      value={basicSalary}
                      onChange={(e) => setBasicSalary(e.target.value)}
                      className="min-w-0 flex-1 rounded-lg border border-slate-200 bg-slate-50/80 px-3 py-2 text-sm font-semibold text-slate-900"
                      inputMode="decimal"
                      aria-label="Basic monthly salary for audit"
                    />
                  </div>
                </div>
              </div>

              <div className="grid grid-cols-1 gap-4 lg:grid-cols-2 3xl:gap-5">
                <div className="rounded-2xl border border-slate-200 bg-white p-5 shadow-sm 3xl:p-6">
                  <div className="flex items-center gap-2 border-b border-slate-100 pb-3">
                    <Landmark className="size-5 shrink-0 text-slate-700" aria-hidden />
                    <div>
                      <h4 className="text-base font-semibold text-slate-900 3xl:text-lg">SSS (RA 11199)</h4>
                      <p className="text-[11px] text-slate-500">MSC from salary bracket · 15% of MSC (5% EE + 10% ER) + EC</p>
                    </div>
                  </div>
                  <ul className="mt-4 space-y-2.5 text-sm text-slate-700 3xl:text-base">
                    <li className="flex justify-between gap-3">
                      <span>Employee</span>
                      <span className="font-semibold tabular-nums text-slate-900">{formatPeso(auditSss?.employee_amount ?? 0)}</span>
                    </li>
                    <li className="flex justify-between gap-3">
                      <span>Employer</span>
                      <span className="font-semibold tabular-nums text-slate-900">{formatPeso(auditSss?.employer_amount ?? 0)}</span>
                    </li>
                    <li className="flex justify-between gap-3">
                      <span>EC (employer only)</span>
                      <span className="font-semibold tabular-nums text-slate-900">{formatPeso(auditSss?.ec_amount ?? 0)}</span>
                    </li>
                    <li className="flex justify-between gap-3 border-t border-slate-100 pt-3 text-base font-bold text-slate-900">
                      <span>Total SSS + EC</span>
                      <span className="tabular-nums">{formatPeso(auditSss?.total_amount ?? 0)}</span>
                    </li>
                    <li className="pt-1 text-xs text-slate-500 3xl:text-sm">
                      <span className="font-medium text-slate-600">MSC bracket: </span>
                      {auditSss?.msc_bracket_range ?? auditSss?.bracket_range ?? '—'}
                      {auditSss?.msc_used != null ? (
                        <span className="text-slate-400"> · MSC {formatPeso(auditSss.msc_used)}</span>
                      ) : null}
                    </li>
                  </ul>
                </div>

                <div className="rounded-2xl border border-slate-200 bg-white p-5 shadow-sm 3xl:p-6">
                  <div className="flex items-center gap-2 border-b border-slate-100 pb-3">
                    <Heart className="size-5 shrink-0 text-rose-600" aria-hidden />
                    <div>
                      <h4 className="text-base font-semibold text-slate-900 3xl:text-lg">PhilHealth (RA 11223)</h4>
                      <p className="text-[11px] text-slate-500">5% total (2.5% EE + 2.5% ER) on applicable base</p>
                    </div>
                  </div>
                  <ul className="mt-4 space-y-2.5 text-sm text-slate-700 3xl:text-base">
                    <li className="flex justify-between gap-3">
                      <span>Employee</span>
                      <span className="font-semibold tabular-nums text-slate-900">{formatPeso(audit?.philhealth?.employee_amount ?? 0)}</span>
                    </li>
                    <li className="flex justify-between gap-3">
                      <span>Employer</span>
                      <span className="font-semibold tabular-nums text-slate-900">{formatPeso(audit?.philhealth?.employer_amount ?? 0)}</span>
                    </li>
                    <li className="flex justify-between gap-3 border-t border-slate-100 pt-3 text-base font-bold text-slate-900">
                      <span>Total</span>
                      <span className="tabular-nums">{formatPeso(audit?.philhealth?.total_amount ?? 0)}</span>
                    </li>
                    <li className="text-xs text-slate-500 3xl:text-sm">
                      <span className="font-medium text-slate-600">Applied salary: </span>
                      {formatPeso(audit?.philhealth?.metadata?.applied_salary ?? 0)}
                    </li>
                  </ul>
                </div>

                <div className="rounded-2xl border border-slate-200 bg-white p-5 shadow-sm 3xl:p-6">
                  <div className="flex items-center gap-2 border-b border-slate-100 pb-3">
                    <PiggyBank className="size-5 shrink-0 text-amber-700" aria-hidden />
                    <div>
                      <h4 className="text-base font-semibold text-slate-900 3xl:text-lg">Pag-IBIG (RA 9679)</h4>
                      <p className="text-[11px] text-slate-500">2% EE + 2% ER · capped fund salary ₱10,000</p>
                    </div>
                  </div>
                  <ul className="mt-4 space-y-2.5 text-sm text-slate-700 3xl:text-base">
                    <li className="flex justify-between gap-3">
                      <span>Employee</span>
                      <span className="font-semibold tabular-nums text-slate-900">{formatPeso(audit?.pagibig?.employee_amount ?? 0)}</span>
                    </li>
                    <li className="flex justify-between gap-3">
                      <span>Employer</span>
                      <span className="font-semibold tabular-nums text-slate-900">{formatPeso(audit?.pagibig?.employer_amount ?? 0)}</span>
                    </li>
                    <li className="flex justify-between gap-3 border-t border-slate-100 pt-3 text-base font-bold text-slate-900">
                      <span>Total</span>
                      <span className="tabular-nums">{formatPeso(audit?.pagibig?.total_amount ?? 0)}</span>
                    </li>
                    <li className="text-xs text-slate-500 3xl:text-sm">
                      <span className="font-medium text-slate-600">Applied: </span>
                      {formatPeso(audit?.pagibig?.metadata?.applied_salary ?? 0)}
                      {audit?.pagibig?.metadata?.cap_applied ? ' (capped)' : ''}
                    </li>
                  </ul>
                </div>

                <div className="rounded-2xl border border-slate-200 bg-white p-5 shadow-sm 3xl:p-6">
                  <div className="flex items-center gap-2 border-b border-slate-100 pb-3">
                    <Scale className="size-5 shrink-0 text-indigo-700" aria-hidden />
                    <div>
                      <h4 className="text-base font-semibold text-slate-900 3xl:text-lg">Withholding Tax (TRAIN)</h4>
                      <p className="text-[11px] text-slate-500">Annualized withholding method based on projected annual taxable income</p>
                    </div>
                  </div>
                  <ul className="mt-4 space-y-2.5 text-sm text-slate-700 3xl:text-base">
                    <li className="flex justify-between gap-3">
                      <span>Estimated Monthly Withholding Tax</span>
                      <span className="font-semibold tabular-nums text-slate-900">{formatPeso(withholdingMonthlyEstimate)}</span>
                    </li>
                    <li className="flex justify-between gap-3">
                      <span>Method</span>
                      <span className="font-semibold text-slate-900">Annualized (TRAIN)</span>
                    </li>
                    <li className="flex justify-between gap-3">
                      <span>Effective Rate</span>
                      <span className="font-semibold tabular-nums text-slate-900">{withholdingEffectiveRate.toFixed(2)}%</span>
                    </li>
                    <li className="flex justify-between gap-3 border-t border-slate-100 pt-3 text-base font-bold text-slate-900">
                      <span>Annual Projection</span>
                      <span className="tabular-nums">{formatPeso(withholdingAnnualProjection)}</span>
                    </li>
                  </ul>
                </div>

              </div>

              <div className="overflow-hidden rounded-2xl border border-slate-200 bg-white shadow-sm">
                <div className="flex items-center gap-2 border-b border-slate-100 bg-slate-50 px-5 py-3 sm:px-6">
                  <Scale className="size-5 shrink-0 text-slate-700" aria-hidden />
                  <h4 className="text-base font-semibold text-slate-900 3xl:text-lg">Summary</h4>
                </div>
                <div className="grid grid-cols-1 gap-4 p-5 sm:grid-cols-3 sm:p-6 3xl:gap-6 3xl:p-7">
                  <div>
                    <p className="text-xs font-medium uppercase tracking-wide text-slate-500">Total employee deductions</p>
                    <p className="mt-1 text-xl font-bold tabular-nums text-slate-900 3xl:text-2xl">{formatPeso(employeeTotal)}</p>
                    <p className="mt-1 text-[11px] text-slate-500">SSS + PhilHealth + Pag-IBIG (EE)</p>
                  </div>
                  <div>
                    <p className="text-xs font-medium uppercase tracking-wide text-slate-500">Total employer liability</p>
                    <p className="mt-1 text-xl font-bold tabular-nums text-slate-900 3xl:text-2xl">{formatPeso(employerTotal)}</p>
                    <p className="mt-1 text-[11px] text-slate-500">SSS ER + EC + PhilHealth ER + Pag-IBIG ER</p>
                  </div>
                  <div className="rounded-xl border border-emerald-100 bg-emerald-50/60 p-4 sm:col-span-1">
                    <p className="text-xs font-medium uppercase tracking-wide text-emerald-900/90">Grand total (statutory)</p>
                    <p className="mt-1 text-xl font-bold tabular-nums text-emerald-950 3xl:text-2xl">{formatPeso(overallTotal)}</p>
                    <p className="mt-1 text-[11px] text-emerald-900/80">Employee shares + employer shares (SSS, EC, PhilHealth, Pag-IBIG)</p>
                  </div>
                </div>
              </div>

              <p className="text-center text-[11px] leading-relaxed text-slate-500 3xl:text-xs">
                Rates as of 2025–2026 · SSS regular SS: <strong className="font-semibold text-slate-700">15% of MSC</strong> (5% EE + 10% ER) since Jan 2025 · EC employer-only ₱30 · PhilHealth 5% (2.5% + 2.5%) · Pag-IBIG 2% + 2% on capped ₱10,000 fund salary
              </p>
            </div>
          ) : null}
        </div>
      </div>

      {historyOpen ? (
        <div className="fixed inset-0 z-50 flex items-center justify-center bg-slate-900/40 p-3 sm:p-4">
          <div className="max-h-[min(85vh,100dvh)] w-full max-w-[calc(100vw-1.5rem)] overflow-hidden rounded-2xl border border-slate-200 bg-white shadow-xl sm:max-w-6xl 3xl:max-w-7xl">
            <div className="flex items-center justify-between border-b border-slate-200 px-5 py-4">
              <div>
                <h3 className="text-lg font-semibold text-slate-900">History of Rates</h3>
                <p className="text-xs text-slate-500">Audit trail for statutory rate updates (SSS, PhilHealth, Pag-IBIG, EC).</p>
              </div>
              <button
                type="button"
                onClick={() => setHistoryOpen(false)}
                className="rounded-md border border-slate-300 px-3 py-1 text-xs font-semibold text-slate-700 hover:bg-slate-50"
              >
                Close
              </button>
            </div>
            <div className="space-y-3 p-5">
              <div className="flex flex-wrap items-center gap-2">
                <select
                  value={historyCodeFilter}
                  onChange={(e) => setHistoryCodeFilter(e.target.value)}
                  className="rounded-md border border-slate-300 px-3 py-2 text-sm"
                >
                  <option value="">All Codes</option>
                  <option value="SSS">SSS</option>
                  <option value="PHILHEALTH">PhilHealth</option>
                  <option value="PAGIBIG">Pag-IBIG</option>
                  <option value="EC">EC</option>
                </select>
                <button
                  type="button"
                  onClick={() => loadHistory(historyCodeFilter)}
                  className="rounded-md bg-slate-900 px-3 py-2 text-xs font-semibold text-white hover:bg-slate-800"
                >
                  Apply Filter
                </button>
              </div>

              {historyError ? <div className="rounded-md bg-rose-50 p-3 text-sm text-rose-700">{historyError}</div> : null}

              <div className="max-h-[55vh] min-h-0 overflow-auto rounded-lg border border-slate-200">
                <table className="min-w-[640px] w-full text-sm md:min-w-full md:text-base">
                  <thead className="sticky top-0 bg-slate-100">
                    <tr className="text-[11px] font-semibold uppercase tracking-wide text-slate-600">
                      <th className="px-3 py-2 text-left">Date/Time</th>
                      <th className="px-3 py-2 text-left">Code</th>
                      <th className="px-3 py-2 text-left">Effective Date</th>
                      <th className="px-3 py-2 text-left">Action</th>
                      <th className="px-3 py-2 text-left">Changed By</th>
                      <th className="px-3 py-2 text-left">Changes (Old → New)</th>
                    </tr>
                  </thead>
                  <tbody>
                    {historyLoading ? (
                      <tr><td colSpan={6} className="px-3 py-6 text-center text-slate-500">Loading history...</td></tr>
                    ) : null}
                    {!historyLoading && historyRows.length === 0 ? (
                      <tr><td colSpan={6} className="px-3 py-6 text-center text-slate-500">No history records found.</td></tr>
                    ) : null}
                    {!historyLoading && historyRows.map((row) => (
                      <tr key={row.id} className="border-t border-slate-100 align-top">
                        <td className="px-3 py-2 text-xs text-slate-600">{row.created_at || '—'}</td>
                        <td className="px-3 py-2 font-semibold text-slate-800">{row.code || '—'}</td>
                        <td className="px-3 py-2 text-slate-700">{row.effective_from || '—'}</td>
                        <td className="px-3 py-2">
                          <span className={`rounded-full px-2 py-0.5 text-[11px] font-semibold ${row.action === 'created' ? 'bg-emerald-100 text-emerald-700' : 'bg-blue-100 text-blue-700'}`}>
                            {row.action || 'updated'}
                          </span>
                        </td>
                        <td className="px-3 py-2 text-slate-700">{row.changed_by?.name || row.changed_by?.email || 'System'}</td>
                        <td className="px-3 py-2 text-xs text-slate-600">
                          {row.changed_fields && Object.keys(row.changed_fields).length > 0
                            ? Object.entries(row.changed_fields).map(([k, v]) => (
                                <div key={`${row.id}-${k}`} className="mb-1">
                                  <span className="font-semibold text-slate-800">{k}</span>: {String(v?.old ?? 'null')} → {String(v?.new ?? 'null')}
                                </div>
                              ))
                            : 'Initial values saved / no diff captured'}
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

