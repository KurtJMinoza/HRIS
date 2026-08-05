import { hasEmoji, hasFancyUnicode } from '@/validation'
import { toHhMm } from '@/lib/timeFormat'
import { detectCrossesMidnight } from '@/lib/scheduleLib'

/** Default weekly days off for new templates (PH common case: single Sunday off). */
export const DEFAULT_REST_DAYS = ['sun']

export const FLEXIBLE_DAY_KEYS = ['mon', 'tue', 'wed', 'thu', 'fri', 'sat', 'sun']

const FLEXIBLE_DAY_LABELS = {
  mon: 'Monday',
  tue: 'Tuesday',
  wed: 'Wednesday',
  thu: 'Thursday',
  fri: 'Friday',
  sat: 'Saturday',
  sun: 'Sunday',
}

export function createDefaultFlexibleOption(sequence = 1, gracePeriod = 5, overrides = {}) {
  return {
    option_name: sequence === 1 ? 'Default' : `Option ${sequence}`,
    time_in: '08:00',
    time_out: '17:00',
    break_start: '12:00',
    break_end: '13:00',
    break_is_paid: false,
    expected_paid_minutes: '',
    half_day_threshold_minutes: '',
    grace_period_minutes: gracePeriod,
    early_timein_minutes: 60,
    overtime_buffer_minutes: 15,
    crosses_midnight: false,
    is_default: sequence === 1,
    matching_start_tolerance_minutes: '',
    matching_end_tolerance_minutes: '',
    sequence,
    ...overrides,
  }
}

export function createDefaultFlexibleDay(dayKey, isWorking = true, gracePeriod = 5) {
  const option = createDefaultFlexibleOption(1, gracePeriod)
  return {
    day_of_week: dayKey,
    is_working_day: isWorking,
    time_in: isWorking ? option.time_in : '',
    time_out: isWorking ? option.time_out : '',
    break_start: isWorking ? option.break_start : '',
    break_end: isWorking ? option.break_end : '',
    break_is_paid: false,
    expected_paid_minutes: '',
    half_day_threshold_minutes: '',
    grace_period_minutes: gracePeriod,
    early_timein_minutes: 60,
    overtime_buffer_minutes: 15,
    crosses_midnight: false,
    options: isWorking ? [option] : [],
  }
}

export function createDefaultFlexibleDays(gracePeriod = 5) {
  return FLEXIBLE_DAY_KEYS.map((key) => createDefaultFlexibleDay(
    key,
    !['sat', 'sun'].includes(key),
    gracePeriod,
  ))
}

export function createDefaultScheduleForm() {
  return {
    name: '',
    schedule_code: '',
    shift_type: 'fixed',
    time_in: '08:00',
    break_start: '',
    break_end: '',
    time_out: '17:00',
    breaks: [],
    work_blocks: [],
    expected_paid_minutes: '',
    half_day_threshold_minutes: '',
    grace_period_minutes: 5,
    early_timein_minutes: 60,
    late_allowance_minutes: '',
    early_timeout_minutes: '',
    overtime_buffer_minutes: 15,
    rest_days: [...DEFAULT_REST_DAYS],
    flexible_required_minutes: '',
    flexible_earliest_in: '',
    flexible_latest_out: '',
    core_hours_start: '',
    core_hours_end: '',
    description: '',
    days: createDefaultFlexibleDays(5),
  }
}

export function scheduleRecordToForm(schedule) {
  if (!schedule) return createDefaultScheduleForm()

  const grace = schedule.grace_period_minutes ?? 5
  const days = Array.isArray(schedule.days) && schedule.days.length > 0
    ? FLEXIBLE_DAY_KEYS.map((key) => {
      const day = schedule.days.find((row) => row.day_of_week === key)
      if (!day) return createDefaultFlexibleDay(key, !['sat', 'sun'].includes(key), grace)
      const fallbackOption = createDefaultFlexibleOption(1, grace, {
        option_name: 'Default',
        time_in: toHhMm(day.time_in) || '08:00',
        time_out: toHhMm(day.time_out) || '17:00',
        break_start: toHhMm(day.break_start) || '',
        break_end: toHhMm(day.break_end) || '',
        break_is_paid: !!day.break_is_paid,
        expected_paid_minutes: day.expected_paid_minutes ?? '',
        half_day_threshold_minutes: day.half_day_threshold_minutes ?? '',
        grace_period_minutes: day.grace_period_minutes ?? grace,
        early_timein_minutes: day.early_timein_minutes ?? schedule.early_timein_minutes ?? 60,
        overtime_buffer_minutes: day.overtime_buffer_minutes ?? schedule.overtime_buffer_minutes ?? 15,
        crosses_midnight: !!day.crosses_midnight,
        is_default: true,
      })
      const options = Array.isArray(day.options) && day.options.length > 0
        ? day.options.map((option, index) => ({
          id: option.id,
          option_name: option.option_name || (index === 0 ? 'Default' : `Option ${index + 1}`),
          time_in: toHhMm(option.time_in) || '',
          time_out: toHhMm(option.time_out) || '',
          break_start: toHhMm(option.break_start) || '',
          break_end: toHhMm(option.break_end) || '',
          break_is_paid: !!option.break_is_paid,
          expected_paid_minutes: option.expected_paid_minutes ?? '',
          half_day_threshold_minutes: option.half_day_threshold_minutes ?? '',
          grace_period_minutes: option.grace_period_minutes ?? grace,
          early_timein_minutes: option.early_timein_minutes ?? schedule.early_timein_minutes ?? 60,
          overtime_buffer_minutes: option.overtime_buffer_minutes ?? schedule.overtime_buffer_minutes ?? 15,
          crosses_midnight: !!option.crosses_midnight,
          is_default: !!option.is_default,
          matching_start_tolerance_minutes: option.matching_start_tolerance_minutes ?? '',
          matching_end_tolerance_minutes: option.matching_end_tolerance_minutes ?? '',
          sequence: option.sequence ?? index + 1,
        }))
        : [fallbackOption]
      if (options.length > 0 && !options.some((option) => option.is_default)) {
        options[0] = { ...options[0], is_default: true }
      }
      const defaultOption = options.find((option) => option.is_default) || options[0] || fallbackOption

      return {
        day_of_week: day.day_of_week,
        is_working_day: !!day.is_working_day,
        time_in: defaultOption?.time_in || '',
        time_out: defaultOption?.time_out || '',
        break_start: defaultOption?.break_start || '',
        break_end: defaultOption?.break_end || '',
        break_is_paid: !!defaultOption?.break_is_paid,
        expected_paid_minutes: defaultOption?.expected_paid_minutes ?? '',
        half_day_threshold_minutes: defaultOption?.half_day_threshold_minutes ?? '',
        grace_period_minutes: defaultOption?.grace_period_minutes ?? grace,
        early_timein_minutes: defaultOption?.early_timein_minutes ?? schedule.early_timein_minutes ?? 60,
        overtime_buffer_minutes: defaultOption?.overtime_buffer_minutes ?? schedule.overtime_buffer_minutes ?? 15,
        crosses_midnight: !!defaultOption?.crosses_midnight,
        options: day.is_working_day ? options : [],
      }
    })
    : createDefaultFlexibleDays(grace)

  return {
    name: schedule.name || '',
    schedule_code: schedule.schedule_code || '',
    shift_type: schedule.shift_type || 'fixed',
    time_in: toHhMm(schedule.time_in) || '08:00',
    break_start: toHhMm(schedule.break_start) || '',
    break_end: toHhMm(schedule.break_end) || '',
    time_out: toHhMm(schedule.time_out) || '17:00',
    breaks: Array.isArray(schedule.breaks) ? schedule.breaks.map((b) => ({
      start: toHhMm(b.start || b.break_start) || '',
      end: toHhMm(b.end || b.break_end) || '',
      is_paid: !!b.is_paid,
    })) : [],
    work_blocks: Array.isArray(schedule.work_blocks) ? schedule.work_blocks.map((b) => ({
      start: toHhMm(b.start) || '',
      end: toHhMm(b.end) || '',
    })) : [],
    expected_paid_minutes: schedule.expected_paid_minutes ?? '',
    half_day_threshold_minutes: schedule.half_day_threshold_minutes ?? '',
    grace_period_minutes: schedule.grace_period_minutes ?? 5,
    early_timein_minutes: schedule.early_timein_minutes ?? 60,
    late_allowance_minutes: schedule.late_allowance_minutes ?? '',
    early_timeout_minutes: schedule.early_timeout_minutes ?? '',
    overtime_buffer_minutes: schedule.overtime_buffer_minutes ?? 15,
    rest_days:
      Array.isArray(schedule.rest_days) && schedule.rest_days.length > 0
        ? [...schedule.rest_days]
        : [...DEFAULT_REST_DAYS],
    flexible_required_minutes: schedule.flexible_required_minutes ?? '',
    flexible_earliest_in: toHhMm(schedule.flexible_earliest_in) || '',
    flexible_latest_out: toHhMm(schedule.flexible_latest_out) || '',
    core_hours_start: toHhMm(schedule.core_hours_start) || '',
    core_hours_end: toHhMm(schedule.core_hours_end) || '',
    description: schedule.description || '',
    days,
  }
}

function breakWithinShift(timeIn, timeOut, breakStart, breakEnd, crossesMidnight) {
  const toMin = (t) => {
    const [h, m] = String(t).slice(0, 5).split(':').map(Number)
    return h * 60 + m
  }
  const inMin = toMin(timeIn)
  const outMin = toMin(timeOut)
  const bsMin = toMin(breakStart)
  const beMin = toMin(breakEnd)
  const spanEnd = crossesMidnight || outMin <= inMin ? outMin + 1440 : outMin
  const breakEndAdj = beMin <= bsMin ? beMin + 1440 : beMin
  return bsMin >= inMin && breakEndAdj <= spanEnd
}

export function validateFlexibleDays(days) {
  const errors = []
  for (const day of days || []) {
    const label = FLEXIBLE_DAY_LABELS[day.day_of_week] || day.day_of_week
    if (!day.is_working_day) continue

    const options = Array.isArray(day.options) && day.options.length > 0
      ? day.options
      : [day]
    if (options.length === 0) {
      errors.push(`${label} requires at least one shift option.`)
      continue
    }

    const defaultCount = options.filter((option) => !!option.is_default).length
    if (defaultCount !== 1) errors.push(`${label} must have exactly one default shift option.`)

    const names = new Set()
    for (const [index, option] of options.entries()) {
      const optionLabel = option.option_name || `Option ${index + 1}`
      const nameKey = String(optionLabel).trim().toLowerCase()
      const timeIn = toHhMm(option.time_in)
      const timeOut = toHhMm(option.time_out)
      const breakStart = option.break_start ? toHhMm(option.break_start) : ''
      const breakEnd = option.break_end ? toHhMm(option.break_end) : ''
      const crossesMidnight = detectCrossesMidnight(timeIn, timeOut)

      if (!nameKey) errors.push(`${label} option ${index + 1} needs a name.`)
      if (nameKey && names.has(nameKey)) errors.push(`${label} shift option names must be unique.`)
      names.add(nameKey)

      if (!timeIn) errors.push(`${label} ${optionLabel} Start Time is required.`)
      if (!timeOut) errors.push(`${label} ${optionLabel} End Time is required.`)
      if (timeIn && timeOut && !crossesMidnight && timeOut <= timeIn) {
        errors.push(`${label} ${optionLabel} End Time must be later than Start Time.`)
      }
      if ((breakStart && !breakEnd) || (!breakStart && breakEnd)) {
        errors.push(`${label} ${optionLabel} break must include both start and end.`)
      }
      if (timeIn && timeOut && breakStart && breakEnd) {
        if (!breakWithinShift(timeIn, timeOut, breakStart, breakEnd, crossesMidnight)) {
          errors.push(`${label} ${optionLabel} break period is outside the scheduled shift.`)
        }
      }
    }
  }
  return errors
}

export function validateScheduleName(value) {
  const trimmed = String(value || '').trim()
  if (!trimmed) return 'Schedule name is required.'
  if (hasEmoji(trimmed)) return 'Emojis are not allowed.'
  if (hasFancyUnicode(trimmed)) {
    return 'Please use standard letters/numbers only (no styled fonts or special symbols).'
  }
  if (!/^[A-Za-z0-9\s\-']+$/.test(trimmed)) {
    return 'Only letters, numbers, spaces, hyphens, and apostrophes are allowed.'
  }
  if (trimmed.length > 100) return 'Schedule name must be 100 characters or less.'
  return ''
}

function numberOrDefault(value, fallback) {
  return value === '' || value == null ? fallback : Number(value)
}

function numberOrNull(value) {
  return value === '' || value == null ? null : Number(value)
}

function normalizeFlexibleOptions(day, fallbackGrace = 5) {
  const rawOptions = Array.isArray(day.options) && day.options.length > 0 ? day.options : [day]
  let options = rawOptions.map((option, index) => ({
    id: option.id,
    option_name: String(option.option_name || (index === 0 ? 'Default' : `Option ${index + 1}`)).trim(),
    time_in: toHhMm(option.time_in || day.time_in) || null,
    time_out: toHhMm(option.time_out || day.time_out) || null,
    break_start: option.break_start ? toHhMm(option.break_start) : (day.break_start ? toHhMm(day.break_start) : null),
    break_end: option.break_end ? toHhMm(option.break_end) : (day.break_end ? toHhMm(day.break_end) : null),
    break_is_paid: !!option.break_is_paid,
    expected_paid_minutes: numberOrNull(option.expected_paid_minutes),
    half_day_threshold_minutes: numberOrNull(option.half_day_threshold_minutes),
    grace_period_minutes: numberOrDefault(option.grace_period_minutes, fallbackGrace),
    early_timein_minutes: numberOrDefault(option.early_timein_minutes, 60),
    overtime_buffer_minutes: numberOrDefault(option.overtime_buffer_minutes, 15),
    crosses_midnight: detectCrossesMidnight(option.time_in || day.time_in, option.time_out || day.time_out),
    is_default: !!option.is_default,
    matching_start_tolerance_minutes: numberOrNull(option.matching_start_tolerance_minutes),
    matching_end_tolerance_minutes: numberOrNull(option.matching_end_tolerance_minutes),
    sequence: option.sequence ?? index + 1,
  }))

  if (options.length > 0 && !options.some((option) => option.is_default)) {
    options = options.map((option, index) => ({ ...option, is_default: index === 0 }))
  }

  return options.map((option, index) => ({
    ...option,
    option_name: option.option_name || (index === 0 ? 'Default' : `Option ${index + 1}`),
    sequence: index + 1,
  }))
}

export function buildWorkingSchedulePayload(editForm) {
  const isFlexible = (editForm.shift_type || 'fixed') === 'flexible'
  const payload = {
    name: editForm.name.trim(),
    schedule_code: editForm.schedule_code?.trim() || null,
    shift_type: editForm.shift_type || 'fixed',
    time_in: toHhMm(editForm.time_in) || editForm.time_in,
    break_start: editForm.break_start ? toHhMm(editForm.break_start) : null,
    break_end: editForm.break_end ? toHhMm(editForm.break_end) : null,
    time_out: toHhMm(editForm.time_out) || editForm.time_out,
    breaks: (editForm.breaks || []).filter((b) => b.start && b.end).map((b) => ({
      start: toHhMm(b.start) || b.start,
      end: toHhMm(b.end) || b.end,
      is_paid: !!b.is_paid,
    })),
    work_blocks: (editForm.work_blocks || []).filter((b) => b.start && b.end).map((b) => ({
      start: toHhMm(b.start) || b.start,
      end: toHhMm(b.end) || b.end,
    })),
    expected_paid_minutes:
      editForm.expected_paid_minutes === '' || editForm.expected_paid_minutes == null
        ? null
        : Number(editForm.expected_paid_minutes),
    half_day_threshold_minutes:
      editForm.half_day_threshold_minutes === '' || editForm.half_day_threshold_minutes == null
        ? null
        : Number(editForm.half_day_threshold_minutes),
    grace_period_minutes:
      editForm.grace_period_minutes === '' || editForm.grace_period_minutes == null
        ? 5
        : Number(editForm.grace_period_minutes),
    early_timein_minutes:
      editForm.early_timein_minutes === '' || editForm.early_timein_minutes == null
        ? 60
        : Number(editForm.early_timein_minutes),
    late_allowance_minutes:
      editForm.late_allowance_minutes === '' || editForm.late_allowance_minutes == null
        ? null
        : Number(editForm.late_allowance_minutes),
    early_timeout_minutes:
      editForm.early_timeout_minutes === '' || editForm.early_timeout_minutes == null
        ? null
        : Number(editForm.early_timeout_minutes),
    overtime_buffer_minutes:
      editForm.overtime_buffer_minutes === '' || editForm.overtime_buffer_minutes == null
        ? 15
        : Number(editForm.overtime_buffer_minutes),
    rest_days: editForm.rest_days,
    flexible_required_minutes:
      editForm.flexible_required_minutes === '' || editForm.flexible_required_minutes == null
        ? null
        : Number(editForm.flexible_required_minutes),
    flexible_earliest_in: editForm.flexible_earliest_in ? toHhMm(editForm.flexible_earliest_in) : null,
    flexible_latest_out: editForm.flexible_latest_out ? toHhMm(editForm.flexible_latest_out) : null,
    core_hours_start: editForm.core_hours_start ? toHhMm(editForm.core_hours_start) : null,
    core_hours_end: editForm.core_hours_end ? toHhMm(editForm.core_hours_end) : null,
    description: editForm.description?.trim() || null,
  }

  if (isFlexible) {
    payload.days = (editForm.days || []).map((day) => {
      const options = day.is_working_day
        ? normalizeFlexibleOptions(day, numberOrDefault(day.grace_period_minutes, 5))
        : []
      const defaultOption = options.find((option) => option.is_default) || options[0] || null

      return {
        day_of_week: day.day_of_week,
        is_working_day: !!day.is_working_day,
        time_in: defaultOption?.time_in || null,
        time_out: defaultOption?.time_out || null,
        break_start: defaultOption?.break_start || null,
        break_end: defaultOption?.break_end || null,
        break_is_paid: !!defaultOption?.break_is_paid,
        expected_paid_minutes: defaultOption?.expected_paid_minutes ?? null,
        half_day_threshold_minutes: defaultOption?.half_day_threshold_minutes ?? null,
        grace_period_minutes: defaultOption?.grace_period_minutes ?? numberOrDefault(day.grace_period_minutes, 5),
        early_timein_minutes: defaultOption?.early_timein_minutes ?? numberOrDefault(day.early_timein_minutes, 60),
        overtime_buffer_minutes: defaultOption?.overtime_buffer_minutes ?? numberOrDefault(day.overtime_buffer_minutes, 15),
        crosses_midnight: defaultOption ? detectCrossesMidnight(defaultOption.time_in, defaultOption.time_out) : false,
        options,
      }
    })
    payload.rest_days = payload.days.filter((d) => !d.is_working_day).map((d) => d.day_of_week)
    const firstWorking = payload.days.find((d) => d.is_working_day)
    if (firstWorking) {
      const defaultOption = firstWorking.options.find((option) => option.is_default) || firstWorking.options[0] || firstWorking
      payload.time_in = defaultOption.time_in
      payload.time_out = defaultOption.time_out
      payload.break_start = defaultOption.break_start
      payload.break_end = defaultOption.break_end
      payload.grace_period_minutes = defaultOption.grace_period_minutes
      payload.early_timein_minutes = defaultOption.early_timein_minutes
      payload.overtime_buffer_minutes = defaultOption.overtime_buffer_minutes
      payload.expected_paid_minutes = defaultOption.expected_paid_minutes
      payload.half_day_threshold_minutes = defaultOption.half_day_threshold_minutes
    }
  }

  return payload
}
