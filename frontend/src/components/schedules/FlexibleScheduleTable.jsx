import { useMemo, useState } from 'react'
import { Copy, ChevronDown } from 'lucide-react'
import { Button } from '@/components/ui/button'
import { Input } from '@/components/ui/input'
import { Checkbox } from '@/components/ui/checkbox'
import {
  DropdownMenu,
  DropdownMenuContent,
  DropdownMenuItem,
  DropdownMenuTrigger,
} from '@/components/ui/dropdown-menu'
import {
  detectCrossesMidnight,
  formatPaidHours,
  halfDayThresholdMinutes,
  netShiftMinutes,
} from '@/lib/scheduleLib'
import { toHhMm } from '@/lib/timeFormat'

const DAY_ROWS = [
  { key: 'mon', label: 'Monday' },
  { key: 'tue', label: 'Tuesday' },
  { key: 'wed', label: 'Wednesday' },
  { key: 'thu', label: 'Thursday' },
  { key: 'fri', label: 'Friday' },
  { key: 'sat', label: 'Saturday' },
  { key: 'sun', label: 'Sunday' },
]

const WEEKDAY_KEYS = ['mon', 'tue', 'wed', 'thu', 'fri']

const DEFAULTS = {
  grace_period_minutes: 5,
  early_timein_minutes: 60,
  overtime_buffer_minutes: 15,
  expected_paid_minutes: '',
  half_day_threshold_minutes: '',
}

function dayPaidMinutes(row) {
  if (!row?.is_working_day || !row.time_in || !row.time_out) return 0
  if (row.expected_paid_minutes !== '' && row.expected_paid_minutes != null && Number(row.expected_paid_minutes) > 0) {
    return Number(row.expected_paid_minutes)
  }
  return netShiftMinutes(row.time_in, row.time_out, row.break_start, row.break_end, [])
}

function cloneDay(source) {
  return {
    ...source,
    time_in: source.time_in || '',
    time_out: source.time_out || '',
    break_start: source.break_start || '',
    break_end: source.break_end || '',
    expected_paid_minutes: source.expected_paid_minutes ?? '',
    half_day_threshold_minutes: source.half_day_threshold_minutes ?? '',
    grace_period_minutes: source.grace_period_minutes ?? DEFAULTS.grace_period_minutes,
    early_timein_minutes: source.early_timein_minutes ?? DEFAULTS.early_timein_minutes,
    overtime_buffer_minutes: source.overtime_buffer_minutes ?? DEFAULTS.overtime_buffer_minutes,
    crosses_midnight: !!source.crosses_midnight,
  }
}

function blankWorkingFields() {
  return {
    time_in: '',
    time_out: '',
    break_start: '',
    break_end: '',
    expected_paid_minutes: '',
    half_day_threshold_minutes: '',
    grace_period_minutes: DEFAULTS.grace_period_minutes,
    early_timein_minutes: DEFAULTS.early_timein_minutes,
    overtime_buffer_minutes: DEFAULTS.overtime_buffer_minutes,
    crosses_midnight: false,
  }
}

function numOrEmpty(value) {
  return value === '' || value == null ? '' : Number(value)
}

export function FlexibleScheduleTable({ days, setDays, readOnly = false }) {
  const [copyTargets, setCopyTargets] = useState(() => new Set())

  const dayMap = useMemo(() => {
    const map = new Map()
    for (const row of days || []) {
      map.set(row.day_of_week, row)
    }
    return map
  }, [days])

  function updateDay(dayKey, patch) {
    setDays((current) => current.map((row) => {
      if (row.day_of_week !== dayKey) return row
      const next = { ...row, ...patch }
      if ('time_in' in patch || 'time_out' in patch) {
        next.crosses_midnight = detectCrossesMidnight(next.time_in, next.time_out)
      }
      if (patch.is_working_day === false) {
        return { ...next, ...blankWorkingFields() }
      }
      if (patch.is_working_day === true && !row.is_working_day) {
        return {
          ...next,
          time_in: next.time_in || '08:00',
          time_out: next.time_out || '17:00',
          break_start: next.break_start || '12:00',
          break_end: next.break_end || '13:00',
          grace_period_minutes: next.grace_period_minutes ?? DEFAULTS.grace_period_minutes,
          early_timein_minutes: next.early_timein_minutes ?? DEFAULTS.early_timein_minutes,
          overtime_buffer_minutes: next.overtime_buffer_minutes ?? DEFAULTS.overtime_buffer_minutes,
        }
      }
      return next
    }))
  }

  function applyCopy(sourceKey, targetKeys) {
    const source = dayMap.get(sourceKey)
    if (!source?.is_working_day) return
    const payload = cloneDay(source)
    setDays((current) => current.map((row) => (
      targetKeys.includes(row.day_of_week)
        ? { ...row, ...payload, day_of_week: row.day_of_week, is_working_day: true }
        : row
    )))
  }

  function toggleCopyTarget(dayKey) {
    setCopyTargets((prev) => {
      const next = new Set(prev)
      if (next.has(dayKey)) next.delete(dayKey)
      else next.add(dayKey)
      return next
    })
  }

  return (
    <div className="space-y-3">
      <div className="flex flex-wrap items-center justify-between gap-2">
        <div>
          <p className="text-sm font-medium">Weekly schedule</p>
          <p className="text-xs text-muted-foreground">
            Each weekday uses its own fixed-style shift pattern (paid hours, half-day, grace, early time-in, OT buffer).
          </p>
        </div>
        {!readOnly && (
          <DropdownMenu>
            <DropdownMenuTrigger asChild>
              <Button type="button" variant="outline" size="sm" className="h-9 gap-1.5">
                <Copy className="size-3.5" />
                Copy actions
                <ChevronDown className="size-3.5 opacity-60" />
              </Button>
            </DropdownMenuTrigger>
            <DropdownMenuContent align="end">
              <DropdownMenuItem
                onClick={() => applyCopy('mon', Array.from(copyTargets).filter((k) => k !== 'mon'))}
                disabled={copyTargets.size === 0}
              >
                Copy Monday to selected days
              </DropdownMenuItem>
              <DropdownMenuItem
                onClick={() => {
                  setDays((current) => current.map((row, index) => {
                    if (index === 0 || !row.is_working_day) return row
                    const prev = current[index - 1]
                    if (!prev?.is_working_day) return row
                    return { ...row, ...cloneDay(prev), day_of_week: row.day_of_week, is_working_day: true }
                  }))
                }}
              >
                Copy previous day
              </DropdownMenuItem>
              <DropdownMenuItem
                onClick={() => applyCopy('mon', WEEKDAY_KEYS.filter((k) => k !== 'mon'))}
              >
                Apply Monday to weekdays
              </DropdownMenuItem>
            </DropdownMenuContent>
          </DropdownMenu>
        )}
      </div>

      <div className="overflow-x-auto rounded-lg border border-border/60">
        <table className="min-w-[1180px] w-full text-sm">
          <thead className="bg-muted/40 text-left text-[11px] uppercase tracking-wide text-muted-foreground">
            <tr>
              {!readOnly && <th className="px-2 py-2.5 w-10">Copy</th>}
              <th className="px-3 py-2.5">Day</th>
              <th className="px-3 py-2.5">Workday</th>
              <th className="px-3 py-2.5">Time In</th>
              <th className="px-3 py-2.5">Time Out</th>
              <th className="px-3 py-2.5">Break</th>
              <th className="px-3 py-2.5">Paid</th>
              <th className="px-3 py-2.5">Expected (hrs)</th>
              <th className="px-3 py-2.5">Half-day (hrs)</th>
              <th className="px-3 py-2.5">Grace</th>
              <th className="px-3 py-2.5">Early in</th>
              <th className="px-3 py-2.5">OT buffer</th>
            </tr>
          </thead>
          <tbody>
            {DAY_ROWS.map(({ key, label }) => {
              const row = dayMap.get(key) || { day_of_week: key, is_working_day: false }
              const working = !!row.is_working_day
              const paid = dayPaidMinutes(row)
              const halfDay = working
                ? halfDayThresholdMinutes({
                  expected_paid_minutes: row.expected_paid_minutes,
                  half_day_threshold_minutes: row.half_day_threshold_minutes,
                  time_in: row.time_in,
                  time_out: row.time_out,
                  break_start: row.break_start,
                  break_end: row.break_end,
                })
                : 0
              const overnight = working && detectCrossesMidnight(row.time_in, row.time_out)

              return (
                <tr key={key} className="border-t border-border/50 align-middle">
                  {!readOnly && (
                    <td className="px-2 py-2">
                      <Checkbox
                        checked={copyTargets.has(key)}
                        onCheckedChange={() => toggleCopyTarget(key)}
                        aria-label={`Select ${label} for copy`}
                        disabled={!working}
                      />
                    </td>
                  )}
                  <td className="px-3 py-2 font-medium whitespace-nowrap">
                    {label}
                    {overnight && (
                      <span className="ml-1.5 text-[10px] font-normal text-amber-700 dark:text-amber-300">Overnight</span>
                    )}
                  </td>
                  <td className="px-3 py-2">
                    <Checkbox
                      checked={working}
                      onCheckedChange={(checked) => updateDay(key, { is_working_day: checked === true })}
                      disabled={readOnly}
                      aria-label={`${label} workday`}
                    />
                  </td>
                  <td className="px-2 py-2">
                    {working ? (
                      <Input
                        type="time"
                        value={toHhMm(row.time_in) || ''}
                        onChange={(e) => updateDay(key, { time_in: e.target.value })}
                        className="h-9 min-w-[7.25rem]"
                        required
                        readOnly={readOnly}
                        disabled={readOnly}
                      />
                    ) : <span className="text-muted-foreground">—</span>}
                  </td>
                  <td className="px-2 py-2">
                    {working ? (
                      <Input
                        type="time"
                        value={toHhMm(row.time_out) || ''}
                        onChange={(e) => updateDay(key, { time_out: e.target.value })}
                        className="h-9 min-w-[7.25rem]"
                        required
                        readOnly={readOnly}
                        disabled={readOnly}
                      />
                    ) : <span className="text-muted-foreground">—</span>}
                  </td>
                  <td className="px-2 py-2">
                    {working ? (
                      <div className="flex min-w-[10.5rem] items-center gap-1">
                        <Input
                          type="time"
                          value={toHhMm(row.break_start) || ''}
                          onChange={(e) => updateDay(key, { break_start: e.target.value })}
                          className="h-9"
                          readOnly={readOnly}
                          disabled={readOnly}
                        />
                        <span className="text-muted-foreground">–</span>
                        <Input
                          type="time"
                          value={toHhMm(row.break_end) || ''}
                          onChange={(e) => updateDay(key, { break_end: e.target.value })}
                          className="h-9"
                          readOnly={readOnly}
                          disabled={readOnly}
                        />
                      </div>
                    ) : <span className="text-muted-foreground">—</span>}
                  </td>
                  <td className="px-3 py-2 whitespace-nowrap text-xs font-medium text-primary">
                    {working ? formatPaidHours(paid) : '—'}
                    {working && (
                      <div className="font-normal text-muted-foreground">HD {formatPaidHours(halfDay)}</div>
                    )}
                  </td>
                  <td className="px-2 py-2">
                    {working ? (
                      <Input
                        type="number"
                        min={0}
                        max={24}
                        step={0.5}
                        value={row.expected_paid_minutes ? Number(row.expected_paid_minutes) / 60 : ''}
                        onChange={(e) => updateDay(key, {
                          expected_paid_minutes: e.target.value === '' ? '' : Math.round(Number(e.target.value) * 60),
                        })}
                        placeholder="Auto"
                        className="h-9 w-[5.5rem]"
                        readOnly={readOnly}
                        disabled={readOnly}
                      />
                    ) : <span className="text-muted-foreground">—</span>}
                  </td>
                  <td className="px-2 py-2">
                    {working ? (
                      <Input
                        type="number"
                        min={0}
                        max={12}
                        step={0.25}
                        value={row.half_day_threshold_minutes ? Number(row.half_day_threshold_minutes) / 60 : ''}
                        onChange={(e) => updateDay(key, {
                          half_day_threshold_minutes: e.target.value === '' ? '' : Math.round(Number(e.target.value) * 60),
                        })}
                        placeholder="Auto (50%)"
                        className="h-9 w-[6.25rem]"
                        readOnly={readOnly}
                        disabled={readOnly}
                      />
                    ) : <span className="text-muted-foreground">—</span>}
                  </td>
                  <td className="px-2 py-2">
                    {working ? (
                      <Input
                        type="number"
                        min={0}
                        max={240}
                        value={row.grace_period_minutes ?? DEFAULTS.grace_period_minutes}
                        onChange={(e) => updateDay(key, { grace_period_minutes: numOrEmpty(e.target.value) })}
                        className="h-9 w-[4.5rem]"
                        readOnly={readOnly}
                        disabled={readOnly}
                      />
                    ) : <span className="text-muted-foreground">—</span>}
                  </td>
                  <td className="px-2 py-2">
                    {working ? (
                      <Input
                        type="number"
                        min={0}
                        max={480}
                        value={row.early_timein_minutes ?? DEFAULTS.early_timein_minutes}
                        onChange={(e) => updateDay(key, { early_timein_minutes: numOrEmpty(e.target.value) })}
                        className="h-9 w-[4.5rem]"
                        readOnly={readOnly}
                        disabled={readOnly}
                      />
                    ) : <span className="text-muted-foreground">—</span>}
                  </td>
                  <td className="px-2 py-2">
                    {working ? (
                      <Input
                        type="number"
                        min={0}
                        max={480}
                        value={row.overtime_buffer_minutes ?? DEFAULTS.overtime_buffer_minutes}
                        onChange={(e) => updateDay(key, { overtime_buffer_minutes: numOrEmpty(e.target.value) })}
                        className="h-9 w-[4.5rem]"
                        readOnly={readOnly}
                        disabled={readOnly}
                      />
                    ) : <span className="text-muted-foreground">—</span>}
                  </td>
                </tr>
              )
            })}
          </tbody>
        </table>
      </div>

      {!readOnly && copyTargets.size > 0 && (
        <p className="text-xs text-muted-foreground">
          {copyTargets.size} day(s) selected for &ldquo;Copy Monday to selected days&rdquo;.
        </p>
      )}
    </div>
  )
}

export function flexibleDaysToRestDays(days) {
  return (days || []).filter((d) => !d.is_working_day).map((d) => d.day_of_week)
}

export function flexiblePreviewSchedule(days, shared = {}) {
  const working = (days || []).filter((d) => d.is_working_day)
  const first = working[0]
  if (!first) {
    return { ...shared, shift_type: 'flexible', rest_days: flexibleDaysToRestDays(days) }
  }
  return {
    ...shared,
    shift_type: 'flexible',
    time_in: first.time_in,
    time_out: first.time_out,
    break_start: first.break_start,
    break_end: first.break_end,
    expected_paid_minutes: first.expected_paid_minutes || shared.expected_paid_minutes,
    half_day_threshold_minutes: first.half_day_threshold_minutes || shared.half_day_threshold_minutes,
    grace_period_minutes: first.grace_period_minutes ?? shared.grace_period_minutes ?? 5,
    early_timein_minutes: first.early_timein_minutes ?? shared.early_timein_minutes ?? 60,
    overtime_buffer_minutes: first.overtime_buffer_minutes ?? shared.overtime_buffer_minutes ?? 15,
    rest_days: flexibleDaysToRestDays(days),
  }
}
