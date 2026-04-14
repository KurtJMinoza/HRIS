import { useState, useMemo, useEffect, useCallback } from 'react'
import { motion as Motion } from 'framer-motion'
import {
  Calendar,
  CalendarDays,
  ChevronLeft,
  ChevronRight,
  Clock,
  List,
  Pencil,
  Plus,
  RotateCcw,
  Search,
  Trash2,
  Users,
  X,
  Zap,
  SlidersHorizontal,
} from 'lucide-react'
import { toast } from 'sonner'
import { Button } from '@/components/ui/button'
import { Card, CardContent, CardHeader, CardTitle, CardDescription } from '@/components/ui/card'
import { Badge } from '@/components/ui/badge'
import { Input } from '@/components/ui/input'
import { Sheet, SheetContent } from '@/components/ui/sheet'
import { Popover, PopoverContent, PopoverTrigger } from '@/components/ui/popover'
import { useAuth } from '@/contexts/AuthContext'
import { cn } from '@/lib/utils'
import { AnimatedSection } from '@/components/ui/AnimatedSection'
import { getAdminHolidays, deleteAdminHoliday } from '@/api'
import { HolidayFormModal } from '@/components/holidays/HolidayFormModal'

const MONTHS = ['January', 'February', 'March', 'April', 'May', 'June', 'July', 'August', 'September', 'October', 'November', 'December']
const WEEKDAYS = ['SUN', 'MON', 'TUE', 'WED', 'THU', 'FRI', 'SAT']

const TYPES = [
  { value: 'regular', label: 'Regular Holiday' },
  { value: 'special', label: 'Special Non-Working' },
  { value: 'special_working', label: 'Special Working' },
  { value: 'company', label: 'Company Event' },
]
/** Visual + copy for each holiday type — full-cell tint, multiplier badge, tooltips. */
const HOLIDAY_TYPE_META = {
  regular: {
    shortLabel: 'Regular',
    multiplier: '2.00×',
    legendDot: 'bg-teal-600',
    cell:
      'border-teal-700/45 bg-teal-600/[0.14] text-teal-950 shadow-sm dark:border-teal-500/40 dark:bg-teal-950/55 dark:text-teal-50 dark:shadow-none',
    cellAdjacent:
      'border-teal-700/25 bg-teal-600/[0.08] text-teal-900/90 opacity-[0.88] dark:border-teal-600/25 dark:bg-teal-950/40 dark:text-teal-100/90',
    badge: 'bg-teal-700 text-white shadow-sm dark:bg-teal-600',
    typePill: 'bg-teal-800/15 text-[10px] font-bold uppercase tracking-wide text-teal-900 dark:bg-teal-400/15 dark:text-teal-100',
    title:
      'Regular holiday — 200% daily rate if worked (ordinary day). Higher rates apply on rest day or overtime per DOLE.',
  },
  special: {
    shortLabel: 'Special',
    multiplier: '1.30×',
    legendDot: 'bg-amber-600',
    cell:
      'border-amber-700/50 bg-amber-500/[0.17] text-amber-950 shadow-sm dark:border-amber-500/45 dark:bg-amber-950/50 dark:text-amber-50 dark:shadow-none',
    cellAdjacent:
      'border-amber-700/28 bg-amber-500/[0.10] text-amber-950/90 opacity-[0.88] dark:border-amber-600/30 dark:bg-amber-950/38 dark:text-amber-50/95',
    badge: 'bg-amber-600 text-white shadow-sm dark:bg-amber-500',
    typePill: 'bg-amber-800/15 text-[10px] font-bold uppercase tracking-wide text-amber-950 dark:bg-amber-400/15 dark:text-amber-100',
    title:
      'Special non-working day — 130% if worked; typically no pay if unworked (check monthly vs daily rules).',
  },
  special_working: {
    shortLabel: 'Sp. Work',
    multiplier: '1.00×',
    legendDot: 'bg-slate-500',
    cell:
      'border-slate-600/35 bg-slate-500/[0.10] text-slate-950 shadow-sm dark:border-slate-500/35 dark:bg-slate-950/45 dark:text-slate-50 dark:shadow-none',
    cellAdjacent:
      'border-slate-600/20 bg-slate-500/[0.06] text-slate-900/90 opacity-[0.88] dark:border-slate-600/20 dark:bg-slate-950/30 dark:text-slate-100/95',
    badge: 'bg-slate-600 text-white shadow-sm dark:bg-slate-500',
    typePill: 'bg-slate-800/15 text-[10px] font-bold uppercase tracking-wide text-slate-900 dark:bg-slate-400/15 dark:text-slate-100',
    title:
      'Special working day — pay as ordinary day (no statutory holiday premium) unless policy/CBA adds benefits.',
  },
  company: {
    shortLabel: 'Company',
    multiplier: '—',
    legendDot: 'bg-violet-600',
    cell:
      'border-violet-600/40 bg-violet-500/[0.11] text-violet-950 shadow-sm dark:border-violet-500/40 dark:bg-violet-950/45 dark:text-violet-50 dark:shadow-none',
    cellAdjacent:
      'border-violet-600/22 bg-violet-500/[0.07] text-violet-950/90 opacity-[0.88] dark:border-violet-600/25 dark:bg-violet-950/32 dark:text-violet-100/95',
    badge: 'bg-violet-700 text-white shadow-sm dark:bg-violet-600',
    typePill: 'bg-violet-800/15 text-[10px] font-bold uppercase tracking-wide text-violet-900 dark:bg-violet-400/15 dark:text-violet-100',
    title: 'Company / custom event — no default statutory premium; follow your payroll policy.',
  },
}

const HOLIDAY_DESCRIPTIONS = {
  "New Year's Day": "Celebrates the first day of the year. Employees may receive holiday premium pay depending on work conditions per DOLE guidelines.",
  "Eid'l Fitr": "Religious holiday marking the end of Ramadan. Employees may receive holiday premium pay depending on work conditions.",
  "Maundy Thursday": "Christian observance during Holy Week. Regular holiday—employees receive 200% if worked, 100% if not.",
  "Good Friday": "Christian observance during Holy Week. Regular holiday—employees receive 200% if worked, 100% if not.",
  "Araw ng Kagitingan": "Philippine holiday commemorating the Fall of Bataan. Regular holiday with DOLE-mandated pay rates.",
  "Labor Day": "International Workers' Day. Celebrates labor and workers' rights with standard holiday premium.",
  "Eid'l Adha": "Islamic holiday of sacrifice. Regular holiday with premium pay rates for employees who work.",
  "Independence Day": "Philippine Independence Day. Regular holiday with 200% rate if worked.",
  "National Heroes Day": "Honors Philippine national heroes. Regular holiday with DOLE pay rules.",
  "Bonifacio Day": "Birthday of Andres Bonifacio. Regular holiday with 200% if worked.",
  "Christmas Day": "Christian holiday. Regular holiday with full premium pay rates.",
  "Rizal Day": "Commemorates Jose Rizal. Regular holiday per DOLE guidelines.",
  "Chinese New Year": "Lunar New Year celebration. Special non-working day—130% if worked, no pay if not.",
  "Black Saturday": "Day between Good Friday and Easter. Special non-working day.",
  "Ninoy Aquino Day": "Commemorates Benigno Aquino Jr. Special non-working day.",
  "All Saints' Day": "Catholic observance. Special non-working day—130% if worked.",
  "All Souls' Day": "Catholic observance for the dead. Special non-working day.",
  "Feast of the Immaculate Conception": "Catholic feast day. Special non-working day.",
  "Christmas Eve": "Evening before Christmas. Special non-working day.",
  "Last Day of the Year": "New Year's Eve. Special non-working day.",
}

function HolidayLegendBar() {
  const items = [
    { key: 'regular', meta: HOLIDAY_TYPE_META.regular },
    { key: 'special', meta: HOLIDAY_TYPE_META.special },
    { key: 'special_working', meta: HOLIDAY_TYPE_META.special_working },
    { key: 'company', meta: HOLIDAY_TYPE_META.company },
  ]
  return (
    <div
      className="max-w-full overflow-hidden rounded-xl border border-border/60 bg-card px-3 py-3 shadow-sm dark:border-border/50 dark:bg-card/80 @sm:px-4 @sm:py-3.5"
      role="region"
      aria-label="Holiday type legend"
    >
      <div className="flex flex-col gap-2.5 @sm:flex-row @sm:flex-wrap @sm:items-center @sm:gap-x-8 @sm:gap-y-2">
        <p className="shrink-0 text-[10px] font-bold uppercase tracking-[0.14em] text-muted-foreground @sm:text-[11px]">
          Payroll legend
        </p>
        <ul className="flex min-w-0 flex-wrap gap-x-4 gap-y-2 @sm:gap-x-6">
          {items.map(({ key, meta }) => (
            <li key={key} className="flex min-w-0 items-center gap-2 text-xs @sm:gap-2.5 @sm:text-sm">
              <span
                className={cn(
                  'size-3 shrink-0 rounded-full ring-2 ring-background ring-offset-2 ring-offset-background @sm:size-3.5',
                  meta.legendDot
                )}
              />
              <span className="font-semibold text-foreground">{meta.shortLabel}</span>
              <span className="text-muted-foreground">·</span>
              <span className="font-mono text-xs font-bold tabular-nums text-foreground @sm:text-sm">
                {meta.multiplier === '—' ? 'Policy' : meta.multiplier}
              </span>
              <span className="hidden text-xs text-muted-foreground @sm:inline">
                {key === 'regular' && '(RH)'}
                {key === 'special' && '(SNW)'}
                {key === 'special_working' && '(SWD)'}
                {key === 'company' && '(custom)'}
              </span>
            </li>
          ))}
        </ul>
      </div>
    </div>
  )
}

function HolidayInsightPanel({ nextHoliday, daysUntilNextHoliday, holidaysInNext30, onViewList }) {
  return (
    <div className="max-w-full overflow-hidden rounded-2xl border border-border/60 bg-card p-4 shadow-md ring-1 ring-border/40 dark:border-border/50 dark:bg-card/90 dark:ring-border/30 md:p-5">
      <div className="space-y-5">
        <div>
          <div className="flex flex-wrap items-center gap-2">
            <span className="inline-flex items-center rounded-full bg-primary/10 px-2.5 py-0.5 text-[11px] font-semibold uppercase tracking-wider text-primary">
              Next holiday
            </span>
            {nextHoliday && daysUntilNextHoliday != null && (
              <span
                className={cn(
                  'inline-flex items-center gap-1 rounded-full border px-2.5 py-0.5 text-[11px] font-semibold tabular-nums',
                  daysUntilNextHoliday === 0
                    ? 'border-emerald-500/40 bg-emerald-500/10 text-emerald-800 dark:text-emerald-300'
                    : 'border-border/60 bg-muted/50 text-muted-foreground dark:bg-muted/30'
                )}
              >
                <Clock className="size-3 shrink-0 opacity-70" aria-hidden />
                {daysUntilNextHoliday === 0 ? 'Today' : `${daysUntilNextHoliday} day${daysUntilNextHoliday === 1 ? '' : 's'}`}
              </span>
            )}
          </div>
          {nextHoliday ? (
            <>
              <h3 className="mt-3 break-words text-lg font-bold tracking-tight text-foreground">{nextHoliday.name}</h3>
              <p className="mt-1 text-sm text-muted-foreground">
                {new Date(nextHoliday.date).toLocaleDateString('en-PH', {
                  weekday: 'long',
                  month: 'long',
                  day: 'numeric',
                  year: 'numeric',
                })}
              </p>
              <Badge
                variant="outline"
                className={cn(
                  'mt-3 text-[11px] font-semibold',
                  nextHoliday.type === 'regular' &&
                    'border-teal-600/50 bg-teal-600/10 text-teal-900 dark:border-teal-500/40 dark:bg-teal-950/50 dark:text-teal-200',
                  nextHoliday.type === 'special' &&
                    'border-amber-600/50 bg-amber-600/10 text-amber-950 dark:border-amber-600/40 dark:bg-amber-950/45 dark:text-amber-100',
                  nextHoliday.type === 'special_working' &&
                    'border-slate-500/50 bg-slate-600/10 text-slate-900 dark:border-slate-500/40 dark:bg-slate-950/45 dark:text-slate-100',
                  nextHoliday.type === 'company' && 'border-violet-600/40 bg-violet-600/10 text-violet-900 dark:bg-violet-900/30 dark:text-violet-100'
                )}
              >
                {nextHoliday.type === 'regular'
                  ? 'Regular'
                  : nextHoliday.type === 'special'
                    ? 'Special'
                    : nextHoliday.type === 'special_working'
                      ? 'Sp. work'
                      : 'Company'}
              </Badge>
            </>
          ) : (
            <p className="mt-3 text-sm text-muted-foreground">No upcoming holidays in this schedule.</p>
          )}
          <p className="mt-4 text-xs leading-relaxed text-muted-foreground">
            Tap a date on the calendar for pay rules and premium simulation.
          </p>
          <Button type="button" variant="secondary" size="sm" className="mt-3 w-full" onClick={onViewList}>
            <List className="mr-2 size-4" />
            View full schedule
          </Button>
        </div>

        <div className="grid grid-cols-2 gap-3">
          <div className="rounded-xl border border-border/50 bg-muted/30 px-3 py-3 text-center dark:bg-muted/20">
            <p className="text-2xl font-bold tabular-nums text-foreground">{holidaysInNext30}</p>
            <p className="mt-1 text-[10px] font-semibold uppercase tracking-wide text-muted-foreground">Next 30 days</p>
          </div>
          <div className="rounded-xl border border-border/50 bg-muted/30 px-3 py-3 text-center dark:bg-muted/20">
            <Users className="mx-auto size-5 text-muted-foreground" aria-hidden />
            <p className="mt-2 text-xs font-semibold text-foreground">All employees</p>
            <p className="mt-0.5 text-[10px] font-semibold uppercase tracking-wide text-muted-foreground">Impact scope</p>
          </div>
        </div>
      </div>
    </div>
  )
}

function HolidaySkeleton() {
  return (
    <div className="animate-pulse space-y-6">
      {/* Top bar */}
      <div className="flex flex-wrap items-center justify-between gap-4">
        <div className="flex gap-2">
          <div className="h-10 w-32 rounded-lg bg-muted" />
          <div className="h-10 w-24 rounded-lg bg-muted" />
        </div>
        <div className="h-10 w-36 rounded-md bg-muted" />
      </div>

      {/* Filters */}
      <div className="flex flex-wrap items-end gap-4 pb-4">
        <div className="flex gap-2">
          <div className="h-8 w-24 rounded-full bg-muted" />
          <div className="h-8 w-28 rounded-full bg-muted" />
        </div>
        <div className="flex gap-2">
          <div className="h-8 w-16 rounded-full bg-muted" />
          <div className="h-8 w-32 rounded-full bg-muted" />
          <div className="h-8 w-28 rounded-full bg-muted" />
        </div>
        <div className="flex gap-3">
          <div className="h-9 w-24 rounded-md bg-muted" />
          <div className="h-9 w-36 rounded-md bg-muted" />
          <div className="h-9 w-36 rounded-md bg-muted" />
        </div>
      </div>

      {/* Month header */}
      <div className="flex flex-wrap items-center justify-between gap-4">
        <div className="h-8 w-40 rounded bg-muted" />
        <div className="flex gap-2">
          <div className="h-9 w-9 rounded-md bg-muted" />
          <div className="h-9 w-16 rounded-md bg-muted" />
          <div className="h-9 w-9 rounded-md bg-muted" />
        </div>
      </div>

      {/* Calendar grid */}
      <div className="grid grid-cols-7 grid-rows-[auto_repeat(6,minmax(5.5rem,1fr))] gap-px overflow-hidden rounded-xl border border-border bg-border">
        {WEEKDAYS.map((w) => (
          <div key={w} className="bg-[#f1f5f9] px-2 py-2 dark:bg-card">
            <div className="mx-auto h-3 w-8 rounded bg-muted" />
          </div>
        ))}
        {Array.from({ length: 42 }).map((_, i) => (
          <div key={i} className="flex min-h-[5.5rem] bg-card p-2 dark:bg-card">
            <div className="flex w-full flex-col gap-2 rounded-xl border border-border/60 bg-[#f8fafc] p-2 dark:border-border/40 dark:bg-muted/15">
              <div className="h-3 w-5 rounded bg-muted" />
              <div className="flex-1 space-y-2">
                <div className="h-3 w-full rounded bg-muted" />
                <div className="h-4 w-20 rounded bg-muted" />
              </div>
            </div>
          </div>
        ))}
      </div>

      {/* Legend */}
      <div className="flex flex-wrap items-center gap-6 pt-4">
        <div className="h-4 w-16 rounded bg-muted" />
        <div className="flex gap-2">
          <div className="h-3 w-3 rounded-full bg-muted" />
          <div className="h-4 w-24 rounded bg-muted" />
        </div>
        <div className="flex gap-2">
          <div className="h-3 w-3 rounded-full bg-muted" />
          <div className="h-4 w-28 rounded bg-muted" />
        </div>
        <div className="flex gap-2">
          <div className="h-3 w-3 rounded-full bg-muted" />
          <div className="h-4 w-24 rounded bg-muted" />
        </div>
      </div>
    </div>
  )
}

function getCalendarCells(year, month, holidayMap) {
  const first = new Date(year, month, 1)
  const last = new Date(year, month + 1, 0)
  const startPad = first.getDay()
  const daysInMonth = last.getDate()
  const prevMonth = month === 0 ? 11 : month - 1
  const prevYear = month === 0 ? year - 1 : year
  const prevLast = new Date(prevYear, prevMonth + 1, 0).getDate()

  const cells = []
  for (let i = 0; i < startPad; i++) {
    const d = prevLast - startPad + 1 + i
    const dateStr = `${prevYear}-${String(prevMonth + 1).padStart(2, '0')}-${String(d).padStart(2, '0')}`
    cells.push({ day: d, month: prevMonth, year: prevYear, dateStr, isAdjacent: true, holiday: holidayMap.get(dateStr) })
  }
  for (let d = 1; d <= daysInMonth; d++) {
    const dateStr = `${year}-${String(month + 1).padStart(2, '0')}-${String(d).padStart(2, '0')}`
    cells.push({ day: d, month, year, dateStr, isAdjacent: false, holiday: holidayMap.get(dateStr) })
  }
  const remaining = 42 - cells.length
  for (let i = 0; i < remaining; i++) {
    const d = i + 1
    const nextMonth = month === 11 ? 0 : month + 1
    const nextYear = month === 11 ? year + 1 : year
    const dateStr = `${nextYear}-${String(nextMonth + 1).padStart(2, '0')}-${String(d).padStart(2, '0')}`
    cells.push({ day: d, month: nextMonth, year: nextYear, dateStr, isAdjacent: true, holiday: holidayMap.get(dateStr) })
  }
  return cells
}

function CalendarCell({ cell, todayKey, onSelect, typeFilter, isSelected }) {
  const { day, dateStr, isAdjacent, holiday, month: cellMonth } = cell
  const isToday = dateStr === todayKey
  const typeKey =
    holiday?.type === 'regular' ||
    holiday?.type === 'special' ||
    holiday?.type === 'special_working' ||
    holiday?.type === 'company'
      ? holiday.type
      : null
  const meta = typeKey ? HOLIDAY_TYPE_META[typeKey] : null

  const filteredOut =
    typeFilter &&
    holiday &&
    ((typeFilter === 'regular' && holiday.type !== 'regular') ||
      (typeFilter === 'special' && holiday.type !== 'special') ||
      (typeFilter === 'special_working' && holiday.type !== 'special_working') ||
      (typeFilter === 'company' && holiday.type !== 'company'))

  const monthShort = MONTHS[cellMonth]?.slice(0, 3) ?? ''

  const tooltip = holiday && meta ? `${holiday.name} — ${meta.title}` : undefined

  return (
    <button
      type="button"
      title={tooltip}
      onClick={() => onSelect?.(cell)}
      className={cn(
        'touch-manipulation group relative flex h-full min-h-[4.25rem] w-full min-w-0 max-w-full flex-col rounded-lg border border-border/25 p-1.5 text-left @sm:min-h-[5.25rem] @sm:rounded-xl @sm:p-2',
        'transition-all duration-200 ease-out focus:outline-none focus-visible:ring-[3px] focus-visible:ring-primary/40 focus-visible:ring-offset-2 ring-offset-background',
        'hover:shadow-md active:scale-[0.99] @lg:hover:z-[1] @lg:hover:scale-[1.03]',
        isAdjacent && !holiday && 'border-border/20 bg-muted/35 text-muted-foreground dark:border-border/30 dark:bg-muted/20',
        isAdjacent && holiday && meta && meta.cellAdjacent,
        !isAdjacent && !holiday && 'border-border/25 bg-card text-foreground shadow-sm dark:border-border/30 dark:bg-card',
        !isAdjacent && !holiday && 'hover:border-primary/30 hover:bg-muted/30 dark:hover:bg-muted/25',
        !isAdjacent && holiday && meta && meta.cell,
        isToday &&
          'ring-2 ring-primary/60 ring-offset-2 ring-offset-background dark:ring-primary/50',
        isSelected &&
          'z-[2] scale-[1.02] border-primary shadow-[0_0_0_3px_hsl(var(--primary)/0.18)] dark:shadow-[0_0_0_3px_hsl(var(--primary)/0.28)]',
        filteredOut && 'opacity-40'
      )}
    >
      <div className="flex items-start justify-between gap-1">
        <span
          className={cn(
            'text-[15px] font-bold tabular-nums leading-none tracking-tight @sm:text-lg',
            isAdjacent && !holiday && 'text-muted-foreground/80',
            holiday && meta && 'text-current/95 dark:text-current'
          )}
        >
          {isToday ? (
            <span className="inline-flex min-w-7 items-center justify-center rounded-md bg-primary px-1 py-0.5 text-sm font-bold text-primary-foreground shadow-sm @sm:rounded-lg @sm:px-1.5 @sm:text-base">
              {day}
            </span>
          ) : (
            day
          )}
        </span>
        {isAdjacent && (
          <span className="shrink-0 rounded bg-background/40 px-1 py-0.5 text-[9px] font-bold uppercase tracking-wider text-muted-foreground dark:bg-background/20">
            {monthShort}
          </span>
        )}
      </div>

      {holiday && meta ? (
        <div className="mt-1 flex min-h-0 min-w-0 flex-1 flex-col @sm:mt-1.5">
          <p className="line-clamp-2 break-words text-[10px] font-semibold leading-tight tracking-tight @sm:text-[13px] @sm:leading-snug">
            {holiday.name}
          </p>
          <span
            className={cn(
              'mt-0.5 inline-flex w-fit max-w-full truncate rounded px-1 py-0.5 text-[8px] @sm:mt-1 @sm:px-1.5 @sm:text-[10px]',
              meta.typePill
            )}
          >
            {meta.shortLabel}
          </span>
          <div className="mt-auto flex justify-end pt-1 @sm:pt-1.5">
            <span
              className={cn(
                'inline-flex min-h-7 min-w-9 shrink-0 items-center justify-center rounded-full px-1.5 py-0.5 text-xs font-black tabular-nums leading-none tracking-tight @sm:min-h-8 @sm:min-w-11 @sm:px-2 @sm:py-1 @sm:text-base',
                meta.badge
              )}
            >
              {meta.multiplier === '—' ? '—' : meta.multiplier}
            </span>
          </div>
        </div>
      ) : isAdjacent ? (
        <div className="mt-1 flex flex-1" aria-hidden />
      ) : (
        <div className="mt-auto flex flex-1 items-end justify-center pb-0.5 opacity-0 transition-opacity group-hover:opacity-100">
          <span className="rounded-md border border-border/60 bg-muted/30 px-2 py-1 text-[10px] font-semibold text-muted-foreground">
            Add
          </span>
        </div>
      )}
    </button>
  )
}

export default function AdminHoliday() {
  useAuth()
  const [year, setYear] = useState(new Date().getFullYear())
  const [month, setMonth] = useState(new Date().getMonth())
  const [activeView, setActiveView] = useState('calendar') // 'calendar' | 'list'
  const [selectedCell, setSelectedCell] = useState(null)
  const [typeFilter, setTypeFilter] = useState('')
  const [yearFilter, setYearFilter] = useState('')
  const [dateFrom, setDateFrom] = useState('')
  const [dateTo, setDateTo] = useState('')
  const [filtersSheetOpen, setFiltersSheetOpen] = useState(false)
  const [holidaySearch, setHolidaySearch] = useState('')
  const [holidayModalOpen, setHolidayModalOpen] = useState(false)
  const [holidayModalMode, setHolidayModalMode] = useState('create')
  const [holidayModalId, setHolidayModalId] = useState(null)
  const [holidayModalInitial, setHolidayModalInitial] = useState(null)
  const [holidays, setHolidays] = useState([]) // Merged from API (includes custom DB holidays)
  const [loading, setLoading] = useState(true)

  const todayKey = useMemo(() => new Date().toISOString().slice(0, 10), [])

  const refetchHolidays = useCallback(async (opts = {}) => {
    if (!opts.silent) setLoading(true)
    try {
      const res = await getAdminHolidays({ year })
      setHolidays(res.holidays || [])
    } catch {
      setHolidays([])
    } finally {
      if (!opts.silent) setLoading(false)
    }
  }, [year])

  // Fetch holidays from API when year changes
  useEffect(() => {
    refetchHolidays()
  }, [refetchHolidays])

  // Holidays from API (Time and Date API + custom DB)
  const allHolidays = useMemo(() => {
    const base = holidays.map((h) => ({ ...h, scope: h.scope || 'nationwide' }))
    return base.sort((a, b) => a.date.localeCompare(b.date))
  }, [holidays])

  const filteredHolidays = useMemo(() => {
    let list = [...allHolidays]
    const q = holidaySearch.trim().toLowerCase()
    if (q) {
      list = list.filter((h) => (h.name || '').toLowerCase().includes(q))
    }
    if (typeFilter) {
      list = list.filter((h) => h.type === typeFilter)
    }
    if (yearFilter) {
      const y = parseInt(yearFilter, 10)
      list = list.filter((h) => new Date(h.date).getFullYear() === y)
    }
    if (dateFrom) {
      list = list.filter((h) => h.date >= dateFrom)
    }
    if (dateTo) {
      list = list.filter((h) => h.date <= dateTo)
    }
    return list.sort((a, b) => a.date.localeCompare(b.date))
  }, [allHolidays, holidaySearch, typeFilter, yearFilter, dateFrom, dateTo])

  const holidaysInViewMonth = useMemo(() => {
    return allHolidays.filter((h) => {
      const d = new Date(`${h.date}T12:00:00`)
      return d.getFullYear() === year && d.getMonth() === month
    }).length
  }, [allHolidays, year, month])

  const holidayMap = useMemo(() => {
    const m = new Map()
    filteredHolidays.forEach((h) => m.set(h.date, h))
    return m
  }, [filteredHolidays])

  const calendarCells = useMemo(
    () => getCalendarCells(year, month, holidayMap),
    [year, month, holidayMap]
  )

  const upcomingHolidays = useMemo(() => {
    const now = new Date()
    return allHolidays
      .filter((h) => new Date(h.date) >= now)
      .sort((a, b) => a.date.localeCompare(b.date))
      .slice(0, 8)
  }, [allHolidays])

  const holidaysInNext30 = useMemo(() => {
    const now = new Date()
    const in30 = new Date(now)
    in30.setDate(in30.getDate() + 30)
    return allHolidays.filter((h) => {
      const d = new Date(h.date)
      return d >= now && d <= in30
    }).length
  }, [allHolidays])

  const nextHoliday = upcomingHolidays[0] ?? null
  const daysUntilNextHoliday = useMemo(() => {
    if (!nextHoliday?.date) return null
    const [y, m, d] = nextHoliday.date.split('-').map(Number)
    const target = new Date(y, m - 1, d)
    const today = new Date()
    const start = new Date(today.getFullYear(), today.getMonth(), today.getDate())
    const end = new Date(target.getFullYear(), target.getMonth(), target.getDate())
    return Math.round((end - start) / (1000 * 60 * 60 * 24))
  }, [nextHoliday])

  const goPrevMonth = () => {
    if (month === 0) {
      setMonth(11)
      setYear((y) => y - 1)
    } else setMonth((m) => m - 1)
  }

  const goNextMonth = () => {
    if (month === 11) {
      setMonth(0)
      setYear((y) => y + 1)
    } else setMonth((m) => m + 1)
  }

  const goToday = () => {
    const today = new Date()
    setYear(today.getFullYear())
    setMonth(today.getMonth())
  }

  const blockedHolidayDates = useMemo(() => {
    const s = new Set(allHolidays.map((h) => h.date))
    if (holidayModalId != null) {
      const row = allHolidays.find((h) => h.id === holidayModalId)
      if (row) s.delete(row.date)
    }
    return s
  }, [allHolidays, holidayModalId])

  const handleDeleteHoliday = async (id) => {
    try {
      await deleteAdminHoliday(id)
      await refetchHolidays({ silent: true })
      setSelectedCell(null)
      toast.success('Holiday deleted successfully')
    } catch (err) {
      const msg = err.message || 'Failed to delete holiday'
      toast.error('Failed to delete holiday', { description: msg })
    }
  }

  const openHolidayCreate = useCallback((prefill = {}) => {
    setHolidayModalMode('create')
    setHolidayModalId(null)
    setHolidayModalInitial(Object.keys(prefill).length ? prefill : null)
    setHolidayModalOpen(true)
  }, [])

  const openHolidayEdit = useCallback((holiday) => {
    if (!holiday?.id || typeof holiday.id !== 'number') return
    setHolidayModalMode('edit')
    setHolidayModalId(holiday.id)
    setHolidayModalInitial(holiday)
    setHolidayModalOpen(true)
    setSelectedCell(null)
  }, [])

  const clearFilters = () => {
    setTypeFilter('')
    setYearFilter('')
    setDateFrom('')
    setDateTo('')
    setHolidaySearch('')
  }

  const hasFilters = typeFilter || yearFilter || dateFrom || dateTo || holidaySearch.trim()

  return (
    <Motion.div
      className="min-w-0 max-w-full space-y-4 overflow-x-hidden @sm:space-y-6"
      initial={{ opacity: 0, y: 10 }}
      animate={{ opacity: 1, y: 0 }}
      transition={{ duration: 0.25, ease: [0.23, 1, 0.32, 1] }}
    >
      <div className="flex flex-col gap-4 @sm:flex-row @sm:items-center @sm:justify-between">
        <div>
          <h2 className="text-2xl font-bold tracking-tight">Holidays</h2>
          <CardDescription>
            Nationwide, company, and custom observances. Premium pay follows DOLE rules and your payroll policy.
          </CardDescription>
        </div>
      </div>

      <HolidayLegendBar />

      <div className="flex min-w-0 flex-col gap-4 @sm:gap-6 @lg:flex-row @lg:items-start @lg:gap-6">
        <div className="min-w-0 flex-1">
        <Card className="overflow-hidden border-0 bg-card shadow-sm">
            <CardHeader className="bg-muted/20 dark:bg-muted/30">
              <CardTitle className="text-lg font-semibold">Calendar &amp; list</CardTitle>
              <CardDescription>Browse by month or use a filtered list view.</CardDescription>
            </CardHeader>
            <CardContent className="p-0">
              {loading ? (
                <div className="p-4 md:p-6">
                  <HolidaySkeleton />
                </div>
              ) : (
                <AnimatedSection staggerChildren={0.06} duration={0.5}>
                  <div className="bg-white/80 px-3 py-3 backdrop-blur-sm dark:bg-muted/45 dark:backdrop-blur-md @sm:px-4 md:px-6">
                    <div className="flex flex-col gap-3">
                      {/* Row 1: View mode — full width on narrow screens */}
                      <div className="flex w-full min-w-0 justify-center @sm:justify-start">
                        <div className="inline-flex rounded-full border border-border/60 bg-muted/30 p-0.5 dark:border-border/50 dark:bg-muted/40">
                          <button
                            type="button"
                            onClick={() => setActiveView('calendar')}
                            className={cn(
                              'flex items-center gap-1.5 rounded-full px-3 py-2 text-sm font-semibold transition-colors @sm:gap-2 @sm:px-4',
                              activeView === 'calendar'
                                ? 'border border-primary/30 bg-primary/15 text-primary dark:bg-primary/20'
                                : 'border border-transparent text-muted-foreground hover:text-foreground'
                            )}
                          >
                            <CalendarDays className="size-4 shrink-0" />
                            Calendar
                          </button>
                          <button
                            type="button"
                            onClick={() => setActiveView('list')}
                            className={cn(
                              'flex items-center gap-1.5 rounded-full px-3 py-2 text-sm font-semibold transition-colors @sm:gap-2 @sm:px-4',
                              activeView === 'list'
                                ? 'border border-primary/30 bg-primary/15 text-primary dark:bg-primary/20'
                                : 'border border-transparent text-muted-foreground hover:text-foreground'
                            )}
                          >
                            <List className="size-4 shrink-0" />
                            List
                          </button>
                        </div>
                      </div>

                      {/* Row 2: Month navigation (calendar only) */}
                      {activeView === 'calendar' && (
                        <div className="flex w-full min-w-0 items-center justify-center gap-0.5 rounded-xl bg-muted/30 p-1 dark:bg-muted/35">
                          <Button
                            variant="ghost"
                            size="icon"
                            className="size-9 shrink-0 rounded-lg hover:bg-background/80 @sm:size-10"
                            onClick={goPrevMonth}
                            aria-label="Previous month"
                          >
                            <ChevronLeft className="size-4 @sm:size-5" />
                          </Button>
                          <button
                            type="button"
                            onClick={goToday}
                            className="min-w-0 flex-1 truncate px-1 py-2 text-center text-sm font-bold tracking-tight text-foreground hover:bg-background/60 dark:hover:bg-background/10 @sm:px-4 @sm:text-base"
                          >
                            {MONTHS[month]} {year}
                          </button>
                          <Button
                            variant="ghost"
                            size="icon"
                            className="size-9 shrink-0 rounded-lg hover:bg-background/80 @sm:size-10"
                            onClick={goNextMonth}
                            aria-label="Next month"
                          >
                            <ChevronRight className="size-4 @sm:size-5" />
                          </Button>
                        </div>
                      )}

                      {/* Row 3: Search — full width */}
                      <div className="relative w-full min-w-0 max-w-full">
                        <Search className="pointer-events-none absolute left-3 top-1/2 size-4 -translate-y-1/2 text-muted-foreground" aria-hidden />
                        <Input
                          type="search"
                          value={holidaySearch}
                          onChange={(e) => setHolidaySearch(e.target.value)}
                          placeholder="Search holidays…"
                          className="h-10 w-full min-w-0 border-border/60 bg-background/80 pl-9 text-sm dark:bg-background/40"
                          aria-label="Search holidays"
                        />
                      </div>

                      {/* Row 4: Type chips + filters + add */}
                      <div className="flex w-full min-w-0 flex-col gap-2 @sm:flex-row @sm:flex-wrap @sm:items-center @sm:gap-2">
                        <div className="flex min-w-0 flex-wrap gap-1.5">
                          {[
                            { value: 'regular', label: 'Regular' },
                            { value: 'special', label: 'Special' },
                            { value: 'special_working', label: 'Sp. work' },
                            { value: 'company', label: 'Company' },
                          ].map(({ value, label }) => (
                            <button
                              key={value}
                              type="button"
                              onClick={() => setTypeFilter((f) => (f === value ? '' : value))}
                              className={cn(
                                'rounded-full border px-2.5 py-1.5 text-xs font-bold transition-colors @sm:px-3',
                                typeFilter === value
                                  ? 'border-primary bg-primary text-primary-foreground shadow-sm'
                                  : 'border-border/60 bg-transparent text-muted-foreground hover:border-border hover:text-foreground'
                              )}
                            >
                              {label}
                            </button>
                          ))}
                        </div>
                        <div className="flex w-full min-w-0 flex-wrap items-center gap-2 @sm:ml-auto @sm:w-auto @sm:justify-end">
                          <Popover open={filtersSheetOpen} onOpenChange={setFiltersSheetOpen}>
                            <PopoverTrigger asChild>
                              <Button variant="outline" size="sm" className="h-10 shrink-0 gap-2">
                                <SlidersHorizontal className="size-4" />
                                Filters
                                {hasFilters && <span className="size-2 rounded-full bg-primary" />}
                              </Button>
                            </PopoverTrigger>
                            <PopoverContent align="end" className="w-[min(100vw-2rem,18rem)] p-4 sm:w-72">
                              <h3 className="mb-3 text-sm font-medium text-foreground">Filters</h3>
                              <div className="space-y-4">
                                <div className="flex flex-wrap gap-1.5">
                                  {[{ value: '', label: 'All' }, ...TYPES].map(({ value, label }) => (
                                    <button
                                      key={value || 'all'}
                                      type="button"
                                      onClick={() => setTypeFilter(value)}
                                      className={cn(
                                        'rounded-full border px-2.5 py-1.5 text-xs font-semibold transition-colors',
                                        typeFilter === value
                                          ? 'border-primary bg-primary/15 text-primary dark:bg-primary/20'
                                          : 'border-border/60 bg-transparent text-muted-foreground hover:border-border hover:text-foreground'
                                      )}
                                    >
                                      {label}
                                    </button>
                                  ))}
                                </div>
                                <div className="flex gap-2">
                                  <button
                                    type="button"
                                    onClick={() => {
                                      const n = new Date()
                                      const last = new Date(n.getFullYear(), n.getMonth() + 1, 0)
                                      setDateFrom(`${n.getFullYear()}-${String(n.getMonth() + 1).padStart(2, '0')}-01`)
                                      setDateTo(last.toISOString().slice(0, 10))
                                      setYearFilter(String(n.getFullYear()))
                                      setFiltersSheetOpen(false)
                                    }}
                                    className="flex-1 rounded-md border border-border py-2 text-xs font-medium text-foreground hover:bg-muted/50"
                                  >
                                    This month
                                  </button>
                                  <button
                                    type="button"
                                    onClick={() => {
                                      const n = new Date()
                                      const to = new Date(n)
                                      to.setDate(to.getDate() + 30)
                                      setDateFrom(n.toISOString().slice(0, 10))
                                      setDateTo(to.toISOString().slice(0, 10))
                                      setYearFilter('')
                                      setFiltersSheetOpen(false)
                                    }}
                                    className="flex-1 rounded-md border border-border py-2 text-xs font-medium text-foreground hover:bg-muted/50"
                                  >
                                    Next 30d
                                  </button>
                                </div>
                                <div className="flex gap-2">
                                  <Input type="date" value={dateFrom} onChange={(e) => setDateFrom(e.target.value)} className="h-8 min-w-0 flex-1 text-xs" />
                                  <Input type="date" value={dateTo} onChange={(e) => setDateTo(e.target.value)} className="h-8 min-w-0 flex-1 text-xs" />
                                </div>
                                {hasFilters && (
                                  <button
                                    type="button"
                                    onClick={() => {
                                      clearFilters()
                                      setFiltersSheetOpen(false)
                                    }}
                                    className="flex items-center gap-1 text-xs text-muted-foreground hover:text-foreground"
                                  >
                                    <RotateCcw className="size-3" /> Clear
                                  </button>
                                )}
                              </div>
                            </PopoverContent>
                          </Popover>
                          <Button
                            onClick={() => openHolidayCreate()}
                            className="h-10 min-w-0 shrink-0 gap-2 px-3 @sm:px-4"
                          >
                            <Plus className="size-4 shrink-0" />
                            <span className="truncate">Add Holiday</span>
                          </Button>
                        </div>
                      </div>
                    </div>
                  </div>

            {/* Filters — inline popover beside Add Holiday; summary when active */}
            {hasFilters && (
              <div className="flex flex-wrap items-center gap-2 px-3 py-2 text-xs text-muted-foreground @sm:px-4 md:px-6">
                <span>Filters active</span>
                <Button variant="ghost" size="sm" className="h-6 px-2 text-xs" onClick={() => setFiltersSheetOpen(true)}>
                  Edit
                </Button>
                <Button variant="ghost" size="sm" className="h-6 px-2 text-xs" onClick={clearFilters}>
                  Clear
                </Button>
              </div>
            )}
            {/* Calendar View */}
            {activeView === 'calendar' && (
              <>
                <div className="mt-2 space-y-3 px-3 pb-4 @sm:px-4 md:pb-6">
                  <div className="flex flex-wrap items-center justify-between gap-2 rounded-lg bg-muted/25 px-3 py-2 text-xs @sm:text-sm dark:bg-muted/20">
                    <p className="text-muted-foreground">
                      <span className="font-bold tabular-nums text-foreground">{holidaysInViewMonth}</span>
                      <span className="mx-1">holiday{holidaysInViewMonth === 1 ? '' : 's'}</span>
                      <span className="text-muted-foreground/90">in {MONTHS[month]} {year}</span>
                    </p>
                    {holidaySearch.trim() && (
                      <span className="text-xs font-medium text-muted-foreground">Search filters the calendar</span>
                    )}
                  </div>
                  <div className="w-full min-w-0 overflow-x-auto @sm:overflow-x-visible">
                    <div className="grid w-full min-w-[280px] grid-cols-[repeat(7,minmax(0,1fr))] grid-rows-[auto_repeat(6,minmax(4.25rem,1fr))] gap-2 @sm:min-w-0 @sm:grid-rows-[auto_repeat(6,minmax(5.25rem,1fr))]">
                  {WEEKDAYS.map((w) => (
                    <div
                      key={w}
                      className="min-w-0 rounded-lg bg-muted/50 px-0.5 py-2 text-center text-[9px] font-bold uppercase leading-tight tracking-wide text-muted-foreground @sm:px-2 @sm:py-2.5 @sm:text-[11px] @sm:tracking-wider"
                    >
                      {w}
                    </div>
                  ))}
                  {calendarCells.map((cell, idx) => (
                    <div key={idx} className="flex min-h-[4.25rem] min-w-0 @sm:min-h-[5.25rem]">
                      <CalendarCell
                        cell={cell}
                        todayKey={todayKey}
                        onSelect={(c) => setSelectedCell(c)}
                        typeFilter={typeFilter}
                        isSelected={selectedCell?.dateStr === cell.dateStr}
                      />
                    </div>
                  ))}
                    </div>
                  </div>
                </div>
              </>
            )}

            {/* List View */}
            {activeView === 'list' && (
              <div className="overflow-x-auto bg-card px-3 pb-4 @sm:px-4 md:pb-6">
                <table className="w-full min-w-[520px] text-sm text-foreground">
                  <thead className="sticky top-0 z-10 bg-muted/40 dark:bg-muted/25">
                    <tr>
                      <th className="py-3 px-3 text-left text-[11px] font-bold uppercase tracking-wider text-muted-foreground">Date</th>
                      <th className="py-3 px-3 text-left text-[11px] font-bold uppercase tracking-wider text-muted-foreground">Holiday</th>
                      <th className="py-3 px-3 text-left text-[11px] font-bold uppercase tracking-wider text-muted-foreground">Type</th>
                      <th className="py-3 px-3 text-left text-[11px] font-bold uppercase tracking-wider text-muted-foreground">Scope</th>
                    </tr>
                  </thead>
                  <tbody className="bg-card">
                    {filteredHolidays.length === 0 ? (
                      <tr>
                        <td colSpan={4} className="py-12 text-center text-sm text-muted-foreground">
                          No holidays match your filters.
                        </td>
                      </tr>
                    ) : (
                      filteredHolidays.map((h, rowIdx) => (
                        <tr
                          key={h.id || h.date}
                          className={cn(
                            'transition-colors hover:bg-muted/25',
                            rowIdx % 2 === 1 ? 'bg-card' : 'bg-muted/20 dark:bg-muted/10'
                          )}
                        >
                          <td className="px-3 py-3 align-middle tabular-nums text-foreground">
                            {new Date(h.date).toLocaleDateString('en-PH', { weekday: 'short', month: 'short', day: 'numeric', year: 'numeric' })}
                          </td>
                          <td className="px-3 py-3 align-middle font-medium text-foreground">{h.name}</td>
                          <td className="px-3 py-3 align-middle">
                            <Badge
                              variant="outline"
                              className={cn(
                                'text-[10px] font-semibold',
                                h.type === 'regular' &&
                                  'border-teal-500/50 bg-teal-600/10 text-teal-900 dark:border-teal-600/40 dark:bg-teal-950/50 dark:text-teal-200',
                                h.type === 'special' &&
                                  'border-amber-500/50 bg-amber-600/10 text-amber-950 dark:border-amber-600/40 dark:bg-amber-950/45 dark:text-amber-100',
                                h.type === 'special_working' &&
                                  'border-slate-500/50 bg-slate-600/10 text-slate-900 dark:border-slate-500/40 dark:bg-slate-950/45 dark:text-slate-100',
                                h.type === 'company' &&
                                  'border-violet-500/50 bg-violet-600/10 text-violet-900 dark:border-violet-600/40 dark:bg-violet-950/40 dark:text-violet-100'
                              )}
                            >
                              {h.type === 'regular'
                                ? 'Regular'
                                : h.type === 'special'
                                  ? 'Special'
                                  : h.type === 'special_working'
                                    ? 'Sp. work'
                                    : 'Company'}
                            </Badge>
                          </td>
                          <td className="px-3 py-3 align-middle capitalize text-muted-foreground">{h.scope || 'nationwide'}</td>
                        </tr>
                      ))
                    )}
                  </tbody>
                </table>
              </div>
            )}
                </AnimatedSection>
              )}
            </CardContent>
          </Card>
        </div>

        <aside className="w-full min-w-0 max-w-full shrink-0 @lg:w-[min(100%,320px)] @lg:sticky @lg:top-20 @lg:self-start">
          <HolidayInsightPanel
            nextHoliday={nextHoliday}
            daysUntilNextHoliday={daysUntilNextHoliday}
            holidaysInNext30={holidaysInNext30}
            onViewList={() => setActiveView('list')}
          />
        </aside>
      </div>

      <HolidayFormModal
        open={holidayModalOpen}
        onOpenChange={(o) => {
          setHolidayModalOpen(o)
          if (!o) {
            setHolidayModalId(null)
            setHolidayModalInitial(null)
          }
        }}
        mode={holidayModalMode}
        editingId={holidayModalId}
        initial={holidayModalInitial}
        blockedDates={blockedHolidayDates}
        onSaved={async () => {
          await refetchHolidays({ silent: true })
        }}
      />

      {/* Date click → Detail panel (premium SaaS modal) */}
      <Sheet open={!!selectedCell} onOpenChange={(open) => !open && setSelectedCell(null)}>
        <SheetContent
          side="right"
          className="flex h-full max-h-screen w-full max-w-[520px] flex-col overflow-y-auto border-l border-border bg-card p-0 sm:max-w-[520px]"
          showCloseButton={false}
        >
          {selectedCell?.holiday ? (
            /* Holiday detail modal — 2026 premium: no redundancy, unified accent, compact rules */
            <div className="flex flex-col">
              {/* Header: title + date + scope pill + close — no duplicate type */}
              <div className="flex items-start justify-between gap-4 px-6 py-6">
                <div className="min-w-0 flex-1">
                  <div className="flex items-center gap-2 mb-2">
                    <Badge
                      variant="outline"
                      className={cn(
                        'text-xs font-medium',
                        selectedCell.holiday.type === 'regular' && 'border-teal-500/25 bg-teal-500/10 text-teal-800 dark:border-teal-500/30 dark:bg-teal-500/15 dark:text-teal-200',
                        selectedCell.holiday.type === 'special' && 'border-amber-500/25 bg-amber-500/10 text-amber-900 dark:border-amber-500/30 dark:bg-amber-500/15 dark:text-amber-100',
                        selectedCell.holiday.type === 'special_working' &&
                          'border-slate-500/25 bg-slate-500/10 text-slate-900 dark:border-slate-500/30 dark:bg-slate-950/40 dark:text-slate-100',
                        selectedCell.holiday.type === 'company' && 'border-violet-500/25 bg-violet-500/10 text-violet-900 dark:border-violet-500/30 dark:bg-violet-950/40 dark:text-violet-100'
                      )}
                    >
                      {selectedCell.holiday.type === 'regular'
                        ? 'Regular Holiday'
                        : selectedCell.holiday.type === 'special'
                          ? 'Special Non-Working'
                          : selectedCell.holiday.type === 'special_working'
                            ? 'Special Working Day'
                            : 'Company Event'}
                    </Badge>
                    <span className="text-xs text-muted-foreground">
                      {(selectedCell.holiday.scope || 'nationwide').charAt(0).toUpperCase() + (selectedCell.holiday.scope || 'nationwide').slice(1)}
                    </span>
                  </div>
                  <h2 className="text-2xl font-bold tracking-tight text-foreground">{selectedCell.holiday.name}</h2>
                  <p className="mt-1.5 text-sm text-muted-foreground flex items-center gap-1.5">
                    <Calendar className="size-3.5 shrink-0" />
                    {new Date(selectedCell.dateStr).toLocaleDateString('en-PH', { weekday: 'long', month: 'long', day: 'numeric', year: 'numeric' })}
                  </p>
                </div>
                <button
                  type="button"
                  onClick={() => setSelectedCell(null)}
                  className="flex size-9 shrink-0 items-center justify-center rounded-lg text-muted-foreground transition-colors hover:bg-muted hover:text-foreground"
                  aria-label="Close"
                >
                  <X className="size-5" />
                </button>
              </div>

              <div className="px-6 pb-6 space-y-6">
                {/* Description — clearer hierarchy */}
                <section className="rounded-xl border border-border/60 bg-muted/20 dark:bg-white/5 p-4 backdrop-blur-sm">
                  <h3 className="text-xs font-semibold uppercase tracking-wider text-muted-foreground mb-2">Description</h3>
                  <p className="text-sm leading-relaxed text-foreground/90">
                    {selectedCell.holiday.type === 'company'
                      ? (selectedCell.holiday.description || `Company event. Annual observance for ${selectedCell.holiday.scope === 'company' ? 'the company' : 'all branches'}. No DOLE payroll premium applies.`)
                      : (HOLIDAY_DESCRIPTIONS[selectedCell.holiday.name] || 'Philippine holiday per DOLE guidelines. Employees may receive premium pay depending on work conditions.')}
                  </p>
                </section>

                {selectedCell.holiday.type === 'special_working' && (
                  <section className="rounded-xl border border-border/60 bg-muted/20 p-4 text-sm text-foreground/90">
                    <h3 className="text-xs font-semibold uppercase tracking-wider text-muted-foreground mb-2">Pay rates</h3>
                    <p>
                      Special working days are treated as <strong>ordinary working days</strong> for statutory holiday
                      premium. Follow ordinary / rest day / OT rules from your payroll policy.
                    </p>
                  </section>
                )}

                {/* Rules — compact table, single accent (emerald) */}
                {(selectedCell.holiday.type === 'regular' || selectedCell.holiday.type === 'special') && (
                  <section>
                    <h3 className="text-xs font-semibold uppercase tracking-wider text-muted-foreground mb-3">Pay rates</h3>
                    <div className="rounded-xl border border-border/60 overflow-hidden">
                      <table className="w-full text-sm">
                        <tbody>
                          {selectedCell.holiday.type === 'regular' ? (
                            <>
                              <tr className="border-b border-border/50 bg-emerald-500/5 dark:bg-emerald-500/10">
                                <td className="py-2.5 px-4 font-medium">Worked</td>
                                <td className="py-2.5 px-4 text-right font-semibold tabular-nums text-emerald-600 dark:text-emerald-400">2.00×</td>
                              </tr>
                              <tr className="border-b border-border/50 bg-emerald-500/5 dark:bg-emerald-500/10">
                                <td className="py-2.5 px-4 font-medium">Rest day</td>
                                <td className="py-2.5 px-4 text-right font-semibold tabular-nums text-emerald-600 dark:text-emerald-400">2.60×</td>
                              </tr>
                              <tr className="border-b border-border/50 bg-emerald-500/5 dark:bg-emerald-500/10">
                                <td className="py-2.5 px-4 font-medium">Overtime</td>
                                <td className="py-2.5 px-4 text-right font-semibold tabular-nums text-emerald-600 dark:text-emerald-400">3.38×</td>
                              </tr>
                              <tr>
                                <td className="py-2.5 px-4 font-medium text-muted-foreground">Not worked</td>
                                <td className="py-2.5 px-4 text-right tabular-nums text-muted-foreground">100%</td>
                              </tr>
                            </>
                          ) : (
                            <>
                              <tr className="border-b border-border/50 bg-emerald-500/5 dark:bg-emerald-500/10">
                                <td className="py-2.5 px-4 font-medium">Worked</td>
                                <td className="py-2.5 px-4 text-right font-semibold tabular-nums text-emerald-600 dark:text-emerald-400">1.30×</td>
                              </tr>
                              <tr>
                                <td className="py-2.5 px-4 font-medium text-muted-foreground">Not worked</td>
                                <td className="py-2.5 px-4 text-right tabular-nums text-muted-foreground">No pay</td>
                              </tr>
                            </>
                          )}
                        </tbody>
                      </table>
                    </div>
                  </section>
                )}

                {/* Impact — glass card, unified styling */}
                <section>
                  <h3 className="text-xs font-semibold uppercase tracking-wider text-muted-foreground mb-3">Impact</h3>
                  <div className="rounded-xl border border-border/60 bg-white/80 dark:bg-white/5 p-4 backdrop-blur-sm space-y-2">
                    {selectedCell.holiday.type === 'company' ? (
                      <>
                        <p className="text-sm flex items-center gap-2 text-muted-foreground">
                          <span className="size-1.5 rounded-full bg-muted-foreground/50" />
                          No payroll premium applied
                        </p>
                        <p className="text-sm flex items-center gap-2 text-muted-foreground">
                          <span className="size-1.5 rounded-full bg-muted-foreground/50" />
                          Internal observance only
                        </p>
                      </>
                    ) : selectedCell.holiday.type === 'special_working' ? (
                      <>
                        <p className="text-sm flex items-center gap-2 text-foreground">
                          <Users className="size-4 shrink-0 text-slate-500 dark:text-slate-300" />
                          Ordinary workday rates (no statutory holiday premium)
                        </p>
                        <p className="text-sm flex items-center gap-2 text-muted-foreground">
                          <Zap className="size-4 shrink-0 text-slate-500" />
                          Confirm proclamation &amp; CBA — policy may still grant extras
                        </p>
                      </>
                    ) : (
                      <>
                        <p className="text-sm flex items-center gap-2 text-foreground">
                          <Users className="size-4 shrink-0 text-emerald-500 dark:text-emerald-400" />
                          Affects all employees in scope
                        </p>
                        <p className="text-sm flex items-center gap-2 text-foreground">
                          <Zap className="size-4 shrink-0 text-emerald-500 dark:text-emerald-400" />
                          {selectedCell.holiday.type === 'regular' ? 'High OT probability' : 'Standard premium rate'}
                        </p>
                      </>
                    )}
                  </div>
                </section>

                {/* Actions — primary Edit, secondary Delete, ghost Close */}
                <div className="flex flex-col gap-2 pt-2">
                  {typeof selectedCell.holiday.id === 'number' && (
                    <div className="flex gap-2">
                      <Button
                        size="sm"
                        className="flex-1 gap-2 bg-emerald-600 hover:bg-emerald-500 text-white"
                        onClick={() => openHolidayEdit({ ...selectedCell.holiday, date: selectedCell.dateStr })}
                      >
                        <Pencil className="size-3.5" />
                        Edit
                      </Button>
                      <Button
                        variant="outline"
                        size="sm"
                        className="flex-1 gap-2 border-rose-200 text-rose-700 hover:bg-rose-50 dark:border-rose-800 dark:text-rose-300 dark:hover:bg-rose-950/30"
                        onClick={() => handleDeleteHoliday(selectedCell.holiday.id)}
                      >
                        <Trash2 className="size-3.5" />
                        Delete
                      </Button>
                    </div>
                  )}
                  <Button
                    variant="ghost"
                    size="sm"
                    className="w-full text-muted-foreground"
                    onClick={() => setSelectedCell(null)}
                  >
                    Close
                  </Button>
                </div>
              </div>
            </div>
          ) : selectedCell ? (
            /* Empty date - Add Holiday CTA (compact, no dead space) */
            <div className="flex flex-col">
              <div className="flex items-start justify-between gap-4 border-b border-border px-6 py-5">
                <div className="flex items-center gap-2">
                  <div className="flex size-10 items-center justify-center rounded-xl bg-muted/50">
                    <Calendar className="size-5 text-muted-foreground" />
                  </div>
                  <div>
                    <h2 className="text-lg font-bold text-foreground">
                      {new Date(selectedCell.dateStr).toLocaleDateString('en-PH', { weekday: 'long', month: 'long', day: 'numeric', year: 'numeric' })}
                    </h2>
                    <p className="text-sm text-muted-foreground">No holiday on this date</p>
                  </div>
                </div>
                <button
                  type="button"
                  onClick={() => setSelectedCell(null)}
                  className="flex size-10 shrink-0 items-center justify-center rounded-lg border border-border bg-muted/30 text-muted-foreground transition-colors hover:bg-muted hover:text-foreground"
                  aria-label="Close"
                >
                  <X className="size-5" />
                </button>
              </div>
              <div className="flex flex-col gap-4 px-6 py-6">
                <div className="rounded-xl border border-dashed border-border/60 bg-muted/20 py-8 dark:border-border/50 dark:bg-muted/15">
                  <p className="mb-4 text-center text-sm text-muted-foreground">
                    Add a company event or observe a special day on this date
                  </p>
                  <Button
                    onClick={() => {
                      openHolidayCreate({ date: selectedCell.dateStr })
                      setSelectedCell(null)
                    }}
                    className="mx-auto flex gap-2"
                  >
                    <Plus className="size-4" />
                    Add Holiday
                  </Button>
                </div>
                <Button
                  variant="secondary"
                  size="default"
                  className="w-full"
                  onClick={() => setSelectedCell(null)}
                >
                  Close
                </Button>
              </div>
            </div>
          ) : null}
        </SheetContent>
      </Sheet>
    </Motion.div>
  )
}
  