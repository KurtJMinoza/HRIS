/** Fallback when options API is unavailable (keep in sync with PresenceFilingService). */
export const PRESENCE_FILING_REASON_OPTIONS_FALLBACK = [
  { value: 'forgot_punch', label: 'Forgot to clock in/out', requires_document: false },
  { value: 'system_issue', label: 'System issue', requires_document: true },
  { value: 'field_work', label: 'Field work', requires_document: true },
  { value: 'manual_override', label: 'Manual override', requires_document: true },
  { value: 'others', label: 'Others', requires_document: true },
]

export function reasonRequiresSupportingDocument(reasonCode, options = PRESENCE_FILING_REASON_OPTIONS_FALLBACK) {
  const code = String(reasonCode || '').trim()
  if (!code) return false
  const row = options.find((o) => o.value === code)
  return Boolean(row?.requires_document)
}
