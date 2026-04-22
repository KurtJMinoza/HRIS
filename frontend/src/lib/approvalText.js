export function sanitizeApprovalDisplayText(value) {
  const raw = value == null ? '' : String(value)
  if (!raw.trim()) return ''

  const cleaned = raw.replace(
    /\s*\[?Routed directly to Admin due to missing\/invalid required heads:\s*[^\]\n]*(?:\])?/gi,
    ' '
  )

  return cleaned.replace(/[ \t]{2,}/g, ' ').trim()
}

