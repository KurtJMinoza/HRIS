/** Coerce API / form values to plain text for display and search (never throws). */
export function toDisplayText(value) {
  if (value == null || value === '') return ''
  if (typeof value === 'string') return value
  if (typeof value === 'number' || typeof value === 'boolean') return String(value)
  if (typeof value === 'object') {
    if (typeof value.name === 'string') return value.name
    if (typeof value.display_name === 'string') return value.display_name
    if (typeof value.formatted_name === 'string') return value.formatted_name
    if (typeof value.label === 'string') return value.label
    if (typeof value.title === 'string') return value.title
    return ''
  }
  return ''
}

export function employeeDisplayName(employee) {
  return (
    toDisplayText(employee?.name)
    || toDisplayText(employee?.display_name)
    || toDisplayText(employee?.formatted_name)
    || 'Unknown'
  )
}

export function employeeSearchHaystack(employee) {
  return [
    employee?.name,
    employee?.display_name,
    employee?.formatted_name,
    employee?.employee_code,
    employee?.email,
    employee?.position,
    employee?.department,
    employee?.department_name,
    employee?.branch_name,
    employee?.company_name,
  ]
    .map(toDisplayText)
    .filter(Boolean)
    .join(' ')
    .toLowerCase()
}

export function filterEmployeesByQuery(employees, query) {
  const q = String(query || '').trim().toLowerCase()
  if (!q) return employees
  return (employees || []).filter((emp) => employeeSearchHaystack(emp).includes(q))
}

export function normalizeLeaderUserId(id) {
  if (id == null || id === '') return ''
  if (typeof id === 'object') return String(id.id ?? '')
  return String(id)
}

/** Normalize org-head summary from list row fields for AssignOrgHeadModal. */
export function buildOrgCurrentHead({
  id,
  name,
  profile_image_url,
  profile_image,
  employee_code,
  position,
} = {}) {
  const normalizedId = normalizeLeaderUserId(id)
  if (!normalizedId) return null

  const displayName = employeeDisplayName({
    name,
    display_name: name,
    formatted_name: typeof name === 'string' ? name : undefined,
  })
  if (!displayName || displayName === 'Unknown') return null

  return {
    id: normalizedId,
    name: displayName,
    profile_image_url: toDisplayText(profile_image_url || profile_image) || null,
    employee_code: toDisplayText(employee_code),
    position: toDisplayText(position),
  }
}

/** True when the employee has no direct company_id on their profile (still assignable to org units). */
export function isEmployeeCompanyUnassigned(employee) {
  const companyId = employee?.company_id
  return companyId == null || companyId === ''
}
