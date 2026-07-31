import {
  AlertTriangle,
  Briefcase,
  CalendarClock,
  CheckCircle2,
  ChevronRight,
  Clock,
  Eye,
  FileText,
  HeartPulse,
  Loader2,
  Palmtree,
  Paperclip,
  Trash2,
  XCircle,
} from 'lucide-react'
import { Avatar, AvatarFallback, AvatarImage } from '@/components/ui/avatar'
import { Button } from '@/components/ui/button'
import { Checkbox } from '@/components/ui/checkbox'
import LeaveStatusPill from '@/components/leave/LeaveStatusPill'
import LeaveCreditUsageBadge from '@/components/leave/LeaveCreditUsageBadge'
import ApproverAvatarNameCell, { approverFromRequestRow } from '@/components/approvals/ApproverAvatarNameCell'
import { profileImageUrl } from '@/api'
import { cn } from '@/lib/utils'
import { requestModuleCompactButtonClass } from '@/lib/requestModuleTable'

function supportingDocUrls(leave) {
  if (!leave) return []
  if (Array.isArray(leave.document_urls) && leave.document_urls.length) return leave.document_urls
  if (leave.document_url) return [leave.document_url]
  return []
}

function formatDateShort(iso) {
  if (!iso) return '—'
  try {
    return new Date(`${iso}T12:00:00`).toLocaleDateString('en-PH', {
      weekday: 'short',
      month: 'short',
      day: 'numeric',
      year: 'numeric',
    })
  } catch {
    return iso
  }
}

function formatDateTime(iso) {
  if (!iso) return '—'
  try {
    return new Date(iso).toLocaleString('en-PH', { dateStyle: 'medium', timeStyle: 'short' })
  } catch {
    return iso
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

function LeaveTypeBadge({ type }) {
  const t = String(type || '').toLowerCase()
  const map = {
    vacation: 'border-emerald-200/90 bg-gradient-to-br from-emerald-50 to-teal-50 text-emerald-950 dark:text-emerald-50',
    sick: 'border-rose-200/90 bg-gradient-to-br from-rose-50 to-red-50 text-rose-950 dark:text-rose-100',
    emergency: 'border-amber-200/90 bg-gradient-to-br from-amber-50 to-orange-50 text-amber-950 dark:text-amber-50',
    undertime: 'border-sky-200/90 bg-gradient-to-br from-sky-50 to-blue-50 text-sky-950 dark:text-sky-50',
    half_day: 'border-violet-200/90 bg-gradient-to-br from-violet-50 to-purple-50 text-violet-950 dark:text-violet-50',
    other: 'border-slate-200/90 bg-gradient-to-br from-slate-50 to-zinc-50 text-slate-900 dark:text-slate-100',
  }
  const Icon =
    t === 'vacation'
      ? Palmtree
      : t === 'sick'
        ? HeartPulse
        : t === 'emergency'
          ? AlertTriangle
          : t === 'undertime'
            ? Clock
            : t === 'half_day'
              ? CalendarClock
              : Briefcase
  return (
    <span
      className={cn(
        'inline-flex items-center gap-1.5 rounded-lg border px-2.5 py-1 text-xs font-semibold shadow-sm ring-1 ring-black/5 dark:ring-white/10',
        map[t] || map.other,
      )}
    >
      <Icon className="size-3.5 shrink-0 opacity-80" aria-hidden />
      {leaveTypeLabel(type)}
    </span>
  )
}

function durationLabel(leave) {
  if (!leave) return '—'
  const type = String(leave.type || '').toLowerCase()
  if (type === 'undertime') {
    const minutes = typeof leave.undertime_minutes === 'number' ? leave.undertime_minutes : null
    return minutes !== null ? `${minutes} min` : '—'
  }
  if (type === 'half_day') {
    if (leave.half_type === 'am') return 'Half day (AM)'
    if (leave.half_type === 'pm') return 'Half day (PM)'
    return 'Half day'
  }
  if (!leave.start_date || !leave.end_date) return '—'
  try {
    const start = new Date(`${leave.start_date}T12:00:00`)
    const end = new Date(`${leave.end_date}T12:00:00`)
    const days = Math.max(0, Math.round((end - start) / 86400000) + 1)
    if (days === 0.5) return '0.5 day'
    return `${days} day${days === 1 ? '' : 's'}`
  } catch {
    return '—'
  }
}

/**
 * Mobile card for leave requests (admin + employee self-service).
 */
export default function LeaveRequestMobileCard({
  leave,
  onView,
  showEmployee = false,
  showBulkCheckbox = false,
  bulkSelection,
  bulkApproving = false,
  canApprove = false,
  canLeaveNotes = false,
  actionLoadingId = null,
  onApprove,
  onReject,
  onDelete,
  onNotes,
  leaveCreditInfo = null,
}) {
  const pending = String(leave?.status || '').toLowerCase() === 'pending'
  const remarksPreview = [leave?.notes, leave?.rejection_note].filter(Boolean).join('\n\n') || ''
  const docs = supportingDocUrls(leave)
  const employeeName = leave?.employee_name || '—'
  const initials =
    employeeName
      .trim()
      .split(/\s+/)
      .map((n) => n[0])
      .join('')
      .toUpperCase()
      .slice(0, 2) || '?'
  const showApproveActions = canApprove && pending && leave?.actor_can_approve
  const loading = actionLoadingId === leave?.id

  return (
    <div className="space-y-2 rounded-xl border border-border/70 bg-card p-4 shadow-sm dark:border-white/10">
      <div className="flex items-start gap-3">
        {showBulkCheckbox ? (
          <Checkbox
            checked={bulkSelection?.isRowSelected?.(leave)}
            disabled={leave?.status !== 'pending' || !leave?.actor_can_approve || bulkApproving}
            onCheckedChange={() => bulkSelection?.toggleRow?.(leave)}
            aria-label={`Select leave request #${leave?.id}`}
            className="mt-0.5 shrink-0"
          />
        ) : null}
        <button
          type="button"
          onClick={() => onView?.(leave)}
          className="min-w-0 flex-1 text-left transition active:scale-[0.99]"
        >
          <div className="flex items-start justify-between gap-3">
            <LeaveTypeBadge type={leave?.type} />
            <LeaveStatusPill
              status={leave?.status}
              displayStatus={leave?.display_status}
              currentStage={leave?.current_stage}
              currentApproverName={null}
              hrWaitMessage={
                pending && canApprove && !leave?.actor_can_approve
                  ? leave?.hr_wait_message || 'You are not the approver for this request at this stage.'
                  : null
              }
            />
          </div>

          {showEmployee ? (
            <div className="mt-3 flex min-w-0 items-center gap-3">
              <Avatar className="size-9 shrink-0 rounded-full">
                <AvatarImage src={leave?.employee_profile_image} alt="" className="object-cover" />
                <AvatarFallback className="rounded-full bg-teal-500/20 text-xs font-bold text-teal-700 dark:text-teal-300">
                  {initials}
                </AvatarFallback>
              </Avatar>
              <span className="line-clamp-2 text-sm font-semibold leading-snug text-foreground" title={employeeName}>
                {employeeName}
              </span>
            </div>
          ) : null}

          <div className="mt-3 grid grid-cols-2 gap-x-3 gap-y-2 text-xs">
            <div className="col-span-2">
              <p className="font-semibold uppercase tracking-wide text-muted-foreground">Date / range</p>
              <p className="mt-0.5 text-sm leading-snug text-foreground">
                {formatDateShort(leave?.start_date)}
                {leave?.start_date !== leave?.end_date ? ` → ${formatDateShort(leave?.end_date)}` : ''}
              </p>
            </div>
            <div>
              <p className="font-semibold uppercase tracking-wide text-muted-foreground">Duration</p>
              <p className="mt-0.5 text-sm text-foreground">{durationLabel(leave)}</p>
            </div>
            <div>
              <p className="font-semibold uppercase tracking-wide text-muted-foreground">Uses credits</p>
              <div className="mt-0.5">
                <LeaveCreditUsageBadge leave={leave} compact leaveCreditInfo={leaveCreditInfo} />
              </div>
            </div>
            <div>
              <p className="font-semibold uppercase tracking-wide text-muted-foreground">Documents</p>
              <p className="mt-0.5 text-sm text-foreground">
                {docs.length === 0 ? (
                  <span className="text-muted-foreground">No</span>
                ) : (
                  <span className="inline-flex flex-wrap gap-x-2 gap-y-0.5">
                    {docs.map((url, i) => (
                      <a
                        key={`${url}-${i}`}
                        href={profileImageUrl(url)}
                        target="_blank"
                        rel="noopener noreferrer"
                        onClick={(e) => e.stopPropagation()}
                        className="inline-flex items-center gap-1 font-medium text-brand underline-offset-2 hover:underline"
                      >
                        <Paperclip className="size-3.5 shrink-0" aria-hidden />
                        View{docs.length > 1 ? ` ${i + 1}` : ''}
                      </a>
                    ))}
                  </span>
                )}
              </p>
            </div>
            {remarksPreview ? (
              <div className="col-span-2">
                <p className="font-semibold uppercase tracking-wide text-muted-foreground">Reason / remarks</p>
                <p className="mt-0.5 line-clamp-2 text-sm leading-snug text-foreground/90">{remarksPreview}</p>
              </div>
            ) : null}
            <div className="col-span-2">
              <p className="font-semibold uppercase tracking-wide text-muted-foreground">Approver</p>
              <div className="mt-1">
                <ApproverAvatarNameCell {...approverFromRequestRow(leave)} />
              </div>
            </div>
          </div>

          <div className="mt-3 flex items-center justify-between border-t border-border/60 pt-3">
            <span className="text-xs text-muted-foreground">
              Filed {leave?.created_at ? formatDateTime(leave.created_at) : '—'}
            </span>
            <ChevronRight className="size-5 shrink-0 text-muted-foreground" aria-hidden />
          </div>
        </button>
      </div>

      <div className="flex flex-wrap gap-2">
        <Button
          type="button"
          variant="outline"
          size="sm"
          className={cn(requestModuleCompactButtonClass, 'flex-1 border-border/80 bg-card hover:bg-brand/10 hover:text-brand')}
          onClick={() => onView?.(leave)}
        >
          <Eye className="size-3.5" aria-hidden />
          View details
        </Button>
        {leave?.actor_can_delete && onDelete ? (
          <Button
            type="button"
            variant="outline"
            size="sm"
            className={cn(requestModuleCompactButtonClass, 'flex-1 border-destructive/40 text-destructive hover:bg-destructive/10')}
            onClick={() => onDelete(leave)}
          >
            <Trash2 className="size-3.5" aria-hidden />
            Delete
          </Button>
        ) : null}
        {showApproveActions ? (
          <>
            <Button
              type="button"
              variant="default"
              size="sm"
              className={cn(requestModuleCompactButtonClass, 'flex-1 bg-emerald-600 text-white hover:bg-emerald-700')}
              onClick={() => onApprove?.(leave)}
              disabled={loading}
            >
              {loading ? <Loader2 className="size-3.5 animate-spin" /> : <CheckCircle2 className="size-3.5" />}
              Approve
            </Button>
            <Button
              type="button"
              variant="outline"
              size="sm"
              className={cn(
                requestModuleCompactButtonClass,
                'flex-1 border-rose-300 text-rose-700 hover:bg-rose-50 dark:border-rose-800 dark:text-rose-300',
              )}
              onClick={() => onReject?.(leave)}
              disabled={loading || leave?.actor_can_reject === false}
            >
              <XCircle className="size-3.5" />
              Reject
            </Button>
          </>
        ) : null}
        {canLeaveNotes && onNotes ? (
          <Button
            type="button"
            variant="ghost"
            size="sm"
            className={cn(requestModuleCompactButtonClass, 'flex-1')}
            onClick={() => onNotes(leave)}
          >
            <FileText className="size-3.5" />
            {leave?.notes ? 'Edit note' : 'Add note'}
          </Button>
        ) : null}
      </div>
    </div>
  )
}
