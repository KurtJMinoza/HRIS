import { ArrowRight, BriefcaseBusiness, CalendarDays, Clock3, IdCard, Send } from 'lucide-react'
import { Card, CardContent, CardDescription, CardHeader, CardTitle } from '@/components/ui/card'
import { Button } from '@/components/ui/button'
import { Avatar, AvatarFallback, AvatarImage } from '@/components/ui/avatar'
import { cn } from '@/lib/utils'

export function OvertimeRequestsCard({
  pendingCount = 0,
  request = null,
  loading = false,
  onViewAll,
  onReviewRequest,
}) {
  const hasPending = Number(pendingCount) > 0
  const activeRequest = hasPending ? request : null
  const employeeName = activeRequest?.requested_by_name || activeRequest?.employee_name || 'Employee'
  const employeePosition = activeRequest?.requested_by_position || activeRequest?.position || activeRequest?.department || 'Employee'
  const employeeId = activeRequest?.employee_id || activeRequest?.requested_by_id
  const employeeCode = activeRequest?.employee_code || (employeeId ? `EMP-${employeeId}` : 'EMP-—')
  const employeeMeta = buildEmployeeMeta(activeRequest)
  const avatarSrc = activeRequest?.requested_by_profile_image_url || activeRequest?.employee_profile_image || undefined
  const startTime = activeRequest?.start_time || activeRequest?.schedule_end
  const endTime = activeRequest?.end_time || activeRequest?.expected_end_time || activeRequest?.time_out
  const hours = formatHours(activeRequest)
  const reason = activeRequest?.reason || 'Overtime request'
  const reasonSubtext = activeRequest?.remarks || activeRequest?.ot_type_label || activeRequest?.day_type_label || 'Awaiting review'

  return (
    <Card
      role="button"
      tabIndex={0}
      onClick={onViewAll}
      onKeyDown={(e) => {
        if (e.key === 'Enter' || e.key === ' ') {
          e.preventDefault()
          onViewAll?.()
        }
      }}
      className={cn(
        'admin-dashboard-card h-[400px] max-h-[400px] min-h-[400px] gap-0 overflow-hidden py-0 transition-[transform,box-shadow] duration-300 hover:-translate-y-px @xl:h-[420px] @xl:max-h-[420px] @xl:min-h-[420px]',
        'cursor-pointer focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-ring focus-visible:ring-offset-2 focus-visible:ring-offset-background',
      )}
    >
      <CardHeader className="px-4 pb-3 pt-4 @sm:px-5 @md:px-6 @md:pt-5">
        <div className="flex flex-col gap-2.5 @sm:flex-row @sm:items-start @sm:justify-between @sm:gap-4">
          <div className="min-w-0">
            <CardTitle className="mb-2.5 flex min-w-0 flex-wrap items-center gap-2.5 text-base font-extrabold leading-snug tracking-tight text-foreground">
              <span className="flex size-7 shrink-0 items-center justify-center rounded-full border-2 border-brand/80 text-brand">
                <Clock3 className="size-4.5" aria-hidden />
              </span>
              <span className="min-w-0 wrap-break-word">Overtime Requests</span>
              {hasPending ? (
                <span className="inline-flex h-5 min-w-5 items-center justify-center rounded-full bg-brand/10 px-1.5 text-[11px] font-semibold text-brand shadow-[0_0_20px_rgba(255,107,0,0.16)]">
                  {pendingCount}
                </span>
              ) : null}
            </CardTitle>
            <CardDescription className="mt-0 text-xs font-normal leading-relaxed text-muted-foreground">
              Pending overtime requests from employees.
            </CardDescription>
          </div>

          <Button
            type="button"
            variant="outline"
            size="sm"
            className={cn(
              'h-8 w-full shrink-0 rounded-md border-border/70 bg-background/70 px-3 @sm:mt-1 @sm:w-auto',
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

      <CardContent className="flex min-h-0 flex-1 flex-col gap-3 overflow-y-auto overscroll-contain px-4 pb-4 pt-0 pr-3 @sm:px-5 @sm:pr-4 @md:px-6">
        {loading ? (
          <div className="rounded-2xl border border-border/70 bg-muted/15 p-5 text-sm font-normal leading-[1.55] text-muted-foreground">
            Loading overtime requests...
          </div>
        ) : !hasPending ? (
          <div className="flex min-h-[172px] flex-col items-center justify-center rounded-lg border border-brand/10 bg-[radial-gradient(circle_at_center,rgba(255,107,0,0.14),rgba(255,107,0,0.04)_58%,transparent)] p-5 text-center dark:border-brand/15">
            <span className="mb-4 flex size-12 items-center justify-center rounded-full border border-brand/25 bg-background text-brand shadow-sm dark:bg-card">
              <Clock3 className="size-6" aria-hidden />
            </span>
            <p className="text-sm font-semibold leading-[1.55] text-foreground">No pending overtime requests.</p>
            <p className="mt-1 text-xs text-muted-foreground">You&apos;re all caught up.</p>
          </div>
        ) : (
          <article
            className={cn(
              'rounded-lg border border-border/70 bg-background/70 p-2.5 shadow-sm @sm:p-3',
              'transition-[border-color,box-shadow,transform] duration-200 hover:border-brand/25 hover:shadow-md',
            )}
          >
            <div className="flex flex-col gap-2.5 @sm:flex-row @sm:items-start @sm:justify-between">
              <div className="flex min-w-0 items-start gap-3">
                <div className="relative shrink-0">
                  <Avatar className="size-10 border-2 border-background shadow-md ring-1 ring-border/70 @md:size-11">
                    <AvatarImage src={avatarSrc} alt="" className="object-cover" />
                    <AvatarFallback className="bg-brand/10 text-sm font-bold text-brand">
                      {initials(employeeName)}
                    </AvatarFallback>
                  </Avatar>
                  <span className="absolute bottom-0.5 right-0.5 size-3 rounded-full border-2 border-background bg-brand shadow-sm" />
                </div>
                <div className="min-w-0 pt-0.5">
                  <p className="wrap-break-word text-sm font-bold tracking-tight text-foreground">{employeeName}</p>
                  <p className="mt-0.5 wrap-break-word text-xs text-muted-foreground">{employeePosition}</p>
                  {employeeMeta ? (
                    <p className="mt-0.5 wrap-break-word text-[11px] leading-relaxed text-muted-foreground/90">{employeeMeta}</p>
                  ) : null}
                  <span className="mt-1.5 inline-flex items-center gap-1.5 rounded-full bg-muted/60 px-2 py-0.5 text-[11px] font-medium text-muted-foreground">
                    <IdCard className="size-3.5" aria-hidden />
                    {employeeCode}
                  </span>
                </div>
              </div>

              <span className="inline-flex w-fit items-center gap-1 rounded-full bg-brand/10 px-2.5 py-1 text-[11px] font-semibold text-brand">
                <Clock3 className="size-3" aria-hidden />
                Pending
              </span>
            </div>

            <div className="my-3 h-px bg-border/70 @md:my-3.5" />

            <div className="grid grid-cols-[repeat(auto-fit,minmax(7.5rem,1fr))] gap-2.5">
              <InfoBlock
                icon={CalendarDays}
                label="Date"
                value={formatDate(activeRequest?.date)}
                subvalue={formatWeekday(activeRequest?.date)}
              />
              <InfoBlock
                icon={Clock3}
                label="Time"
                value={formatTimeRange(startTime, endTime)}
                subvalue={hours}
              />
              <InfoBlock
                icon={BriefcaseBusiness}
                label="Reason"
                value={reason}
                subvalue={reasonSubtext}
              />
            </div>

            <div className="mt-3 border-t border-border/70 pt-3">
              <Button
                type="button"
                className="h-9 w-full rounded-lg bg-brand px-4 text-xs font-semibold text-brand-foreground shadow-[0_10px_20px_rgba(255,107,0,0.24)] hover:bg-brand-strong"
                onClick={(e) => {
                  e.stopPropagation()
                  onReviewRequest?.(activeRequest)
                }}
              >
                <Send className="mr-2 size-4" aria-hidden />
                Review Request
              </Button>
            </div>
          </article>
        )}
      </CardContent>
    </Card>
  )
}

function InfoBlock({ icon, label, value, subvalue }) {
  const IconComponent = icon
  return (
    <div className="flex min-w-0 items-start gap-2 rounded-lg border border-border/45 bg-muted/15 px-2.5 py-2">
      <span className="flex size-8 shrink-0 items-center justify-center rounded-lg bg-brand/10 text-brand @md:size-9">
        <IconComponent className="size-3.5 @md:size-4" aria-hidden />
      </span>
      <div className="min-w-0">
        <p className="text-[11px] font-medium text-muted-foreground">{label}</p>
        <p className="mt-0.5 wrap-break-word text-xs font-bold text-foreground">{value}</p>
        {subvalue ? <p className="mt-0.5 wrap-break-word text-[11px] text-muted-foreground">{subvalue}</p> : null}
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
  if (!dateStr) return '—'
  const date = new Date(`${String(dateStr).slice(0, 10)}T12:00:00`)
  if (Number.isNaN(date.getTime())) return '—'
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
  if (a === '—' && b === '—') return '—'
  return `${a} - ${b}`
}

function formatTime(value) {
  if (!value) return '—'
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
  return chunks.join(' • ')
}
