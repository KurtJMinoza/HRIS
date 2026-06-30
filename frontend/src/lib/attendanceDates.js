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
