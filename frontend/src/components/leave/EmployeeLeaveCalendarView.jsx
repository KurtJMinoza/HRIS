import { useCallback, useEffect, useMemo, useState } from 'react'
import { Calendar, ChevronLeft, ChevronRight, Loader2, Trash2 } from 'lucide-react'
import { Button } from '@/components/ui/button'
import { Avatar, AvatarFallback, AvatarImage } from '@/components/ui/avatar'
import { cn } from '@/lib/utils'
import { profileImageUrl } from '@/api'
import {
  buildMonthCalendarCells,
  CALENDAR_MONTHS,
  CALENDAR_WEEKDAYS,
} from '@/lib/monthCalendarGrid'

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
  return map[t] || (type ? String(type).replace(/_/g, ' ') : 'Leave')
}

function leaveDurationLabel(leave) {
  if (!leave) return ''
  const type = String(leave.type || '').toLowerCase()
  if (type === 'half_day') {
    if (leave.half_type === 'am') return 'Half day · AM'
    if (leave.half_type === 'pm') return 'Half day · PM'
    return 'Half day'
  }
  if (type === 'undertime') {
    const minutes = typeof leave.undertime_minutes === 'number' ? leave.undertime_minutes : null
    return minutes !== null ? `${minutes} min undertime` : 'Undertime'
  }
  if (leave.start_date && leave.end_date && leave.start_date !== leave.end_date) {
    return `${leave.start_date} → ${leave.end_date}`
  }
  return leaveTypeLabel(leave.type)
}

const LEAVE_STATUS_META = {
  pending: {
    legendDot: 'bg-amber-500',
    badge: 'bg-amber-500 text-white',
    shortLabel: 'Pending',
    text: 'text-amber-700 dark:text-amber-300',
  },
  approved: {
    legendDot: 'bg-emerald-500',
    badge: 'bg-emerald-600 text-white',
    shortLabel: 'Approved',
    text: 'text-emerald-700 dark:text-emerald-300',
  },
  rejected: {
    legendDot: 'bg-rose-500',
    badge: 'bg-rose-600 text-white',
    shortLabel: 'Rejected',
    text: 'text-rose-700 dark:text-rose-300',
  },
}

function normalizeLeaveStatus(status) {
  return String(status ?? '').trim().toLowerCase()
}

function personInitials(name) {
  return (
    String(name || '')
      .trim()
      .split(/\s+/)
      .map((part) => part[0])
      .join('')
      .toUpperCase()
      .slice(0, 2) || '?'
  )
}

export function resolveLeavePerson(leave, viewerProfile) {
  const name =
    leave?.employee_name ||
    leave?.requested_by_name ||
    viewerProfile?.name ||
    'Employee'
  const avatarRaw =
    leave?.employee_profile_image ||
    leave?.requested_by_profile_image_url ||
    viewerProfile?.profileImage ||
    ''
  return {
    name,
    avatar: avatarRaw ? profileImageUrl(avatarRaw) : null,
    position: leave?.requested_by_position || leave?.employee_position || viewerProfile?.position || '',
  }
}

export function pickPrimaryLeave(leaves) {
  if (!Array.isArray(leaves) || leaves.length === 0) return null
  const order = ['pending', 'approved', 'rejected']
  for (const status of order) {
    const match = leaves.find((leave) => normalizeLeaveStatus(leave.status) === status)
    if (match) return match
  }
  return leaves[0]
}

function sortLeavesForDisplay(leaves) {
  const order = { pending: 0, approved: 1, rejected: 2 }
  return [...leaves].sort((a, b) => {
    const sa = order[normalizeLeaveStatus(a.status)] ?? 9
    const sb = order[normalizeLeaveStatus(b.status)] ?? 9
    if (sa !== sb) return sa - sb
    const na = resolveLeavePerson(a).name
    const nb = resolveLeavePerson(b).name
    return na.localeCompare(nb)
  })
}

export function buildLeaveDateMap(leaves) {
  const map = new Map()
  if (!Array.isArray(leaves)) return map

  for (const leave of leaves) {
    if (!leave?.start_date || !leave?.end_date) continue
    const start = new Date(`${leave.start_date}T12:00:00`)
    const end = new Date(`${leave.end_date}T12:00:00`)
    if (Number.isNaN(start.getTime()) || Number.isNaN(end.getTime())) continue

    const cursor = new Date(start)
    while (cursor <= end) {
      const y = cursor.getFullYear()
      const m = String(cursor.getMonth() + 1).padStart(2, '0')
      const d = String(cursor.getDate()).padStart(2, '0')
      const key = `${y}-${m}-${d}`
      const bucket = map.get(key)
      if (bucket) bucket.push(leave)
      else map.set(key, [leave])
      cursor.setDate(cursor.getDate() + 1)
    }
  }
  return map
}

function LeaveCalendarEntry({ leave, showEmployeeDetails, viewerProfile, onOpenLeave, onDeleteLeave }) {
  const status = normalizeLeaveStatus(leave.status)
  const meta = LEAVE_STATUS_META[status]
  const person = resolveLeavePerson(leave, viewerProfile)
  const displayStatus = leave.display_status || meta?.shortLabel || status
  const detailLine = leaveDurationLabel(leave)
  const canDelete = Boolean(leave?.actor_can_delete && onDeleteLeave)

  return (
    <div className="flex w-full min-w-0 items-stretch gap-0.5">
    <button
      type="button"
      onClick={(event) => {
        event.stopPropagation()
        onOpenLeave?.(leave)
      }}
      className={cn(
        'flex min-w-0 flex-1 items-start gap-1.5 rounded-lg border border-slate-200/90 bg-white/95 px-1.5 py-1 text-left shadow-sm transition-colors',
        'hover:border-brand/35 hover:bg-brand/5 focus:outline-none focus-visible:ring-2 focus-visible:ring-brand/35 dark:border-white/10 dark:bg-slate-900/75 dark:hover:bg-brand/10 @sm:gap-2 @sm:px-2 @sm:py-1.5',
      )}
      title={`${person.name} — ${leaveTypeLabel(leave.type)} (${displayStatus})`}
    >
      {showEmployeeDetails ? (
        <Avatar className="size-5 shrink-0 rounded-full @sm:size-6">
          <AvatarImage src={person.avatar || undefined} alt="" className="object-cover" />
          <AvatarFallback className="bg-muted text-[8px] font-bold @sm:text-[9px]">
            {personInitials(person.name)}
          </AvatarFallback>
        </Avatar>
      ) : (
        <span
          className={cn('mt-0.5 size-2 shrink-0 rounded-full ring-2 ring-white dark:ring-slate-950', meta?.legendDot)}
          aria-hidden
        />
      )}
      <span className="min-w-0 flex-1">
        {showEmployeeDetails ? (
          <span className="block truncate text-[9px] font-black leading-tight text-slate-950 dark:text-white @sm:text-[10px]">
            {person.name}
          </span>
        ) : null}
        <span className="block truncate text-[9px] font-semibold leading-tight text-slate-700 dark:text-slate-200 @sm:text-[10px]">
          {leaveTypeLabel(leave.type)}
        </span>
        <span className="mt-0.5 block truncate text-[8px] font-medium leading-tight text-slate-500 dark:text-slate-400 @sm:text-[9px]">
          {detailLine}
        </span>
        <span className={cn('mt-0.5 block truncate text-[8px] font-bold uppercase tracking-wide @sm:text-[9px]', meta?.text)}>
          {displayStatus}
        </span>
      </span>
    </button>
    {canDelete ? (
      <button
        type="button"
        aria-label={`Delete leave request #${leave.id}`}
        title="Delete leave request"
        className={cn(
          'shrink-0 self-start rounded-md border border-destructive/25 p-1 text-destructive transition-colors',
          'hover:border-destructive/45 hover:bg-destructive/10 focus:outline-none focus-visible:ring-2 focus-visible:ring-destructive/35 @sm:p-1.5',
        )}
        onClick={(event) => {
          event.stopPropagation()
          onDeleteLeave?.(leave)
        }}
      >
        <Trash2 className="size-3 @sm:size-3.5" aria-hidden />
      </button>
    ) : null}
    </div>
  )
}

function LeaveCalendarCell({
  cell,
  todayKey,
  leavesOnDay,
  isRangeAnchor,
  isSelected,
  allowFileLeave,
  showEmployeeDetails,
  viewerProfile,
  onOpenLeave,
  onDeleteLeave,
  onSelect,
}) {
  const { day, dateStr, isAdjacent, month: cellMonth } = cell
  const isToday = dateStr === todayKey
  const hasLeaves = leavesOnDay.length > 0
  const sortedLeaves = useMemo(() => sortLeavesForDisplay(leavesOnDay), [leavesOnDay])
  const monthShort = CALENDAR_MONTHS[cellMonth]?.slice(0, 3) ?? ''

  const frameClass = cn(
    'touch-manipulation group relative flex h-full min-h-[4.65rem] w-full min-w-0 max-w-full flex-col rounded-2xl border p-2 text-left @sm:min-h-[6.5rem] @sm:p-2.5',
    'transition-all duration-200 ease-out focus:outline-none focus-visible:ring-[3px] focus-visible:ring-orange-500/35 focus-visible:ring-offset-2 ring-offset-background',
    isAdjacent && !hasLeaves && 'border-border/25 bg-muted/20 text-muted-foreground dark:border-border/25 dark:bg-background/30',
    isAdjacent && hasLeaves && 'border-border/45 bg-card/70 text-foreground opacity-80 dark:bg-card/70',
    !isAdjacent && !hasLeaves && 'border-slate-200 bg-white text-slate-950 shadow-sm dark:border-white/10 dark:bg-slate-950/80 dark:text-white',
    !isAdjacent && hasLeaves && 'border-slate-200 bg-white text-slate-950 shadow-sm ring-1 ring-slate-950/5 dark:border-white/10 dark:bg-slate-950/95 dark:text-white dark:ring-white/10',
    isToday && 'ring-2 ring-orange-500/60 ring-offset-2 ring-offset-background dark:ring-orange-400/55',
    (isSelected || isRangeAnchor) &&
      'z-2 scale-[1.02] border-orange-500 shadow-[0_0_0_3px_rgba(249,115,22,0.18)] dark:shadow-[0_0_0_3px_rgba(251,146,60,0.28)]',
  )

  const dayHeader = (
    <div className="flex items-start justify-between gap-1">
      <span
        className={cn(
          'text-[14px] font-black tabular-nums leading-none tracking-tight @sm:text-base',
          isAdjacent && !hasLeaves && 'text-muted-foreground/80',
        )}
      >
        {isToday || isSelected || isRangeAnchor ? (
          <span className="inline-flex min-w-7 items-center justify-center rounded-lg bg-orange-600 px-1.5 py-1 text-sm font-black text-white shadow-sm ring-1 ring-orange-300/70 @sm:text-base dark:bg-orange-500 dark:text-white">
            {day}
          </span>
        ) : (
          day
        )}
      </span>
      {isAdjacent ? (
        <span className="shrink-0 rounded-md bg-muted/45 px-1 py-0.5 text-[9px] font-black uppercase tracking-wider text-muted-foreground dark:bg-background/35">
          {monthShort}
        </span>
      ) : null}
      {hasLeaves ? (
        <span className="shrink-0 rounded-full bg-muted/60 px-1.5 py-0.5 text-[9px] font-black tabular-nums text-muted-foreground @sm:text-[10px]">
          {leavesOnDay.length}
        </span>
      ) : null}
    </div>
  )

  if (hasLeaves) {
    return (
      <div className={frameClass}>
        {dayHeader}
        <div className="mt-1.5 flex min-h-0 flex-1 flex-col gap-1 overflow-y-auto @sm:mt-2 @sm:gap-1.5">
          {sortedLeaves.map((leave) => (
            <LeaveCalendarEntry
              key={leave.id}
              leave={leave}
              showEmployeeDetails={showEmployeeDetails}
              viewerProfile={viewerProfile}
              onOpenLeave={onOpenLeave}
              onDeleteLeave={onDeleteLeave}
            />
          ))}
        </div>
      </div>
    )
  }

  const tooltip = allowFileLeave ? 'Click to file leave' : 'No leave on this day'

  return (
    <button
      type="button"
      title={tooltip}
      onClick={(event) => onSelect?.(cell, event)}
      className={cn(
        frameClass,
        !isAdjacent && !hasLeaves && 'hover:-translate-y-0.5 hover:border-orange-500/45 hover:bg-white hover:shadow-[0_14px_30px_rgba(15,23,42,0.10)] active:scale-[0.99] dark:hover:bg-slate-950 dark:hover:shadow-none',
      )}
    >
      {dayHeader}
      {isAdjacent ? (
        <div className="mt-1 flex flex-1" aria-hidden />
      ) : allowFileLeave ? (
        <div className="mt-auto flex flex-1 items-end justify-center pb-0.5 opacity-0 transition-opacity group-hover:opacity-100">
          <span className="rounded-md border border-border/60 bg-muted/30 px-2 py-1 text-[10px] font-semibold text-muted-foreground">
            File leave
          </span>
        </div>
      ) : (
        <div className="mt-auto min-h-3 flex-1" aria-hidden />
      )}
    </button>
  )
}

export function EmployeeLeaveCalendarView({
  leaves = [],
  loading = false,
  minLeaveDate,
  allowFileLeave = true,
  showEmployeeDetails = true,
  viewerProfile = null,
  hintText,
  onFileLeave,
  onOpenLeave,
  onDeleteLeave,
  onInvalidDate,
  onVisibleMonthChange,
}) {
  const now = new Date()
  const [year, setYear] = useState(now.getFullYear())
  const [month, setMonth] = useState(now.getMonth())
  const [rangeAnchor, setRangeAnchor] = useState(null)

  const todayKey = useMemo(() => new Date().toISOString().slice(0, 10), [])
  const leaveDateMap = useMemo(() => buildLeaveDateMap(leaves), [leaves])

  const calendarCells = useMemo(
    () => buildMonthCalendarCells(year, month, leaveDateMap),
    [year, month, leaveDateMap],
  )

  const leavesInViewMonth = useMemo(() => {
    const monthPrefix = `${year}-${String(month + 1).padStart(2, '0')}`
    const seen = new Set()
    for (const leave of leaves) {
      if (!leave?.start_date || !leave?.end_date) continue
      if (leave.end_date.slice(0, 7) < monthPrefix || leave.start_date.slice(0, 7) > monthPrefix) continue
      seen.add(leave.id)
    }
    return seen.size
  }, [leaves, year, month])

  useEffect(() => {
    onVisibleMonthChange?.(year, month)
  }, [year, month, onVisibleMonthChange])

  const goPrevMonth = useCallback(() => {
    if (month === 0) {
      setMonth(11)
      setYear((y) => y - 1)
    } else {
      setMonth((m) => m - 1)
    }
    setRangeAnchor(null)
  }, [month])

  const goNextMonth = useCallback(() => {
    if (month === 11) {
      setMonth(0)
      setYear((y) => y + 1)
    } else {
      setMonth((m) => m + 1)
    }
    setRangeAnchor(null)
  }, [month])

  const goToday = useCallback(() => {
    const t = new Date()
    setYear(t.getFullYear())
    setMonth(t.getMonth())
    setRangeAnchor(null)
  }, [])

  const handleCellSelect = useCallback(
    (cell, event) => {
      const leavesOnDay = leaveDateMap.get(cell.dateStr) || []
      if (leavesOnDay.length > 0) return

      if (minLeaveDate && cell.dateStr < minLeaveDate) {
        onInvalidDate?.(cell.dateStr)
        return
      }

      if (!allowFileLeave) return

      if (event?.shiftKey && rangeAnchor && rangeAnchor !== cell.dateStr) {
        const start = rangeAnchor <= cell.dateStr ? rangeAnchor : cell.dateStr
        const end = rangeAnchor <= cell.dateStr ? cell.dateStr : rangeAnchor
        setRangeAnchor(null)
        onFileLeave?.(start, end)
        return
      }

      setRangeAnchor(cell.dateStr)
      onFileLeave?.(cell.dateStr, cell.dateStr)
    },
    [allowFileLeave, leaveDateMap, minLeaveDate, onFileLeave, onInvalidDate, rangeAnchor],
  )

  if (loading) {
    return (
      <div className="flex justify-center py-20">
        <Loader2 className="size-10 animate-spin text-brand" aria-hidden />
      </div>
    )
  }

  return (
    <div className="space-y-4 px-4 pb-5 @sm:px-6 @sm:pb-6">
      <div className="flex w-full min-w-0 items-center justify-center gap-0.5 rounded-2xl border border-border/60 bg-background/70 p-1 dark:bg-background/35">
        <Button
          type="button"
          variant="ghost"
          size="icon"
          className="size-9 shrink-0 rounded-xl hover:bg-brand/8 @sm:size-10"
          onClick={goPrevMonth}
          aria-label="Previous month"
        >
          <ChevronLeft className="size-4 @sm:size-5" />
        </Button>
        <button
          type="button"
          onClick={goToday}
          className="flex min-w-0 flex-1 items-center justify-center gap-2 truncate rounded-xl px-1 py-2 text-center text-sm font-black tracking-tight text-foreground hover:bg-muted/35 dark:hover:bg-muted/20 @sm:px-4 @sm:text-base"
        >
          <Calendar className="size-4 text-muted-foreground" aria-hidden />
          {CALENDAR_MONTHS[month]} {year}
        </button>
        <Button
          type="button"
          variant="ghost"
          size="icon"
          className="size-9 shrink-0 rounded-xl hover:bg-brand/8 @sm:size-10"
          onClick={goNextMonth}
          aria-label="Next month"
        >
          <ChevronRight className="size-4 @sm:size-5" />
        </Button>
      </div>

      <div className="flex flex-col gap-2 @sm:flex-row @sm:items-center @sm:justify-between">
        <p className="text-xs font-semibold text-brand @sm:text-sm">
          <span className="font-black tabular-nums text-brand">{leavesInViewMonth}</span>
          <span className="mx-1">request{leavesInViewMonth === 1 ? '' : 's'}</span>
          <span className="text-muted-foreground/90">in {CALENDAR_MONTHS[month]} {year}</span>
        </p>
        <p className="text-[11px] leading-relaxed text-muted-foreground @sm:text-xs">
          {hintText ??
            (allowFileLeave
              ? 'Tap an open day to file leave. Shift+click a second day to pre-fill a date range. Tap a leave card to view details or delete when allowed.'
              : 'Tap a leave card to view details or delete when allowed.')}
        </p>
      </div>

      <div className="flex flex-wrap gap-3 text-[10px] font-semibold uppercase tracking-wide text-muted-foreground @sm:text-[11px]">
        {Object.entries(LEAVE_STATUS_META).map(([key, meta]) => (
          <span key={key} className="inline-flex items-center gap-1.5">
            <span className={cn('size-2 rounded-full', meta.legendDot)} aria-hidden />
            {meta.shortLabel}
          </span>
        ))}
      </div>

      <div className="w-full min-w-0">
        <div className="grid w-full min-w-0 grid-cols-7 grid-rows-[auto_repeat(6,minmax(4.65rem,auto))] gap-1 @sm:grid-rows-[auto_repeat(6,minmax(6.5rem,auto))] @sm:gap-2">
          {CALENDAR_WEEKDAYS.map((w) => (
            <div
              key={w}
              className="min-w-0 rounded-xl bg-muted/35 px-0.5 py-1.5 text-center text-[8px] font-black uppercase leading-tight tracking-wide text-muted-foreground @sm:px-2 @sm:py-2.5 @sm:text-[11px] @sm:tracking-wider"
            >
              <span className="@sm:hidden">{w.slice(0, 1)}</span>
              <span className="hidden @sm:inline">{w}</span>
            </div>
          ))}
          {calendarCells.map((cell, idx) => {
            const leavesOnDay = leaveDateMap.get(cell.dateStr) || []
            return (
              <div key={`${cell.dateStr}-${idx}`} className="flex min-h-14 min-w-0 @sm:min-h-21">
                <LeaveCalendarCell
                  cell={cell}
                  todayKey={todayKey}
                  leavesOnDay={leavesOnDay}
                  isRangeAnchor={rangeAnchor === cell.dateStr}
                  isSelected={rangeAnchor === cell.dateStr}
                  allowFileLeave={allowFileLeave}
                  showEmployeeDetails={showEmployeeDetails}
                  viewerProfile={viewerProfile}
                  onOpenLeave={onOpenLeave}
                  onDeleteLeave={onDeleteLeave}
                  onSelect={handleCellSelect}
                />
              </div>
            )
          })}
        </div>
      </div>
    </div>
  )
}
