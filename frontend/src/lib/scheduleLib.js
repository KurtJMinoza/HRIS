/**
 * Client-side schedule math for previews.
 * Supports: fixed, flexible, split, overnight, rotating, compressed shifts;
 * multiple breaks (paid/unpaid); dynamic half-day thresholds.
 */

const DAY_KEYS = ['mon', 'tue', 'wed', 'thu', 'fri', 'sat', 'sun']

const SHIFT_TYPES = [
  { value: 'fixed', label: 'Fixed Shift' },
  { value: 'flexible', label: 'Flexible Shift' },
  { value: 'split', label: 'Split Shift' },
  { value: 'overnight', label: 'Overnight Shift' },
  { value: 'rotating', label: 'Rotating Shift' },
  { value: 'compressed', label: 'Compressed Work Week' },
]

/** @param {string} hhmm - "HH:mm" or "H:mm" */
function minutesFromMidnight(hhmm) {
  if (!hhmm || typeof hhmm !== 'string') return 0
  const [h, m] = hhmm.trim().slice(0, 5).split(':').map((x) => parseInt(x, 10))
  if (Number.isNaN(h) || Number.isNaN(m)) return 0
  return h * 60 + m
}

/** @returns {string} "HH:mm" */
function minutesToHhMm(total) {
  let t = ((total % 1440) + 1440) % 1440
  const h = Math.floor(t / 60)
  const m = t % 60
  return `${String(h).padStart(2, '0')}:${String(m).padStart(2, '0')}`
}

/**
 * Net working minutes for one shift (handles night span).
 * Supports multiple breaks via breaks array.
 */
function netShiftMinutes(timeIn, timeOut, breakStart, breakEnd, breaks) {
  const a = minutesFromMidnight(timeIn)
  const b = minutesFromMidnight(timeOut)
  let span = b - a
  if (span <= 0) span += 24 * 60

  let totalBreak = 0
  const mergedBreaks = []
  const seen = new Set()

  function pushBreak(start, end, isPaid = false) {
    if (!start || !end) return
    const key = `${start}|${end}`
    if (seen.has(key)) return
    seen.add(key)
    mergedBreaks.push({ start, end, is_paid: !!isPaid })
  }

  if (Array.isArray(breaks)) {
    for (const br of breaks) {
      pushBreak(br.start || br.break_start, br.end || br.break_end, br.is_paid)
    }
  }
  pushBreak(breakStart, breakEnd, false)

  if (mergedBreaks.length > 0) {
    for (const br of mergedBreaks) {
      if (br.is_paid) continue
      const bs = minutesFromMidnight(br.start)
      const be = minutesFromMidnight(br.end)
      let bspan = be - bs
      if (bspan < 0) bspan += 24 * 60
      totalBreak += Math.max(0, Math.min(bspan, span))
    }
  }

  return Math.max(0, span - totalBreak)
}

/**
 * Compute paid minutes for a schedule (supports explicit override, split shift blocks).
 */
function computePaidMinutes(schedule) {
  if (schedule.expected_paid_minutes && Number(schedule.expected_paid_minutes) > 0) {
    return Number(schedule.expected_paid_minutes)
  }

  if (schedule.shift_type === 'split' && Array.isArray(schedule.work_blocks) && schedule.work_blocks.length > 0) {
    let total = 0
    for (const block of schedule.work_blocks) {
      const s = minutesFromMidnight(block.start)
      const e = minutesFromMidnight(block.end)
      let dur = e - s
      if (dur <= 0) dur += 24 * 60
      total += dur
    }
    return total
  }

  // Legacy flexible hours-window mode only. Per-day flexible uses fixed-style times.
  if (
    schedule.shift_type === 'flexible'
    && schedule.flexible_required_minutes
    && !schedule.time_in
  ) {
    return Number(schedule.flexible_required_minutes)
  }

  return netShiftMinutes(
    schedule.time_in,
    schedule.time_out,
    schedule.break_start,
    schedule.break_end,
    schedule.breaks
  )
}

/**
 * Half-day threshold: explicit, or paid_minutes / 2
 */
function halfDayThresholdMinutes(schedule) {
  if (schedule.half_day_threshold_minutes && Number(schedule.half_day_threshold_minutes) > 0) {
    return Number(schedule.half_day_threshold_minutes)
  }
  return Math.floor(computePaidMinutes(schedule) / 2)
}

/** ND window 22:00–06:00 (crosses midnight). Returns overlap minutes with [time_in, time_out). */
function ndOverlapMinutes(timeIn, timeOut) {
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

function weeklyScheduledHours(schedule) {
  const rest = new Set(Array.isArray(schedule.rest_days) ? schedule.rest_days : [])
  const workDays = DAY_KEYS.filter((d) => !rest.has(d))
  if (workDays.length === 0) return 0
  const perDay = computePaidMinutes(schedule)
  return (perDay / 60) * workDays.length
}

function weeklyNdHours(schedule) {
  const rest = new Set(Array.isArray(schedule.rest_days) ? schedule.rest_days : [])
  const workDays = DAY_KEYS.filter((d) => !rest.has(d))
  if (workDays.length === 0) return 0
  const perDayMin = ndOverlapMinutes(schedule.time_in, schedule.time_out)
  return (perDayMin / 60) * workDays.length
}

/** @returns {'low'|'medium'|'high'} */
function otRiskLevel(schedule) {
  const wh = weeklyScheduledHours(schedule)
  const daily = computePaidMinutes(schedule) / 60
  if (wh > 48 || daily > 10) return 'high'
  if (wh > 44 || daily > 9) return 'medium'
  return 'low'
}

function hasWeeklyRestDay(schedule) {
  const rest = Array.isArray(schedule.rest_days) ? schedule.rest_days : []
  return rest.length >= 1
}

/**
 * Format paid minutes as human-readable string: "8h 0m" or "7h 30m"
 */
function formatPaidHours(minutes) {
  if (!minutes || minutes <= 0) return '0h'
  const h = Math.floor(minutes / 60)
  const m = minutes % 60
  if (m === 0) return `${h}h`
  return `${h}h ${m}m`
}

/**
 * Detect if shift crosses midnight based on time_in and time_out.
 */
function detectCrossesMidnight(timeIn, timeOut) {
  if (!timeIn || !timeOut) return false
  return minutesFromMidnight(timeOut) <= minutesFromMidnight(timeIn)
}

export {
  DAY_KEYS,
  SHIFT_TYPES,
  minutesFromMidnight,
  minutesToHhMm,
  netShiftMinutes,
  computePaidMinutes,
  halfDayThresholdMinutes,
  ndOverlapMinutes,
  weeklyScheduledHours,
  weeklyNdHours,
  otRiskLevel,
  hasWeeklyRestDay,
  formatPaidHours,
  detectCrossesMidnight,
}
