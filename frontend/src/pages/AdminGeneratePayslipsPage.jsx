import { useCallback, useEffect, useMemo, useRef, useState } from 'react'
import { bulkPayslipDownloadStatusLabel, saveBulkPayslipZipBlob } from '../lib/bulkPayslipDownload'
import { useNavigate } from 'react-router-dom'
import {
  adminGeneratePayslips,
  adminQueueBulkPayslipDownload,
  adminPollAndDownloadBulkPayslipZip,
  adminPreviewPayslipSampleBlob,
  adminPreviewPayslipSampleData,
  getAdminPayslipPreviewScope,
  getAdminPayslipsRecentByCompany,
  adminDeletePayslipBatch,
  getAdminCompanyDefaultPayslipDates,
  getBranches,
  getCompanies,
  getDepartments,
  getPayCycles,
  apiOrigin,
} from '@/api'
import { useHrBasePath } from '@/contexts/HrAppPathContext'
import { useAuth } from '@/contexts/AuthContext'
import { Accordion, AccordionContent, AccordionItem, AccordionTrigger } from '@/components/ui/accordion'
import { Badge } from '@/components/ui/badge'
import { Button } from '@/components/ui/button'
import { Card, CardContent, CardDescription, CardHeader, CardTitle } from '@/components/ui/card'
import { Input } from '@/components/ui/input'
import { Label } from '@/components/ui/label'
import { Progress } from '@/components/ui/progress'
import { Select, SelectContent, SelectItem, SelectTrigger, SelectValue } from '@/components/ui/select'
import { Separator } from '@/components/ui/separator'
import { Switch } from '@/components/ui/switch'
import { Dialog, DialogContent, DialogDescription, DialogFooter, DialogHeader, DialogTitle } from '@/components/ui/dialog'
import PayslipHtmlDocument from '@/components/payslips/PayslipHtmlDocument'
import { PAYSLIP_MODAL_PRINT_STYLES } from '@/components/payslips/payslipPrintStyles'
import { Table, TableBody, TableCell, TableHead, TableHeader, TableRow } from '@/components/ui/table'
import { Tooltip, TooltipContent, TooltipProvider, TooltipTrigger } from '@/components/ui/tooltip'
import { Skeleton } from '@/components/ui/skeleton'
import { useToast } from '@/components/ui/use-toast'
import { cn } from '@/lib/utils'
import {
  AlertTriangle,
  Building2,
  CalendarClock,
  Clock3,
  Eye,
  FileDown,
  FileText,
  Layers,
  Loader2,
  MapPin,
  RefreshCw,
  Sparkles,
  TrendingDown,
  TrendingUp,
  Users,
  Printer,
  PhilippinePeso,
  Trash2,
  Zap,
} from 'lucide-react'

/** Full-width shell aligned with Government Deduction / Pay Cycles */
const PAYSLIP_MODULE_SHELL =
  'w-full min-w-0 max-w-none bg-background px-3 py-4 text-foreground sm:px-4 md:px-5 lg:px-6 lg:py-5'
const PAYSLIP_STACK = 'space-y-5 sm:space-y-6'
const PAYSLIP_PREVIEW_DIALOG =
  '!max-w-[min(88rem,calc(100vw-1.5rem))] w-full overflow-hidden border-slate-200/90 bg-white p-0 shadow-xl shadow-slate-900/[0.07] sm:!max-w-[min(88rem,calc(100vw-2rem))] dark:border-border dark:bg-card dark:shadow-black/40'

const CARD_SHELL =
  'rounded-2xl border border-border/80 bg-card text-card-foreground shadow-sm shadow-slate-900/[0.03] transition-shadow duration-200 hover:shadow-md dark:shadow-black/25 dark:hover:shadow-lg'
const SELECT_TRIGGER =
  'h-11 rounded-xl border-border/80 bg-background text-sm font-semibold text-foreground shadow-sm dark:bg-input/45'
const DEMO_ORG_NAME_PATTERN = /^(company\s+[ab]|acme\s+(corp|group))$/i

function isDemoOrganization(item) {
  return DEMO_ORG_NAME_PATTERN.test(String(item?.name || '').trim())
}

function sortByName(a, b) {
  return String(a?.name || '').localeCompare(String(b?.name || ''))
}

function parsePayDate(value) {
  if (value == null || value === '') return null
  const d = new Date(value)
  return Number.isNaN(d.getTime()) ? null : d
}

function formatPayPeriodRange(start, end) {
  const a = parsePayDate(start)
  const b = parsePayDate(end)
  if (!a || !b) return `${start ?? '—'} → ${end ?? '—'}`
  const full = { month: 'short', day: 'numeric', year: 'numeric' }
  const sameYear = a.getFullYear() === b.getFullYear()
  const startStr = a.toLocaleDateString(undefined, sameYear ? { month: 'short', day: 'numeric' } : full)
  const endStr = b.toLocaleDateString(undefined, full)
  return `${startStr} – ${endStr}`
}

function formatPeso(n) {
  const v = Number(n)
  if (!Number.isFinite(v)) return '₱0.00'
  return `₱${v.toLocaleString(undefined, { minimumFractionDigits: 2, maximumFractionDigits: 2 })}`
}

function formatCompactPeso(n) {
  const v = Number(n)
  if (!Number.isFinite(v) || v === 0) return '₱0'
  if (v >= 1_000_000) return `₱${(v / 1_000_000).toFixed(1)}M`
  if (v >= 1_000) return `₱${(v / 1_000).toFixed(0)}K`
  return formatPeso(v)
}

function rowGroupKey(r) {
  if (r?.payroll_batch_run_id != null) return String(r.payroll_batch_run_id)
  return `${r.company_id}|${r.pay_period_start}|${r.pay_period_end}`
}

function formatGeneratedDate(iso) {
  if (!iso) return '—'
  try {
    return new Date(iso).toLocaleString(undefined, { dateStyle: 'medium', timeStyle: 'short' })
  } catch {
    return String(iso)
  }
}

function batchStatusBadge(status, statusLabel) {
  const s = String(status || '').toLowerCase()
  const label = (statusLabel && String(statusLabel).trim()) || (s === 'finalized' ? 'Finalized' : s === 'draft' ? 'Draft' : s)
  if (s === 'finalized') {
    return (
      <Badge className="border-brand/30 bg-brand/10 text-brand hover:bg-brand/10">
        <span className="mr-1 inline-block h-1.5 w-1.5 rounded-full bg-brand" />
        {label}
      </Badge>
    )
  }
  if (s === 'partial') {
    return (
      <Badge className="border-amber-200/80 bg-amber-50 text-amber-950 hover:bg-amber-50 dark:border-amber-900/40 dark:bg-amber-950/40 dark:text-amber-100">
        <span className="mr-1 inline-block h-1.5 w-1.5 rounded-full bg-amber-500" />
        {label}
      </Badge>
    )
  }
  if (s === 'queued') {
    return (
      <Badge className="border-brand/30 bg-brand/10 text-brand hover:bg-brand/10">
        <span className="mr-1 inline-block h-1.5 w-1.5 animate-pulse rounded-full bg-brand" />
        {label}
      </Badge>
    )
  }
  if (s === 'processing') {
    return (
      <Badge className="border-amber-200/80 bg-amber-50 text-amber-950 hover:bg-amber-50 dark:border-amber-900/40 dark:bg-amber-950/40 dark:text-amber-100">
        <span className="mr-1 inline-block h-1.5 w-1.5 animate-pulse rounded-full bg-amber-500" />
        {label}
      </Badge>
    )
  }
  if (s === 'failed') {
    return (
      <Badge variant="destructive" className="font-medium">
        <span className="mr-1 inline-block h-1.5 w-1.5 rounded-full bg-red-200" />
        {label}
      </Badge>
    )
  }
  return (
    <Badge className="border-border/70 bg-muted/60 text-foreground hover:bg-muted/60">
      <span className="mr-1 inline-block h-1.5 w-1.5 rounded-full bg-slate-400" />
      {label}
    </Badge>
  )
}

function resolveLogoUrl(logoUrl) {
  if (logoUrl == null || logoUrl === '') return null
  if (typeof logoUrl === 'string' && (logoUrl.startsWith('http://') || logoUrl.startsWith('https://'))) return logoUrl
  if (typeof logoUrl === 'string' && logoUrl.startsWith('/')) {
    const origin = apiOrigin()
    return origin ? `${origin}${logoUrl}` : logoUrl
  }
  return logoUrl
}

function CircularProgress({ value = 0, size = 160, strokeWidth = 10, children, className }) {
  const radius = (size - strokeWidth) / 2
  const circumference = 2 * Math.PI * radius
  const clamped = Math.max(0, Math.min(100, Number(value) || 0))
  const offset = circumference - (clamped / 100) * circumference
  return (
    <div className={cn('relative inline-flex items-center justify-center', className)} style={{ width: size, height: size }}>
      <svg width={size} height={size} className="-rotate-90">
        <circle
          cx={size / 2}
          cy={size / 2}
          r={radius}
          fill="none"
          stroke="currentColor"
          strokeWidth={strokeWidth}
          className="text-muted/50 dark:text-muted/30"
        />
        <circle
          cx={size / 2}
          cy={size / 2}
          r={radius}
          fill="none"
          stroke="url(#progressGradient)"
          strokeWidth={strokeWidth}
          strokeLinecap="round"
          strokeDasharray={circumference}
          strokeDashoffset={offset}
          className="transition-[stroke-dashoffset] duration-700 ease-out"
        />
        <defs>
          <linearGradient id="progressGradient" x1="0%" y1="0%" x2="100%" y2="100%">
            <stop offset="0%" stopColor="#ff7a1a" />
            <stop offset="100%" stopColor="#f24b00" />
          </linearGradient>
        </defs>
      </svg>
      <div className="absolute inset-0 flex items-center justify-center">{children}</div>
    </div>
  )
}

function MetricCard({ icon, label, value, subtext, accent = false }) {
  const MetricIcon = icon
  return (
    <div
      className={cn(
        'rounded-xl border px-4 py-3.5 transition-all duration-200',
        accent
          ? 'border-brand/35 bg-brand/10 dark:border-brand/35 dark:bg-brand/15'
          : 'border-border/60 bg-muted/25 dark:bg-muted/15',
      )}
    >
      <div className="flex items-center gap-2">
        <MetricIcon className={cn('h-4 w-4 shrink-0', accent ? 'text-brand dark:text-brand' : 'text-muted-foreground')} />
        <span className="text-sm font-normal uppercase leading-tight tracking-[0.06em] text-muted-foreground">{label}</span>
      </div>
      <p
        className={cn(
          'mt-1.5 text-[22px] font-medium tabular-nums leading-none tracking-tight',
          accent ? 'font-semibold text-brand' : 'text-foreground',
        )}
      >
        {value}
      </p>
      {subtext && <p className="mt-1 text-[12px] font-normal text-muted-foreground">{subtext}</p>}
    </div>
  )
}

function BreakdownPill({ label, count }) {
  return (
    <span className="inline-flex items-center gap-1.5 rounded-full border border-border/60 bg-background px-3 py-1 text-xs font-medium text-foreground shadow-sm dark:border-border/40">
      {label}
      <span className="rounded-full bg-foreground/10 px-1.5 py-0.5 text-[10px] font-semibold tabular-nums text-foreground dark:bg-foreground/20">
        {count}
      </span>
    </span>
  )
}

export default function AdminGeneratePayslipsPage() {
  const { user } = useAuth()
  const { toast } = useToast()
  const navigate = useNavigate()
  const hrBase = useHrBasePath()
  const isAdmin = user?.role === 'admin' || user?.role === 'super_admin'
  const permissionSet = useMemo(() => new Set(user?.permissions ?? []), [user?.permissions])
  const canManagePayslips = permissionSet.has('payslip.generate')
  const canBulkDownloadPayslipZip = permissionSet.has('payslip.download')

  const [companies, setCompanies] = useState([])
  const [branches, setBranches] = useState([])
  const [departments, setDepartments] = useState([])
  const [cycles, setCycles] = useState([])

  const [companyId, setCompanyId] = useState('')
  const [branchId, setBranchId] = useState('')
  const [departmentId, setDepartmentId] = useState('')
  const [payCycleId, setPayCycleId] = useState('')

  const [fromDate, setFromDate] = useState('')
  const [toDate, setToDate] = useState('')
  const [referenceDate, setReferenceDate] = useState('')
  const [useCompanyDefaultDates, setUseCompanyDefaultDates] = useState(true)
  const [companyDefaultMeta, setCompanyDefaultMeta] = useState({ weekend_adjusted: false, weekend_adjustment_note: null, cycle_label: null })
  const [passwordProtect, setPasswordProtect] = useState(false)
  const [employeeId, setEmployeeId] = useState('')

  const [preview, setPreview] = useState(null)
  const [previewLoading, setPreviewLoading] = useState(false)

  const [generating, setGenerating] = useState(false)

  const [listLoading, setListLoading] = useState(false)
  const [bulkDownloadingBatchId, setBulkDownloadingBatchId] = useState(null)
  /** @type {import('react').MutableRefObject<AbortController|null>} */
  const bulkDownloadAbortRef = useRef(null)
  const [bulkDownloadProgress, setBulkDownloadProgress] = useState(null)
  const [deletingBatchId, setDeletingBatchId] = useState(null)
  const [deleteBatchDialogRow, setDeleteBatchDialogRow] = useState(null)
  const [samplePreviewLoading, setSamplePreviewLoading] = useState(false)
  const [samplePreviewOpen, setSamplePreviewOpen] = useState(false)
  const [samplePreviewData, setSamplePreviewData] = useState(null)
  const [samplePdfDownloading, setSamplePdfDownloading] = useState(false)
  const [companyRows, setCompanyRows] = useState([])
  const [batchEstimateData, setBatchEstimateData] = useState(null)
  const [batchEstimateLoading, setBatchEstimateLoading] = useState(false)

  const loadMeta = useCallback(async () => {
    try {
      const [cRes, cyRes] = await Promise.all([getCompanies({ fresh: true }), getPayCycles()])
      setCompanies(
        (Array.isArray(cRes?.companies) ? cRes.companies : [])
          .filter((company) => company?.id != null && !isDemoOrganization(company))
          .sort(sortByName)
      )
      setCycles(Array.isArray(cyRes?.data) ? cyRes.data : [])
    } catch (e) {
      toast({ title: 'Payslips', description: e.message || 'Failed to load form data', variant: 'destructive' })
    }
  }, [toast])

  const loadBranches = useCallback(async (cid) => {
    if (!cid) {
      setBranches([])
      return
    }
    try {
      const res = await getBranches({ company_id: cid })
      setBranches((Array.isArray(res?.data) ? res.data : []).sort(sortByName))
    } catch {
      setBranches([])
    }
  }, [])

  const loadDepartments = useCallback(async (bid) => {
    if (!bid) {
      setDepartments([])
      return
    }
    try {
      const res = await getDepartments({ branch_id: bid })
      setDepartments((Array.isArray(res?.data) ? res.data : []).sort(sortByName))
    } catch {
      setDepartments([])
    }
  }, [])

  useEffect(() => {
    loadMeta()
  }, [loadMeta])

  useEffect(() => {
    if (isAdmin) return
    const cid = user?.company_id
    if (!cid) return
    setCompanyId((prev) => (prev ? prev : String(cid)))
  }, [isAdmin, user?.company_id])

  useEffect(() => {
    if (!companyId || companies.length === 0) return
    if (companies.some((company) => String(company.id) === String(companyId))) return
    setCompanyId('')
    setBranchId('')
    setDepartmentId('')
  }, [companies, companyId])

  useEffect(() => {
    if (companyId) loadBranches(companyId)
    else {
      setBranches([])
      setBranchId('')
    }
  }, [companyId, loadBranches])

  useEffect(() => {
    if (!branchId || branches.length === 0) return
    if (branches.some((branch) => String(branch.id) === String(branchId))) return
    setBranchId('')
    setDepartmentId('')
  }, [branches, branchId])

  useEffect(() => {
    if (branchId) loadDepartments(branchId)
    else {
      setDepartments([])
      setDepartmentId('')
    }
  }, [branchId, loadDepartments])

  useEffect(() => {
    if (!departmentId || departments.length === 0) return
    if (departments.some((department) => String(department.id) === String(departmentId))) return
    setDepartmentId('')
  }, [departments, departmentId])

  const scopeReady = Boolean(companyId || branchId || departmentId)
  const finalizeReady = !isAdmin || scopeReady || Boolean(String(employeeId || '').trim())

  const bulkPayload = useMemo(
    () => ({
      from_date: fromDate || null,
      to_date: toDate || null,
      pay_cycle_id: payCycleId ? Number(payCycleId) : null,
      reference_date: referenceDate || null,
      // Only treat as "Company Default" when the toggle is ON and no explicit cycle is chosen.
      // When the user manually enters custom dates (toggle OFF), use_company_default must be false
      // so the backend does NOT override the user-provided pay date with default cycle logic.
      use_company_default: useCompanyDefaultDates && !payCycleId,
      password_protect: passwordProtect,
      company_id: companyId ? Number(companyId) : null,
      branch_id: branchId ? Number(branchId) : null,
      department_id: departmentId ? Number(departmentId) : null,
      employee_id: String(employeeId || '').trim() ? Number(employeeId) : null,
    }),
    [fromDate, toDate, payCycleId, referenceDate, useCompanyDefaultDates, passwordProtect, companyId, branchId, departmentId, employeeId],
  )

  useEffect(() => {
    if (!canManagePayslips || (isAdmin && !scopeReady)) {
      setPreview(null)
      return
    }
    let cancelled = false
    const t = setTimeout(async () => {
      setPreviewLoading(true)
      try {
        const data = await getAdminPayslipPreviewScope({
          company_id: companyId ? Number(companyId) : undefined,
          branch_id: branchId ? Number(branchId) : undefined,
          department_id: departmentId ? Number(departmentId) : undefined,
        })
        if (!cancelled) setPreview(data)
      } catch {
        if (!cancelled) setPreview(null)
      } finally {
        if (!cancelled) setPreviewLoading(false)
      }
    }, 380)
    return () => {
      cancelled = true
      clearTimeout(t)
    }
  }, [canManagePayslips, isAdmin, companyId, branchId, departmentId, scopeReady])

  useEffect(() => {
    if (!canManagePayslips || !scopeReady) {
      setBatchEstimateData(null)
      return
    }
    let cancelled = false
    const t = setTimeout(async () => {
      setBatchEstimateLoading(true)
      try {
        const data = await adminPreviewPayslipSampleData(bulkPayload)
        if (!cancelled) setBatchEstimateData(data)
      } catch {
        if (!cancelled) setBatchEstimateData(null)
      } finally {
        if (!cancelled) setBatchEstimateLoading(false)
      }
    }, 450)
    return () => {
      cancelled = true
      clearTimeout(t)
    }
  }, [canManagePayslips, scopeReady, bulkPayload])

  const loadCompanySummary = useCallback(async () => {
    setListLoading(true)
    try {
      const res = await getAdminPayslipsRecentByCompany({
        company_id: companyId || undefined,
        branch_id: branchId || undefined,
        department_id: departmentId || undefined,
        per_page: 15,
      })
      setCompanyRows(Array.isArray(res?.data) ? res.data : [])
    } catch (e) {
      toast({ title: 'Payslips', description: e.message || 'Failed to load summary', variant: 'destructive' })
      setCompanyRows([])
    } finally {
      setListLoading(false)
    }
  }, [companyId, branchId, departmentId, toast])

  useEffect(() => {
    if (canManagePayslips) loadCompanySummary()
  }, [canManagePayslips, loadCompanySummary])

  useEffect(() => {
    const onFinalized = () => {
      loadCompanySummary()
    }
    const onStorage = (e) => {
      if (e.key === 'hr:payroll-finalized-at') loadCompanySummary()
    }
    if (typeof window !== 'undefined') {
      window.addEventListener('hr:payroll-finalized', onFinalized)
      window.addEventListener('storage', onStorage)
      window.addEventListener('focus', onFinalized)
    }
    return () => {
      if (typeof window !== 'undefined') {
        window.removeEventListener('hr:payroll-finalized', onFinalized)
        window.removeEventListener('storage', onStorage)
        window.removeEventListener('focus', onFinalized)
      }
    }
  }, [loadCompanySummary])

  const selectedCompany = useMemo(
    () => companies.find((c) => String(c.id) === String(companyId)),
    [companies, companyId],
  )
  const selectedCompanyLogo = resolveLogoUrl(selectedCompany?.logo_url)

  const activeEmployees = Number(preview?.total_employees ?? 0)
  const regularEmployees = Number(preview?.regular ?? 0)
  const contractualEmployees = Number(preview?.contractual_or_project ?? 0)
  const otherEmployees = Number(preview?.other ?? 0)

  const scopeReadiness = useMemo(() => {
    const checkpoints = [
      Boolean(companyId),
      Boolean(branchId),
      Boolean(departmentId),
      Boolean(payCycleId || useCompanyDefaultDates),
      Boolean(fromDate && toDate),
    ]
    const filled = checkpoints.filter(Boolean).length
    return Math.round((filled / checkpoints.length) * 100)
  }, [companyId, branchId, departmentId, payCycleId, useCompanyDefaultDates, fromDate, toDate])

  const estimatedSeconds = useMemo(() => Math.max(8, Math.round(activeEmployees / 28)), [activeEmployees])

  const sampleGross = Number(batchEstimateData?.amounts?.gross_pay ?? 0)
  const sampleDeductions = Number(batchEstimateData?.amounts?.total_deductions ?? 0)
  const sampleNet = Number(batchEstimateData?.amounts?.net_pay ?? 0)
  const estimatedGross = activeEmployees * sampleGross
  const estimatedDeductions = activeEmployees * sampleDeductions
  const estimatedNet = activeEmployees * sampleNet

  const incompleteAttendance = useMemo(() => {
    const summary = batchEstimateData?.summary?.attendance_display_summary
    const workingDays = Number(summary?.working_days_count ?? 0)
    const actualDays = Number(batchEstimateData?.summary?.actual_days_worked ?? 0)
    if (workingDays > 0 && actualDays >= 0 && actualDays < workingDays) {
      return `${workingDays - actualDays} day${workingDays - actualDays === 1 ? '' : 's'} short in the sample employee attendance.`
    }
    return null
  }, [batchEstimateData])

  const recentListNetTotal = useMemo(() => {
    if (!companyRows.length) return null
    const sum = companyRows.reduce((acc, r) => acc + Number(r.total_net_pay ?? 0), 0)
    return Number.isFinite(sum) ? sum : null
  }, [companyRows])

  const buildFinalizeQuery = useCallback(() => {
    const p = new URLSearchParams()
    if (fromDate) p.set('from_date', fromDate)
    if (toDate) p.set('to_date', toDate)
    if (payCycleId) p.set('pay_cycle_id', String(payCycleId))
    if (referenceDate) p.set('reference_date', referenceDate)
    if (useCompanyDefaultDates && !payCycleId) p.set('use_company_default', 'true')
    if (passwordProtect) p.set('password_protect', 'true')
    if (companyId) p.set('company_id', String(companyId))
    if (branchId) p.set('branch_id', String(branchId))
    if (departmentId) p.set('department_id', String(departmentId))
    const eid = String(employeeId || '').trim()
    if (eid) p.set('employee_id', eid)
    return p
  }, [fromDate, toDate, payCycleId, referenceDate, useCompanyDefaultDates, passwordProtect, companyId, branchId, departmentId, employeeId])

  useEffect(() => {
    if (!payCycleId) return
    if (useCompanyDefaultDates) {
      setUseCompanyDefaultDates(false)
      setCompanyDefaultMeta({ weekend_adjusted: false, weekend_adjustment_note: null, cycle_label: null })
    }
  }, [payCycleId, useCompanyDefaultDates])

  useEffect(() => {
    if (!useCompanyDefaultDates) return
    if (payCycleId) return
    let cancelled = false
    const run = async () => {
      try {
        const todayIso = new Date().toISOString().slice(0, 10)
        const anchor = toDate || fromDate || todayIso
        // Always use anchor_date so the backend derives the correct pay date
        // from the cut-off window (15th / last calendar day of month + weekend adjustment).
        const res = await getAdminCompanyDefaultPayslipDates({
          company_id: companyId ? Number(companyId) : undefined,
          anchor_date: anchor,
        })
        if (cancelled) return
        if (res?.from_date) setFromDate(String(res.from_date))
        if (res?.to_date) setToDate(String(res.to_date))
        if (res?.reference_date) setReferenceDate(String(res.reference_date))
        setCompanyDefaultMeta({
          weekend_adjusted: Boolean(res?.weekend_adjusted),
          weekend_adjustment_note: res?.weekend_adjustment_note ?? null,
          cycle_label: res?.cycle_label ?? null,
        })
      } catch {
        /* keep existing values */
      }
    }
    run()
    return () => {
      cancelled = true
    }
    // companyId triggers re-fetch when company changes; fromDate/toDate only on initial
    // toggle-on (subsequent fetches are idempotent since anchor stays the same).
  }, [useCompanyDefaultDates, payCycleId, companyId, fromDate, toDate])

  const handleGeneratePayslips = useCallback(async () => {
    if (!canManagePayslips) return
    if (!finalizeReady) {
      toast({
        title: 'Select scope or employee',
        description: 'Choose company, branch, or department — or enter a single employee user ID.',
        variant: 'destructive',
      })
      return
    }
    setGenerating(true)
    try {
      const res = await adminGeneratePayslips(bulkPayload)
      toast({
        title: res?.queued === false ? 'Payslips generated' : 'Payroll draft queued',
        description:
          res?.queued === false
            ? `${Number(res?.generated_count ?? res?.employee_count ?? 0)} draft payslip${Number(res?.generated_count ?? res?.employee_count ?? 0) === 1 ? '' : 's'} are ready.`
            : 'Finalize Payroll will open now while Redis computes employee rows in the background.',
      })
      loadCompanySummary()
      const q = new URLSearchParams(buildFinalizeQuery().toString())
      if (res?.pay_period_start) q.set('from_date', String(res.pay_period_start))
      if (res?.pay_period_end) q.set('to_date', String(res.pay_period_end))
      navigate(`${hrBase}/compensation/finalize-payroll?${q.toString()}`)
    } catch (e) {
      toast({ title: 'Generate failed', description: e.message || 'Failed to queue payslip generation', variant: 'destructive' })
    } finally {
      setGenerating(false)
    }
  }, [canManagePayslips, finalizeReady, toast, bulkPayload, loadCompanySummary, buildFinalizeQuery, navigate, hrBase])

  const handleViewBatch = (row) => {
    const q = new URLSearchParams()
    if (row?.company_id != null) q.set('company_id', String(row.company_id))
    else if (companyId) q.set('company_id', String(companyId))
    if (row?.branch_id != null) q.set('branch_id', String(row.branch_id))
    if (row?.department_id != null) q.set('department_id', String(row.department_id))
    if (row?.pay_period_start) q.set('from_date', String(row.pay_period_start))
    if (row?.pay_period_end) q.set('to_date', String(row.pay_period_end))
    if (row?.pay_cycle_id != null) q.set('pay_cycle_id', String(row.pay_cycle_id))
    else if (row?.pay_cycle_source === 'company_default') q.set('use_company_default', 'true')
    navigate(`${hrBase}/compensation/finalize-payroll?${q.toString()}`)
  }

  const openDeleteBatchDialog = (row) => {
    const id = row?.payroll_batch_run_id
    if (id == null || deletingBatchId || !row?.can_delete) return
    setDeleteBatchDialogRow(row)
  }

  const handleBulkDownloadBatchPdf = async (row) => {
    const id = row?.payroll_batch_run_id
    if (id == null || bulkDownloadingBatchId != null) return
    if (String(row?.batch_run_status || '').toLowerCase() !== 'finalized') return
    if (!canBulkDownloadPayslipZip) return

    bulkDownloadAbortRef.current?.abort()
    const abort = new AbortController()
    bulkDownloadAbortRef.current = abort

    setBulkDownloadingBatchId(id)
    setBulkDownloadProgress({ status: 'pending', progress_percent: 0 })
    try {
      const queued = await adminQueueBulkPayslipDownload(id)
      toast({
        title: queued?.message || 'Bulk payslip download is being prepared.',
        description: 'PDFs are generated in the background. You can keep using this page.',
      })
      const requestId = Number(queued?.request_id ?? queued?.bulk_download?.id ?? 0)
      if (!requestId) {
        throw new Error('Server did not return a bulk download request id.')
      }
      const { blob, bulk_download: doneBulk } = await adminPollAndDownloadBulkPayslipZip(requestId, {
        signal: abort.signal,
        onProgress: (b) => setBulkDownloadProgress(b),
      })
      const filename =
        String(doneBulk?.download_filename || '') ||
        `Payslips_${String(row?.company_name || 'batch').replace(/[^\w-]+/g, '_')}.zip`
      saveBulkPayslipZipBlob(blob, filename)
      toast({ title: 'Bulk payslip download is ready.', description: 'Your ZIP download has started.' })
    } catch (e) {
      if (e?.name === 'AbortError') return
      toast({
        title: 'Bulk payslip download failed',
        description: e.message || 'Bulk payslip download failed. Please try again.',
        variant: 'destructive',
      })
    } finally {
      setBulkDownloadingBatchId(null)
      setBulkDownloadProgress(null)
      if (bulkDownloadAbortRef.current === abort) {
        bulkDownloadAbortRef.current = null
      }
    }
  }

  const executeDeleteBatch = async () => {
    const row = deleteBatchDialogRow
    const id = row?.payroll_batch_run_id
    if (id == null || deletingBatchId) return
    setDeletingBatchId(id)
    try {
      await adminDeletePayslipBatch(id)
      toast({
        title: 'Batch deleted',
        description: 'Draft payslip rows for this company and pay period were removed (or the queued run was cancelled).',
      })
      setDeleteBatchDialogRow(null)
      await loadCompanySummary()
      if (typeof window !== 'undefined') {
        window.dispatchEvent(new CustomEvent('hr:payroll-batch-deleted', { detail: { payroll_batch_run_id: id } }))
      }
    } catch (e) {
      toast({ title: 'Delete failed', description: e.message || 'Could not delete batch.', variant: 'destructive' })
    } finally {
      setDeletingBatchId(null)
    }
  }

  const handleViewSamplePreview = async () => {
    if (isAdmin && !scopeReady) {
      toast({
        title: 'Select scope',
        description: 'Choose company, branch, or department to preview a sample payslip for that batch.',
        variant: 'destructive',
      })
      return
    }
    setSamplePreviewLoading(true)
    setSamplePreviewOpen(true)
    setSamplePreviewData(null)
    try {
      const data = await adminPreviewPayslipSampleData(bulkPayload)
      setSamplePreviewData(data)
    } catch (e) {
      toast({ title: 'Preview failed', description: e.message, variant: 'destructive' })
      setSamplePreviewOpen(false)
    } finally {
      setSamplePreviewLoading(false)
    }
  }

  const getSamplePdfBlob = useCallback(async () => {
    const { blob } = await adminPreviewPayslipSampleBlob(bulkPayload)
    return blob
  }, [bulkPayload])

  const handlePrintSamplePreview = async () => {
    if (!samplePreviewData || samplePreviewLoading || samplePdfDownloading) return
    setSamplePdfDownloading(true)
    try {
      const blob = await getSamplePdfBlob()
      const url = URL.createObjectURL(blob)
      const popup = window.open(url, '_blank', 'noopener,noreferrer')
      if (!popup) {
        URL.revokeObjectURL(url)
        throw new Error('Popup blocked. Allow popups for printing.')
      }
      const cleanup = () => URL.revokeObjectURL(url)
      popup.addEventListener('load', () => {
        popup.focus()
        popup.print()
        setTimeout(cleanup, 15000)
      })
    } catch (e) {
      toast({ title: 'Print failed', description: e.message || 'Unable to open printable PDF.', variant: 'destructive' })
    } finally {
      setSamplePdfDownloading(false)
    }
  }

  const handleDownloadSamplePreview = async () => {
    if (!samplePreviewData || samplePdfDownloading) return
    setSamplePdfDownloading(true)
    try {
      const { blob, pdfPassword } = await adminPreviewPayslipSampleBlob(bulkPayload)
      const safeName = String(samplePreviewData?.employee?.name || 'sample').replace(/[^\w-]+/g, '-')
      const url = URL.createObjectURL(blob)
      const a = document.createElement('a')
      a.href = url
      a.download = `payslip-sample-${safeName}.pdf`
      a.click()
      URL.revokeObjectURL(url)
      if (pdfPassword) {
        toast({
          title: 'PDF downloaded',
          description: `Password: ${pdfPassword}`,
        })
      }
    } catch (e) {
      toast({ title: 'Download failed', description: e.message || 'Could not generate sample PDF.', variant: 'destructive' })
    } finally {
      setSamplePdfDownloading(false)
    }
  }

  if (!canManagePayslips) {
    return (
      <TooltipProvider>
        <div className={cn(PAYSLIP_MODULE_SHELL, PAYSLIP_STACK)}>
          <Card className={cn('mx-auto max-w-lg', CARD_SHELL)}>
            <CardHeader>
              <CardTitle className="text-foreground">Bulk payslip generation</CardTitle>
              <CardDescription>
                You do not have permission to generate payslips.
              </CardDescription>
            </CardHeader>
          </Card>
        </div>
      </TooltipProvider>
    )
  }

  return (
    <TooltipProvider>
      <div className={cn(PAYSLIP_MODULE_SHELL, PAYSLIP_STACK)}>
        {/* ── Hero Header ── */}
        <div className="overflow-hidden rounded-2xl border border-border/80 bg-card shadow-sm shadow-slate-900/[0.03] dark:shadow-black/25">
          <div className="relative grid min-h-[220px] gap-6 p-6 md:grid-cols-[1fr_290px] md:p-8">
            <div className="relative z-10 max-w-3xl space-y-3 self-center">
              <Badge
                variant="outline"
                className="w-fit rounded-full border-brand/30 bg-brand/10 px-3 py-1 text-[12px] font-bold tracking-normal text-brand hover:bg-brand/10"
              >
                <Zap className="mr-1 h-3 w-3" />
                Payroll · Compensation
              </Badge>
              <h1 className="text-[30px] font-extrabold leading-tight tracking-normal text-foreground md:text-[34px]">
                Bulk Payslip Generation
              </h1>
              <p className="max-w-2xl text-[15px] font-medium leading-7 text-muted-foreground">
                Generate official PDF payslips for active employees in the selected scope using the same payroll engine as your
                previews — pay components, statutory deductions, loans, pay cycles, and daily computation.
              </p>
            </div>
            <div className="pointer-events-none relative hidden min-h-[150px] items-center justify-center md:flex" aria-hidden>
              <div className="absolute inset-y-8 right-0 w-full opacity-70 [background-image:radial-gradient(circle,#fb923c_1.4px,transparent_1.4px)] [background-size:18px_18px] dark:opacity-25" />
              <div className="relative h-[150px] w-[122px] rounded-xl border border-brand/25 bg-background shadow-md shadow-slate-900/10 dark:bg-card dark:shadow-black/30">
                <div className="absolute right-0 top-0 h-9 w-9 rounded-bl-xl bg-muted" />
                <div className="mx-auto mt-9 h-1.5 w-12 rounded-full bg-brand" />
                <div className="mx-auto mt-6 h-1.5 w-14 rounded-full bg-brand" />
                <div className="mx-auto mt-4 h-1.5 w-20 rounded-full bg-brand" />
                <div className="mx-auto mt-4 h-1.5 w-16 rounded-full bg-brand" />
                <div className="mx-auto mt-4 h-1.5 w-24 rounded-full bg-brand" />
              </div>
              <div className="absolute bottom-6 right-9 flex h-16 w-16 items-center justify-center rounded-full bg-brand text-3xl font-extrabold text-brand-foreground shadow-lg shadow-brand/30">
                <PhilippinePeso className="h-8 w-8" />
              </div>
            </div>
          </div>
        </div>

        {/* ── Two-Column Layout ── */}
        <div className="grid grid-cols-1 gap-6 lg:grid-cols-[1fr_400px] lg:items-start">
          {/* ── LEFT: Generation Parameters (70%) ── */}
          <div className="space-y-6">
            <Card className={CARD_SHELL}>
              <CardHeader className="pb-5">
                <div className="flex items-center gap-3">
                  <div className="flex h-11 w-11 shrink-0 items-center justify-center rounded-xl bg-brand/10 text-brand ring-1 ring-brand/15">
                    <Layers className="h-6 w-6" />
                  </div>
                  <div>
                    <CardTitle className="text-lg font-bold text-foreground @md:text-xl">Generation Parameters</CardTitle>
                    <CardDescription className="text-sm font-normal text-muted-foreground">
                      Narrow the batch by company, branch, and department. Choose a pay cycle or use company defaults.
                    </CardDescription>
                  </div>
                </div>
              </CardHeader>
              <CardContent className="space-y-8 pt-6">
                {/* Company Entity — full width with logo */}
                <div className="space-y-3">
                  <Label className="flex items-center gap-2 text-sm font-normal text-muted-foreground">
                    <Building2 className="h-4 w-4 shrink-0 text-muted-foreground/80" aria-hidden />
                    Company Entity
                  </Label>
                  <div className="flex flex-col gap-3 sm:flex-row sm:items-center">
                    <div
                      className="flex h-14 w-14 shrink-0 items-center justify-center overflow-hidden rounded-xl border border-border/80 bg-background shadow-sm transition-all duration-200 dark:bg-input/35"
                      aria-hidden
                    >
                      {selectedCompanyLogo ? (
                        <img src={selectedCompanyLogo} alt="" className="max-h-14 max-w-14 object-contain" />
                      ) : (
                        <Building2 className="h-7 w-7 text-muted-foreground/50" />
                      )}
                    </div>
                    <div className="min-w-0 flex-1">
                      <Select value={companyId || '__none__'} onValueChange={(v) => setCompanyId(v === '__none__' ? '' : v)}>
                        <SelectTrigger className={`${SELECT_TRIGGER} h-12 w-full`}>
                          <SelectValue placeholder="Select company" />
                        </SelectTrigger>
                        <SelectContent>
                          <SelectItem value="__none__">Select company…</SelectItem>
                          {companies.map((c) => (
                            <SelectItem key={c.id} value={String(c.id)}>
                              {c.name}
                            </SelectItem>
                          ))}
                        </SelectContent>
                      </Select>
                      <p className="mt-1.5 text-xs text-muted-foreground">
                        Choose a company to filter branches and see its logo.
                      </p>
                    </div>
                  </div>
                </div>

                {/* Branch + Department — two columns */}
                <div className="grid grid-cols-1 gap-6 md:grid-cols-2">
                  <div className="space-y-2">
                    <Label className="flex items-center gap-2 text-sm font-normal text-muted-foreground">
                      <MapPin className="h-4 w-4 shrink-0 text-muted-foreground/80" aria-hidden />
                      Branch Location
                    </Label>
                    <Select
                      value={branchId || '__none__'}
                      onValueChange={(v) => setBranchId(v === '__none__' ? '' : v)}
                      disabled={!companyId}
                    >
                      <SelectTrigger className={SELECT_TRIGGER}>
                        <SelectValue placeholder={companyId ? 'All branches in company' : 'Select company first'} />
                      </SelectTrigger>
                      <SelectContent>
                        <SelectItem value="__none__">All branches (company scope)</SelectItem>
                        {branches.map((b) => (
                          <SelectItem key={b.id} value={String(b.id)}>
                            {b.name}
                          </SelectItem>
                        ))}
                      </SelectContent>
                    </Select>
                  </div>

                  <div className="space-y-2">
                    <Label className="flex items-center gap-2 text-sm font-normal text-muted-foreground">
                      <Users className="h-4 w-4 shrink-0 text-muted-foreground/80" aria-hidden />
                      Department
                    </Label>
                    <Select
                      value={departmentId || '__none__'}
                      onValueChange={(v) => setDepartmentId(v === '__none__' ? '' : v)}
                      disabled={!branchId}
                    >
                      <SelectTrigger className={SELECT_TRIGGER}>
                        <SelectValue placeholder={branchId ? 'All departments in branch' : 'Select branch first'} />
                      </SelectTrigger>
                      <SelectContent>
                        <SelectItem value="__none__">All departments (branch scope)</SelectItem>
                        {departments.map((d) => (
                          <SelectItem key={d.id} value={String(d.id)}>
                            {d.name}
                          </SelectItem>
                        ))}
                      </SelectContent>
                    </Select>
                  </div>
                </div>

                {/* Pay Cycle — full width */}
                <div className="space-y-2">
                  <Label className="flex items-center gap-2 text-sm font-normal text-muted-foreground">
                    <CalendarClock className="h-4 w-4 shrink-0 text-muted-foreground/80" aria-hidden />
                    Pay Period (Pay Cycle)
                  </Label>
                  <Select value={payCycleId || '__none__'} onValueChange={(v) => setPayCycleId(v === '__none__' ? '' : v)}>
                    <SelectTrigger className={SELECT_TRIGGER}>
                      <SelectValue placeholder="Default (employee / company cycle)" />
                    </SelectTrigger>
                    <SelectContent>
                      <SelectItem value="__none__">Use employee / company default</SelectItem>
                      {cycles.map((c) => (
                        <SelectItem key={c.id} value={String(c.id)}>
                          {c.name} · {c.code}
                        </SelectItem>
                      ))}
                    </SelectContent>
                  </Select>
                </div>

                {/* Advanced Options */}
                <Accordion type="single" collapsible className="rounded-xl border border-border/80 bg-background dark:bg-input/30">
                  <AccordionItem value="advanced" className="border-0">
                    <AccordionTrigger className="px-4 py-3.5 text-sm font-semibold text-foreground hover:no-underline data-[state=open]:font-bold">
                      Advanced — Custom Dates & Security
                    </AccordionTrigger>
                    <AccordionContent className="space-y-4 px-4 pb-4">
                      <div className="flex items-center justify-between gap-3 rounded-lg border border-border/80 bg-card px-3 py-2.5 shadow-sm dark:bg-input/35">
                        <div className="min-w-0">
                          <p className="text-base font-semibold text-foreground">Use company default cut-off and pay date</p>
                          <p className="text-xs text-muted-foreground">
                            Auto-fills cut-off dates and sets Reference Date = Pay Date. Weekend adjustment: pay on Friday if Saturday/Sunday.
                          </p>
                        </div>
                        <Switch
                          checked={useCompanyDefaultDates}
                          onCheckedChange={(v) => {
                            const next = Boolean(v)
                            setUseCompanyDefaultDates(next)
                            if (!next) {
                              setCompanyDefaultMeta({ weekend_adjusted: false, weekend_adjustment_note: null, cycle_label: null })
                            }
                          }}
                          disabled={Boolean(payCycleId)}
                        />
                      </div>
                      {useCompanyDefaultDates && !payCycleId && companyDefaultMeta?.weekend_adjustment_note ? (
                        <p className="text-xs text-muted-foreground">{companyDefaultMeta.weekend_adjustment_note}</p>
                      ) : null}
                      <div className="grid grid-cols-1 gap-4 md:grid-cols-3">
                        <div className="space-y-2">
                          <Label className="text-sm font-normal text-muted-foreground">From date</Label>
                          <Input
                            type="date"
                            value={fromDate}
                            onChange={(e) => setFromDate(e.target.value)}
                            disabled={useCompanyDefaultDates && !payCycleId}
                            className="h-10 rounded-lg border-border/80 bg-background dark:bg-input/45"
                          />
                        </div>
                        <div className="space-y-2">
                          <Label className="text-sm font-normal text-muted-foreground">To date</Label>
                          <Input
                            type="date"
                            value={toDate}
                            onChange={(e) => setToDate(e.target.value)}
                            disabled={useCompanyDefaultDates && !payCycleId}
                            className="h-10 rounded-lg border-border/80 bg-background dark:bg-input/45"
                          />
                        </div>
                        <div className="space-y-2">
                          <Label className="text-sm font-normal text-muted-foreground">Reference date</Label>
                          <Input
                            type="date"
                            value={referenceDate}
                            onChange={(e) => setReferenceDate(e.target.value)}
                            disabled={useCompanyDefaultDates && !payCycleId}
                            className="h-10 rounded-lg border-border/80 bg-background dark:bg-input/45"
                          />
                        </div>
                      </div>
                      <div className="flex items-center justify-between gap-3 rounded-lg border border-border/80 bg-card px-3 py-2.5 shadow-sm dark:bg-input/35">
                        <div>
                          <p className="text-base font-semibold text-foreground">Password-protect PDFs</p>
                          <p className="text-xs text-muted-foreground">Password is shown once after generation for secure download.</p>
                        </div>
                        <Switch checked={passwordProtect} onCheckedChange={setPasswordProtect} />
                      </div>
                      <Separator />
                      <div className="space-y-2">
                        <Label className="text-sm font-normal text-muted-foreground">Single employee (optional)</Label>
                        <div className="flex flex-col gap-2 sm:flex-row">
                          <Input
                            value={employeeId}
                            onChange={(e) => setEmployeeId(e.target.value)}
                            placeholder="Employee user ID"
                            inputMode="numeric"
                            className="h-10 rounded-lg border-border/80 bg-background sm:max-w-xs dark:bg-input/45"
                          />
                          <Button
                            type="button"
                            variant="outline"
                            className="h-10 rounded-lg border-border/80"
                            onClick={handleGeneratePayslips}
                            disabled={!String(employeeId || '').trim() || generating}
                          >
                            Generate one
                          </Button>
                        </div>
                      </div>
                    </AccordionContent>
                  </AccordionItem>
                </Accordion>
              </CardContent>
            </Card>

            {/* ── Action Area ── */}
            <div
              className={cn(
                'overflow-hidden rounded-2xl border bg-card shadow-sm shadow-slate-900/[0.03] dark:shadow-black/25',
                scopeReady
                  ? 'border-brand/35 ring-1 ring-brand/10'
                  : 'border-border/80',
              )}
            >
              <div className="p-5 md:p-6">
                {scopeReady && (
                  <div className="mb-4 overflow-hidden rounded-full">
                    <Progress value={scopeReadiness} className="h-1.5" indicatorClassName="bg-brand" />
                  </div>
                )}
                <div className="flex flex-col gap-5 lg:flex-row lg:items-center lg:justify-between">
                  <div className="max-w-2xl space-y-2">
                    <p className="text-[17px] font-semibold leading-snug text-foreground">
                      {scopeReady
                        ? `Ready to generate · ${activeEmployees} employee${activeEmployees === 1 ? '' : 's'} in scope`
                        : 'Choose filters to estimate your batch'}
                    </p>
                    {scopeReady ? (
                      recentListNetTotal != null && companyRows.length > 0 ? (
                        <p className="text-sm font-normal leading-relaxed text-muted-foreground">
                          <span className="text-xl font-semibold tabular-nums text-brand">
                            {formatPeso(recentListNetTotal)}
                          </span>{' '}
                          <span className="text-muted-foreground">combined net in the company summary below.</span>
                        </p>
                      ) : (
                        <p className="text-sm font-normal text-muted-foreground">
                          Continue to Finalize Payroll to review totals and generate PDF payslips.
                        </p>
                      )
                    ) : (
                      <p className="text-sm font-normal text-muted-foreground">
                        Select a company, branch, or department to see counts and run bulk generation.
                      </p>
                    )}
                  </div>
                  <div className="flex flex-col gap-2 sm:flex-row sm:items-center sm:justify-end">
                    <Button
                      type="button"
                      variant="outline"
                      size="default"
                      disabled={!scopeReady || samplePreviewLoading}
                      className="h-10 min-w-[168px] rounded-xl border-border/80 bg-background text-sm font-semibold text-foreground shadow-sm hover:bg-muted disabled:opacity-60 dark:bg-input/35"
                      onClick={handleViewSamplePreview}
                    >
                      {samplePreviewLoading ? (
                        <Loader2 className="mr-2 h-4 w-4 shrink-0 animate-spin" />
                      ) : (
                        <FileText className="mr-2 h-4 w-4 shrink-0" />
                      )}
                      View Sample Preview
                    </Button>
                    <Button
                      type="button"
                      size="lg"
                      disabled={!finalizeReady || generating}
                      onClick={handleGeneratePayslips}
                      className={cn(
                        'h-12 min-w-[220px] rounded-xl px-8 text-[16px] font-bold shadow-lg transition-all duration-200 disabled:opacity-60',
                        'bg-brand text-brand-foreground hover:bg-brand-strong',
                        'shadow-[0_8px_24px_rgba(249,115,22,0.35)] dark:shadow-[0_8px_24px_rgba(251,146,60,0.24)]',
                      )}
                    >
                      {generating ? <Loader2 className="mr-2 h-4 w-4 animate-spin" /> : <Sparkles className="mr-2 h-4 w-4" />}
                      {generating ? 'Queuing…' : 'Generate Payslips'}
                    </Button>
                  </div>
                </div>
              </div>
            </div>
          </div>

          {/* ── RIGHT: Live Processing Summary (30%) ── */}
          <div className="lg:sticky lg:top-6">
            <Card className={cn(CARD_SHELL, 'overflow-hidden')}>
              <div className="bg-linear-to-br from-transparent via-transparent to-brand/5 dark:to-brand/10">
                <CardHeader className="pb-4">
                  <div className="flex items-center justify-between">
                    <div>
                      <CardTitle className="text-lg font-bold text-foreground">Processing Summary</CardTitle>
                      <CardDescription className="text-xs font-normal text-muted-foreground">
                        Live estimate for current filters
                      </CardDescription>
                    </div>
                    {previewLoading && <Loader2 className="h-4 w-4 animate-spin text-muted-foreground" />}
                  </div>
                </CardHeader>
                <CardContent className="space-y-5 pt-5">
                  {/* Circular Progress Ring */}
                  <div className="flex justify-center py-2">
                    <CircularProgress value={scopeReady ? scopeReadiness : 0} size={160} strokeWidth={10}>
                      <div className="text-center">
                        <p className="text-5xl font-extrabold tabular-nums tracking-tight text-foreground transition-all duration-500 md:text-[56px] md:leading-none">
                          {scopeReady ? activeEmployees : '—'}
                        </p>
                        <p className="mt-1 text-[11px] font-semibold uppercase tracking-[0.14em] text-muted-foreground">
                          Employees
                        </p>
                      </div>
                    </CircularProgress>
                  </div>

                  {/* Breakdown pills */}
                  {scopeReady && (
                    <div className="flex flex-wrap justify-center gap-2">
                      {regularEmployees > 0 && <BreakdownPill label="Regular" count={regularEmployees} />}
                      {contractualEmployees > 0 && <BreakdownPill label="Contractual" count={contractualEmployees} />}
                      {otherEmployees > 0 && <BreakdownPill label="Other" count={otherEmployees} />}
                    </div>
                  )}

                  <Separator className="my-1" />

                  {/* Financial Estimates */}
                  <div className="space-y-2.5">
                    <MetricCard
                      icon={TrendingUp}
                      label="Est. Gross Payroll"
                      value={scopeReady && estimatedGross > 0 ? formatCompactPeso(estimatedGross) : '—'}
                      subtext={scopeReady && sampleGross > 0 ? `${formatPeso(sampleGross)} avg/employee` : undefined}
                    />
                    <MetricCard
                      icon={TrendingDown}
                      label="Est. Total Deductions"
                      value={scopeReady && estimatedDeductions > 0 ? formatCompactPeso(estimatedDeductions) : '—'}
                      subtext={scopeReady && sampleDeductions > 0 ? `${formatPeso(sampleDeductions)} avg/employee` : undefined}
                    />
                    <MetricCard
                      icon={PhilippinePeso}
                      label="Est. Net Pay"
                      value={scopeReady && estimatedNet > 0 ? formatCompactPeso(estimatedNet) : '—'}
                      subtext={scopeReady && sampleNet > 0 ? `${formatPeso(sampleNet)} avg/employee` : undefined}
                      accent
                    />
                  </div>

                  {/* Estimated processing time */}
                  <div className="rounded-xl border border-border/80 bg-background px-4 py-3 dark:bg-input/35">
                    <div className="flex items-center justify-between">
                      <span className="flex items-center gap-2 text-xs font-normal text-muted-foreground">
                        <Clock3 className="h-3.5 w-3.5 opacity-80" />
                        Est. Processing Time
                      </span>
                      <span className="text-xs font-medium tabular-nums text-muted-foreground">
                        ~{scopeReady ? estimatedSeconds : 0}s
                      </span>
                    </div>
                    {scopeReady && (
                      <div className="mt-2">
                        <Progress
                          value={Math.min(100, (activeEmployees / Math.max(activeEmployees, 100)) * 100)}
                          className="h-1"
                          indicatorClassName="bg-brand"
                        />
                      </div>
                    )}
                  </div>

                  {/* Branches in scope */}
                  <div className="flex items-center justify-between rounded-xl border border-border/80 bg-background px-4 py-3 dark:bg-input/35">
                    <span className="flex items-center gap-2 text-xs font-normal text-muted-foreground">
                      <Layers className="h-3.5 w-3.5 opacity-80" />
                      Branches in scope
                    </span>
                    <span className="text-sm font-medium tabular-nums text-muted-foreground">{preview?.branches_filtered ?? '—'}</span>
                  </div>

                  {/* Attendance Warning */}
                  {incompleteAttendance && scopeReady && (
                    <div className="flex items-start gap-2.5 rounded-xl border border-amber-200/80 bg-amber-50/50 px-4 py-3 dark:border-amber-900/40 dark:bg-amber-950/30">
                      <AlertTriangle className="mt-0.5 h-4 w-4 shrink-0 text-amber-600 dark:text-amber-400" />
                      <p className="text-xs leading-relaxed text-amber-800 dark:text-amber-200">{incompleteAttendance}</p>
                    </div>
                  )}

                  {/* Batch estimate loading */}
                  {batchEstimateLoading && scopeReady && (
                    <div className="flex items-center justify-center gap-2 py-1 text-xs text-muted-foreground">
                      <Loader2 className="h-3 w-3 animate-spin" />
                      Calculating estimates…
                    </div>
                  )}
                </CardContent>
              </div>
            </Card>
          </div>
        </div>

        {/* ── Recent Payslips Table ── */}
        <Card className={CARD_SHELL}>
          <CardHeader className="flex flex-col gap-1 md:flex-row md:items-center md:justify-between">
            <div className="flex items-center gap-3">
              <div className="flex h-10 w-10 items-center justify-center rounded-xl bg-brand/10 text-brand">
                <FileText className="h-5 w-5" />
              </div>
              <div>
                <CardTitle className="text-lg font-bold text-foreground @md:text-[19px]">Recent Payslips</CardTitle>
                <CardDescription className="text-sm font-normal text-muted-foreground">
                  Aggregated by company and pay period
                </CardDescription>
              </div>
            </div>
            <Button
              type="button"
              variant="outline"
              size="sm"
              onClick={loadCompanySummary}
              disabled={listLoading}
              className="shrink-0 rounded-lg"
            >
              <RefreshCw className={`mr-2 h-4 w-4 ${listLoading ? 'animate-spin' : ''}`} />
              Refresh
            </Button>
          </CardHeader>
          <CardContent className="pt-6">
            {listLoading ? (
              <div className="space-y-3">
                {Array.from({ length: 5 }).map((_, i) => (
                  <div key={i} className="flex items-center gap-4 rounded-lg p-3">
                    <Skeleton className="h-10 w-10 rounded-xl" />
                    <div className="flex-1 space-y-2">
                      <Skeleton className="h-4 w-56" />
                      <Skeleton className="h-3 w-40" />
                    </div>
                    <Skeleton className="h-6 w-20 rounded-full" />
                    <Skeleton className="h-4 w-28" />
                  </div>
                ))}
              </div>
            ) : companyRows.length === 0 ? (
              <div className="flex flex-col items-center justify-center py-16 text-center">
                <div className="flex h-16 w-16 items-center justify-center rounded-2xl bg-muted/50 dark:bg-muted/30">
                  <FileText className="h-7 w-7 text-muted-foreground/50" />
                </div>
                <p className="mt-4 text-sm font-medium text-foreground">No payslip batches</p>
                <p className="mt-1 max-w-sm text-xs text-muted-foreground">
                  No payslip batches match the current filters. Generate your first batch using the form above.
                </p>
              </div>
            ) : (
              <div className="w-full overflow-x-auto rounded-xl bg-transparent">
                <Table className="w-full min-w-[880px] border-0 border-collapse-separate [border-spacing:0] [&_td]:border-0 [&_th]:border-0 [&_tr]:border-0">
                  <TableHeader className="[&_tr]:border-0">
                    <TableRow className="border-0 bg-background hover:bg-background dark:bg-input/25 dark:hover:bg-input/25">
                      <TableHead className="min-w-[200px] text-[13px] font-bold tracking-normal text-foreground">
                        Company
                      </TableHead>
                      <TableHead className="min-w-[180px] text-[13px] font-bold tracking-normal text-foreground">
                        Pay Period
                      </TableHead>
                      <TableHead className="w-[120px] text-right text-[13px] font-bold tracking-normal text-foreground">
                        Employees
                      </TableHead>
                      <TableHead className="min-w-[130px] text-right text-[13px] font-bold tracking-normal text-foreground">
                        Total Net Pay
                      </TableHead>
                      <TableHead className="min-w-[160px] text-[13px] font-bold tracking-normal text-foreground">
                        Generated
                      </TableHead>
                      <TableHead className="w-[110px] text-[13px] font-bold tracking-normal text-foreground">Status</TableHead>
                      <TableHead className="min-w-[200px] text-right text-[13px] font-bold tracking-normal text-foreground">
                        Actions
                      </TableHead>
                    </TableRow>
                  </TableHeader>
                  <TableBody className="[&_tr]:border-0 [&_tr]:transition-colors divide-y divide-border/70">
                    {companyRows.map((r) => {
                      const key = rowGroupKey(r)
                      const logo = resolveLogoUrl(r.company_logo_url)
                      const showDelete = Boolean(r.can_delete)
                      const deleteDisabled = !r.can_delete || deletingBatchId === r.payroll_batch_run_id
                      const batchFinalized = String(r.batch_run_status || '').toLowerCase() === 'finalized'
                      const showBulkPdf = batchFinalized && canBulkDownloadPayslipZip
                      return (
                        <TableRow
                          key={key}
                          className="group border-0 transition-colors hover:bg-muted/35"
                        >
                          <TableCell className="py-4">
                            <div className="flex items-center gap-3">
                              <div className="flex h-10 w-10 shrink-0 items-center justify-center overflow-hidden rounded-xl border border-border/80 bg-background transition-shadow group-hover:shadow-sm dark:bg-input/35">
                                {logo ? (
                                  <img src={logo} alt="" className="max-h-9 max-w-9 object-contain" />
                                ) : (
                                  <Building2 className="h-4 w-4 text-muted-foreground" />
                                )}
                              </div>
                              <span className="text-base font-bold text-foreground">{r.company_name ?? '—'}</span>
                            </div>
                          </TableCell>
                          <TableCell className="py-4">
                            <div className="text-sm font-semibold text-foreground">
                              {formatPayPeriodRange(r.pay_period_start, r.pay_period_end)}
                            </div>
                            {r.pay_cycle_source_label ? (
                              <div className="mt-0.5 text-xs text-muted-foreground">{r.pay_cycle_source_label}</div>
                            ) : null}
                          </TableCell>
                          <TableCell className="py-4 text-right">
                            <div className="flex items-center justify-end gap-2">
                              <span className="text-sm font-semibold tabular-nums text-foreground">
                                {r.employee_count ?? '—'}
                              </span>
                            </div>
                            {['queued', 'processing'].includes(String(r.batch_run_status || '').toLowerCase()) ? (
                              <div className="mt-1 text-[11px] font-medium tabular-nums text-muted-foreground">
                                {Number(r.processed_employees || 0)}/{Number(r.total_employees || r.employee_count || 0)} computed
                              </div>
                            ) : null}
                          </TableCell>
                          <TableCell className="py-4 text-right">
                            <span className="text-base font-semibold tabular-nums text-foreground">
                              {formatPeso(r.total_net_pay)}
                            </span>
                          </TableCell>
                          <TableCell className="py-4 text-sm text-muted-foreground">
                            {formatGeneratedDate(r.generated_at)}
                          </TableCell>
                          <TableCell className="py-4">{batchStatusBadge(r.status, r.status_label)}</TableCell>
                          <TableCell className="py-4 text-right">
                            <div className="flex flex-wrap items-center justify-end gap-2">
                              <Button
                                type="button"
                                size="sm"
                                variant="outline"
                                className="h-8 rounded-lg border-border/80 bg-background px-3 text-xs font-semibold text-foreground shadow-sm hover:bg-muted dark:bg-input/35"
                                onClick={() => handleViewBatch(r)}
                              >
                                <Eye className="mr-1.5 h-4 w-4" />
                                View
                              </Button>
                              {showBulkPdf && (
                                <Button
                                  type="button"
                                  size="sm"
                                  variant="outline"
                                  className="h-8 rounded-lg border-border/80 bg-background px-3 text-xs font-semibold text-foreground shadow-sm hover:bg-muted dark:bg-input/35"
                                  disabled={bulkDownloadingBatchId === r.payroll_batch_run_id}
                                  onClick={() => handleBulkDownloadBatchPdf(r)}
                                >
                                  {bulkDownloadingBatchId === r.payroll_batch_run_id ? (
                                    <Loader2 className="mr-1.5 h-4 w-4 animate-spin" />
                                  ) : (
                                    <FileDown className="mr-1.5 h-4 w-4" />
                                  )}
                                  {bulkDownloadingBatchId === r.payroll_batch_run_id && bulkDownloadProgress
                                    ? `${bulkPayslipDownloadStatusLabel(bulkDownloadProgress)}${
                                        bulkDownloadProgress.progress_percent != null
                                          ? ` (${bulkDownloadProgress.progress_percent}%)`
                                          : ''
                                      }`
                                    : 'Bulk Download PDF'}
                                </Button>
                              )}
                              {showDelete && (
                                <Button
                                  type="button"
                                  size="sm"
                                  variant="outline"
                                  title={
                                    deleteDisabled && !deletingBatchId
                                      ? 'Only draft, queued, failed, or empty stuck generating batches can be deleted.'
                                      : undefined
                                  }
                                  className="h-8 rounded-lg border-red-200/70 bg-background px-3 text-xs font-normal text-red-600 shadow-sm hover:bg-red-50 disabled:opacity-50 dark:border-red-900/40 dark:text-red-400 dark:hover:bg-red-950/30"
                                  disabled={deleteDisabled}
                                  onClick={() => openDeleteBatchDialog(r)}
                                >
                                  {deletingBatchId === r.payroll_batch_run_id ? (
                                    <Loader2 className="mr-1.5 h-4 w-4 animate-spin" />
                                  ) : (
                                    <Trash2 className="mr-1.5 h-4 w-4" />
                                  )}
                                  Delete
                                </Button>
                              )}
                            </div>
                          </TableCell>
                        </TableRow>
                      )
                    })}
                  </TableBody>
                </Table>
              </div>
            )}
          </CardContent>
        </Card>

        {/* ── Delete Batch Dialog ── */}
        <Dialog
          open={deleteBatchDialogRow != null}
          onOpenChange={(open) => {
            if (!open) setDeleteBatchDialogRow(null)
          }}
        >
          <DialogContent className="sm:max-w-md">
            <DialogHeader>
              <DialogTitle className="text-foreground">Delete this batch?</DialogTitle>
              <DialogDescription className="text-left text-muted-foreground">
                This removes draft payslip rows or cancels a failed, queued, or empty stuck generation for{' '}
                <span className="font-medium text-foreground">{deleteBatchDialogRow?.company_name ?? 'this company'}</span>
                {deleteBatchDialogRow?.pay_period_start && deleteBatchDialogRow?.pay_period_end ? (
                  <>
                    {' '}
                    ({formatPayPeriodRange(deleteBatchDialogRow.pay_period_start, deleteBatchDialogRow.pay_period_end)}).
                  </>
                ) : (
                  '.'
                )}{' '}
                Finalized payslips cannot be deleted. This cannot be undone.
              </DialogDescription>
            </DialogHeader>
            <DialogFooter className="gap-2 sm:justify-end">
              <Button type="button" variant="outline" onClick={() => setDeleteBatchDialogRow(null)} disabled={deletingBatchId != null}>
                Cancel
              </Button>
              <Button type="button" variant="destructive" onClick={() => void executeDeleteBatch()} disabled={deletingBatchId != null}>
                {deletingBatchId != null ? <Loader2 className="mr-2 h-4 w-4 animate-spin" /> : null}
                Delete batch
              </Button>
            </DialogFooter>
          </DialogContent>
        </Dialog>

        {/* ── Sample Preview Dialog ── */}
        <Dialog
          open={samplePreviewOpen}
          onOpenChange={(open) => {
            if (!open) {
              setSamplePreviewOpen(false)
              setSamplePreviewData(null)
              setSamplePdfDownloading(false)
              return
            }
            setSamplePreviewOpen(true)
          }}
        >
          <DialogContent className={cn(PAYSLIP_PREVIEW_DIALOG, 'max-h-[95vh]')}>
            <style dangerouslySetInnerHTML={{ __html: PAYSLIP_MODAL_PRINT_STYLES }} />
            <div data-payslip-modal-chrome className="border-b border-border/80 bg-card px-6 py-4">
              <DialogHeader>
                <div className="flex items-start justify-between gap-4">
                  <DialogTitle>Sample payslip preview</DialogTitle>
                  <div className="flex items-center gap-2">
                    <Button
                      type="button"
                      variant="outline"
                      onClick={handlePrintSamplePreview}
                      disabled={!samplePreviewData || samplePreviewLoading}
                    >
                      <Printer className="mr-2 h-4 w-4" />
                      Print
                    </Button>
                    <Button
                      type="button"
                      variant="outline"
                      onClick={() => void handleDownloadSamplePreview()}
                      disabled={!samplePreviewData || samplePreviewLoading || samplePdfDownloading}
                    >
                      {samplePdfDownloading ? (
                        <Loader2 className="mr-2 h-4 w-4 animate-spin" />
                      ) : (
                        <FileDown className="mr-2 h-4 w-4" />
                      )}
                      Download PDF
                    </Button>
                  </div>
                </div>
              </DialogHeader>
            </div>
            <div data-payslip-print-mount className="h-[88vh] overflow-y-auto bg-muted/35 p-6">
              {samplePreviewLoading ? (
                <div className="flex h-full items-center justify-center rounded-2xl border border-border/80 bg-card text-muted-foreground">
                  <Loader2 className="mr-2 h-5 w-5 animate-spin" />
                  Loading sample data…
                </div>
              ) : samplePreviewData ? (
                <div className="mx-auto h-full w-full max-w-[min(80rem,100%)] rounded-2xl border border-border/80 bg-card p-3 shadow-sm">
                  <PayslipHtmlDocument data={samplePreviewData} isPreviewMode />
                </div>
              ) : (
                <p className="text-sm text-muted-foreground">No preview available.</p>
              )}
            </div>
          </DialogContent>
        </Dialog>
      </div>
    </TooltipProvider>
  )
}
