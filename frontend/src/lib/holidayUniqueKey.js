/** @param {string} name */
function normalizeHolidayName(name) {
  return String(name || '')
    .trim()
    .toLowerCase()
    .replace(/\s+/g, ' ')
}

/** @param {object} holiday */
export function upcomingHolidaySourceRank(holiday) {
  const source = String(holiday?.source || 'custom').toLowerCase()
  if (source === 'custom') return 30
  if (source === 'recurring') return 20
  if (source === 'seeded') return 10
  return 0
}

/** Build stable dedupe key aligned with backend upcomingHolidayUniqueKey. */
export function upcomingHolidayUniqueKey(holiday) {
  if (holiday?.unique_key) return String(holiday.unique_key)
  const date = holiday?.date || holiday?.holiday_date || ''
  const coverageIds = Array.isArray(holiday?.coverage_ids) ? holiday.coverage_ids : []
  return [
    date,
    normalizeHolidayName(holiday?.holiday_name || holiday?.name),
    String(holiday?.type || holiday?.holiday_type || '').toLowerCase(),
    String(holiday?.scope || '').toLowerCase(),
    String(holiday?.scope_type || '').toLowerCase(),
    JSON.stringify(coverageIds),
    String(holiday?.company_id ?? 0),
    String(holiday?.branch_id ?? 0),
    String(holiday?.department_id ?? 0),
    String(holiday?.employee_id ?? 0),
  ].join('|')
}
