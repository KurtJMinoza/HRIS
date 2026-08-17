/**
 * Half-day leave labels — "am"/"pm" in the API mean which half is leave,
 * not which half the employee works. Keep wording leave-first to avoid that mix-up.
 */

export function halfDayTypeShortLabel(halfType) {
  const t = String(halfType || '').toLowerCase()
  if (t === 'am') return 'Morning leave'
  if (t === 'pm') return 'Afternoon leave'
  return null
}

export function halfDayTypeOptionLabel(halfType, windows = null) {
  const t = String(halfType || '').toLowerCase()
  if (t === 'am') {
    const w = windows?.am
    if (w?.work_start && w?.work_end) {
      return `Morning leave — off first half, work ${w.work_start}–${w.work_end}`
    }
    return 'Morning leave — off first half, work second half'
  }
  if (t === 'pm') {
    const w = windows?.pm
    if (w?.work_start && w?.work_end) {
      return `Afternoon leave — work ${w.work_start}–${w.work_end}, off second half`
    }
    return 'Afternoon leave — work first half, off second half'
  }
  return 'Half day'
}

export function halfDayTypeListLabel(halfType) {
  const short = halfDayTypeShortLabel(halfType)
  return short ? `Half day (${short})` : 'Half day'
}

/** Boundary time field: when the worked half starts (AM leave) or ends (PM leave). */
export function halfDayBoundaryTimeLabel(halfType) {
  const t = String(halfType || '').toLowerCase()
  if (t === 'pm') return 'Leave starts at'
  if (t === 'am') return 'Work starts at'
  return 'Half-day boundary time'
}
