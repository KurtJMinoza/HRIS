import { useMemo, useState } from 'react'
import { ChevronDown, Clock3, Copy, GripVertical, Plus, Trash2, Utensils } from 'lucide-react'
import { Button } from '@/components/ui/button'
import { Input } from '@/components/ui/input'
import { Checkbox } from '@/components/ui/checkbox'
import {
  detectCrossesMidnight,
  formatPaidHours,
  halfDayThresholdMinutes,
  netShiftMinutes,
} from '@/lib/scheduleLib'
import { createDefaultFlexibleOption } from '@/lib/workScheduleForm'
import { formatShiftRange12h, toHhMm } from '@/lib/timeFormat'
import { cn } from '@/lib/utils'

const DAY_ROWS = [
  { key: 'mon', label: 'Monday' },
  { key: 'tue', label: 'Tuesday' },
  { key: 'wed', label: 'Wednesday' },
  { key: 'thu', label: 'Thursday' },
  { key: 'fri', label: 'Friday' },
  { key: 'sat', label: 'Saturday' },
  { key: 'sun', label: 'Sunday' },
]

const DEFAULTS = {
  grace_period_minutes: 5,
  early_timein_minutes: 60,
  overtime_buffer_minutes: 15,
}

function optionPaidMinutes(option) {
  if (!option?.time_in || !option.time_out) return 0
  if (option.expected_paid_minutes !== '' && option.expected_paid_minutes != null && Number(option.expected_paid_minutes) > 0) {
    return Number(option.expected_paid_minutes)
  }
  return netShiftMinutes(
    option.time_in,
    option.time_out,
    option.break_is_paid ? null : option.break_start,
    option.break_is_paid ? null : option.break_end,
    []
  )
}

function normalizeOptions(row) {
  if (!row?.is_working_day) return []
  const options = Array.isArray(row.options) && row.options.length > 0
    ? row.options
    : [createDefaultFlexibleOption(1, row.grace_period_minutes ?? DEFAULTS.grace_period_minutes, {
      time_in: row.time_in || '08:00',
      time_out: row.time_out || '17:00',
      break_start: row.break_start || '12:00',
      break_end: row.break_end || '13:00',
      break_is_paid: !!row.break_is_paid,
      expected_paid_minutes: row.expected_paid_minutes ?? '',
      half_day_threshold_minutes: row.half_day_threshold_minutes ?? '',
      grace_period_minutes: row.grace_period_minutes ?? DEFAULTS.grace_period_minutes,
      early_timein_minutes: row.early_timein_minutes ?? DEFAULTS.early_timein_minutes,
      overtime_buffer_minutes: row.overtime_buffer_minutes ?? DEFAULTS.overtime_buffer_minutes,
      crosses_midnight: !!row.crosses_midnight,
      is_default: true,
    })]

  if (options.some((option) => option.is_default)) return options
  return options.map((option, index) => ({ ...option, is_default: index === 0 }))
}

function defaultOptionFor(row) {
  const options = normalizeOptions(row)
  return options.find((option) => option.is_default) || options[0] || null
}

function uniqueOptionName(options, baseName) {
  const names = new Set((options || []).map((option) => String(option.option_name || '').trim().toLowerCase()))
  const base = String(baseName || 'Option').trim() || 'Option'
  let name = base
  let counter = 2
  while (names.has(name.toLowerCase())) {
    name = `${base} ${counter}`
    counter += 1
  }
  return name
}

function renumberOptions(options) {
  return (options || []).map((option, index) => ({ ...option, sequence: index + 1 }))
}

function syncDayFromOptions(row, options) {
  const orderedOptions = renumberOptions(options)
  const defaultOption = orderedOptions.find((option) => option.is_default) || orderedOptions[0]
  return {
    ...row,
    options: orderedOptions,
    time_in: defaultOption?.time_in || '',
    time_out: defaultOption?.time_out || '',
    break_start: defaultOption?.break_start || '',
    break_end: defaultOption?.break_end || '',
    break_is_paid: !!defaultOption?.break_is_paid,
    expected_paid_minutes: defaultOption?.expected_paid_minutes ?? '',
    half_day_threshold_minutes: defaultOption?.half_day_threshold_minutes ?? '',
    grace_period_minutes: defaultOption?.grace_period_minutes ?? DEFAULTS.grace_period_minutes,
    early_timein_minutes: defaultOption?.early_timein_minutes ?? DEFAULTS.early_timein_minutes,
    overtime_buffer_minutes: defaultOption?.overtime_buffer_minutes ?? DEFAULTS.overtime_buffer_minutes,
    crosses_midnight: detectCrossesMidnight(defaultOption?.time_in, defaultOption?.time_out),
  }
}

function blankWorkingFields() {
  return {
    time_in: '',
    time_out: '',
    break_start: '',
    break_end: '',
    break_is_paid: false,
    expected_paid_minutes: '',
    half_day_threshold_minutes: '',
    grace_period_minutes: DEFAULTS.grace_period_minutes,
    early_timein_minutes: DEFAULTS.early_timein_minutes,
    overtime_buffer_minutes: DEFAULTS.overtime_buffer_minutes,
    crosses_midnight: false,
    options: [],
  }
}

function formatTimeBox(value) {
  const hhmm = toHhMm(value)
  if (!hhmm) return '--:--'
  const [hRaw, m] = hhmm.split(':')
  const hour24 = Number(hRaw)
  const period = hour24 >= 12 ? 'PM' : 'AM'
  const hour12 = hour24 % 12 || 12
  return `${String(hour12).padStart(2, '0')}:${m} ${period}`
}

function displayHours(minutes) {
  const h = Math.floor((Number(minutes) || 0) / 60)
  const m = (Number(minutes) || 0) % 60
  return `${h}h ${String(m).padStart(2, '0')}m`
}

function TimeField({ label, value, onChange, readOnly }) {
  return (
    <label className="min-w-0 space-y-2">
      <span className="flex items-center gap-1 text-[12px] font-medium text-[#596273]">
        {label}
        <span className="text-[10px] text-[#b07870]">?</span>
      </span>
      <div className="relative">
        <Input
          type="time"
          value={toHhMm(value) || ''}
          onChange={onChange}
          className="h-10 rounded-md border-[#d9dde5] bg-white pl-3 pr-9 text-[13px] font-medium text-[#252a31] shadow-none [color-scheme:light]"
          readOnly={readOnly}
          disabled={readOnly}
        />
        <Clock3 className="pointer-events-none absolute right-3 top-1/2 size-3.5 -translate-y-1/2 text-[#69707d]" />
      </div>
    </label>
  )
}

function ValueField({ label, value }) {
  return (
    <label className="min-w-0 space-y-2">
      <span className="flex items-center gap-1 text-[12px] font-medium text-[#596273]">
        {label}
        <span className="text-[10px] text-[#b07870]">?</span>
      </span>
      <div className="flex h-10 items-center rounded-md border border-[#d9dde5] bg-white px-3 text-[13px] font-medium text-[#596273]">
        {value}
      </div>
    </label>
  )
}

function EditableMinuteField({ label, value, onChange, readOnly }) {
  return (
    <label className="min-w-0 space-y-2">
      <span className="flex items-center gap-1 text-[12px] font-medium text-[#596273]">
        {label}
        <span className="text-[10px] text-[#b07870]">?</span>
      </span>
      <Input
        value={`${value ?? 0}m`}
        onChange={(e) => onChange(Number(String(e.target.value).replace(/\D/g, '')) || 0)}
        className="h-10 rounded-md border-[#d9dde5] bg-white px-3 text-[13px] font-medium text-[#252a31] shadow-none"
        readOnly={readOnly}
        disabled={readOnly}
      />
    </label>
  )
}

function OptionEditor({ option, readOnly, onChange }) {
  const paid = optionPaidMinutes(option)
  const halfDay = halfDayThresholdMinutes({
    expected_paid_minutes: option.expected_paid_minutes,
    half_day_threshold_minutes: option.half_day_threshold_minutes,
    time_in: option.time_in,
    time_out: option.time_out,
    break_start: option.break_is_paid ? null : option.break_start,
    break_end: option.break_is_paid ? null : option.break_end,
  })

  return (
    <div className="space-y-4 pb-0 pt-5">
      <div className="grid gap-4 xl:grid-cols-[1.05fr_1.05fr_1.05fr_1.05fr_.72fr_.72fr_.9fr_.9fr]">
        <TimeField
          label="Time in"
          value={option.time_in}
          readOnly={readOnly}
          onChange={(e) => onChange({ time_in: e.target.value, crosses_midnight: detectCrossesMidnight(e.target.value, option.time_out) })}
        />
        <TimeField
          label="Break start"
          value={option.break_start}
          readOnly={readOnly}
          onChange={(e) => onChange({ break_start: e.target.value })}
        />
        <TimeField
          label="Break end"
          value={option.break_end}
          readOnly={readOnly}
          onChange={(e) => onChange({ break_end: e.target.value })}
        />
        <TimeField
          label="Time out"
          value={option.time_out}
          readOnly={readOnly}
          onChange={(e) => onChange({ time_out: e.target.value, crosses_midnight: detectCrossesMidnight(option.time_in, e.target.value) })}
        />
        <ValueField label="Expected hrs" value={displayHours(paid)} />
        <ValueField label="Half-day hrs" value={displayHours(halfDay)} />
        <EditableMinuteField
          label="Grace period"
          value={option.grace_period_minutes ?? DEFAULTS.grace_period_minutes}
          readOnly={readOnly}
          onChange={(value) => onChange({ grace_period_minutes: value })}
        />
        <EditableMinuteField
          label="OT buffer"
          value={option.overtime_buffer_minutes ?? DEFAULTS.overtime_buffer_minutes}
          readOnly={readOnly}
          onChange={(value) => onChange({ overtime_buffer_minutes: value })}
        />
      </div>

      <div className="grid gap-6 border-t border-[#edf0f3] pt-4 lg:grid-cols-[360px_1fr]">
        <div className="space-y-2">
          <div className="flex items-center gap-2 text-[12px] font-medium text-[#515966]">
            <Utensils className="size-4" />
            Breaks
          </div>
          <div className="grid min-h-10 grid-cols-[1fr_auto_auto] items-center gap-3 rounded-md border border-[#d9dde5] bg-white px-3 py-1.5 text-[13px]">
            <span className="font-medium text-[#6b7280]">Lunch Break</span>
            <span className="text-[#6b7280]">{formatShiftRange12h(option.break_start, option.break_end, ' - ')}</span>
            <div className="flex rounded-md border border-[#d9dde5] bg-[#f8fafc] p-0.5">
              {[
                { label: 'Unpaid', value: false },
                { label: 'Paid', value: true },
              ].map((choice) => (
                <button
                  key={choice.label}
                  type="button"
                  disabled={readOnly}
                  onClick={() => onChange({ break_is_paid: choice.value })}
                  className={cn(
                    'h-7 rounded-[5px] px-2.5 text-[11px] font-semibold transition',
                    !!option.break_is_paid === choice.value
                      ? choice.value
                        ? 'bg-[#d8f7e8] text-[#168855]'
                        : 'bg-[#fff1e9] text-[#f45113]'
                      : 'text-[#667085] hover:bg-white',
                    readOnly && 'cursor-not-allowed opacity-70'
                  )}
                >
                  {choice.label}
                </button>
              ))}
            </div>
          </div>
        </div>
        <label className="space-y-2">
          <span className="text-[12px] font-medium text-[#5f6673]">Notes <span className="font-normal">(optional)</span></span>
          <div className="relative">
            <Input
              className="h-10 rounded-md border-[#d9dde5] bg-white pr-14 text-[13px] shadow-none"
              placeholder="Add note for this day"
              maxLength={150}
              readOnly={readOnly}
              disabled={readOnly}
            />
            <span className="absolute right-3 top-1/2 -translate-y-1/2 text-[12px] text-[#7a828e]">0/150</span>
          </div>
        </label>
      </div>
    </div>
  )
}

export function FlexibleScheduleTable({ days, setDays, readOnly = false }) {
  const [expandedDay, setExpandedDay] = useState('mon')
  const dayMap = useMemo(() => {
    const map = new Map()
    for (const row of days || []) map.set(row.day_of_week, row)
    return map
  }, [days])

  function updateDay(dayKey, updater) {
    setDays((current) => current.map((row) => {
      if (row.day_of_week !== dayKey) return row
      return typeof updater === 'function' ? updater(row) : { ...row, ...updater }
    }))
  }

  function setWorking(dayKey, checked) {
    updateDay(dayKey, (row) => {
      if (!checked) return { ...row, is_working_day: false, ...blankWorkingFields() }
      const option = createDefaultFlexibleOption(1, row.grace_period_minutes ?? DEFAULTS.grace_period_minutes)
      return syncDayFromOptions({ ...row, is_working_day: true }, [option])
    })
  }

  function updateOption(dayKey, index, patch) {
    updateDay(dayKey, (row) => {
      const options = normalizeOptions(row).map((option, optionIndex) => (
        optionIndex === index ? { ...option, ...patch } : option
      ))
      return syncDayFromOptions(row, options)
    })
  }

  function addOption(dayKey) {
    updateDay(dayKey, (row) => {
      const options = normalizeOptions(row)
      const option = createDefaultFlexibleOption(options.length + 1, row.grace_period_minutes ?? DEFAULTS.grace_period_minutes, {
        option_name: uniqueOptionName(options, `Option ${options.length + 1}`),
        is_default: false,
      })
      return syncDayFromOptions(row, [...options, option])
    })
  }

  function duplicateOption(dayKey, index) {
    updateDay(dayKey, (row) => {
      const options = normalizeOptions(row)
      const source = options[index]
      if (!source) return row
      const copy = {
        ...source,
        id: undefined,
        option_name: uniqueOptionName(options, `${source.option_name || `Option ${index + 1}`} Copy`),
        is_default: false,
      }
      return syncDayFromOptions(row, [...options, copy])
    })
  }

  function removeOption(dayKey, index) {
    updateDay(dayKey, (row) => {
      const options = normalizeOptions(row)
      if (options.length <= 1) return row
      const wasDefault = !!options[index]?.is_default
      let next = options.filter((_, optionIndex) => optionIndex !== index)
      if (wasDefault && next.length > 0) {
        next = next.map((option, optionIndex) => ({ ...option, is_default: optionIndex === 0 }))
      }
      return syncDayFromOptions(row, next)
    })
  }

  function setDefaultOption(dayKey, index) {
    updateDay(dayKey, (row) => {
      const options = normalizeOptions(row).map((option, optionIndex) => ({
        ...option,
        is_default: optionIndex === index,
      }))
      return syncDayFromOptions(row, options)
    })
  }

  return (
    <div className="space-y-2.5">
      {DAY_ROWS.map(({ key, label }) => {
        const row = dayMap.get(key) || { day_of_week: key, is_working_day: false, options: [] }
        const working = !!row.is_working_day
        const options = normalizeOptions(row)
        const defaultOption = defaultOptionFor(row)
        const expanded = expandedDay === key

        return (
          <section
            key={key}
            className={cn(
              'rounded-lg border border-[#e2e5ea] bg-white shadow-[0_1px_2px_rgba(16,24,40,0.04)]',
              expanded && 'shadow-[0_2px_8px_rgba(16,24,40,0.06)]'
            )}
          >
            <div className="flex min-h-[54px] items-center justify-between gap-3 px-5">
              <div className="flex min-w-0 items-center gap-4">
                <GripVertical className="size-4 shrink-0 text-[#111827]" />
                <Checkbox
                  checked={working}
                  onCheckedChange={(checked) => setWorking(key, checked === true)}
                  disabled={readOnly}
                  aria-label={`${label} workday`}
                  className="size-5 rounded-[4px] border-[#cbd2dc] data-[state=checked]:border-[#f45113] data-[state=checked]:bg-[#f45113]"
                />
                <div className="min-w-0">
                  <div className="flex items-center gap-2">
                    <p className="text-[14px] font-semibold leading-tight text-[#1f2329]">{label}</p>
                    <span
                      className={cn(
                        'rounded-full px-3 py-1 text-[11px] font-semibold',
                        working ? 'bg-[#dff7eb] text-[#14945b]' : 'bg-[#eff1f4] text-[#656d78]'
                      )}
                    >
                      {working ? 'Working day' : 'Day off'}
                    </span>
                  </div>
                  <p className="mt-0.5 text-[12px] text-[#505866]">
                    {working && defaultOption
                      ? `Default: ${formatTimeBox(defaultOption.time_in)} - ${formatTimeBox(defaultOption.time_out)}`
                      : 'Default: --'}
                  </p>
                </div>
              </div>
              <div className="flex shrink-0 items-center gap-2">
                {expanded && working && !readOnly && (
                  <Button
                    type="button"
                    variant="outline"
                    size="sm"
                    onClick={() => addOption(key)}
                    className="h-9 gap-2 rounded-md border-[#dfe3e8] bg-white px-4 text-[13px] font-semibold text-[#1f2329] shadow-sm hover:bg-[#fafafa]"
                  >
                    <Plus className="size-4" />
                    Add shift option
                  </Button>
                )}
                <Button
                  type="button"
                  variant="ghost"
                  size="icon"
                  className="size-8 text-[#111827]"
                  onClick={() => setExpandedDay(expanded ? '' : key)}
                  title={expanded ? 'Collapse' : 'Expand'}
                >
                  <ChevronDown className={cn('size-4 transition-transform', expanded && 'rotate-180')} />
                </Button>
              </div>
            </div>

            {expanded && working && (
              <div className="space-y-3 px-16 pb-5 pr-20">
                {options.map((option, index) => (
                  <div
                    key={`${option.id || 'new'}-${index}`}
                    className={cn(
                      'rounded-lg border px-4 pb-4',
                      option.is_default ? 'border-[#ffd8c6] bg-[#fffaf7]' : 'border-[#e7ebf0] bg-white'
                    )}
                  >
                    <div className="flex min-h-12 items-center justify-between gap-3 border-b border-[#edf0f3]">
                      <div className="flex min-w-0 items-center gap-3">
                        <Input
                          value={option.option_name || ''}
                          onChange={(e) => updateOption(key, index, { option_name: e.target.value })}
                          className="h-8 w-[210px] rounded-md border-[#d9dde5] bg-white text-[13px] font-semibold text-[#1f2329] shadow-none"
                          readOnly={readOnly}
                          disabled={readOnly}
                          aria-label={`${label} shift option name`}
                        />
                        {option.is_default ? (
                          <span className="rounded-full bg-[#fff1e9] px-2.5 py-1 text-[11px] font-semibold text-[#f45113]">Default</span>
                        ) : (
                          !readOnly && (
                            <Button
                              type="button"
                              variant="ghost"
                              size="sm"
                              onClick={() => setDefaultOption(key, index)}
                              className="h-8 rounded-md px-2 text-[12px] font-semibold text-[#596273] hover:bg-white"
                            >
                              Set default
                            </Button>
                          )
                        )}
                      </div>
                      {working && !readOnly && (
                        <div className="flex shrink-0 items-center gap-1">
                          <Button type="button" variant="ghost" size="icon" className="size-8 text-[#1f2329]" onClick={() => duplicateOption(key, index)} title="Duplicate shift option">
                            <Copy className="size-4" />
                          </Button>
                          <Button
                            type="button"
                            variant="ghost"
                            size="icon"
                            className="size-8 text-[#ff4d5e] disabled:opacity-35"
                            onClick={() => removeOption(key, index)}
                            disabled={options.length <= 1}
                            title="Remove shift option"
                          >
                            <Trash2 className="size-4" />
                          </Button>
                        </div>
                      )}
                    </div>
                    <OptionEditor
                      option={option}
                      readOnly={readOnly}
                      onChange={(patch) => updateOption(key, index, patch)}
                    />
                  </div>
                ))}
              </div>
            )}
          </section>
        )
      })}
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
  const option = defaultOptionFor(first) || first
  return {
    ...shared,
    shift_type: 'flexible',
    time_in: option.time_in,
    time_out: option.time_out,
    break_start: option.break_start,
    break_end: option.break_end,
    break_is_paid: !!option.break_is_paid,
    breaks: option.break_start && option.break_end
      ? [{ start: option.break_start, end: option.break_end, is_paid: !!option.break_is_paid }]
      : [],
    expected_paid_minutes: option.expected_paid_minutes || shared.expected_paid_minutes,
    half_day_threshold_minutes: option.half_day_threshold_minutes || shared.half_day_threshold_minutes,
    grace_period_minutes: option.grace_period_minutes ?? shared.grace_period_minutes ?? 5,
    early_timein_minutes: option.early_timein_minutes ?? shared.early_timein_minutes ?? 60,
    overtime_buffer_minutes: option.overtime_buffer_minutes ?? shared.overtime_buffer_minutes ?? 15,
    rest_days: flexibleDaysToRestDays(days),
  }
}
