import { useEffect, useMemo, useRef, useState } from 'react'
import { useNavigate, useSearchParams } from 'react-router-dom'
import {
  adminBulkSendFinalizedBatchPayslips,
  adminDeleteFinalizedPayrollBatch,
  adminDeletePayslipBatch,
  adminFinalizeExecomPayroll,
  adminPollAndDownloadBulkPayslipZip,
  adminQueueBulkPayslipDownload,
  adminRecomputeExecomPayroll,
  getExecomPayrollBatchStatus,
  getExecomPayrollBatches,
  getExecomPayrollPayslips,
  getExecomPayrollReportPdfBlob,
} from '@/api'
import { Avatar, AvatarFallback } from '@/components/ui/avatar'
import { Badge } from '@/components/ui/badge'
import { Button } from '@/components/ui/button'
import { Card, CardContent } from '@/components/ui/card'
import { Dialog, DialogContent, DialogDescription, DialogFooter, DialogHeader, DialogTitle } from '@/components/ui/dialog'
import { Input } from '@/components/ui/input'
import { Progress } from '@/components/ui/progress'
import { Table, TableBody, TableCell, TableHead, TableHeader, TableRow } from '@/components/ui/table'
import { useToast } from '@/components/ui/use-toast'
import { useHrBasePath } from '@/contexts/HrAppPathContext'
import { saveBulkPayslipZipBlob } from '@/lib/bulkPayslipDownload'
import { cn } from '@/lib/utils'
import {
  ArrowLeft,
  ArrowUpRight,
  CheckCircle2,
  FileDown,
  FileText,
  Lock,
  Loader2,
  PhilippinePeso,
  RefreshCw,
  Search,
  Send,
  Trash2,
  Users,
} from 'lucide-react'

const TEXT = 'text-foreground'
const PAGE_SHELL =
  'w-full min-w-0 max-w-none bg-background px-3 py-4 text-foreground sm:px-4 md:px-5 lg:px-6 lg:py-5'
const PAGE_STACK = 'space-y-5 sm:space-y-6'
const CARD =
  'rounded-2xl border border-border/80 bg-card text-card-foreground shadow-sm shadow-slate-900/[0.03] dark:shadow-black/25'

function formatPeso(value) {
  const amount = Number(value || 0)
  const sign = amount < 0 ? '-' : ''
  return `${sign}\u20B1${Math.abs(amount).toLocaleString(undefined, { minimumFractionDigits: 2, maximumFractionDigits: 2 })}`
}

function formatDate(value) {
  if (!value) return '—'
  const d = new Date(value)
  if (Number.isNaN(d.getTime())) return String(value)
  return d.toLocaleDateString(undefined, { month: 'short', day: 'numeric', year: 'numeric' })
}

function initials(name) {
  const text = String(name || '').trim()
  if (!text) return 'EX'
  const parts = text.split(/\s+/).filter(Boolean)
  if (parts.length === 1) return parts[0].slice(0, 2).toUpperCase()
  return `${parts[0][0]}${parts[parts.length - 1][0]}`.toUpperCase()
}

function statusPill(status) {
  const value = String(status || '').toLowerCase()
  const label = value ? value.replace(/_/g, ' ') : 'draft'
  if (value === 'finalized') {
    return (
      <Badge className="border-brand/30 bg-brand/10 text-brand hover:bg-brand/10">
        <span className="mr-1 inline-block h-1.5 w-1.5 rounded-full bg-brand" />
        Finalized
      </Badge>
    )
  }
  if (value === 'queued' || value === 'processing') {
    return (
      <Badge className="border-amber-200/80 bg-amber-50 text-amber-950 hover:bg-amber-50 dark:border-amber-900/40 dark:bg-amber-950/40 dark:text-amber-100">
        <span className="mr-1 inline-block h-1.5 w-1.5 animate-pulse rounded-full bg-amber-500" />
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

function rowSummary(row) {
  return row?.snapshot?.summary && typeof row.snapshot.summary === 'object' ? row.snapshot.summary : {}
}

function rowDepartment(row) {
  return (
    row?.employee?.department_name ||
    row?.employee?.departmentRelation?.name ||
    row?.employee?.department_relation?.name ||
    row?.employee?.department ||
    row?.department_name ||
    row?.department ||
    rowSummary(row)?.employee_department ||
    '—'
  )
}

function rowBasicSalary(row) {
  const summary = rowSummary(row)
  return Number(
    summary.basic_salary_period
      ?? summary.basic_pay_this_period
      ?? summary.basic_salary
      ?? summary.fixed_salary
      ?? 0
  )
}

function rowMonthlySalary(row) {
  const summary = rowSummary(row)
  return Number(
    summary.fixed_salary
      ?? summary.basic_salary_used
      ?? summary.execom_fixed_salary
      ?? summary.employee_compensation_salary
      ?? summary.employee_monthly_salary
      ?? 0
  )
}

function rowBasicSalaryReference(row) {
  const period = rowBasicSalary(row)
  const monthly = rowMonthlySalary(row)
  if (period > 0 && monthly > 0 && Math.abs(period - monthly) >= 0.01) {
    return `${formatPeso(period)} (monthly - ${formatPeso(monthly)})`
  }
  return formatPeso(period)
}

function rowAllowance(row) {
  const summary = rowSummary(row)
  const nonBasic = Number(summary.non_basic_earnings_this_period ?? 0)
  if (Number.isFinite(nonBasic) && nonBasic > 0) {
    return nonBasic
  }

  return earningLines(row)
    .filter((line) => {
      const key = String(line?.key || '').toLowerCase()
      const label = String(line?.label || line?.name || '').trim().toLowerCase()
      return !line?.is_basic_salary_line && !key.includes('basic') && label !== 'basic pay'
    })
    .reduce((sum, line) => sum + lineAmount(line), 0)
}

function rowDailyRate(row) {
  const summary = rowSummary(row)
  return Number(row?.daily_rate ?? summary.daily_rate ?? row?.snapshot?.daily_rate ?? 0)
}

function deductionLines(row) {
  const summary = rowSummary(row)
  return [
    ...(Array.isArray(summary.payslip_deduction_lines) ? summary.payslip_deduction_lines : []),
    ...(Array.isArray(summary.payslip_custom_deduction_lines) ? summary.payslip_custom_deduction_lines : []),
  ]
}

function earningLines(row) {
  const summary = rowSummary(row)
  const lines = (Array.isArray(summary.payslip_earning_lines) ? summary.payslip_earning_lines : [])
    .filter((line) => {
      const key = String(line?.key || '').toLowerCase()
      const label = String(line?.label || line?.name || '').trim().toLowerCase()
      const category = String(line?.category || '').toLowerCase()
      return !key.includes('regular_pay') && label !== 'regular pay' && category !== 'regular_pay'
    })
  if (lines.some((line) => lineAmount(line) > 0)) return lines
  const basic = rowBasicSalary(row)
  return basic > 0 ? [{ key: 'execom_basic_pay_fallback', label: 'Basic Pay', amount: basic }] : []
}

function lineAmount(line) {
  return Number(line?.amount ?? line?.resolved_amount ?? line?.scheduled_this_period ?? 0)
}

function saveBlob(blob, filename) {
  const url = URL.createObjectURL(blob)
  const link = document.createElement('a')
  link.href = url
  link.download = filename
  document.body.appendChild(link)
  link.click()
  link.remove()
  URL.revokeObjectURL(url)
}

function MetricCard({ label, value, icon, accent = false }) {
  return (
    <Card className={cn(
      CARD,
      'overflow-hidden',
      accent ? 'border-brand/30 bg-brand/10 shadow-[0_8px_20px_rgba(249,115,22,0.12)] dark:bg-brand/15' : '',
    )}>
      <CardContent className="p-5">
        <p className={cn('text-[13px] font-semibold uppercase tracking-normal', accent ? 'text-brand' : 'text-muted-foreground')}>
          {label}
        </p>
        <div className="mt-3 flex items-center justify-between">
          {value == null ? (
            <div className={cn('animate-pulse rounded', accent ? 'h-8 w-36 bg-brand/20' : 'h-7 w-32 bg-muted')} />
          ) : (
            <p className={cn(
              'font-extrabold tabular-nums leading-none',
              accent ? 'text-[30px] tracking-normal text-brand @md:text-[32px]' : 'text-[26px] text-foreground',
            )}>
              {value}
            </p>
          )}
          {icon}
        </div>
      </CardContent>
    </Card>
  )
}

export default function AdminExecomFinalizePayrollPage() {
  const { toast } = useToast()
  const navigate = useNavigate()
  const hrBase = useHrBasePath()
  const pollRef = useRef(null)
  const [searchParams] = useSearchParams()
  const initialBatchId = searchParams.get('batch_run_id')
  const [batches, setBatches] = useState([])
  const [selectedBatchId, setSelectedBatchId] = useState(initialBatchId || '')
  const [selectedBatch, setSelectedBatch] = useState(null)
  const [payslips, setPayslips] = useState([])
  const [loading, setLoading] = useState(false)
  const [working, setWorking] = useState(false)
  const [search, setSearch] = useState('')
  const [page, setPage] = useState(1)
  const [breakdownRow, setBreakdownRow] = useState(null)
  const [queuedRunId, setQueuedRunId] = useState(null)
  const [showFinalizeModal, setShowFinalizeModal] = useState(false)
  const [showDeleteModal, setShowDeleteModal] = useState(false)
  const [showSendBatchModal, setShowSendBatchModal] = useState(false)
  const [finalizing, setFinalizing] = useState(false)
  const [sendingBatchPayslips, setSendingBatchPayslips] = useState(false)
  const [finalizeStage, setFinalizeStage] = useState('Queued: waiting for worker')
  const [progress, setProgress] = useState(0)
  const [processedCount, setProcessedCount] = useState(0)
  const [done, setDone] = useState(false)
  const pageSize = 12

  const selectedId = useMemo(() => Number(selectedBatchId || 0), [selectedBatchId])

  async function loadBatches() {
    const data = await getExecomPayrollBatches({ per_page: 50 })
    const rows = Array.isArray(data.data) ? data.data : []
    setBatches(rows)
    if (!selectedBatchId && rows[0]) {
      setSelectedBatchId(String(rows[0].payroll_batch_run_id || rows[0].id))
    }
  }

  async function loadSelected(batchId = selectedId) {
    if (!batchId) return
    setLoading(true)
    try {
      const status = await getExecomPayrollBatchStatus(batchId)
      const list = await getExecomPayrollPayslips({
        batch_run_id: batchId,
        status: status?.status === 'finalized' ? 'finalized' : 'draft',
        per_page: 100,
      })
      setSelectedBatch(status)
      const rows = Array.isArray(list.payslips?.data) ? list.payslips.data : Array.isArray(list.data) ? list.data : []
      setPayslips(rows)
    } catch (error) {
      toast({ title: 'Failed to load EXECOM payroll', description: error.message, variant: 'error' })
    } finally {
      setLoading(false)
    }
  }

  useEffect(() => {
    loadBatches().catch((error) => toast({ title: 'Failed to load EXECOM batches', description: error.message, variant: 'error' }))
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, [])

  useEffect(() => {
    if (selectedId) loadSelected(selectedId)
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, [selectedId])

  async function finalizeBatch() {
    if (!selectedId) return
    setWorking(true)
    setFinalizing(true)
    setDone(false)
    setProgress(8)
    setProcessedCount(0)
    setFinalizeStage('Queued: waiting for worker')
    setShowFinalizeModal(true)
    try {
      const result = await adminFinalizeExecomPayroll(selectedId)
      const runId = Number(result?.payroll_batch_run_id || selectedId)
      setQueuedRunId(runId)
      toast({ title: 'EXECOM finalization queued', description: 'Finalization is running in the payroll worker.' })
      await loadSelected(selectedId)
      await loadBatches()
    } catch (error) {
      setShowFinalizeModal(false)
      setFinalizing(false)
      setProgress(0)
      setProcessedCount(0)
      setQueuedRunId(null)
      toast({ title: 'Finalize failed', description: error.message, variant: 'error' })
    }
  }

  async function recomputeBatch() {
    if (!selectedId) return
    setWorking(true)
    try {
      await adminRecomputeExecomPayroll(selectedId)
      toast({ title: 'EXECOM recompute queued' })
      await loadSelected(selectedId)
      await loadBatches()
    } catch (error) {
      toast({ title: 'Recompute failed', description: error.message, variant: 'error' })
    } finally {
      setWorking(false)
    }
  }

  async function downloadZip() {
    if (!selectedId) return
    try {
      const queued = await adminQueueBulkPayslipDownload(selectedId)
      const { blob } = await adminPollAndDownloadBulkPayslipZip(queued.request_id)
      saveBulkPayslipZipBlob(blob, `EXECOM-payslips-${selectedId}.zip`)
    } catch (error) {
      toast({ title: 'Bulk download failed', description: error.message, variant: 'error' })
    }
  }

  async function downloadReport() {
    if (!selectedId) return
    try {
      const blob = await getExecomPayrollReportPdfBlob(
        selectedId,
        selectedBatch?.company_id ?? null,
      )
      saveBlob(blob, `EXECOM-payroll-report-${selectedId}.pdf`)
    } catch (error) {
      toast({ title: 'Report download failed', description: error.message, variant: 'error' })
    }
  }

  async function bulkSendBatchPayslips() {
    if (!selectedId || !finalized || sendingBatchPayslips) return
    setSendingBatchPayslips(true)
    try {
      const result = await adminBulkSendFinalizedBatchPayslips(selectedId)
      setShowSendBatchModal(false)
      await loadSelected(selectedId)
      toast({
        title: 'EXECOM payslips sent',
        description: `${Number(result?.delivered || 0)} of ${Number(result?.targeted || totals.employeeCount || 0)} employees were sent.`,
      })
    } catch (error) {
      toast({ title: 'Bulk send failed', description: error.message, variant: 'error' })
    } finally {
      setSendingBatchPayslips(false)
    }
  }

  async function deleteOrVoidBatch() {
    if (!selectedId || working) return
    const status = String(selectedBatch?.status || '').toLowerCase()
    const isFinalized = status === 'finalized'

    setWorking(true)
    try {
      if (isFinalized) {
        await adminDeleteFinalizedPayrollBatch(selectedId)
        toast({ title: 'EXECOM payroll voided', description: 'The finalized batch was voided and active locks were released.' })
      } else {
        await adminDeletePayslipBatch(selectedId)
        toast({ title: 'EXECOM draft deleted', description: 'The draft batch and draft payslips were removed.' })
      }
      setSelectedBatch(null)
      setPayslips([])
      setShowDeleteModal(false)
      await loadBatches()
      navigate(`${hrBase}/compensation/generate-payslips?module=execom`)
    } catch (error) {
      toast({ title: isFinalized ? 'Void failed' : 'Delete failed', description: error.message, variant: 'error' })
    } finally {
      setWorking(false)
    }
  }

  const finalized = String(selectedBatch?.status || '').toLowerCase() === 'finalized'
  const draft = String(selectedBatch?.status || '').toLowerCase() === 'draft'
  const failed = String(selectedBatch?.status || '').toLowerCase() === 'failed'
  const voided = String(selectedBatch?.status || '').toLowerCase() === 'voided'
  const processing = ['queued', 'processing'].includes(String(selectedBatch?.status || '').toLowerCase())
  const canRetryOrFinalize = draft || failed
  const canDeleteOrVoid = selectedId && !processing && !voided

  const visiblePayslips = useMemo(() => {
    const q = search.trim().toLowerCase()
    if (!q) return payslips
    return payslips.filter((row) => {
      const haystack = [
        row.employee?.name,
        row.employee_name,
        row.employee?.employee_code,
        row.employee_code,
        row.status,
      ].filter(Boolean).join(' ').toLowerCase()
      return haystack.includes(q)
    })
  }, [payslips, search])
  const pageCount = Math.max(1, Math.ceil(visiblePayslips.length / pageSize))
  const pageRows = useMemo(() => {
    const start = (Math.max(1, page) - 1) * pageSize
    return visiblePayslips.slice(start, start + pageSize)
  }, [visiblePayslips, page])

  useEffect(() => {
    setPage(1)
  }, [search, selectedBatchId])

  useEffect(() => {
    if (page > pageCount) setPage(pageCount)
  }, [page, pageCount])

  const totals = useMemo(() => {
    const aggregate = selectedBatch?.aggregate || {}
    const gross = Number(aggregate.total_gross_pay ?? selectedBatch?.total_gross_pay)
    const deductions = Number(aggregate.total_deductions ?? selectedBatch?.total_deductions)
    const net = Number(aggregate.total_net_pay ?? selectedBatch?.total_net_pay)
    const rowGross = payslips.reduce((sum, row) => sum + Number(row.gross_pay || 0), 0)
    const rowDeductions = payslips.reduce((sum, row) => sum + Number(row.total_deductions || 0), 0)
    const rowNet = payslips.reduce((sum, row) => sum + Number(row.net_pay || 0), 0)
    return {
      gross: payslips.length > 0 ? rowGross : (Number.isFinite(gross) && gross > 0 ? gross : 0),
      deductions: payslips.length > 0 ? rowDeductions : (Number.isFinite(deductions) && deductions > 0 ? deductions : 0),
      net: payslips.length > 0 ? rowNet : (Number.isFinite(net) && net > 0 ? net : 0),
      employeeCount: Number(selectedBatch?.employee_count || selectedBatch?.payslip_count || payslips.length || 0),
    }
  }, [selectedBatch, payslips])

  useEffect(() => {
    if (!queuedRunId) return undefined
    if (pollRef.current) clearInterval(pollRef.current)
    let attempts = 0
    const maxAttempts = 240

    pollRef.current = setInterval(async () => {
      attempts += 1
      if (attempts > maxAttempts) {
        if (pollRef.current) clearInterval(pollRef.current)
        pollRef.current = null
        setWorking(false)
        setFinalizing(false)
        setShowFinalizeModal(false)
        setProgress(0)
        setProcessedCount(0)
        setQueuedRunId(null)
        toast({
          title: 'Finalize timed out',
          description: 'Status polling stopped after 10 minutes. Check the queue worker and batch run status.',
          variant: 'error',
        })
        return
      }

      try {
        const status = await getExecomPayrollBatchStatus(queuedRunId)
        const s = String(status?.status || '').toLowerCase()
        const realProgress = status?.progress || null
        if (realProgress && Number(realProgress.total_employees || 0) > 0) {
          setProcessedCount(Number(realProgress.processed_employees || 0))
          setProgress(Math.max(8, Math.min(99, Number(realProgress.percent || 0))))
        }
        if (s === 'queued') {
          setFinalizeStage('Queued: waiting for worker')
          setProgress((p) => Math.max(p, 22))
        } else if (s === 'processing') {
          setFinalizeStage('Processing employee payroll and PDFs')
          setProgress((p) => Math.max(p, 58))
        }
        if (s === 'finalized') {
          if (pollRef.current) clearInterval(pollRef.current)
          pollRef.current = null
          const total = Math.max(1, Number(status?.totals?.employee_count || totals.employeeCount || 1))
          setProcessedCount(total)
          setProgress(100)
          setFinalizeStage('Finalized and locked')
          setDone(true)
          setFinalizing(false)
          setWorking(false)
          await loadSelected(queuedRunId)
          await loadBatches()
          toast({ title: 'EXECOM payroll finalized', description: 'Payslips and payroll locks are now in place.' })
          setTimeout(() => setShowFinalizeModal(false), 1300)
          setQueuedRunId(null)
          return
        }
        if (s === 'failed') {
          if (pollRef.current) clearInterval(pollRef.current)
          pollRef.current = null
          setWorking(false)
          setFinalizing(false)
          setShowFinalizeModal(false)
          setProgress(0)
          setProcessedCount(0)
          setQueuedRunId(null)
          await loadSelected(queuedRunId)
          await loadBatches()
          toast({
            title: 'Finalize failed',
            description: status?.error_message || 'EXECOM payroll finalize job failed.',
            variant: 'error',
          })
          return
        }
        setProgress((p) => Math.min(96, p + 1))
      } catch {
        // Keep polling through transient status-check failures.
      }
    }, 2500)

    return () => {
      if (pollRef.current) clearInterval(pollRef.current)
      pollRef.current = null
    }
  }, [queuedRunId, totals.employeeCount])

  return (
    <div className={cn(PAGE_SHELL, PAGE_STACK)}>
      <div className="flex flex-wrap items-center justify-between gap-2">
        <Button
          variant="outline"
          size="sm"
          className="h-10 w-fit gap-2 rounded-xl border-border/80 bg-card font-semibold shadow-sm hover:bg-muted"
          onClick={() => navigate(`${hrBase}/compensation/generate-payslips?module=execom`)}
        >
          <ArrowLeft className="h-4 w-4" />
          Back
        </Button>
        <div className="flex flex-wrap items-center justify-end gap-2">
          {finalized ? (
            <>
              <Button type="button" variant="outline" size="sm" className="h-10 gap-2 rounded-xl border-border/80 bg-card font-semibold shadow-sm hover:bg-muted" onClick={downloadZip} disabled={loading || working}>
                <FileDown className="h-4 w-4" />
                Bulk Download PDF
              </Button>
              <Button type="button" size="sm" className="h-10 gap-2 rounded-xl bg-brand font-semibold text-brand-foreground shadow-sm hover:bg-brand-strong disabled:bg-brand/40" onClick={() => setShowSendBatchModal(true)} disabled={loading || working || sendingBatchPayslips}>
                {sendingBatchPayslips ? <Loader2 className="h-4 w-4 animate-spin" /> : <Send className="h-4 w-4" />}
                Bulk Send Payslip
              </Button>
              <Button type="button" variant="outline" size="sm" className="h-10 gap-2 rounded-xl border-border/80 bg-card font-semibold shadow-sm hover:bg-muted" onClick={downloadReport} disabled={loading || working}>
                <FileText className="h-4 w-4" />
                Payroll Report PDF
              </Button>
            </>
          ) : null}
          <Button
            type="button"
            variant="outline"
            size="sm"
            className="h-10 gap-2 rounded-xl border-border/80 bg-card font-semibold shadow-sm hover:bg-muted"
            onClick={() => loadSelected(selectedId)}
            disabled={loading || !selectedId}
          >
            <RefreshCw className={cn('h-4 w-4', loading ? 'animate-spin' : '')} />
            Refresh calculation
          </Button>
        </div>
      </div>

      <div className={cn(CARD, 'p-5 md:p-6')}>
        <div className="flex flex-col gap-5 @md:flex-row @md:items-start @md:justify-between">
          <div className="space-y-2">
            <Badge className="w-fit border-brand/30 bg-brand/10 text-brand hover:bg-brand/10">EXECOM Payroll</Badge>
            <h1 className={cn('text-[28px] font-extrabold leading-tight tracking-normal @md:text-[32px]', TEXT)}>
              Finalize Payroll
            </h1>
            <p className="max-w-3xl text-[15px] font-medium leading-7 text-muted-foreground">
              Review fixed Basic Pay drafts, shared deductions, statutory lines, and generated payslip rows, then finalize to lock the EXECOM payroll run.
            </p>
          </div>
          <div className={cn('flex w-fit items-center gap-3 rounded-xl border px-4 py-3 shadow-sm', finalized ? 'border-brand/30 bg-brand/10' : 'border-brand/25 bg-brand/10')}>
            <div>
              <p className="text-xs font-extrabold uppercase tracking-normal text-brand">
                {finalized ? 'Status: Finalized' : processing ? 'Status: Processing' : failed ? 'Status: Failed' : draft ? 'Status: Draft' : 'Status: Select Batch'}
              </p>
              <p className="text-xs text-muted-foreground">
                {finalized ? 'Locked - read only' : processing ? 'Finalizing in background...' : failed ? 'Retry finalization or recompute draft' : draft ? 'Editable before finalize' : 'Choose a batch to review'}
              </p>
            </div>
            <Lock className="h-5 w-5 text-brand" />
          </div>
        </div>

        <div className="mt-5 grid gap-3 @md:grid-cols-[minmax(0,1fr)_auto] @md:items-end">
          <div className="space-y-2">
            <p className="text-sm font-semibold text-muted-foreground">EXECOM batch</p>
            <div className="h-11 w-full rounded-xl border border-border/80 bg-background px-3 text-sm font-semibold text-foreground shadow-sm dark:bg-input/35 flex items-center">
              {selectedId
                ? `#${selectedId} · Execom · ${formatDate(selectedBatch?.pay_period_start)} - ${formatDate(selectedBatch?.pay_period_end)}`
                : 'No EXECOM batch selected'}
            </div>
          </div>
          <div className="flex flex-wrap gap-2">
            <Button variant="outline" className="h-11 rounded-xl border-border/80 bg-card font-semibold shadow-sm hover:bg-muted" disabled={!canRetryOrFinalize || working} onClick={recomputeBatch}>
              Recompute Draft
            </Button>
            <Button className="h-11 rounded-xl bg-brand px-6 font-bold text-brand-foreground shadow-sm hover:bg-brand-strong" disabled={!canRetryOrFinalize || working} onClick={finalizeBatch}>
              {working || finalizing ? <Loader2 className="mr-2 h-4 w-4 animate-spin" /> : null}
              {failed ? 'Retry Finalize Payroll' : 'Finalize Payroll'}
            </Button>
            <Button
              variant="outline"
              className="h-11 rounded-xl border-red-200/70 bg-card font-semibold text-red-600 shadow-sm hover:bg-red-50 dark:border-red-900/40 dark:text-red-400 dark:hover:bg-red-950/30"
              disabled={!canDeleteOrVoid || working}
              onClick={() => setShowDeleteModal(true)}
            >
              {working ? <Loader2 className="mr-2 h-4 w-4 animate-spin" /> : <Trash2 className="mr-2 h-4 w-4" />}
              {finalized ? 'Void Payroll' : 'Delete Draft'}
            </Button>
          </div>
        </div>

        <div className="mt-4 grid grid-cols-1 gap-3 text-sm @md:grid-cols-3">
          <div className="rounded-xl border border-border/80 bg-background px-4 py-3 text-muted-foreground dark:bg-input/30">
            <span className="font-semibold text-foreground">Pay Cycle:</span> {selectedBatch?.pay_cycle?.name || selectedBatch?.pay_cycle_name || 'EXECOM Payroll'}
          </div>
          <div className="rounded-xl border border-border/80 bg-background px-4 py-3 text-muted-foreground dark:bg-input/30">
            <span className="font-semibold text-foreground">Cut-off:</span> {formatDate(selectedBatch?.pay_period_start)} - {formatDate(selectedBatch?.pay_period_end)}
          </div>
          <div className="rounded-xl border border-border/80 bg-background px-4 py-3 text-muted-foreground dark:bg-input/30">
            <span className="font-semibold text-foreground">Batch:</span> {selectedId ? `#${selectedId}` : '—'} {selectedBatch?.status ? `· ${selectedBatch.status}` : ''}
          </div>
        </div>

        {!finalized ? (
          <p className="mt-4 flex items-start gap-2 rounded-xl border border-brand/20 bg-brand/10 px-4 py-3 text-sm text-muted-foreground">
            <Lock className="mt-0.5 h-4 w-4 shrink-0 text-brand" />
            <span>Figures below are computed for review only. PDFs are available after you confirm and finalize this EXECOM payroll.</span>
          </p>
        ) : (
          <p className="mt-4 flex items-start gap-2 rounded-xl border border-brand/30 bg-brand/10 px-4 py-3 text-sm text-foreground">
            <Lock className="mt-0.5 h-4 w-4 shrink-0 text-brand" />
            <span>
              <span className="font-semibold text-brand">This EXECOM payroll has been finalized and is now locked.</span> You can view payslips, bulk download PDFs, or export the payroll report.
            </span>
          </p>
        )}
      </div>

      <div className="grid grid-cols-1 gap-4 @lg:grid-cols-4">
        <MetricCard label="Total gross pay" value={loading ? null : formatPeso(totals.gross)} icon={<ArrowUpRight className="h-6 w-6 text-brand" />} />
        <MetricCard label="Total deductions" value={loading ? null : formatPeso(totals.deductions)} icon={<ArrowUpRight className="h-6 w-6 text-brand" />} />
        <MetricCard label="Total net pay" value={loading ? null : formatPeso(totals.net)} accent icon={<PhilippinePeso className="h-6 w-6 text-brand" />} />
        <MetricCard label="Employee count" value={loading ? null : String(totals.employeeCount)} icon={<Users className="h-6 w-6 text-muted-foreground" />} />
      </div>

      <Card className={cn(CARD, 'p-0')}>
        <CardContent className="p-0">
          <div className="flex flex-col gap-3 px-4 py-4 @md:flex-row @md:items-center @md:justify-between @md:px-5">
            <div>
              <h2 className={cn('text-[22px] font-bold leading-snug @md:text-2xl', TEXT)}>Employees</h2>
              <p className="mt-1 text-sm font-normal leading-snug text-muted-foreground">
                Draft and finalized EXECOM payslip rows for the selected batch.
              </p>
            </div>
            <div className="flex w-full max-w-md items-center gap-2">
              <Search className="h-4 w-4 shrink-0 text-muted-foreground" />
              <Input
                value={search}
                onChange={(event) => setSearch(event.target.value)}
                placeholder="Search employee..."
                className="h-10 rounded-xl border-border/80 bg-background text-foreground dark:bg-input/35"
              />
            </div>
          </div>

          <div className="w-full px-0 pb-4 pt-1">
            <div className="w-full overflow-x-auto">
              <Table className="min-w-[1260px] border-0 [&_td]:border-0 [&_th]:border-0">
                <TableHeader className="[&_tr]:border-0">
                  <TableRow className="border-0 bg-background hover:bg-background dark:bg-input/25 dark:hover:bg-input/25">
                    <TableHead className="h-auto py-2.5 pl-4 pr-2 text-left text-[12px] font-bold uppercase tracking-normal text-muted-foreground first:pl-5">Employee</TableHead>
                    <TableHead className="h-auto py-2.5 px-2 text-left text-[12px] font-bold uppercase tracking-normal text-muted-foreground">Department</TableHead>
                    <TableHead className="h-auto py-2.5 px-2 text-right text-[12px] font-bold uppercase tracking-normal text-muted-foreground">Basic Salary (Period)</TableHead>
                    <TableHead className="h-auto py-2.5 px-2 text-right text-[12px] font-bold uppercase tracking-normal text-muted-foreground">Allowance</TableHead>
                    <TableHead className="h-auto py-2.5 px-2 text-right text-[12px] font-bold uppercase tracking-normal text-muted-foreground">Daily Rate</TableHead>
                    <TableHead className="h-auto py-2.5 px-2 text-right text-[12px] font-bold uppercase tracking-normal text-muted-foreground">Gross Pay</TableHead>
                    <TableHead className="h-auto py-2.5 px-2 text-right text-[12px] font-bold uppercase tracking-normal text-muted-foreground">Total Deductions</TableHead>
                    <TableHead className="h-auto py-2.5 px-2 text-right text-[12px] font-bold uppercase tracking-normal text-brand">Net Pay</TableHead>
                    <TableHead className="h-auto py-2.5 px-2 text-left text-[12px] font-bold uppercase tracking-normal text-muted-foreground">Status</TableHead>
                    <TableHead className="h-auto min-w-[160px] py-2.5 pl-2 pr-4 text-right text-[12px] font-bold uppercase tracking-normal text-muted-foreground @md:pr-5">Actions</TableHead>
                  </TableRow>
                </TableHeader>
                <TableBody className="[&_tr]:border-0">
                  {pageRows.map((row) => {
                    const employeeName = row.employee?.name || row.employee_name || `Employee #${row.user_id}`
                    const employeeCode = row.employee?.employee_code || row.employee_code || 'EXECOM'
                    return (
                      <TableRow key={row.id} className="border-0 transition-colors hover:bg-muted/35">
                        <TableCell className="py-3 pl-5 pr-2">
                          <div className="flex min-w-0 items-center gap-2.5">
                            <Avatar className="h-9 w-9 border border-border/70 bg-background">
                              <AvatarFallback className="bg-brand/10 text-[11px] font-bold text-brand">{initials(employeeName)}</AvatarFallback>
                            </Avatar>
                            <div className="min-w-0">
                              <p className={cn('truncate text-sm font-semibold', TEXT)}>{employeeName}</p>
                              <p className="truncate text-xs text-muted-foreground">{employeeCode}</p>
                            </div>
                          </div>
                        </TableCell>
                        <TableCell className="py-3 px-2 text-sm text-muted-foreground">{rowDepartment(row)}</TableCell>
                        <TableCell className="py-3 px-2 text-right text-sm font-semibold tabular-nums text-foreground">{rowBasicSalaryReference(row)}</TableCell>
                        <TableCell className="py-3 px-2 text-right text-sm font-semibold tabular-nums text-foreground">{formatPeso(rowAllowance(row))}</TableCell>
                        <TableCell className="py-3 px-2 text-right text-sm font-semibold tabular-nums text-foreground">{formatPeso(rowDailyRate(row))}</TableCell>
                        <TableCell className="py-3 px-2 text-right text-sm font-semibold tabular-nums text-foreground">{formatPeso(row.gross_pay)}</TableCell>
                        <TableCell className="py-3 px-2 text-right text-sm font-semibold tabular-nums text-foreground">{formatPeso(row.total_deductions)}</TableCell>
                        <TableCell className="py-3 px-2 text-right text-sm font-extrabold tabular-nums text-brand">{formatPeso(row.net_pay)}</TableCell>
                        <TableCell className="py-3 px-2">{statusPill(row.status)}</TableCell>
                        <TableCell className="py-3 pl-2 pr-5 text-right">
                          <div className="flex flex-wrap items-center justify-end gap-2">
                            <Button
                              size="sm"
                              variant="outline"
                              className="h-8 rounded-lg border-border/80 bg-background font-semibold"
                              onClick={() => setBreakdownRow(row)}
                            >
                              View breakdown
                            </Button>
                            <Button
                              size="sm"
                              variant="outline"
                              className="h-8 rounded-lg border-border/80 bg-background font-semibold"
                              onClick={() => navigate(`${hrBase}/compensation/payslips/${row.id}/view?return_to=${encodeURIComponent(`${hrBase}/execom/payroll/finalize?batch_run_id=${selectedId}`)}`)}
                            >
                              View Payslip
                            </Button>
                          </div>
                        </TableCell>
                      </TableRow>
                    )
                  })}
                  {!loading && visiblePayslips.length === 0 ? (
                    <TableRow>
                      <TableCell colSpan={10} className="py-10 text-center text-sm text-muted-foreground">
                        No payslips found for this batch.
                      </TableCell>
                    </TableRow>
                  ) : null}
                  {loading ? (
                    <TableRow>
                      <TableCell colSpan={9} className="py-10 text-center text-sm text-muted-foreground">
                        Loading EXECOM payroll...
                      </TableCell>
                    </TableRow>
                  ) : null}
                </TableBody>
              </Table>
            </div>
            {visiblePayslips.length > 0 ? (
              <div className="mt-3 flex flex-col gap-2 px-4 text-sm text-muted-foreground @sm:flex-row @sm:items-center @sm:justify-between @sm:px-5">
                <span>
                  Page {page} of {pageCount} · {visiblePayslips.length} employee{visiblePayslips.length === 1 ? '' : 's'}
                </span>
                <div className="flex gap-2">
                  <Button
                    type="button"
                    variant="outline"
                    size="sm"
                    className="h-8 rounded-lg border-border/80 bg-background text-xs font-semibold"
                    disabled={page <= 1}
                    onClick={() => setPage((prev) => Math.max(1, prev - 1))}
                  >
                    Previous
                  </Button>
                  <Button
                    type="button"
                    variant="outline"
                    size="sm"
                    className="h-8 rounded-lg border-border/80 bg-background text-xs font-semibold"
                    disabled={page >= pageCount}
                    onClick={() => setPage((prev) => Math.min(pageCount, prev + 1))}
                  >
                    Next
                  </Button>
                </div>
              </div>
            ) : null}
          </div>
        </CardContent>
      </Card>
      <Dialog open={showFinalizeModal} onOpenChange={() => {}}>
        <DialogContent showCloseButton={false} className="sm:max-w-md">
          <DialogHeader>
            <DialogTitle className="text-xl font-bold text-foreground">
              {done ? 'EXECOM payroll finalized' : 'Finalizing EXECOM payroll'}
            </DialogTitle>
            <DialogDescription className="text-sm text-muted-foreground">
              {done
                ? 'All employees in this EXECOM batch were finalized successfully.'
                : `Finalizing EXECOM payroll for ${Math.max(1, Number(totals.employeeCount || payslips.length || 1))} employees...`}
            </DialogDescription>
          </DialogHeader>
          <div className="space-y-3 py-2">
            <Progress value={progress} indicatorClassName="bg-brand transition-all duration-300" />
            <p className="text-xs font-medium text-muted-foreground">{done ? 'Finalized and locked' : finalizeStage}</p>
            <div className="flex items-center justify-between text-xs text-muted-foreground">
              <span>{done ? 'Done' : `${processedCount}/${Math.max(1, Number(totals.employeeCount || payslips.length || 1))}`}</span>
              <span className="font-semibold text-foreground">{progress}%</span>
            </div>
            {done ? (
              <div className="flex items-center gap-2 rounded-lg border border-brand/30 bg-brand/10 px-3 py-2 text-sm text-brand">
                <CheckCircle2 className="h-4 w-4" />
                Success! Payslips and payroll locks are now in place.
              </div>
            ) : null}
          </div>
        </DialogContent>
      </Dialog>
      <Dialog open={showDeleteModal} onOpenChange={(open) => !working && setShowDeleteModal(open)}>
        <DialogContent className="sm:max-w-md">
          <DialogHeader>
            <DialogTitle>{finalized ? 'Void EXECOM payroll?' : 'Delete EXECOM draft?'}</DialogTitle>
            <DialogDescription>
              {finalized
                ? 'This will void the finalized EXECOM payroll batch, preserve audit history, and remove it from active payroll lists.'
                : 'This will delete the EXECOM draft batch and its draft payslip rows. This action cannot be undone.'}
            </DialogDescription>
          </DialogHeader>
          <div className="rounded-xl border border-border/80 bg-background p-3 text-sm text-muted-foreground dark:bg-input/30">
            <p className="font-semibold text-foreground">Batch #{selectedId || '—'}</p>
            <p>Execom</p>
            <p>{formatDate(selectedBatch?.pay_period_start)} - {formatDate(selectedBatch?.pay_period_end)}</p>
          </div>
          <DialogFooter>
            <Button variant="outline" disabled={working} onClick={() => setShowDeleteModal(false)}>
              Cancel
            </Button>
            <Button
              className="bg-red-600 text-white hover:bg-red-700"
              disabled={working}
              onClick={deleteOrVoidBatch}
            >
              {working ? <Loader2 className="mr-2 h-4 w-4 animate-spin" /> : <Trash2 className="mr-2 h-4 w-4" />}
              {finalized ? 'Void Payroll' : 'Delete Draft'}
            </Button>
          </DialogFooter>
        </DialogContent>
      </Dialog>
      <Dialog
        open={showSendBatchModal}
        onOpenChange={(open) => {
          if (!sendingBatchPayslips) setShowSendBatchModal(open)
        }}
      >
        <DialogContent className="sm:max-w-md">
          <DialogHeader>
            <DialogTitle>Bulk send EXECOM payslips?</DialogTitle>
            <DialogDescription>
              Send finalized EXECOM payslips to all {totals.employeeCount} employees in this batch so they appear in employee My Payslips?
            </DialogDescription>
          </DialogHeader>
          <div className="rounded-xl border border-border/80 bg-background p-3 text-sm text-muted-foreground dark:bg-input/30">
            <p className="font-semibold text-foreground">Batch #{selectedId || '—'}</p>
            <p>Execom</p>
            <p>{formatDate(selectedBatch?.pay_period_start)} - {formatDate(selectedBatch?.pay_period_end)}</p>
          </div>
          <DialogFooter>
            <Button variant="outline" disabled={sendingBatchPayslips} onClick={() => setShowSendBatchModal(false)}>
              Cancel
            </Button>
            <Button disabled={sendingBatchPayslips || totals.employeeCount <= 0} onClick={bulkSendBatchPayslips}>
              {sendingBatchPayslips ? <Loader2 className="mr-2 h-4 w-4 animate-spin" /> : <Send className="mr-2 h-4 w-4" />}
              Send all payslips
            </Button>
          </DialogFooter>
        </DialogContent>
      </Dialog>
      <Dialog open={breakdownRow != null} onOpenChange={(open) => !open && setBreakdownRow(null)}>
        <DialogContent className="max-h-[90vh] overflow-y-auto rounded-2xl border-border bg-card text-card-foreground sm:max-w-2xl">
          <DialogHeader>
            <DialogTitle>Payroll breakdown</DialogTitle>
            <DialogDescription>
              EXECOM fixed Basic Pay, allowance, government deduction, and other deduction lines for {breakdownRow?.employee?.name || breakdownRow?.employee_name || 'this employee'}.
            </DialogDescription>
          </DialogHeader>
          {breakdownRow ? (
            <div className="grid gap-4">
              <div className="grid gap-3 rounded-xl border border-border/80 bg-background p-4 text-sm dark:bg-input/30 sm:grid-cols-2">
                <div>
                  <p className="text-xs font-semibold uppercase text-muted-foreground">Basic Salary (Period)</p>
                  <p className="mt-1 font-bold tabular-nums text-foreground">{rowBasicSalaryReference(breakdownRow)}</p>
                </div>
                <div>
                  <p className="text-xs font-semibold uppercase text-muted-foreground">Daily Rate</p>
                  <p className="mt-1 font-bold tabular-nums text-foreground">{formatPeso(rowDailyRate(breakdownRow))}</p>
                </div>
              </div>
              <div>
                <p className="mb-2 text-sm font-bold text-foreground">Earnings</p>
                <div className="divide-y divide-border/70 rounded-xl border border-border/80">
                  {earningLines(breakdownRow).filter((line) => lineAmount(line) > 0).map((line, idx) => (
                    <div key={`${line?.key || line?.label || 'earning'}-${idx}`} className="flex items-center justify-between gap-3 px-4 py-2 text-sm">
                      <span className="text-muted-foreground">{line?.label || line?.name || 'Earning'}</span>
                      <span className="font-semibold tabular-nums text-foreground">{formatPeso(lineAmount(line))}</span>
                    </div>
                  ))}
                </div>
              </div>
              <div>
                <p className="mb-2 text-sm font-bold text-foreground">Deductions</p>
                <div className="divide-y divide-border/70 rounded-xl border border-border/80">
                  {deductionLines(breakdownRow).filter((line) => lineAmount(line) > 0).map((line, idx) => (
                    <div key={`${line?.key || line?.label || 'deduction'}-${idx}`} className="flex items-center justify-between gap-3 px-4 py-2 text-sm">
                      <span className="text-muted-foreground">{line?.label || line?.name || 'Deduction'}</span>
                      <span className="font-semibold tabular-nums text-foreground">{formatPeso(lineAmount(line))}</span>
                    </div>
                  ))}
                </div>
              </div>
            </div>
          ) : null}
          <DialogFooter>
            <Button variant="outline" onClick={() => setBreakdownRow(null)}>Close</Button>
          </DialogFooter>
        </DialogContent>
      </Dialog>
    </div>
  )
}
