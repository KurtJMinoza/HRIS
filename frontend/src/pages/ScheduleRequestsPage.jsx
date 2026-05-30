import { useCallback, useEffect, useMemo, useState } from 'react'
import {
  CalendarDays,
  CheckCircle2,
  Clock,
  Inbox,
  Loader2,
  RefreshCw,
  Search,
  Trash2,
  UsersRound,
  XCircle,
} from 'lucide-react'
import { Card, CardContent, CardDescription, CardHeader, CardTitle } from '@/components/ui/card'
import { Badge } from '@/components/ui/badge'
import { Button } from '@/components/ui/button'
import { Dialog, DialogContent, DialogDescription, DialogFooter, DialogHeader, DialogTitle } from '@/components/ui/dialog'
import { Input } from '@/components/ui/input'
import { Label } from '@/components/ui/label'
import { Textarea } from '@/components/ui/textarea'
import { Table, TableBody, TableCell, TableHead, TableHeader, TableRow } from '@/components/ui/table'
import { Select, SelectContent, SelectItem, SelectTrigger, SelectValue } from '@/components/ui/select'
import { useToast } from '@/components/ui/use-toast'
import { RemarksPreviewCell } from '@/components/presenceFiling/CorrectionTableCells'
import { ScheduleRequestStatusBadge } from '@/components/schedules/ScheduleRequestTableCells'
import { AdminDataTableActions } from '@/components/admin/AdminDataTableActions'
import {
  approveScheduleRequest,
  deleteAdminScheduleRequest,
  getAdminScheduleRequestDetail,
  getAdminScheduleRequests,
  rejectScheduleRequest,
} from '@/api'
import { cn } from '@/lib/utils'
import { sanitizeApprovalDisplayText } from '@/lib/approvalText'
import { formatShiftRange12h } from '@/lib/timeFormat'

function formatDateTime(value) {
  if (!value) return '-'
  const date = new Date(value)
  return Number.isNaN(date.getTime()) ? value : date.toLocaleString('en-PH', { dateStyle: 'medium', timeStyle: 'short' })
}

function formatShortDate(iso) {
  if (!iso) return '-'
  const d = new Date(`${iso}T12:00:00`)
  return Number.isNaN(d.getTime()) ? iso : d.toLocaleDateString('en-PH', { dateStyle: 'medium' })
}

function scheduleDetailSummary(item) {
  const ws = item?.working_schedule || {}
  const payload = item?.custom_schedule_payload || {}
  const restDays = Array.isArray(ws.rest_days) && ws.rest_days.length ? ws.rest_days : (Array.isArray(payload.rest_days) ? payload.rest_days : [])
  const workDaysCount = typeof ws.work_days_per_week === 'number'
    ? ws.work_days_per_week
    : Math.max(0, 7 - restDays.length)
  const breakMinutes = Number(payload.break_duration_minutes ?? 0)
  const breakLabel = ws.break_start && ws.break_end
    ? formatShiftRange12h(ws.break_start, ws.break_end, ' - ')
    : breakMinutes > 0
      ? `${breakMinutes} min`
      : 'No break set'
  return { restDays, workDaysCount, breakLabel }
}

function formatLongDate(iso) {
  if (!iso) return '-'
  const d = new Date(`${iso}T12:00:00`)
  return Number.isNaN(d.getTime()) ? iso : d.toLocaleDateString('en-PH', { month: 'long', day: 'numeric', year: 'numeric' })
}

function getInitials(name) {
  if (!name || typeof name !== 'string') return '?'
  const parts = name.trim().split(/\s+/).filter(Boolean)
  if (parts.length === 0) return '?'
  if (parts.length === 1) return parts[0].slice(0, 2).toUpperCase()
  return `${parts[0][0] ?? ''}${parts[parts.length - 1][0] ?? ''}`.toUpperCase() || '?'
}

function scheduleTimeRange(item) {
  const timeIn = item?.working_schedule?.time_in || item?.custom_schedule_payload?.time_in
  const timeOut = item?.working_schedule?.time_out || item?.custom_schedule_payload?.time_out
  if (!timeIn && !timeOut) return '-'
  return formatShiftRange12h(timeIn, timeOut, ' - ')
}

function scheduleName(item) {
  return item?.working_schedule?.name || item?.custom_schedule_payload?.name || 'Schedule'
}

function historyActionLabel(action) {
  switch (action) {
    case 'file':
      return 'Request filed'
    case 'approve_first':
      return 'First approval'
    case 'approve_final':
      return 'Final approval'
    case 'reject':
      return 'Rejected'
    default:
      return action ? String(action).replace(/_/g, ' ') : 'Action'
  }
}

function humanStepStatus(status) {
  switch (status) {
    case 'completed':
      return 'Completed'
    case 'current':
    case 'pending':
      return 'Pending'
    case 'rejected':
      return 'Rejected'
    case 'skipped':
      return 'Skipped'
    default:
      return status ? String(status) : '-'
  }
}

function stepName(step) {
  if (step?.key === 'submitted') return step.submitter_name || '-'
  return step?.approver_name || step?.approver_role_label || '-'
}

function stepRole(step) {
  if (step?.key === 'submitted') return 'Requester'
  return step?.approver_role_label || step?.approver_role || ''
}

const scheduleCardClass =
  'rounded-[18px] border border-border/70 bg-card text-card-foreground shadow-[0_12px_34px_-24px_rgba(15,23,42,0.55),0_2px_10px_-7px_rgba(15,23,42,0.25)] dark:border-white/10 dark:bg-card/95 dark:shadow-[0_18px_44px_-24px_rgba(0,0,0,0.75)]'

function ScheduleStatusPill({ item }) {
  const status = item?.status
  const label = item?.display_status || (status === 'approved' ? 'Approved' : status === 'rejected' ? 'Rejected' : 'Pending')
  const Icon = status === 'approved' ? CheckCircle2 : status === 'rejected' ? XCircle : Clock
  const tone =
    status === 'approved'
      ? 'border-emerald-200/80 bg-emerald-50 text-emerald-800 dark:border-emerald-900/40 dark:bg-emerald-950/35 dark:text-emerald-100'
      : status === 'rejected'
        ? 'border-rose-200/80 bg-rose-50 text-rose-800 dark:border-rose-900/40 dark:bg-rose-950/35 dark:text-rose-100'
        : 'border-amber-200/80 bg-amber-50 text-amber-800 dark:border-amber-900/40 dark:bg-amber-950/35 dark:text-amber-100'

  return (
    <span className={cn('inline-flex items-center gap-2 rounded-full border px-3.5 py-1.5 text-sm font-bold shadow-sm', tone)}>
      <Icon className="size-4" aria-hidden />
      {label}
    </span>
  )
}

function ScheduleDetailSection({ icon: Icon, title, children, className }) {
  return (
    <section className={cn(scheduleCardClass, 'p-5', className)}>
      <h3 className="mb-4 flex items-center gap-3 border-b border-border/70 pb-3 text-[11px] font-black uppercase tracking-[0.2em] text-brand dark:border-white/10">
        {Icon ? <Icon className="size-5 shrink-0" aria-hidden /> : null}
        {title}
      </h3>
      {children}
    </section>
  )
}

function ScheduleInitialsAvatar({ name }) {
  return (
    <div className="flex size-12 shrink-0 items-center justify-center rounded-full border border-brand/15 bg-brand/10 text-sm font-black uppercase text-brand shadow-sm dark:border-brand/25 dark:bg-brand/15">
      {getInitials(name)}
    </div>
  )
}

function ScheduleApprovalChain({ steps }) {
  if (!Array.isArray(steps) || steps.length === 0) return null

  return (
    <ScheduleDetailSection icon={UsersRound} title="Approval chain">
      <ol className="space-y-4">
        {steps.map((step, idx) => {
          const name = stepName(step)
          const role = stepRole(step)
          const statusLabel = humanStepStatus(step.status)
          const statusLine = step.acted_at ? `${statusLabel} - ${formatDateTime(step.acted_at)}` : statusLabel
          const remarks = sanitizeApprovalDisplayText(step?.remarks)

          return (
            <li key={step.key || `schedule-step-${idx}`}>
              <p className="mb-3 text-[11px] font-black uppercase tracking-[0.18em] text-brand">
                <span className="tabular-nums">{idx + 1}. </span>
                {step.label}
              </p>
              <div
                className={cn(
                  'rounded-xl border border-border/70 bg-background/70 p-4 shadow-sm dark:border-white/10 dark:bg-background/35',
                  step.status === 'current' &&
                    'border-amber-400/70 bg-amber-50/80 ring-2 ring-amber-500/20 dark:border-amber-400/35 dark:bg-amber-950/25'
                )}
              >
                <div className="flex gap-4">
                  <ScheduleInitialsAvatar name={name} />
                  <div className="min-w-0 flex-1">
                    <p className="text-[15px] font-bold leading-snug text-foreground">{name}</p>
                    {role ? <p className="mt-1 text-sm font-medium text-foreground/85">{role}</p> : null}
                    {step?.is_self_approval ? (
                      <span className="mt-2 inline-flex w-fit rounded-full border border-amber-300/70 bg-amber-50 px-2.5 py-1 text-[11px] font-black uppercase tracking-[0.14em] text-amber-800 dark:border-amber-400/40 dark:bg-amber-950/35 dark:text-amber-200">
                        Self Approval
                      </span>
                    ) : null}
                    <p className="mt-1 text-sm leading-relaxed text-muted-foreground">{statusLine}</p>
                    {remarks ? (
                      <p className="mt-3 text-sm leading-relaxed text-foreground">
                        <span className="font-semibold">Remarks: </span>
                        {remarks}
                      </p>
                    ) : null}
                  </div>
                </div>
              </div>
            </li>
          )
        })}
      </ol>
    </ScheduleDetailSection>
  )
}

function ScheduleHistoryTimeline({ history }) {
  if (!Array.isArray(history) || history.length === 0) return null

  return (
    <ScheduleDetailSection icon={Clock} title="Approval history">
      <ol className="relative ml-1 border-l-2 border-border/70 pl-6 dark:border-white/10">
        {[...history]
          .sort((a, b) => new Date(a.at || 0) - new Date(b.at || 0))
          .map((item, idx) => {
            const actionLabel = historyActionLabel(item.action)
            const details = sanitizeApprovalDisplayText(item?.details)
            const headline = [item.actor_name, item.approver_role].filter(Boolean).join(' - ') || actionLabel
            return (
              <li key={`${item.at}-${idx}-${item.action}`} className="relative pb-7 last:pb-0">
                <span className="absolute -left-[1.95rem] top-3 size-2.5 rounded-full bg-brand ring-4 ring-card dark:ring-card" />
                <div className="rounded-xl border border-border/70 bg-background/70 px-4 py-4 shadow-sm dark:border-white/10 dark:bg-background/35">
                  <div className="flex flex-wrap items-baseline justify-between gap-x-4 gap-y-2">
                    <p className="text-sm font-bold text-foreground">{headline}</p>
                    <time className="text-xs tabular-nums text-foreground/85" dateTime={item.at || undefined}>
                      {item.at ? formatDateTime(item.at) : '-'}
                    </time>
                  </div>
                  {details ? <p className="mt-3 whitespace-pre-wrap text-sm leading-relaxed text-foreground/90">{details}</p> : null}
                </div>
              </li>
            )
          })}
      </ol>
    </ScheduleDetailSection>
  )
}

export default function ScheduleRequestsPage() {
  const { toast } = useToast()
  const [loading, setLoading] = useState(true)
  const [saving, setSaving] = useState(false)
  const [statusFilter, setStatusFilter] = useState('all')
  const [requests, setRequests] = useState([])
  const [selectedRequest, setSelectedRequest] = useState(null)
  const [detailOpen, setDetailOpen] = useState(false)
  const [detailLoading, setDetailLoading] = useState(false)
  const [actionDialog, setActionDialog] = useState({ open: false, mode: 'approve', request: null })
  const [deleteDialog, setDeleteDialog] = useState({ open: false, request: null })
  const [remarks, setRemarks] = useState('')
  const [queueSearch, setQueueSearch] = useState('')

  const cellPad = '!p-3.5'

  const loadData = useCallback(async () => {
    setLoading(true)
    try {
      const data = await getAdminScheduleRequests(statusFilter === 'all' ? {} : { status: statusFilter })
      setRequests(Array.isArray(data?.requests) ? data.requests : [])
    } catch (error) {
      toast({ title: 'Schedule requests', description: error.message || 'Failed to load schedule requests', variant: 'destructive' })
    } finally {
      setLoading(false)
    }
  }, [statusFilter, toast])

  useEffect(() => {
    loadData()
  }, [loadData])

  const summary = useMemo(() => ({
    pending: requests.filter((item) => item.status === 'pending').length,
    approved: requests.filter((item) => item.status === 'approved').length,
    rejected: requests.filter((item) => item.status === 'rejected').length,
  }), [requests])

  const filteredQueue = useMemo(() => {
    const q = queueSearch.trim().toLowerCase()
    if (!q) return requests
    return requests.filter((item) => {
      const name = String(item.requested_by_name || '').toLowerCase()
      const role = String(item.requested_by_role_label || '').toLowerCase()
      const sched = `${item.working_schedule?.name || ''} ${item.custom_schedule_payload?.name || ''}`.toLowerCase()
      const times = `${item.working_schedule?.time_in || ''} ${item.custom_schedule_payload?.time_in || ''}`.toLowerCase()
      const remarksL = String(item.remarks || '').toLowerCase()
      const id = String(item.id ?? '')
      const status = `${item.display_status || ''} ${item.status || ''}`.toLowerCase()
      return (
        name.includes(q) ||
        role.includes(q) ||
        sched.includes(q) ||
        times.includes(q) ||
        remarksL.includes(q) ||
        id.includes(q) ||
        status.includes(q)
      )
    })
  }, [requests, queueSearch])

  const openRequestDetail = useCallback(async (item) => {
    setSelectedRequest(item)
    setDetailOpen(true)
    setDetailLoading(true)
    try {
      const data = await getAdminScheduleRequestDetail(item.id)
      setSelectedRequest(data?.request || item)
    } catch (error) {
      toast({
        title: 'Schedule request',
        description: error.message || 'Failed to load schedule request details',
        variant: 'destructive',
      })
    } finally {
      setDetailLoading(false)
    }
  }, [toast])

  async function handleAction() {
    if (!actionDialog.request) return
    setSaving(true)
    try {
      if (actionDialog.mode === 'approve') {
        await approveScheduleRequest(actionDialog.request.id, { remarks: remarks.trim() || null })
      } else {
        await rejectScheduleRequest(actionDialog.request.id, { remarks: remarks.trim() })
      }
      toast({ title: 'Schedule requests', description: `Request ${actionDialog.mode === 'approve' ? 'updated' : 'rejected'}.` })
      setActionDialog({ open: false, mode: 'approve', request: null })
      setRemarks('')
      await loadData()
    } catch (error) {
      toast({ title: 'Schedule requests', description: error.message || 'Failed to update request', variant: 'destructive' })
    } finally {
      setSaving(false)
    }
  }

  async function handleDelete() {
    if (!deleteDialog.request) return
    setSaving(true)
    try {
      await deleteAdminScheduleRequest(deleteDialog.request.id)
      toast({ title: 'Schedule requests', description: 'Request deleted.' })
      setDeleteDialog({ open: false, request: null })
      await loadData()
    } catch (error) {
      toast({ title: 'Schedule requests', description: error.message || 'Failed to delete request', variant: 'destructive' })
    } finally {
      setSaving(false)
    }
  }

  return (
    <div className="min-h-0 min-w-0 max-w-full space-y-8 overflow-x-hidden px-1 py-4 @sm:px-0 @sm:py-6">
      <div className="flex w-full min-w-0 flex-col gap-5 pb-1 @lg:flex-row @lg:items-end @lg:justify-between">
        <div className="max-w-2xl space-y-3">
          <p className="text-sm font-extrabold uppercase tracking-[0.14em] text-brand">Schedules</p>
          <h1 className="text-3xl font-extrabold tracking-tight text-foreground @md:text-4xl">Schedule Requests</h1>
          <p className="text-base leading-relaxed text-muted-foreground">
            Review schedule change requests in your approval scope - same table experience as Correction Requests.
          </p>
        </div>
        <div className="flex w-full min-w-0 flex-col gap-3 @sm:flex-row @sm:flex-wrap @sm:items-center @sm:justify-end">
          <Select value={statusFilter} onValueChange={setStatusFilter}>
            <SelectTrigger className="h-12 min-w-[180px] rounded-xl border-border/80 bg-card text-base font-semibold shadow-sm dark:border-white/10">
              <SelectValue />
            </SelectTrigger>
            <SelectContent>
              <SelectItem value="all">All statuses</SelectItem>
              <SelectItem value="pending">Pending</SelectItem>
              <SelectItem value="approved">Approved</SelectItem>
              <SelectItem value="rejected">Rejected</SelectItem>
            </SelectContent>
          </Select>
          <Button
            type="button"
            variant="outline"
            className="h-12 shrink-0 gap-2 rounded-xl border-border/80 bg-card px-5 text-base font-semibold text-foreground shadow-sm hover:bg-muted dark:border-white/10"
            onClick={loadData}
          >
            <RefreshCw className={cn('size-4', loading && 'animate-spin')} />
            Refresh
          </Button>
        </div>
      </div>

      <div className="grid gap-5 md:grid-cols-3">
        <SummaryCard label="Pending" value={summary.pending} tone="amber" />
        <SummaryCard label="Approved" value={summary.approved} tone="emerald" />
        <SummaryCard label="Rejected" value={summary.rejected} tone="rose" />
      </div>

      <Card className={cn(scheduleCardClass, 'w-full min-w-0 overflow-hidden')}>
        <CardHeader className="space-y-2 border-b border-border bg-card px-5 py-5 @md:px-8 @md:py-7">
          <CardTitle className="flex items-center gap-3 text-xl font-semibold tracking-tight text-foreground">
            <UsersRound className="size-6 text-brand" aria-hidden />
            Approval queue
          </CardTitle>
          <CardDescription className="text-sm text-muted-foreground">
            Scoped to employees your role can review. Search, filter by status, then approve or reject with remarks.
          </CardDescription>
        </CardHeader>
        <CardContent className="p-0">
          {loading && requests.length === 0 ? (
            <div className="flex items-center justify-center py-16">
              <Loader2 className="size-8 animate-spin text-muted-foreground" />
            </div>
          ) : (
            <>
              <div className="border-b border-border bg-card px-4 py-5 @sm:px-6 md:px-8">
                <div className="flex flex-col gap-4">
                  <div className="relative w-full min-w-0 max-w-full">
                    <Search
                      className="pointer-events-none absolute left-3.5 top-1/2 size-4 -translate-y-1/2 text-muted-foreground"
                      aria-hidden
                    />
                    <Input
                      type="search"
                      value={queueSearch}
                      onChange={(e) => setQueueSearch(e.target.value)}
                      placeholder="Search employee, schedule, request ID, or remarks..."
                      className="h-12 w-full min-w-0 rounded-xl border-border/80 bg-background pl-10 pr-14 text-[15px] shadow-sm dark:border-white/10 dark:bg-background/40"
                      aria-label="Search approval queue"
                    />
                    <div className="absolute right-2 top-1/2 flex -translate-y-1/2 items-center gap-1.5">
                      <Button
                        type="button"
                        variant="outline"
                        size="icon"
                        className="size-9 rounded-lg border-border/80 bg-card dark:border-white/10"
                        onClick={() => loadData()}
                        disabled={loading}
                        aria-label="Refresh queue"
                      >
                        {loading ? <Loader2 className="size-4 animate-spin" /> : <RefreshCw className="size-4" />}
                      </Button>
                    </div>
                  </div>
                  <p className="text-xs text-muted-foreground">
                    <span className="font-semibold tabular-nums text-foreground">{filteredQueue.length}</span>
                    {filteredQueue.length === 1 ? ' request' : ' requests'}
                    {queueSearch.trim() ? ' - filtered' : ''}
                  </p>
                </div>
              </div>

              {!loading && filteredQueue.length === 0 ? (
                <div className="flex min-h-[26rem] flex-col items-center justify-center px-6 py-20 text-center">
                  <div className="relative mb-6 flex size-24 items-center justify-center rounded-full bg-brand/10 text-brand dark:bg-brand/15">
                    <Inbox className="size-11" strokeWidth={1.85} aria-hidden />
                    <span className="absolute -left-1 top-2 text-lg font-semibold text-brand" aria-hidden>
                      +
                    </span>
                    <span className="absolute -right-2 bottom-3 text-lg font-semibold text-brand/70" aria-hidden>
                      +
                    </span>
                  </div>
                  <div className="text-xl font-semibold tracking-tight text-foreground">
                    {requests.length === 0 ? 'No requests in your scope' : 'No requests match'}
                  </div>
                  <p className="mt-3 max-w-md text-sm leading-relaxed text-muted-foreground">
                    {requests.length === 0
                      ? 'When employees file schedule changes you can approve, they will appear here.'
                      : 'Try a different keyword, status filter, or clear search.'}
                  </p>
                </div>
              ) : (
                <div className="w-full min-w-0 touch-pan-x overflow-x-auto bg-card px-4 pb-8 pt-2 sm:px-6 md:px-8">
                  <Table className="w-full min-w-[960px]">
                    <TableHeader>
                      <TableRow className="border-b border-border/60 bg-muted/40 hover:bg-muted/40 dark:bg-muted/25 dark:hover:bg-muted/25">
                        <TableHead className={cn('min-w-[12rem] py-3.5 pl-2 sm:pl-3')}>
                          <span className="text-xs font-semibold uppercase tracking-wide text-muted-foreground">
                            Employee name
                          </span>
                        </TableHead>
                        <TableHead className="min-w-[14rem] py-3.5">
                          <span className="text-xs font-semibold uppercase tracking-wide text-muted-foreground">
                            Requested schedule
                          </span>
                        </TableHead>
                        <TableHead className="min-w-[12rem] py-3.5">
                          <span className="text-xs font-semibold uppercase tracking-wide text-muted-foreground">Reason</span>
                        </TableHead>
                        <TableHead className="min-w-[11rem] py-3.5">
                          <span className="text-xs font-semibold uppercase tracking-wide text-muted-foreground">Status</span>
                        </TableHead>
                        <TableHead className="min-w-[10rem] py-3.5">
                          <span className="text-xs font-semibold uppercase tracking-wide text-muted-foreground">
                            Submitted date
                          </span>
                        </TableHead>
                        <TableHead className="w-[14rem] min-w-[14rem] py-3.5 pr-2 text-right sm:pr-3">
                          <span className="text-xs font-semibold uppercase tracking-wide text-muted-foreground">Actions</span>
                        </TableHead>
                      </TableRow>
                    </TableHeader>
                    <TableBody>
                      {filteredQueue.map((item, rowIdx) => (
                        <TableRow
                          key={item.id}
                          className={cn(
                            'border-b border-border/50 text-[15px] leading-snug transition-colors hover:bg-muted/25',
                            rowIdx % 2 === 1 ? 'bg-card' : 'bg-muted/20 dark:bg-muted/10',
                          )}
                        >
                          <TableCell className={cn('align-top', cellPad)}>
                            <div>
                              <p className="font-semibold leading-snug text-foreground">{item.requested_by_name || '-'}</p>
                              <p className="mt-0.5 text-xs text-muted-foreground">{item.requested_by_role_label || '-'}</p>
                            </div>
                          </TableCell>
                          <TableCell className={cn('align-top', cellPad)}>
                            {(() => {
                              const details = scheduleDetailSummary(item)
                              return <div>
                              <p className="font-semibold leading-snug text-foreground">
                                {scheduleName(item)}
                              </p>
                              <p className="mt-0.5 text-xs text-muted-foreground">
                                {scheduleTimeRange(item)}
                              </p>
                              <div className="mt-1.5 flex flex-wrap items-center gap-2">
                                {item.request_kind === 'custom' ? (
                                  <Badge
                                    variant="outline"
                                    className="rounded-lg border-border/80 bg-background px-2 py-0 text-[10px] font-medium shadow-sm dark:border-white/10 dark:bg-background/40"
                                  >
                                    Custom proposal
                                  </Badge>
                                ) : null}
                                <span className="text-[11px] tabular-nums text-muted-foreground">
                                  Effective {formatShortDate(item.effective_from)}
                                </span>
                              </div>
                              <div className="mt-2 flex flex-wrap items-center gap-2">
                                {details.restDays.map((d) => (
                                  <Badge
                                    key={`${item.id}-rest-${d}`}
                                    variant="outline"
                                    className="rounded-full px-2 py-0 text-[10px]"
                                  >
                                    {String(d).toUpperCase()}
                                  </Badge>
                                ))}
                                <span className="text-[11px] text-muted-foreground">Break: {details.breakLabel}</span>
                                <span className="text-[11px] text-muted-foreground">Work: {details.workDaysCount} days/week</span>
                              </div>
                            </div>
                            })()}
                          </TableCell>
                          <TableCell className={cn('max-w-[18rem] align-top', cellPad)}>
                            <RemarksPreviewCell text={item.remarks} />
                          </TableCell>
                          <TableCell className={cn('align-top', cellPad)}>
                            <ScheduleRequestStatusBadge item={item} />
                          </TableCell>
                          <TableCell className={cn('align-middle text-sm tabular-nums text-foreground', cellPad)}>
                            {item.filed_at ? formatDateTime(item.filed_at) : '-'}
                          </TableCell>
                          <TableCell className={cn('text-right align-middle', cellPad)}>
                            <AdminDataTableActions
                              onView={() => {
                                openRequestDetail(item)
                              }}
                              viewAriaLabel="View schedule request"
                              showApprove={Boolean(item.actor_can_approve)}
                              onApprove={() => {
                                setActionDialog({ open: true, mode: 'approve', request: item })
                                setRemarks('')
                              }}
                              showReject={Boolean(item.actor_can_reject)}
                              onReject={() => {
                                setActionDialog({ open: true, mode: 'reject', request: item })
                                setRemarks('')
                              }}
                              showDelete={Boolean(item.actor_can_delete)}
                              onDelete={() => setDeleteDialog({ open: true, request: item })}
                            />
                          </TableCell>
                        </TableRow>
                      ))}
                    </TableBody>
                  </Table>
                </div>
              )}
            </>
          )}
        </CardContent>
      </Card>

      <Dialog open={detailOpen} onOpenChange={setDetailOpen}>
        <DialogContent
          showCloseButton
          overlayClassName="bg-black/55 backdrop-blur-sm dark:bg-black/70"
          closeButtonClassName="right-5 top-5 size-11 rounded-xl border-border/80 bg-background/90 text-foreground shadow-sm hover:bg-muted dark:border-white/10 dark:bg-card/90"
          className="max-h-[94vh] max-w-[min(96vw,78rem)] overflow-hidden rounded-[18px] border-border/80 bg-card shadow-[0_24px_80px_-24px_rgba(0,0,0,0.5)] dark:border-white/10 dark:bg-card"
          innerClassName="gap-0 overflow-hidden p-0 pr-0"
        >
          <DialogHeader className="border-b border-border/70 px-7 pb-6 pt-7 text-left dark:border-white/10">
            <p className="text-[11px] font-black uppercase tracking-[0.22em] text-brand">Schedule request</p>
            <div className="mt-4 flex flex-wrap items-center gap-4 pr-12">
              <DialogTitle className="text-4xl font-black leading-none tracking-tight text-foreground">
                #{selectedRequest?.id || '-'}
              </DialogTitle>
              {selectedRequest ? <ScheduleStatusPill item={selectedRequest} /> : null}
              {detailLoading ? <Loader2 className="size-5 animate-spin text-muted-foreground" aria-hidden /> : null}
            </div>
            <DialogDescription className="mt-4 max-w-2xl text-base leading-relaxed text-muted-foreground">
              Review employee details, requested schedule, approval chain, and request history below.
            </DialogDescription>
          </DialogHeader>

          <div className="min-h-0 flex-1 overflow-y-auto px-7 py-6">
            {selectedRequest ? (
              <div className="grid gap-6 xl:grid-cols-[minmax(0,1fr)_minmax(24rem,0.95fr)]">
                <div className="space-y-6">
                  <ScheduleDetailSection icon={CalendarDays} title="Summary">
                    <dl className="grid grid-cols-[minmax(0,12rem)_1fr] gap-x-6 gap-y-4 text-sm">
                      <dt className="text-muted-foreground">Employee</dt>
                      <dd className="font-bold text-foreground">{selectedRequest.requested_by_name || '-'}</dd>
                      <dt className="text-muted-foreground">Role</dt>
                      <dd className="font-medium text-foreground">{selectedRequest.requested_by_role_label || '-'}</dd>
                      <dt className="text-muted-foreground">Request type</dt>
                      <dd>
                        <Badge variant="outline" className="rounded-full border-brand/25 bg-brand/10 px-3 py-1 text-xs font-bold text-brand">
                          {selectedRequest.request_kind === 'custom' ? 'Custom proposal' : 'Template schedule'}
                        </Badge>
                      </dd>
                      <dt className="text-muted-foreground">Effective date</dt>
                      <dd className="font-medium tabular-nums text-foreground">{formatLongDate(selectedRequest.effective_from)}</dd>
                      <dt className="text-muted-foreground">Filed</dt>
                      <dd className="font-medium tabular-nums text-foreground">{formatDateTime(selectedRequest.filed_at || selectedRequest.created_at)}</dd>
                    </dl>
                  </ScheduleDetailSection>

                  <ScheduleDetailSection icon={Clock} title="Requested schedule">
                    {(() => {
                      const details = scheduleDetailSummary(selectedRequest)
                      return (
                        <div className="space-y-4">
                          <div>
                            <p className="text-xl font-black tracking-tight text-foreground">{scheduleName(selectedRequest)}</p>
                            <p className="mt-1 font-mono text-lg font-bold tabular-nums text-foreground">{scheduleTimeRange(selectedRequest)}</p>
                          </div>
                          <div className="grid gap-3 sm:grid-cols-2">
                            <div className="rounded-xl border border-border/70 bg-background/70 p-4 dark:border-white/10 dark:bg-background/35">
                              <p className="text-xs font-bold uppercase tracking-wide text-muted-foreground">Work days</p>
                              <p className="mt-2 text-lg font-bold text-foreground">{details.workDaysCount} per week</p>
                            </div>
                            <div className="rounded-xl border border-border/70 bg-background/70 p-4 dark:border-white/10 dark:bg-background/35">
                              <p className="text-xs font-bold uppercase tracking-wide text-muted-foreground">Break</p>
                              <p className="mt-2 text-lg font-bold text-foreground">{details.breakLabel}</p>
                            </div>
                          </div>
                          <div className="flex flex-wrap items-center gap-2">
                            <span className="text-sm font-semibold text-muted-foreground">Rest days:</span>
                            {details.restDays.length ? details.restDays.map((day) => (
                              <Badge key={`detail-rest-${day}`} variant="outline" className="rounded-full px-3 py-1 text-xs font-bold">
                                {String(day).toUpperCase()}
                              </Badge>
                            )) : <span className="text-sm text-muted-foreground">None</span>}
                          </div>
                          {selectedRequest.request_kind === 'custom' ? (
                            <p className="rounded-xl border border-brand/20 bg-brand/10 px-4 py-3 text-sm leading-relaxed text-foreground">
                              Custom schedule: on final approval the system creates a new template and assigns it.
                            </p>
                          ) : null}
                        </div>
                      )
                    })()}
                  </ScheduleDetailSection>

                  <ScheduleDetailSection icon={Inbox} title="Requester remarks">
                    <p className="whitespace-pre-wrap text-sm leading-relaxed text-foreground">
                      {sanitizeApprovalDisplayText(selectedRequest.remarks) || 'No note provided.'}
                    </p>
                  </ScheduleDetailSection>
                </div>

                <div className="space-y-6">
                  <ScheduleApprovalChain steps={selectedRequest.approval_progress || []} />
                  <ScheduleHistoryTimeline history={selectedRequest.approval_history || []} />
                </div>
              </div>
            ) : (
              <div className="flex min-h-72 items-center justify-center">
                <Loader2 className="size-8 animate-spin text-muted-foreground" />
              </div>
            )}
          </div>

          <DialogFooter className="shrink-0 border-t border-border/70 bg-card px-7 py-5 dark:border-white/10">
            <Button type="button" variant="outline" className="h-12 rounded-xl px-8 text-base font-semibold" onClick={() => setDetailOpen(false)}>
              Close
            </Button>
          </DialogFooter>
        </DialogContent>
      </Dialog>
      <Dialog open={actionDialog.open} onOpenChange={(open) => setActionDialog((prev) => ({ ...prev, open }))}>
        <DialogContent className="max-w-lg border-border/60 bg-card shadow-2xl dark:border-border/50">
          <DialogHeader>
            <DialogTitle>{actionDialog.mode === 'approve' ? 'Approve schedule request' : 'Reject schedule request'}</DialogTitle>
            <DialogDescription>
              {actionDialog.request?.requested_by_name} - {scheduleName(actionDialog.request)}
            </DialogDescription>
          </DialogHeader>
          <div className="space-y-2">
            <Label>Remarks</Label>
            <Textarea
              rows={5}
              value={remarks}
              onChange={(e) => setRemarks(e.target.value)}
              placeholder={actionDialog.mode === 'approve' ? 'Optional approval note' : 'Reason for rejection'}
            />
          </div>
          <DialogFooter>
            <Button type="button" variant="outline" className="rounded-lg" onClick={() => setActionDialog({ open: false, mode: 'approve', request: null })}>
              Cancel
            </Button>
            <Button
              type="button"
              className="rounded-lg"
              disabled={saving || (actionDialog.mode === 'reject' && !remarks.trim())}
              onClick={handleAction}
            >
              {saving ? <Loader2 className="mr-2 size-4 animate-spin" /> : null}
              {actionDialog.mode === 'approve' ? 'Approve' : 'Reject'}
            </Button>
          </DialogFooter>
        </DialogContent>
      </Dialog>

      <Dialog open={deleteDialog.open} onOpenChange={(open) => !open && setDeleteDialog({ open: false, request: null })}>
        <DialogContent className="max-w-md border-border/60 bg-card shadow-2xl dark:border-border/50">
          <DialogHeader>
            <DialogTitle>Delete schedule request</DialogTitle>
            <DialogDescription>Are you sure you want to delete this request?</DialogDescription>
          </DialogHeader>
          <DialogFooter>
            <Button type="button" variant="outline" className="rounded-lg" onClick={() => setDeleteDialog({ open: false, request: null })} disabled={saving}>
              Cancel
            </Button>
            <Button type="button" variant="destructive" className="rounded-lg" onClick={handleDelete} disabled={saving}>
              {saving ? <Loader2 className="mr-2 size-4 animate-spin" /> : <Trash2 className="mr-2 size-4" />}
              Delete
            </Button>
          </DialogFooter>
        </DialogContent>
      </Dialog>
    </div>
  )
}

function SummaryCard({ label, value, tone }) {
  const tones = {
    amber: {
      card: 'border-orange-200/80 bg-orange-50/25 dark:border-orange-900/35 dark:bg-orange-950/10',
      icon: 'bg-orange-100 text-orange-600 dark:bg-orange-950/45 dark:text-orange-300',
      badge: 'bg-orange-100 text-orange-700 dark:bg-orange-950/45 dark:text-orange-200',
      Icon: Clock,
    },
    emerald: {
      card: 'border-emerald-200/80 bg-emerald-50/20 dark:border-emerald-900/35 dark:bg-emerald-950/10',
      icon: 'bg-emerald-100 text-emerald-700 dark:bg-emerald-950/45 dark:text-emerald-300',
      badge: 'bg-emerald-100 text-emerald-700 dark:bg-emerald-950/45 dark:text-emerald-200',
      Icon: CheckCircle2,
    },
    rose: {
      card: 'border-rose-200/80 bg-rose-50/20 dark:border-rose-900/35 dark:bg-rose-950/10',
      icon: 'bg-rose-100 text-rose-700 dark:bg-rose-950/45 dark:text-rose-300',
      badge: 'bg-rose-100 text-rose-700 dark:bg-rose-950/45 dark:text-rose-200',
      Icon: XCircle,
    },
  }
  const toneDef = tones[tone] || tones.amber
  const Icon = toneDef.Icon

  return (
    <Card className={cn(scheduleCardClass, toneDef.card, 'overflow-hidden')}>
      <CardContent className="flex items-center gap-5 p-6">
        <div className={cn('flex size-16 shrink-0 items-center justify-center rounded-full', toneDef.icon)}>
          <Icon className="size-8" aria-hidden />
        </div>
        <div className="min-w-0 flex-1">
          <p className="text-base font-medium text-muted-foreground">{label}</p>
          <div className="mt-2 flex items-center justify-between gap-4">
            <p className="text-4xl font-black tracking-tight text-foreground">{value}</p>
            <Badge className={cn('rounded-full px-3 py-1 text-xs font-bold', toneDef.badge)}>{label}</Badge>
          </div>
        </div>
      </CardContent>
    </Card>
  )
}
