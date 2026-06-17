import { hasEmoji, hasFancyUnicode } from '@/validation'
import { toHhMm } from '@/lib/timeFormat'

/** Default weekly days off for new templates (PH common case: single Sunday off). */
export const DEFAULT_REST_DAYS = ['sun']

export function createDefaultScheduleForm() {
  return {
    name: '',
    time_in: '08:00',
    break_start: '',
    break_end: '',
    time_out: '17:00',
    grace_period_minutes: 5,
    early_timein_minutes: 60,
    late_allowance_minutes: '',
    early_timeout_minutes: '',
    overtime_buffer_minutes: 15,
    rest_days: [...DEFAULT_REST_DAYS],
  }
}

export function scheduleRecordToForm(schedule) {
  if (!schedule) return createDefaultScheduleForm()
  return {
    name: schedule.name || '',
    time_in: toHhMm(schedule.time_in) || '08:00',
    break_start: toHhMm(schedule.break_start) || '',
    break_end: toHhMm(schedule.break_end) || '',
    time_out: toHhMm(schedule.time_out) || '17:00',
    grace_period_minutes: schedule.grace_period_minutes ?? 5,
    early_timein_minutes: schedule.early_timein_minutes ?? 60,
    late_allowance_minutes: schedule.late_allowance_minutes ?? '',
    early_timeout_minutes: schedule.early_timeout_minutes ?? '',
    overtime_buffer_minutes: schedule.overtime_buffer_minutes ?? 15,
    rest_days:
      Array.isArray(schedule.rest_days) && schedule.rest_days.length > 0
        ? [...schedule.rest_days]
        : [...DEFAULT_REST_DAYS],
  }
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
  return {
    name: editForm.name.trim(),
    time_in: toHhMm(editForm.time_in) || editForm.time_in,
    break_start: editForm.break_start ? toHhMm(editForm.break_start) : null,
    break_end: editForm.break_end ? toHhMm(editForm.break_end) : null,
    time_out: toHhMm(editForm.time_out) || editForm.time_out,
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
  }
}
