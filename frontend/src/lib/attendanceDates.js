/**
 * Default matches backend `config('attendance.timezone')` (typically Asia/Manila).
 */
export const ATTENDANCE_TIMEZONE = 'Asia/Manila'

/**
 * Calendar YYYY-MM-DD for `date` in the given IANA timezone.
 */
export function calendarYmdInTimeZone(date, timeZone = ATTENDANCE_TIMEZONE) {
  return new Intl.DateTimeFormat('en-CA', {
    timeZone,
    year: 'numeric',
    month: '2-digit',
    day: '2-digit',
  }).format(date)
}

/**
 * Earliest start date when filing leave for any role (tomorrow in attendance TZ).
 */
export function earliestLeaveStartYmd(timeZone = ATTENDANCE_TIMEZONE) {
  const todayStr = calendarYmdInTimeZone(new Date(), timeZone)
  const [y, m, d] = todayStr.split('-').map(Number)
  const next = new Date(Date.UTC(y, m - 1, d + 1, 12, 0, 0))
  return calendarYmdInTimeZone(next, timeZone)
}
