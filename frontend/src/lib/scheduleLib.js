/**
 * Client-side schedule math for previews (PH: 8h day, ND window 22:00–06:00, weekly rest).
 * Not a substitute for payroll engine — for admin UX warnings only.
 */

const DAY_KEYS = ['mon', 'tue', 'wed', 'thu', 'fri', 'sat', 'sun']

/** @param {string} hhmm - "HH:mm" or "H:mm" */
export function minutesFromMidnight(hhmm) {
  if (!hhmm || typeof hhmm !== 'string') return 0
  const [h, m] = hhmm.trim().slice(0, 5).split(':').map((x) => parseInt(x, 10))
  if (Number.isNaN(h) || Number.isNaN(m)) return 0
  return h * 60 + m
}

/** @returns {string} "HH:mm" */
export function minutesToHhMm(total) {
  let t = ((total % 1440) + 1440) % 1440
  const h = Math.floor(t / 60)
  const m = t % 60
  return `${String(h).padStart(2, '0')}:${String(m).padStart(2, '0')}`
}

/**
 * Net working minutes for one shift (handles night span).
 * @param {string|null|undefined} breakStart
 * @param {string|null|undefined} breakEnd
 */
export function netShiftMinutes(timeIn, timeOut, breakStart, breakEnd) {
  const a = minutesFromMidnight(timeIn)
  const b = minutesFromMidnight(timeOut)
  let span = b - a
  if (span <= 0) span += 24 * 60

  let br = 0
  if (breakStart && breakEnd) {
    const bs = minutesFromMidnight(breakStart)
    const be = minutesFromMidnight(breakEnd)
    let bspan = be - bs
    if (bspan < 0) bspan += 24 * 60
    br = Math.max(0, Math.min(bspan, span))
  }
  return Math.max(0, span - br)
}

/** ND window 22:00–06:00 (crosses midnight). Returns overlap minutes with [time_in, time_out). */
export function ndOverlapMinutes(timeIn, timeOut) {
  const ND_START = 22 * 60
  const ND_END = 6 * 60
  const a = minutesFromMidnight(timeIn)
  const b = minutesFromMidnight(timeOut)
  const crosses = b <= a

  function overlapSegment(segA, segB) {
    const len = Math.min(segB, 24 * 60) - Math.max(segA, 0)
    return len > 0 ? len : 0
  }

  if (!crosses) {
    let total = 0
    if (a < ND_END) total += overlapSegment(a, Math.min(b, ND_END))
    if (b > ND_START) total += overlapSegment(Math.max(a, ND_START), b)
    return total
  }

  const part1 = overlapSegment(a, 24 * 60)
  const part2 = overlapSegment(0, b)
  return part1 + part2
}

/**
 * @param {{ time_in: string, time_out: string, break_start?: string|null, break_end?: string|null, rest_days?: string[] }}
 */
export function weeklyScheduledHours(schedule) {
  const rest = new Set(Array.isArray(schedule.rest_days) ? schedule.rest_days : [])
  const workDays = DAY_KEYS.filter((d) => !rest.has(d))
  if (workDays.length === 0) return 0
  const perDay = netShiftMinutes(
    schedule.time_in,
    schedule.time_out,
    schedule.break_start,
    schedule.break_end
  )
  return (perDay / 60) * workDays.length
}

export function weeklyNdHours(schedule) {
  const rest = new Set(Array.isArray(schedule.rest_days) ? schedule.rest_days : [])
  const workDays = DAY_KEYS.filter((d) => !rest.has(d))
  if (workDays.length === 0) return 0
  const perDayMin = ndOverlapMinutes(schedule.time_in, schedule.time_out)
  return (perDayMin / 60) * workDays.length
}

/** @returns {'low'|'medium'|'high'} */
export function otRiskLevel(schedule) {
  const wh = weeklyScheduledHours(schedule)
  const daily = netShiftMinutes(schedule.time_in, schedule.time_out, schedule.break_start, schedule.break_end) / 60
  if (wh > 48 || daily > 10) return 'high'
  if (wh > 44 || daily > 9) return 'medium'
  return 'low'
}

export function hasWeeklyRestDay(schedule) {
  const rest = Array.isArray(schedule.rest_days) ? schedule.rest_days : []
  return rest.length >= 1
}

export { DAY_KEYS }
