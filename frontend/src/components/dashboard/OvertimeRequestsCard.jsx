import { ArrowRight, BriefcaseBusiness, CalendarDays, Clock3, IdCard, Send } from 'lucide-react'
import { Card, CardDescription, CardHeader, CardTitle } from '@/components/ui/card'
import { Button } from '@/components/ui/button'
import { Avatar, AvatarFallback, AvatarImage } from '@/components/ui/avatar'
import { cn } from '@/lib/utils'
import OvertimeStatusBadge from '@/components/overtime/OvertimeStatusBadge'
import {
  DASHBOARD_PENDING_CARD_HEADER_CLASS,
  DASHBOARD_PENDING_CARD_SHELL_CLASS,
  DASHBOARD_PENDING_PREVIEW_LIMIT,
  DASHBOARD_PENDING_SCROLL_BODY_PROPS,
  DASHBOARD_PENDING_SCROLL_CONTENT_CLASS,
  DASHBOARD_PENDING_SCROLL_LIST_CLASS,
} from '@/lib/dashboardPendingCards'

export function OvertimeRequestsCard({
  pendingCount = 0,
  request = null,
  requests = [],
  loading = false,
  onViewAll,
  onReviewRequest,
}) {
  const pendingRequests = (Array.isArray(requests) && requests.length > 0 ? requests : [request].filter(Boolean))
    .filter(isPendingOvertime)
    .slice(0, DASHBOARD_PENDING_PREVIEW_LIMIT)
  const hasPending = pendingRequests.length > 0
  const showEmptyState = !loading && !hasPending

  return (
    <Card className={DASHBOARD_PENDING_CARD_SHELL_CLASS}>
      <CardHeader
        className={cn(DASHBOARD_PENDING_CARD_HEADER_CLASS, 'cursor-pointer')}
        onClick={() => onViewAll?.()}
        onKeyDown={(e) => {
          if (e.key === 'Enter' || e.key === ' ') {
            e.preventDefault()
            onViewAll?.()
          }
        }}
        role="button"
        tabIndex={0}
        aria-label="View all overtime requests"
      >
        <div className="flex min-h-[3.75rem] flex-col gap-2 @sm:flex-row @sm:items-start @sm:justify-between @sm:gap-3">
          <div className="min-w-0 flex-1">
            <CardTitle className="mb-1 flex min-w-0 flex-wrap items-center gap-2 text-base font-extrabold leading-snug tracking-tight text-foreground">
              <span className="flex size-6 shrink-0 items-center justify-center rounded-full border-2 border-brand/80 text-brand">
                <Clock3 className="size-4" aria-hidden />
              </span>
              <span className="min-w-0 wrap-break-word">Overtime Requests</span>
              {hasPending || Number(pendingCount) > 0 ? (
                <span className="inline-flex h-5 min-w-5 items-center justify-center rounded-full bg-brand/10 px-1.5 text-[11px] font-semibold text-brand shadow-[0_0_20px_rgba(255,107,0,0.16)]">
                  {Number(pendingCount) > 0 ? pendingCount : pendingRequests.length}
                </span>
              ) : null}
            </CardTitle>
            <CardDescription className="mt-0 min-h-8 text-[11px] font-normal leading-4 text-muted-foreground line-clamp-2">
              Pending overtime requests from employees.
            </CardDescription>
          </div>

          <Button
            type="button"
            variant="outline"
            size="sm"
            className={cn(
              'h-7 w-full shrink-0 rounded-md border-border/70 bg-background/70 px-2.5 @sm:mt-0 @sm:w-auto',
              'text-xs font-medium',
              'shadow-sm shadow-black/5 hover:bg-accent/55 hover:shadow-black/10',
              'focus-visible:ring-2 focus-visible:ring-ring focus-visible:ring-offset-2 focus-visible:ring-offset-background',
              'transition-[background-color,box-shadow,color] duration-200',
            )}
            onClick={(e) => {
              e.stopPropagation()
              onViewAll?.()
            }}
          >
            View All
            <ArrowRight className="ml-1.5 size-3.5 opacity-70" aria-hidden />
          </Button>
        </div>
      </CardHeader>

      <div
        className={DASHBOARD_PENDING_SCROLL_CONTENT_CLASS}
        {...DASHBOARD_PENDING_SCROLL_BODY_PROPS}
      >
        {loading ? (
          <div className="rounded-2xl border border-border/70 bg-muted/15 p-5 text-sm font-normal leading-[1.55] text-muted-foreground">
            Loading overtime requests...
          </div>
        ) : showEmptyState ? (
          <div className="flex min-h-[172px] flex-col items-center justify-center rounded-lg border border-brand/10 bg-[radial-gradient(circle_at_center,rgba(255,107,0,0.14),rgba(255,107,0,0.04)_58%,transparent)] p-5 text-center dark:border-brand/15">
            <span className="mb-4 flex size-12 items-center justify-center rounded-full border border-brand/25 bg-background text-brand shadow-sm dark:bg-card">
              <Clock3 className="size-6" aria-hidden />
            </span>
            <p className="text-sm font-semibold leading-[1.55] text-foreground">No pending overtime requests.</p>
            <p className="mt-1 text-xs text-muted-foreground">You&apos;re all caught up.</p>
          </div>
        ) : (
          <div className={DASHBOARD_PENDING_SCROLL_LIST_CLASS}>
            {pendingRequests.map((item, index) => (
              <PendingOvertimeItem
                key={item?.id ?? item?.request_id ?? `${item?.employee_id ?? item?.requested_by_id ?? 'employee'}-${item?.date ?? index}`}
                request={item}
                onReviewRequest={onReviewRequest}
              />
            ))}
          </div>
        )}
      </div>
    </Card>
  )
}

function isPendingOvertime(row) {
  return String(row?.status || '').toLowerCase() === 'pending'
}

function PendingOvertimeItem({ request, onReviewRequest }) {
  const employeeName = request?.requested_by_name || request?.employee_name || 'Employee'
  const employeePosition = request?.requested_by_position || request?.position || request?.department || 'Employee'
  const employeeId = request?.employee_id || request?.requested_by_id
  const employeeCode = request?.employee_code || (employeeId ? `EMP-${employeeId}` : 'EMP--')
  const employeeMeta = buildEmployeeMeta(request)
  const avatarSrc = request?.requested_by_profile_image_url || request?.employee_profile_image || undefined
  const startTime = request?.start_time || request?.schedule_end
  const endTime = request?.end_time || request?.expected_end_time || request?.time_out
  const hours = formatHours(request)
  const reason = request?.reason || 'Overtime request'
  const reasonSubtext = request?.remarks || request?.ot_type_label || request?.day_type_label || 'Awaiting review'

  return (
    <article
      className={cn(
        'shrink-0 rounded-lg border border-border/70 bg-background/70 p-2 shadow-sm @sm:p-2.5',
        'transition-[border-color,box-shadow,transform] duration-200 hover:border-brand/25 hover:shadow-md',
      )}
    >
      <div className="flex items-start justify-between gap-2">
        <div className="flex min-w-0 flex-1 items-start gap-2.5">
          <div className="relative shrink-0">
            <Avatar className="size-9 border-2 border-background shadow-md ring-1 ring-border/70">
              <AvatarImage src={avatarSrc} alt="" className="object-cover" />
              <AvatarFallback className="bg-brand/10 text-xs font-bold text-brand">
                {initials(employeeName)}
              </AvatarFallback>
            </Avatar>
            <span className="absolute bottom-0 right-0 size-2.5 rounded-full border-2 border-background bg-brand shadow-sm" />
          </div>
          <div className="min-h-[3.75rem] min-w-0">
            <p className="line-clamp-1 text-sm font-bold tracking-tight text-foreground">{employeeName}</p>
            <p className="line-clamp-1 text-[11px] text-muted-foreground">{employeePosition}</p>
            <p className={cn('line-clamp-1 text-[10px] leading-snug text-muted-foreground/90', !employeeMeta && 'invisible')}>
              {employeeMeta || '\u00a0'}
            </p>
            <span className="mt-1 inline-flex items-center gap-1 rounded-full bg-muted/60 px-1.5 py-0 text-[10px] font-medium text-muted-foreground">
              <IdCard className="size-3" aria-hidden />
              {employeeCode}
            </span>
          </div>
        </div>

        <OvertimeStatusBadge row={request} className="items-end" />
      </div>

      <div className="my-2 h-px bg-border/70" />

      <div className="grid grid-cols-2 gap-2">
        <InfoBlock
          icon={CalendarDays}
          label="Date"
          value={formatDate(request?.date)}
          subvalue={formatWeekday(request?.date)}
        />
        <InfoBlock
          icon={Clock3}
          label="Time"
          value={formatTimeRange(startTime, endTime)}
          subvalue={hours}
        />
        <InfoBlock
          className="col-span-2"
          icon={BriefcaseBusiness}
          label="Reason"
          value={reason}
          subvalue={reasonSubtext}
          clampValue
        />
      </div>

      <div className="mt-2 border-t border-border/70 pt-2">
        <Button
          type="button"
          className="h-8 w-full rounded-lg bg-brand px-3 text-[11px] font-semibold text-brand-foreground shadow-[0_8px_16px_rgba(255,107,0,0.2)] hover:bg-brand-strong"
          onClick={(e) => {
            e.stopPropagation()
            onReviewRequest?.(request)
          }}
        >
          <Send className="mr-1.5 size-3.5" aria-hidden />
          Review Request
        </Button>
      </div>
    </article>
  )
}

function InfoBlock({ icon, label, value, subvalue, className, clampValue = false }) {
  const IconComponent = icon
  return (
    <div className={cn('flex min-w-0 items-start gap-1.5 rounded-lg border border-border/45 bg-muted/15 px-2 py-1.5', className)}>
      <span className="flex size-7 shrink-0 items-center justify-center rounded-md bg-brand/10 text-brand">
        <IconComponent className="size-3.5" aria-hidden />
      </span>
      <div className="min-w-0">
        <p className="text-[10px] font-medium text-muted-foreground">{label}</p>
        <p className={cn('mt-0.5 text-[11px] font-bold text-foreground', clampValue && 'line-clamp-2')}>{value}</p>
        {subvalue ? <p className="mt-0.5 line-clamp-1 text-[10px] text-muted-foreground">{subvalue}</p> : null}
      </div>
    </div>
  )
}

function initials(name) {
  const parts = String(name || '').trim().split(/\s+/).filter(Boolean)
  if (parts.length === 0) return 'OT'
  if (parts.length === 1) return parts[0].slice(0, 2).toUpperCase()
  return `${parts[0][0] ?? ''}${parts[parts.length - 1][0] ?? ''}`.toUpperCase()
}

function formatDate(dateStr) {
  if (!dateStr) return '-'
  const date = new Date(`${String(dateStr).slice(0, 10)}T12:00:00`)
  if (Number.isNaN(date.getTime())) return '-'
  return date.toLocaleDateString('en-PH', { month: 'short', day: 'numeric', year: 'numeric' })
}

function formatWeekday(dateStr) {
  if (!dateStr) return ''
  const date = new Date(`${String(dateStr).slice(0, 10)}T12:00:00`)
  if (Number.isNaN(date.getTime())) return ''
  return date.toLocaleDateString('en-PH', { weekday: 'long' })
}

function formatTimeRange(start, end) {
  const a = formatTime(start)
  const b = formatTime(end)
  if (a === '-' && b === '-') return '-'
  return `${a} - ${b}`
}

function formatTime(value) {
  if (!value) return '-'
  const [hhRaw, mmRaw = '00'] = String(value).split(':')
  const hh = Number(hhRaw)
  const mm = Number(mmRaw)
  if (!Number.isFinite(hh) || !Number.isFinite(mm)) return String(value)
  const date = new Date()
  date.setHours(hh, mm, 0, 0)
  return date.toLocaleTimeString('en-PH', { hour: 'numeric', minute: '2-digit' })
}

function formatHours(row) {
  const raw = row?.computed_hours ?? row?.requested_ot_hours
  const n = Number(raw)
  if (Number.isFinite(n) && n > 0) {
    const hours = Math.floor(n)
    const minutes = Math.round((n - hours) * 60)
    if (hours > 0 && minutes > 0) return `${hours}h ${String(minutes).padStart(2, '0')}m`
    if (hours > 0) return `${hours}h 00m`
    return `${minutes}m`
  }
  return ''
}

function buildEmployeeMeta(row) {
  if (!row || typeof row !== 'object') return ''
  const chunks = [
    row.requested_by_role_label,
    row.department,
    row.branch,
    row.company,
  ]
    .map((value) => String(value || '').trim())
    .filter(Boolean)
  return chunks.join(' | ')
}
