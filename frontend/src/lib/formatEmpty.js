/**
 * Standard empty/null display for HRIS tables and detail views (em dash U+2014).
 */

export const EMPTY_PLACEHOLDER = '\u2014'

/** UTF-8 mojibake sequences often shown when em dash was mis-encoded. */
const MOJIBAKE_REPLACEMENTS = [
  [/\u00e2\u20ac\u201d/g, EMPTY_PLACEHOLDER],
  [/\u00e2\u20ac\u201c/g, EMPTY_PLACEHOLDER],
  [/\u00e2\u20ac\u2013/g, '\u2013'],
  [/\u00e2\u20ac\u2014/g, EMPTY_PLACEHOLDER],
  [/\u00e2\u20ac\u00a6/g, '\u2026'],
  [/\u00e2\u20ac\u00a2/g, '\u00b7'],
  [/\u00e2\u20ac\u02dc/g, '\u2018'],
  [/\u00e2\u20ac\u2122/g, '\u2019'],
]

/**
 * @param {unknown} value
 * @returns {boolean}
 */
export function isEmptyValue(value) {
  if (value == null) return true
  if (typeof value === 'string') {
    const t = repairMojibake(value).trim()
    return t === '' || t === EMPTY_PLACEHOLDER || t === '-' || t === '--'
  }
  return false
}

/**
 * Repair common UTF-8 mojibake in display strings.
 * @param {unknown} text
 * @returns {string}
 */
export function repairMojibake(text) {
  if (text == null || text === '') return ''
  let s = String(text)
  for (const [pattern, replacement] of MOJIBAKE_REPLACEMENTS) {
    s = s.replace(pattern, replacement)
  }
  return s
}

/**
 * @param {unknown} value
 * @param {string} [placeholder=EMPTY_PLACEHOLDER]
 * @returns {string}
 */
export function formatEmpty(value, placeholder = EMPTY_PLACEHOLDER) {
  if (isEmptyValue(value)) return placeholder
  return repairMojibake(String(value).trim())
}
