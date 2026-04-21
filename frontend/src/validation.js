/**
 * Real-time form validation: required fields, no emojis, no fancy Unicode.
 */

// Only basic Latin letters, space, hyphen, apostrophe (e.g. O'Brien, Mary-Jane)
const ALLOWED_NAME = /^[a-zA-Z\s\-']*$/
// Email: letters, digits, . _ % + - @
const ALLOWED_EMAIL = /^[a-zA-Z0-9._%+\-@]*$/
const ALLOWED_LOGIN = /^[a-zA-Z0-9._%+\-@]*$/
const ALLOWED_USERNAME = /^[a-zA-Z0-9._]*$/
// Password: printable ASCII only (no emojis or fancy symbols)
const ALLOWED_PASSWORD = /^[\x20-\x7E]*$/

// Emoji and symbol ranges (catches most emojis and decorative symbols)
// Emoji detection via code points (avoids regex astral-plane issues in some linters)
function hasEmojiCode(s) {
  for (let i = 0; i < s.length; i++) {
    const code = s.codePointAt(i)
    if (code === undefined) continue
    if ((code >= 0x2600 && code <= 0x27BF) || (code >= 0xFE00 && code <= 0xFE0F)) return true
    if (code >= 0x1F300 && code <= 0x1F9FF) return true
    if (code === 0x231A || code === 0x231B || code === 0x2B50) return true
    if (code > 0xFFFF) i++
  }
  return false
}

// Non-ASCII "lookalike" letters (e.g. mathematical alphanumeric, fullwidth, script)
const FANCY_UNICODE = /[\u00C0-\u024F\u1D00-\u1D7F\u1D80-\u1DBF\u1E00-\u1EFF\u2100-\u214F\uFF00-\uFFEF]/

export function hasEmoji(str) {
  return hasEmojiCode(str)
}

export function hasFancyUnicode(str) {
  return FANCY_UNICODE.test(str)
}

export function sanitizeName(value) {
  return value.replace(/[^a-zA-Z\s\-']/g, '')
}

export function sanitizeEmail(value) {
  return value.replace(/[^a-zA-Z0-9._%+\-@]/g, '')
}

export function sanitizeLogin(value) {
  return value.replace(/[^a-zA-Z0-9._%+\-@]/g, '')
}

export function sanitizeUsername(value) {
  return value.replace(/[^a-zA-Z0-9._]/g, '')
}

export function sanitizePassword(value) {
  return value.replace(/[^\x20-\x7E]/g, '')
}

const EMAIL_FORMAT = /^[a-zA-Z0-9._%+-]+@[a-zA-Z0-9.-]+\.[a-zA-Z]{2,}$/

export function validateName(value, fieldLabel = 'This field') {
  const trimmed = value.trim()
  if (!trimmed) return `${fieldLabel} is required.`
  if (hasEmoji(trimmed)) return 'Emojis are not allowed.'
  if (hasFancyUnicode(trimmed)) return 'Please use standard letters only (no special or styled characters).'
  if (!ALLOWED_NAME.test(trimmed)) return 'Only letters, spaces, hyphens, and apostrophes are allowed.'
  if (trimmed.length > 100) return `${fieldLabel} must be 100 characters or less.`
  return ''
}

export function validateEmail(value, required = true) {
  const trimmed = value.trim()
  if (!trimmed) return required ? 'Email is required.' : ''
  if (hasEmoji(trimmed)) return 'Emojis are not allowed.'
  if (hasFancyUnicode(trimmed)) return 'Please use standard characters only.'
  if (!ALLOWED_EMAIL.test(trimmed)) return 'Email can only contain letters, numbers, and . _ % + - @'
  if (!EMAIL_FORMAT.test(trimmed)) return 'Please enter a valid email address.'
  if (trimmed.length > 255) return 'Email is too long.'
  return ''
}

export function validateLoginIdentifier(value, required = true) {
  const trimmed = value.trim()
  if (!trimmed) return required ? 'Username or email is required.' : ''
  if (hasEmoji(trimmed)) return 'Emojis are not allowed.'
  if (hasFancyUnicode(trimmed)) return 'Please use standard characters only.'
  if (!ALLOWED_LOGIN.test(trimmed)) return 'Use only letters, numbers, and . _ % + - @'
  if (trimmed.length > 255) return 'Username or email is too long.'
  return ''
}

export function validateUsername(value, required = true) {
  const trimmed = value.trim()
  if (!trimmed) return required ? 'Username is required.' : ''
  if (!ALLOWED_USERNAME.test(trimmed)) return 'Username can only contain letters, numbers, underscores, and dots (no spaces).'
  if (trimmed.length > 255) return 'Username is too long.'
  return ''
}

export function validatePassword(value, isSignup = false) {
  if (!value) return 'Password is required.'
  if (hasEmoji(value)) return 'Emojis are not allowed.'
  if (!ALLOWED_PASSWORD.test(value)) return 'Please use only standard keyboard characters (no emojis or special fonts).'
  if (value.length < 8) return 'Password must be at least 8 characters.'
  if (isSignup) {
    if (!/[a-zA-Z]/.test(value)) return 'Password must include at least one letter.'
    if (!/[0-9]/.test(value)) return 'Password must include at least one number.'
  }
  if (value.length > 128) return 'Password is too long.'
  return ''
}

export function validateConfirmPassword(password, confirm) {
  if (!confirm) return 'Please confirm your password.'
  if (password !== confirm) return 'Passwords do not match.'
  return ''
}

// Philippine mobile: +63 9XXXXXXXXX, +639XXXXXXXXX, or 09XXXXXXXXX
const PH_MOBILE_REGEX = /^(\+63\s?9\d{9}|09\d{9})$/

export function validatePhone(value, required = true) {
  const trimmed = value.trim()
  if (!trimmed) return required ? 'Mobile number is required. Use +63 followed by 10 digits (e.g. +63 912 345 6789).' : ''
  if (!PH_MOBILE_REGEX.test(trimmed)) return 'Phone number must start with +63 and be followed by exactly 10 digits (e.g. +63 912 345 6789).'
  return ''
}

/** Allow digits, +, and spaces only for phone input. */
export function sanitizePhone(value) {
  return value.replace(/[^\d+\s]/g, '')
}

/** Split "Full Name" into first_name and last_name for API. */
export function parseFullName(fullName) {
  const t = fullName.trim()
  const i = t.indexOf(' ')
  if (i < 0) return { firstName: t || '', lastName: t || '' }
  return {
    firstName: t.slice(0, i),
    lastName: t.slice(i + 1).trim() || t.slice(0, i),
  }
}
