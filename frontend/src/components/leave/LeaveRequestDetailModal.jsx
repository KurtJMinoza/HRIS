import { CalendarDays, Calendar, Clock, FileText, MessageSquareText, UsersRound } from 'lucide-react'
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
import { sanitizeApprovalDisplayText } from '@/lib/approvalText'

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


function getInitials(name) {
  if (!name || typeof name !== 'string') return '?'
  const parts = name.trim().split(/\s+/).filter(Boolean)
  if (parts.length === 0) return '?'
  if (parts.length === 1) return parts[0].slice(0, 2).toUpperCase()
  return `${parts[0][0] ?? ''}${parts[parts.length - 1][0] ?? ''}`.toUpperCase() || '?'
}

function approvalStepName(step) {
  if (step?.key === 'submitted') return step.submitter_name || '—'
  return step?.approver_name || step?.approver_role_label || '—'
}

function approvalStepRole(step) {
  if (step?.key === 'submitted') return 'Requester'
  return step?.approver_role_label || step?.approver_role || ''
}

function humanStepStatus(status) {
  switch (status) {
    case 'completed':
      return 'Completed'
    case 'current':
      return 'Pending'
    case 'pending':
      return 'Pending'
    case 'rejected':
      return 'Rejected'
    case 'skipped':
      return 'Skipped'
    default:
      return status ? String(status) : '—'
  }
}

function LeaveDetailSection({ icon: Icon, title, children, className }) {
  return (
    <section
      className={cn(
        'rounded-xl border border-border/70 bg-card p-5 shadow-[0_10px_28px_-22px_rgba(15,23,42,0.65),0_2px_8px_-6px_rgba(15,23,42,0.28)] dark:border-white/10 dark:bg-card/95 dark:shadow-[0_18px_42px_-28px_rgba(0,0,0,0.8)]',
        className
      )}
    >
      <h3 className="mb-4 flex items-center gap-3 border-b border-border/70 pb-3 text-[11px] font-black uppercase tracking-[0.2em] text-brand dark:border-white/10">
        {Icon ? <Icon className="size-5 shrink-0" aria-hidden /> : null}
        {title}
      </h3>
      {children}
    </section>
  )
}

function LeaveInitialsAvatar({ name }) {
  return (
    <div
      className="flex size-12 shrink-0 items-center justify-center rounded-full border border-brand/15 bg-brand/10 text-sm font-black uppercase text-brand shadow-sm dark:border-brand/25 dark:bg-brand/15"
      aria-hidden
    >
      {getInitials(name)}
    </div>
  )
}

function LeaveApprovalChain({ steps }) {
  if (!Array.isArray(steps) || steps.length === 0) return null

  return (
    <LeaveDetailSection icon={UsersRound} title="Approval chain">
      <ol className="space-y-4">
        {steps.map((step, idx) => {
          const name = approvalStepName(step)
          const role = approvalStepRole(step)
          const statusLabel = humanStepStatus(step.status)
          const statusLine =
            step.acted_at != null && step.acted_at !== ''
              ? `${statusLabel} · ${formatDateTime(step.acted_at)}`
              : statusLabel
          const remarks = sanitizeApprovalDisplayText(step?.remarks)

          return (
            <li key={step.key || `approval-step-${idx}`}>
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
                  <LeaveInitialsAvatar name={name} />
                  <div className="min-w-0 flex-1">
                    <p className="text-[15px] font-bold leading-snug text-foreground">{name}</p>
                    {role ? <p className="mt-1 text-sm font-medium text-foreground/85">{role}</p> : null}
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
    </LeaveDetailSection>
  )
}

function LeaveApprovalHistory({ history }) {
  if (!Array.isArray(history) || history.length === 0) return null

  return (
    <LeaveDetailSection icon={Clock} title="Approval history">
      <ol className="relative ml-1 border-l-2 border-border/70 pl-6 dark:border-white/10">
        {[...history]
          .sort((a, b) => new Date(a.at || 0) - new Date(b.at || 0))
          .map((item, idx) => {
            const actionLabel = leaveApprovalHistoryActionLabel(item.action)
            const details = sanitizeApprovalDisplayText(item?.details)
            const headline = [item.actor_name, item.approver_role].filter(Boolean).join(' · ') || actionLabel
            return (
              <li key={`${item.at}-${idx}-${item.action}`} className="relative pb-7 last:pb-0">
                <span className="absolute -left-[1.95rem] top-3 size-2.5 rounded-full bg-brand ring-4 ring-card dark:ring-card" />
                <div className="rounded-xl border border-border/70 bg-background/70 px-4 py-4 shadow-sm dark:border-white/10 dark:bg-background/35">
                  <div className="flex flex-wrap items-baseline justify-between gap-x-4 gap-y-2">
                    <p className="text-sm font-bold text-foreground">{headline}</p>
                    <time className="text-xs tabular-nums text-foreground/85" dateTime={item.at || undefined}>
                      {item.at ? formatDateTime(item.at) : '—'}
                    </time>
                  </div>
                  {details ? (
                    <p className="mt-3 whitespace-pre-wrap text-sm leading-relaxed text-foreground/90">{details}</p>
                  ) : null}
                </div>
              </li>
            )
          })}
      </ol>
    </LeaveDetailSection>
  )
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
  const docs = supportingDocUrls(leave)

  return (
    <Dialog open={open} onOpenChange={onOpenChange}>
      <DialogContent
        showCloseButton
        closeButtonClassName="right-4 top-4 size-10 rounded-lg border-border/80 bg-card/95 text-foreground shadow-md hover:bg-muted dark:border-white/10 dark:bg-card"
        innerClassName="flex min-h-0 flex-1 flex-col gap-0 overflow-hidden p-0 pb-0 pl-0 pr-14 pt-0"
        className="max-h-[92vh] max-w-[min(100vw-1rem,38rem)] flex flex-col overflow-hidden rounded-2xl border border-border/80 bg-card p-0 text-card-foreground shadow-[0_24px_80px_-28px_rgba(0,0,0,0.55)] scheme-light dark:border-white/10 dark:bg-card dark:scheme-dark"
      >
        <DialogHeader className="sr-only">
          <DialogTitle>Leave request details</DialogTitle>
          <DialogDescription>Approval chain, dates, and approval history.</DialogDescription>
        </DialogHeader>
        {leave && (
          <>
            <div className="shrink-0 border-b border-border/70 bg-card px-7 pb-7 pt-8 text-left dark:border-white/10">
              <div className="space-y-5">
                <p className="text-[11px] font-black uppercase tracking-[0.22em] text-brand">Leave request</p>
                <div className="flex flex-wrap items-center gap-3">
                  <span className="font-mono text-4xl font-black leading-none tracking-tight text-foreground">
                    #{leave.id}
                  </span>
                  <Badge
                    className={cn(
                      'rounded-full px-3.5 py-1.5 text-sm font-bold',
                      leaveDisplayStatusBadgeClass(leave.display_status, leave.status)
                    )}
                  >
                    {badgeLabel}
                  </Badge>
                </div>
                <p className="text-sm leading-relaxed text-muted-foreground">
                  Review summary, leave dates, approval chain, and history below.
                </p>
              </div>
            </div>

            <div className="min-h-0 flex-1 space-y-5 overflow-y-auto overscroll-contain bg-card px-7 py-6 text-sm dark:bg-card">
              <LeaveDetailSection icon={CalendarDays} title="Summary">
                <dl className="grid grid-cols-[minmax(0,12.5rem)_1fr] gap-x-4 gap-y-4 text-sm">
                  {showEmployeeName ? (
                    <>
                      <dt className="text-muted-foreground">Employee</dt>
                      <dd className="font-bold text-foreground">{leave.employee_name || '—'}</dd>
                    </>
                  ) : null}
                  <dt className="text-muted-foreground">Leave type</dt>
                  <dd>
                    <Badge
                      variant="outline"
                      className="rounded-full border-brand/25 bg-brand/10 px-3 py-1 font-bold text-brand dark:border-brand/30 dark:bg-brand/15"
                    >
                      {leaveTypeLabel(leave.type)}
                    </Badge>
                  </dd>
                  <dt className="text-muted-foreground">Leave dates</dt>
                  <dd className="font-bold tabular-nums text-foreground">{formatDateRangeLabel(leave.start_date, leave.end_date)}</dd>
                  <dt className="text-muted-foreground">Duration</dt>
                  <dd className="font-bold text-foreground">{formatDurationSummary(leave)}</dd>
                  <dt className="text-muted-foreground">Date filed</dt>
                  <dd className="tabular-nums text-foreground">{leave.created_at ? formatDateTime(leave.created_at) : '—'}</dd>
                  {leave.reviewed_at ? (
                    <>
                      <dt className="text-muted-foreground">Last updated</dt>
                      <dd className="tabular-nums text-foreground">{formatDateTime(leave.reviewed_at)}</dd>
                    </>
                  ) : null}
                  {leave.approval_stage != null ? (
                    <>
                      <dt className="text-muted-foreground">Approval stage</dt>
                      <dd className="text-foreground">{String(leave.approval_stage)}</dd>
                    </>
                  ) : null}
                </dl>
              </LeaveDetailSection>

              <LeaveDetailSection icon={Calendar} title="Leave details">
                <div className="divide-y divide-border/70 dark:divide-white/10">
                  <div className="flex min-h-14 items-center justify-between gap-4 py-2">
                    <p className="text-[15px] font-bold text-foreground">Start</p>
                    <p className="text-right font-mono text-base font-black tabular-nums tracking-tight text-foreground">
                      {formatDateShort(leave.start_date)}
                    </p>
                  </div>
                  <div className="flex min-h-14 items-center justify-between gap-4 py-4">
                    <p className="text-[15px] font-bold text-foreground">End</p>
                    <p className="text-right font-mono text-base font-black tabular-nums tracking-tight text-foreground">
                      {formatDateShort(leave.end_date)}
                    </p>
                  </div>
                  {leave.type === 'undertime' && leave.undertime_time ? (
                    <div className="flex min-h-14 items-center justify-between gap-4 py-4">
                      <p className="text-[15px] font-bold text-foreground">Early out</p>
                      <p className="font-mono text-base font-black tabular-nums tracking-tight text-foreground">
                        {formatTimeHM(leave.undertime_time)}
                      </p>
                    </div>
                  ) : null}
                  {leave.type === 'half_day' ? (
                    <div className="flex min-h-14 items-center justify-between gap-4 py-4">
                      <p className="text-[15px] font-bold text-foreground">Half day</p>
                      <p className="font-bold text-foreground">
                        {leave.half_type === 'am' ? 'AM' : leave.half_type === 'pm' ? 'PM' : '—'}
                      </p>
                    </div>
                  ) : null}
                </div>
              </LeaveDetailSection>

              {leave.hr_wait_message ? (
                <div
                  role="status"
                  className="rounded-xl border border-amber-500/45 bg-amber-500/[0.12] px-4 py-3 text-sm text-amber-950 dark:border-amber-500/35 dark:bg-amber-950/25 dark:text-amber-100"
                >
                  {leave.hr_wait_message}
                </div>
              ) : null}

              <LeaveApprovalChain steps={leave.approval_progress} />
              <LeaveApprovalHistory history={leave.approval_history} />

              {leave.notes ? (
                <LeaveDetailSection icon={MessageSquareText} title="Remarks">
                  <p className="whitespace-pre-wrap text-sm leading-relaxed text-foreground">{leave.notes}</p>
                </LeaveDetailSection>
              ) : null}

              {leave.rejection_note ? (
                <section className="rounded-xl border border-destructive/30 bg-destructive/[0.06] p-5 dark:border-destructive/25 dark:bg-destructive/10">
                  <h3 className="mb-2 text-[11px] font-semibold uppercase tracking-[0.12em] text-destructive">
                    Rejection reason
                  </h3>
                  <p className="whitespace-pre-wrap text-sm leading-relaxed text-foreground">{leave.rejection_note}</p>
                </section>
              ) : null}

              {docs.length > 0 ? (
                <LeaveDetailSection icon={FileText} title="Supporting documents">
                  <div className="flex flex-col gap-2">
                    {docs.map((url, i) => (
                      <a
                        key={`${url}-${i}`}
                        href={resolveDocUrl(url)}
                        target="_blank"
                        rel="noreferrer"
                        className="inline-flex items-center gap-2 rounded-lg border border-border/60 bg-background px-3 py-2 text-sm font-medium text-primary hover:bg-muted/50 dark:border-white/10 dark:bg-background/35"
                      >
                        <FileText className="size-4 shrink-0" aria-hidden />
                        {docs.length > 1 ? `View file ${i + 1}` : 'View document'}
                      </a>
                    ))}
                  </div>
                </LeaveDetailSection>
              ) : null}
            </div>

            <div className="mt-auto flex shrink-0 flex-wrap items-center justify-between gap-3 border-t border-border/70 bg-card px-7 py-5 dark:border-white/10">
              <p className="text-xs text-muted-foreground">
                <kbd className="rounded border border-border bg-background px-1 font-mono text-[10px]">Esc</kbd> to close
              </p>
              <Button
                type="button"
                variant="outline"
                className="min-w-24 rounded-lg border-brand/70 bg-card px-6 font-bold text-brand hover:bg-brand/10 hover:text-brand dark:border-brand/55 dark:bg-card"
                onClick={() => onOpenChange(false)}
              >
                Close
              </Button>
            </div>
          </>
        )}
      </DialogContent>
    </Dialog>
  )
}
