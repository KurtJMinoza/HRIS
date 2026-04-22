import { CalendarDays, Calendar, Clock, FileText } from 'lucide-react'
import { Badge } from '@/components/ui/badge'
import { Button } from '@/components/ui/button'
import {
  Dialog,
  DialogContent,
  DialogDescription,
  DialogHeader,
  DialogTitle,
} from '@/components/ui/dialog'
import { cn } from '@/lib/utils'
import { ApprovalChainDetailView } from '@/components/approval/ApprovalChainDetailView'
import { sanitizeApprovalDisplayText } from '@/lib/approvalText'
import {
  APP_MODAL_DESCRIPTION_CLASS,
  APP_MODAL_FOOTER,
  APP_MODAL_FORM_BODY,
  APP_MODAL_HEADER,
  APP_MODAL_OUTLINE_BUTTON_CLASS,
  appModalDialogContentClass,
} from '@/lib/appModalStyles'

function formatDateTime(iso) {
  if (!iso) return '—'
  try {
    return new Date(iso).toLocaleString('en-PH', { dateStyle: 'medium', timeStyle: 'short' })
  } catch {
    return iso
  }
}

function formatDateShort(iso) {
  if (!iso) return '—'
  try {
    const d = new Date(`${iso}T12:00:00`)
    return d.toLocaleDateString('en-PH', {
      weekday: 'short',
      month: 'short',
      day: 'numeric',
      year: 'numeric',
    })
  } catch {
    return iso
  }
}

function formatDateRangeLabel(start, end) {
  if (!start || !end) return '—'
  if (start === end) return formatDateShort(start)
  return `${formatDateShort(start)} – ${formatDateShort(end)}`
}

function formatTimeHM(value) {
  if (!value) return '—'
  try {
    const [h, m] = String(value).split(':')
    const date = new Date()
    date.setHours(Number(h), Number(m), 0, 0)
    return date.toLocaleTimeString('en-PH', {
      hour: 'numeric',
      minute: '2-digit',
      hour12: true,
    })
  } catch {
    return value
  }
}

function leaveTypeLabel(type) {
  const t = String(type || '').toLowerCase()
  const map = {
    vacation: 'Vacation',
    sick: 'Sick',
    emergency: 'Emergency',
    undertime: 'Undertime',
    half_day: 'Half day',
    other: 'Other',
  }
  return map[t] || (type ? String(type).replace(/_/g, ' ') : '—')
}

function computeLeaveDurationDays(leave) {
  if (!leave?.start_date || !leave?.end_date) return null
  const t = String(leave.type || '').toLowerCase()
  if (t === 'half_day') return 0.5
  if (t === 'undertime') return null
  const s = new Date(`${leave.start_date}T12:00:00`)
  const e = new Date(`${leave.end_date}T12:00:00`)
  const diff = Math.round((e - s) / 86400000) + 1
  return Math.max(0, diff)
}

function formatDurationSummary(leave) {
  if (!leave?.start_date || !leave?.end_date) return '—'
  const { type, half_type } = leave
  if (type === 'half_day') {
    const label = half_type === 'am' ? 'AM' : half_type === 'pm' ? 'PM' : ''
    return `0.5 day${label ? ` (${label})` : ''}`
  }
  if (type === 'undertime') {
    if (typeof leave.undertime_minutes === 'number') {
      return `${leave.undertime_minutes} min (${(leave.undertime_minutes / 60).toFixed(2)} hours)`
    }
    return '—'
  }
  const d = computeLeaveDurationDays(leave)
  if (d === null) return '—'
  if (d === 0.5) return '0.5 day'
  return `${d} day${d === 1 ? '' : 's'}`
}

function supportingDocUrls(leave) {
  if (!leave) return []
  if (Array.isArray(leave.document_urls) && leave.document_urls.length) return leave.document_urls
  if (leave.document_url) return [leave.document_url]
  return []
}

/** Map API display_status to badge styles (aligned with admin Attendance Corrections). */
function leaveDisplayStatusBadgeClass(displayStatus, status) {
  const s = String(status || '').toLowerCase()
  if (!displayStatus && s === 'approved') {
    return 'bg-gradient-to-br from-emerald-100 to-teal-50 text-emerald-950 shadow-emerald-500/25 ring-1 ring-emerald-200/90 dark:from-emerald-950/50 dark:to-emerald-950/30 dark:text-emerald-50 dark:ring-emerald-500/15'
  }
  if (!displayStatus && s === 'rejected') {
    return 'bg-gradient-to-br from-red-100 to-rose-50 text-red-950 shadow-red-500/20 ring-1 ring-red-200/80 dark:from-red-950/45 dark:to-red-950/25 dark:text-red-100 dark:ring-red-500/30'
  }
  if (!displayStatus && s === 'pending') {
    return 'bg-amber-100 text-amber-950 shadow-amber-500/15 ring-1 ring-amber-200/80 dark:bg-amber-950/45 dark:text-amber-100'
  }
  if (!displayStatus) return 'bg-muted text-muted-foreground shadow-sm'
  if (displayStatus === 'HR Approved' || displayStatus === 'Approved') {
    return 'bg-gradient-to-br from-emerald-100 to-teal-50 text-emerald-950 shadow-emerald-500/25 ring-1 ring-emerald-200/90 dark:from-emerald-950/50 dark:to-emerald-950/30 dark:text-emerald-50 dark:ring-emerald-500/15'
  }
  if (displayStatus === 'Rejected') {
    return 'bg-gradient-to-br from-red-100 to-rose-50 text-red-950 shadow-red-500/20 ring-1 ring-red-200/80 dark:from-red-950/45 dark:to-red-950/25 dark:text-red-100 dark:ring-red-500/30'
  }
  if (displayStatus === 'Draft') {
    return 'bg-muted text-muted-foreground shadow-sm'
  }
  if (displayStatus.startsWith('Pending Department')) {
    return 'bg-amber-100 text-amber-950 shadow-amber-500/15 ring-1 ring-amber-200/80 dark:bg-amber-950/45 dark:text-amber-100'
  }
  if (displayStatus.startsWith('Pending HR')) {
    return 'bg-violet-100 text-violet-950 shadow-violet-500/15 ring-1 ring-violet-200/70 dark:bg-violet-950/45 dark:text-violet-100'
  }
  if (displayStatus.startsWith('Pending Branch')) {
    return 'bg-sky-100 text-sky-950 shadow-sky-500/15 ring-1 ring-sky-200/70 dark:bg-sky-950/40 dark:text-sky-100'
  }
  if (displayStatus.startsWith('Pending Company')) {
    return 'bg-indigo-100 text-indigo-950 shadow-indigo-500/15 ring-1 ring-indigo-200/70 dark:bg-indigo-950/45 dark:text-indigo-100'
  }
  if (displayStatus.startsWith('Pending')) {
    return 'bg-amber-100 text-amber-950 shadow-amber-500/15 ring-1 ring-amber-200/80 dark:bg-amber-950/45 dark:text-amber-100'
  }
  return 'bg-muted text-muted-foreground shadow-sm'
}

function leaveApprovalHistoryActionLabel(action) {
  switch (action) {
    case 'hr_remark':
      return 'HR internal remark'
    case 'approve_first':
      return 'Line manager approval'
    case 'approve_final':
      return 'HR final approval'
    case 'reject':
      return 'Rejected'
    case 'file':
      return 'Request filed'
    default:
      return action || 'Action'
  }
}

/**
 * Leave request details — same shell as admin Attendance Corrections “View” modal.
 *
 * @param {{
 *   open: boolean
 *   onOpenChange: (open: boolean) => void
 *   leave: object | null
 *   showEmployeeName?: boolean
 *   resolveDocUrl?: (path: string) => string
 * }} props
 */
export function LeaveRequestDetailModal({
  open,
  onOpenChange,
  leave,
  showEmployeeName = false,
  resolveDocUrl = (url) => url,
}) {
  const badgeLabel = leave?.display_status || leave?.status || '—'

  return (
    <Dialog open={open} onOpenChange={onOpenChange}>
      <DialogContent
        className={appModalDialogContentClass({ size: 'md' })}
        innerClassName="gap-0 overflow-hidden p-0 pr-0"
      >
        <DialogHeader className="sr-only">
          <DialogTitle>Leave request details</DialogTitle>
          <DialogDescription>Approval chain, dates, and approval history.</DialogDescription>
        </DialogHeader>
        {leave && (
          <>
            <div className={APP_MODAL_HEADER}>
              <div className="space-y-3 text-left">
                <p className="text-[11px] font-semibold uppercase tracking-[0.14em] text-muted-foreground">Leave request</p>
                <div className="flex flex-wrap items-center gap-3">
                  <span className="font-mono text-2xl font-bold tracking-tight text-foreground @sm:text-3xl">
                    #{leave.id}
                  </span>
                  <Badge
                    className={cn(
                      'rounded-full px-3 py-1 text-sm font-semibold',
                      leaveDisplayStatusBadgeClass(leave.display_status, leave.status)
                    )}
                  >
                    {badgeLabel}
                  </Badge>
                </div>
                <p className={cn(APP_MODAL_DESCRIPTION_CLASS, 'max-w-none')}>
                  Review summary, leave dates, approval chain, and history below.
                </p>
              </div>
            </div>

            <div className={cn(APP_MODAL_FORM_BODY, 'space-y-5 text-sm')}>
              {leave.start_date ? (
                <div className="rounded-lg border border-border/60 bg-muted/30 px-4 py-3 dark:bg-muted/20">
                  <div className="flex items-center gap-3">
                    <div className="flex size-10 items-center justify-center rounded-md bg-background text-muted-foreground shadow-sm ring-1 ring-border/50">
                      <CalendarDays className="size-5" aria-hidden />
                    </div>
                    <div>
                      <p className="text-[11px] font-semibold uppercase tracking-[0.12em] text-muted-foreground">
                        Leave dates
                      </p>
                      <p className="text-base font-semibold tabular-nums text-foreground">
                        {formatDateRangeLabel(leave.start_date, leave.end_date)}
                      </p>
                    </div>
                  </div>
                </div>
              ) : null}

              <section className="rounded-lg border border-border/60 bg-card p-5 shadow-sm dark:border-border/50">
                <h3 className="mb-4 border-b border-border/50 pb-3 text-[11px] font-semibold uppercase tracking-[0.12em] text-muted-foreground">
                  Summary
                </h3>
                <div className="grid grid-cols-1 gap-x-8 gap-y-3 @sm:grid-cols-2">
                  <div className="grid grid-cols-[auto_1fr] gap-x-3 gap-y-1 text-sm">
                    {showEmployeeName ? (
                      <>
                        <span className="text-muted-foreground">Employee</span>
                        <span className="font-medium text-foreground">{leave.employee_name || '—'}</span>
                      </>
                    ) : null}
                    <span className="text-muted-foreground">Leave type</span>
                    <span>
                      <Badge variant="outline" className="font-normal">
                        {leaveTypeLabel(leave.type)}
                      </Badge>
                    </span>
                    <span className="text-muted-foreground">Duration</span>
                    <span className="text-foreground">{formatDurationSummary(leave)}</span>
                    <span className="text-muted-foreground">Date filed</span>
                    <span className="tabular-nums">{leave.created_at ? formatDateTime(leave.created_at) : '—'}</span>
                    {leave.reviewed_at ? (
                      <>
                        <span className="text-muted-foreground">Last updated</span>
                        <span className="tabular-nums">{formatDateTime(leave.reviewed_at)}</span>
                      </>
                    ) : null}
                    {leave.approval_stage != null && (
                      <>
                        <span className="text-muted-foreground">Approval stage</span>
                        <span className="text-foreground">{String(leave.approval_stage)}</span>
                      </>
                    )}
                  </div>
                </div>
              </section>

              <section className="rounded-lg border border-border/60 bg-muted/20 p-5 dark:border-border/50 dark:bg-muted/15">
                <h3 className="mb-4 flex items-center gap-2 border-b border-border/50 pb-3 text-[11px] font-semibold uppercase tracking-[0.12em] text-muted-foreground">
                  <Calendar className="size-4 text-muted-foreground" aria-hidden />
                  Leave details
                </h3>
                <div className="grid grid-cols-1 gap-3 @sm:grid-cols-2">
                  <div className="rounded-lg border border-border/60 bg-background p-4 shadow-sm dark:border-border/50">
                    <p className="text-[11px] font-semibold uppercase tracking-[0.12em] text-muted-foreground">Start</p>
                    <p className="mt-1 font-mono text-xl font-semibold tabular-nums tracking-tight text-foreground @sm:text-2xl">
                      {formatDateShort(leave.start_date)}
                    </p>
                  </div>
                  <div className="rounded-lg border border-border/60 bg-background p-4 shadow-sm dark:border-border/50">
                    <p className="text-[11px] font-semibold uppercase tracking-[0.12em] text-muted-foreground">End</p>
                    <p className="mt-1 font-mono text-xl font-semibold tabular-nums tracking-tight text-foreground @sm:text-2xl">
                      {formatDateShort(leave.end_date)}
                    </p>
                  </div>
                </div>
                {leave.type === 'undertime' && leave.undertime_time ? (
                  <div className="mt-4 flex items-center gap-2 rounded-lg border border-border/50 bg-card px-3 py-2 text-sm">
                    <Clock className="size-4 shrink-0 text-muted-foreground" aria-hidden />
                    <span className="text-muted-foreground">Early out:</span>
                    <span className="font-medium tabular-nums">{formatTimeHM(leave.undertime_time)}</span>
                  </div>
                ) : null}
                {leave.type === 'half_day' ? (
                  <p className="mt-3 text-sm text-muted-foreground">
                    Half day:{' '}
                    <span className="font-medium text-foreground">
                      {leave.half_type === 'am' ? 'AM' : leave.half_type === 'pm' ? 'PM' : '—'}
                    </span>
                  </p>
                ) : null}
              </section>

              {Array.isArray(leave.approval_progress) && leave.approval_progress.length > 0 ? (
                <section className="rounded-lg border border-border/60 bg-card p-5 shadow-sm dark:border-border/50">
                  <h3 className="mb-4 border-b border-border/50 pb-3 text-[11px] font-semibold uppercase tracking-[0.12em] text-muted-foreground">
                    Approval chain
                  </h3>
                  <ApprovalChainDetailView steps={leave.approval_progress} />
                </section>
              ) : null}

              {leave.hr_wait_message ? (
                <div
                  role="status"
                  className="rounded-xl border border-amber-500/45 bg-amber-500/[0.12] px-4 py-3 text-sm text-amber-950 dark:border-amber-500/35 dark:bg-amber-950/25 dark:text-amber-100"
                >
                  {leave.hr_wait_message}
                </div>
              ) : null}

              {Array.isArray(leave.approval_history) && leave.approval_history.length > 0 ? (
                <section className="rounded-lg border border-border/60 bg-card p-5 shadow-sm dark:border-border/50">
                  <h3 className="mb-4 border-b border-border/50 pb-3 text-[11px] font-semibold uppercase tracking-[0.12em] text-muted-foreground">
                    Approval history
                  </h3>
                  <ol className="relative ml-0.5 border-l-2 border-border pl-6">
                    {[...leave.approval_history]
                      .sort((a, b) => new Date(a.at || 0) - new Date(b.at || 0))
                      .map((h, idx) => {
                        const actionLabel = leaveApprovalHistoryActionLabel(h.action)
                        const headline = [h.actor_name, h.approver_role || actionLabel].filter(Boolean).join(' · ')
                        return (
                          <li key={`${h.at}-${idx}-${h.action}`} className="relative pb-6 last:pb-0">
                            <span className="absolute -left-[1.35rem] top-1 size-2.5 rounded-full border-2 border-background bg-primary ring-2 ring-background" />
                            <div className="rounded-lg border border-border/50 bg-muted/30 px-3 py-2.5 dark:bg-white/5">
                              <div className="flex flex-wrap items-baseline justify-between gap-2">
                                <p className="text-sm font-semibold text-foreground">{headline || actionLabel}</p>
                                <time className="text-xs tabular-nums text-muted-foreground" dateTime={h.at || undefined}>
                                  {h.at ? formatDateTime(h.at) : '—'}
                                </time>
                              </div>
                              {sanitizeApprovalDisplayText(h?.details) ? (
                                <p className="mt-2 whitespace-pre-wrap text-xs leading-relaxed text-muted-foreground">
                                  {sanitizeApprovalDisplayText(h.details)}
                                </p>
                              ) : null}
                            </div>
                          </li>
                        )
                      })}
                  </ol>
                </section>
              ) : null}

              {leave.notes ? (
                <section className="rounded-lg border border-border/60 bg-card p-5 shadow-sm dark:border-border/50">
                  <h3 className="mb-3 border-b border-border/50 pb-3 text-[11px] font-semibold uppercase tracking-[0.12em] text-muted-foreground">
                    Remarks
                  </h3>
                  <p className="whitespace-pre-wrap text-sm leading-relaxed text-foreground">{leave.notes}</p>
                </section>
              ) : null}

              {leave.rejection_note ? (
                <section className="rounded-lg border border-destructive/30 bg-destructive/[0.06] p-5 dark:border-destructive/25 dark:bg-destructive/10">
                  <h3 className="mb-2 text-[11px] font-semibold uppercase tracking-[0.12em] text-destructive">
                    Rejection reason
                  </h3>
                  <p className="whitespace-pre-wrap text-sm leading-relaxed text-foreground">{leave.rejection_note}</p>
                </section>
              ) : null}

              {supportingDocUrls(leave).length > 0 ? (
                <section className="rounded-lg border border-border/60 bg-card p-5 shadow-sm dark:border-border/50">
                  <h3 className="mb-3 border-b border-border/50 pb-3 text-[11px] font-semibold uppercase tracking-[0.12em] text-muted-foreground">
                    Supporting documents
                  </h3>
                  <div className="flex flex-col gap-2">
                    {supportingDocUrls(leave).map((url, i) => (
                      <a
                        key={`${url}-${i}`}
                        href={resolveDocUrl(url)}
                        target="_blank"
                        rel="noreferrer"
                        className="inline-flex items-center gap-2 rounded-lg border border-border/60 bg-background px-3 py-2 text-sm font-medium text-primary hover:bg-muted/50"
                      >
                        <FileText className="size-4 shrink-0" aria-hidden />
                        {supportingDocUrls(leave).length > 1 ? `View file ${i + 1}` : 'View document'}
                      </a>
                    ))}
                  </div>
                </section>
              ) : null}
            </div>

            <div className={APP_MODAL_FOOTER}>
              <p className="text-xs text-muted-foreground">
                <kbd className="rounded border border-border bg-background px-1 font-mono text-[10px]">Esc</kbd> to close
              </p>
              <Button type="button" variant="outline" className={APP_MODAL_OUTLINE_BUTTON_CLASS} onClick={() => onOpenChange(false)}>
                Close
              </Button>
            </div>
          </>
        )}
      </DialogContent>
    </Dialog>
  )
}
