/**
 * Display helpers for 24h API times (HH:mm). Storage and APIs stay 24-hour.
 */

import { EMPTY_PLACEHOLDER, isEmptyValue, repairMojibake } from '@/lib/formatEmpty'

/**
 * Value for `<input type="time">`: empty string or "HH:mm" (24h, zero-padded).
 * Parses "H:mm", "HH:mm:ss", ISO datetimes, and rejects placeholders like "—".
 * @param {unknown} value
 * @returns {string}
 */
export function toTimeInputValue(value) {
  if (value == null || value === '') return ''
  let s = String(value).trim().replace(/^\uFEFF/, '')
  if (!s || s === EMPTY_PLACEHOLDER || s === '-' || s === '--') return ''
  const ampm = s.match(/^(\d{1,2}):(\d{2})(?::(\d{2}))?(?:\.\d+)?\s*(am|pm)\s*$/i)
  if (ampm) {
    let h = parseInt(ampm[1], 10)
    const min = parseInt(ampm[2], 10)
    const ap = ampm[4].toUpperCase()
    if (Number.isNaN(h) || Number.isNaN(min) || min < 0 || min > 59) return ''
    if (ap === 'PM' && h !== 12) h += 12
    if (ap === 'AM' && h === 12) h = 0
    if (h < 0 || h > 23) return ''
    return `${String(h).padStart(2, '0')}:${String(min).padStart(2, '0')}`
  }
  const clock = s.match(/^(\d{1,2}):(\d{2})(?::(\d{2}))?(?:\.\d+)?$/)
  if (clock) {
    const h = parseInt(clock[1], 10)
    const min = parseInt(clock[2], 10)
    if (
      Number.isNaN(h) ||
      Number.isNaN(min) ||
      h < 0 ||
      h > 23 ||
      min < 0 ||
      min > 59
    ) {
      return ''
    }
    return `${String(h).padStart(2, '0')}:${String(min).padStart(2, '0')}`
  }
  const d = new Date(s)
  if (!Number.isNaN(d.getTime())) {
    return `${String(d.getHours()).padStart(2, '0')}:${String(d.getMinutes()).padStart(2, '0')}`
  }
  return ''
}

/** Normalize time string to HH:mm. Handles "HH:mm" or "HH:mm:ss". */
export function toHhMm(value) {
  if (value == null || typeof value !== 'string') return value
  const trimmed = value.trim()
  if (!trimmed) return trimmed
  const tv = toTimeInputValue(trimmed)
  return tv || trimmed.slice(0, 5)
}

/**
 * Single clock value, e.g. "8:00 AM" (no leading zero on hour 1–9; space before AM/PM).
 * @param {string|null|undefined} value
 * @returns {string}
 */
export function formatHHmmTo12h(value) {
  if (value == null || value === '') return ''
  const s = String(value).trim()
  const m = s.match(/^(\d{1,2}):(\d{2})(?::(\d{2}))?$/)
  if (!m) return s
  let h = parseInt(m[1], 10)
  const min = parseInt(m[2], 10)
  if (Number.isNaN(h) || Number.isNaN(min)) return s
  const period = h >= 12 ? 'PM' : 'AM'
  const h12 = h % 12 || 12
  return `${h12}:${String(min).padStart(2, '0')} ${period}`
}

/**
 * Shift window, e.g. "8:00 AM – 5:00 PM".
 * @param {string|null|undefined} start
 * @param {string|null|undefined} end
 * @param {string} [sep=' – ']
 */
export function formatShiftRange12h(start, end, sep = ' – ') {
  const a = formatHHmmTo12h(toHhMm(start))
  const b = formatHHmmTo12h(toHhMm(end))
  if (!a && !b) return EMPTY_PLACEHOLDER
  if (!a) return b
  if (!b) return a
  return `${a}${sep}${b}`
}

/**
 * Backend schedule labels like "08:00 – 17:00", or "Rest day". Passthrough if not a simple range.
 * @param {string|null|undefined} label
 */
export function formatScheduleLabel12h(label) {
  if (isEmptyValue(label)) return EMPTY_PLACEHOLDER
  const s = repairMojibake(String(label).trim())
  if (s === 'Rest day' || s === EMPTY_PLACEHOLDER || s === '-') return s
  const range = s.match(/^(\d{1,2}:\d{2})\s*[-\u2013]\s*(\d{1,2}:\d{2})$/)
  if (range) return formatShiftRange12h(range[1], range[2])
  return s
}

/**
 * Attendance / UI: ISO datetime, HH:mm, or HH:mm:ss string → 12h display.
 * @param {string|null|undefined} value
 */
export function formatClockTimeDisplay(value) {
  if (isEmptyValue(value)) return EMPTY_PLACEHOLDER
  if (typeof value === 'string') {
    const t = repairMojibake(value).trim()
    if (/^\d{1,2}:\d{2}$/.test(t)) return formatHHmmTo12h(t) || EMPTY_PLACEHOLDER
    if (/^\d{1,2}:\d{2}:\d{2}$/.test(t)) return formatHHmmTo12h(t.slice(0, 5)) || EMPTY_PLACEHOLDER
  }
  try {
    const d = new Date(value)
    if (Number.isNaN(d.getTime())) return String(value)
    const h = d.getHours()
    const min = d.getMinutes()
    const period = h >= 12 ? 'PM' : 'AM'
    const h12 = h % 12 || 12
    return `${h12}:${String(min).padStart(2, '0')} ${period}`
  } catch {
    return String(value)
  }
}
