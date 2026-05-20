/** Holiday pay multiplier label for dashboard / list UIs. */
export function formatHolidayMultiplierLabel(holiday) {
  const label = String(holiday?.multiplier_label ?? '').trim()
  if (label && label !== 'null' && label !== 'undefined') return label
  const raw = holiday?.multiplier ?? holiday?.pay_rate_multiplier
  if (raw == null || raw === '') return '-'
  const n = Number(raw)
  if (!Number.isFinite(n) || n <= 0) return '-'
  const pct = n <= 3 ? Math.round(n * 100) : Math.round(n)
  return `${pct}%`
}

export function holidayMultiplierBadgeClass() {
  return 'border-sky-500/35 bg-sky-500/12 text-sky-900 dark:text-sky-100'
}
