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

export function createDefaultFlexibleDay(dayKey, isWorking = true, gracePeriod = 5) {
  return {
    day_of_week: dayKey,
    is_working_day: isWorking,
    time_in: isWorking ? '08:00' : '',
    time_out: isWorking ? '17:00' : '',
    break_start: isWorking ? '12:00' : '',
    break_end: isWorking ? '13:00' : '',
    expected_paid_minutes: '',
    half_day_threshold_minutes: '',
    grace_period_minutes: gracePeriod,
    early_timein_minutes: 60,
    overtime_buffer_minutes: 15,
    crosses_midnight: false,
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
    ? schedule.days.map((day) => ({
      day_of_week: day.day_of_week,
      is_working_day: !!day.is_working_day,
      time_in: toHhMm(day.time_in) || '',
      time_out: toHhMm(day.time_out) || '',
      break_start: toHhMm(day.break_start) || '',
      break_end: toHhMm(day.break_end) || '',
      expected_paid_minutes: day.expected_paid_minutes ?? '',
      half_day_threshold_minutes: day.half_day_threshold_minutes ?? '',
      grace_period_minutes: day.grace_period_minutes ?? grace,
      early_timein_minutes: day.early_timein_minutes ?? schedule.early_timein_minutes ?? 60,
      overtime_buffer_minutes: day.overtime_buffer_minutes ?? schedule.overtime_buffer_minutes ?? 15,
      crosses_midnight: !!day.crosses_midnight,
    }))
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

    const timeIn = toHhMm(day.time_in)
    const timeOut = toHhMm(day.time_out)
    const breakStart = day.break_start ? toHhMm(day.break_start) : ''
    const breakEnd = day.break_end ? toHhMm(day.break_end) : ''
    const crossesMidnight = detectCrossesMidnight(timeIn, timeOut)

    if (!timeIn) errors.push(`${label} Time In is required.`)
    if (!timeOut) errors.push(`${label} Time Out is required.`)
    if (timeIn && timeOut && !crossesMidnight && timeOut <= timeIn) {
      errors.push(`${label} Time Out must be later than Time In.`)
    }
    if ((breakStart && !breakEnd) || (!breakStart && breakEnd)) {
      errors.push(`${label} break must include both start and end.`)
    }
    if (timeIn && timeOut && breakStart && breakEnd) {
      if (!breakWithinShift(timeIn, timeOut, breakStart, breakEnd, crossesMidnight)) {
        errors.push(`${label} break period is outside the scheduled shift.`)
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
    payload.days = (editForm.days || []).map((day) => ({
      day_of_week: day.day_of_week,
      is_working_day: !!day.is_working_day,
      time_in: day.is_working_day ? (toHhMm(day.time_in) || null) : null,
      time_out: day.is_working_day ? (toHhMm(day.time_out) || null) : null,
      break_start: day.is_working_day && day.break_start ? toHhMm(day.break_start) : null,
      break_end: day.is_working_day && day.break_end ? toHhMm(day.break_end) : null,
      expected_paid_minutes:
        day.expected_paid_minutes === '' || day.expected_paid_minutes == null
          ? null
          : Number(day.expected_paid_minutes),
      half_day_threshold_minutes:
        day.half_day_threshold_minutes === '' || day.half_day_threshold_minutes == null
          ? null
          : Number(day.half_day_threshold_minutes),
      grace_period_minutes:
        day.grace_period_minutes === '' || day.grace_period_minutes == null
          ? 5
          : Number(day.grace_period_minutes),
      early_timein_minutes:
        day.early_timein_minutes === '' || day.early_timein_minutes == null
          ? 60
          : Number(day.early_timein_minutes),
      overtime_buffer_minutes:
        day.overtime_buffer_minutes === '' || day.overtime_buffer_minutes == null
          ? 15
          : Number(day.overtime_buffer_minutes),
      crosses_midnight: day.is_working_day ? detectCrossesMidnight(day.time_in, day.time_out) : false,
    }))
    payload.rest_days = payload.days.filter((d) => !d.is_working_day).map((d) => d.day_of_week)
    const firstWorking = payload.days.find((d) => d.is_working_day)
    if (firstWorking) {
      payload.time_in = firstWorking.time_in
      payload.time_out = firstWorking.time_out
      payload.break_start = firstWorking.break_start
      payload.break_end = firstWorking.break_end
      payload.grace_period_minutes = firstWorking.grace_period_minutes
      payload.early_timein_minutes = firstWorking.early_timein_minutes
      payload.overtime_buffer_minutes = firstWorking.overtime_buffer_minutes
      payload.expected_paid_minutes = firstWorking.expected_paid_minutes
      payload.half_day_threshold_minutes = firstWorking.half_day_threshold_minutes
    }
  }

  return payload
}
