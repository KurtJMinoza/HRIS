import { useCallback, useEffect, useMemo, useRef, useState } from 'react'
import { Link, useNavigate, useSearchParams } from 'react-router-dom'
import {
  adminQueueBulkPayslipDownload,
  adminPollAndDownloadBulkPayslipZip,
  adminBulkSendFinalizedBatchPayslips,
  adminDeleteFinalizedPayrollBatch,
  adminDeliverFinalizePayslips,
  adminExecuteFinalizePayroll,
  adminFinalizePayrollStatus,
  adminPreviewFinalizePayroll,
  companyLogoUrl,
  getCompanies,
  getPayrollRunCompanyPayrollReportPdfBlob,
  userProfileImageSrc,
} from '@/api'
import { useHrBasePath } from '@/contexts/HrAppPathContext'
import { useAuth } from '@/contexts/AuthContext'
import { bulkPayslipDownloadStatusLabel, saveBulkPayslipZipBlob } from '@/lib/bulkPayslipDownload'
import { Avatar, AvatarFallback, AvatarImage } from '@/components/ui/avatar'
import { Button } from '@/components/ui/button'
import { Card, CardContent } from '@/components/ui/card'
import { Checkbox } from '@/components/ui/checkbox'
import { Dialog, DialogContent, DialogDescription, DialogHeader, DialogTitle } from '@/components/ui/dialog'
import { Input } from '@/components/ui/input'
import { Label } from '@/components/ui/label'
import { Progress } from '@/components/ui/progress'
import { Badge } from '@/components/ui/badge'
import { Table, TableBody, TableCell, TableHead, TableHeader, TableRow } from '@/components/ui/table'
import { TooltipProvider } from '@/components/ui/tooltip'
import { useToast } from '@/components/ui/use-toast'
import { cn } from '@/lib/utils'
import { compareEmployeeRowsBySortKey, formatEmployeeName } from '@/lib/employeeSort'
import {
  ArrowLeft,
  ArrowUpRight,
  Building2,
  CheckCircle2,
  Eye,
  FileDown,
  FileText,
  Info,
  RefreshCw,
  Loader2,
  Lock,
  PhilippinePeso,
  Search,
  Send,
  Trash2,
  Users,
} from 'lucide-react'

const TEXT = 'text-foreground'
/** Full-width shell aligned with Government Deduction / Pay Cycles style */
const PAYSLIP_MODULE_SHELL =
  'w-full min-w-0 max-w-none bg-background px-3 py-4 text-foreground sm:px-4 md:px-5 lg:px-6 lg:py-5'
const PAYSLIP_STACK = 'space-y-5 sm:space-y-6'
const CARD = 'rounded-2xl border border-border/80 bg-card text-card-foreground shadow-sm shadow-slate-900/[0.03] dark:shadow-black/25'
const PAYSLIP_PREVIEW_DIALOG =
  '!max-w-[min(88rem,calc(100vw-1.5rem))] w-full overflow-hidden border-border/80 bg-card p-0 shadow-xl shadow-slate-900/[0.07] sm:!max-w-[min(88rem,calc(100vw-2rem))] dark:shadow-black/40'

/** Matches non-draft payslip rows considered “done” for period lock + per-row Finalize button (align with backend). */
const PUBLISHED_PAYSLIP_STATUSES = new Set(['finalized', 'generated', 'emailed', 'sent_finalized', 'viewed'])

function isPayslipPublishedDone(status) {
  return PUBLISHED_PAYSLIP_STATUSES.has(String(status || '').toLowerCase())
}

/** Align with {@see PayslipService::sendPayslip}: any published payslip ({@see Payslip::lockingStatuses()}). */
function isSendablePayslipStatus(status) {
  return isPayslipPublishedDone(status)
}

function formatPeso(n) {
  const v = Number(n)
  if (!Number.isFinite(v)) return '₱0.00'
  return `₱${v.toLocaleString(undefined, { minimumFractionDigits: 2, maximumFractionDigits: 2 })}`
}

function formatDate(value) {
  if (!value) return '—'
  const d = new Date(value)
  if (Number.isNaN(d.getTime())) return String(value)
  return d.toLocaleDateString(undefined, { month: 'short', day: 'numeric', year: 'numeric' })
}

function payrollEmployeeDisplayName(row) {
  return formatEmployeeName(row)
}

function initials(name) {
  if (!name || typeof name !== 'string') return '?'
  const p = name.trim().split(/\s+/).filter(Boolean)
  if (p.length === 0) return '?'
  if (p.length === 1) return p[0].slice(0, 2).toUpperCase()
  return `${p[0][0]}${p[p.length - 1][0]}`.toUpperCase()
}

function parsePayload(sp) {
  const employeeRaw = sp.get('employee_id')
  return {
    company_id: sp.get('company_id') ? Number(sp.get('company_id')) : null,
    branch_id: sp.get('branch_id') ? Number(sp.get('branch_id')) : null,
    department_id: sp.get('department_id') ? Number(sp.get('department_id')) : null,
    employee_id: employeeRaw && String(employeeRaw).trim() ? Number(employeeRaw) : null,
    from_date: sp.get('from_date') || null,
    to_date: sp.get('to_date') || null,
    pay_cycle_id: sp.get('pay_cycle_id') ? Number(sp.get('pay_cycle_id')) : null,
    reference_date: sp.get('reference_date') || null,
    payroll_period_id: sp.get('payroll_period_id') ? Number(sp.get('payroll_period_id')) : null,
    use_company_default: sp.get('use_company_default') === 'true',
    password_protect: sp.get('password_protect') === 'true',
    is_final_pay: false,
  }
}

function hasScope(p) {
  return Boolean(p.company_id || p.branch_id || p.department_id || p.employee_id)
}

function employeeRole(row) {
  return (
    row?.position ||
    row?.job_position ||
    row?.job_title ||
    row?.designation ||
    row?.role_name ||
    row?.role ||
    '—'
  )
}

function employeeCompanyPosition(row) {
  return row?.employee_hr_role || row?.company_position || row?.companyRole || row?.hr_role || 'employee'
}

function roleBadgeMeta(roleRaw) {
  const role = String(roleRaw || '').trim().toLowerCase()
  if (role === 'company_head' || role === 'company head') {
    return { label: 'Company Head', className: 'bg-brand/10 text-brand ring-1 ring-brand/20' }
  }
  if (role === 'branch_head' || role === 'branch head') {
    return { label: 'Branch Head', className: 'bg-brand/10 text-brand ring-1 ring-brand/20' }
  }
  if (role === 'department_head' || role === 'department head') {
    return { label: 'Department Head', className: 'bg-brand/10 text-brand ring-1 ring-brand/20' }
  }
  return { label: 'Employee', className: 'bg-muted text-muted-foreground ring-1 ring-border/60' }
}

function roleRank(roleRaw) {
  const role = String(roleRaw || '').trim().toLowerCase()
  if (role === 'company_head' || role === 'company head') return 0
  if (role === 'branch_head' || role === 'branch head') return 1
  if (role === 'department_head' || role === 'department head') return 2
  return 3
}

function employeeAvatarSrc(row) {
  if (!row || typeof row !== 'object') return undefined
  const candidate =
    row.employee ||
    row.user ||
    {
      profile_image_url: row.profile_image_url ?? row.avatar_url ?? row.photo_url ?? null,
      profile_image: row.profile_image ?? row.avatar ?? row.photo ?? null,
    }
  return userProfileImageSrc(candidate)
}

function dailyEarningHighlights(row) {
  const lines = Array.isArray(row?.daily_computation_earning_lines) ? row.daily_computation_earning_lines : []
  return lines
    .map((line) => ({
      label: String(line?.label || '').trim(),
      amount: Number(line?.amount || 0),
    }))
    .filter((line) => line.label && Number.isFinite(line.amount) && Math.abs(line.amount) > 0.004)
    .slice(0, 3)
}

function attendanceSummaryLine(row) {
  const summary = row?.attendance_display_summary
  const count = Number(summary?.working_days_count || 0)
  if (!Number.isFinite(count) || count <= 0) return null
  const presence = Number(summary?.presence_days_count || 0)
  const firstShift = Array.isArray(summary?.lines) && summary.lines.length > 0
    ? String(summary.lines[0]?.shift || '').trim()
    : ''
  const base = firstShift
    ? `${count} regular-rate days (${firstShift}, Sun excluded)`
    : `${count} regular-rate days (Sun excluded)`
  if (Number.isFinite(presence) && presence > count) {
    return `${base} — ${presence} calendar day(s) with paid attendance (premium holidays count under Holiday premium).`
  }
  return base
}

function statusPill(label, color = 'gray') {
  const map = {
    green: 'border-brand/30 bg-brand/10 text-brand',
    gray: 'border-border/70 bg-muted/60 text-foreground',
  }
  const dot = color === 'green' ? 'text-brand' : 'text-muted-foreground'
  return (
    <span className={cn('inline-flex items-center gap-1.5 rounded-full border px-2.5 py-1 text-xs font-medium', map[color] || map.gray)}>
      <span className={cn('text-[10px]', dot)}>●</span>
      {label}
    </span>
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

export default function AdminFinalizePayrollPage() {
  const { user } = useAuth()
  const { toast } = useToast()
  const toastRef = useRef(toast)
  toastRef.current = toast
  const hrBase = useHrBasePath()
  const navigate = useNavigate()
  const [searchParams, setSearchParams] = useSearchParams()
  const isAdmin = user?.role === 'admin' || user?.role === 'super_admin'
  const permissionSet = useMemo(() => new Set(user?.permissions ?? []), [user?.permissions])
  const canFinalizePayroll = permissionSet.has('payslip.finalize')
  const canBulkDownloadPayslipZip = permissionSet.has('payslip.download')

  const payload = useMemo(() => parsePayload(searchParams), [searchParams.toString()])
  const effectivePayload = useMemo(() => {
    // Company Head fallback: when URL scope is missing, use the actor's company context.
    if (payload.company_id || user?.hr_role !== 'company_head') return payload
    const fallbackCompanyId = Number(user?.company_id || 0)
    if (!fallbackCompanyId) return payload
    return { ...payload, company_id: fallbackCompanyId }
  }, [payload, user?.hr_role, user?.company_id])

  /** Pay window + org scope only (excludes preview refresh token) — resets local finalize state when navigating batches. */
  const finalizeScopeKey = useMemo(
    () =>
      JSON.stringify({
        c: effectivePayload.company_id,
        b: effectivePayload.branch_id,
        d: effectivePayload.department_id,
        e: effectivePayload.employee_id,
        f: effectivePayload.from_date,
        t: effectivePayload.to_date,
        p: effectivePayload.pay_cycle_id,
        r: effectivePayload.reference_date,
        pp: effectivePayload.payroll_period_id,
        ucd: effectivePayload.use_company_default,
        pwd: effectivePayload.password_protect,
      }),
    [
      effectivePayload.company_id,
      effectivePayload.branch_id,
      effectivePayload.department_id,
      effectivePayload.employee_id,
      effectivePayload.from_date,
      effectivePayload.to_date,
      effectivePayload.pay_cycle_id,
      effectivePayload.reference_date,
      effectivePayload.payroll_period_id,
      effectivePayload.use_company_default,
      effectivePayload.password_protect,
    ],
  )

  const [loading, setLoading] = useState(true)
  const [preview, setPreview] = useState(null)
  const [previewError, setPreviewError] = useState('')
  const [companies, setCompanies] = useState([])
  const [search, setSearch] = useState('')
  const [debouncedSearch, setDebouncedSearch] = useState('')
  const initialPageFromUrl = useMemo(() => {
    const raw = Number(searchParams.get('page') || 1)
    return Number.isFinite(raw) && raw > 0 ? Math.floor(raw) : 1
  }, [searchParams])
  const [page, setPage] = useState(initialPageFromUrl)
  const [reviewConfirmed, setReviewConfirmed] = useState(false)
  const [finalizing, setFinalizing] = useState(false)
  const [done, setDone] = useState(false)
  const [showFinalizeModal, setShowFinalizeModal] = useState(false)
  const [progress, setProgress] = useState(0)
  const [processedCount, setProcessedCount] = useState(0)
  const tickRef = useRef(null)
  const pollRef = useRef(null)
  const [queuedRunId, setQueuedRunId] = useState(null)
  const [finalizeStage, setFinalizeStage] = useState('Preparing request')
  const pageSize = 12
  // Keep local page in sync when URL page changes (back/forward/manual URL edit).
  useEffect(() => {
    const next = Number(searchParams.get('page') || 1)
    const normalized = Number.isFinite(next) && next > 0 ? Math.floor(next) : 1
    setPage((prev) => (prev === normalized ? prev : normalized))
  }, [searchParams])

  // Reflect page changes in URL (?page=2) while preserving existing query params.
  useEffect(() => {
    const currentInUrl = Number(searchParams.get('page') || 1)
    const normalizedCurrent = Number.isFinite(currentInUrl) && currentInUrl > 0 ? Math.floor(currentInUrl) : 1
    if (normalizedCurrent === page) return
    const next = new URLSearchParams(searchParams)
    next.set('page', String(page))
    setSearchParams(next, { replace: true })
  }, [page, searchParams, setSearchParams])

  useEffect(() => {
    const timer = setTimeout(() => setDebouncedSearch(search.trim()), 300)
    return () => clearTimeout(timer)
  }, [search])

  const [breakdownRow, setBreakdownRow] = useState(null)
  const [refreshToken, setRefreshToken] = useState(() => String(Date.now()))
  /** Set when status polling sees `finalized` so the badge flips immediately (before preview refetch). */
  const [localFinalizeLocked, setLocalFinalizeLocked] = useState(false)

  const [selectedPayslipIds, setSelectedPayslipIds] = useState(() => new Set())
  const [deliveringBulk, setDeliveringBulk] = useState(false)
  const [deliveringPayslipId, setDeliveringPayslipId] = useState(null)
  const [deleteDialogOpen, setDeleteDialogOpen] = useState(false)
  const [deletingBatch, setDeletingBatch] = useState(false)
  const [bulkDownloadingZip, setBulkDownloadingZip] = useState(false)
  const [payrollReportDownloading, setPayrollReportDownloading] = useState(false)
  const [bulkDownloadProgress, setBulkDownloadProgress] = useState(null)
  const bulkDownloadAbortRef = useRef(null)
  const [sendBatchDialogOpen, setSendBatchDialogOpen] = useState(false)
  const [sendingBatchPayslips, setSendingBatchPayslips] = useState(false)

  const periodFinalized = useMemo(() => {
    if (done || localFinalizeLocked) return true
    if (preview?.period_locked) return true
    const br = String(preview?.batch_run?.status || '').toLowerCase()
    if (br === 'finalized') return true
    const emps = preview?.employees
    if (Array.isArray(emps) && emps.length > 0) {
      const allFinalized = emps.every((r) => isPayslipPublishedDone(r?.status))
      if (allFinalized) return true
    }
    return false
  }, [preview, done, localFinalizeLocked])

  const batchRunStatusFinalized = useMemo(
    () => String(preview?.batch_run?.status || '').toLowerCase() === 'finalized',
    [preview?.batch_run?.status]
  )

  useEffect(() => {
    setDone(false)
    setLocalFinalizeLocked(false)
    setQueuedRunId(null)
    setFinalizing(false)
    setShowFinalizeModal(false)
    setReviewConfirmed(false)
    setProcessedCount(0)
    setProgress(0)
    setFinalizeStage('Preparing request')
  }, [finalizeScopeKey])

  useEffect(() => {
    setSelectedPayslipIds(new Set())
  }, [finalizeScopeKey])

  useEffect(() => {
    if (!queuedRunId) return
    if (!periodFinalized) return
    if (pollRef.current) clearInterval(pollRef.current)
    setQueuedRunId(null)
    setFinalizing(false)
    if (tickRef.current) clearInterval(tickRef.current)
    setShowFinalizeModal(false)
  }, [queuedRunId, periodFinalized])

  /** Scope key so preview effect does not re-run on unrelated object identity changes. */
  const previewScopeKey = useMemo(
    () =>
      JSON.stringify({
        c: effectivePayload.company_id,
        b: effectivePayload.branch_id,
        d: effectivePayload.department_id,
        e: effectivePayload.employee_id,
        f: effectivePayload.from_date,
        t: effectivePayload.to_date,
        p: effectivePayload.pay_cycle_id,
        r: effectivePayload.reference_date,
        pp: effectivePayload.payroll_period_id,
        ucd: effectivePayload.use_company_default,
        pwd: effectivePayload.password_protect,
        rt: refreshToken,
      }),
    [
      effectivePayload.company_id,
      effectivePayload.branch_id,
      effectivePayload.department_id,
      effectivePayload.employee_id,
      effectivePayload.from_date,
      effectivePayload.to_date,
      effectivePayload.pay_cycle_id,
      effectivePayload.reference_date,
      effectivePayload.payroll_period_id,
      effectivePayload.use_company_default,
      effectivePayload.password_protect,
      refreshToken,
    ]
  )

  useEffect(() => {
    let cancelled = false

    if (!canFinalizePayroll || (isAdmin && !hasScope(effectivePayload))) {
      setLoading(false)
      setPreview(null)
      setPreviewError('')
    } else {
      setLoading(true)
      setPreviewError('')
      ;(async () => {
        try {
          const data = await adminPreviewFinalizePayroll({
            ...effectivePayload,
            refresh_token: refreshToken,
            page,
            per_page: pageSize,
            search: debouncedSearch || undefined,
          })
          if (cancelled) return
          setPreview(data)
        } catch (e) {
          if (cancelled) return
          const fallback = 'Server request timed out. Check if the backend API is running and reachable.'
          const message = String(e?.message || '').trim() || fallback
          setPreviewError(message)
          toastRef.current({ title: 'Preview failed', description: message, variant: 'destructive' })
          setPreview(null)
        } finally {
          if (!cancelled) setLoading(false)
        }
      })()
    }

    return () => {
      cancelled = true
    }
  }, [canFinalizePayroll, isAdmin, previewScopeKey, page, pageSize, debouncedSearch])

  // Real-time sync: when attendance logs/corrections change, the preview must recompute.
  // Finalize payroll already supports a cache-busting `refresh_token`; we auto-rotate it
  // periodically while the preview page is visible.
  useEffect(() => {
    const timer = window.setInterval(() => {
      if (document.hidden) return
      if (!canFinalizePayroll) return
      if (periodFinalized || finalizing || loading) return
      setRefreshToken(String(Date.now()))
    }, 30000)

    return () => window.clearInterval(timer)
  }, [canFinalizePayroll, periodFinalized, finalizing, loading])

  useEffect(() => {
    let cancelled = false
    ;(async () => {
      try {
        const res = await getCompanies()
        if (!cancelled) setCompanies(Array.isArray(res?.companies) ? res.companies : [])
      } catch {
        if (!cancelled) setCompanies([])
      }
    })()
    return () => {
      cancelled = true
    }
  }, [])

  const employees = preview?.employees ?? []
  const totals = preview?.totals ?? { total_gross: 0, total_deductions: 0, total_net: 0, employee_count: 0 }
  const periodPreview = preview?.period_preview ?? null
  const batchRun = preview?.batch_run ?? null
  const batchRunStatus = String(batchRun?.status || '').toLowerCase()
  const draftProcessing = batchRunStatus === 'queued' || batchRunStatus === 'processing'
  const draftReady = batchRunStatus === 'draft'
  const draftToastShownRef = useRef(false)

  useEffect(() => {
    draftToastShownRef.current = false
  }, [finalizeScopeKey])

  useEffect(() => {
    if (draftToastShownRef.current || periodFinalized || finalizing) return
    if (!draftReady || draftProcessing) return
    if (loading) return
    draftToastShownRef.current = true
    toastRef.current({
      title: 'Payroll draft generated successfully.',
      description: 'Employee salary rows are ready for review.',
    })
  }, [draftReady, draftProcessing, periodFinalized, finalizing, loading])

  useEffect(() => {
    if (!draftProcessing || periodFinalized || finalizing) return undefined
    const timer = window.setInterval(() => {
      if (document.hidden) return
      setRefreshToken(String(Date.now()))
    }, 3000)

    return () => window.clearInterval(timer)
  }, [draftProcessing, periodFinalized, finalizing])

  const selectedCompany = useMemo(
    () => companies.find((c) => Number(c.id) === Number(effectivePayload.company_id)),
    [companies, effectivePayload.company_id]
  )
  const selectedCompanyLogo = companyLogoUrl(selectedCompany)

  const pageRows = useMemo(() => {
    return [...employees].sort((a, b) => compareEmployeeRowsBySortKey({ original: a }, { original: b }))
  }, [employees])
  const pagination = preview?.pagination ?? { page: 1, per_page: pageSize, total: pageRows.length, last_page: 1 }
  const pageCount = Math.max(1, Number(pagination.last_page || 1))

  // If backend clamps page (e.g., URL page > last_page), reflect the effective page in UI + URL.
  useEffect(() => {
    if (!preview?.pagination) return
    const serverPage = Number(preview.pagination.page || 1)
    const normalized = Number.isFinite(serverPage) && serverPage > 0 ? Math.floor(serverPage) : 1
    setPage((prev) => (prev === normalized ? prev : normalized))
  }, [preview?.pagination])

  useEffect(() => {
    setPage(1)
  }, [debouncedSearch])

  const handleFinalize = async () => {
    if (!canFinalizePayroll || !reviewConfirmed || periodFinalized) return

    const total = Math.max(1, Number(totals?.employee_count || employees.length || 1))
    setShowFinalizeModal(true)
    setProgress(4)
    setProcessedCount(0)
    setFinalizeStage('Submitting finalize request')
    if (tickRef.current) clearInterval(tickRef.current)
    tickRef.current = setInterval(() => {
      setProcessedCount((c) => {
        const next = Math.min(total - 1, c + Math.max(1, Math.ceil(total / 28)))
        setProgress(Math.min(94, Math.round((next / total) * 100)))
        return next
      })
    }, 170)

    setFinalizing(true)
    try {
      const queued = await adminExecuteFinalizePayroll({ ...effectivePayload, review_confirmed: true })
      const runId = Number(queued?.payroll_batch_run_id ?? 0) || null
      setQueuedRunId(runId)
      setFinalizeStage('Queued: waiting for worker')
      if (!runId) {
        if (tickRef.current) clearInterval(tickRef.current)
        setFinalizing(false)
        setShowFinalizeModal(false)
        setProgress(0)
        setProcessedCount(0)
        toastRef.current({
          title: 'Finalize failed',
          description: 'The server did not return a batch run id. Check the network response or queue worker.',
          variant: 'destructive',
        })
        return
      }
      toastRef.current({
        title: 'Finalize queued',
        description: 'Finalizing in background. Please wait while we process the batch.',
      })
    } catch (e) {
      if (tickRef.current) clearInterval(tickRef.current)
      if (pollRef.current) clearInterval(pollRef.current)
      setShowFinalizeModal(false)
      setProgress(0)
      setProcessedCount(0)
      setQueuedRunId(null)
      setFinalizing(false)
      toastRef.current({ title: 'Finalize failed', description: e.message, variant: 'destructive' })
    } finally {
      // keep modal "in progress" while queued job runs; will flip false on completion/failure polling.
    }
  }

  useEffect(() => {
    if (!queuedRunId || periodFinalized) return
    if (pollRef.current) clearInterval(pollRef.current)
    let pollAttempts = 0
    const maxPollAttempts = 240

    pollRef.current = setInterval(async () => {
      pollAttempts += 1
      if (pollAttempts > maxPollAttempts) {
        if (tickRef.current) clearInterval(tickRef.current)
        if (pollRef.current) clearInterval(pollRef.current)
        setFinalizing(false)
        setShowFinalizeModal(false)
        setProgress(0)
        setProcessedCount(0)
        setQueuedRunId(null)
        toastRef.current({
          title: 'Finalize timed out',
          description: 'Status polling stopped after 10 minutes. Check the queue worker and batch run status.',
          variant: 'destructive',
        })
        return
      }
      try {
        const status = await adminFinalizePayrollStatus(queuedRunId)
        const s = String(status?.status || '').toLowerCase()
        const realProgress = status?.progress || null
        if (realProgress && Number(realProgress.total_employees || 0) > 0) {
          setProcessedCount(Number(realProgress.processed_employees || 0))
          setProgress(Math.max(4, Math.min(99, Number(realProgress.percent || 0))))
        }
        if (s === 'queued') {
          setFinalizeStage('Queued: waiting for worker')
          setProgress((p) => Math.max(p, 22))
        } else if (s === 'processing') {
          setFinalizeStage('Processing employee payroll and PDFs')
          setProgress((p) => Math.max(p, 58))
        }
        if (s === 'finalized') {
          if (tickRef.current) clearInterval(tickRef.current)
          if (pollRef.current) clearInterval(pollRef.current)
          const total = Math.max(1, Number(status?.totals?.employee_count || totals?.employee_count || 1))
          setProcessedCount(total)
          setProgress(100)
          setFinalizeStage('Finalized and locked')
          setLocalFinalizeLocked(true)
          setDone(true)
          setFinalizing(false)
          setRefreshToken(String(Date.now()))
          try {
            const marker = String(Date.now())
            if (typeof window !== 'undefined') {
              window.dispatchEvent(new CustomEvent('hr:payroll-finalized', { detail: { at: marker } }))
              window.localStorage.setItem('hr:payroll-finalized-at', marker)
            }
          } catch {
            // no-op
          }
          toastRef.current({ title: 'Payroll finalized', description: 'Payslip PDFs were generated and periods locked.' })
          setTimeout(() => setShowFinalizeModal(false), 1300)
          return
        }
        if (s === 'failed') {
          if (tickRef.current) clearInterval(tickRef.current)
          if (pollRef.current) clearInterval(pollRef.current)
          setFinalizing(false)
          setShowFinalizeModal(false)
          setProgress(0)
          setProcessedCount(0)
          setQueuedRunId(null)
          toastRef.current({
            title: 'Finalize failed',
            description: status?.error_message || 'Payroll finalize job failed.',
            variant: 'destructive',
          })
          return
        }
        setProgress((p) => Math.min(96, p + 1))
      } catch {
        // keep polling on transient status-check failures
      }
    }, 2500)
    return () => {
      if (pollRef.current) clearInterval(pollRef.current)
    }
  }, [queuedRunId, periodFinalized, totals?.employee_count])

  const handlePreviewPayslip = async (row) => {
    /** Scope + period for round-trip Back navigation (must match Finalize URL; never omit branch/dept when user narrowed scope). */
    const appendFinalizeScopeToQuery = (q) => {
      if (effectivePayload.company_id) q.set('company_id', String(effectivePayload.company_id))
      if (effectivePayload.branch_id) q.set('branch_id', String(effectivePayload.branch_id))
      if (effectivePayload.department_id) q.set('department_id', String(effectivePayload.department_id))
      if (effectivePayload.from_date) q.set('from_date', String(effectivePayload.from_date))
      if (effectivePayload.to_date) q.set('to_date', String(effectivePayload.to_date))
      if (effectivePayload.pay_cycle_id) q.set('pay_cycle_id', String(effectivePayload.pay_cycle_id))
      if (effectivePayload.reference_date) q.set('reference_date', String(effectivePayload.reference_date))
      if (effectivePayload.payroll_period_id) q.set('payroll_period_id', String(effectivePayload.payroll_period_id))
      if (effectivePayload.is_final_pay) q.set('is_final_pay', 'true')
      if (effectivePayload.password_protect) q.set('password_protect', 'true')
    }

    const qBack = new URLSearchParams()
    appendFinalizeScopeToQuery(qBack)
    const finalizeBackTo = qBack.toString()
      ? `${hrBase}/compensation/finalize-payroll?${qBack.toString()}`
      : `${hrBase}/compensation/finalize-payroll`
    const navState = { payslipBackTo: finalizeBackTo }

    if (row?.payslip_id) {
      const q = new URLSearchParams()
      appendFinalizeScopeToQuery(q)
      const qs = q.toString()
      navigate(`${hrBase}/compensation/payslips/${row.payslip_id}/view${qs ? `?${qs}` : ''}`, { state: navState })
      return
    }

    if (!row?.user_id) {
      toast({
        title: 'No preview data',
        description: 'No employee context was found for this row.',
        variant: 'destructive',
      })
      return
    }

    const q = new URLSearchParams()
    q.set('employee_id', String(row.user_id))
    appendFinalizeScopeToQuery(q)
    navigate(`${hrBase}/compensation/payslips/preview/view?${q.toString()}`, { state: navState })
  }

  const buildDeliverPayload = useCallback(
    (payslipIds) => {
      const body = { payslip_ids: payslipIds }
      if (effectivePayload.company_id) body.company_id = effectivePayload.company_id
      if (effectivePayload.branch_id) body.branch_id = effectivePayload.branch_id
      if (effectivePayload.department_id) body.department_id = effectivePayload.department_id
      return body
    },
    [effectivePayload.company_id, effectivePayload.branch_id, effectivePayload.department_id],
  )

  const handleDeliverOne = async (row) => {
    const pid = Number(row?.payslip_id ?? 0)
    if (!periodFinalized || pid <= 0) return
    const alreadySent = Boolean(row?.is_sent || row?.delivered_at)
    setDeliveringPayslipId(pid)
    try {
      await adminDeliverFinalizePayslips(buildDeliverPayload([pid]))
      toastRef.current({
        title: alreadySent ? 'Payslip re-sent' : 'Payslip sent',
        description: 'Employees can open this payslip from My Payslips.',
      })
      setRefreshToken(String(Date.now()))
    } catch (e) {
      toastRef.current({ title: 'Release failed', description: e.message, variant: 'destructive' })
    } finally {
      setDeliveringPayslipId(null)
    }
  }

  const handleDeliverBulk = async () => {
    if (!periodFinalized || selectedPayslipIds.size === 0) return
    setDeliveringBulk(true)
    try {
      await adminDeliverFinalizePayslips(buildDeliverPayload(Array.from(selectedPayslipIds)))
      toastRef.current({
        title: 'Payslips sent',
        description: `${selectedPayslipIds.size} payslip(s) are now visible in My Payslips.`,
      })
      setSelectedPayslipIds(new Set())
      setRefreshToken(String(Date.now()))
    } catch (e) {
      toastRef.current({ title: 'Release failed', description: e.message, variant: 'destructive' })
    } finally {
      setDeliveringBulk(false)
    }
  }

  const handleDeleteFinalizedBatch = async () => {
    const batchRunId = Number(preview?.batch_run?.payroll_batch_run_id || 0)
    if (batchRunId <= 0 || deletingBatch) return
    setDeletingBatch(true)
    try {
      await adminDeleteFinalizedPayrollBatch(batchRunId)
      setDeleteDialogOpen(false)
      setDone(false)
      setLocalFinalizeLocked(false)
      setSelectedPayslipIds(new Set())
      setRefreshToken(String(Date.now()))
      toastRef.current({
        title: 'Finalized batch voided',
        description: 'The finalized payroll record was voided. Snapshot values were preserved and the batch was not converted back to draft.',
      })
    } catch (e) {
      toastRef.current({
        title: 'Delete failed',
        description: e.message || 'Could not delete finalized batch.',
        variant: 'destructive',
      })
    } finally {
      setDeletingBatch(false)
    }
  }

  const handleBulkDownloadFinalizedZip = async () => {
    const batchRunId = Number(preview?.batch_run?.payroll_batch_run_id || 0)
    if (batchRunId <= 0 || bulkDownloadingZip || !batchRunStatusFinalized || !canBulkDownloadPayslipZip) return

    bulkDownloadAbortRef.current?.abort()
    const abort = new AbortController()
    bulkDownloadAbortRef.current = abort

    setBulkDownloadingZip(true)
    setBulkDownloadProgress({ status: 'pending', progress_percent: 0 })
    try {
      const queued = await adminQueueBulkPayslipDownload(batchRunId)
      toastRef.current({
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
        `Payslips_${String(preview?.totals?.company_name || selectedCompany?.name || 'batch').replace(/[^\w-]+/g, '_')}.zip`
      saveBulkPayslipZipBlob(blob, filename)
      toastRef.current({
        title: 'Bulk payslip download is ready.',
        description: 'Your ZIP download has started.',
      })
    } catch (e) {
      if (e?.name === 'AbortError') return
      toastRef.current({
        title: 'Bulk payslip download failed',
        description: e.message || 'Bulk payslip download failed. Please try again.',
        variant: 'destructive',
      })
    } finally {
      setBulkDownloadingZip(false)
      setBulkDownloadProgress(null)
      if (bulkDownloadAbortRef.current === abort) {
        bulkDownloadAbortRef.current = null
      }
    }
  }

  const handleDownloadPayrollReportPdf = async () => {
    const batchRunId = Number(preview?.batch_run?.payroll_batch_run_id || 0)
    const companyId = Number(effectivePayload.company_id || preview?.batch_run?.company_id || selectedCompany?.id || 0)
    if (batchRunId <= 0 || companyId <= 0 || payrollReportDownloading || !batchRunStatusFinalized || !canBulkDownloadPayslipZip) return

    setPayrollReportDownloading(true)
    try {
      const blob = await getPayrollRunCompanyPayrollReportPdfBlob(batchRunId, companyId)
      const companyName = String(selectedCompany?.name || preview?.totals?.company_name || 'company').replace(/[^\w-]+/g, '_')
      savePdfBlob(blob, `Payroll_Report_${companyName}_Run_${batchRunId}.pdf`)
      toastRef.current({ title: 'Payroll Report PDF downloaded', description: 'Your report download has started.' })
    } catch (e) {
      toastRef.current({
        title: 'Payroll Report PDF failed',
        description: e.message || 'Could not download Payroll Report PDF.',
        variant: 'destructive',
      })
    } finally {
      setPayrollReportDownloading(false)
    }
  }

  const togglePayslipSelected = (id) => {
    setSelectedPayslipIds((prev) => {
      const next = new Set(prev)
      if (next.has(id)) next.delete(id)
      else next.add(id)
      return next
    })
  }

  const selectableOnPage = useMemo(
    () => pageRows.filter((r) => isSendablePayslipStatus(r.status) && Number(r.payslip_id) > 0),
    [pageRows],
  )

  const allPageSelected =
    selectableOnPage.length > 0 && selectableOnPage.every((r) => selectedPayslipIds.has(Number(r.payslip_id)))

  const toggleSelectAllPage = () => {
    setSelectedPayslipIds((prev) => {
      const next = new Set(prev)
      if (allPageSelected) {
        selectableOnPage.forEach((r) => next.delete(Number(r.payslip_id)))
      } else {
        selectableOnPage.forEach((r) => next.add(Number(r.payslip_id)))
      }
      return next
    })
  }

  const finalizedBatchEmployeeCount = useMemo(
    () =>
      Math.max(
        Number(totals?.employee_count || 0),
        Number(preview?.batch_run?.employee_count || 0),
        Number(preview?.pagination?.total || 0),
      ),
    [totals?.employee_count, preview?.batch_run?.employee_count, preview?.pagination?.total],
  )

  const handleBulkSendBatchPayslips = async () => {
    const batchRunId = Number(preview?.batch_run?.payroll_batch_run_id || 0)
    if (!periodFinalized || batchRunId <= 0 || sendingBatchPayslips) return
    setSendingBatchPayslips(true)
    try {
      const result = await adminBulkSendFinalizedBatchPayslips(batchRunId)
      setSendBatchDialogOpen(false)
      setSelectedPayslipIds(new Set())
      setRefreshToken(String(Date.now()))
      toastRef.current({
        title: 'Batch payslips sent',
        description: `${Number(result?.delivered || 0)} of ${Number(result?.targeted || finalizedBatchEmployeeCount || 0)} employees were sent.`,
      })
    } catch (e) {
      toastRef.current({
        title: 'Bulk send failed',
        description: e.message || 'Could not send payslips for this finalized batch.',
        variant: 'destructive',
      })
    } finally {
      setSendingBatchPayslips(false)
    }
  }

  useEffect(
    () => () => {
      if (tickRef.current) clearInterval(tickRef.current)
      if (pollRef.current) clearInterval(pollRef.current)
    },
    []
  )

  if (!canFinalizePayroll) {
    return (
      <div className="mx-auto w-full max-w-lg px-4 py-10">
        <Card className={CARD}>
          <CardContent className="p-6">
            <h2 className={cn('text-xl font-semibold', TEXT)}>Finalize payroll</h2>
            <p className="mt-2 text-sm text-muted-foreground">You do not have permission to finalize payroll runs.</p>
          </CardContent>
        </Card>
      </div>
    )
  }

  if (isAdmin && !hasScope(effectivePayload)) {
    return (
      <div className="mx-auto w-full max-w-xl space-y-6 px-4 py-10">
        <Button variant="ghost" size="sm" className="gap-2" asChild>
          <Link to={`${hrBase}/compensation/generate-payslips`}>
            <ArrowLeft className="h-4 w-4" />
            Back to Generate Payslips
          </Link>
        </Button>
        <Card className={CARD}>
          <CardContent className="p-6">
            <h2 className={cn('text-xl font-semibold', TEXT)}>No batch selected</h2>
            <p className="mt-2 text-sm text-muted-foreground">
              Start from <span className="font-medium text-foreground">Generate Payslips</span>, set your filters, then click
              "Generate payslips" to review and finalize here.
            </p>
          </CardContent>
        </Card>
      </div>
    )
  }

  return (
    <TooltipProvider>
      <div className={cn(PAYSLIP_MODULE_SHELL, PAYSLIP_STACK)}>
        <div className="flex flex-wrap items-center justify-between gap-2">
          <Button variant="outline" size="sm" className="h-10 w-fit gap-2 rounded-xl border-border/80 bg-card font-semibold shadow-sm hover:bg-muted" asChild>
            <Link to={`${hrBase}/compensation/generate-payslips`}>
              <ArrowLeft className="h-4 w-4" />
              Back
            </Link>
          </Button>
          <div className="flex flex-wrap items-center justify-end gap-2">
            {batchRunStatusFinalized &&
              Number(preview?.batch_run?.payroll_batch_run_id || 0) > 0 &&
              canBulkDownloadPayslipZip && (
                <Button
                  type="button"
                  variant="outline"
                  size="sm"
                  className="h-10 gap-2 rounded-xl border-border/80 bg-card font-semibold shadow-sm hover:bg-muted"
                  disabled={bulkDownloadingZip || loading}
                  onClick={handleBulkDownloadFinalizedZip}
                >
                  {bulkDownloadingZip ? (
                    <Loader2 className="h-4 w-4 animate-spin" />
                  ) : (
                    <FileDown className="h-4 w-4" />
                  )}
                  {bulkDownloadingZip && bulkDownloadProgress
                    ? `${bulkPayslipDownloadStatusLabel(bulkDownloadProgress)}${
                        bulkDownloadProgress.progress_percent != null
                          ? ` (${bulkDownloadProgress.progress_percent}%)`
                          : ''
                      }`
                    : 'Bulk Download PDF'}
                </Button>
              )}
            {batchRunStatusFinalized &&
              Number(preview?.batch_run?.payroll_batch_run_id || 0) > 0 &&
              canBulkDownloadPayslipZip && (
                <Button
                  type="button"
                  variant="outline"
                  size="sm"
                  className="h-10 gap-2 rounded-xl border-border/80 bg-card font-semibold shadow-sm hover:bg-muted"
                  disabled={payrollReportDownloading || loading}
                  onClick={handleDownloadPayrollReportPdf}
                >
                  {payrollReportDownloading ? (
                    <Loader2 className="h-4 w-4 animate-spin" />
                  ) : (
                    <FileText className="h-4 w-4" />
                  )}
                  Payroll Report PDF
                </Button>
              )}
            <Button
              type="button"
              variant="outline"
              size="sm"
              className="h-10 gap-2 rounded-xl border-border/80 bg-card font-semibold shadow-sm hover:bg-muted"
              onClick={() => setRefreshToken(String(Date.now()))}
              disabled={loading || periodFinalized}
              title={periodFinalized ? 'Preview refresh is disabled while this payroll is locked.' : undefined}
            >
              <RefreshCw className={cn('h-4 w-4', loading ? 'animate-spin' : '')} />
              Refresh calculation
            </Button>
          </div>
        </div>

        <div className={cn(CARD, 'p-5 md:p-6')}>
          <div className="flex flex-col gap-5 @md:flex-row @md:items-start @md:justify-between">
            <div className="space-y-2">
              <h1 className={cn('text-[28px] font-extrabold leading-tight tracking-normal @md:text-[32px]', TEXT)}>Finalize Payroll</h1>
              <p className="max-w-3xl text-[15px] font-medium leading-7 text-muted-foreground">
                Review totals from the same payroll engine as pay components, deductions, statutory lines, pay cycles, schedules, and
                daily computation — then finalize to persist payroll periods, generate PDF payslips, and lock the run.
              </p>
            </div>
            <div
              className={cn(
                'flex w-fit items-center gap-3 rounded-xl border px-4 py-3 shadow-sm',
                periodFinalized ? 'border-brand/30 bg-brand/10' : 'border-brand/25 bg-brand/10',
              )}
            >
              <div>
                <p className="text-xs font-extrabold uppercase tracking-normal text-brand">
                  {periodFinalized ? 'Status: Finalized' : draftProcessing ? `Status: ${batchRunStatus === 'queued' ? 'Pending' : 'Processing'}` : 'Status: Draft'}
                </p>
                <p className="text-xs text-muted-foreground">
                  {periodFinalized ? 'Locked - read only' : draftProcessing ? 'Generating payroll draft…' : 'Editable before finalize'}
                </p>
              </div>
              <Lock className="h-5 w-5 text-brand" />
            </div>
          </div>

          <div className="mt-5 inline-flex items-center gap-3 rounded-xl border border-border/80 bg-background px-3 py-2 shadow-sm dark:bg-input/35">
            {selectedCompanyLogo ? (
              <img src={selectedCompanyLogo} alt="" className="h-10 w-10 rounded-md border border-border/80 bg-background object-contain" />
            ) : (
              <span className="inline-flex h-10 w-10 items-center justify-center rounded-md border border-border/80 bg-muted">
                <Building2 className="h-5 w-5 text-muted-foreground" />
              </span>
            )}
            <div className="min-w-0">
              <p className="text-[11px] font-semibold uppercase tracking-normal text-muted-foreground">Company</p>
              <p className={cn('truncate font-semibold', TEXT)}>
                {selectedCompany?.name || (user?.hr_role === 'company_head' ? `${user?.company_name || 'Company'} (Company-wide)` : 'All selected scope')}
              </p>
            </div>
          </div>

          {periodPreview ? (
            <div className="mt-4 grid grid-cols-1 gap-3 text-sm @md:grid-cols-3">
              <div className="rounded-xl border border-border/80 bg-background px-4 py-3 text-muted-foreground dark:bg-input/30">
                <span className="font-semibold text-foreground">Pay Cycle:</span> {periodPreview?.pay_cycle_name || periodPreview?.cycle_label || '—'}
                {periodPreview?.pay_cycle_source_label ? (
                  <div className="mt-1 text-[11px] text-muted-foreground">{periodPreview.pay_cycle_source_label}</div>
                ) : null}
              </div>
              <div className="rounded-xl border border-border/80 bg-background px-4 py-3 text-muted-foreground dark:bg-input/30">
                <span className="font-semibold text-foreground">Next Cut-off:</span> {formatDate(periodPreview?.next_cut_off_start || periodPreview?.cut_off_start_date)} - {formatDate(periodPreview?.next_cut_off_end || periodPreview?.cut_off_end_date)}
              </div>
              <div className="rounded-xl border border-border/80 bg-background px-4 py-3 text-muted-foreground dark:bg-input/30">
                <span className="font-semibold text-foreground">Pay Date:</span> {formatDate(periodPreview?.next_pay_date || periodPreview?.pay_date)}
              </div>
            </div>
          ) : null}

          {!periodFinalized ? (
            <p className="mt-4 flex items-start gap-2 rounded-xl border border-brand/20 bg-brand/10 px-4 py-3 text-sm text-muted-foreground">
              <Info className="mt-0.5 h-4 w-4 shrink-0 text-brand" />
              <span>
                Figures below are <span className="font-bold text-brand">computed</span> for review only. PDFs are created after
                you confirm and click Finalize Payroll.
              </span>
            </p>
          ) : (
            <div className="mt-4 space-y-3">
              <p className="flex items-start gap-2 rounded-xl border border-brand/30 bg-brand/10 px-4 py-3 text-sm text-foreground">
                <Lock className="mt-0.5 h-4 w-4 shrink-0 text-brand" aria-hidden />
                <span>
                  <span className="font-semibold text-brand">This payroll has been finalized and is now locked.</span> Use{' '}
                  <span className="font-medium text-brand">Send payslips</span> for each finalized row (or bulk) so they appear in My Payslips.
                  You cannot change attendance, leave, or amounts for this period.
                </span>
              </p>
              {Number(preview?.batch_run?.payroll_batch_run_id || 0) > 0 ? (
                <div className="flex justify-end">
                  <Button
                    type="button"
                    variant="destructive"
                    className="h-9 gap-2"
                    onClick={() => setDeleteDialogOpen(true)}
                    disabled={deletingBatch}
                  >
                    {deletingBatch ? <Loader2 className="h-4 w-4 animate-spin" /> : <Trash2 className="h-4 w-4" />}
                    Void Finalized Batch
                  </Button>
                </div>
              ) : null}
            </div>
          )}

          {!loading && previewError ? (
            <div className="mt-4 rounded-xl border border-destructive/30 bg-destructive/10 px-4 py-3 text-sm text-destructive">
              {previewError}
            </div>
          ) : null}
        </div>

        <div className="grid grid-cols-1 gap-4 @lg:grid-cols-4">
          <Card className={cn(CARD, 'overflow-hidden')}>
            <CardContent className="p-5">
              <p className="text-[13px] font-semibold uppercase tracking-normal text-muted-foreground">Total gross pay</p>
              <div className="mt-3 flex items-center justify-between">
                {loading ? (
                  <div className="h-7 w-32 rounded bg-muted animate-pulse" />
                ) : (
                  <p className={cn('text-[26px] font-extrabold tabular-nums leading-none', TEXT)}>
                    {formatPeso(totals.total_gross)}
                  </p>
                )}
                <ArrowUpRight className="h-6 w-6 text-brand" />
              </div>
            </CardContent>
          </Card>

          <Card className={cn(CARD, 'overflow-hidden')}>
            <CardContent className="p-5">
              <p className="text-[13px] font-semibold uppercase tracking-normal text-muted-foreground">Total deductions</p>
              <div className="mt-3 flex items-center justify-between">
                {loading ? (
                  <div className="h-7 w-32 rounded bg-muted animate-pulse" />
                ) : (
                  <p className={cn('text-[26px] font-extrabold tabular-nums leading-none', TEXT)}>
                    {formatPeso(totals.total_deductions)}
                  </p>
                )}
                <ArrowUpRight className="h-6 w-6 text-brand" />
              </div>
            </CardContent>
          </Card>

          <Card className={cn(CARD, 'overflow-hidden border-brand/30 bg-brand/10 shadow-[0_8px_20px_rgba(249,115,22,0.12)] dark:bg-brand/15')}>
            <CardContent className="p-5">
              <p className="text-[13px] font-bold uppercase tracking-normal text-brand">Total net pay</p>
              <div className="mt-3 flex items-center justify-between">
                {loading ? (
                  <div className="h-8 w-36 rounded bg-brand/20 animate-pulse" />
                ) : (
                  <p className={cn('text-[30px] font-extrabold tabular-nums leading-none tracking-normal @md:text-[32px]', 'text-brand')}>
                    {formatPeso(totals.total_net)}
                  </p>
                )}
                <PhilippinePeso className="h-6 w-6 text-brand" />
              </div>
            </CardContent>
          </Card>

          <Card className={cn(CARD, 'overflow-hidden')}>
            <CardContent className="p-5">
              <p className="text-[13px] font-semibold uppercase tracking-normal text-muted-foreground">Employee count</p>
              <div className="mt-3 flex items-center justify-between">
                {loading ? (
                  <div className="h-7 w-12 rounded bg-muted animate-pulse" />
                ) : (
                  <p className={cn('text-[28px] font-extrabold tabular-nums leading-none', TEXT)}>{Number(totals.employee_count || 0)}</p>
                )}
                <div className="flex items-center -space-x-2">
                  {employees.slice(0, 3).map((row) => (
                    <Avatar key={row.user_id} className="h-8 w-8 border-2 border-background ring-1 ring-border/60">
                      <AvatarImage src={employeeAvatarSrc(row) || undefined} alt="" />
                      <AvatarFallback className="text-[10px] font-semibold">{initials(payrollEmployeeDisplayName(row))}</AvatarFallback>
                    </Avatar>
                  ))}
                  <span className="ml-2 inline-flex h-8 w-8 items-center justify-center rounded-full bg-muted text-xs font-semibold text-muted-foreground">
                    <Users className="h-4 w-4" />
                  </span>
                </div>
              </div>
            </CardContent>
          </Card>
        </div>

        <Card className={cn(CARD, 'p-0')}>
          <CardContent className="p-0">
            <div className="flex flex-col gap-3 px-4 py-4 @md:flex-row @md:items-center @md:justify-between @md:px-5">
              <div>
                <h2 className={cn('text-[22px] font-bold leading-snug @md:text-2xl', TEXT)}>Employees</h2>
                <p className="mt-1 text-sm font-normal leading-snug text-muted-foreground">
                  View full payslip details on Draft. Finalize only locks the period and publishes PDFs.
                </p>
              </div>
              <div className="flex w-full max-w-md items-center gap-2">
                <Search className="h-4 w-4 shrink-0 text-muted-foreground" />
                <Input
                  value={search}
                  onChange={(e) => setSearch(e.target.value)}
                  placeholder="Search employee…"
                  disabled={periodFinalized}
                  className="h-10 rounded-xl border-border/80 bg-background text-foreground dark:bg-input/35"
                />
              </div>
            </div>

            {periodFinalized ? (
              <div className="flex flex-col gap-3 border-t border-border/70 px-4 py-3 @md:flex-row @md:flex-wrap @md:items-center @md:justify-end @md:px-5">
                {Number(preview?.batch_run?.payroll_batch_run_id || 0) > 0 ? (
                  <Button
                    type="button"
                    disabled={sendingBatchPayslips || loading}
                    className="h-9 gap-2 rounded-xl bg-brand font-semibold text-brand-foreground shadow-sm hover:bg-brand-strong disabled:bg-brand/40"
                    onClick={() => setSendBatchDialogOpen(true)}
                  >
                    {sendingBatchPayslips ? <Loader2 className="h-4 w-4 animate-spin" /> : <Send className="h-4 w-4" />}
                    Bulk Send Payslip ({finalizedBatchEmployeeCount})
                  </Button>
                ) : null}
                <Button
                  type="button"
                  disabled={deliveringBulk || selectedPayslipIds.size === 0}
                  className="h-9 gap-2 rounded-xl bg-brand font-semibold text-brand-foreground shadow-sm hover:bg-brand-strong disabled:bg-brand/40"
                  onClick={() => handleDeliverBulk()}
                >
                  {deliveringBulk ? <Loader2 className="h-4 w-4 animate-spin" /> : <Send className="h-4 w-4" />}
                  Send payslips{selectedPayslipIds.size > 0 ? ` (${selectedPayslipIds.size})` : ''}
                </Button>
              </div>
            ) : null}

            {loading ? (
              <div className="w-full px-0 pb-4 pt-1">
                <div className="w-full overflow-x-auto">
                  <Table className="min-w-[1020px] border-0 [&_td]:border-0 [&_th]:border-0">
                    <TableHeader className="[&_tr]:border-0">
                      <TableRow className="border-0 bg-background hover:bg-background dark:bg-input/25 dark:hover:bg-input/25">
                        <TableHead className="h-auto py-2.5 pl-4 pr-2 text-left text-[12px] font-bold uppercase tracking-normal text-muted-foreground first:pl-5">Employee</TableHead>
                        <TableHead className="h-auto py-2.5 px-2 text-left text-[12px] font-bold uppercase tracking-normal text-muted-foreground">Department</TableHead>
                        <TableHead className="h-auto py-2.5 px-2 text-right text-[12px] font-bold uppercase tracking-normal text-muted-foreground">Basic Salary</TableHead>
                        <TableHead className="h-auto py-2.5 px-2 text-right text-[12px] font-bold uppercase tracking-normal text-muted-foreground">Daily Rate</TableHead>
                        <TableHead className="h-auto py-2.5 px-2 text-right text-[12px] font-bold uppercase tracking-normal text-muted-foreground">Gross Pay</TableHead>
                        <TableHead className="h-auto py-2.5 px-2 text-right text-[12px] font-bold uppercase tracking-normal text-muted-foreground">Total Deductions</TableHead>
                        <TableHead className="h-auto py-2.5 px-2 text-right text-[12px] font-bold uppercase tracking-normal text-brand">Net pay</TableHead>
                        <TableHead className="h-auto py-2.5 px-2 text-left text-[12px] font-bold uppercase tracking-normal text-muted-foreground">Status</TableHead>
                        <TableHead className="h-auto min-w-[220px] py-2.5 pl-2 pr-4 text-right text-[12px] font-bold uppercase tracking-normal text-muted-foreground @md:pr-5">Actions</TableHead>
                      </TableRow>
                    </TableHeader>
                    <TableBody className="[&_tr]:border-0">
                      {Array.from({ length: pageSize }, (_, i) => (
                        <TableRow key={`skel-${i}`} className="border-0">
                          <TableCell className="py-3 pl-5 pr-2"><div className="flex items-center gap-2.5"><div className="h-9 w-9 rounded-full bg-muted animate-pulse" /><div className="space-y-1.5"><div className="h-4 w-28 rounded bg-muted animate-pulse" /><div className="h-3 w-20 rounded bg-muted animate-pulse" /></div></div></TableCell>
                          <TableCell className="py-3 px-2"><div className="h-4 w-20 rounded bg-muted animate-pulse" /></TableCell>
                          <TableCell className="py-3 px-2 text-right"><div className="ml-auto h-4 w-16 rounded bg-muted animate-pulse" /></TableCell>
                          <TableCell className="py-3 px-2 text-right"><div className="ml-auto h-4 w-14 rounded bg-muted animate-pulse" /></TableCell>
                          <TableCell className="py-3 px-2 text-right"><div className="ml-auto h-4 w-16 rounded bg-muted animate-pulse" /></TableCell>
                          <TableCell className="py-3 px-2 text-right"><div className="ml-auto h-4 w-16 rounded bg-muted animate-pulse" /></TableCell>
                          <TableCell className="py-3 px-2 text-right"><div className="ml-auto h-4 w-16 rounded bg-muted animate-pulse" /></TableCell>
                          <TableCell className="py-3 px-2"><div className="h-5 w-14 rounded-full bg-muted animate-pulse" /></TableCell>
                          <TableCell className="py-3 pl-2 pr-5 text-right"><div className="ml-auto h-7 w-20 rounded-lg bg-muted animate-pulse" /></TableCell>
                        </TableRow>
                      ))}
                    </TableBody>
                  </Table>
                </div>
              </div>
            ) : Number(pagination.total || 0) === 0 || (draftProcessing && pageRows.length === 0) ? (
              <div className="px-4 py-10 text-center text-sm text-muted-foreground @md:px-5">
                {draftProcessing
                  ? 'No employee rows are available yet.'
                  : 'No employees found in the selected scope.'}
              </div>
            ) : (
              <div className="w-full px-0 pb-4 pt-1">
                <div className="w-full overflow-x-auto">
                  <Table className="min-w-[1020px] border-0 [&_td]:border-0 [&_th]:border-0">
                    <TableHeader className="[&_tr]:border-0">
                      <TableRow className="border-0 bg-background hover:bg-background dark:bg-input/25 dark:hover:bg-input/25">
                        {periodFinalized ? (
                          <TableHead className="h-auto w-10 py-2.5 pl-4 pr-0 text-left first:pl-5">
                            <Checkbox
                              checked={allPageSelected}
                              onCheckedChange={() => toggleSelectAllPage()}
                              disabled={selectableOnPage.length === 0}
                              aria-label="Select all on page"
                            />
                          </TableHead>
                        ) : null}
                        <TableHead className="h-auto py-2.5 pl-4 pr-2 text-left text-[12px] font-bold uppercase tracking-normal text-muted-foreground first:pl-5">
                          Employee
                        </TableHead>
                        <TableHead className="h-auto py-2.5 px-2 text-left text-[12px] font-bold uppercase tracking-normal text-muted-foreground">Department</TableHead>
                        <TableHead className="h-auto py-2.5 px-2 text-right text-[12px] font-bold uppercase tracking-normal text-muted-foreground">Basic Salary (Period)</TableHead>
                        <TableHead className="h-auto py-2.5 px-2 text-right text-[12px] font-bold uppercase tracking-normal text-muted-foreground">Daily Rate</TableHead>
                        <TableHead className="h-auto py-2.5 px-2 text-right text-[12px] font-bold uppercase tracking-normal text-muted-foreground">Gross Pay</TableHead>
                        <TableHead className="h-auto py-2.5 px-2 text-right text-[12px] font-bold uppercase tracking-normal text-muted-foreground">Total Deductions</TableHead>
                        <TableHead className="h-auto py-2.5 px-2 text-right text-[12px] font-bold uppercase tracking-normal text-brand">Net pay</TableHead>
                        <TableHead className="h-auto py-2.5 px-2 text-left text-[12px] font-bold uppercase tracking-normal text-muted-foreground">Status</TableHead>
                        <TableHead className="h-auto min-w-[220px] py-2.5 pl-2 pr-4 text-right text-[12px] font-bold uppercase tracking-normal text-muted-foreground @md:pr-5">
                          Actions
                        </TableHead>
                      </TableRow>
                    </TableHeader>
                    <TableBody className="[&_tr]:border-0 [&_tr]:transition-colors">
                      {pageRows.map((row) => {
                        const rowFinalized = isPayslipPublishedDone(row.status)
                        const payslipIdNum = Number(row.payslip_id ?? 0)
                        const canSendRow = periodFinalized && payslipIdNum > 0 && isSendablePayslipStatus(row.status)
                        const rowIsSent = Boolean(row.is_sent || row.delivered_at)

                        return (
                        <TableRow
                          key={`${row.payslip_id ?? `preview-${row.user_id}`}`}
                          className="border-0 transition-colors duration-150 hover:bg-muted/35"
                        >
                          {periodFinalized ? (
                            <TableCell className="w-10 py-2.5 pl-4 pr-0 align-middle first:pl-5">
                              {canSendRow ? (
                                <Checkbox
                                  checked={selectedPayslipIds.has(payslipIdNum)}
                                  onCheckedChange={() => togglePayslipSelected(payslipIdNum)}
                                  aria-label={`Select payslip ${payslipIdNum}`}
                                />
                              ) : (
                                <span className="inline-block w-4" />
                              )}
                            </TableCell>
                          ) : null}
                          <TableCell className="py-2.5 pl-4 pr-2 align-middle first:pl-5">
                            <div className="flex items-start gap-2.5">
                              <Avatar className="h-9 w-9 ring-1 ring-border/80">
                                <AvatarImage src={employeeAvatarSrc(row) || undefined} alt="" />
                                <AvatarFallback className="text-[10px] font-semibold">{initials(payrollEmployeeDisplayName(row))}</AvatarFallback>
                              </Avatar>
                              <div className="min-w-0">
                                <p className={cn('truncate text-base font-bold', TEXT)}>{payrollEmployeeDisplayName(row)}</p>
                                <div className="mt-0.5 flex flex-wrap items-center gap-2">
                                  <span className={cn('inline-flex rounded-full px-2 py-0.5 text-[10px] font-medium', roleBadgeMeta(employeeCompanyPosition(row)).className)}>
                                    {row?.employee_role_label || roleBadgeMeta(employeeCompanyPosition(row)).label}
                                  </span>
                                  <span className="text-[11px] text-muted-foreground">{row.employee_code}</span>
                                  {periodFinalized && canSendRow && rowIsSent ? (
                                    <Badge variant="secondary" className="h-5 rounded-md border-brand/30 bg-brand/10 px-2 text-[10px] font-semibold uppercase tracking-normal text-brand">
                                      Sent
                                    </Badge>
                                  ) : null}
                                </div>
                                {employeeRole(row) && employeeRole(row) !== '—' ? (
                                  <p className="mt-0.5 text-[15px] font-normal leading-snug text-muted-foreground">{employeeRole(row)}</p>
                                ) : null}
                              </div>
                            </div>
                          </TableCell>
                          <TableCell className="py-2.5 px-2 text-[15px] font-normal text-muted-foreground">{row.department || '—'}</TableCell>
                          <TableCell className="py-2.5 px-2 text-right text-sm font-medium tabular-nums text-foreground/80">
                            {formatPeso(row.basic_salary)}
                            {Number(row.basic_salary_monthly || 0) > 0 ? (
                              <div className="mt-1 text-[10px] text-muted-foreground">
                                Monthly: {formatPeso(row.basic_salary_monthly)}
                              </div>
                            ) : null}
                          </TableCell>
                          <TableCell className="py-2.5 px-2 text-right text-sm font-medium tabular-nums text-foreground/80">
                            {formatPeso(row.daily_rate)}
                          </TableCell>
                          <TableCell className="py-2.5 px-2 text-right align-middle">
                            <div className="flex flex-col items-end gap-1">
                              <span className="text-[15px] font-semibold tabular-nums text-foreground">
                                {formatPeso(row.gross_pay)}
                              </span>
                              <div className="flex items-center justify-end gap-2">
                                <button
                                  type="button"
                                  className="text-xs font-semibold text-muted-foreground underline underline-offset-4 hover:text-foreground"
                                  onClick={() => setBreakdownRow(row)}
                                >
                                  View breakdown
                                </button>
                              </div>
                              {Number(row.actual_days_worked || 0) <= 0 && Number(row.basic_salary || 0) > 0 ? (
                                <div className="text-[11px] font-medium leading-tight text-amber-700 dark:text-amber-300">No attendance in selected period</div>
                              ) : null}
                            </div>
                          </TableCell>
                          <TableCell
                            className={cn(
                              'py-2.5 px-2 text-right text-base font-medium tabular-nums',
                              Number(row.total_deductions || 0) > 0 ? 'text-brand' : 'text-muted-foreground',
                            )}
                          >
                            {formatPeso(row.total_deductions)}
                          </TableCell>
                          <TableCell className="py-2.5 px-2 text-right text-base font-semibold tabular-nums text-foreground">
                            {formatPeso(row.net_pay)}
                          </TableCell>
                          <TableCell className="py-2.5 px-2">
                            {rowFinalized ? statusPill('Finalized', 'green') : statusPill('Draft', 'gray')}
                          </TableCell>
                          <TableCell className="min-w-[220px] max-w-[min(100%,28rem)] py-2.5 pl-2 pr-4 text-right align-middle @md:pr-5">
                            <div className="-mx-1 flex flex-row flex-nowrap items-center justify-end gap-1.5 overflow-x-auto overflow-y-visible px-1 py-0.5 [-webkit-overflow-scrolling:touch] sm:gap-2 sm:overflow-visible">
                              {periodFinalized && canSendRow ? (
                                <Button
                                  type="button"
                                  variant={rowIsSent ? 'outline' : 'default'}
                                  size="sm"
                                  className={cn(
                                    'h-8 shrink-0 gap-1.5 whitespace-nowrap rounded-lg px-3 text-xs font-semibold @sm:text-sm',
                                    rowIsSent
                                      ? 'border-brand/30 text-brand hover:bg-brand/10'
                                      : 'border-transparent bg-brand text-brand-foreground hover:bg-brand-strong',
                                  )}
                                  disabled={deliveringPayslipId === payslipIdNum}
                                  onClick={() => handleDeliverOne(row)}
                                >
                                  {deliveringPayslipId === payslipIdNum ? (
                                    <Loader2 className="h-3.5 w-3.5 shrink-0 animate-spin @sm:h-4 @sm:w-4" />
                                  ) : rowIsSent ? (
                                    <RefreshCw className="h-3.5 w-3.5 shrink-0 @sm:h-4 @sm:w-4" />
                                  ) : (
                                    <Send className="h-3.5 w-3.5 shrink-0 @sm:h-4 @sm:w-4" />
                                  )}
                                  {rowIsSent ? 'Resend' : 'Send'}
                                </Button>
                              ) : null}
                              <Button
                                type="button"
                                size="sm"
                                variant="outline"
                                className="h-8 shrink-0 gap-1.5 whitespace-nowrap rounded-lg border-border/80 bg-background px-3 text-xs font-semibold text-foreground hover:bg-muted @sm:text-sm dark:bg-input/35"
                                onClick={() => handlePreviewPayslip(row)}
                              >
                                <Eye className="h-3.5 w-3.5 shrink-0 @sm:h-4 @sm:w-4" />
                                View
                              </Button>
                            </div>
                          </TableCell>
                        </TableRow>
                        )
                      })}
                    </TableBody>
                  </Table>
                </div>

                <div className="mt-3 flex flex-col gap-2 px-4 text-sm text-muted-foreground @sm:flex-row @sm:items-center @sm:justify-between @sm:px-5">
                  <span>
                    Page {page} of {pageCount} · {Number(pagination.total || 0)} employee{Number(pagination.total || 0) === 1 ? '' : 's'}
                  </span>
                  <div className="flex gap-2">
                    <Button type="button" variant="outline" size="sm" className="rounded-xl border-border/80 bg-background hover:bg-muted dark:bg-input/35" onClick={() => setPage((p) => Math.max(1, p - 1))} disabled={page <= 1}>
                      Prev
                    </Button>
                    <Button type="button" variant="outline" size="sm" className="rounded-xl border-border/80 bg-background hover:bg-muted dark:bg-input/35" onClick={() => setPage((p) => Math.min(pageCount, p + 1))} disabled={page >= pageCount}>
                      Next
                    </Button>
                  </div>
                </div>
              </div>
            )}
          </CardContent>
        </Card>

        <Card className={cn(CARD, 'sticky bottom-3')}>
          <CardContent className="py-6">
            {periodFinalized ? (
              <div className="flex flex-wrap items-center justify-center gap-2 rounded-xl border border-brand/30 bg-brand/10 px-4 py-3 text-center">
                <CheckCircle2 className="h-4 w-4 text-brand" aria-hidden />
                <span className="text-sm font-semibold text-foreground">Payroll Finalized</span>
                <span className="text-sm text-muted-foreground">This payroll batch is locked and no further confirmation is required.</span>
              </div>
            ) : (
              <div className="flex flex-col gap-6 @md:flex-row @md:items-center @md:justify-between">
                <div className="flex items-start gap-3">
                  <Checkbox
                    id="review"
                    checked={reviewConfirmed}
                    disabled={finalizing}
                    onCheckedChange={(v) => setReviewConfirmed(v === true)}
                    className="mt-1"
                  />
                  <div>
                    <Label htmlFor="review" className={cn('cursor-pointer text-base font-bold leading-snug', TEXT)}>
                      I confirm I have reviewed all totals and employee details for this payroll batch.
                    </Label>
                    <p className="mt-1.5 text-sm font-normal text-muted-foreground">Gross pay verified • Deductions correct • No open loans conflicting</p>
                  </div>
                </div>
                <Button
                  type="button"
                  size="lg"
                  disabled={!reviewConfirmed || finalizing || loading || draftProcessing || Number(totals?.employee_count || 0) <= 0}
                  onClick={handleFinalize}
                  className={cn(
                    'h-12 min-w-[260px] shrink-0 rounded-xl px-8 text-[16px] font-bold transition-transform active:scale-[0.99]',
                    'bg-brand text-brand-foreground shadow-[0_8px_20px_rgba(249,115,22,0.35)] hover:bg-brand-strong',
                    'disabled:bg-muted disabled:text-muted-foreground disabled:shadow-none',
                  )}
                >
                  {finalizing ? (
                    <>
                      <Loader2 className="mr-2 h-4 w-4 animate-spin" />
                      Finalizing payroll…
                    </>
                  ) : (
                    <>
                      <Lock className="mr-2 h-4 w-4" />
                      Finalize payroll
                    </>
                  )}
                </Button>
              </div>
            )}
          </CardContent>
        </Card>

        <Dialog open={showFinalizeModal} onOpenChange={() => {}}>
          <DialogContent showCloseButton={false} className="sm:max-w-md">
            <DialogHeader>
              <DialogTitle className={cn('text-xl font-bold', TEXT)}>{periodFinalized ? 'Payroll finalized' : 'Finalizing payroll'}</DialogTitle>
              <DialogDescription className="text-sm text-muted-foreground">
                {periodFinalized
                  ? 'All employees in this batch were finalized successfully.'
                  : `Finalizing payroll for ${Math.max(1, Number(totals?.employee_count || employees.length || 1))} employees...`}
              </DialogDescription>
            </DialogHeader>
            <div className="space-y-3 py-2">
              <Progress value={progress} indicatorClassName="bg-brand transition-all duration-300" />
              <p className="text-xs font-medium text-muted-foreground">{periodFinalized ? 'Finalized and locked' : finalizeStage}</p>
              <div className="flex items-center justify-between text-xs text-muted-foreground">
                <span>{periodFinalized ? 'Done' : `${processedCount}/${Math.max(1, Number(totals?.employee_count || employees.length || 1))}`}</span>
                <span className="font-semibold text-foreground">{progress}%</span>
              </div>
              {periodFinalized ? (
                <div className="flex items-center gap-2 rounded-lg border border-brand/30 bg-brand/10 px-3 py-2 text-sm text-brand">
                  <CheckCircle2 className="h-4 w-4" />
                  Success! Payslips and payroll locks are now in place.
                </div>
              ) : null}
            </div>
          </DialogContent>
        </Dialog>

        <Dialog
          open={breakdownRow != null}
          onOpenChange={(open) => {
            if (!open) setBreakdownRow(null)
          }}
        >
          <DialogContent className={cn(PAYSLIP_PREVIEW_DIALOG, 'max-h-[90vh]')}>
            <DialogHeader>
              <DialogTitle className={cn('text-xl font-bold', TEXT)}>Payroll breakdown</DialogTitle>
              <DialogDescription className="text-muted-foreground">
                {payrollEmployeeDisplayName(breakdownRow) ? (
                  <span className="font-medium text-foreground">{payrollEmployeeDisplayName(breakdownRow)}</span>
                ) : (
                  'Employee'
                )}{' '}
                ·{' '}
                {breakdownRow?.employee_code || '—'}
              </DialogDescription>
            </DialogHeader>

            <div className="space-y-4">
              <div className="rounded-xl border border-border/80 bg-background p-4 dark:bg-input/35">
                <div className="grid grid-cols-1 gap-3 @sm:grid-cols-3">
                  <div>
                    <p className="text-[11px] font-semibold uppercase tracking-normal text-muted-foreground">Gross pay</p>
                    <p className="mt-1 text-lg font-bold tabular-nums text-foreground">{formatPeso(breakdownRow?.gross_pay)}</p>
                  </div>
                  <div>
                    <p className="text-[11px] font-semibold uppercase tracking-normal text-muted-foreground">Total deductions</p>
                    <p className="mt-1 text-lg font-bold tabular-nums text-foreground">{formatPeso(breakdownRow?.total_deductions)}</p>
                  </div>
                  <div>
                    <p className="text-[11px] font-semibold uppercase tracking-normal text-muted-foreground">Net pay</p>
                    <p className="mt-1 text-lg font-bold tabular-nums text-brand">{formatPeso(breakdownRow?.net_pay)}</p>
                  </div>
                </div>
              </div>

              <div className="rounded-xl border border-border/80 bg-card">
                <div className="border-b border-border/70 px-4 py-3">
                  <p className="text-sm font-semibold text-foreground">Earnings (Daily Computation)</p>
                  <p className="mt-0.5 text-xs text-muted-foreground">Based on attendance, holidays, OT approvals, and policy rules.</p>
                </div>
                <div className="divide-y divide-border/70">
                  {(Array.isArray(breakdownRow?.daily_computation_earning_lines) ? breakdownRow.daily_computation_earning_lines : []).map((l, i) => (
                    <div key={`bde-${i}`} className="flex items-center justify-between gap-4 px-4 py-3">
                      <div className="min-w-0">
                        <p className="truncate text-sm font-medium text-foreground">{String(l?.label || 'Earning')}</p>
                        {l?.units ? <p className="mt-0.5 text-xs text-muted-foreground">{String(l.units)}</p> : null}
                      </div>
                      <p className="shrink-0 text-sm font-semibold tabular-nums text-foreground">{formatPeso(Number(l?.amount || 0))}</p>
                    </div>
                  ))}
                  {(Array.isArray(breakdownRow?.daily_computation_earning_lines) ? breakdownRow.daily_computation_earning_lines : []).length === 0 ? (
                    <div className="px-4 py-10 text-center text-sm text-muted-foreground">No earning lines available for this preview row.</div>
                  ) : null}
                </div>
              </div>

              <div className="flex flex-wrap items-center justify-end gap-2">
                <Button
                  type="button"
                  variant="outline"
                  className="h-9 rounded-xl border-border/80 bg-background text-foreground hover:bg-muted dark:bg-input/35"
                  onClick={() => setBreakdownRow(null)}
                >
                  Close
                </Button>
                <Button
                  type="button"
                  className="h-9 rounded-xl bg-brand text-brand-foreground hover:bg-brand-strong"
                  onClick={() => {
                    if (!breakdownRow) return
                    setBreakdownRow(null)
                    handlePreviewPayslip(breakdownRow)
                  }}
                >
                  Open payslip
                </Button>
              </div>
            </div>
          </DialogContent>
        </Dialog>

        <Dialog
          open={sendBatchDialogOpen}
          onOpenChange={(open) => {
            if (!sendingBatchPayslips) setSendBatchDialogOpen(open)
          }}
        >
          <DialogContent className="sm:max-w-md">
            <DialogHeader>
              <DialogTitle className={cn('text-xl font-bold', TEXT)}>Bulk Send Payslip</DialogTitle>
              <DialogDescription className="text-sm text-muted-foreground">
                Send payslips to all {finalizedBatchEmployeeCount} employees in this batch?
              </DialogDescription>
            </DialogHeader>
            <div className="flex justify-end gap-2 pt-2">
              <Button type="button" variant="outline" onClick={() => setSendBatchDialogOpen(false)} disabled={sendingBatchPayslips}>
                Cancel
              </Button>
              <Button type="button" onClick={() => handleBulkSendBatchPayslips()} disabled={sendingBatchPayslips || finalizedBatchEmployeeCount <= 0}>
                {sendingBatchPayslips ? <Loader2 className="mr-2 h-4 w-4 animate-spin" /> : <Send className="mr-2 h-4 w-4" />}
                Send all payslips
              </Button>
            </div>
          </DialogContent>
        </Dialog>

        <Dialog
          open={deleteDialogOpen}
          onOpenChange={(open) => {
            if (!deletingBatch) setDeleteDialogOpen(open)
          }}
        >
          <DialogContent className="sm:max-w-md">
            <DialogHeader>
              <DialogTitle className={cn('text-xl font-bold', TEXT)}>Void finalized batch?</DialogTitle>
              <DialogDescription className="text-sm text-muted-foreground">
                You are deleting a finalized payroll batch. This will void the finalized payroll record but will not convert it back to draft. Snapshot values and audit history are preserved.
              </DialogDescription>
            </DialogHeader>
            <div className="flex justify-end gap-2 pt-2">
              <Button type="button" variant="outline" onClick={() => setDeleteDialogOpen(false)} disabled={deletingBatch}>
                Cancel
              </Button>
              <Button type="button" variant="destructive" onClick={() => handleDeleteFinalizedBatch()} disabled={deletingBatch}>
                {deletingBatch ? <Loader2 className="mr-2 h-4 w-4 animate-spin" /> : null}
                Void Finalized Batch
              </Button>
            </div>
          </DialogContent>
        </Dialog>
      </div>
    </TooltipProvider>
  )
}
