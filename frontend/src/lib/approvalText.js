export function sanitizeApprovalDisplayText(value) {
  const raw = value == null ? '' : String(value)
  if (!raw.trim()) return ''

  const cleaned = raw.replace(
    /\s*\[?Routed directly to Admin due to missing\/invalid required heads:\s*[^\]\n]*(?:\])?/gi,
    ' '
  )

  return cleaned.replace(/[ \t]{2,}/g, ' ').trim()
}

export function normalizeApprovalHeadTitle(value) {
  const raw = sanitizeApprovalDisplayText(value)
  if (!raw) return ''

  return raw
    .replace(/\bArea Head\s*\/\s*Area Manager\b/gi, 'Area Head')
    .replace(/\bCompany Head\s*\/\s*Company Manager\b/gi, 'Company Head')
    .replace(/\bBranch Head\s*\/\s*Branch Manager\b/gi, 'Branch Head')
    .replace(/\bDepartment Head\s*\/\s*Department Manager\b/gi, 'Department Head')
    .replace(/\bDivision Head\s*\/\s*Division Manager\b/gi, 'Division Head')
    .replace(/\s+final\s+approval\b/gi, ' final')
    .replace(/\s+approval\b/gi, '')
    .replace(/[ \t]{2,}/g, ' ')
    .replace(/\s+[.-]$/, '')
    .trim()
}

export function normalizeApprovalStatusLabel(value) {
  const raw = sanitizeApprovalDisplayText(value)
  if (!raw) return ''

  return raw
    .replace(/\bArea Head\s*\/\s*Area Manager\b/gi, 'Area Head')
    .replace(/\bCompany Head\s*\/\s*Company Manager\b/gi, 'Company Head')
    .replace(/\bBranch Head\s*\/\s*Branch Manager\b/gi, 'Branch Head')
    .replace(/\bDepartment Head\s*\/\s*Department Manager\b/gi, 'Department Head')
    .replace(/\bDivision Head\s*\/\s*Division Manager\b/gi, 'Division Head')
    .replace(/[ \t]{2,}/g, ' ')
    .trim()
}

