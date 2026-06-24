/** Shared 6×7 month grid (same algorithm as Admin → Holidays & Employee dashboard). */

export const CALENDAR_MONTHS = [
  'January',
  'February',
  'March',
  'April',
  'May',
  'June',
  'July',
  'August',
  'September',
  'October',
  'November',
  'December',
]

export const CALENDAR_WEEKDAYS = ['SUN', 'MON', 'TUE', 'WED', 'THU', 'FRI', 'SAT']

/**
 * @param {number} year
 * @param {number} month 0–11
 * @param {Map<string, unknown>|null} [cellDataMap] keyed by YYYY-MM-DD
 */
export function buildMonthCalendarCells(year, month, cellDataMap = null) {
  const first = new Date(year, month, 1)
  const last = new Date(year, month + 1, 0)
  const startPad = first.getDay()
  const daysInMonth = last.getDate()
  const prevMonth = month === 0 ? 11 : month - 1
  const prevYear = month === 0 ? year - 1 : year
  const prevLast = new Date(prevYear, prevMonth + 1, 0).getDate()

  const cells = []
  for (let i = 0; i < startPad; i++) {
    const d = prevLast - startPad + 1 + i
    const dateStr = `${prevYear}-${String(prevMonth + 1).padStart(2, '0')}-${String(d).padStart(2, '0')}`
    cells.push({
      day: d,
      month: prevMonth,
      year: prevYear,
      dateStr,
      isAdjacent: true,
      data: cellDataMap?.get(dateStr) ?? null,
    })
  }
  for (let d = 1; d <= daysInMonth; d++) {
    const dateStr = `${year}-${String(month + 1).padStart(2, '0')}-${String(d).padStart(2, '0')}`
    cells.push({
      day: d,
      month,
      year,
      dateStr,
      isAdjacent: false,
      data: cellDataMap?.get(dateStr) ?? null,
    })
  }
  const remaining = 42 - cells.length
  for (let i = 0; i < remaining; i++) {
    const d = i + 1
    const nextMonth = month === 11 ? 0 : month + 1
    const nextYear = month === 11 ? year + 1 : year
    const dateStr = `${nextYear}-${String(nextMonth + 1).padStart(2, '0')}-${String(d).padStart(2, '0')}`
    cells.push({
      day: d,
      month: nextMonth,
      year: nextYear,
      dateStr,
      isAdjacent: true,
      data: cellDataMap?.get(dateStr) ?? null,
    })
  }
  return cells
}
