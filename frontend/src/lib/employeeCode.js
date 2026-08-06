/** Fixed Employee ID prefix — not editable in admin UI. */
export const EMPLOYEE_CODE_PREFIX = 'EMP-'

/**
 * Digits-only portion of an employee code (EMP- prefix stripped).
 * @param {unknown} code
 * @param {number} [maxDigits]
 */
export function employeeCodeDigits(code, maxDigits = 20) {
  const raw = String(code ?? '').trim()
  const withoutPrefix = raw.replace(/^EMP-?/i, '')
  return withoutPrefix.replace(/\D/g, '').slice(0, maxDigits)
}

/**
 * Build EMP-{digits}. Empty digits → empty string (caller may auto-generate).
 * @param {unknown} digitsOrCode
 */
export function composeEmployeeCode(digitsOrCode) {
  const digits = employeeCodeDigits(digitsOrCode)
  return digits ? `${EMPLOYEE_CODE_PREFIX}${digits}` : ''
}

/** @param {unknown} code */
export function isValidEmployeeCode(code) {
  return /^EMP-\d{1,20}$/.test(String(code ?? '').trim())
}
