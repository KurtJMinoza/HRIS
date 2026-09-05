import { useCallback, useEffect, useMemo, useState } from 'react'
import {
  BadgeCheck,
  CheckCircle2,
  CircleDollarSign,
  ClipboardList,
  History,
  Info,
  Loader2,
  Plus,
  RefreshCw,
  Search,
  ShieldCheck,
  Undo2,
  User,
  XCircle,
} from 'lucide-react'
import { AgcBrandLogo } from '@/components/AgcBrandLogo'
import {
  createAdminRefund,
  getAdminRefundCounts,
  getAdminRefundDetail,
  getAdminRefunds,
  getEmployees,
  getPayCycles,
  previewAdminRefund,
  previewPayCycle,
  transitionAdminRefund,
  updateAdminRefund,
} from '@/api'
import { useAuth } from '@/contexts/AuthContext'
import { AdminDataTableActions } from '@/components/admin/AdminDataTableActions'
import { ApprovalQueueTabBadge } from '@/components/admin/ApprovalQueueTabBadge'
import { Button } from '@/components/ui/button'
import { Card, CardContent, CardDescription, CardHeader, CardTitle } from '@/components/ui/card'
import {
  Dialog,
  DialogContent,
  DialogDescription,
  DialogFooter,
  DialogHeader,
  DialogTitle,
} from '@/components/ui/dialog'
import { Input } from '@/components/ui/input'
import { Label } from '@/components/ui/label'
import {
  Table,
  TableBody,
  TableCell,
  TableHead,
  TableHeader,
  TableRow,
} from '@/components/ui/table'
import { Textarea } from '@/components/ui/textarea'
import { useToast } from '@/components/ui/use-toast'
import {
  ADMIN_FORM_DIALOG_FOOTER_CLASS,
  ADMIN_FORM_DIALOG_PRIMARY_BUTTON_CLASS,
} from '@/lib/adminFormDialogStyles'
import {
  REFUND_REASONS,
  REFUND_STATUSES,
  formatPeso,
  refundComponentRows,
  refundComponentPayslipLine,
  refundComponentReportColumn,
} from '@/lib/refundConstants'
import {
  requestModuleActionsTdClass,
  requestModuleHeadRowClass,
  requestModuleRowClass,
  requestModuleTableClass,
  requestModuleTdClass,
  requestModuleThClass,
  requestModuleThRightClass,
} from '@/lib/requestModuleTable'
import { cn } from '@/lib/utils'

const PAGE_SHELL = 'w-full min-w-0 bg-background px-3 py-4 text-foreground sm:px-4 md:px-6 lg:px-8'
const CARD_SHELL = 'rounded-[1.35rem] border border-border/70 bg-card text-card-foreground shadow-[0_14px_40px_rgba(15,23,42,0.06)] dark:shadow-black/25'
const ORANGE_BUTTON = 'bg-brand text-brand-foreground shadow-sm shadow-brand/20 hover:bg-brand-strong'
const TABLE_HEAD = 'bg-[#fff8f1] text-[11px] font-extrabold uppercase tracking-[0.04em] text-muted-foreground dark:bg-input/25'

const refundCreateModalShellClass =
  'flex max-h-[min(94dvh,calc(100dvh-1.5rem))] w-[calc(100vw-1.5rem)] max-w-5xl flex-col overflow-hidden rounded-2xl border border-border/80 bg-card p-0 text-card-foreground shadow-[0_24px_80px_-28px_rgba(0,0,0,0.55)] sm:max-h-[min(94vh,calc(100dvh-1.5rem))] sm:w-[min(calc(100vw-2rem),64rem)] dark:border-white/10 dark:bg-card'
const refundDetailModalShellClass =
  'flex max-h-[min(94dvh,calc(100dvh-1.5rem))] w-[calc(100vw-1.5rem)] max-w-[calc(100vw-1.5rem)] flex-col overflow-hidden rounded-2xl border border-border/80 bg-card p-0 text-card-foreground shadow-[0_24px_80px_-28px_rgba(0,0,0,0.55)] sm:max-h-[min(94vh,calc(100dvh-1.5rem))] sm:w-[min(calc(100vw-2rem),60rem)] dark:border-white/10 dark:bg-card'
const refundCreateModalInnerClass = 'flex min-h-0 flex-1 flex-col gap-0 overflow-hidden p-0'
const refundCreateModalCloseClass =
  'right-3 top-3 size-9 rounded-lg border-border/80 bg-card/95 text-foreground shadow-md hover:bg-muted sm:right-4 sm:top-4 sm:size-10 dark:border-white/10 dark:bg-card'
const refundModalLabelClass = 'text-sm font-semibold tracking-tight text-foreground sm:text-base'
const refundModalFieldClass =
  'h-11 w-full rounded-xl border-border/80 bg-background px-3 text-base font-medium tabular-nums text-foreground shadow-sm transition focus-visible:border-brand focus-visible:ring-brand/25 sm:h-12 sm:px-4 dark:border-white/12 dark:bg-background/40 dark:focus-visible:border-brand/70'
const refundModalSelectClass =
  'h-11 w-full rounded-xl border border-brand/40 bg-background px-4 text-base font-semibold text-foreground shadow-sm outline-none transition focus:border-brand focus:ring-4 focus:ring-brand/15 sm:h-12 sm:px-4 dark:bg-background/40 dark:focus:ring-brand/20'
const refundModalHintClass = 'text-[13px] leading-relaxed text-muted-foreground'

const REFUND_REASON_GROUP_LABELS = {
  attendance: 'Attendance',
  overtime: 'Overtime',
  holiday: 'Holiday',
  leave: 'Leave',
  schedule: 'Schedule',
  payroll_computation: 'Payroll computation',
  other: 'Other',
}

const STATUS_FILTERS = [
  { value: 'all', label: 'All' },
  { value: 'requests', label: 'Active' },
  { value: 'pending_approval', label: 'Pending Approval' },
  { value: 'processed', label: 'Processed' },
  { value: 'history', label: 'History' },
]

const STATUS_BADGE_CLASS = {
  draft: 'bg-secondary text-secondary-foreground',
  submitted: 'bg-blue-100 text-blue-800 dark:bg-blue-500/15 dark:text-blue-300',
  payroll_review: 'bg-violet-100 text-violet-800 dark:bg-violet-500/15 dark:text-violet-300',
  approved: 'bg-green-100 text-green-800 dark:bg-green-500/15 dark:text-green-300',
  rejected: 'bg-red-100 text-red-800 dark:bg-red-500/15 dark:text-red-300',
  queued_for_payroll: 'bg-amber-100 text-amber-900 dark:bg-amber-500/20 dark:text-amber-200',
  processed: 'bg-emerald-100 text-emerald-800 dark:bg-emerald-500/15 dark:text-emerald-300',
  cancelled: 'bg-muted text-muted-foreground',
  voided: 'bg-muted text-muted-foreground line-through',
}

function statusLabel(status) {
  return REFUND_STATUSES[status]?.label || status
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

function formatDateTime(value) {
  if (!value) return '—'
  try {
    return new Intl.DateTimeFormat(undefined, {
      month: 'short', day: 'numeric', year: 'numeric', hour: 'numeric', minute: '2-digit',
    }).format(new Date(value))
  } catch {
    return value
  }
}

function StatusBadge({ status }) {
  return (
    <span className={cn('inline-flex items-center rounded-full px-2 py-0.5 text-xs font-semibold', STATUS_BADGE_CLASS[status] || 'bg-secondary')}>
      {statusLabel(status)}
    </span>
  )
}

function directionBadge(direction) {
  const map = {
    underpayment: { label: 'Underpayment', cls: 'bg-emerald-100 text-emerald-800 dark:bg-emerald-500/15 dark:text-emerald-300' },
    overpayment: { label: 'Overpayment', cls: 'bg-red-100 text-red-800 dark:bg-red-500/15 dark:text-red-300' },
    payroll_adjustment: { label: 'Adjustment', cls: 'bg-secondary text-secondary-foreground' },
  }
  const cfg = map[direction] || map.payroll_adjustment
  return <span className={cn('inline-flex items-center rounded-full px-2 py-0.5 text-[11px] font-medium', cfg.cls)}>{cfg.label}</span>
}

function RefundStatCard({ icon: Icon, value, label, tone = 'text-brand' }) {
  return (
    <Card className={cn(CARD_SHELL, 'overflow-hidden')}>
      <CardContent className="flex items-center justify-between gap-3 p-4">
        <div>
          <p className="text-xs font-bold uppercase tracking-[0.04em] text-muted-foreground">{label}</p>
          <p className="mt-1 text-2xl font-extrabold tabular-nums text-foreground">{value ?? 0}</p>
        </div>
        <div className={cn('flex size-11 items-center justify-center rounded-xl border border-border/70 bg-background/80', tone)}>
          <Icon className="size-5" aria-hidden />
        </div>
      </CardContent>
    </Card>
  )
}

function CalculationPanel({ preview, compact = false }) {
  if (!preview) return null
  const components = refundComponentRows(preview.components || [])
  const signed = typeof preview.refund_signed_amount === 'number' ? preview.refund_signed_amount : null
  const refundAmount = signed !== null ? Math.abs(signed) : Number(preview.refund_amount || 0)

  return (
    <div className="space-y-3">
      <div className="grid gap-2 sm:grid-cols-3">
        <div className="rounded-xl border border-border/60 bg-muted/40 p-3">
          <p className="text-[11px] font-bold uppercase tracking-wide text-muted-foreground">Original Payroll</p>
          <p className="mt-1 font-mono text-lg font-semibold tabular-nums">{formatPeso(preview.original_amount)}</p>
        </div>
        <div className="rounded-xl border border-border/60 bg-muted/40 p-3">
          <p className="text-[11px] font-bold uppercase tracking-wide text-muted-foreground">Corrected Payroll</p>
          <p className="mt-1 font-mono text-lg font-semibold tabular-nums">{formatPeso(preview.corrected_amount)}</p>
        </div>
        <div className={cn(
          'rounded-xl border p-3',
          signed != null && signed < -0.004
            ? 'border-red-300 bg-red-50 dark:border-red-500/30 dark:bg-red-500/10'
            : 'border-emerald-300 bg-emerald-50 dark:border-emerald-500/30 dark:bg-emerald-500/10',
        )}>
          <p className="text-[11px] font-bold uppercase tracking-wide text-muted-foreground">
            {signed != null && signed < -0.004 ? 'Recoverable Overpayment' : 'Refundable Difference'}
          </p>
          <p className={cn(
            'mt-1 font-mono text-lg font-semibold tabular-nums',
            signed != null && signed < -0.004 ? 'text-red-700 dark:text-red-300' : 'text-emerald-700 dark:text-emerald-300',
          )}>
            {formatPeso(refundAmount)}
          </p>
        </div>
      </div>

      {!compact && (
        <div className="overflow-hidden rounded-xl border border-border/60">
          <Table>
            <TableHeader>
              <TableRow className={TABLE_HEAD}>
                <TableHead>Component</TableHead>
                <TableHead className="text-right">Original</TableHead>
                <TableHead className="text-right">Correct</TableHead>
                <TableHead className="text-right">Difference</TableHead>
                <TableHead>Payslip line</TableHead>
                <TableHead>Report column</TableHead>
              </TableRow>
            </TableHeader>
            <TableBody>
              {components.map((c) => (
                <TableRow key={c.key || c.label}>
                  <TableCell className="font-medium">{c.label}</TableCell>
                  <TableCell className="text-right font-mono tabular-nums">{formatPeso(c.paid)}</TableCell>
                  <TableCell className="text-right font-mono tabular-nums">{formatPeso(c.expected)}</TableCell>
                  <TableCell className={cn('text-right font-mono font-semibold tabular-nums', c.difference > 0 && 'text-emerald-600 dark:text-emerald-400', c.difference < 0 && 'text-red-600 dark:text-red-400')}>
                    {c.difference > 0 ? '+' : ''}{formatPeso(c.difference)}
                  </TableCell>
                  <TableCell className="text-sm text-muted-foreground">
                    {refundComponentPayslipLine(c.key, preview.reason_label || preview.reasonLabel || '')}
                  </TableCell>
                  <TableCell className="text-sm font-medium text-foreground">
                    {refundComponentReportColumn(c.key, preview.reason || '')}
                  </TableCell>
                </TableRow>
              ))}
            </TableBody>
          </Table>
        </div>
      )}

      {(preview.direction === 'overpayment') && (
        <p className="rounded-lg bg-amber-50 px-3 py-2 text-sm text-amber-900 dark:bg-amber-500/10 dark:text-amber-200">
          This is an <strong>overpayment</strong> — it will be recorded as a Payroll Recovery, not an employee refund.
        </p>
      )}

      {preview.finalized && (
        <p className="rounded-lg border border-blue-200 bg-blue-50 px-3 py-2 text-sm text-blue-900 dark:border-blue-500/30 dark:bg-blue-500/10 dark:text-blue-200">
          🔒 {preview.lock_message || 'This payroll period has already been finalized and is locked.'}
          {' '}The adjustment will be applied to the <strong>next eligible payroll</strong>; the original finalized run is preserved.
        </p>
      )}

      {preview.application_note && (
        <p className="flex gap-2 rounded-lg border border-border/60 bg-muted/40 px-3 py-2 text-sm leading-relaxed text-foreground">
          <Info className="mt-0.5 size-4 shrink-0 text-brand" aria-hidden />
          <span>{preview.application_note}</span>
        </p>
      )}

      {(preview.warnings || []).map((w, i) => (
        <p key={i} className="rounded-lg bg-muted px-3 py-2 text-xs text-muted-foreground">{w}</p>
      ))}

      {preview.days?.length > 0 && !compact && (
        <details className="rounded-xl border border-border/60 px-3 py-2">
          <summary className="cursor-pointer text-sm font-semibold">Engine day details ({preview.days.length})</summary>
          <div className="mt-2 space-y-2">
            {preview.days.map((d) => (
              <div key={d.date} className="rounded-lg bg-muted/50 p-2 text-xs">
                <div className="flex flex-wrap items-center gap-2">
                  <span className="font-mono font-semibold">{formatDate(d.date)}</span>
                  <span className="text-muted-foreground">{d.rule_code}{d.holiday_name ? ` · ${d.holiday_name}` : ''}{d.is_rest_day ? ' · Rest Day' : ''}</span>
                  <span className="ml-auto text-muted-foreground">
                    paid via <strong>{d.paid_source === 'finalized_payroll' ? 'finalized payroll' : 'current engine'}</strong>
                  </span>
                </div>
                <div className="mt-1 grid grid-cols-2 gap-x-4 gap-y-1 sm:grid-cols-4">
                  {Object.entries(d.components || {}).map(([key, c]) => (
                    <span key={key} className="font-mono tabular-nums">
                      {c.label}: {formatPeso(c.paid)} → {formatPeso(c.expected)}
                    </span>
                  ))}
                </div>
              </div>
            ))}
          </div>
        </details>
      )}
    </div>
  )
}

function RefundDetailDialog({ refundId, open, onOpenChange, onActionDone, perms, initialRemarkMode = null }) {
  const { toast } = useToast()
  const [detail, setDetail] = useState(null)
  const [loading, setLoading] = useState(false)
  const [acting, setActing] = useState(false)
  const [remarkMode, setRemarkMode] = useState(null)
  const [remarks, setRemarks] = useState('')

  const load = useCallback(async () => {
    if (!refundId || !open) return
    setLoading(true)
    try {
      const res = await getAdminRefundDetail(refundId)
      setDetail(res.data)
    } catch (e) {
      toast({ title: 'Failed to load refund detail', description: e.message, variant: 'destructive' })
    } finally {
      setLoading(false)
    }
  }, [refundId, open, toast])

  useEffect(() => { load() }, [load])

  useEffect(() => {
    if (!open) {
      setRemarkMode(null)
      setRemarks('')
    }
  }, [open])

  useEffect(() => {
    if (open && initialRemarkMode) {
      setRemarkMode(initialRemarkMode)
      setRemarks('')
    }
  }, [initialRemarkMode, open])

  const doTransition = async (action, extra = {}) => {
    setActing(true)
    try {
      await transitionAdminRefund(refundId, action, extra)
      toast({ title: `Refund ${String(action).replace(/-/g, ' ')} successful.` })
      setRemarkMode(null)
      setRemarks('')
      await load()
      onActionDone?.()
    } catch (e) {
      toast({ title: 'Action failed', description: e.message, variant: 'destructive' })
    } finally {
      setActing(false)
    }
  }

  const calc = detail?.calculation

  return (
    <Dialog open={open} onOpenChange={onOpenChange}>
      <DialogContent
        overlayClassName="bg-black/55 backdrop-blur-sm dark:bg-black/70"
        closeButtonClassName={refundCreateModalCloseClass}
        className={refundDetailModalShellClass}
        innerClassName={refundCreateModalInnerClass}
        showCloseButton
      >
        <div className="min-h-0 flex-1 overflow-y-auto overscroll-contain">
          <DialogHeader className="relative overflow-hidden border-b border-border/70 bg-linear-to-br from-card via-card to-brand/5 px-4 pb-4 pt-4 text-left dark:to-brand/10 sm:px-7 sm:pb-5 sm:pt-7">
            <AgcBrandLogo className="mb-4 h-8 sm:mb-6 sm:h-9" />
            <div className="relative z-10 max-w-3xl space-y-2 pr-10 sm:space-y-3 sm:pr-12">
              <p className="text-[10px] font-black uppercase tracking-[0.18em] text-brand sm:text-[11px]">
                Refunds &amp; adjustments
              </p>
              <DialogTitle className="text-xl font-bold tracking-tight text-foreground sm:text-2xl">
                {detail?.refund_number || 'Refund details'}
              </DialogTitle>
              <DialogDescription className="text-sm leading-relaxed text-muted-foreground">
                {detail ? `${detail.employee_name} · ${detail.reason_label}` : 'Loading refund request…'}
              </DialogDescription>
              <div className="flex flex-wrap items-center gap-2 pt-1">
                {detail && <StatusBadge status={detail.status} />}
                {detail && directionBadge(detail.direction)}
              </div>
            </div>
            <RefundCreateModalHeaderArt />
          </DialogHeader>

          <div className="px-4 py-4 sm:px-7 sm:py-6">
            {loading && !detail && (
              <div className="flex items-center justify-center py-10"><Loader2 className="size-6 animate-spin" /></div>
            )}

            {detail && (
              <div className="space-y-5">
                <div className="grid gap-2 text-sm sm:grid-cols-2">
                  <div><span className="text-muted-foreground">Affected date:</span> <strong>{formatDate(detail.affected_date)}{detail.affected_date_to && detail.affected_date_to !== detail.affected_date ? ` → ${formatDate(detail.affected_date_to)}` : ''}</strong></div>
                  <div><span className="text-muted-foreground">Payroll cutoff:</span> <strong>{formatDate(detail.cutoff_start_date)} → {formatDate(detail.cutoff_end_date)}</strong></div>
                  <div><span className="text-muted-foreground">Created by:</span> <strong>{detail.created_by_name || '—'}</strong> · {formatDateTime(detail.created_at)}</div>
                  <div><span className="text-muted-foreground">Approved by:</span> <strong>{detail.approved_by_name || '—'}</strong>{detail.approved_at ? ` · ${formatDateTime(detail.approved_at)}` : ''}</div>
                  {detail.rejection_reason && <div className="sm:col-span-2"><span className="text-muted-foreground">Rejection reason:</span> <strong>{detail.rejection_reason}</strong></div>}
                  {detail.reason_notes && <div className="sm:col-span-2"><span className="text-muted-foreground">Notes:</span> {detail.reason_notes}</div>}
                </div>

                {calc && (
                  <CalculationPanel
                    preview={{
                      original_amount: calc.original_amount,
                      corrected_amount: calc.corrected_amount,
                      refund_signed_amount: calc.refund_signed_amount ?? calc.amounts?.refund ?? detail.refund_amount,
                      components: calc.components,
                      days: calc.days,
                      warnings: calc.warnings,
                      finalized: calc.finalized,
                      lock_message: detail.calculation?.lock_message || (calc.finalized ? 'This payroll period has already been finalized and is locked.' : null),
                      application_note: calc.application_note,
                      application_timing: calc.application_timing,
                      direction: detail.direction,
                    }}
                  />
                )}

                <div>
                  <h4 className="mb-2 text-sm font-bold uppercase tracking-wide text-muted-foreground">Audit Trail</h4>
                  <ol className="space-y-2">
                    {(detail.audit_trail || []).map((a) => (
                      <li key={a.id} className="rounded-lg border border-border/50 bg-muted/30 px-3 py-2 text-sm">
                        <div className="flex flex-wrap items-center gap-2">
                          <strong className="capitalize">{String(a.action).replace(/-/g, ' ')}</strong>
                          {a.from_status && a.to_status && (
                            <span className="text-xs text-muted-foreground">{statusLabel(a.from_status)} → {statusLabel(a.to_status)}</span>
                          )}
                          <span className="ml-auto text-xs text-muted-foreground">{a.user_name || 'System'} · {formatDateTime(a.created_at)}</span>
                        </div>
                        {a.remarks && <p className="mt-1 text-xs text-muted-foreground">{a.remarks}</p>}
                      </li>
                    ))}
                    {!(detail.audit_trail || []).length && <li className="text-sm text-muted-foreground">No audit entries.</li>}
                  </ol>
                </div>
              </div>
            )}
          </div>
        </div>

        <DialogFooter className={cn(ADMIN_FORM_DIALOG_FOOTER_CLASS, 'flex flex-wrap items-center')}>
          {remarkMode === null && detail && (
            <>
              {perms.canApprove && ['submitted', 'payroll_review'].includes(detail.status) && (
                <>
                  <Button size="sm" className={ADMIN_FORM_DIALOG_PRIMARY_BUTTON_CLASS} disabled={acting} onClick={() => doTransition('approve')}><BadgeCheck className="mr-1 size-4" /> Approve</Button>
                  <Button variant="destructive" size="sm" disabled={acting} onClick={() => { setRemarkMode('reject'); setRemarks('') }}><XCircle className="mr-1 size-4" /> Reject</Button>
                </>
              )}
              {perms.canApprove && detail.status === 'approved' && (
                <Button variant="outline" size="sm" disabled={acting} onClick={() => { setRemarkMode('void'); setRemarks('') }}><Undo2 className="mr-1 size-4" /> Void</Button>
              )}
            </>
          )}

          {remarkMode !== null && (
            <div className="w-full space-y-3">
              <div className="space-y-1">
                <Label>{remarkMode === 'void' ? 'Void reason *' : 'Rejection reason *'}</Label>
                <Textarea rows={2} value={remarks} onChange={(e) => setRemarks(e.target.value)} />
              </div>
              <div className="flex justify-end gap-2">
                <Button variant="ghost" size="sm" onClick={() => setRemarkMode(null)}>Cancel action</Button>
                <Button
                  size="sm"
                  className={ADMIN_FORM_DIALOG_PRIMARY_BUTTON_CLASS}
                  disabled={acting}
                  onClick={async () => {
                    if (!remarks.trim()) {
                      toast({ title: 'A reason is required.', variant: 'destructive' })
                      return
                    }
                    await doTransition(remarkMode, { remarks })
                  }}
                >
                  Confirm
                </Button>
              </div>
            </div>
          )}
        </DialogFooter>
      </DialogContent>
    </Dialog>
  )
}

function RefundCreateModalHeaderArt() {
  return (
    <div
      className="pointer-events-none absolute bottom-0 right-6 hidden h-44 w-44 items-center justify-center text-brand opacity-20 dark:opacity-25 xl:flex"
      aria-hidden
    >
      <CircleDollarSign className="size-40" strokeWidth={1.1} />
    </div>
  )
}

function RefundFormSection({ step, title, description, children, className }) {
  return (
    <section className={cn('rounded-2xl border border-border/70 bg-muted/15 p-4 shadow-sm dark:bg-muted/10 sm:p-5', className)}>
      <div className="mb-4 flex items-start gap-3">
        {step != null && (
          <span className="flex size-8 shrink-0 items-center justify-center rounded-full bg-brand text-sm font-black text-brand-foreground shadow-sm">
            {step}
          </span>
        )}
        <div className="min-w-0">
          <h3 className="text-base font-bold tracking-tight text-foreground sm:text-lg">{title}</h3>
          {description ? <p className={cn('mt-1', refundModalHintClass)}>{description}</p> : null}
        </div>
      </div>
      <div className="space-y-4 sm:space-y-5">{children}</div>
    </section>
  )
}

function CreateRefundDialog({ open, onOpenChange, onCreated, perms, editId = null }) {
  const { toast } = useToast()
  const [employeeQuery, setEmployeeQuery] = useState('')
  const [employees, setEmployees] = useState([])
  const [selectedEmployeeMeta, setSelectedEmployeeMeta] = useState(null)
  const [searching, setSearching] = useState(false)
  const [payCycles, setPayCycles] = useState([])
  const [payCyclesLoading, setPayCyclesLoading] = useState(false)
  const [payCycleId, setPayCycleId] = useState('')
  const [cyclePeriods, setCyclePeriods] = useState([])
  const [cyclePeriodsLoading, setCyclePeriodsLoading] = useState(false)
  const [selectedPeriodKey, setSelectedPeriodKey] = useState('')
  const [form, setForm] = useState({
    employee_id: '',
    reason: '',
    affected_date: '',
    affected_date_to: '',
    cutoff_start_date: '',
    cutoff_end_date: '',
    direct_refund_amount: '',
    reason_notes: '',
  })
  const [preview, setPreview] = useState(null)
  const [saving, setSaving] = useState(false)
  const [loadingEdit, setLoadingEdit] = useState(false)

  const reasonGroups = useMemo(() => {
    const grouped = new Map()
    for (const reason of REFUND_REASONS) {
      const key = reason.category || 'other'
      if (!grouped.has(key)) grouped.set(key, [])
      grouped.get(key).push(reason)
    }
    return [...grouped.entries()].map(([category, reasons]) => ({
      category,
      label: REFUND_REASON_GROUP_LABELS[category] || category,
      reasons,
    }))
  }, [])

  const scopedPayCycles = useMemo(() => {
    const companyId = selectedEmployeeMeta?.company_id
    if (!companyId) return payCycles
    return payCycles.filter((cycle) => {
      if (cycle.company_id == null && !(cycle.company_ids || []).length) return true
      if (Number(cycle.company_id) === Number(companyId)) return true
      return (cycle.company_ids || []).some((id) => Number(id) === Number(companyId))
    })
  }, [payCycles, selectedEmployeeMeta?.company_id])

  const resetForm = useCallback(() => {
    setForm({
      employee_id: '', reason: '', affected_date: '', affected_date_to: '',
      cutoff_start_date: '', cutoff_end_date: '',
      direct_refund_amount: '', reason_notes: '',
    })
    setEmployeeQuery('')
    setEmployees([])
    setSelectedEmployeeMeta(null)
    setPreview(null)
    setPayCycleId('')
    setCyclePeriods([])
    setSelectedPeriodKey('')
  }, [])

  useEffect(() => {
    if (!open) {
      resetForm()
      return undefined
    }
    let cancelled = false
    ;(async () => {
      setPayCyclesLoading(true)
      try {
        const res = await getPayCycles()
        if (!cancelled) setPayCycles(Array.isArray(res?.data) ? res.data : [])
      } catch (e) {
        if (!cancelled) {
          setPayCycles([])
          toast({ title: 'Failed to load pay cycles', description: e.message, variant: 'destructive' })
        }
      } finally {
        if (!cancelled) setPayCyclesLoading(false)
      }
    })()
    return () => { cancelled = true }
  }, [open, toast])

  useEffect(() => {
    if (!open) {
      resetForm()
      return undefined
    }
    if (!editId) return undefined
    let cancelled = false
    ;(async () => {
      setLoadingEdit(true)
      try {
        const res = await getAdminRefundDetail(editId)
        const d = res.data
        if (cancelled || !d) return
        setForm({
          employee_id: String(d.employee_id || ''),
          reason: d.reason || '',
          affected_date: d.affected_date || '',
          affected_date_to: d.affected_date_to && d.affected_date_to !== d.affected_date ? d.affected_date_to : '',
          cutoff_start_date: d.cutoff_start_date || '',
          cutoff_end_date: d.cutoff_end_date || '',
          direct_refund_amount: String(Number(d.calculation?.refund_signed_amount ?? d.refund_amount ?? 0)),
          reason_notes: d.reason_notes || '',
        })
        setSelectedEmployeeMeta({
          id: d.employee_id,
          name: d.employee_name,
          employee_code: d.employee_code,
          company_id: d.company_id,
        })
        setEmployeeQuery(d.employee_name || '')
        setPreview(null)
      } catch (e) {
        toast({ title: 'Failed to load draft', description: e.message, variant: 'destructive' })
      } finally {
        if (!cancelled) setLoadingEdit(false)
      }
    })()
    return () => { cancelled = true }
  }, [editId, open, resetForm, toast])

  useEffect(() => {
    if (employeeQuery.trim().length < 2) { setEmployees([]); return undefined }
    const t = setTimeout(async () => {
      setSearching(true)
      try {
        const res = await getEmployees({ q: employeeQuery.trim(), per_page: 10, active_filter: 'active' })
        setEmployees(res.employees || [])
      } catch { /* ignore */ }
      finally { setSearching(false) }
    }, 300)
    return () => clearTimeout(t)
  }, [employeeQuery])

  const selectedCycle = useMemo(
    () => scopedPayCycles.find((c) => String(c.id) === String(payCycleId)) || null,
    [scopedPayCycles, payCycleId],
  )

  useEffect(() => {
    if (!selectedCycle) {
      setCyclePeriods([])
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
        const res = await previewPayCycle({
          ...cycleSnapshot,
        })
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

  const applyPeriod = useCallback((period) => {
    if (!period) return
    const start = period.cut_off_start_date || ''
    const end = period.cut_off_end_date || ''
    setSelectedPeriodKey(`${start}|${end}|${period.pay_date || ''}`)
    setForm((f) => ({
      ...f,
      affected_date: start,
      affected_date_to: end && end !== start ? end : '',
      cutoff_start_date: start,
      cutoff_end_date: end,
    }))
    setPreview(null)
  }, [])

  const buildPayload = useCallback(() => ({
    employee_id: Number(form.employee_id),
    reason: form.reason,
    affected_date: form.affected_date,
    affected_date_to: form.affected_date_to || null,
    cutoff_start_date: form.cutoff_start_date || null,
    cutoff_end_date: form.cutoff_end_date || null,
    direct_refund_amount: form.direct_refund_amount !== ''
      ? Number(form.direct_refund_amount)
      : null,
    reason_notes: form.reason_notes || null,
  }), [form])

  const save = async (submit) => {
    setSaving(true)
    try {
      if (editId) {
        await updateAdminRefund(editId, buildPayload(), { submit })
      } else {
        await createAdminRefund(buildPayload(), { submit })
      }
      toast({ title: submit ? 'Refund submitted for review.' : 'Refund draft saved.' })
      resetForm()
      onOpenChange(false)
      onCreated?.()
    } catch (e) {
      toast({ title: 'Failed to save refund', description: e.message, variant: 'destructive' })
    } finally {
      setSaving(false)
    }
  }

  const canSave = form.employee_id && form.reason && form.affected_date && form.direct_refund_amount !== ''

  const selectEmployee = (emp) => {
    setForm((f) => ({ ...f, employee_id: emp.id }))
    setSelectedEmployeeMeta(emp)
    setPreview(null)
  }

  return (
    <Dialog open={open} onOpenChange={onOpenChange}>
      <DialogContent
        showCloseButton
        overlayClassName="bg-black/55 backdrop-blur-sm dark:bg-black/70"
        closeButtonClassName={refundCreateModalCloseClass}
        className={refundCreateModalShellClass}
        innerClassName={refundCreateModalInnerClass}
        aria-describedby="refund-create-desc"
      >
        <div className="min-h-0 flex-1 overflow-y-auto overscroll-contain">
          <DialogHeader className="relative overflow-hidden border-b border-border/70 bg-linear-to-br from-card via-card to-brand/5 px-4 pb-4 pt-4 text-left dark:to-brand/10 sm:px-7 sm:pb-5 sm:pt-7">
            <AgcBrandLogo className="mb-4 h-8 sm:mb-6 sm:h-9" />
            <div className="relative z-10 max-w-208 space-y-2 pr-10 sm:space-y-3 sm:pr-12">
              <p className="text-[10px] font-black uppercase tracking-[0.18em] text-brand sm:text-[11px]">
                Refunds &amp; adjustments
              </p>
              <DialogTitle className="text-xl font-bold tracking-tight text-foreground sm:text-2xl">
                {editId ? 'Edit Refund Draft' : 'Create Refund / Adjustment'}
              </DialogTitle>
              <DialogDescription id="refund-create-desc" className="max-w-3xl text-sm leading-relaxed text-muted-foreground">
                Recalculate the affected date through the schedule, attendance, OT, holiday, and payroll engines.
                The refund equals the difference between what payroll should have paid and what it actually paid.
              </DialogDescription>
            </div>
            <RefundCreateModalHeaderArt />
          </DialogHeader>

          <div className="px-4 py-4 sm:px-7 sm:py-6">
            {loadingEdit ? (
              <div className="flex flex-col items-center justify-center gap-3 py-20">
                <Loader2 className="size-8 animate-spin text-brand" />
                <p className="text-sm font-medium text-muted-foreground">Loading draft…</p>
              </div>
            ) : (
              <div className="space-y-5 sm:space-y-6">
                  <div className="flex items-start gap-3 rounded-xl border border-brand/20 bg-brand/5 px-3 py-3 text-sm leading-relaxed text-foreground shadow-sm dark:border-brand/25 dark:bg-brand/10 sm:gap-4 sm:px-4 sm:py-4 sm:text-base">
                    <span className="mt-0.5 flex size-7 shrink-0 items-center justify-center rounded-full bg-brand text-brand-foreground">
                      <Info className="size-4" strokeWidth={2.4} aria-hidden />
                    </span>
                    <p>
                      When an employee was underpaid or overpaid on a <strong>finalized</strong> payroll — for example missing attendance on the previous pay run — create a refund here. After approval, the adjustment is applied automatically on the <strong>next payroll</strong> as extra pay or a payroll recovery deduction. The closed payslip is never changed.
                    </p>
                  </div>

                  <RefundFormSection
                    step={1}
                    title="Employee & payroll cycle"
                    description="Choose the employee, then select which payroll cycle should reflect this refund or recovery."
                  >
                    <div className="space-y-2 sm:space-y-3">
                      <Label htmlFor="refund-employee-search" className={refundModalLabelClass}>
                        Employee <span className="text-destructive">*</span>
                      </Label>
                      <div className="relative">
                        <Search className="pointer-events-none absolute left-3 top-1/2 size-4 -translate-y-1/2 text-muted-foreground sm:left-4" aria-hidden />
                        <Input
                          id="refund-employee-search"
                          placeholder="Search by name or employee code…"
                          value={employeeQuery}
                          onChange={(e) => setEmployeeQuery(e.target.value)}
                          className={cn(refundModalFieldClass, 'pl-9 sm:pl-10')}
                        />
                      </div>
                      {searching && (
                        <p className="flex items-center gap-2 text-[13px] text-muted-foreground">
                          <Loader2 className="size-3.5 animate-spin" aria-hidden />
                          Searching employees…
                        </p>
                      )}
                      {employeeQuery.trim().length >= 2 && employees.length > 0 && (
                        <div className="max-h-52 overflow-y-auto rounded-xl border border-border/70 bg-background shadow-sm">
                          {employees.map((emp) => (
                            <button
                              key={emp.id}
                              type="button"
                              className={cn(
                                'block w-full border-b border-border/50 px-4 py-3 text-left text-sm transition last:border-b-0 hover:bg-brand/5',
                                String(emp.id) === String(form.employee_id) && 'bg-brand/10',
                              )}
                              onClick={() => selectEmployee(emp)}
                            >
                              <span className="font-semibold text-foreground">{emp.name}</span>
                              {emp.employee_code ? <span className="ml-1.5 text-xs text-muted-foreground">#{emp.employee_code}</span> : null}
                              <span className="mt-0.5 block text-xs text-muted-foreground">
                                {[emp.company_name, emp.department].filter(Boolean).join(' · ')}
                              </span>
                            </button>
                          ))}
                        </div>
                      )}
                      {selectedEmployeeMeta && (
                        <div className="rounded-xl border border-brand/25 bg-brand/4.5 px-4 py-3 shadow-sm dark:border-brand/25 dark:bg-brand/10 sm:px-5 sm:py-4">
                          <p className="text-[11px] font-bold uppercase tracking-[0.16em] text-brand">Selected employee</p>
                          <p className="mt-1.5 flex items-center gap-2 text-sm font-semibold text-foreground sm:text-base">
                            <User className="size-4 shrink-0 text-brand" aria-hidden />
                            {selectedEmployeeMeta.name}
                            {selectedEmployeeMeta.employee_code ? (
                              <span className="font-normal text-muted-foreground">#{selectedEmployeeMeta.employee_code}</span>
                            ) : null}
                          </p>
                          {[selectedEmployeeMeta.company_name, selectedEmployeeMeta.department, selectedEmployeeMeta.employment_type].filter(Boolean).length > 0 && (
                            <p className="mt-1 text-xs text-muted-foreground">
                              {[selectedEmployeeMeta.company_name, selectedEmployeeMeta.department, selectedEmployeeMeta.employment_type].filter(Boolean).join(' · ')}
                            </p>
                          )}
                        </div>
                      )}
                    </div>

                    <div className="space-y-3">
                      <div className="space-y-4 rounded-xl border border-dashed border-border/70 bg-background/80 p-4">
                        <div className="space-y-2 sm:space-y-3">
                          <Label htmlFor="refund-pay-cycle" className={refundModalLabelClass}>
                            Pay cycle <span className="text-destructive">*</span>
                          </Label>
                          <select
                            id="refund-pay-cycle"
                            className={refundModalSelectClass}
                            value={payCycleId}
                            disabled={payCyclesLoading}
                            onChange={(e) => {
                              setPayCycleId(e.target.value)
                              setSelectedPeriodKey('')
                              setForm((f) => ({
                                ...f,
                                affected_date: '',
                                affected_date_to: '',
                                cutoff_start_date: '',
                                cutoff_end_date: '',
                              }))
                              setPreview(null)
                            }}
                          >
                            <option value="">{payCyclesLoading ? 'Loading pay cycles…' : 'Select a pay cycle template…'}</option>
                            {scopedPayCycles.map((cycle) => (
                              <option key={cycle.id} value={String(cycle.id)}>
                                {cycle.name}{cycle.code ? ` · ${cycle.code}` : ''}{cycle.is_default ? ' (default)' : ''}
                              </option>
                            ))}
                          </select>
                        </div>

                        <div className="space-y-2 sm:space-y-3">
                          <Label htmlFor="refund-cycle-period" className={refundModalLabelClass}>
                            Payroll cycle to reflect adjustment <span className="text-destructive">*</span>
                          </Label>
                          <select
                            id="refund-cycle-period"
                            className={refundModalSelectClass}
                            value={selectedPeriodKey}
                            disabled={!payCycleId || cyclePeriodsLoading}
                            onChange={(e) => {
                              const key = e.target.value
                              const period = cyclePeriods.find((p) => `${p.cut_off_start_date}|${p.cut_off_end_date}|${p.pay_date || ''}` === key)
                              if (period) applyPeriod(period)
                            }}
                          >
                            <option value="">
                              {cyclePeriodsLoading
                                ? 'Loading periods…'
                                : !payCycleId
                                  ? 'Select a pay cycle first…'
                                  : 'Select a payroll cycle…'}
                            </option>
                            {cyclePeriods.map((period) => {
                              const key = `${period.cut_off_start_date}|${period.cut_off_end_date}|${period.pay_date || ''}`
                              return (
                                <option key={key} value={key}>
                                  {period.preview_line
                                    || `${period.cut_off_start_date} → ${period.cut_off_end_date}${period.pay_date ? ` · Pay ${period.pay_date}` : ''}`}
                                </option>
                              )
                            })}
                          </select>
                          <p className={refundModalHintClass}>
                            The selected payroll cycle becomes the target period where this adjustment will appear.
                          </p>
                        </div>

                        {form.affected_date ? (
                          <div className="rounded-xl border border-brand/25 bg-brand/4.5 px-4 py-3 dark:border-brand/25 dark:bg-brand/10">
                            <p className="text-[11px] font-bold uppercase tracking-[0.16em] text-brand">Selected payroll cycle</p>
                            <p className="mt-1.5 text-sm font-semibold text-foreground">
                              {formatDate(form.affected_date)}
                              {form.affected_date_to && form.affected_date_to !== form.affected_date
                                ? ` → ${formatDate(form.affected_date_to)}`
                                : ''}
                            </p>
                            {(form.cutoff_start_date || form.cutoff_end_date) && (
                              <p className="mt-1 text-xs text-muted-foreground">
                                Payroll cutoff: {formatDate(form.cutoff_start_date)} → {formatDate(form.cutoff_end_date)}
                              </p>
                            )}
                          </div>
                        ) : null}
                      </div>
                    </div>
                  </RefundFormSection>

                  <RefundFormSection
                    step={2}
                    title="Amount & reason"
                    description="Enter the adjustment amount and reason. Positive amount adds pay; negative amount creates a payroll recovery."
                  >
                    <div className="space-y-2 sm:space-y-3">
                      <Label htmlFor="refund-direct-amount" className={refundModalLabelClass}>
                        Adjustment amount <span className="text-destructive">*</span>
                      </Label>
                      <Input
                        id="refund-direct-amount"
                        type="number"
                        step="0.01"
                        placeholder="e.g. 1250.00 or -1250.00"
                        value={form.direct_refund_amount}
                        onChange={(e) => { setForm((f) => ({ ...f, direct_refund_amount: e.target.value })); setPreview(null) }}
                        className={refundModalFieldClass}
                      />
                      <p className={refundModalHintClass}>
                        Use a positive amount for refund/additional pay. Use a negative amount for payroll recovery/deduction.
                      </p>
                    </div>

                    <div className="space-y-2 sm:space-y-3">
                      <Label htmlFor="refund-reason" className={refundModalLabelClass}>
                        Refund reason <span className="text-destructive">*</span>
                      </Label>
                      <select
                        id="refund-reason"
                        className={refundModalSelectClass}
                        value={form.reason}
                        onChange={(e) => { setForm((f) => ({ ...f, reason: e.target.value })); setPreview(null) }}
                      >
                        <option value="">Select a reason…</option>
                        {reasonGroups.map(({ category, label, reasons }) => (
                          <optgroup key={category} label={label}>
                            {reasons.map((r) => (
                              <option key={r.value} value={r.value}>{r.label}</option>
                            ))}
                          </optgroup>
                        ))}
                      </select>
                    </div>

                    <div className="space-y-2 sm:space-y-3">
                      <Label htmlFor="refund-notes" className={refundModalLabelClass}>Reason notes</Label>
                      <Textarea
                        id="refund-notes"
                        rows={3}
                        placeholder="e.g. Employee forgot to clock out; approved OT was not included in payroll."
                        value={form.reason_notes}
                        onChange={(e) => setForm((f) => ({ ...f, reason_notes: e.target.value }))}
                        className="min-h-24 resize-none rounded-xl border-border/80 bg-background px-3 py-3 text-base shadow-sm placeholder:text-muted-foreground focus-visible:border-brand focus-visible:ring-brand/25 sm:min-h-28 sm:px-4 dark:border-white/12 dark:bg-background/40"
                      />
                    </div>
                  </RefundFormSection>
              </div>
            )}
          </div>
        </div>

        {!loadingEdit && (
          <DialogFooter className="mt-auto flex shrink-0 flex-col-reverse gap-2 border-t border-border/70 bg-card px-4 py-4 sm:flex-row sm:items-center sm:justify-between sm:gap-3 sm:px-7 sm:py-5">
            <p className="hidden text-xs text-muted-foreground sm:block">
              Enter the amount, reason, and payroll cycle, then save or submit.
            </p>
            <div className="flex flex-col-reverse gap-2 sm:flex-row sm:items-center">
              <Button
                type="button"
                variant="outline"
                className="h-11 rounded-xl border-border/80 bg-background px-5 text-sm font-semibold"
                onClick={() => onOpenChange(false)}
                disabled={saving}
              >
                Cancel
              </Button>
              {perms.canCreate && (
                <>
                  <Button
                    type="button"
                    variant="outline"
                    className="h-11 rounded-xl px-5 text-sm font-semibold"
                    disabled={saving || !canSave}
                    onClick={() => save(false)}
                  >
                    Save Draft
                  </Button>
                  <Button
                    type="button"
                    className="h-11 rounded-xl bg-black px-5 text-sm font-bold text-white hover:bg-neutral-900 dark:bg-black dark:hover:bg-neutral-900"
                    disabled={saving || !canSave}
                    onClick={() => save(true)}
                  >
                    {saving ? <Loader2 className="mr-2 size-4 animate-spin" /> : null}
                    Submit for Review
                  </Button>
                </>
              )}
            </div>
          </DialogFooter>
        )}
      </DialogContent>
    </Dialog>
  )
}

function RefundsTable({ items, loading, onView, onApprove, onReject, onVoid, canApprove }) {
  if (loading && !items.length) {
    return (
      <div className="flex items-center justify-center py-12">
        <Loader2 className="size-6 animate-spin text-muted-foreground" />
      </div>
    )
  }
  if (!items.length) {
    return <p className="py-10 text-center text-sm text-muted-foreground">No refund requests match this filter.</p>
  }

  return (
    <div className="overflow-x-auto">
      <table className={requestModuleTableClass}>
        <thead>
          <tr className={requestModuleHeadRowClass}>
            <th className={requestModuleThClass}>Refund #</th>
            <th className={requestModuleThClass}>Employee</th>
            <th className={requestModuleThClass}>Reason</th>
            <th className={requestModuleThClass}>Affected date</th>
            <th className={requestModuleThClass}>Type</th>
            <th className={requestModuleThRightClass}>Amount</th>
            <th className={requestModuleThClass}>Status</th>
            <th className={requestModuleThRightClass}>Actions</th>
          </tr>
        </thead>
        <tbody>
          {items.map((r, idx) => (
            <tr key={r.id} className={requestModuleRowClass(idx)}>
              <td className={cn(requestModuleTdClass, 'font-mono text-[11px] font-semibold')}>{r.refund_number}</td>
              <td className={requestModuleTdClass}>
                <span className="font-medium">{r.employee_name}</span>
                {r.employee_code ? <span className="ml-1 text-[10px] text-muted-foreground">#{r.employee_code}</span> : null}
              </td>
              <td className={requestModuleTdClass}>
                {r.reason_label}
                {r.finalized_original_payroll ? (
                  <span className="ml-1 inline-block rounded bg-blue-100 px-1 text-[9px] font-bold text-blue-800 dark:bg-blue-500/20 dark:text-blue-200">FINALIZED</span>
                ) : null}
              </td>
              <td className={cn(requestModuleTdClass, 'whitespace-nowrap tabular-nums')}>{formatDate(r.affected_date)}</td>
              <td className={requestModuleTdClass}>{directionBadge(r.direction)}</td>
              <td className={cn(requestModuleTdClass, 'text-right font-mono font-semibold tabular-nums', r.direction === 'overpayment' && 'text-red-600 dark:text-red-400')}>
                {formatPeso(r.refund_amount)}
              </td>
              <td className={requestModuleTdClass}><StatusBadge status={r.status} /></td>
              <td className={requestModuleActionsTdClass}>
                <AdminDataTableActions
                  dense
                  onView={() => onView(r.id)}
                  showApprove={canApprove && ['submitted', 'payroll_review'].includes(r.status)}
                  onApprove={() => onApprove?.(r)}
                  showReject={canApprove && ['submitted', 'payroll_review'].includes(r.status)}
                  onReject={() => onReject?.(r)}
                  showDelete={canApprove && r.status === 'approved'}
                  onDelete={() => onVoid?.(r)}
                  deleteLabel="Void"
                  deleteAriaLabel="Void"
                />
              </td>
            </tr>
          ))}
        </tbody>
      </table>
    </div>
  )
}

export default function AdminRefundsPage() {
  const { user } = useAuth()
  const { toast } = useToast()
  const perms = useMemo(() => ({
    canCreate: new Set(user?.permissions ?? []).has('refunds.create'),
    canApprove: new Set(user?.permissions ?? []).has('refunds.approve'),
  }), [user])

  const [statusFilter, setStatusFilter] = useState('all')
  const [items, setItems] = useState([])
  const [counts, setCounts] = useState({})
  const [loading, setLoading] = useState(true)
  const [searchInput, setSearchInput] = useState('')
  const [debouncedSearch, setDebouncedSearch] = useState('')
  const [page, setPage] = useState(1)
  const [meta, setMeta] = useState(null)
  const [detailId, setDetailId] = useState(null)
  const [detailOpen, setDetailOpen] = useState(false)
  const [createOpen, setCreateOpen] = useState(false)
  const [editDraftId, setEditDraftId] = useState(null)
  const [detailRemarkMode, setDetailRemarkMode] = useState(null)

  useEffect(() => {
    const t = setTimeout(() => setDebouncedSearch(searchInput.trim()), 300)
    return () => clearTimeout(t)
  }, [searchInput])

  const loadCounts = useCallback(async () => {
    try {
      const c = await getAdminRefundCounts()
      setCounts(c)
    } catch { /* ignore */ }
  }, [])

  const loadList = useCallback(async (opts = {}) => {
    setLoading(true)
    try {
      const filter = opts.statusFilter ?? statusFilter
      const res = await getAdminRefunds({
        ...(filter !== 'all' ? { status: filter } : {}),
        search: opts.search ?? debouncedSearch,
        page: opts.page ?? page,
        per_page: 25,
      })
      setItems(res.data?.data || [])
      setMeta(res.data ? { current_page: res.data.current_page, last_page: res.data.last_page, total: res.data.total } : null)
      if (res.counts) setCounts(res.counts)
    } catch { /* surfaced via empty state */ }
    finally { setLoading(false) }
  }, [statusFilter, debouncedSearch, page])

  useEffect(() => {
    loadList()
  }, [statusFilter, debouncedSearch, page]) // eslint-disable-line react-hooks/exhaustive-deps

  useEffect(() => {
    loadCounts()
  }, [loadCounts])

  const refreshAll = useCallback(async () => {
    setPage(1)
    await loadCounts()
    await loadList({ page: 1 })
  }, [loadCounts, loadList])

  const countForFilter = (value) => {
    if (value === 'all') return counts.all
    return counts[value]
  }

  const openCreate = () => {
    setEditDraftId(null)
    setCreateOpen(true)
  }

  const quickApprove = async (refund) => {
    try {
      await transitionAdminRefund(refund.id, 'approve')
      toast({ title: 'Refund approve successful.' })
      await refreshAll()
    } catch (e) {
      toast({ title: 'Action failed', description: e.message, variant: 'destructive' })
    }
  }

  const openReject = (id) => {
    setDetailId(id)
    setDetailRemarkMode('reject')
    setDetailOpen(true)
  }

  const openVoid = (id) => {
    setDetailId(id)
    setDetailRemarkMode('void')
    setDetailOpen(true)
  }

  const openView = (id) => {
    setDetailId(id)
    setDetailRemarkMode(null)
    setDetailOpen(true)
  }

  return (
    <div className={cn(PAGE_SHELL, 'space-y-5 md:space-y-6')}>
      <div className="space-y-2">
        <div className="flex items-start justify-between gap-3">
          <div className="flex min-w-0 items-start gap-3">
            <div className="mt-0.5 flex h-10 w-10 shrink-0 items-center justify-center rounded-xl border border-brand/20 bg-brand/10 text-brand shadow-sm">
              <CircleDollarSign className="h-5 w-5" />
            </div>
            <h1 className="pt-0.5 text-[26px] font-extrabold tracking-tight text-foreground md:text-[30px]">
              Refunds &amp; Adjustments
            </h1>
          </div>
          <div className="flex shrink-0 flex-wrap items-center justify-end gap-2">
            {perms.canCreate && (
              <Button onClick={openCreate} className={cn('h-10 rounded-xl px-4 font-bold', ORANGE_BUTTON)}>
                <Plus className="mr-2 size-4" />
                New Refund
              </Button>
            )}
            <Button
              onClick={refreshAll}
              disabled={loading}
              variant="outline"
              className="h-10 rounded-xl border-border/80 bg-card px-4 font-semibold shadow-sm"
            >
              <RefreshCw className={cn('mr-2 size-4', loading ? 'animate-spin' : '')} />
              Refresh
            </Button>
          </div>
        </div>
        <p className="max-w-4xl pl-13 text-sm font-medium leading-6 text-muted-foreground">
          Recover the difference between what payroll should have paid and what it actually paid — using the same
          schedule, attendance, OT, holiday, and payroll engines. If the original period is already finalized
          (e.g. missing attendance on the previous payroll), the adjustment is applied on the next payroll run, not by reopening the old payslip.
        </p>
      </div>

      <div className="grid gap-3 sm:grid-cols-2 xl:grid-cols-4">
        <RefundStatCard icon={ClipboardList} value={counts.all} label="Total Requests" />
        <RefundStatCard icon={ShieldCheck} value={counts.pending_approval} label="Pending Approval" tone="text-blue-600 dark:text-blue-300" />
        <RefundStatCard icon={CheckCircle2} value={counts.processed} label="Processed" tone="text-emerald-600 dark:text-emerald-300" />
        <RefundStatCard icon={History} value={counts.history} label="History" tone="text-muted-foreground" />
      </div>

      <Card className={CARD_SHELL}>
        <CardHeader className="px-4 pb-3 pt-5 sm:px-5">
          <CardTitle className="text-[17px] font-extrabold">Refund requests</CardTitle>
          <CardDescription className="text-sm">
            All refund and payroll recovery requests in your scope. Filter by status or search by refund number or employee.
          </CardDescription>
        </CardHeader>
        <CardContent className="space-y-4 px-4 pb-5 sm:px-5">
          <div className="flex flex-col gap-4 border-b border-border/60 pb-4">
            <div
              className="inline-flex flex-wrap rounded-xl border border-border/60 bg-muted/40 p-1 shadow-inner dark:bg-muted/30"
              role="tablist"
              aria-label="Status filter"
            >
              {STATUS_FILTERS.map(({ value, label }) => (
                <button
                  key={value}
                  type="button"
                  role="tab"
                  aria-selected={statusFilter === value}
                  onClick={() => { setStatusFilter(value); setPage(1) }}
                  className={cn(
                    'rounded-lg px-4 py-2.5 text-sm font-semibold transition-all',
                    statusFilter === value
                      ? 'bg-card text-foreground shadow-sm ring-1 ring-border/60'
                      : 'text-muted-foreground hover:text-foreground',
                  )}
                >
                  <span className="inline-flex items-center">
                    {label}
                    {value === 'pending_approval' ? (
                      <ApprovalQueueTabBadge count={counts.pending_approval} />
                    ) : countForFilter(value) > 0 && value !== 'all' ? (
                      <span className="ml-2 inline-flex h-5 min-w-5 items-center justify-center rounded-full bg-muted px-1.5 text-[11px] font-bold tabular-nums text-muted-foreground">
                        {countForFilter(value) > 99 ? '99+' : countForFilter(value)}
                      </span>
                    ) : null}
                  </span>
                </button>
              ))}
            </div>

            <div className="relative max-w-md">
              <Search className="pointer-events-none absolute left-3 top-1/2 h-4 w-4 -translate-y-1/2 text-muted-foreground" />
              <Input
                className="h-11 rounded-xl border-border/80 bg-background pl-9 shadow-sm dark:bg-input/45"
                placeholder="Search refund # or employee…"
                value={searchInput}
                onChange={(e) => { setSearchInput(e.target.value); setPage(1) }}
              />
            </div>
          </div>

          <div className="overflow-hidden rounded-2xl border border-border/70 bg-white dark:bg-input/15">
            <RefundsTable
              items={items}
              loading={loading}
              canApprove={perms.canApprove}
              onApprove={quickApprove}
              onReject={(row) => openReject(row.id)}
              onVoid={(row) => openVoid(row.id)}
              onView={openView}
            />
          </div>

          {meta && meta.last_page > 1 && (
            <div className="flex items-center justify-between text-sm">
              <span className="text-muted-foreground">Page {meta.current_page} of {meta.last_page} · {meta.total} total</span>
              <div className="flex gap-2">
                <Button variant="outline" size="sm" disabled={page <= 1 || loading} onClick={() => setPage((p) => Math.max(1, p - 1))}>Previous</Button>
                <Button variant="outline" size="sm" disabled={page >= meta.last_page || loading} onClick={() => setPage((p) => p + 1)}>Next</Button>
              </div>
            </div>
          )}
        </CardContent>
      </Card>

      <CreateRefundDialog
        open={createOpen}
        onOpenChange={(v) => { setCreateOpen(v); if (!v) setEditDraftId(null) }}
        onCreated={refreshAll}
        perms={perms}
        editId={editDraftId}
      />

      <RefundDetailDialog
        refundId={detailId}
        open={detailOpen}
        onOpenChange={(v) => { setDetailOpen(v); if (!v) setDetailRemarkMode(null) }}
        onActionDone={refreshAll}
        perms={perms}
        initialRemarkMode={detailRemarkMode}
      />
    </div>
  )
}
