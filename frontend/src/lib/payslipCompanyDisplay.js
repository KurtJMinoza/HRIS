/** Neutral placeholders when company registry fields are missing on payslips. */

export function displayCompanyTin(value) {
  if (value == null || String(value).trim() === '') return '—'
  return String(value).trim()
}

export function displayCompanyAddress(value) {
  if (value == null || String(value).trim() === '') return 'No address provided'
  return String(value).trim()
}
