import { useCallback, useEffect, useMemo, useRef, useState } from 'react'
import { Link, useNavigate, useSearchParams } from 'react-router-dom'
import {
  adminBulkDownloadPayrollBatchPdfZip,
  adminDeleteFinalizedPayrollBatch,
  adminDeliverFinalizePayslips,
  adminExecuteFinalizePayroll,
  adminFinalizePayrollStatus,
  adminPreviewFinalizePayroll,
  companyLogoUrl,
  getCompanies,
  userProfileImageSrc,
} from '@/api'
import { useHrBasePath } from '@/contexts/HrAppPathContext'
import { useAuth } from '@/contexts/AuthContext'
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
import {
  ArrowLeft,
  ArrowUpRight,
  Building2,
  CheckCircle2,
  Eye,
  FileDown,
  RefreshCw,
  Loader2,
  Lock,
  Search,
  Send,
  Trash2,
  Users,
} from 'lucide-react'

const TEXT = 'text-[#0A0A0A]'
/** Full-width shell aligned with Government Deduction / Pay Cycles style */
const PAYSLIP_MODULE_SHELL =
  'w-full min-w-0 max-w-none bg-white px-3 py-4 sm:px-4 md:px-5 lg:px-6 lg:py-5'
const PAYSLIP_STACK = 'space-y-5 sm:space-y-6 lg:space-y-8'
const CARD = 'rounded-2xl border border-slate-200/90 bg-white shadow-sm'
const PAYSLIP_PREVIEW_DIALOG =
  '!max-w-[min(88rem,calc(100vw-1.5rem))] w-full overflow-hidden border-slate-200/90 bg-white p-0 shadow-xl shadow-slate-900/[0.07] sm:!max-w-[min(88rem,calc(100vw-2rem))]'

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
    return { label: 'Company Head', className: 'bg-blue-50 text-blue-700' }
  }
  if (role === 'branch_head' || role === 'branch head') {
    return { label: 'Branch Head', className: 'bg-violet-50 text-violet-700' }
  }
  if (role === 'department_head' || role === 'department head') {
    return { label: 'Department Head', className: 'bg-teal-50 text-teal-700' }
  }
  return { label: 'Employee', className: 'bg-slate-100 text-slate-700' }
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
    green: 'border-emerald-200 bg-emerald-50 text-emerald-700',
    gray: 'border-slate-200 bg-slate-50 text-slate-700',
  }
  const dot = color === 'green' ? 'text-emerald-500' : 'text-slate-400'
  return (
    <span className={cn('inline-flex items-center gap-1.5 rounded-full border px-2.5 py-1 text-xs font-medium', map[color] || map.gray)}>
      <span className={cn('text-[10px]', dot)}>●</span>
      {label}
    </span>
  )
}

export default function AdminFinalizePayrollPage() {
  const { user } = useAuth()
  const { toast } = useToast()
  const toastRef = useRef(toast)
  toastRef.current = toast
  const hrBase = useHrBasePath()
  const navigate = useNavigate()
  const [searchParams] = useSearchParams()
  const isAdmin = user?.role === 'admin'
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
  const [page, setPage] = useState(1)
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
    // previewScopeKey covers all finalize preview inputs; avoids effect churn from object identity.
  }, [canFinalizePayroll, isAdmin, previewScopeKey])

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

  const selectedCompany = useMemo(
    () => companies.find((c) => Number(c.id) === Number(effectivePayload.company_id)),
    [companies, effectivePayload.company_id]
  )
  const selectedCompanyLogo = companyLogoUrl(selectedCompany)

  const filtered = useMemo(() => {
    const ordered = [...employees].sort((a, b) => {
      const byRole = roleRank(employeeCompanyPosition(a)) - roleRank(employeeCompanyPosition(b))
      if (byRole !== 0) return byRole
      return String(a?.name || '').localeCompare(String(b?.name || ''))
    })
    const q = search.trim().toLowerCase()
    if (!q) return ordered
    return ordered.filter((r) => `${r.name || ''} ${r.employee_code || ''} ${r.department || ''}`.toLowerCase().includes(q))
  }, [employees, search])

  const pageCount = Math.max(1, Math.ceil(filtered.length / pageSize))
  const pageRows = useMemo(() => {
    const start = (page - 1) * pageSize
    return filtered.slice(start, start + pageSize)
  }, [filtered, page])

  useEffect(() => {
    setPage(1)
  }, [search, employees.length])

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
        title: 'Finalized batch deleted',
        description: 'All payslips and sent records were removed. Regenerate payroll to finalize again.',
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
    setBulkDownloadingZip(true)
    toastRef.current({
      title: 'Preparing download…',
      description: 'Reusing saved PDFs when available. First-time or forced rebuilds may take several minutes.',
    })
    try {
      const blob = await adminBulkDownloadPayrollBatchPdfZip(batchRunId)
      const url = URL.createObjectURL(blob)
      const a = document.createElement('a')
      a.href = url
      const safeCo = String(preview?.totals?.company_name || preview?.company?.name || selectedCompany?.name || 'batch')
        .replace(/[^\w-]+/g, '-')
        .slice(0, 40)
      a.download = `payslips-${safeCo}-${batchRunId}.zip`
      a.click()
      URL.revokeObjectURL(url)
      toastRef.current({
        title: 'Download started',
        description: 'Your ZIP file of payslip PDFs is downloading.',
      })
    } catch (e) {
      toastRef.current({
        title: 'Download failed',
        description: e.message || 'Could not build ZIP.',
        variant: 'destructive',
      })
    } finally {
      setBulkDownloadingZip(false)
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
            <p className="mt-2 text-sm text-[#0A0A0A]/70">You do not have permission to finalize payroll runs.</p>
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
            <p className="mt-2 text-sm text-[#0A0A0A]/70">
              Start from <span className="font-medium text-[#0A0A0A]">Generate Payslips</span>, set your filters, then click
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
          <Button variant="ghost" size="sm" className="w-fit gap-2" asChild>
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
                  className="gap-2"
                  disabled={bulkDownloadingZip || loading}
                  onClick={handleBulkDownloadFinalizedZip}
                >
                  {bulkDownloadingZip ? (
                    <Loader2 className="h-4 w-4 animate-spin" />
                  ) : (
                    <FileDown className="h-4 w-4" />
                  )}
                  Bulk Download PDF
                </Button>
              )}
            <Button
              type="button"
              variant="outline"
              size="sm"
              className="gap-2"
              onClick={() => setRefreshToken(String(Date.now()))}
              disabled={loading || periodFinalized}
              title={periodFinalized ? 'Preview refresh is disabled while this payroll is locked.' : undefined}
            >
              <RefreshCw className={cn('h-4 w-4', loading ? 'animate-spin' : '')} />
              Refresh calculation
            </Button>
          </div>
        </div>

        <div className={cn(CARD, 'p-6')}>
          <div className="flex flex-col gap-4 @md:flex-row @md:items-start @md:justify-between">
            <div className="space-y-2">
              <h1 className={cn('text-[28px] font-bold leading-tight tracking-tight @md:text-[32px]', TEXT)}>Finalize Payroll</h1>
              <p className="max-w-3xl text-[15px] font-normal leading-relaxed text-[#0A0A0A]/55">
                Review totals from the same payroll engine as pay components, deductions, statutory lines, pay cycles, schedules, and
                daily computation — then finalize to persist payroll periods, generate PDF payslips, and lock the run.
              </p>
            </div>
            <div
              className={cn(
                'w-fit rounded-2xl border px-4 py-2.5 shadow-sm',
                periodFinalized ? 'border-emerald-200 bg-emerald-50' : 'border-slate-200 bg-slate-50',
              )}
            >
              <p className={cn('text-xs font-semibold tracking-[0.16em]', periodFinalized ? 'text-emerald-700' : 'text-slate-700')}>
                {periodFinalized ? 'STATUS: FINALIZED' : 'STATUS: DRAFT'}
              </p>
              <p className={cn('text-xs', periodFinalized ? 'text-emerald-700/80' : 'text-slate-600')}>
                {periodFinalized ? 'Locked — read only' : 'Editable before finalize'}
              </p>
            </div>
          </div>

          <div className="mt-4 inline-flex items-center gap-3 rounded-xl border border-slate-200/80 bg-white px-3 py-2 shadow-sm">
            {selectedCompanyLogo ? (
              <img src={selectedCompanyLogo} alt="" className="h-10 w-10 rounded-md border border-slate-200/80 bg-white object-contain" />
            ) : (
              <span className="inline-flex h-10 w-10 items-center justify-center rounded-md border border-slate-200/80 bg-slate-50">
                <Building2 className="h-5 w-5 text-[#0A0A0A]/55" />
              </span>
            )}
            <div className="min-w-0">
              <p className="text-[11px] font-semibold uppercase tracking-[0.15em] text-[#0A0A0A]/65">Company</p>
              <p className={cn('truncate font-semibold', TEXT)}>
                {selectedCompany?.name || (user?.hr_role === 'company_head' ? `${user?.company_name || 'Company'} (Company-wide)` : 'All selected scope')}
              </p>
            </div>
          </div>

          {periodPreview ? (
            <div className="mt-3 grid grid-cols-1 gap-2 text-xs @md:grid-cols-3">
              <div className="rounded-lg border border-slate-200/70 bg-white px-3 py-2 text-[#0A0A0A]/75">
                <span className="font-semibold text-[#0A0A0A]">Pay Cycle:</span> {periodPreview?.pay_cycle_name || periodPreview?.cycle_label || '—'}
                {periodPreview?.pay_cycle_source_label ? (
                  <div className="mt-1 text-[11px] text-[#0A0A0A]/55">{periodPreview.pay_cycle_source_label}</div>
                ) : null}
              </div>
              <div className="rounded-lg border border-slate-200/70 bg-white px-3 py-2 text-[#0A0A0A]/75">
                <span className="font-semibold text-[#0A0A0A]">Next Cut-off:</span> {formatDate(periodPreview?.next_cut_off_start || periodPreview?.cut_off_start_date)} - {formatDate(periodPreview?.next_cut_off_end || periodPreview?.cut_off_end_date)}
              </div>
              <div className="rounded-lg border border-slate-200/70 bg-white px-3 py-2 text-[#0A0A0A]/75">
                <span className="font-semibold text-[#0A0A0A]">Pay Date:</span> {formatDate(periodPreview?.next_pay_date || periodPreview?.pay_date)}
              </div>
            </div>
          ) : null}

          {!periodFinalized ? (
            <p className="mt-4 rounded-xl border border-slate-200/70 bg-slate-50/80 px-4 py-3 text-sm text-[#0A0A0A]/70">
              Figures below are <span className="font-medium text-[#0A0A0A]">computed</span> for review only. PDFs are created after
              you confirm and click Finalize Payroll.
            </p>
          ) : (
            <div className="mt-4 space-y-3">
              <p className="flex items-start gap-2 rounded-xl border border-emerald-200 bg-emerald-50 px-4 py-3 text-sm text-[#0A0A0A]">
                <Lock className="mt-0.5 h-4 w-4 shrink-0 text-emerald-700" aria-hidden />
                <span>
                  <span className="font-semibold text-emerald-900">This payroll has been finalized and is now locked.</span> Use{' '}
                  <span className="font-medium text-emerald-900">Send payslips</span> for each finalized row (or bulk) so they appear in My Payslips.
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
                    Delete Finalized Batch
                  </Button>
                </div>
              ) : null}
            </div>
          )}

          {!loading && previewError ? (
            <div className="mt-4 rounded-xl border border-red-200 bg-red-50 px-4 py-3 text-sm text-red-700">
              {previewError}
            </div>
          ) : null}
        </div>

        <div className="grid grid-cols-1 gap-4 @lg:grid-cols-4">
          <Card className={cn(CARD, 'overflow-hidden')}>
            <CardContent className="p-5">
              <p className="text-[14px] font-medium uppercase tracking-[0.12em] text-[#0A0A0A]/50">Total gross pay</p>
              <div className="mt-3 flex items-center justify-between">
                <p className={cn('text-[26px] font-medium tabular-nums leading-none', loading ? 'text-[#0A0A0A]/45' : TEXT)}>
                  {loading ? '—' : formatPeso(totals.total_gross)}
                </p>
                <ArrowUpRight className="h-6 w-6 text-[#0A0A0A]/45" />
              </div>
            </CardContent>
          </Card>

          <Card className={cn(CARD, 'overflow-hidden')}>
            <CardContent className="p-5">
              <p className="text-[14px] font-medium uppercase tracking-[0.12em] text-[#0A0A0A]/50">Total deductions</p>
              <p className={cn('mt-3 text-[26px] font-medium tabular-nums leading-none', loading ? 'text-[#0A0A0A]/45' : TEXT)}>
                {loading ? '—' : formatPeso(totals.total_deductions)}
              </p>
            </CardContent>
          </Card>

          <Card className={cn(CARD, 'overflow-hidden border-emerald-200/80 bg-emerald-50/70 shadow-[0_8px_20px_rgba(16,185,129,0.12)]')}>
            <CardContent className="p-5">
              <p className="text-[14px] font-medium uppercase tracking-[0.12em] text-emerald-800/70">Total net pay</p>
              <p className={cn('mt-3 text-[34px] font-bold tabular-nums leading-none tracking-tight @md:text-[36px]', loading ? 'text-[#0A0A0A]/45' : 'text-emerald-950')}>
                {loading ? '—' : formatPeso(totals.total_net)}
              </p>
            </CardContent>
          </Card>

          <Card className={cn(CARD, 'overflow-hidden')}>
            <CardContent className="p-5">
              <p className="text-[14px] font-medium uppercase tracking-[0.12em] text-[#0A0A0A]/50">Employee count</p>
              <div className="mt-3 flex items-center justify-between">
                <p className={cn('text-[28px] font-bold tabular-nums leading-none', TEXT)}>{loading ? '—' : Number(totals.employee_count || 0)}</p>
                <div className="flex items-center -space-x-2">
                  {employees.slice(0, 3).map((row) => (
                    <Avatar key={row.user_id} className="h-8 w-8 border-2 border-background ring-1 ring-border/60">
                      <AvatarImage src={employeeAvatarSrc(row) || undefined} alt="" />
                      <AvatarFallback className="text-[10px] font-semibold">{initials(row.name)}</AvatarFallback>
                    </Avatar>
                  ))}
                  <span className="ml-2 inline-flex h-8 w-8 items-center justify-center rounded-full bg-muted text-xs font-semibold text-[#0A0A0A]/55">
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
                <h2 className={cn('text-[22px] font-semibold leading-snug @md:text-2xl', TEXT)}>Employees</h2>
                <p className="mt-1 text-sm font-normal leading-snug text-[#0A0A0A]/55">
                  View full payslip details on Draft. Finalize only locks the period and publishes PDFs.
                </p>
              </div>
              <div className="flex w-full max-w-md items-center gap-2">
                <Search className="h-4 w-4 shrink-0 text-[#0A0A0A]/55" />
                <Input
                  value={search}
                  onChange={(e) => setSearch(e.target.value)}
                  placeholder="Search employee…"
                  disabled={periodFinalized}
                  className="h-9 rounded-lg border-[#0A0A0A]/12 bg-white text-[#0A0A0A]"
                />
              </div>
            </div>

            {periodFinalized ? (
              <div className="flex flex-col gap-3 border-t border-slate-100 px-4 py-3 @md:flex-row @md:flex-wrap @md:items-center @md:justify-end @md:px-5">
                <Button
                  type="button"
                  disabled={deliveringBulk || selectedPayslipIds.size === 0}
                  className="h-9 gap-2 bg-emerald-600 font-semibold text-white shadow-sm hover:bg-emerald-700 disabled:bg-emerald-600/40"
                  onClick={() => handleDeliverBulk()}
                >
                  {deliveringBulk ? <Loader2 className="h-4 w-4 animate-spin" /> : <Send className="h-4 w-4" />}
                  Send payslips{selectedPayslipIds.size > 0 ? ` (${selectedPayslipIds.size})` : ''}
                </Button>
              </div>
            ) : null}

            {loading ? (
              <div className="flex items-center justify-center py-14 text-[#0A0A0A]/60">
                <Loader2 className="mr-2 h-5 w-5 animate-spin" />
                Loading employees…
              </div>
            ) : filtered.length === 0 ? (
              <div className="px-4 py-10 text-center text-sm text-[#0A0A0A]/65 @md:px-5">
                No employees found in the selected scope.
              </div>
            ) : (
              <div className="w-full px-0 pb-4 pt-1">
                <div className="w-full overflow-x-auto">
                  <Table className="min-w-[1020px] border-0 [&_td]:border-0 [&_th]:border-0">
                    <TableHeader className="[&_tr]:border-0">
                      <TableRow className="border-0 bg-slate-100/70 hover:bg-slate-100/70">
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
                        <TableHead className="h-auto py-2.5 pl-4 pr-2 text-left text-[14px] font-semibold uppercase tracking-[0.06em] text-[#0A0A0A]/70 first:pl-5">
                          Employee
                        </TableHead>
                        <TableHead className="h-auto py-2.5 px-2 text-left text-[14px] font-semibold uppercase tracking-[0.06em] text-[#0A0A0A]/70">Department</TableHead>
                        <TableHead className="h-auto py-2.5 px-2 text-right text-[14px] font-semibold uppercase tracking-[0.06em] text-[#0A0A0A]/70">Basic Salary (Period)</TableHead>
                        <TableHead className="h-auto py-2.5 px-2 text-right text-[14px] font-semibold uppercase tracking-[0.06em] text-[#0A0A0A]/70">Daily Rate</TableHead>
                        <TableHead className="h-auto py-2.5 px-2 text-right text-[14px] font-semibold uppercase tracking-[0.06em] text-[#0A0A0A]/70">Gross Pay</TableHead>
                        <TableHead className="h-auto py-2.5 px-2 text-right text-[14px] font-semibold uppercase tracking-[0.06em] text-[#0A0A0A]/70">Total Deductions</TableHead>
                        <TableHead className="h-auto py-2.5 px-2 text-right text-[14px] font-semibold uppercase tracking-[0.06em] text-emerald-800/90">Net pay</TableHead>
                        <TableHead className="h-auto py-2.5 px-2 text-left text-[14px] font-semibold uppercase tracking-[0.06em] text-[#0A0A0A]/70">Status</TableHead>
                        <TableHead className="h-auto min-w-[220px] py-2.5 pl-2 pr-4 text-right text-[14px] font-semibold uppercase tracking-[0.06em] text-[#0A0A0A]/70 @md:pr-5">
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
                          className="border-0 transition-colors duration-150 hover:bg-slate-50/90"
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
                              <Avatar className="h-9 w-9 ring-1 ring-[#0A0A0A]/10">
                                <AvatarImage src={employeeAvatarSrc(row) || undefined} alt="" />
                                <AvatarFallback className="text-[10px] font-semibold">{initials(row.name)}</AvatarFallback>
                              </Avatar>
                              <div className="min-w-0">
                                <p className={cn('truncate text-base font-bold', TEXT)}>{row.name}</p>
                                <div className="mt-0.5 flex flex-wrap items-center gap-2">
                                  <span className={cn('inline-flex rounded-full px-2 py-0.5 text-[10px] font-medium', roleBadgeMeta(employeeCompanyPosition(row)).className)}>
                                    {row?.employee_role_label || roleBadgeMeta(employeeCompanyPosition(row)).label}
                                  </span>
                                  <span className="text-[11px] text-[#0A0A0A]/50">{row.employee_code}</span>
                                  {periodFinalized && canSendRow && rowIsSent ? (
                                    <Badge variant="secondary" className="h-5 rounded-md border-emerald-200 bg-emerald-50 px-2 text-[10px] font-semibold uppercase tracking-wide text-emerald-900">
                                      Sent
                                    </Badge>
                                  ) : null}
                                </div>
                                {employeeRole(row) && employeeRole(row) !== '—' ? (
                                  <p className="mt-0.5 text-[15px] font-normal leading-snug text-[#0A0A0A]/55">{employeeRole(row)}</p>
                                ) : null}
                              </div>
                            </div>
                          </TableCell>
                          <TableCell className="py-2.5 px-2 text-[15px] font-normal text-[#0A0A0A]/58">{row.department || '—'}</TableCell>
                          <TableCell className="py-2.5 px-2 text-right text-sm font-medium tabular-nums text-[#0A0A0A]/80">
                            {formatPeso(row.basic_salary)}
                            {Number(row.basic_salary_monthly || 0) > 0 ? (
                              <div className="mt-1 text-[10px] text-[#0A0A0A]/55">
                                Monthly: {formatPeso(row.basic_salary_monthly)}
                              </div>
                            ) : null}
                          </TableCell>
                          <TableCell className="py-2.5 px-2 text-right text-sm font-medium tabular-nums text-[#0A0A0A]/80">
                            {formatPeso(row.daily_rate)}
                          </TableCell>
                          <TableCell className="py-2.5 px-2 text-right align-middle">
                            <div className="flex flex-col items-end gap-1">
                              <span className="text-[15px] font-semibold tabular-nums text-[#0A0A0A]">
                                {formatPeso(row.gross_pay)}
                              </span>
                              <div className="flex items-center justify-end gap-2">
                                <button
                                  type="button"
                                  className="text-xs font-semibold text-[#0A0A0A]/70 underline underline-offset-4 hover:text-[#0A0A0A]"
                                  onClick={() => setBreakdownRow(row)}
                                >
                                  View breakdown
                                </button>
                              </div>
                              {Number(row.actual_days_worked || 0) <= 0 && Number(row.basic_salary || 0) > 0 ? (
                                <div className="text-[11px] font-medium leading-tight text-amber-700">No attendance in selected period</div>
                              ) : null}
                            </div>
                          </TableCell>
                          <TableCell
                            className={cn(
                              'py-2.5 px-2 text-right text-base font-medium tabular-nums',
                              Number(row.total_deductions || 0) > 0 ? 'text-orange-700' : 'text-[#0A0A0A]/50',
                            )}
                          >
                            {formatPeso(row.total_deductions)}
                          </TableCell>
                          <TableCell className="py-2.5 px-2 text-right text-base font-semibold tabular-nums text-[#0A0A0A]">
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
                                      ? 'border-emerald-200 text-emerald-800 hover:bg-emerald-50'
                                      : 'border-transparent bg-emerald-600 text-white hover:bg-emerald-700',
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
                                className="h-8 shrink-0 gap-1.5 whitespace-nowrap rounded-lg border-slate-200/90 bg-white px-3 text-xs font-semibold text-[#0A0A0A] hover:bg-slate-50 @sm:text-sm"
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

                <div className="mt-3 flex flex-col gap-2 px-4 @sm:flex-row @sm:items-center @sm:justify-between @sm:px-5 text-sm text-[#0A0A0A]/70">
                  <span>
                    Page {page} of {pageCount} · {filtered.length} employee{filtered.length === 1 ? '' : 's'}
                  </span>
                  <div className="flex gap-2">
                    <Button type="button" variant="outline" size="sm" className="rounded-xl" onClick={() => setPage((p) => Math.max(1, p - 1))} disabled={page <= 1}>
                      Prev
                    </Button>
                    <Button type="button" variant="outline" size="sm" className="rounded-xl" onClick={() => setPage((p) => Math.min(pageCount, p + 1))} disabled={page >= pageCount}>
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
              <div className="flex flex-wrap items-center justify-center gap-2 rounded-xl border border-emerald-200 bg-emerald-50 px-4 py-3 text-center">
                <CheckCircle2 className="h-4 w-4 text-emerald-700" aria-hidden />
                <span className="text-sm font-semibold text-[#0A0A0A]">Payroll Finalized</span>
                <span className="text-sm text-[#0A0A0A]/60">This payroll batch is locked and no further confirmation is required.</span>
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
                    <Label htmlFor="review" className={cn('cursor-pointer text-base font-medium leading-snug', TEXT)}>
                      I confirm I have reviewed all totals and employee details for this payroll batch.
                    </Label>
                    <p className="mt-1.5 text-sm font-normal text-[#0A0A0A]/55">Gross pay verified • Deductions correct • No open loans conflicting</p>
                  </div>
                </div>
                <Button
                  type="button"
                  size="lg"
                  disabled={!reviewConfirmed || finalizing || loading || Number(totals?.employee_count || 0) <= 0}
                  onClick={handleFinalize}
                  className={cn(
                    'h-12 min-w-[260px] shrink-0 rounded-xl px-8 text-[17px] font-bold transition-transform active:scale-[0.99]',
                    'bg-emerald-600 text-white shadow-[0_8px_20px_rgba(5,150,105,0.35)] hover:bg-emerald-700',
                    'disabled:bg-[#0A0A0A]/25 disabled:text-white/90 disabled:shadow-none',
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
              <DialogDescription className="text-sm">
                {periodFinalized
                  ? 'All employees in this batch were finalized successfully.'
                  : `Finalizing payroll for ${Math.max(1, Number(totals?.employee_count || employees.length || 1))} employees...`}
              </DialogDescription>
            </DialogHeader>
            <div className="space-y-3 py-2">
              <Progress value={progress} indicatorClassName="bg-emerald-600 transition-all duration-300" />
              <p className="text-xs font-medium text-[#0A0A0A]/70">{periodFinalized ? 'Finalized and locked' : finalizeStage}</p>
              <div className="flex items-center justify-between text-xs text-[#0A0A0A]/65">
                <span>{periodFinalized ? 'Done' : `${processedCount}/${Math.max(1, Number(totals?.employee_count || employees.length || 1))}`}</span>
                <span className="font-semibold text-[#0A0A0A]">{progress}%</span>
              </div>
              {periodFinalized ? (
                <div className="flex items-center gap-2 rounded-lg border border-emerald-200 bg-emerald-50 px-3 py-2 text-sm text-emerald-700">
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
              <DialogDescription className="text-[#0A0A0A]/60">
                {breakdownRow?.name ? <span className="font-medium text-[#0A0A0A]">{breakdownRow.name}</span> : 'Employee'} ·{' '}
                {breakdownRow?.employee_code || '—'}
              </DialogDescription>
            </DialogHeader>

            <div className="space-y-4">
              <div className="rounded-xl border border-slate-200/80 bg-white p-4">
                <div className="grid grid-cols-1 gap-3 @sm:grid-cols-3">
                  <div>
                    <p className="text-[11px] font-semibold uppercase tracking-[0.12em] text-[#0A0A0A]/50">Gross pay</p>
                    <p className="mt-1 text-lg font-bold tabular-nums text-[#0A0A0A]">{formatPeso(breakdownRow?.gross_pay)}</p>
                  </div>
                  <div>
                    <p className="text-[11px] font-semibold uppercase tracking-[0.12em] text-[#0A0A0A]/50">Total deductions</p>
                    <p className="mt-1 text-lg font-bold tabular-nums text-[#0A0A0A]">{formatPeso(breakdownRow?.total_deductions)}</p>
                  </div>
                  <div>
                    <p className="text-[11px] font-semibold uppercase tracking-[0.12em] text-[#0A0A0A]/50">Net pay</p>
                    <p className="mt-1 text-lg font-bold tabular-nums text-[#0A0A0A]">{formatPeso(breakdownRow?.net_pay)}</p>
                  </div>
                </div>
              </div>

              <div className="rounded-xl border border-slate-200/80 bg-white">
                <div className="border-b border-slate-100 px-4 py-3">
                  <p className="text-sm font-semibold text-[#0A0A0A]">Earnings (Daily Computation)</p>
                  <p className="mt-0.5 text-xs text-[#0A0A0A]/55">Based on attendance, holidays, OT approvals, and policy rules.</p>
                </div>
                <div className="divide-y divide-slate-100">
                  {(Array.isArray(breakdownRow?.daily_computation_earning_lines) ? breakdownRow.daily_computation_earning_lines : []).map((l, i) => (
                    <div key={`bde-${i}`} className="flex items-center justify-between gap-4 px-4 py-3">
                      <div className="min-w-0">
                        <p className="truncate text-sm font-medium text-[#0A0A0A]">{String(l?.label || 'Earning')}</p>
                        {l?.units ? <p className="mt-0.5 text-xs text-[#0A0A0A]/55">{String(l.units)}</p> : null}
                      </div>
                      <p className="shrink-0 text-sm font-semibold tabular-nums text-[#0A0A0A]">{formatPeso(Number(l?.amount || 0))}</p>
                    </div>
                  ))}
                  {(Array.isArray(breakdownRow?.daily_computation_earning_lines) ? breakdownRow.daily_computation_earning_lines : []).length === 0 ? (
                    <div className="px-4 py-10 text-center text-sm text-[#0A0A0A]/60">No earning lines available for this preview row.</div>
                  ) : null}
                </div>
              </div>

              <div className="flex flex-wrap items-center justify-end gap-2">
                <Button
                  type="button"
                  variant="outline"
                  className="h-9 rounded-xl border-[#0A0A0A]/15 bg-white text-[#0A0A0A] hover:bg-slate-50"
                  onClick={() => setBreakdownRow(null)}
                >
                  Close
                </Button>
                <Button
                  type="button"
                  className="h-9 rounded-xl bg-[#0A0A0A] text-white hover:bg-[#0A0A0A]/90"
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
          open={deleteDialogOpen}
          onOpenChange={(open) => {
            if (!deletingBatch) setDeleteDialogOpen(open)
          }}
        >
          <DialogContent className="sm:max-w-md">
            <DialogHeader>
              <DialogTitle className={cn('text-xl font-bold', TEXT)}>Delete finalized batch?</DialogTitle>
              <DialogDescription className="text-sm text-[#0A0A0A]/70">
                This will delete all payslips and sent records. You will need to regenerate the payroll.
              </DialogDescription>
            </DialogHeader>
            <div className="flex justify-end gap-2 pt-2">
              <Button type="button" variant="outline" onClick={() => setDeleteDialogOpen(false)} disabled={deletingBatch}>
                Cancel
              </Button>
              <Button type="button" variant="destructive" onClick={() => handleDeleteFinalizedBatch()} disabled={deletingBatch}>
                {deletingBatch ? <Loader2 className="mr-2 h-4 w-4 animate-spin" /> : null}
                Delete Finalized Batch
              </Button>
            </div>
          </DialogContent>
        </Dialog>
      </div>
    </TooltipProvider>
  )
}
