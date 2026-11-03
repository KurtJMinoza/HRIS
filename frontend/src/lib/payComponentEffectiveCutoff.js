/** @param {Record<string, unknown>|null|undefined} period */
export function payComponentPeriodKey(period) {
  if (!period || typeof period !== 'object') return ''
  const start = String(period.cut_off_start_date || '')
  const end = String(period.cut_off_end_date || '')
  const pay = String(period.pay_date || '')
  if (!start || !end) return ''
  return `${start}|${end}|${pay}`
}

/** @param {Record<string, unknown>|null|undefined} cutoff */
export function readEffectiveUntilCutoffFromItem(item) {
  const empty = {
    until_cutoff_enabled: false,
    until_cutoff_pay_cycle_id: '',
    until_cutoff_period_key: '',
  }
  if (!item || typeof item !== 'object') {
    return empty
  }
  const cutoff = item.effective_until_cutoff
    || (item.metadata && typeof item.metadata === 'object' ? item.metadata.effective_until_cutoff : null)
  if (!cutoff || typeof cutoff !== 'object') {
    const legacyEnd = item.effective_to ? String(item.effective_to) : ''
    return {
      until_cutoff_enabled: legacyEnd !== '',
      until_cutoff_pay_cycle_id: '',
      until_cutoff_period_key: legacyEnd !== '' ? `legacy|${legacyEnd}|` : '',
    }
  }
  return {
    until_cutoff_enabled: true,
    until_cutoff_pay_cycle_id: cutoff.pay_cycle_id != null ? String(cutoff.pay_cycle_id) : '',
    until_cutoff_period_key: payComponentPeriodKey(cutoff),
  }
}

/**
 * @param {{ enabled: boolean, payCycleId: string, payCycleName?: string, period: Record<string, unknown>|null }} input
 */
export function buildEffectiveUntilCutoffMetadata({ enabled, payCycleId, payCycleName, period }) {
  if (!enabled || !period) return null
  const start = String(period.cut_off_start_date || '')
  const end = String(period.cut_off_end_date || '')
  if (!start || !end) return null
  return {
    pay_cycle_id: payCycleId ? Number(payCycleId) : null,
    pay_cycle_name: payCycleName || null,
    cut_off_start_date: start,
    cut_off_end_date: end,
    pay_date: period.pay_date ? String(period.pay_date) : null,
    cycle_label: period.cycle_label ? String(period.cycle_label) : null,
  }
}
