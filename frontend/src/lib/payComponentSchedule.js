/**
 * Central mappings for employee pay-component schedule_override (aligned with backend {@see PayComponentSchedule}).
 * Storage: API returns null → follow company Deduction Schedule Settings for that component.
 */

export const SCHEDULE_OVERRIDE_LABELS = {
  first_run: '15th',
  second_run: 'End of month',
  split: '15/30 Split',
}

/** @param {unknown} slug */
export function labelForStoredOverride(slug, emptyLabel = 'Use default') {
  let key = slug != null ? String(slug).trim() : ''
  if (!key || key === 'default') return emptyLabel
  if (key === 'monthly') key = 'split'
  return SCHEDULE_OVERRIDE_LABELS[key] || emptyLabel
}

/** DeductionScheduleSetting.schedule_type tokens from compensation summaries */
export function labelForResolvedSchedule(resolvedType) {
  const t = String(resolvedType || '').trim().toLowerCase()
  if (t === '15th') return 'First semi-monthly run'
  if (t === '30th') return 'End of month'
  if (t === 'both') return '15/30 Split'
  return resolvedType ? String(resolvedType) : '—'
}

export function badgeClassForStoredOverride(slug) {
  const key = slug != null ? String(slug).trim() : ''
  if (!key || key === 'default') return 'bg-slate-100 text-slate-600'
  if (key === 'first_run') return 'bg-blue-50 text-blue-700'
  if (key === 'second_run') return 'bg-purple-50 text-purple-700'
  if (key === 'split' || key === 'monthly') return 'bg-emerald-50 text-emerald-700'
  return 'bg-slate-100 text-slate-600'
}
