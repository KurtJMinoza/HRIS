import { useState, useMemo, useEffect, useCallback } from 'react'
import { motion as Motion } from 'framer-motion'
import {
  ArrowRightLeft,
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
import { Dialog, DialogContent, DialogFooter, DialogHeader, DialogTitle } from '@/components/ui/dialog'
import { Popover, PopoverContent, PopoverTrigger } from '@/components/ui/popover'
import { useAuth } from '@/contexts/AuthContext'
import { cn } from '@/lib/utils'
import { AnimatedSection } from '@/components/ui/AnimatedSection'
import {
  createAdminHoliday,
  getAdminHolidays,
  getMyHolidays,
  deleteAdminHoliday,
  swapAdminHoliday,
  swapSeededAdminHoliday,
  companyLogoUrl,
} from '@/api'
import { HolidayFormModal } from '@/components/holidays/HolidayFormModal'
import { SwapHolidayModal } from '@/components/holidays/SwapHolidayModal'

const MONTHS = ['January', 'February', 'March', 'April', 'May', 'June', 'July', 'August', 'September', 'October', 'November', 'December']
const WEEKDAYS = ['SUN', 'MON', 'TUE', 'WED', 'THU', 'FRI', 'SAT']

const AGCTEK_BRAND = {
  badge: 'border-orange-500/25 bg-orange-50 text-orange-700 dark:border-orange-400/30 dark:bg-orange-500/10 dark:text-orange-200',
  panel: 'rounded-l-[2rem] border-slate-950/10 bg-white shadow-[0_24px_70px_rgba(15,23,42,0.22)] dark:border-orange-500/20 dark:bg-slate-950',
  section: 'rounded-2xl border border-slate-200 bg-white p-4 shadow-sm dark:border-white/10 dark:bg-slate-900/85',
  sectionTitle: 'mb-2 text-[10px] font-black uppercase tracking-[0.18em] text-slate-500 dark:text-slate-300',
  orangeText: 'text-orange-600 dark:text-orange-300',
}

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
    legendDot: 'bg-orange-500',
    cell:
      'border-teal-700/45 bg-teal-600/[0.14] text-teal-950 shadow-sm dark:border-teal-500/40 dark:bg-teal-950/55 dark:text-teal-50 dark:shadow-none',
    cellAdjacent:
      'border-teal-700/25 bg-teal-600/[0.08] text-teal-900/90 opacity-[0.88] dark:border-teal-600/25 dark:bg-teal-950/40 dark:text-teal-100/90',
    badge: 'bg-orange-600 text-white shadow-sm dark:bg-orange-500 dark:text-white',
    typePill: 'bg-teal-800/15 text-[10px] font-bold uppercase tracking-wide text-teal-900 dark:bg-teal-400/15 dark:text-teal-100',
    title:
      'Regular holiday — 200% daily rate if worked (ordinary day). Higher rates apply on rest day or overtime per DOLE.',
  },
  special: {
    shortLabel: 'Special',
    multiplier: '1.30×',
    legendDot: 'bg-orange-600',
    cell:
      'border-amber-700/50 bg-amber-500/[0.17] text-amber-950 shadow-sm dark:border-amber-500/45 dark:bg-amber-950/50 dark:text-amber-50 dark:shadow-none',
    cellAdjacent:
      'border-amber-700/28 bg-amber-500/[0.10] text-amber-950/90 opacity-[0.88] dark:border-amber-600/30 dark:bg-amber-950/38 dark:text-amber-50/95',
    badge: 'bg-orange-600 text-white shadow-sm dark:bg-orange-500 dark:text-white',
    typePill: 'bg-amber-800/15 text-[10px] font-bold uppercase tracking-wide text-amber-950 dark:bg-amber-400/15 dark:text-amber-100',
    title:
      'Special non-working day — 130% if worked; typically no pay if unworked (check monthly vs daily rules).',
  },
  special_working: {
    shortLabel: 'Sp. Work',
    multiplier: '1.00×',
    legendDot: 'bg-slate-950 dark:bg-orange-300',
    cell:
      'border-slate-600/35 bg-slate-500/[0.10] text-slate-950 shadow-sm dark:border-slate-500/35 dark:bg-slate-950/45 dark:text-slate-50 dark:shadow-none',
    cellAdjacent:
      'border-slate-600/20 bg-slate-500/[0.06] text-slate-900/90 opacity-[0.88] dark:border-slate-600/20 dark:bg-slate-950/30 dark:text-slate-100/95',
    badge: 'bg-orange-600 text-white shadow-sm dark:bg-orange-500 dark:text-white',
    typePill: 'bg-slate-800/15 text-[10px] font-bold uppercase tracking-wide text-slate-900 dark:bg-slate-400/15 dark:text-slate-100',
    title:
      'Special working day — pay as ordinary day (no statutory holiday premium) unless policy/CBA adds benefits.',
  },
  company: {
    shortLabel: 'Company',
    multiplier: '—',
    legendDot: 'bg-orange-700',
    cell:
      'border-violet-600/40 bg-violet-500/[0.11] text-violet-950 shadow-sm dark:border-violet-500/40 dark:bg-violet-950/45 dark:text-violet-50 dark:shadow-none',
    cellAdjacent:
      'border-violet-600/22 bg-violet-500/[0.07] text-violet-950/90 opacity-[0.88] dark:border-violet-600/25 dark:bg-violet-950/32 dark:text-violet-100/95',
    badge: 'bg-orange-600 text-white shadow-sm dark:bg-orange-500 dark:text-white',
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

function HolidayInsightPanel({ nextHoliday, daysUntilNextHoliday, onViewList }) {
  const nextTypeMeta = nextHoliday?.type && HOLIDAY_TYPE_META[nextHoliday.type] ? HOLIDAY_TYPE_META[nextHoliday.type] : null

  return (
    <div className="space-y-4">
      <div className="max-w-full overflow-hidden rounded-3xl border border-border/70 bg-card p-4 shadow-[0_18px_45px_rgba(15,23,42,0.08)] ring-1 ring-border/25 dark:border-border/50 dark:bg-card/95 dark:shadow-none md:p-5">
        <div className="flex flex-wrap items-center gap-2">
          <span className="inline-flex items-center rounded-full bg-brand/10 px-2.5 py-1 text-[10px] font-black uppercase tracking-[0.12em] text-brand dark:bg-brand/15">
            Next holiday
          </span>
          {nextHoliday && daysUntilNextHoliday != null && (
            <span className="inline-flex items-center gap-1 rounded-full border border-border/70 bg-muted/35 px-2.5 py-1 text-[11px] font-bold tabular-nums text-muted-foreground dark:bg-muted/25">
              <Clock className="size-3 shrink-0 opacity-70" aria-hidden />
              {daysUntilNextHoliday === 0 ? 'Today' : `${daysUntilNextHoliday} day${daysUntilNextHoliday === 1 ? '' : 's'}`}
            </span>
          )}
        </div>

        <div className="mt-5 flex items-start gap-3">
          <div className="flex size-12 shrink-0 items-center justify-center rounded-2xl border border-brand/25 bg-brand/10 text-brand shadow-sm dark:bg-brand/15">
            <Calendar className="size-6" aria-hidden />
          </div>
          <div className="min-w-0 flex-1">
            {nextHoliday ? (
              <>
                <h3 className="wrap-break-word text-lg font-black tracking-tight text-foreground">{nextHoliday.name}</h3>
                <p className="mt-1 text-sm leading-relaxed text-muted-foreground">
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
                    'mt-3 rounded-full border px-2.5 py-1 text-[11px] font-bold',
                    nextHoliday.type === 'regular' &&
                      'border-teal-600/35 bg-teal-600/10 text-teal-800 dark:border-teal-500/35 dark:bg-teal-500/10 dark:text-teal-200',
                    nextHoliday.type === 'special' &&
                      'border-amber-600/35 bg-amber-500/10 text-amber-900 dark:border-amber-500/35 dark:bg-amber-500/10 dark:text-amber-100',
                    nextHoliday.type === 'special_working' &&
                      'border-slate-500/35 bg-slate-500/10 text-slate-700 dark:border-slate-400/30 dark:bg-white/5 dark:text-slate-200',
                    nextHoliday.type === 'company' &&
                      'border-violet-600/35 bg-violet-500/10 text-violet-800 dark:border-violet-500/35 dark:bg-violet-500/10 dark:text-violet-100'
                  )}
                >
                  {nextTypeMeta?.shortLabel || 'Holiday'} Holiday
                </Badge>
              </>
            ) : (
              <>
                <h3 className="text-lg font-black tracking-tight text-foreground">No upcoming holidays</h3>
                <p className="mt-1 text-sm leading-relaxed text-muted-foreground">Create a holiday or change filters to see schedule details.</p>
              </>
            )}
          </div>
        </div>

        <p className="mt-5 text-xs leading-relaxed text-muted-foreground">
          Tap a date on the calendar for pay rules and premium simulation.
        </p>
        <Button
          type="button"
          variant="outline"
          size="sm"
          className="mt-4 h-10 w-full gap-2 border-brand/35 bg-brand/5 font-bold text-brand hover:bg-brand/10 dark:border-brand/45 dark:bg-brand/10 dark:text-brand"
          onClick={onViewList}
        >
          <List className="size-4" />
          View full schedule
        </Button>
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
          <div key={i} className="flex min-h-22 bg-card p-2 dark:bg-card">
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

function holidayScopeLabel(holiday) {
  const scope = holiday?.scope || 'nationwide'
  const entries = Array.isArray(holiday?.holidays) ? holiday.holidays : []
  if (entries.length > 1) {
    const labels = entries
      .map((entry) => holidayTargetLabel(entry))
      .filter(Boolean)
      .filter((label, idx, list) => list.indexOf(label) === idx)
    const visible = labels.slice(0, 2).join(', ')
    const suffix = labels.length > 2 ? ` +${labels.length - 2}` : ''
    if (visible) return `${visible}${suffix}`
    if (scope === 'company') return `${entries.length} companies`
    if (scope === 'branch') return `${entries.length} branches`
    if (scope === 'department') return `${entries.length} departments`
    if (scope === 'employee') return `${entries.length} employees`
    return `${entries.length} scoped targets`
  }
  if (scope === 'company') return holiday.company_name || (holiday.company_id ? `Company #${holiday.company_id}` : 'Company')
  if (scope === 'branch') return holiday.branch_name || (holiday.branch_id ? `Branch #${holiday.branch_id}` : 'Branch')
  if (scope === 'department') return holiday.department_name || (holiday.department_id ? `Department #${holiday.department_id}` : 'Department')
  if (scope === 'employee') return holiday.employee_name || holiday.employee_code || (holiday.employee_id ? `Employee #${holiday.employee_id}` : 'Employee')
  if (scope === 'regional') return 'Selected regions'
  return 'All employees'
}

function holidayScopeTypeLabel(holiday) {
  const scope = holiday?.scope || 'nationwide'
  if (scope === 'company') return 'Company'
  if (scope === 'branch') return 'Branch'
  if (scope === 'department') return 'Department'
  if (scope === 'employee') return 'Employee'
  if (scope === 'regional') return 'Regional'
  return 'Nationwide'
}

function holidayTargetLabel(holiday) {
  if (!holiday) return 'Target'
  if (holiday.scope === 'employee') {
    return [holiday.employee_name || holiday.employee_code || `Employee #${holiday.employee_id}`, holiday.department_name, holiday.company_name]
      .filter(Boolean)
      .join(' · ')
  }
  if (holiday.scope === 'department') {
    return [holiday.department_name || `Department #${holiday.department_id}`, holiday.branch_name, holiday.company_name].filter(Boolean).join(' · ')
  }
  if (holiday.scope === 'branch') {
    return [holiday.branch_name || `Branch #${holiday.branch_id}`, holiday.company_name].filter(Boolean).join(' · ')
  }
  if (holiday.scope === 'company') return holiday.company_name || `Company #${holiday.company_id}`
  return holidayScopeLabel(holiday)
}

function targetLogoUrl(holiday) {
  return holiday?.company_logo_url || companyLogoUrl({ logo_url: holiday?.company_logo_url })
}

function groupHolidayRows(rows) {
  const groups = new Map()
  rows.forEach((holiday) => {
    const key = [
      holiday.date,
      holiday.name,
      holiday.type,
      holiday.scope || 'nationwide',
      holiday.description || '',
      holiday.status || 'active',
      holiday.source || 'custom',
    ].join('|')
    const existing = groups.get(key)
    if (existing) {
      existing.holidays.push(holiday)
      existing.company_ids = [...new Set([...existing.company_ids, holiday.company_id].filter((id) => id != null))]
      existing.branch_ids = [...new Set([...existing.branch_ids, holiday.branch_id].filter((id) => id != null))]
      existing.department_ids = [...new Set([...existing.department_ids, holiday.department_id].filter((id) => id != null))]
      existing.employee_ids = [...new Set([...existing.employee_ids, holiday.employee_id].filter((id) => id != null))]
      return
    }
    groups.set(key, {
      ...holiday,
      id: holiday.id,
      holidays: [holiday],
      company_ids: holiday.company_id != null ? [holiday.company_id] : [],
      branch_ids: holiday.branch_id != null ? [holiday.branch_id] : [],
      department_ids: holiday.department_id != null ? [holiday.department_id] : [],
      employee_ids: holiday.employee_id != null ? [holiday.employee_id] : [],
    })
  })

  return Array.from(groups.values()).map((group) => ({
    ...group,
    id: group.holidays.length === 1 ? group.id : undefined,
    company_logos: Array.from(new Set(group.holidays.map(targetLogoUrl).filter(Boolean))),
  }))
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
  const targetEntries = Array.isArray(holiday?.holidays) ? holiday.holidays : []
  const logos = Array.isArray(holiday?.company_logos) ? holiday.company_logos.slice(0, 3) : []

  return (
    <button
      type="button"
      title={tooltip}
      onClick={() => onSelect?.(cell)}
      className={cn(
        'touch-manipulation group relative flex h-full min-h-[4.65rem] w-full min-w-0 max-w-full flex-col rounded-2xl border p-2 text-left @sm:min-h-[5.9rem] @sm:p-2.5',
        'transition-all duration-200 ease-out focus:outline-none focus-visible:ring-[3px] focus-visible:ring-orange-500/35 focus-visible:ring-offset-2 ring-offset-background',
        'hover:-translate-y-0.5 hover:shadow-[0_14px_30px_rgba(15,23,42,0.10)] active:scale-[0.99] dark:hover:shadow-none',
        isAdjacent && !holiday && 'border-border/25 bg-muted/20 text-muted-foreground dark:border-border/25 dark:bg-background/30',
        isAdjacent && holiday && 'border-border/45 bg-card/70 text-foreground opacity-80 dark:bg-card/70',
        !isAdjacent && !holiday && 'border-slate-200 bg-white text-slate-950 shadow-sm dark:border-white/10 dark:bg-slate-950/80 dark:text-white',
        !isAdjacent && !holiday && 'hover:border-orange-500/45 hover:bg-white dark:hover:bg-slate-950',
        !isAdjacent && holiday && 'border-slate-200 bg-white text-slate-950 shadow-sm ring-1 ring-slate-950/5 dark:border-white/10 dark:bg-slate-950/95 dark:text-white dark:ring-white/10',
        isToday &&
          'ring-2 ring-orange-500/60 ring-offset-2 ring-offset-background dark:ring-orange-400/55',
        isSelected &&
          'z-2 scale-[1.02] border-orange-500 shadow-[0_0_0_3px_rgba(249,115,22,0.18)] dark:shadow-[0_0_0_3px_rgba(251,146,60,0.28)]',
        filteredOut && 'opacity-40'
      )}
    >
      <div className="flex items-start justify-between gap-1">
        <span
          className={cn(
            'text-[14px] font-black tabular-nums leading-none tracking-tight @sm:text-base',
            isAdjacent && !holiday && 'text-muted-foreground/80',
            holiday && meta && 'text-current/95 dark:text-current'
          )}
        >
          {isToday || isSelected ? (
            <span className="inline-flex min-w-7 items-center justify-center rounded-lg bg-orange-600 px-1.5 py-1 text-sm font-black text-white shadow-sm ring-1 ring-orange-300/70 @sm:text-base dark:bg-orange-500 dark:text-white">
              {day}
            </span>
          ) : (
            day
          )}
        </span>
        {isAdjacent && (
          <span className="shrink-0 rounded-md bg-muted/45 px-1 py-0.5 text-[9px] font-black uppercase tracking-wider text-muted-foreground dark:bg-background/35">
            {monthShort}
          </span>
        )}
        {holiday && meta && (
          <span className={cn('size-2.5 shrink-0 rounded-full shadow-sm ring-2 ring-orange-100 dark:ring-slate-950', meta.legendDot)} />
        )}
      </div>

      {holiday && meta ? (
        <div className="mt-2 flex min-h-0 min-w-0 flex-1 flex-col rounded-xl border border-slate-200 bg-white px-2 py-1.5 shadow-[inset_0_1px_0_rgba(255,255,255,0.70)] dark:border-white/10 dark:bg-slate-900/70 dark:shadow-none @sm:mt-2.5">
          <p className="line-clamp-2 wrap-break-word text-[10px] font-black leading-tight tracking-tight text-slate-950 dark:text-white @sm:text-[12px] @sm:leading-snug">
            {holiday.is_swap && <span className="mr-0.5 inline-block text-[9px] text-orange-600 dark:text-orange-300">↔</span>}
            {holiday.name}
          </p>
          <span className="mt-0.5 truncate text-[9px] font-bold leading-tight text-slate-500 dark:text-slate-300 @sm:text-[10px]">
            {holiday.is_swap ? 'Swap' : targetEntries.length > 1 ? `${targetEntries.length} targets` : meta.shortLabel}
          </span>
          <div className="mt-auto flex items-end justify-between gap-1 pt-1.5">
            {logos.length > 0 ? (
              <div className="flex -space-x-1 overflow-hidden">
                {logos.map((logo, logoIdx) => (
                  <img
                    key={`${logo}-${logoIdx}`}
                    src={logo}
                    alt=""
                    className="size-5 rounded-full border border-background bg-background object-cover @sm:size-6"
                    loading="lazy"
                  />
                ))}
              </div>
            ) : (
              <span />
            )}
            <span
              className={cn(
                'inline-flex min-h-5 min-w-8 shrink-0 items-center justify-center rounded-full px-1.5 py-0.5 text-[10px] font-black tabular-nums leading-none tracking-tight @sm:min-h-6 @sm:min-w-9 @sm:text-xs',
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

export default function AdminHoliday({ mode = 'admin' }) {
  const { user } = useAuth()
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
  const [swapModalOpen, setSwapModalOpen] = useState(false)
  const [swapModalMode, setSwapModalMode] = useState('create')
  const [swapModalId, setSwapModalId] = useState(null)
  const [swapModalInitial, setSwapModalInitial] = useState(null)
  const [swapDialogOpen, setSwapDialogOpen] = useState(false)
  const [swapTargetHoliday, setSwapTargetHoliday] = useState(null)
  const [swapDate, setSwapDate] = useState('')
  const [holidays, setHolidays] = useState([]) // Merged from API (includes custom DB holidays)
  const [loading, setLoading] = useState(true)

  const todayKey = useMemo(() => new Date().toISOString().slice(0, 10), [])
  const isEmployeeScoped = mode === 'employee'
  const permissions = useMemo(() => new Set(user?.permissions ?? []), [user?.permissions])
  const canCreateHoliday = permissions.has('holidays.create') || permissions.has('holidays.manage') || permissions.has('holiday.manage')
  const canUpdateHoliday = permissions.has('holidays.update') || permissions.has('holidays.manage') || permissions.has('holiday.manage')
  const canDeleteHoliday = permissions.has('holidays.delete') || permissions.has('holidays.manage') || permissions.has('holiday.manage')

  const refetchHolidays = useCallback(async (opts = {}) => {
    if (!opts.silent) setLoading(true)
    try {
      const res = isEmployeeScoped ? await getMyHolidays({ year }) : await getAdminHolidays({ year })
      setHolidays(res.holidays || [])
    } catch {
      setHolidays([])
    } finally {
      if (!opts.silent) setLoading(false)
    }
  }, [isEmployeeScoped, year])

  // Fetch holidays from API when year changes
  useEffect(() => {
    refetchHolidays()
  }, [refetchHolidays])

  // Keep impact cards fresh as roster, rates, or payroll daily records change elsewhere.
  useEffect(() => {
    const interval = window.setInterval(() => {
      refetchHolidays({ silent: true })
    }, 60000)

    return () => window.clearInterval(interval)
  }, [refetchHolidays])

  // Holidays from API (Time and Date API + custom DB)
  const allHolidays = useMemo(() => {
    const base = holidays.map((h) => ({ ...h, scope: h.scope || 'nationwide' }))
    return groupHolidayRows(base).sort((a, b) => a.date.localeCompare(b.date))
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

  const activeFilteredHolidays = useMemo(
    () => filteredHolidays.filter((h) => (h.status || 'active') === 'active'),
    [filteredHolidays]
  )

  const holidaysInViewMonth = useMemo(() => {
    return allHolidays.filter((h) => {
      if ((h.status || 'active') !== 'active') return false
      const d = new Date(`${h.date}T12:00:00`)
      return d.getFullYear() === year && d.getMonth() === month
    }).length
  }, [allHolidays, year, month])

  const holidayMap = useMemo(() => {
    const m = new Map()
    activeFilteredHolidays.forEach((h) => {
      if (!m.has(h.date)) m.set(h.date, h)
    })
    return m
  }, [activeFilteredHolidays])

  const calendarCells = useMemo(
    () => getCalendarCells(year, month, holidayMap),
    [year, month, holidayMap]
  )

  const upcomingHolidays = useMemo(() => {
    const now = new Date()
    return allHolidays
      .filter((h) => (h.status || 'active') === 'active' && new Date(h.date) >= now)
      .sort((a, b) => a.date.localeCompare(b.date))
      .slice(0, 8)
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

  const holidayOverridePayload = useCallback((holiday, patch = {}) => ({
    name: holiday.name,
    date: holiday.date || selectedCell?.dateStr,
    type: holiday.type,
    scope: holiday.scope || 'nationwide',
    company_id: holiday.company_id ?? undefined,
    branch_id: holiday.branch_id ?? undefined,
    department_id: holiday.department_id ?? undefined,
    employee_id: holiday.employee_id ?? undefined,
    regions: Array.isArray(holiday.regions) ? holiday.regions : undefined,
    description: holiday.description || undefined,
    is_recurring: Boolean(holiday.is_recurring),
    ...patch,
  }), [selectedCell?.dateStr])

  const handleDeleteHoliday = async (holidayOrId) => {
    if (!canDeleteHoliday) {
      toast.error('Permission denied', { description: 'You do not have permission to delete holidays.' })
      return
    }
    try {
      if (typeof holidayOrId === 'number') {
        await deleteAdminHoliday(holidayOrId)
      } else if (Array.isArray(holidayOrId?.holidays) && holidayOrId.holidays.length > 1) {
        await Promise.all(holidayOrId.holidays.map((entry) => (
          entry?.id ? deleteAdminHoliday(entry.id) : createAdminHoliday(holidayOverridePayload(entry, { status: 'inactive' }))
        )))
      } else if (holidayOrId?.id) {
        await deleteAdminHoliday(holidayOrId.id)
      } else if (holidayOrId) {
        await createAdminHoliday(holidayOverridePayload(holidayOrId, { status: 'inactive' }))
      }
      await refetchHolidays({ silent: true })
      setSelectedCell(null)
      toast.success('Holiday deleted successfully')
    } catch (err) {
      const msg = err.message || 'Failed to delete holiday'
      toast.error('Failed to delete holiday', { description: msg })
    }
  }

  const handleSwapHoliday = async (holiday) => {
    if (!canUpdateHoliday) {
      toast.error('Permission denied', { description: 'You do not have permission to update holidays.' })
      return
    }
    if (!holiday) return
    const nextDate = swapDate
    if (!/^\d{4}-\d{2}-\d{2}$/.test(nextDate || '')) {
      toast.error('Invalid date', { description: 'Use YYYY-MM-DD format.' })
      return
    }
    try {
      if (Array.isArray(holiday?.holidays) && holiday.holidays.length > 1) {
        await Promise.all(holiday.holidays.map((entry) => {
          if (entry?.id) return swapAdminHoliday(entry.id, { date: nextDate })
          return swapSeededAdminHoliday(holidayOverridePayload(entry, { new_date: nextDate }))
        }))
      } else if (holiday.id) {
        await swapAdminHoliday(holiday.id, { date: nextDate })
      } else {
        await swapSeededAdminHoliday(holidayOverridePayload(holiday, { new_date: nextDate }))
      }
      await refetchHolidays({ silent: true })
      setSelectedCell(null)
      setSwapDialogOpen(false)
      setSwapTargetHoliday(null)
      setSwapDate('')
      toast.success('Holiday swapped', { description: `Moved to ${nextDate}` })
    } catch (err) {
      const msg = err.message || 'Failed to swap holiday'
      toast.error('Failed to swap holiday', { description: msg })
    }
  }

  const openSwapDialog = useCallback((holiday) => {
    const initialDate = holiday?.date || selectedCell?.dateStr || ''
    setSwapTargetHoliday(holiday)
    setSwapDate(initialDate)
    setSwapDialogOpen(true)
  }, [selectedCell?.dateStr])

  const openHolidayCreate = useCallback((prefill = {}) => {
    if (!canCreateHoliday) {
      toast.error('Permission denied', { description: 'You do not have permission to create holidays.' })
      return
    }
    setHolidayModalMode('create')
    setHolidayModalId(null)
    setHolidayModalInitial(Object.keys(prefill).length ? prefill : null)
    setHolidayModalOpen(true)
  }, [canCreateHoliday])

  const openHolidayEdit = useCallback((holiday) => {
    if (!canUpdateHoliday) {
      toast.error('Permission denied', { description: 'You do not have permission to update holidays.' })
      return
    }
    if (!holiday?.id || typeof holiday.id !== 'number') return
    setHolidayModalMode('edit')
    setHolidayModalId(holiday.id)
    setHolidayModalInitial(holiday)
    setHolidayModalOpen(true)
    setSelectedCell(null)
  }, [canUpdateHoliday])

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
      className="min-w-0 max-w-full space-y-5 overflow-x-hidden @sm:space-y-6"
      initial={{ opacity: 0, y: 10 }}
      animate={{ opacity: 1, y: 0 }}
      transition={{ duration: 0.25, ease: [0.23, 1, 0.32, 1] }}
    >
      <div className="flex flex-col gap-2 @sm:flex-row @sm:items-start @sm:justify-between">
        <div>
          <h2 className="text-3xl font-black tracking-tight text-foreground">Holidays</h2>
          <CardDescription className="mt-1 text-sm text-muted-foreground">
            {isEmployeeScoped
              ? 'Your applicable company, branch, division, department, section/unit, and employee observances.'
              : 'Company, branch, department, and employee observances. Premium pay follows DOLE rules and your payroll policy.'}
          </CardDescription>
        </div>
      </div>

      <HolidayLegendBar />

      <div className="flex min-w-0 flex-col gap-4 @sm:gap-6 @lg:flex-row @lg:items-start @lg:gap-6">
        <div className="min-w-0 flex-1">
        <Card className="overflow-hidden rounded-3xl border border-border/70 bg-card shadow-[0_18px_55px_rgba(15,23,42,0.08)] dark:border-border/50 dark:bg-card/95 dark:shadow-none">
            <CardHeader className="border-b border-border/50 bg-card px-4 py-5 dark:bg-card/95 md:px-6">
              <CardTitle className="text-xl font-black tracking-tight">Calendar &amp; list</CardTitle>
              <CardDescription>Browse by month or use a filtered list view.</CardDescription>
            </CardHeader>
            <CardContent className="p-0">
              {loading ? (
                <div className="p-4 md:p-6">
                  <HolidaySkeleton />
                </div>
              ) : (
                <AnimatedSection staggerChildren={0.06} duration={0.5}>
                  <div className="bg-card px-4 py-4 dark:bg-card/95 md:px-6">
                    <div className="flex flex-col gap-4">
                      {/* Row 1: View mode — full width on narrow screens */}
                      <div className="flex w-full min-w-0 justify-center @sm:justify-start">
                        <div className="inline-flex rounded-full border border-border/60 bg-muted/30 p-1 dark:border-border/50 dark:bg-muted/30">
                          <button
                            type="button"
                            onClick={() => setActiveView('calendar')}
                            className={cn(
                              'flex items-center gap-1.5 rounded-full px-3 py-2 text-sm font-semibold transition-colors @sm:gap-2 @sm:px-4',
                              activeView === 'calendar'
                                ? 'border border-brand/25 bg-brand text-brand-foreground shadow-sm'
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
                                ? 'border border-brand/25 bg-brand text-brand-foreground shadow-sm'
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
                        <div className="flex w-full min-w-0 items-center justify-center gap-0.5 rounded-2xl border border-border/60 bg-background/70 p-1 dark:bg-background/35">
                          <Button
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
                            {MONTHS[month]} {year}
                          </button>
                          <Button
                            variant="ghost"
                            size="icon"
                            className="size-9 shrink-0 rounded-xl hover:bg-brand/8 @sm:size-10"
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
                          placeholder="Search holidays..."
                          className="h-11 w-full min-w-0 rounded-xl border-border/60 bg-background/70 pl-9 text-sm shadow-sm dark:bg-background/35"
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
                                'rounded-full border px-3 py-1.5 text-xs font-bold transition-colors @sm:px-3.5',
                                typeFilter === value
                                  ? 'border-brand bg-brand text-brand-foreground shadow-sm'
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
                              <Button variant="outline" size="sm" className="h-11 shrink-0 gap-2 rounded-xl border-border/70 bg-background/70 font-bold shadow-sm dark:bg-background/35">
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
                          {canCreateHoliday && (
                            <Button
                              onClick={() => openHolidayCreate()}
                              className="h-11 min-w-0 shrink-0 gap-2 rounded-xl bg-brand px-3 font-bold text-brand-foreground shadow-sm hover:bg-brand-strong @sm:px-4"
                            >
                              <Plus className="size-4 shrink-0" />
                              <span className="truncate">Add Holiday</span>
                            </Button>
                          )}
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
                <div className="space-y-3 px-4 pb-5 md:px-6 md:pb-6">
                  <div className="flex flex-wrap items-center justify-between gap-2 px-0 text-xs @sm:text-sm">
                    <p className="font-semibold text-brand">
                      <span className="font-black tabular-nums text-brand">{holidaysInViewMonth}</span>
                      <span className="mx-1">holiday{holidaysInViewMonth === 1 ? '' : 's'}</span>
                      <span className="text-muted-foreground/90">in {MONTHS[month]} {year}</span>
                    </p>
                    {holidaySearch.trim() && (
                      <span className="text-xs font-medium text-muted-foreground">Search filters the calendar</span>
                    )}
                  </div>
                  <div className="w-full min-w-0 overflow-x-auto @sm:overflow-x-visible">
                    <div className="grid w-full min-w-[280px] grid-cols-7 grid-rows-[auto_repeat(6,minmax(4.25rem,1fr))] gap-2 @sm:min-w-0 @sm:grid-rows-[auto_repeat(6,minmax(5.25rem,1fr))]">
                  {WEEKDAYS.map((w) => (
                    <div
                      key={w}
                      className="min-w-0 rounded-xl bg-muted/35 px-0.5 py-2 text-center text-[9px] font-black uppercase leading-tight tracking-wide text-muted-foreground @sm:px-2 @sm:py-2.5 @sm:text-[11px] @sm:tracking-wider"
                    >
                      {w}
                    </div>
                  ))}
                  {calendarCells.map((cell, idx) => (
                    <div key={idx} className="flex min-h-17 min-w-0 @sm:min-h-21">
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
                          key={h.id || `${h.date}-${h.name}-${h.scope}-${rowIdx}`}
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
                          <td className="px-3 py-3 align-middle">
                            <div className="min-w-0">
                              <p className="wrap-break-word font-medium text-foreground">{holidayScopeLabel(h)}</p>
                              <p className="mt-0.5 text-[11px] font-semibold uppercase tracking-wide text-muted-foreground">
                                {holidayScopeTypeLabel(h)}
                              </p>
                            </div>
                          </td>
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
        onSaved={async () => {
          await refetchHolidays({ silent: true })
        }}
      />

      <SwapHolidayModal
        open={swapModalOpen}
        onOpenChange={(o) => {
          setSwapModalOpen(o)
          if (!o) {
            setSwapModalId(null)
            setSwapModalInitial(null)
          }
        }}
        mode={swapModalMode}
        editingId={swapModalId}
        initial={swapModalInitial}
        onSaved={async () => {
          await refetchHolidays({ silent: true })
        }}
      />

      {/* Date click → Detail panel (premium SaaS modal) */}
      <Sheet open={!!selectedCell} onOpenChange={(open) => !open && setSelectedCell(null)}>
        <SheetContent
          side="right"
          className={cn('h-full max-h-dvh w-[min(100vw-0.5rem,38rem)] gap-0 p-0 sm:w-[min(100vw-1rem,42rem)] sm:max-w-none', AGCTEK_BRAND.panel)}
          showCloseButton={false}
        >
          {selectedCell?.holiday ? (
            <div className="flex h-full min-h-0 flex-col">
              <div className="relative overflow-hidden border-b border-slate-950/8 bg-white px-6 py-6 pr-5 dark:border-orange-500/15 dark:bg-slate-950">
                <div className="pointer-events-none absolute -right-14 -top-16 size-36 rounded-full bg-orange-500/10 blur-2xl" />
                <div className="relative flex shrink-0 items-start justify-between gap-4">
                  <div className="flex min-w-0 flex-1 items-start gap-4">
                    {targetLogoUrl((Array.isArray(selectedCell.holiday.holidays) && selectedCell.holiday.holidays[0]) || selectedCell.holiday) ? (
                      <img
                        src={targetLogoUrl((Array.isArray(selectedCell.holiday.holidays) && selectedCell.holiday.holidays[0]) || selectedCell.holiday)}
                        alt=""
                        className="size-16 shrink-0 rounded-full border-4 border-orange-100 bg-white object-cover shadow-sm dark:border-orange-500/20 dark:bg-slate-900"
                        loading="lazy"
                      />
                    ) : (
                      <div className="flex size-16 shrink-0 items-center justify-center rounded-full border-4 border-orange-100 bg-orange-50 text-xl font-black text-orange-700 shadow-sm dark:border-orange-500/20 dark:bg-orange-500/10 dark:text-orange-300">
                        {(selectedCell.holiday.name || 'H').slice(0, 1).toUpperCase()}
                      </div>
                    )}
                    <div className="min-w-0 flex-1 pt-0.5">
                      <div className="mb-1.5 flex flex-wrap items-center gap-1.5">
                        <Badge
                          variant="outline"
                          className={cn(
                            'rounded-full px-2 py-0.5 text-[10px] font-black leading-tight',
                            AGCTEK_BRAND.badge
                          )}
                        >
                          {selectedCell.holiday.type === 'regular'
                            ? 'Regular'
                            : selectedCell.holiday.type === 'special'
                              ? 'Special'
                              : selectedCell.holiday.type === 'special_working'
                                ? 'Sp. work'
                                : 'Company'}
                        </Badge>
                        <span className="truncate text-xs font-semibold text-slate-500 dark:text-slate-300">
                          {holidayTargetLabel((Array.isArray(selectedCell.holiday.holidays) && selectedCell.holiday.holidays[0]) || selectedCell.holiday)}
                        </span>
                      </div>
                      <h2 className="wrap-break-word text-2xl font-black leading-tight tracking-tight text-slate-950 dark:text-white">{selectedCell.holiday.name}</h2>
                      <p className="mt-1.5 flex items-center gap-1.5 text-sm font-semibold text-slate-500 dark:text-slate-300">
                        <Calendar className="size-4 shrink-0 text-slate-400" aria-hidden />
                        {new Date(selectedCell.dateStr).toLocaleDateString('en-PH', { weekday: 'short', month: 'short', day: 'numeric', year: 'numeric' })}
                      </p>
                    </div>
                  </div>
                  <button
                    type="button"
                    onClick={() => setSelectedCell(null)}
                    className="flex size-11 shrink-0 items-center justify-center rounded-2xl border border-slate-950/10 bg-white text-slate-950 shadow-sm transition-colors hover:border-orange-500/35 hover:bg-orange-50 hover:text-orange-700 dark:border-white/10 dark:bg-slate-900 dark:text-white dark:hover:bg-orange-500/10 dark:hover:text-orange-300"
                    aria-label="Close"
                  >
                    <X className="size-5" />
                  </button>
                </div>
              </div>

              <div className="min-h-0 flex-1 overflow-y-auto overscroll-y-contain bg-white px-6 py-5 [scrollbar-gutter:stable] dark:bg-slate-950">
                <div className="space-y-5">
                  <section className={AGCTEK_BRAND.section}>
                    <h3 className={AGCTEK_BRAND.sectionTitle}>Description</h3>
                    <p className="text-sm leading-relaxed text-slate-800 dark:text-slate-100/90">
                      {selectedCell.holiday.type === 'company'
                        ? (selectedCell.holiday.description || `Company event for ${holidayScopeLabel(selectedCell.holiday).toLowerCase()}. No DOLE premium by default.`)
                        : (HOLIDAY_DESCRIPTIONS[selectedCell.holiday.name] || 'Philippine holiday per DOLE guidelines. Premium pay depends on work conditions.')}
                    </p>
                  </section>

                  {selectedCell.holiday.is_swap && (
                    <section className="rounded-2xl border border-slate-200 bg-white p-4 text-sm text-slate-800 shadow-sm dark:border-white/10 dark:bg-slate-900/85 dark:text-slate-100">
                      <h3 className="mb-2 flex items-center gap-1.5 text-[10px] font-black uppercase tracking-[0.18em] text-orange-700 dark:text-orange-300">
                        <ArrowRightLeft className="size-3" /> Swap Holiday
                      </h3>
                      <p className="leading-snug">
                        Coverage: <strong>{selectedCell.holiday.coverage_type || 'company'}</strong>
                        {selectedCell.holiday.original_date && (
                          <span className="ml-1 text-muted-foreground">(moved from {selectedCell.holiday.original_date})</span>
                        )}
                      </p>
                      {Array.isArray(selectedCell.holiday.coverage_ids) && selectedCell.holiday.coverage_ids.length > 0 && (
                        <p className="mt-1 text-muted-foreground">
                          {selectedCell.holiday.coverage_ids.length} {selectedCell.holiday.coverage_type === 'employees' ? 'employees' : selectedCell.holiday.coverage_type === 'departments' ? 'departments' : selectedCell.holiday.coverage_type === 'branches' ? 'branches' : 'companies'} covered
                        </p>
                      )}
                      <Button
                        variant="outline"
                        size="sm"
                        className="mt-3 h-8 gap-1 rounded-xl border-orange-500/30 px-3 text-xs font-black text-orange-700 hover:bg-orange-500/10 dark:text-orange-300"
                        onClick={() => {
                          setSwapModalMode('edit')
                          setSwapModalId(selectedCell.holiday.id)
                          setSwapModalInitial(selectedCell.holiday)
                          setSwapModalOpen(true)
                          setSelectedCell(null)
                        }}
                      >
                        <Pencil className="size-3" /> Edit Swap
                      </Button>
                    </section>
                  )}

                  {selectedCell.holiday.type === 'special_working' && (
                    <section className={cn(AGCTEK_BRAND.section, 'text-sm text-slate-800 dark:text-slate-100/90')}>
                      <h3 className={AGCTEK_BRAND.sectionTitle}>Pay rates</h3>
                      <p className="leading-relaxed">
                        Treated as <strong>ordinary working days</strong> for statutory holiday premium. Follow your payroll policy for OT/rest day.
                      </p>
                    </section>
                  )}

                  {Array.isArray(selectedCell.holiday.holidays) && selectedCell.holiday.holidays.length > 0 && (
                    <section>
                      <h3 className={AGCTEK_BRAND.sectionTitle}>
                        Coverage ({selectedCell.holiday.holidays.length})
                      </h3>
                      <div className="max-h-[min(32vh,12.5rem)] space-y-2 overflow-y-auto rounded-2xl border border-slate-200 bg-white p-2 shadow-sm dark:border-white/10 dark:bg-slate-900/85">
                        {selectedCell.holiday.holidays.map((entry) => {
                          const logo = targetLogoUrl(entry)
                          return (
                            <div
                              key={entry.id || `${entry.scope}-${holidayTargetLabel(entry)}`}
                              className="flex items-start gap-3 rounded-xl border border-slate-200 bg-white px-3 py-2 dark:border-white/10 dark:bg-slate-950/60"
                            >
                              {logo ? (
                                <img src={logo} alt="" className="size-6 shrink-0 rounded-full border border-background object-cover" loading="lazy" />
                              ) : (
                                <div className="flex size-7 shrink-0 items-center justify-center rounded-full bg-orange-500/10 text-[10px] font-black text-orange-700 dark:text-orange-300">
                                  {(entry.company_name || entry.branch_name || entry.department_name || entry.employee_name || 'H').slice(0, 1).toUpperCase()}
                                </div>
                              )}
                              <div className="min-w-0 flex-1">
                                <p className="wrap-break-word text-xs font-black leading-snug text-slate-950 dark:text-white">{holidayTargetLabel(entry)}</p>
                                <p className="text-[10px] font-bold capitalize leading-tight text-orange-700 dark:text-orange-300">{entry.scope || 'nationwide'}</p>
                              </div>
                            </div>
                          )
                        })}
                      </div>
                    </section>
                  )}

                  {(selectedCell.holiday.type === 'regular' || selectedCell.holiday.type === 'special') && (
                    <section>
                      <h3 className={AGCTEK_BRAND.sectionTitle}>Pay rates</h3>
                      <div className="overflow-hidden rounded-2xl border border-slate-200 bg-white shadow-sm dark:border-white/10 dark:bg-slate-900/85">
                        <table className="w-full text-sm">
                          <tbody>
                            {selectedCell.holiday.type === 'regular' ? (
                              <>
                                <tr className="border-b border-slate-200 bg-slate-50/70 dark:border-white/10 dark:bg-white/5">
                                  <td className="px-4 py-3 font-black text-slate-950 dark:text-white"><Clock className="mr-3 inline size-4 text-orange-600 dark:text-orange-300" />Worked</td>
                                  <td className="px-4 py-3 text-right font-black tabular-nums text-slate-950 dark:text-white">2.00×</td>
                                </tr>
                                <tr className="border-b border-slate-200 bg-slate-50/70 dark:border-white/10 dark:bg-white/5">
                                  <td className="px-4 py-3 font-black text-slate-950 dark:text-white"><CalendarDays className="mr-3 inline size-4 text-orange-600 dark:text-orange-300" />Rest day</td>
                                  <td className="px-4 py-3 text-right font-black tabular-nums text-slate-950 dark:text-white">2.60×</td>
                                </tr>
                                <tr className="border-b border-slate-200 bg-slate-50/70 dark:border-white/10 dark:bg-white/5">
                                  <td className="px-4 py-3 font-black text-slate-950 dark:text-white"><Zap className="mr-3 inline size-4 text-orange-600 dark:text-orange-300" />Overtime</td>
                                  <td className="px-4 py-3 text-right font-black tabular-nums text-slate-950 dark:text-white">3.38×</td>
                                </tr>
                                <tr>
                                  <td className="px-4 py-3 font-bold text-slate-500 dark:text-slate-300"><X className="mr-3 inline size-4 text-slate-400" />Not worked</td>
                                  <td className="px-4 py-3 text-right font-semibold tabular-nums text-slate-500 dark:text-slate-300">100%</td>
                                </tr>
                              </>
                            ) : (
                              <>
                                <tr className="border-b border-slate-200 bg-slate-50/70 dark:border-white/10 dark:bg-white/5">
                                  <td className="px-4 py-3 font-black text-slate-950 dark:text-white"><Clock className="mr-3 inline size-4 text-orange-600 dark:text-orange-300" />Worked</td>
                                  <td className="px-4 py-3 text-right font-black tabular-nums text-slate-950 dark:text-white">1.30×</td>
                                </tr>
                                <tr>
                                  <td className="px-4 py-3 font-bold text-slate-500 dark:text-slate-300"><X className="mr-3 inline size-4 text-slate-400" />Not worked</td>
                                  <td className="px-4 py-3 text-right font-semibold tabular-nums text-slate-500 dark:text-slate-300">No pay</td>
                                </tr>
                              </>
                            )}
                          </tbody>
                        </table>
                      </div>
                    </section>
                  )}

                  <section>
                    <h3 className={AGCTEK_BRAND.sectionTitle}>Impact</h3>
                    <div className="space-y-3 rounded-2xl border border-slate-950/10 bg-white p-4 shadow-sm dark:border-orange-500/15 dark:bg-slate-900/85">
                      {selectedCell.holiday.type === 'company' ? (
                        <>
                          <p className="flex items-start gap-3 text-sm font-semibold text-slate-600 dark:text-slate-300">
                            <span className="mt-1.5 size-2 shrink-0 rounded-full bg-orange-500/70" />
                            No payroll premium applied
                          </p>
                          <p className="flex items-start gap-3 text-sm font-semibold text-slate-600 dark:text-slate-300">
                            <span className="mt-1.5 size-2 shrink-0 rounded-full bg-orange-500/70" />
                            Internal observance only
                          </p>
                        </>
                      ) : selectedCell.holiday.type === 'special_working' ? (
                        <>
                          <p className="flex items-start gap-3 text-sm font-semibold text-slate-950 dark:text-white">
                            <Users className="mt-0.5 size-4 shrink-0 text-orange-600 dark:text-orange-300" />
                            Ordinary rates (no statutory holiday premium)
                          </p>
                          <p className="flex items-start gap-3 text-sm font-semibold text-slate-600 dark:text-slate-300">
                            <Zap className="mt-0.5 size-4 shrink-0 text-orange-600 dark:text-orange-300" />
                            Confirm proclamation &amp; CBA
                          </p>
                        </>
                      ) : (
                        <>
                          <p className="flex items-start gap-3 text-sm font-semibold text-slate-950 dark:text-white">
                            <Users className="mt-0.5 size-4 shrink-0 text-orange-600 dark:text-orange-300" />
                            Affects employees in scope
                          </p>
                          <p className="flex items-start gap-3 text-sm font-semibold text-slate-950 dark:text-white">
                            <Zap className="mt-0.5 size-4 shrink-0 text-orange-600 dark:text-orange-300" />
                            {selectedCell.holiday.type === 'regular' ? 'High OT probability' : 'Standard premium rate'}
                          </p>
                        </>
                      )}
                    </div>
                  </section>
                </div>
              </div>

              <div className="sticky bottom-0 z-10 shrink-0 border-t border-slate-950/10 bg-white/95 px-6 py-4 pb-[max(1rem,env(safe-area-inset-bottom))] backdrop-blur supports-backdrop-filter:bg-white/90 dark:border-orange-500/15 dark:bg-slate-950/95">
                {selectedCell.holiday && (
                  <div className="grid grid-cols-3 gap-3">
                    {canUpdateHoliday && typeof selectedCell.holiday.id === 'number' && (
                      <Button
                        size="sm"
                        className="h-11 gap-2 rounded-xl bg-orange-600 px-3 text-sm font-black text-white shadow-sm hover:bg-orange-700"
                        onClick={() => openHolidayEdit({ ...selectedCell.holiday, date: selectedCell.dateStr })}
                      >
                        <Pencil className="size-4" />
                        Edit
                      </Button>
                    )}
                    {canUpdateHoliday && (
                      <Button
                        variant="outline"
                        size="sm"
                        className="h-11 gap-2 rounded-xl border-slate-950/30 px-3 text-sm font-black text-slate-950 hover:border-orange-500/40 hover:bg-orange-50 dark:border-white/30 dark:text-white dark:hover:bg-orange-500/10"
                        onClick={() => openSwapDialog({ ...selectedCell.holiday, date: selectedCell.dateStr })}
                      >
                        <RotateCcw className="size-4" />
                        Swap
                      </Button>
                    )}
                    {canDeleteHoliday && (
                      <Button
                        variant="outline"
                        size="sm"
                        className="h-11 gap-2 rounded-xl border-rose-300 px-3 text-sm font-black text-rose-600 hover:bg-rose-50 dark:border-rose-700 dark:text-rose-300 dark:hover:bg-rose-950/30"
                        onClick={() => handleDeleteHoliday({ ...selectedCell.holiday, date: selectedCell.dateStr })}
                      >
                        <Trash2 className="size-4" />
                        Delete
                      </Button>
                    )}
                  </div>
                )}
                <Button
                  variant="outline"
                  size="sm"
                  className="mt-3 h-10 w-full rounded-xl border-slate-950/35 text-sm font-black text-slate-950 hover:bg-slate-50 dark:border-white/25 dark:text-white dark:hover:bg-white/5"
                  onClick={() => setSelectedCell(null)}
                >
                  Close
                </Button>
              </div>
            </div>
          ) : selectedCell ? (
            <div className="flex h-full min-h-0 flex-col">
              <div className="flex shrink-0 items-start justify-between gap-2 border-b border-border/60 px-3 py-2.5">
                <div className="flex min-w-0 items-center gap-2">
                  <div className="flex size-8 shrink-0 items-center justify-center rounded-lg bg-muted/50">
                    <Calendar className="size-4 text-muted-foreground" />
                  </div>
                  <div className="min-w-0">
                    <h2 className="text-sm font-bold leading-snug text-foreground">
                      {new Date(selectedCell.dateStr).toLocaleDateString('en-PH', { weekday: 'short', month: 'short', day: 'numeric', year: 'numeric' })}
                    </h2>
                    <p className="text-[11px] text-muted-foreground">No holiday on this date</p>
                  </div>
                </div>
                <button
                  type="button"
                  onClick={() => setSelectedCell(null)}
                  className="flex size-8 shrink-0 items-center justify-center rounded-md text-muted-foreground transition-colors hover:bg-muted hover:text-foreground"
                  aria-label="Close"
                >
                  <X className="size-4" />
                </button>
              </div>
              <div className="min-h-0 flex-1 overflow-y-auto px-3 py-3">
                <div className="rounded-lg border border-dashed border-border/60 bg-muted/20 py-5 dark:border-border/50 dark:bg-muted/15">
                  <p className="mb-3 px-2 text-center text-xs text-muted-foreground">
                    {canCreateHoliday ? 'Add a holiday on this date' : 'No holiday on this date'}
                  </p>
                  {canCreateHoliday && (
                    <Button
                      size="sm"
                      className="mx-auto flex h-8 gap-1.5 text-xs"
                      onClick={() => {
                        openHolidayCreate({ date: selectedCell.dateStr })
                        setSelectedCell(null)
                      }}
                    >
                      <Plus className="size-3.5" />
                      Add Holiday
                    </Button>
                  )}
                </div>
              </div>
              <div className="sticky bottom-0 z-10 shrink-0 border-t border-border/60 bg-card/95 px-3 py-2.5 pb-[max(0.5rem,env(safe-area-inset-bottom))] backdrop-blur supports-backdrop-filter:bg-card/90">
                <Button variant="secondary" size="sm" className="h-8 w-full text-xs" onClick={() => setSelectedCell(null)}>
                  Close
                </Button>
              </div>
            </div>
          ) : null}
        </SheetContent>
      </Sheet>

      <Dialog
        open={swapDialogOpen}
        onOpenChange={(open) => {
          setSwapDialogOpen(open)
          if (!open) {
            setSwapTargetHoliday(null)
            setSwapDate('')
          }
        }}
      >
        <DialogContent className="sm:max-w-md">
          <DialogHeader>
            <DialogTitle>Swap holiday date</DialogTitle>
          </DialogHeader>
          <div className="space-y-2">
            <p className="text-sm text-muted-foreground">
              Move <span className="font-semibold text-foreground">{swapTargetHoliday?.name || 'holiday'}</span> to:
            </p>
            <Input
              type="date"
              value={swapDate}
              onChange={(e) => setSwapDate(e.target.value)}
            />
          </div>
          <DialogFooter>
            <Button
              variant="outline"
              onClick={() => {
                setSwapDialogOpen(false)
                setSwapTargetHoliday(null)
                setSwapDate('')
              }}
            >
              Cancel
            </Button>
            <Button
              onClick={() => handleSwapHoliday(swapTargetHoliday)}
            >
              Confirm swap
            </Button>
          </DialogFooter>
        </DialogContent>
      </Dialog>
    </Motion.div>
  )
}
  
