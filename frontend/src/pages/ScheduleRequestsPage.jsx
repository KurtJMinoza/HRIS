import { useCallback, useEffect, useMemo, useState } from 'react'
import { Inbox, Loader2, RefreshCw, Search, Sparkles } from 'lucide-react'
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
import { ApprovalChainDetailView } from '@/components/approval/ApprovalChainDetailView'
import { RemarksPreviewCell } from '@/components/presenceFiling/CorrectionTableCells'
import { ScheduleRequestStatusBadge } from '@/components/schedules/ScheduleRequestTableCells'
import { AdminDataTableActions } from '@/components/admin/AdminDataTableActions'
import { approveScheduleRequest, getAdminScheduleRequests, rejectScheduleRequest } from '@/api'
import { cn } from '@/lib/utils'

function formatDateTime(value) {
  if (!value) return '—'
  const date = new Date(value)
  return Number.isNaN(date.getTime()) ? value : date.toLocaleString('en-PH', { dateStyle: 'medium', timeStyle: 'short' })
}

function formatShortDate(iso) {
  if (!iso) return '—'
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
    ? `${ws.break_start} – ${ws.break_end}`
    : breakMinutes > 0
      ? `${breakMinutes} min`
      : 'No break set'
  return { restDays, workDaysCount, breakLabel }
}

export default function ScheduleRequestsPage() {
  const { toast } = useToast()
  const [loading, setLoading] = useState(true)
  const [saving, setSaving] = useState(false)
  const [statusFilter, setStatusFilter] = useState('all')
  const [requests, setRequests] = useState([])
  const [selectedRequest, setSelectedRequest] = useState(null)
  const [detailOpen, setDetailOpen] = useState(false)
  const [actionDialog, setActionDialog] = useState({ open: false, mode: 'approve', request: null })
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

  return (
    <div className="min-h-0 min-w-0 max-w-full space-y-6 overflow-x-hidden">
      <div className="flex w-full min-w-0 flex-col gap-5 border-b border-border/60 pb-6 @lg:flex-row @lg:items-end @lg:justify-between">
        <div className="max-w-2xl space-y-3">
          <p className="text-sm font-semibold uppercase tracking-[0.2em] text-slate-500 dark:text-slate-400">Schedules</p>
          <h1 className="hr-page-title text-slate-900 dark:text-slate-50">Schedule Requests</h1>
          <p className="text-base leading-relaxed text-slate-600 dark:text-slate-400">
            Review schedule change requests in your approval scope — same table experience as Correction Requests.
          </p>
        </div>
        <div className="flex w-full min-w-0 flex-col gap-3 @sm:flex-row @sm:flex-wrap @sm:items-center @sm:justify-end">
          <Select value={statusFilter} onValueChange={setStatusFilter}>
            <SelectTrigger className="h-11 min-w-[180px] rounded-xl border-slate-200/90">
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
            className="h-11 shrink-0 gap-2 rounded-xl border-border/80"
            onClick={loadData}
          >
            <RefreshCw className={cn('size-4', loading && 'animate-spin')} />
            Refresh
          </Button>
        </div>
      </div>

      <div className="grid gap-4 md:grid-cols-3">
        <SummaryCard label="Pending" value={summary.pending} tone="amber" />
        <SummaryCard label="Approved" value={summary.approved} tone="emerald" />
        <SummaryCard label="Rejected" value={summary.rejected} tone="rose" />
      </div>

      <Card className="w-full min-w-0 overflow-hidden rounded-2xl border border-slate-200/80 bg-white shadow-xl shadow-slate-200/50 ring-1 ring-slate-100/80 dark:border-slate-800 dark:bg-slate-950/40 dark:shadow-black/40 dark:ring-slate-800/50">
        <CardHeader className="space-y-1 border-b border-slate-100 bg-gradient-to-r from-slate-50/90 to-white px-5 py-5 dark:border-slate-800 dark:from-slate-900/40 dark:to-slate-950/20 @md:px-6 @md:py-6">
          <CardTitle className="text-xl font-semibold tracking-tight text-slate-900 dark:text-slate-50">
            Approval queue
          </CardTitle>
          <CardDescription className="text-sm text-slate-600 dark:text-slate-400">
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
              <div className="border-b border-slate-100 bg-muted/15 px-4 py-5 dark:border-slate-800 dark:bg-muted/10 @sm:px-6 md:px-8">
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
                      placeholder="Search employee, schedule, request ID, or remarks…"
                      className="h-12 w-full min-w-0 rounded-xl border-slate-200/90 bg-white pl-10 pr-14 text-[15px] shadow-[0_1px_2px_rgba(15,23,42,0.06)] dark:border-slate-700 dark:bg-slate-950/45"
                      aria-label="Search approval queue"
                    />
                    <div className="absolute right-2 top-1/2 flex -translate-y-1/2 items-center gap-1.5">
                      <Button
                        type="button"
                        variant="secondary"
                        size="icon"
                        className="size-9 rounded-lg"
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
                    {queueSearch.trim() ? ' · filtered' : ''}
                  </p>
                </div>
              </div>

              {!loading && filteredQueue.length === 0 ? (
                <div className="flex flex-col items-center justify-center px-6 py-20 text-center">
                  <div className="mb-6 flex size-24 items-center justify-center rounded-3xl border border-dashed border-slate-200 bg-slate-50 dark:border-slate-700 dark:bg-slate-900/40">
                    <Inbox className="size-12 text-slate-400" aria-hidden />
                  </div>
                  <div className="flex items-center gap-2 text-xl font-semibold tracking-tight text-slate-900 dark:text-slate-50">
                    <Sparkles className="size-6 text-amber-500" aria-hidden />
                    {requests.length === 0 ? 'No requests in your scope' : 'No requests match'}
                  </div>
                  <p className="mt-3 max-w-md text-sm leading-relaxed text-slate-600 dark:text-slate-400">
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
                              <p className="font-semibold leading-snug text-foreground">{item.requested_by_name || '—'}</p>
                              <p className="mt-0.5 text-xs text-muted-foreground">{item.requested_by_role_label || '—'}</p>
                            </div>
                          </TableCell>
                          <TableCell className={cn('align-top', cellPad)}>
                            {(() => {
                              const details = scheduleDetailSummary(item)
                              return <div>
                              <p className="font-semibold leading-snug text-foreground">
                                {item.working_schedule?.name || item.custom_schedule_payload?.name || 'Schedule'}
                              </p>
                              <p className="mt-0.5 text-xs text-muted-foreground">
                                {item.working_schedule?.time_in || item.custom_schedule_payload?.time_in} –{' '}
                                {item.working_schedule?.time_out || item.custom_schedule_payload?.time_out}
                              </p>
                              <div className="mt-1.5 flex flex-wrap items-center gap-2">
                                {item.request_kind === 'custom' ? (
                                  <Badge
                                    variant="outline"
                                    className="rounded-lg border-slate-200/90 bg-white px-2 py-0 text-[10px] font-medium shadow-sm dark:border-slate-700 dark:bg-slate-900/40"
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
                            {item.filed_at ? formatDateTime(item.filed_at) : '—'}
                          </TableCell>
                          <TableCell className={cn('text-right align-middle', cellPad)}>
                            <AdminDataTableActions
                              onView={() => {
                                setSelectedRequest(item)
                                setDetailOpen(true)
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
        <DialogContent className="max-w-5xl border-border/60 bg-card shadow-2xl dark:border-border/50">
          <DialogHeader>
            <DialogTitle>{selectedRequest?.requested_by_name || 'Schedule request'}</DialogTitle>
            <DialogDescription>{selectedRequest?.display_status || 'Request details'}</DialogDescription>
          </DialogHeader>
          {selectedRequest ? (
            <div className="grid gap-6 lg:grid-cols-[minmax(0,1fr)_minmax(0,1fr)]">
              <div className="space-y-4">
                <div className="rounded-xl border border-border/60 bg-muted/20 p-4 dark:border-border/50">
                  <p className="text-sm font-medium text-foreground">Requested schedule</p>
                  <p className="mt-1 text-lg font-semibold text-foreground">
                    {selectedRequest.working_schedule?.name || selectedRequest.custom_schedule_payload?.name}
                  </p>
                  <p className="mt-1 text-sm text-muted-foreground">
                    {selectedRequest.working_schedule?.time_in || selectedRequest.custom_schedule_payload?.time_in} –{' '}
                    {selectedRequest.working_schedule?.time_out || selectedRequest.custom_schedule_payload?.time_out}
                  </p>
                  {(() => {
                    const details = scheduleDetailSummary(selectedRequest)
                    return (
                      <div className="mt-3 space-y-2">
                        <div className="flex flex-wrap items-center gap-2">
                          <span className="text-xs font-medium text-muted-foreground">Rest days:</span>
                          {details.restDays.length ? details.restDays.map((d) => (
                            <Badge
                              key={`detail-rest-${d}`}
                              variant="outline"
                              className="rounded-full px-2 py-0 text-[10px]"
                            >
                              {String(d).toUpperCase()}
                            </Badge>
                          )) : <span className="text-xs text-muted-foreground">None</span>}
                        </div>
                        <p className="text-xs text-muted-foreground">Break: {details.breakLabel}</p>
                        <p className="text-xs text-muted-foreground">Work days: {details.workDaysCount} per week</p>
                      </div>
                    )
                  })()}
                  {selectedRequest.request_kind === 'custom' ? (
                    <p className="mt-2 text-xs text-muted-foreground">
                      Custom schedule — on final approval the system creates a new template and assigns it.
                    </p>
                  ) : null}
                </div>
                <div className="rounded-xl border border-border/60 bg-background p-4 dark:border-border/50">
                  <p className="text-sm font-medium text-foreground">Effective date (requested)</p>
                  <p className="mt-2 text-sm tabular-nums text-muted-foreground">{formatShortDate(selectedRequest.effective_from)}</p>
                </div>
                <div className="rounded-xl border border-border/60 bg-background p-4 dark:border-border/50">
                  <p className="text-sm font-medium text-foreground">Employee note</p>
                  <p className="mt-2 text-sm leading-relaxed text-muted-foreground">{selectedRequest.remarks || 'No note provided.'}</p>
                </div>
              </div>
              <ApprovalChainDetailView steps={selectedRequest.approval_progress || []} />
            </div>
          ) : null}
        </DialogContent>
      </Dialog>

      <Dialog open={actionDialog.open} onOpenChange={(open) => setActionDialog((prev) => ({ ...prev, open }))}>
        <DialogContent className="max-w-lg border-border/60 bg-card shadow-2xl dark:border-border/50">
          <DialogHeader>
            <DialogTitle>{actionDialog.mode === 'approve' ? 'Approve schedule request' : 'Reject schedule request'}</DialogTitle>
            <DialogDescription>
              {actionDialog.request?.requested_by_name} ·{' '}
              {actionDialog.request?.working_schedule?.name || actionDialog.request?.custom_schedule_payload?.name}
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
    </div>
  )
}

function SummaryCard({ label, value, tone }) {
  const toneClass = {
    amber: 'bg-amber-100 text-amber-900 dark:bg-amber-950/40 dark:text-amber-100',
    emerald: 'bg-emerald-100 text-emerald-900 dark:bg-emerald-950/40 dark:text-emerald-100',
    rose: 'bg-rose-100 text-rose-900 dark:bg-rose-950/40 dark:text-rose-100',
  }[tone]

  return (
    <Card className="border-border/60 shadow-sm dark:border-border/50">
      <CardContent className="p-5">
        <p className="text-sm text-muted-foreground">{label}</p>
        <div className="mt-3 flex items-center justify-between">
          <p className="text-3xl font-semibold tracking-tight text-foreground">{value}</p>
          <Badge className={`rounded-full ${toneClass}`}>{label}</Badge>
        </div>
      </CardContent>
    </Card>
  )
}
