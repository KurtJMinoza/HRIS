import { useCallback, useEffect, useMemo, useRef, useState } from 'react'
import { bulkPayslipDownloadStatusLabel, saveBulkPayslipZipBlob } from '../lib/bulkPayslipDownload'
import { useNavigate, useSearchParams } from 'react-router-dom'
import {
  adminGenerateExecomPayroll,
  adminGenerateConsultantPayroll,
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
  previewPayCycle,
  getPayrollRunCompanyPayrollReportPdfBlob,
  getPayrollRunCompanyPayrollReportXlsxBlob,
  getPayrollRunCompanyPayrollDeductionsPdfBlob,
  getPayrollRunCompanyPayrollDeductionsXlsxBlob,
  getBankPayrollExportXlsxBlobByCutoff,
  getBankPayrollExportCsvBlobByCutoff,
  getBankPayrollExportPdfBlobByCutoff,
  getBankPayrollExportCutoffs,
  getExecomPayrollReportPdfBlob,
  getExecomPayrollDeductionsPdfBlob,
  getConsultantPayrollReportPdfBlob,
  getConsultantPayrollDeductionsPdfBlob,
  getConsultantPayrollReportXlsxBlob,
  getConsultantPayrollDeductionsXlsxBlob,
  companyLogoUrl,
} from '@/api'
import { useHrBasePath } from '@/contexts/useHrBasePath'
import { useAuth } from '@/contexts/AuthContext'
import { Accordion, AccordionContent, AccordionItem, AccordionTrigger } from '@/components/ui/accordion'
import { Badge } from '@/components/ui/badge'
import { Button } from '@/components/ui/button'
import { Card, CardContent, CardDescription, CardHeader, CardTitle } from '@/components/ui/card'
import {
  DropdownMenu,
  DropdownMenuContent,
  DropdownMenuItem,
  DropdownMenuTrigger,
} from '@/components/ui/dropdown-menu'
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
  ChevronDown,
  ChevronLeft,
  ChevronRight,
  Clock3,
  Eye,
  FileDown,
  FileSpreadsheet,
  FileText,
  Layers,
  Landmark,
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
  'rounded-2xl border border-border/80 bg-card text-card-foreground shadow-sm shadow-slate-900/3 transition-shadow duration-200 hover:shadow-md dark:shadow-black/25 dark:hover:shadow-lg'
const SELECT_TRIGGER =
  'h-11 rounded-xl border-border/80 bg-background/95 px-3.5 text-sm font-semibold text-foreground shadow-sm shadow-slate-900/5 transition-all duration-200 hover:border-brand/45 hover:bg-card focus-visible:border-brand/60 focus-visible:ring-brand/20 data-[placeholder]:text-muted-foreground dark:bg-input/45 dark:hover:bg-input/60 [&_[data-select-option-avatar]]:h-7 [&_[data-select-option-avatar]]:w-7 [&_[data-select-option-avatar]]:rounded-lg [&_[data-select-option-subtitle]]:hidden [&_[data-select-option-title]]:truncate'
const SELECT_CONTENT =
  'max-h-80 rounded-2xl border-border/80 bg-popover/95 p-1.5 shadow-2xl shadow-slate-900/15 backdrop-blur-xl dark:shadow-black/40'
const RECENT_HEADER_ACTION_BTN =
  'shrink-0 rounded-lg border border-border/80 bg-background font-medium text-foreground shadow-sm hover:border-border hover:bg-muted/40 dark:border-input dark:bg-input/35 dark:hover:bg-input/50'
const SELECT_ITEM =
  'min-h-11 rounded-xl px-3 py-2.5 pr-9 text-sm font-medium transition-colors focus:bg-brand/10 focus:text-brand data-[state=checked]:bg-brand/10 data-[state=checked]:text-brand'
const DEMO_ORG_NAME_PATTERN = /^(company\s+[ab]|acme\s+(corp|group))$/i

function payrollModuleTabClass(active) {
  return cn(
    'rounded-md border shadow-none box-border',
    active
      ? 'border-brand bg-brand text-brand-foreground hover:border-brand hover:bg-brand-strong hover:text-brand-foreground'
      : 'border-border bg-background text-foreground hover:border-border hover:bg-muted/50 hover:text-foreground dark:border-input dark:bg-input/30 dark:hover:bg-input/50',
  )
}

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

function payCutoffKey(fromDate, toDate) {
  return `${String(fromDate || '').slice(0, 10)}|${String(toDate || '').slice(0, 10)}`
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

function formatDate(value) {
  if (!value) return '—'
  try {
    return new Intl.DateTimeFormat(undefined, { month: 'short', day: 'numeric', year: 'numeric' })
      .format(new Date(`${value}T12:00:00`))
  } catch {
    return value
  }
}

function payCyclePeriodKey(period) {
  return `${period.cut_off_start_date}|${period.cut_off_end_date}|${period.pay_date || ''}`
}

function formatPeso(n) {
  const v = Number(n)
  if (!Number.isFinite(v)) return '\u20B10.00'
  const sign = v < 0 ? '-' : ''
  return `${sign}\u20B1${Math.abs(v).toLocaleString(undefined, { minimumFractionDigits: 2, maximumFractionDigits: 2 })}`
}

function formatCompactPeso(n) {
  const v = Number(n)
  if (!Number.isFinite(v) || v === 0) return '\u20B10'
  const sign = v < 0 ? '-' : ''
  const abs = Math.abs(v)
  if (abs >= 1_000_000) return `${sign}\u20B1${(abs / 1_000_000).toFixed(1)}M`
  if (abs >= 1_000) return `${sign}\u20B1${(abs / 1_000).toFixed(0)}K`
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

function savePdfBlob(blob, filename) {
  const url = URL.createObjectURL(blob)
  const a = document.createElement('a')
  a.href = url
  a.download = filename
  document.body.appendChild(a)
  a.click()
  document.body.removeChild(a)
  URL.revokeObjectURL(url)
}

function resolveLogoUrl(logoUrl) {
  return companyLogoUrl(logoUrl) || null
}

function firstFilled(...values) {
  return values.find((value) => String(value ?? '').trim()) || null
}

function PayrollSelectItem({ value, icon: Icon, title, subtitle, logoUrl, className }) {
  return (
    <SelectItem value={value} className={cn(SELECT_ITEM, className)} textValue={title}>
      <span className="flex min-w-0 items-center gap-3">
        <span
          data-select-option-avatar
          className="flex h-8 w-8 shrink-0 items-center justify-center overflow-hidden rounded-xl border border-border/80 bg-background text-muted-foreground shadow-sm dark:bg-input/45"
          aria-hidden
        >
          {logoUrl ? (
            <img src={logoUrl} alt="" className="h-full w-full object-contain p-0.5" />
          ) : Icon ? (
            <Icon className="h-4 w-4" />
          ) : null}
        </span>
        <span className="min-w-0">
          <span data-select-option-title className="block truncate font-semibold leading-5 text-current">
            {title}
          </span>
          {subtitle ? (
            <span data-select-option-subtitle className="block truncate text-[11px] font-normal leading-4 text-muted-foreground">
              {subtitle}
            </span>
          ) : null}
        </span>
      </span>
    </SelectItem>
  )
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

function payrollRowModuleKind(row) {
  const module = String(row?.payroll_module || '').toLowerCase()
  const label = String(row?.module_label || '').toLowerCase()
  if (module === 'execom' || label.includes('execom')) return 'execom'
  if (module === 'consultant' || label.includes('consultant')) return 'consultant'
  return 'regular'
}

export default function AdminGeneratePayslipsPage() {
  const { user } = useAuth()
  const { toast } = useToast()
  const navigate = useNavigate()
  const [searchParams, setSearchParams] = useSearchParams()
  const hrBase = useHrBasePath()
  const isAdmin = user?.role === 'admin' || user?.role === 'super_admin'
  const permissionSet = useMemo(() => new Set(user?.permissions ?? []), [user?.permissions])
  const isExecomModule = searchParams.get('module') === 'execom'
  const isConsultantModule = searchParams.get('module') === 'consultant'
  const isDedicatedPayrollModule = isExecomModule || isConsultantModule
  const canManageRegularPayslips = permissionSet.has('payslip.generate')
  const canManageExecomPayroll = permissionSet.has('execom.payroll.generate')
  const canManageConsultantPayroll =
    permissionSet.has('consultant.payroll.generate') || permissionSet.has('payslip.generate')
  const canManagePayslips = isExecomModule
    ? canManageExecomPayroll
    : isConsultantModule
      ? canManageConsultantPayroll
      : canManageRegularPayslips
  const canBulkDownloadPayslipZip = permissionSet.has('payslip.download')
  const canViewExecom = permissionSet.has('execom.view') || canManageExecomPayroll
  const canViewConsultant = permissionSet.has('consultant.view') || canManageConsultantPayroll

  const [companies, setCompanies] = useState([])
  const [branches, setBranches] = useState([])
  const [departments, setDepartments] = useState([])
  const [cycles, setCycles] = useState([])

  const [companyId, setCompanyId] = useState('')
  const [branchId, setBranchId] = useState('')
  const [departmentId, setDepartmentId] = useState('')
  const [payCycleId, setPayCycleId] = useState('')
  const [cyclePeriods, setCyclePeriods] = useState([])
  const [cyclePeriodsLoading, setCyclePeriodsLoading] = useState(false)
  const [selectedPeriodKey, setSelectedPeriodKey] = useState('')

  const [fromDate, setFromDate] = useState('')
  const [toDate, setToDate] = useState('')
  const [referenceDate, setReferenceDate] = useState('')
  const [useCompanyDefaultDates, setUseCompanyDefaultDates] = useState(true)
  const [companyDefaultMeta, setCompanyDefaultMeta] = useState({ weekend_adjusted: false, weekend_adjustment_note: null, cycle_label: null })
  const [passwordProtect, setPasswordProtect] = useState(false)
  const [includeThirteenthMonth, setIncludeThirteenthMonth] = useState(false)
  const [employeeId, setEmployeeId] = useState('')

  const [preview, setPreview] = useState(null)
  const [previewLoading, setPreviewLoading] = useState(false)

  const [generating, setGenerating] = useState(false)

  const [listLoading, setListLoading] = useState(false)
  const [bulkDownloadingBatchId, setBulkDownloadingBatchId] = useState(null)
  const [payrollReportDownloadingBatchId, setPayrollReportDownloadingBatchId] = useState(null)
  const [payrollDeductionsDownloadingBatchId, setPayrollDeductionsDownloadingBatchId] = useState(null)
  const [payrollReportExcelDownloadingBatchId, setPayrollReportExcelDownloadingBatchId] = useState(null)
  const [payrollDeductionsExcelDownloadingBatchId, setPayrollDeductionsExcelDownloadingBatchId] = useState(null)
  const [bankPayrollExportDownloadingFormat, setBankPayrollExportDownloadingFormat] = useState(null)
  const [bankExportDialogOpen, setBankExportDialogOpen] = useState(false)
  const [bankExportCutoffOptions, setBankExportCutoffOptions] = useState([])
  const [bankExportDialogCutoffKey, setBankExportDialogCutoffKey] = useState('')
  const [bankExportCutoffsLoading, setBankExportCutoffsLoading] = useState(false)
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
  const [recentListMeta, setRecentListMeta] = useState({ current_page: 1, last_page: 1, total: 0, per_page: 15 })
  const [recentListPage, setRecentListPage] = useState(1)
  const [recentModuleFilter, setRecentModuleFilter] = useState('all')
  const [batchEstimateData, setBatchEstimateData] = useState(null)
  const [batchEstimateLoading, setBatchEstimateLoading] = useState(false)

  const RECENT_LIST_PER_PAGE = 15

  const bankExportCutoff = useMemo(() => {
    if (!bankExportDialogCutoffKey) return null
    return bankExportCutoffOptions.find((option) => option.key === bankExportDialogCutoffKey) || null
  }, [bankExportDialogCutoffKey, bankExportCutoffOptions])

  const loadBankExportCutoffs = useCallback(async () => {
    if (isDedicatedPayrollModule || !canBulkDownloadPayslipZip) return
    setBankExportCutoffsLoading(true)
    try {
      const data = await getBankPayrollExportCutoffs()
      const options = Array.isArray(data?.cutoffs) ? data.cutoffs : []
      setBankExportCutoffOptions(options)
      setBankExportDialogCutoffKey((prev) => {
        if (prev && options.some((option) => option.key === prev)) return prev
        const formKey = payCutoffKey(fromDate, toDate)
        if (formKey !== '|' && options.some((option) => option.key === formKey)) return formKey
        return options[0]?.key || ''
      })
    } catch (e) {
      setBankExportCutoffOptions([])
      setBankExportDialogCutoffKey('')
      toast({
        title: 'Bank payroll cutoffs',
        description: e.message || 'Failed to load finalized pay cutoffs.',
        variant: 'destructive',
      })
    } finally {
      setBankExportCutoffsLoading(false)
    }
  }, [canBulkDownloadPayslipZip, fromDate, isDedicatedPayrollModule, toDate, toast])

  const handleOpenBankExportDialog = () => {
    setBankExportDialogOpen(true)
    void loadBankExportCutoffs()
  }

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
  const execomReady = Boolean(fromDate && toDate)
  const finalizeReady = isDedicatedPayrollModule
    ? execomReady
    : (!isAdmin || scopeReady || Boolean(String(employeeId || '').trim()))

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
      include_thirteenth_month: includeThirteenthMonth,
      include_13th_month_pay: includeThirteenthMonth,
      company_id: companyId ? Number(companyId) : null,
      branch_id: branchId ? Number(branchId) : null,
      department_id: departmentId ? Number(departmentId) : null,
      employee_id: String(employeeId || '').trim() ? Number(employeeId) : null,
    }),
    [fromDate, toDate, payCycleId, referenceDate, useCompanyDefaultDates, passwordProtect, includeThirteenthMonth, companyId, branchId, departmentId, employeeId],
  )

  const execomBulkPayload = useMemo(
    () => ({
      from_date: fromDate || null,
      to_date: toDate || null,
      pay_cycle_id: payCycleId ? Number(payCycleId) : null,
      reference_date: referenceDate || null,
      password_protect: passwordProtect,
      include_thirteenth_month: includeThirteenthMonth,
      include_13th_month_pay: includeThirteenthMonth,
      company_id: companyId ? Number(companyId) : null,
      branch_id: branchId ? Number(branchId) : null,
      department_id: departmentId ? Number(departmentId) : null,
      employee_id: String(employeeId || '').trim() ? Number(employeeId) : null,
    }),
    [fromDate, toDate, payCycleId, referenceDate, passwordProtect, includeThirteenthMonth, companyId, branchId, departmentId, employeeId],
  )

  const consultantBulkPayload = execomBulkPayload

  const setPayrollModule = useCallback(
    (module) => {
      const next = new URLSearchParams(searchParams)
      if (module === 'execom' || module === 'consultant') next.set('module', module)
      else next.delete('module')
      setSearchParams(next, { replace: true })
    },
    [searchParams, setSearchParams],
  )

  useEffect(() => {
    if (!canManageRegularPayslips && canManageExecomPayroll && !isDedicatedPayrollModule) {
      setPayrollModule('execom')
    } else if (!canManageRegularPayslips && !canManageExecomPayroll && canManageConsultantPayroll && !isDedicatedPayrollModule) {
      setPayrollModule('consultant')
    }
  }, [canManageRegularPayslips, canManageExecomPayroll, canManageConsultantPayroll, isDedicatedPayrollModule, setPayrollModule])

  useEffect(() => {
    if (!isDedicatedPayrollModule) return
    setCompanyId('')
    setBranchId('')
    setDepartmentId('')
  }, [isDedicatedPayrollModule])

  useEffect(() => {
    if (isDedicatedPayrollModule || !canManagePayslips || (isAdmin && !scopeReady)) {
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
          from_date: fromDate || undefined,
          to_date: toDate || undefined,
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
  }, [canManagePayslips, isAdmin, companyId, branchId, departmentId, fromDate, toDate, scopeReady, isDedicatedPayrollModule])

  useEffect(() => {
    if (isDedicatedPayrollModule || !canManagePayslips || !scopeReady) {
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
  }, [canManagePayslips, scopeReady, bulkPayload, isDedicatedPayrollModule])

  const loadCompanySummary = useCallback(async () => {
    setListLoading(true)
    try {
      const res = await getAdminPayslipsRecentByCompany({
        company_id: companyId || undefined,
        branch_id: branchId || undefined,
        department_id: departmentId || undefined,
        payroll_module: recentModuleFilter,
        per_page: RECENT_LIST_PER_PAGE,
        page: recentListPage,
      })
      setCompanyRows(Array.isArray(res?.data) ? res.data : [])
      const lastPage = Math.max(1, Number(res?.last_page || 1))
      const currentPage = Math.max(1, Number(res?.current_page || recentListPage || 1))
      setRecentListMeta({
        current_page: currentPage,
        last_page: lastPage,
        total: Number(res?.total || 0),
        per_page: Number(res?.per_page || RECENT_LIST_PER_PAGE),
      })
      if (currentPage !== recentListPage && currentPage <= lastPage) {
        setRecentListPage(currentPage)
      } else if (recentListPage > lastPage) {
        setRecentListPage(lastPage)
      }
    } catch (e) {
      toast({ title: 'Payslips', description: e.message || 'Failed to load summary', variant: 'destructive' })
      setCompanyRows([])
      setRecentListMeta({ current_page: 1, last_page: 1, total: 0, per_page: RECENT_LIST_PER_PAGE })
    } finally {
      setListLoading(false)
    }
  }, [companyId, branchId, departmentId, recentModuleFilter, recentListPage, toast])

  useEffect(() => {
    setRecentListPage(1)
  }, [companyId, branchId, departmentId, recentModuleFilter])

  useEffect(() => {
    if (canManagePayslips) loadCompanySummary()
  }, [canManagePayslips, loadCompanySummary])

  useEffect(() => {
    const onFinalized = () => {
      loadCompanySummary()
      if (bankExportDialogOpen) void loadBankExportCutoffs()
    }
    const onStorage = (e) => {
      if (e.key === 'hr:payroll-finalized-at') {
        loadCompanySummary()
        if (bankExportDialogOpen) void loadBankExportCutoffs()
      }
    }
    if (typeof window !== 'undefined') {
      window.addEventListener('hr:payroll-finalized', onFinalized)
      window.addEventListener('hr:attendance-payroll-changed', onFinalized)
      window.addEventListener('storage', onStorage)
      window.addEventListener('focus', onFinalized)
    }
    return () => {
      if (typeof window !== 'undefined') {
        window.removeEventListener('hr:payroll-finalized', onFinalized)
        window.removeEventListener('hr:attendance-payroll-changed', onFinalized)
        window.removeEventListener('storage', onStorage)
        window.removeEventListener('focus', onFinalized)
      }
    }
  }, [loadCompanySummary, loadBankExportCutoffs, bankExportDialogOpen])

  const selectedCompany = useMemo(
    () => companies.find((c) => String(c.id) === String(companyId)),
    [companies, companyId],
  )
  const companyLogoById = useMemo(() => {
    const map = {}
    companies.forEach((company) => {
      if (company?.id != null) map[company.id] = resolveLogoUrl(company)
    })
    return map
  }, [companies])
  const selectedCompanyLogo = resolveLogoUrl(selectedCompany)

  const activeEmployees = Number(preview?.total_employees ?? 0)
  const payrollScopeTotalEmployees = Number(preview?.payroll_scope_total_employees ?? activeEmployees)
  const execomExcludedEmployees = Number(preview?.execom_excluded_employees ?? 0)
  const consultantExcludedEmployees = Number(preview?.consultant_excluded_employees ?? 0)
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

  const selectedCycle = useMemo(
    () => cycles.find((c) => String(c.id) === String(payCycleId)) || null,
    [cycles, payCycleId],
  )

  const applyPayCyclePeriod = useCallback((period) => {
    if (!period) return
    const start = period.cut_off_start_date || ''
    const end = period.cut_off_end_date || ''
    setSelectedPeriodKey(payCyclePeriodKey(period))
    setFromDate(start)
    setToDate(end)
    setReferenceDate(period.pay_date || '')
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
      setCyclePeriodsLoading(true)
      try {
        const res = await previewPayCycle(cycleSnapshot)
        if (cancelled) return
        const periods = Array.isArray(res?.data?.preview_periods) ? res.data.preview_periods : []
        setCyclePeriods(periods)
      } catch (e) {
        if (!cancelled) {
          setCyclePeriods([])
          toast({ title: 'Failed to load pay periods', description: e.message, variant: 'destructive' })
        }
      } finally {
        if (!cancelled) setCyclePeriodsLoading(false)
      }
    })()
    return () => { cancelled = true }
  }, [payCycleId, selectedCycle?.code, selectedCycle?.cut_off_type, selectedCycle?.pay_day_type, selectedCycle?.pay_day_offset, toast]) // eslint-disable-line react-hooks/exhaustive-deps

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
        title: isDedicatedPayrollModule ? 'Select pay dates' : 'Select scope or employee',
        description: isExecomModule
          ? 'Choose a pay period before generating EXECOM payroll.'
          : isConsultantModule
            ? 'Choose a pay period before generating Consultant payroll.'
            : 'Choose company, branch, or department — or enter a single employee user ID.',
        variant: 'destructive',
      })
      return
    }
    setGenerating(true)
    try {
      const res = isExecomModule
        ? await adminGenerateExecomPayroll(execomBulkPayload)
        : isConsultantModule
          ? await adminGenerateConsultantPayroll(consultantBulkPayload)
          : await adminGeneratePayslips(bulkPayload)
      toast({
        title: res?.queued === false
          ? 'Payslips generated'
          : isExecomModule
            ? 'EXECOM payroll draft queued'
            : isConsultantModule
              ? 'Consultant payroll draft queued'
              : 'Payroll draft queued',
        description:
          res?.queued === false
            ? `${Number(res?.generated_count ?? res?.employee_count ?? 0)} draft payslip${Number(res?.generated_count ?? res?.employee_count ?? 0) === 1 ? '' : 's'} are ready.`
            : isExecomModule
              ? 'The EXECOM review page will open while Redis computes fixed Basic Pay rows in the background.'
              : isConsultantModule
                ? 'The Consultant review page will open while payroll rows are computed in the background.'
                : 'Finalize Payroll will open now while Redis computes employee rows in the background.',
      })
      loadCompanySummary()
      const q = new URLSearchParams(buildFinalizeQuery().toString())
      if (res?.pay_period_start) q.set('from_date', String(res.pay_period_start))
      if (res?.pay_period_end) q.set('to_date', String(res.pay_period_end))
      if (res?.payroll_batch_run_id) q.set('batch_run_id', String(res.payroll_batch_run_id))
      navigate(
        isExecomModule
          ? `${hrBase}/execom/payroll/finalize?${q.toString()}`
          : isConsultantModule
            ? `${hrBase}/consultant/payroll/finalize?${q.toString()}`
            : `${hrBase}/compensation/finalize-payroll?${q.toString()}`,
      )
    } catch (e) {
      toast({ title: 'Generate failed', description: e.message || 'Failed to queue payslip generation', variant: 'destructive' })
    } finally {
      setGenerating(false)
    }
  }, [canManagePayslips, finalizeReady, toast, bulkPayload, execomBulkPayload, consultantBulkPayload, isExecomModule, isConsultantModule, isDedicatedPayrollModule, loadCompanySummary, buildFinalizeQuery, navigate, hrBase])

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
    if (row?.payroll_batch_run_id != null) q.set('batch_run_id', String(row.payroll_batch_run_id))
    const rowModule = payrollRowModuleKind(row)
    navigate(
      rowModule === 'execom'
        ? `${hrBase}/execom/payroll/finalize?${q.toString()}`
        : rowModule === 'consultant'
          ? `${hrBase}/consultant/payroll/finalize?${q.toString()}`
          : `${hrBase}/compensation/finalize-payroll?${q.toString()}`,
    )
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
      const bulkReady = Boolean(queued?.bulk_download?.ready)
      toast({
        title: queued?.message || (bulkReady ? 'Bulk payslip download is ready.' : 'Bulk payslip download is being prepared.'),
        description: bulkReady
          ? 'Your ZIP download will start shortly.'
          : 'PDFs are generated in the background. You can keep using this page.',
      })
      const requestId = Number(queued?.request_id ?? queued?.bulk_download?.id ?? 0)
      if (!requestId) {
        throw new Error('Server did not return a bulk download request id.')
      }
      const { blob, bulk_download: doneBulk } = await adminPollAndDownloadBulkPayslipZip(requestId, {
        signal: abort.signal,
        initialBulk: queued?.bulk_download ?? null,
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

  const handleDownloadPayrollReportPdf = async (row, deductionsOnly = false) => {
    const id = row?.payroll_batch_run_id
    const rowModule = payrollRowModuleKind(row)
    const rowCompanyId = Number(row?.company_id || 0)
    const downloadingBatchId = deductionsOnly ? payrollDeductionsDownloadingBatchId : payrollReportDownloadingBatchId
    if (id == null || downloadingBatchId != null) return
    if (rowModule === 'regular' && rowCompanyId <= 0) return
    if (String(row?.batch_run_status || '').toLowerCase() !== 'finalized') return
    if (!canBulkDownloadPayslipZip) return

    if (deductionsOnly) setPayrollDeductionsDownloadingBatchId(id)
    else setPayrollReportDownloadingBatchId(id)
    try {
      const blob = rowModule === 'execom'
        ? await (deductionsOnly ? getExecomPayrollDeductionsPdfBlob(id) : getExecomPayrollReportPdfBlob(id))
        : rowModule === 'consultant'
          ? await (deductionsOnly ? getConsultantPayrollDeductionsPdfBlob(id) : getConsultantPayrollReportPdfBlob(id))
          : await (deductionsOnly
              ? getPayrollRunCompanyPayrollDeductionsPdfBlob(id, rowCompanyId)
              : getPayrollRunCompanyPayrollReportPdfBlob(id, rowCompanyId))
      const companyName = (rowModule === 'regular' ? String(row?.company_name || 'company') : rowModule === 'consultant' ? 'Consultant' : 'Execom').replace(
        /[^\w-]+/g,
        '_',
      )
      const prefix = deductionsOnly ? 'Payroll_Deductions_Report' : 'Payroll_Report'
      savePdfBlob(blob, `${prefix}_${companyName}_Run_${id}.pdf`)
      toast({
        title: deductionsOnly ? 'Payroll Deductions PDF downloaded' : 'Payroll Report PDF downloaded',
        description: 'Your report download has started.',
      })
    } catch (e) {
      toast({
        title: deductionsOnly ? 'Payroll Deductions PDF failed' : 'Payroll Report PDF failed',
        description: e.message || `Could not download ${deductionsOnly ? 'Payroll Deductions' : 'Payroll Report'} PDF.`,
        variant: 'destructive',
      })
    } finally {
      if (deductionsOnly) setPayrollDeductionsDownloadingBatchId(null)
      else setPayrollReportDownloadingBatchId(null)
    }
  }

  const handleDownloadPayrollReportExcel = async (row) => {
    const id = row?.payroll_batch_run_id
    const rowModule = payrollRowModuleKind(row)
    const rowCompanyId = Number(row?.company_id || 0)
    if (id == null || payrollReportExcelDownloadingBatchId != null) return
    if (rowModule === 'regular' && rowCompanyId <= 0) return
    if (String(row?.batch_run_status || '').toLowerCase() !== 'finalized') return
    if (!canBulkDownloadPayslipZip) return

    setPayrollReportExcelDownloadingBatchId(id)
    try {
      const blob = rowModule === 'consultant'
        ? await getConsultantPayrollReportXlsxBlob(id)
        : await getPayrollRunCompanyPayrollReportXlsxBlob(id, rowCompanyId)
      const companyName = (rowModule === 'regular' ? String(row?.company_name || 'company') : rowModule === 'consultant' ? 'Consultant' : 'Execom').replace(/[^\w-]+/g, '_')
      savePdfBlob(blob, `Payroll_Report_${companyName}_Run_${id}.xlsx`)
      toast({
        title: 'Payroll Report Excel downloaded',
        description: 'Your report spreadsheet download has started.',
      })
    } catch (e) {
      toast({
        title: 'Payroll Report Excel failed',
        description: e.message || 'Could not download Payroll Report Excel.',
        variant: 'destructive',
      })
    } finally {
      setPayrollReportExcelDownloadingBatchId(null)
    }
  }

  const handleDownloadPayrollDeductionsExcel = async (row) => {
    const id = row?.payroll_batch_run_id
    const rowModule = payrollRowModuleKind(row)
    const rowCompanyId = Number(row?.company_id || 0)
    if (id == null || payrollDeductionsExcelDownloadingBatchId != null) return
    if (rowModule === 'regular' && rowCompanyId <= 0) return
    if (String(row?.batch_run_status || '').toLowerCase() !== 'finalized') return
    if (!canBulkDownloadPayslipZip) return

    setPayrollDeductionsExcelDownloadingBatchId(id)
    try {
      const blob = rowModule === 'consultant'
        ? await getConsultantPayrollDeductionsXlsxBlob(id)
        : await getPayrollRunCompanyPayrollDeductionsXlsxBlob(id, rowCompanyId)
      const companyName = (rowModule === 'regular' ? String(row?.company_name || 'company') : rowModule === 'consultant' ? 'Consultant' : 'Execom').replace(/[^\w-]+/g, '_')
      savePdfBlob(blob, `Payroll_Deductions_Report_${companyName}_Run_${id}.xlsx`)
      toast({
        title: 'Payroll Deductions Excel downloaded',
        description: 'Your deductions spreadsheet download has started.',
      })
    } catch (e) {
      toast({
        title: 'Payroll Deductions Excel failed',
        description: e.message || 'Could not download Payroll Deductions Excel.',
        variant: 'destructive',
      })
    } finally {
      setPayrollDeductionsExcelDownloadingBatchId(null)
    }
  }

  const handleDownloadBankPayrollExport = async (format, bankCode = 'AUB') => {
    if (!bankExportCutoff || bankPayrollExportDownloadingFormat != null) return
    if (!canBulkDownloadPayslipZip || isDedicatedPayrollModule) return

    setBankPayrollExportDownloadingFormat(format)
    try {
      const { from_date: cutoffFrom, to_date: cutoffTo } = bankExportCutoff
      const blob = format === 'xlsx'
        ? await getBankPayrollExportXlsxBlobByCutoff(cutoffFrom, cutoffTo, bankCode)
        : format === 'csv'
          ? await getBankPayrollExportCsvBlobByCutoff(cutoffFrom, cutoffTo, bankCode)
          : await getBankPayrollExportPdfBlobByCutoff(cutoffFrom, cutoffTo, bankCode)
      const start = cutoffFrom.replace(/-/g, '')
      const end = cutoffTo.replace(/-/g, '')
      const ext = format === 'xlsx' ? 'xlsx' : format === 'csv' ? 'csv' : 'pdf'
      savePdfBlob(blob, `Bank_Payroll_Export_${bankCode}_All_Companies_${start}_${end}.${ext}`)
      toast({
        title: 'Bank Payroll Export downloaded',
        description: `${bankCode} ${format.toUpperCase()} for ${formatPayPeriodRange(cutoffFrom, cutoffTo)} — all finalized companies, sorted alphabetically.`,
      })
      setBankExportDialogOpen(false)
    } catch (e) {
      toast({
        title: 'Bank Payroll Export failed',
        description: e.message || 'Could not download Bank Payroll Export.',
        variant: 'destructive',
      })
    } finally {
      setBankPayrollExportDownloadingFormat(null)
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

  if (!canManagePayslips && !(canManageRegularPayslips || canViewExecom || canViewConsultant)) {
    return (
      <TooltipProvider>
        <div className={cn(PAYSLIP_MODULE_SHELL, PAYSLIP_STACK)}>
          <Card className={cn('mx-auto max-w-lg', CARD_SHELL)}>
            <CardHeader>
              <CardTitle className="text-foreground">Bulk payslip generation</CardTitle>
              <CardDescription>
                You do not have permission to generate payslips, EXECOM payroll, or Consultant payroll.
              </CardDescription>
            </CardHeader>
          </Card>
        </div>
      </TooltipProvider>
    )
  }

  if (!canManagePayslips) {
    return (
      <TooltipProvider>
        <div className={cn(PAYSLIP_MODULE_SHELL, PAYSLIP_STACK)}>
          <Card className={cn('mx-auto max-w-lg', CARD_SHELL)}>
            <CardHeader>
              <CardTitle className="text-foreground">
                {isExecomModule ? 'EXECOM payroll generation' : isConsultantModule ? 'Consultant payroll generation' : 'Bulk payslip generation'}
              </CardTitle>
              <CardDescription>
                {isExecomModule
                  ? 'You do not have permission to generate EXECOM payroll drafts.'
                  : isConsultantModule
                    ? 'You do not have permission to generate Consultant payroll drafts.'
                    : 'You do not have permission to generate regular payslips.'}
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
        <div className="overflow-hidden rounded-2xl border border-border/80 bg-card shadow-sm shadow-slate-900/3 dark:shadow-black/25">
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
                {isExecomModule ? 'EXECOM Payroll Generation' : isConsultantModule ? 'Consultant Payroll Generation' : 'Bulk Payslip Generation'}
              </h1>
              <p className="max-w-2xl text-[15px] font-medium leading-7 text-muted-foreground">
                {isExecomModule
                  ? 'Generate EXECOM payroll drafts using fixed Basic Pay. Allowances, deductions, schedules, and pay cycles follow the same rules as regular payroll.'
                  : isConsultantModule
                    ? 'Generate Consultant payroll drafts for employees with consultant employment type only. Uses the same consultant fixed-pay and policy rules as the regular engine.'
                    : 'Generate official PDF payslips for active employees in the selected scope using the same payroll engine as your previews — pay components, statutory deductions, loans, pay cycles, and daily computation.'}
              </p>
              {(canManageRegularPayslips || canManageExecomPayroll || canManageConsultantPayroll) && (
                <div className="flex flex-wrap gap-2 pt-1">
                  {canManageRegularPayslips && (
                    <Button
                      type="button"
                      size="sm"
                      variant="outline"
                      className={payrollModuleTabClass(!isDedicatedPayrollModule)}
                      onClick={() => setPayrollModule('regular')}
                    >
                      Regular Payroll
                    </Button>
                  )}
                  {canManageExecomPayroll && (
                    <Button
                      type="button"
                      size="sm"
                      variant="outline"
                      className={payrollModuleTabClass(isExecomModule)}
                      onClick={() => setPayrollModule('execom')}
                    >
                      EXECOM Payroll
                    </Button>
                  )}
                  {canManageConsultantPayroll && (
                    <Button
                      type="button"
                      size="sm"
                      variant="outline"
                      className={payrollModuleTabClass(isConsultantModule)}
                      onClick={() => setPayrollModule('consultant')}
                    >
                      Consultant Payroll
                    </Button>
                  )}
                </div>
              )}
            </div>
            <div className="pointer-events-none relative hidden min-h-[150px] items-center justify-center md:flex" aria-hidden>
              <div className="absolute inset-y-8 right-0 w-full bg-[radial-gradient(circle,#fb923c_1.4px,transparent_1.4px)] bg-size-[18px_18px] opacity-70 dark:opacity-25" />
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

        {/* ── Generation Layout ── */}
        <div className={cn(
          'grid grid-cols-1 gap-6 lg:items-start',
          'lg:grid-cols-1',
        )}>
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
                      {isExecomModule
                        ? 'Choose the pay period and PDF options for all active EXECOM profiles.'
                        : isConsultantModule
                          ? 'Choose the pay period and PDF options for all active consultant employees.'
                          : 'Narrow the batch by company, branch, and department. Choose a pay cycle or use company defaults.'}
                    </CardDescription>
                  </div>
                </div>
              </CardHeader>
              <CardContent className="space-y-8 pt-6">
                {!isDedicatedPayrollModule && (
                  <>
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
                            <SelectContent position="popper" align="start" className={SELECT_CONTENT}>
                              <PayrollSelectItem
                                value="__none__"
                                icon={Building2}
                                title="Select company..."
                                subtitle="Choose a company entity"
                              />
                              {companies.map((c) => (
                                <PayrollSelectItem
                                  key={c.id}
                                  value={String(c.id)}
                                  icon={Building2}
                                  logoUrl={resolveLogoUrl(c)}
                                  title={c.name}
                                  subtitle={firstFilled(c.code, c.company_code, c.short_name, 'Company entity')}
                                />
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
                          <SelectContent position="popper" align="start" className={SELECT_CONTENT}>
                            <PayrollSelectItem
                              value="__none__"
                              icon={MapPin}
                              title="All branches"
                              subtitle="Use the whole company scope"
                            />
                            {branches.map((b) => (
                              <PayrollSelectItem
                                key={b.id}
                                value={String(b.id)}
                                icon={MapPin}
                                title={b.name}
                                subtitle={firstFilled(b.code, b.branch_code, b.address, 'Branch location')}
                              />
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
                          <SelectContent position="popper" align="start" className={SELECT_CONTENT}>
                            <PayrollSelectItem
                              value="__none__"
                              icon={Users}
                              title="All departments"
                              subtitle="Use the whole branch scope"
                            />
                            {departments.map((d) => (
                              <PayrollSelectItem
                                key={d.id}
                                value={String(d.id)}
                                icon={Users}
                                title={d.name}
                                subtitle={firstFilled(d.code, d.department_code, d.branch_name, 'Department')}
                              />
                            ))}
                          </SelectContent>
                        </Select>
                      </div>
                    </div>
                  </>
                )}

                {/* Pay Cycle — full width */}
                <div className="space-y-2">
                  <Label className="flex items-center gap-2 text-sm font-normal text-muted-foreground">
                    <CalendarClock className="h-4 w-4 shrink-0 text-muted-foreground/80" aria-hidden />
                    Pay Period (Pay Cycle)
                  </Label>
                  <Select
                    value={payCycleId || '__none__'}
                    onValueChange={(v) => {
                      const next = v === '__none__' ? '' : v
                      setPayCycleId(next)
                      setSelectedPeriodKey('')
                      if (next) {
                        setFromDate('')
                        setToDate('')
                        setReferenceDate('')
                      }
                    }}
                  >
                    <SelectTrigger className={SELECT_TRIGGER}>
                      <SelectValue placeholder="Default (employee / company cycle)" />
                    </SelectTrigger>
                    <SelectContent position="popper" align="start" className={SELECT_CONTENT}>
                      <PayrollSelectItem
                        value="__none__"
                        icon={CalendarClock}
                        title="Use employee / company default"
                        subtitle="Applies the configured default pay cycle"
                      />
                      {cycles.map((c) => (
                        <SelectItem key={c.id} value={String(c.id)} className={SELECT_ITEM}>
                          {c.name} · {c.code}
                        </SelectItem>
                      ))}
                    </SelectContent>
                  </Select>
                </div>

                {payCycleId ? (
                  <div className="space-y-2">
                    <Label className="flex items-center gap-2 text-sm font-normal text-muted-foreground">
                      <CalendarClock className="h-4 w-4 shrink-0 text-muted-foreground/80" aria-hidden />
                      Payroll cycle
                    </Label>
                    <Select
                      value={selectedPeriodKey || '__none__'}
                      disabled={cyclePeriodsLoading}
                      onValueChange={(v) => {
                        if (v === '__none__') {
                          setSelectedPeriodKey('')
                          setFromDate('')
                          setToDate('')
                          setReferenceDate('')
                          return
                        }
                        const period = cyclePeriods.find((p) => payCyclePeriodKey(p) === v)
                        if (period) applyPayCyclePeriod(period)
                      }}
                    >
                      <SelectTrigger className={SELECT_TRIGGER}>
                        <SelectValue
                          placeholder={
                            cyclePeriodsLoading
                              ? 'Loading periods…'
                              : 'Select a payroll cycle…'
                          }
                        />
                      </SelectTrigger>
                      <SelectContent position="popper" align="start" className={SELECT_CONTENT}>
                        <SelectItem value="__none__" className={SELECT_ITEM}>
                          {cyclePeriodsLoading ? 'Loading periods…' : 'Select a payroll cycle…'}
                        </SelectItem>
                        {cyclePeriods.map((period) => {
                          const key = payCyclePeriodKey(period)
                          return (
                            <SelectItem key={key} value={key} className={SELECT_ITEM}>
                              {period.preview_line
                                || `${period.cut_off_start_date} → ${period.cut_off_end_date}${period.pay_date ? ` · Pay ${period.pay_date}` : ''}`}
                            </SelectItem>
                          )
                        })}
                      </SelectContent>
                    </Select>
                    <p className="text-xs text-muted-foreground">
                      Pick the cut-off window and pay date for this run, same as refunds.
                    </p>
                    {fromDate && toDate ? (
                      <div className="rounded-xl border border-brand/25 bg-brand/4.5 px-4 py-3 dark:border-brand/25 dark:bg-brand/10">
                        <p className="text-[11px] font-bold uppercase tracking-[0.16em] text-brand">Selected payroll cycle</p>
                        <p className="mt-1.5 text-sm font-semibold text-foreground">
                          {formatPayPeriodRange(fromDate, toDate)}
                        </p>
                        {referenceDate ? (
                          <p className="mt-1 text-xs text-muted-foreground">
                            Pay date: {formatDate(referenceDate)}
                          </p>
                        ) : null}
                      </div>
                    ) : null}
                  </div>
                ) : null}

                <div className="space-y-2">
                    <Label className="flex items-center gap-2 text-sm font-normal text-muted-foreground">
                      <PhilippinePeso className="h-4 w-4 shrink-0 text-muted-foreground/80" aria-hidden />
                      13th Month Pay
                    </Label>
                    <Select value={includeThirteenthMonth ? 'include' : 'exclude'} onValueChange={(value) => setIncludeThirteenthMonth(value === 'include')}>
                      <SelectTrigger className={SELECT_TRIGGER}><SelectValue /></SelectTrigger>
                      <SelectContent position="popper" align="start" className={SELECT_CONTENT}>
                        <PayrollSelectItem
                          value="exclude"
                          icon={PhilippinePeso}
                          title="Exclude 13th Month Pay"
                          subtitle="Generate regular payroll only"
                        />
                        <PayrollSelectItem
                          value="include"
                          icon={Sparkles}
                          title="Include 13th Month Pay"
                          subtitle="Adds payable amount from finalized configuration"
                        />
                      </SelectContent>
                    </Select>
                    <p className="text-xs text-muted-foreground">Include uses the employee's payable amount from a finalized 13th month configuration.</p>
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
                            disabled={(useCompanyDefaultDates && !payCycleId) || Boolean(payCycleId)}
                            className="h-10 rounded-lg border-border/80 bg-background dark:bg-input/45"
                          />
                        </div>
                        <div className="space-y-2">
                          <Label className="text-sm font-normal text-muted-foreground">To date</Label>
                          <Input
                            type="date"
                            value={toDate}
                            onChange={(e) => setToDate(e.target.value)}
                            disabled={(useCompanyDefaultDates && !payCycleId) || Boolean(payCycleId)}
                            className="h-10 rounded-lg border-border/80 bg-background dark:bg-input/45"
                          />
                        </div>
                        <div className="space-y-2">
                          <Label className="text-sm font-normal text-muted-foreground">Reference date</Label>
                          <Input
                            type="date"
                            value={referenceDate}
                            onChange={(e) => setReferenceDate(e.target.value)}
                            disabled={(useCompanyDefaultDates && !payCycleId) || Boolean(payCycleId)}
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
                'overflow-hidden rounded-2xl border bg-card shadow-sm shadow-slate-900/3 dark:shadow-black/25',
                (isDedicatedPayrollModule ? execomReady : scopeReady)
                  ? 'border-brand/35 ring-1 ring-brand/10'
                  : 'border-border/80',
              )}
            >
              <div className="p-5 md:p-6">
                {!isDedicatedPayrollModule && scopeReady && (
                  <div className="mb-4 overflow-hidden rounded-full">
                    <Progress value={scopeReadiness} className="h-1.5" indicatorClassName="bg-brand" />
                  </div>
                )}
                <div className="flex flex-col gap-5 lg:flex-row lg:items-center lg:justify-between">
                  <div className="max-w-2xl space-y-2">
                    <p className="text-[17px] font-semibold leading-snug text-foreground">
                      {isExecomModule
                        ? (execomReady ? 'Ready to generate EXECOM payroll' : 'Choose pay dates for EXECOM payroll')
                        : isConsultantModule
                          ? (execomReady ? 'Ready to generate Consultant payroll' : 'Choose pay dates for Consultant payroll')
                          : scopeReady
                        ? `Ready to generate · ${activeEmployees} Regular Payroll employee${activeEmployees === 1 ? '' : 's'} in scope`
                        : 'Choose filters to estimate your batch'}
                    </p>
                    {isExecomModule ? (
                      <p className="text-sm font-normal text-muted-foreground">
                        All active EXECOM profiles will be included. Fixed Basic Pay is used for EXECOM payroll.
                      </p>
                    ) : isConsultantModule ? (
                      <p className="text-sm font-normal text-muted-foreground">
                        All active consultant employees will be included. Consultant fixed-pay and employment payroll policies apply.
                      </p>
                    ) : scopeReady ? (
                      recentListNetTotal != null && companyRows.length > 0 ? (
                        <p className="text-sm font-normal leading-relaxed text-muted-foreground">
                          <span className="text-xl font-semibold tabular-nums text-brand">
                            {formatPeso(recentListNetTotal)}
                          </span>{' '}
                          <span className="text-muted-foreground">combined net in the company summary below.</span>
                        </p>
                      ) : (
                        <p className="text-sm font-normal text-muted-foreground">
                          {execomExcludedEmployees > 0 || consultantExcludedEmployees > 0
                            ? [
                                execomExcludedEmployees > 0
                                  ? `${execomExcludedEmployees} EXECOM employee${execomExcludedEmployees === 1 ? '' : 's'} handled in EXECOM Payroll`
                                  : null,
                                consultantExcludedEmployees > 0
                                  ? `${consultantExcludedEmployees} Consultant employee${consultantExcludedEmployees === 1 ? '' : 's'} handled in Consultant Payroll`
                                  : null,
                              ].filter(Boolean).join('. ') + '.'
                            : 'Continue to Finalize Payroll to review totals and generate PDF payslips.'}
                        </p>
                      )
                    ) : (
                      <p className="text-sm font-normal text-muted-foreground">
                        Select a company, branch, or department to see counts and run bulk generation.
                      </p>
                    )}
                  </div>
                  <div className="flex flex-col gap-2 sm:flex-row sm:items-center sm:justify-end">
                    {!isDedicatedPayrollModule && (
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
                    )}
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
                      {generating ? 'Queuing…' : isExecomModule ? 'Generate EXECOM Draft' : isConsultantModule ? 'Generate Consultant Draft' : 'Generate Payslips'}
                    </Button>
                  </div>
                </div>
              </div>
            </div>
          </div>

          {/* ── RIGHT: Live Processing Summary (30%) ── */}
          {false && !isExecomModule && (
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
                          Regular Payroll
                        </p>
                      </div>
                    </CircularProgress>
                  </div>

                  {/* Breakdown pills */}
                  {scopeReady && (
                    <div className="flex flex-wrap justify-center gap-2">
                      {activeEmployees > 0 && <BreakdownPill label="Regular Payroll" count={activeEmployees} />}
                      {execomExcludedEmployees > 0 && <BreakdownPill label="EXECOM separate" count={execomExcludedEmployees} />}
                      {consultantExcludedEmployees > 0 && <BreakdownPill label="Consultant separate" count={consultantExcludedEmployees} />}
                      {payrollScopeTotalEmployees > activeEmployees && <BreakdownPill label="Combined payroll scope" count={payrollScopeTotalEmployees} />}
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
                      value={scopeReady && Number.isFinite(estimatedNet) ? formatCompactPeso(estimatedNet) : '—'}
                      subtext={scopeReady && Number.isFinite(sampleNet) ? `${formatPeso(sampleNet)} avg/employee` : undefined}
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
          )}
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
            <div className="flex items-center gap-2">
              <Select value={recentModuleFilter} onValueChange={setRecentModuleFilter}>
                <SelectTrigger className={`${SELECT_TRIGGER} h-9 w-[11.5rem] rounded-lg text-xs`}>
                  <SelectValue />
                </SelectTrigger>
                <SelectContent position="popper" align="end" className={SELECT_CONTENT}>
                  <PayrollSelectItem
                    value="all"
                    icon={Layers}
                    title="All Modules"
                    subtitle="Regular, EXECOM, and Consultant"
                  />
                  <PayrollSelectItem
                    value="regular"
                    icon={FileText}
                    title="Regular Payroll"
                    subtitle="Standard payslip batches"
                  />
                  <PayrollSelectItem
                    value="execom"
                    icon={Zap}
                    title="EXECOM Payroll"
                    subtitle="Executive payroll batches"
                  />
                  <PayrollSelectItem
                    value="consultant"
                    icon={Users}
                    title="Consultant Payroll"
                    subtitle="Consultant-only batches"
                  />
                </SelectContent>
              </Select>
              {!isDedicatedPayrollModule && canBulkDownloadPayslipZip ? (
                <Button
                  type="button"
                  variant="outline"
                  size="sm"
                  onClick={handleOpenBankExportDialog}
                  disabled={listLoading || bankPayrollExportDownloadingFormat != null}
                  className={RECENT_HEADER_ACTION_BTN}
                >
                  {bankPayrollExportDownloadingFormat ? (
                    <Loader2 className="mr-2 h-4 w-4 animate-spin" />
                  ) : (
                    <Landmark className="mr-2 h-4 w-4" />
                  )}
                  Bank Payroll Export (AUB)
                </Button>
              ) : null}
              <Button
                type="button"
                variant="outline"
                size="sm"
                onClick={loadCompanySummary}
                disabled={listLoading}
                className={RECENT_HEADER_ACTION_BTN}
              >
                <RefreshCw className={`mr-2 h-4 w-4 ${listLoading ? 'animate-spin' : ''}`} />
                Refresh
              </Button>
            </div>
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
                      <TableHead className="w-[110px] text-[13px] font-bold tracking-normal text-foreground">
                        Module
                      </TableHead>
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
                      <TableHead className="min-w-[240px] whitespace-nowrap text-right text-[13px] font-bold tracking-normal text-foreground">
                        Actions
                      </TableHead>
                    </TableRow>
                  </TableHeader>
                  <TableBody className="[&_tr]:border-0 [&_tr]:transition-colors divide-y divide-border/70">
                    {companyRows.map((r) => {
                      const key = rowGroupKey(r)
                      const rowModule = payrollRowModuleKind(r)
                      const isDedicatedRow = rowModule !== 'regular'
                      const logo = isDedicatedRow ? null : (resolveLogoUrl(r) || companyLogoById[r.company_id])
                      const displayCompanyName = rowModule === 'execom' ? 'Execom' : rowModule === 'consultant' ? 'Consultant' : (r.company_name ?? '—')
                      const showDelete = Boolean(r.can_delete)
                      const deleteDisabled = !r.can_delete || deletingBatchId === r.payroll_batch_run_id
                      const batchFinalized = String(r.batch_run_status || '').toLowerCase() === 'finalized'
                      const showBulkPdf = batchFinalized && canBulkDownloadPayslipZip
                      const showPayrollReportPdf = showBulkPdf && (isDedicatedRow || Number(r.company_id || 0) > 0)
                      const showCompanyExcelDownloads = showPayrollReportPdf && (rowModule === 'regular' ? Number(r.company_id || 0) > 0 : rowModule === 'consultant')
                      const downloadsBusy =
                        bulkDownloadingBatchId === r.payroll_batch_run_id ||
                        payrollReportDownloadingBatchId === r.payroll_batch_run_id ||
                        payrollReportExcelDownloadingBatchId === r.payroll_batch_run_id ||
                        payrollDeductionsDownloadingBatchId === r.payroll_batch_run_id ||
                        payrollDeductionsExcelDownloadingBatchId === r.payroll_batch_run_id
                      return (
                        <TableRow
                          key={key}
                          className="group border-0 transition-colors hover:bg-muted/35"
                        >
                          <TableCell className="py-4">
                            <Badge
                              variant="outline"
                              className={cn(
                                'rounded-full text-[11px] font-semibold',
                                rowModule === 'execom'
                                  ? 'border-violet-300 bg-violet-50 text-violet-900 dark:border-violet-800 dark:bg-violet-950/40 dark:text-violet-200'
                                  : rowModule === 'consultant'
                                    ? 'border-amber-300 bg-amber-50 text-amber-900 dark:border-amber-800 dark:bg-amber-950/40 dark:text-amber-200'
                                    : 'border-sky-300 bg-sky-50 text-sky-900 dark:border-sky-800 dark:bg-sky-950/40 dark:text-sky-200',
                              )}
                            >
                              {r.module_label || (rowModule === 'execom' ? 'EXECOM' : rowModule === 'consultant' ? 'Consultant' : 'Regular')}
                            </Badge>
                          </TableCell>
                          <TableCell className="py-4">
                            <div className="flex items-center gap-3">
                              <div className="flex h-10 w-10 shrink-0 items-center justify-center overflow-hidden rounded-xl border border-border/80 bg-background transition-shadow group-hover:shadow-sm dark:bg-input/35">
                                {logo ? (
                                  <img src={logo} alt="" className="max-h-9 max-w-9 object-contain" />
                                ) : (
                                  <Building2 className="h-4 w-4 text-muted-foreground" />
                                )}
                              </div>
                              <span className="text-base font-bold text-foreground">{displayCompanyName}</span>
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
                            <div className="flex flex-nowrap items-center justify-end gap-2 whitespace-nowrap">
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
                                <DropdownMenu>
                                  <DropdownMenuTrigger asChild>
                                    <Button
                                      type="button"
                                      size="sm"
                                      variant="outline"
                                      className="h-8 rounded-lg border-border/80 bg-background px-3 text-xs font-semibold text-foreground shadow-sm hover:bg-muted dark:bg-input/35"
                                      disabled={downloadsBusy}
                                    >
                                      {downloadsBusy ? (
                                        <Loader2 className="mr-1.5 h-4 w-4 animate-spin" />
                                      ) : (
                                        <FileDown className="mr-1.5 h-4 w-4" />
                                      )}
                                      Downloads
                                      <ChevronDown className="ml-1 h-3.5 w-3.5 opacity-70" />
                                    </Button>
                                  </DropdownMenuTrigger>
                                  <DropdownMenuContent
                                    align="end"
                                    className="min-w-[12.5rem] border-border/60 shadow-sm"
                                  >
                                    <DropdownMenuItem
                                      disabled={bulkDownloadingBatchId === r.payroll_batch_run_id}
                                      onSelect={() => handleBulkDownloadBatchPdf(r)}
                                    >
                                      {bulkDownloadingBatchId === r.payroll_batch_run_id ? (
                                        <Loader2 className="mr-2 h-4 w-4 animate-spin" />
                                      ) : (
                                        <FileDown className="mr-2 h-4 w-4" />
                                      )}
                                      Bulk Download PDF
                                    </DropdownMenuItem>
                                    {showPayrollReportPdf && (
                                      <DropdownMenuItem
                                        disabled={payrollReportDownloadingBatchId === r.payroll_batch_run_id}
                                        onSelect={() => handleDownloadPayrollReportPdf(r)}
                                      >
                                        {payrollReportDownloadingBatchId === r.payroll_batch_run_id ? (
                                          <Loader2 className="mr-2 h-4 w-4 animate-spin" />
                                        ) : (
                                          <FileText className="mr-2 h-4 w-4" />
                                        )}
                                        Payroll Report PDF
                                      </DropdownMenuItem>
                                    )}
                                    {showCompanyExcelDownloads && (
                                      <DropdownMenuItem
                                        disabled={payrollReportExcelDownloadingBatchId === r.payroll_batch_run_id}
                                        onSelect={() => handleDownloadPayrollReportExcel(r)}
                                      >
                                        {payrollReportExcelDownloadingBatchId === r.payroll_batch_run_id ? (
                                          <Loader2 className="mr-2 h-4 w-4 animate-spin" />
                                        ) : (
                                          <FileSpreadsheet className="mr-2 h-4 w-4" />
                                        )}
                                        Payroll Report Excel
                                      </DropdownMenuItem>
                                    )}
                                    {showPayrollReportPdf && (
                                      <DropdownMenuItem
                                        disabled={payrollDeductionsDownloadingBatchId === r.payroll_batch_run_id}
                                        onSelect={() => handleDownloadPayrollReportPdf(r, true)}
                                      >
                                        {payrollDeductionsDownloadingBatchId === r.payroll_batch_run_id ? (
                                          <Loader2 className="mr-2 h-4 w-4 animate-spin" />
                                        ) : (
                                          <FileText className="mr-2 h-4 w-4" />
                                        )}
                                        Deductions PDF
                                      </DropdownMenuItem>
                                    )}
                                    {showCompanyExcelDownloads && (
                                      <DropdownMenuItem
                                        disabled={payrollDeductionsExcelDownloadingBatchId === r.payroll_batch_run_id}
                                        onSelect={() => handleDownloadPayrollDeductionsExcel(r)}
                                      >
                                        {payrollDeductionsExcelDownloadingBatchId === r.payroll_batch_run_id ? (
                                          <Loader2 className="mr-2 h-4 w-4 animate-spin" />
                                        ) : (
                                          <FileSpreadsheet className="mr-2 h-4 w-4" />
                                        )}
                                        Deductions Excel
                                      </DropdownMenuItem>
                                    )}
                                  </DropdownMenuContent>
                                </DropdownMenu>
                              )}
                              {showDelete && (
                                <Button
                                  type="button"
                                  size="sm"
                                  variant="outline"
                                  title={
                                    deleteDisabled && !deletingBatchId
                                      ? 'Only draft, queued, generating, or failed batches can be deleted.'
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
            {!listLoading && Number(recentListMeta.total || 0) > 0 ? (
              <div className="mt-4 flex flex-col gap-3 border-t border-border/60 pt-4 sm:flex-row sm:items-center sm:justify-between">
                <p className="text-sm text-muted-foreground">
                  Page {recentListMeta.current_page} of {recentListMeta.last_page}
                  {' · '}
                  {recentListMeta.total} batch{Number(recentListMeta.total) === 1 ? '' : 'es'}
                </p>
                <div className="flex items-center gap-2">
                  <Button
                    type="button"
                    variant="outline"
                    size="sm"
                    className="rounded-lg"
                    disabled={listLoading || recentListPage <= 1}
                    onClick={() => setRecentListPage((p) => Math.max(1, p - 1))}
                  >
                    <ChevronLeft className="mr-1 h-4 w-4" />
                    Previous
                  </Button>
                  <Button
                    type="button"
                    variant="outline"
                    size="sm"
                    className="rounded-lg"
                    disabled={listLoading || recentListPage >= Number(recentListMeta.last_page || 1)}
                    onClick={() => setRecentListPage((p) => Math.min(Number(recentListMeta.last_page || 1), p + 1))}
                  >
                    Next
                    <ChevronRight className="ml-1 h-4 w-4" />
                  </Button>
                </div>
              </div>
            ) : null}
          </CardContent>
        </Card>

        {/* ── Bank Payroll Export Dialog ── */}
        <Dialog
          open={bankExportDialogOpen}
          onOpenChange={(open) => {
            if (bankPayrollExportDownloadingFormat == null) setBankExportDialogOpen(open)
          }}
        >
          <DialogContent className="sm:max-w-lg">
            <DialogHeader>
              <DialogTitle className="flex items-center gap-2 text-foreground">
                <Landmark className="h-5 w-5 text-emerald-600" />
                Bank Payroll Export (AUB)
              </DialogTitle>
              <DialogDescription className="text-left text-muted-foreground">
                Step 1: choose the finalized pay cycle. Step 2: pick a download format. All finalized companies for that cutoff are included, sorted alphabetically. Account numbers stay as 12-digit text in Excel.
              </DialogDescription>
            </DialogHeader>
            <div className="space-y-5 py-1">
              <div className="space-y-2">
                <Label htmlFor="bank-export-cutoff">Pay cycle / cutoff</Label>
                {bankExportCutoffsLoading ? (
                  <div className="flex items-center gap-2 rounded-lg border border-border/60 px-3 py-2.5 text-sm text-muted-foreground">
                    <Loader2 className="h-4 w-4 animate-spin" />
                    Loading finalized cutoffs...
                  </div>
                ) : bankExportCutoffOptions.length === 0 ? (
                  <p className="rounded-lg border border-dashed border-border/70 px-3 py-2.5 text-sm text-muted-foreground">
                    No finalized regular payroll cutoffs are available yet.
                  </p>
                ) : (
                  <Select value={bankExportDialogCutoffKey || undefined} onValueChange={setBankExportDialogCutoffKey}>
                    <SelectTrigger id="bank-export-cutoff" className={SELECT_TRIGGER}>
                      <SelectValue placeholder="Select pay cycle" />
                    </SelectTrigger>
                    <SelectContent className={SELECT_CONTENT}>
                      {bankExportCutoffOptions.map((option) => (
                        <SelectItem key={option.key} value={option.key} className={SELECT_ITEM}>
                          {formatPayPeriodRange(option.from_date, option.to_date)}
                          {Number(option.company_count || 0) > 0
                            ? ` · ${option.company_count} ${Number(option.company_count) === 1 ? 'company' : 'companies'}`
                            : ''}
                        </SelectItem>
                      ))}
                    </SelectContent>
                  </Select>
                )}
              </div>
              <div className="space-y-2">
                <Label>Download format</Label>
                <div className="grid gap-2 sm:grid-cols-3">
                  <Button
                    type="button"
                    variant="outline"
                    className="h-auto flex-col gap-1 border-2 border-emerald-500/40 py-3 hover:bg-emerald-50 dark:hover:bg-emerald-950/20"
                    disabled={!bankExportCutoff || bankPayrollExportDownloadingFormat != null}
                    onClick={() => void handleDownloadBankPayrollExport('xlsx')}
                  >
                    {bankPayrollExportDownloadingFormat === 'xlsx' ? (
                      <Loader2 className="h-5 w-5 animate-spin" />
                    ) : (
                      <FileSpreadsheet className="h-5 w-5" />
                    )}
                    <span className="text-xs font-semibold">Excel (.xlsx)</span>
                  </Button>
                  <Button
                    type="button"
                    variant="outline"
                    className="h-auto flex-col gap-1 border-2 border-emerald-500/40 py-3 hover:bg-emerald-50 dark:hover:bg-emerald-950/20"
                    disabled={!bankExportCutoff || bankPayrollExportDownloadingFormat != null}
                    onClick={() => void handleDownloadBankPayrollExport('csv')}
                  >
                    {bankPayrollExportDownloadingFormat === 'csv' ? (
                      <Loader2 className="h-5 w-5 animate-spin" />
                    ) : (
                      <FileText className="h-5 w-5" />
                    )}
                    <span className="text-xs font-semibold">CSV (.csv)</span>
                  </Button>
                  <Button
                    type="button"
                    variant="outline"
                    className="h-auto flex-col gap-1 border-2 border-emerald-500/40 py-3 hover:bg-emerald-50 dark:hover:bg-emerald-950/20"
                    disabled={!bankExportCutoff || bankPayrollExportDownloadingFormat != null}
                    onClick={() => void handleDownloadBankPayrollExport('pdf')}
                  >
                    {bankPayrollExportDownloadingFormat === 'pdf' ? (
                      <Loader2 className="h-5 w-5 animate-spin" />
                    ) : (
                      <FileText className="h-5 w-5" />
                    )}
                    <span className="text-xs font-semibold">PDF (.pdf)</span>
                  </Button>
                </div>
              </div>
            </div>
            <DialogFooter>
              <Button
                type="button"
                variant="outline"
                onClick={() => setBankExportDialogOpen(false)}
                disabled={bankPayrollExportDownloadingFormat != null}
              >
                Cancel
              </Button>
            </DialogFooter>
          </DialogContent>
        </Dialog>

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
                This removes draft payslip rows or cancels a failed, queued, or generating payroll for{' '}
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
