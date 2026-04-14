/**
 * Display helpers for 24h API times (HH:mm). Storage and APIs stay 24-hour.
 */

/** Normalize time string to HH:mm. Handles "HH:mm" or "HH:mm:ss". */
export function toHhMm(value) {
  if (value == null || typeof value !== 'string') return value
  const trimmed = value.trim()
  if (!trimmed) return trimmed
  return trimmed.length >= 5 ? trimmed.slice(0, 5) : trimmed
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
  if (!a && !b) return '—'
  if (!a) return b
  if (!b) return a
  return `${a}${sep}${b}`
}

/**
 * Backend schedule labels like "08:00 – 17:00", or "Rest day". Passthrough if not a simple range.
 * @param {string|null|undefined} label
 */
export function formatScheduleLabel12h(label) {
  if (label == null || label === '') return '—'
  const s = String(label).trim()
  if (s === 'Rest day' || s === '—' || s === '-') return s
  const range = s.match(/^(\d{1,2}:\d{2})\s*[\u2013\-]\s*(\d{1,2}:\d{2})$/)
  if (range) return formatShiftRange12h(range[1], range[2])
  return s
}

/**
 * Attendance / UI: ISO datetime, HH:mm, or HH:mm:ss string → 12h display.
 * @param {string|null|undefined} value
 */
export function formatClockTimeDisplay(value) {
  if (value == null || value === '') return '—'
  if (typeof value === 'string') {
    const t = value.trim()
    if (/^\d{1,2}:\d{2}$/.test(t)) return formatHHmmTo12h(t) || '—'
    if (/^\d{1,2}:\d{2}:\d{2}$/.test(t)) return formatHHmmTo12h(t.slice(0, 5)) || '—'
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
