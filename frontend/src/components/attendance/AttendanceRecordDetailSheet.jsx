import { Link } from 'react-router-dom'
import {
  Paperclip,
  FileText,
  Clock,
  LogIn,
  LogOut,
  GitBranch,
  ExternalLink,
} from 'lucide-react'
import {
  Sheet,
  SheetContent,
  SheetHeader,
  SheetTitle,
  SheetDescription,
} from '@/components/ui/sheet'
import { Button } from '@/components/ui/button'
import { Badge } from '@/components/ui/badge'
import { Separator } from '@/components/ui/separator'
import { Avatar, AvatarFallback, AvatarImage } from '@/components/ui/avatar'
import { Popover, PopoverContent, PopoverTrigger } from '@/components/ui/popover'
import { cn } from '@/lib/utils'
import {
  attendanceRecordRef,
  formatShortDate,
  formatTimeHhMm,
  adminHoursDetailSummary,
  employeeHoursDetailSummary,
  adminTypeReasonLabel,
  employeeTypeReasonLabel,
  resolveAdminStatusLabel,
  resolveEmployeeStatusLabel,
} from '@/components/attendance/attendanceRecordUtils'
import { AttendanceStatusPill } from '@/components/attendance/AttendanceStatusPill'

function TimelineItem({ title, subtitle, done, isLast }) {
  return (
    <div className="flex gap-3">
      <div className="flex flex-col items-center">
        <span
          className={cn(
            'mt-0.5 size-2.5 rounded-full border-2',
            done ? 'border-emerald-500 bg-emerald-500' : 'border-muted-foreground/40 bg-background',
          )}
        />
        {!isLast && <span className="min-h-[2.25rem] w-px flex-1 bg-border" aria-hidden />}
      </div>
      <div className="min-w-0 flex-1 pb-4">
        <p className="text-sm font-medium text-foreground">{title}</p>
        {subtitle && <p className="text-xs text-muted-foreground">{subtitle}</p>}
      </div>
    </div>
  )
}

function RemarksBlock({ text }) {
  if (!text || !String(text).trim()) {
    return <p className="text-sm text-muted-foreground">No remarks on file.</p>
  }
  const s = String(text).trim()
  const long = s.length > 140
  if (!long) {
    return <p className="text-sm leading-relaxed text-foreground">{s}</p>
  }
  return (
    <div>
      <p className="text-sm leading-relaxed text-foreground">{s.slice(0, 140)}…</p>
      <Popover>
        <PopoverTrigger asChild>
          <Button type="button" variant="link" className="h-auto px-0 text-xs font-semibold text-emerald-700 dark:text-emerald-400">
            View full remarks
          </Button>
        </PopoverTrigger>
        <PopoverContent className="max-w-sm text-sm leading-relaxed" align="start">
          {s}
        </PopoverContent>
      </Popover>
    </div>
  )
}

export function AttendanceRecordDetailSheet({
  open,
  onOpenChange,
  mode,
  row,
  profileImageUrl,
  employeeName,
  employeeInitials,
  profileSrc,
  correctionsHref,
  showPayrollColumns,
}) {
  if (!row) return null

  const isAdmin = mode === 'admin'
  const refId = isAdmin
    ? attendanceRecordRef(row.employee_id, row.date)
    : attendanceRecordRef(null, row.date)
  const dateTitle = formatShortDate(row.date)

  const statusLabel = isAdmin ? resolveAdminStatusLabel(row) : resolveEmployeeStatusLabel(row)
  const typeLabel = isAdmin ? adminTypeReasonLabel(row) : employeeTypeReasonLabel(row)
  const duration = isAdmin
    ? adminHoursDetailSummary(row, { showPayroll: !!showPayrollColumns })
    : employeeHoursDetailSummary(row)

  const docCount = isAdmin
    ? row.has_correction
      ? 1
      : 0
    : row.presence_filing
      ? 1
      : 0

  const timelineCorrection = isAdmin && row.has_correction
  const hasThirdStep = Boolean(timelineCorrection || row.presence_filing)
  const approvalNote = isAdmin
    ? row.correction_approved
      ? 'Correction approved'
      : row.correction_remarks
        ? 'Awaiting review or rejected — see remarks'
        : 'Correction on file'
    : row.presence_filing?.status === 'approved'
      ? 'Filing approved'
      : row.presence_filing?.status === 'rejected'
        ? 'Filing rejected'
        : row.presence_filing?.status === 'pending'
          ? 'Pending approval'
          : null

  return (
    <Sheet open={open} onOpenChange={onOpenChange}>
      <SheetContent
        side="right"
        className="flex w-full flex-col border-l bg-background p-0 sm:max-w-xl md:max-w-2xl"
      >
        <div className="flex min-h-0 flex-1 flex-col overflow-hidden">
          <SheetHeader className="shrink-0 border-b border-border/60 px-6 py-5 text-left">
            <SheetTitle className="text-xl font-semibold tracking-tight">Attendance details</SheetTitle>
            <SheetDescription className="text-sm text-muted-foreground">
              {dateTitle} · <span className="font-mono text-xs text-foreground/80">{refId}</span>
            </SheetDescription>
          </SheetHeader>

          <div className="min-h-0 flex-1 overflow-y-auto px-6 py-5">
            <div className="space-y-6">
              <div className="flex flex-wrap items-start justify-between gap-4">
                <div className="flex min-w-0 items-center gap-4">
                  <Avatar className="size-14 shrink-0 ring-2 ring-emerald-500/25 ring-offset-2 ring-offset-background">
                    <AvatarImage
                      src={isAdmin ? profileImageUrl?.(row.profile_image) : profileSrc}
                      alt=""
                      className="object-cover"
                    />
                    <AvatarFallback className="bg-emerald-500/15 text-base font-bold text-emerald-800 dark:text-emerald-200">
                      {isAdmin
                        ? (row.employee_name || '?')
                            .trim()
                            .split(/\s+/)
                            .map((n) => n[0])
                            .join('')
                            .toUpperCase()
                            .slice(0, 2) || '?'
                        : employeeInitials}
                    </AvatarFallback>
                  </Avatar>
                  <div className="min-w-0">
                    <p className="truncate text-lg font-semibold text-foreground">
                      {isAdmin ? row.employee_name : employeeName}
                    </p>
                    {isAdmin && (
                      <p className="text-sm text-muted-foreground">
                        {row.department || 'No department'} · {row.company_name || '—'}
                      </p>
                    )}
                  </div>
                </div>
                <AttendanceStatusPill
                  status={row.status}
                  label={statusLabel}
                  presenceIssue={row.presence_issue}
                />
              </div>

              <div className="grid gap-3 @sm:grid-cols-2">
                <div className="rounded-xl border border-border/60 bg-muted/20 px-4 py-3">
                  <p className="text-[11px] font-medium uppercase tracking-wide text-muted-foreground">
                    Hours breakdown
                  </p>
                  <p className="mt-1 text-sm font-medium leading-snug text-foreground">{duration}</p>
                </div>
                <div className="rounded-xl border border-border/60 bg-muted/20 px-4 py-3">
                  <p className="text-[11px] font-medium uppercase tracking-wide text-muted-foreground">Type</p>
                  <div className="mt-1.5">
                    <Badge variant="secondary" className="font-medium">
                      {typeLabel}
                    </Badge>
                  </div>
                </div>
              </div>

              <div>
                <h3 className="mb-3 flex items-center gap-2 text-sm font-semibold text-foreground">
                  <GitBranch className="size-4 text-emerald-600 dark:text-emerald-400" aria-hidden />
                  Timeline
                </h3>
                <div className="rounded-xl border border-border/50 bg-card/50 p-4">
                  <TimelineItem
                    title={row.time_in ? `Clock in · ${formatTimeHhMm(row.time_in)}` : 'No clock in'}
                    subtitle="First punch of the day"
                    done={!!row.time_in}
                    isLast={false}
                  />
                  <TimelineItem
                    title={
                      row.time_out
                        ? `Clock out · ${formatTimeHhMm(row.time_out)}${row.time_out_next_day ? ' (next day)' : ''}`
                        : row.virtual_time_out_from_ot
                          ? `Expected end from approved OT${row.approved_ot_end_time ? ` · ${row.approved_ot_end_time}` : ''} (no punch-out)`
                          : row.has_approved_overtime && row.approved_ot_end_time
                            ? `Approved OT until ${row.approved_ot_end_time} (awaiting clock-out)`
                            : 'No clock out'
                    }
                    subtitle={
                      row.has_approved_overtime && row.approved_ot_end_time
                        ? `Clock-out extended to ${row.approved_ot_end_time} (regular shift ends at ${row.schedule_out || '—'})`
                        : row.virtual_time_out_from_ot
                          ? 'Virtual clock-out reference for premium calculations'
                          : 'Closing punch'
                    }
                    done={!!row.time_out || !!row.virtual_time_out_from_ot}
                    isLast={!hasThirdStep}
                  />
                  {hasThirdStep && (
                    <TimelineItem
                      title={approvalNote || 'Correction / filing'}
                      subtitle={
                        isAdmin && row.correction_id
                          ? `Correction #${row.correction_id}`
                          : row.presence_filing?.reason_code || undefined
                      }
                      done={Boolean(isAdmin ? row.correction_approved : row.presence_filing?.status === 'approved')}
                      isLast
                    />
                  )}
                </div>
              </div>

              <div>
                <h3 className="mb-2 flex items-center gap-2 text-sm font-semibold text-foreground">
                  <Paperclip className="size-4 text-emerald-600 dark:text-emerald-400" aria-hidden />
                  Supporting documents
                </h3>
                {docCount > 0 ? (
                  <div className="flex flex-wrap items-center gap-2">
                    <Badge variant="outline" className="gap-1 font-normal">
                      <FileText className="size-3.5" aria-hidden />
                      {docCount} linked
                    </Badge>
                    <span className="text-xs text-muted-foreground">
                      {isAdmin ? 'Tied to attendance correction when present.' : 'Correction filing metadata on record.'}
                    </span>
                  </div>
                ) : (
                  <p className="text-sm text-muted-foreground">No supporting documents for this row.</p>
                )}
              </div>

              <div>
                <h3 className="mb-2 text-sm font-semibold text-foreground">Remarks</h3>
                <RemarksBlock text={isAdmin ? row.correction_remarks : row.presence_filing?.rejection_note} />
              </div>

              <Separator />

              <div className="flex flex-wrap gap-2">
                {correctionsHref && (
                  <Button variant="outline" size="sm" className="gap-1.5" asChild>
                    <Link to={correctionsHref}>
                      <ExternalLink className="size-3.5" aria-hidden />
                      Open corrections
                    </Link>
                  </Button>
                )}
              </div>
            </div>
          </div>

          <div className="shrink-0 border-t border-border/60 bg-muted/10 px-6 py-4">
            <div className="flex flex-wrap gap-4 text-xs text-muted-foreground">
              <span className="inline-flex items-center gap-1.5">
                <LogIn className="size-3.5 text-emerald-600" aria-hidden />
                In: {row.time_in ? formatTimeHhMm(row.time_in) : '—'}
              </span>
              <span className="inline-flex items-center gap-1.5">
                <LogOut className="size-3.5 text-emerald-600" aria-hidden />
                Out: {row.time_out ? formatTimeHhMm(row.time_out) : '—'}
              </span>
              <span className="inline-flex items-center gap-1.5">
                <Clock className="size-3.5" aria-hidden />
                Record date: {row.date}
              </span>
            </div>
          </div>
        </div>
      </SheetContent>
    </Sheet>
  )
}
