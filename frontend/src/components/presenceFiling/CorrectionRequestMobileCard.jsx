import { CheckCircle2, ChevronRight, Eye, Trash2, XCircle } from 'lucide-react'
import { Link } from 'react-router-dom'
import { Avatar, AvatarFallback, AvatarImage } from '@/components/ui/avatar'
import { Button } from '@/components/ui/button'
import { Checkbox } from '@/components/ui/checkbox'
import {
  IssueTypeCell,
  ReviewStatusTableBadge,
  TimeCell,
} from '@/components/presenceFiling/CorrectionTableCells'
import ApproverAvatarNameCell, { approverFromRequestRow } from '@/components/approvals/ApproverAvatarNameCell'
import { profileImageUrl } from '@/api'
import { cn } from '@/lib/utils'
import { requestModuleCompactButtonClass } from '@/lib/requestModuleTable'
import { remarksUserText } from '@/lib/presenceFilingTable'

function formatDate(iso) {
  if (!iso) return '—'
  try {
    const value = String(iso).includes('T') ? iso : `${iso}T12:00:00`
    return new Date(value).toLocaleDateString('en-PH', {
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

/**
 * Mobile card for attendance corrections — layout matches LeaveRequestMobileCard.
 */
export default function CorrectionRequestMobileCard({
  item,
  employeeProfileTo = null,
  showEmployee = true,
  showBulkCheckbox = false,
  bulkSelection,
  bulkApproving = false,
  showApprove = false,
  showReject = false,
  showDelete = false,
  onView,
  onApprove,
  onReject,
  onDelete,
}) {
  const empName = item?.employee_name || item?.requested_by_name || '—'
  const empImg = item?.employee_profile_image_url || item?.requested_by_profile_image_url
  const tIn = item?.requested_time_in ?? item?.time_in
  const tOut = item?.requested_time_out ?? item?.time_out
  const lastAction = item?.last_action_label ? String(item.last_action_label).trim() : ''
  const canDelete = Boolean(showDelete || item?.actor_can_delete)
  const remarksPreview = remarksUserText(item?.remarks || '')
  const initials =
    empName
      .trim()
      .split(/\s+/)
      .map((n) => n[0])
      .join('')
      .toUpperCase()
      .slice(0, 2) || '?'

  return (
    <div className="space-y-2 rounded-xl border border-border/70 bg-card p-4 shadow-sm dark:border-white/10">
      <div className="flex items-start gap-3">
        {showBulkCheckbox ? (
          <Checkbox
            checked={bulkSelection?.isRowSelected?.(item)}
            disabled={item?.status !== 'pending' || !item?.actor_can_approve || bulkApproving}
            onCheckedChange={() => bulkSelection?.toggleRow?.(item)}
            aria-label={`Select attendance correction #${item?.id}`}
            className="mt-0.5 shrink-0"
          />
        ) : null}
        <button
          type="button"
          onClick={() => onView?.(item)}
          className="min-w-0 flex-1 text-left transition active:scale-[0.99]"
        >
          <div className="flex items-start justify-between gap-3">
            <IssueTypeCell issueType={item?.issue_type} reasonCode={item?.reason_code} />
            <ReviewStatusTableBadge item={item} showApprover={false} />
          </div>

          {showEmployee ? (
            <div className="mt-3 flex min-w-0 items-center gap-3">
              {employeeProfileTo ? (
                <Link
                  to={employeeProfileTo}
                  onClick={(e) => e.stopPropagation()}
                  className="shrink-0 rounded-full outline-none focus-visible:ring-2 focus-visible:ring-brand/40"
                  aria-label={`View profile: ${empName}`}
                >
                  <Avatar className="size-9 rounded-full">
                    <AvatarImage src={empImg ? profileImageUrl(empImg) : undefined} alt="" className="object-cover" />
                    <AvatarFallback className="rounded-full bg-teal-500/20 text-xs font-bold text-teal-700 dark:text-teal-300">
                      {initials}
                    </AvatarFallback>
                  </Avatar>
                </Link>
              ) : (
                <Avatar className="size-9 shrink-0 rounded-full">
                  <AvatarImage src={empImg ? profileImageUrl(empImg) : undefined} alt="" className="object-cover" />
                  <AvatarFallback className="rounded-full bg-teal-500/20 text-xs font-bold text-teal-700 dark:text-teal-300">
                    {initials}
                  </AvatarFallback>
                </Avatar>
              )}
              <span className="line-clamp-2 text-sm font-semibold leading-snug text-foreground" title={empName}>
                {empName}
              </span>
            </div>
          ) : null}

          <div className="mt-3 grid grid-cols-2 gap-x-3 gap-y-2 text-xs">
            <div className="col-span-2">
              <p className="font-semibold uppercase tracking-wide text-muted-foreground">Attendance date</p>
              <p className="mt-0.5 text-sm leading-snug text-foreground">
                {item?.date ? formatDate(item.date) : '—'}
              </p>
            </div>
            <div className="min-w-0">
              <p className="font-semibold uppercase tracking-wide text-muted-foreground">Time in</p>
              <div className="mt-0.5">
                <TimeCell iso={tIn} />
              </div>
            </div>
            <div className="min-w-0">
              <p className="font-semibold uppercase tracking-wide text-muted-foreground">Time out</p>
              <div className="mt-0.5">
                <TimeCell iso={tOut} />
              </div>
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
                <ApproverAvatarNameCell {...approverFromRequestRow(item)} />
              </div>
            </div>
            {lastAction ? (
              <div className="col-span-2">
                <p className="text-xs leading-snug">
                  <span className="font-semibold text-muted-foreground">Last action: </span>
                  <span className="text-foreground">{lastAction}</span>
                </p>
              </div>
            ) : null}
          </div>

          <div className="mt-3 flex items-center justify-between border-t border-border/60 pt-3">
            <span className="text-xs text-muted-foreground">
              Filed {item?.filed_at ? formatDateTime(item.filed_at) : '—'}
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
          onClick={() => onView?.(item)}
        >
          <Eye className="size-3.5" aria-hidden />
          View details
        </Button>
        {canDelete && onDelete ? (
          <Button
            type="button"
            variant="outline"
            size="sm"
            className={cn(requestModuleCompactButtonClass, 'flex-1 border-destructive/40 text-destructive hover:bg-destructive/10')}
            onClick={() => onDelete(item)}
          >
            <Trash2 className="size-3.5" aria-hidden />
            Delete
          </Button>
        ) : null}
        {showApprove ? (
          <Button
            type="button"
            variant="default"
            size="sm"
            className={cn(requestModuleCompactButtonClass, 'flex-1 bg-emerald-600 text-white hover:bg-emerald-700')}
            onClick={() => onApprove?.(item)}
          >
            <CheckCircle2 className="size-3.5" />
            Approve
          </Button>
        ) : null}
        {showReject ? (
          <Button
            type="button"
            variant="outline"
            size="sm"
            className={cn(
              requestModuleCompactButtonClass,
              'flex-1 border-rose-300 text-rose-700 hover:bg-rose-50 dark:border-rose-800 dark:text-rose-300',
            )}
            onClick={() => onReject?.(item)}
          >
            <XCircle className="size-3.5" />
            Reject
          </Button>
        ) : null}
      </div>
    </div>
  )
}
