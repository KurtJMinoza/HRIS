function sameUserId(a, b) {
  if (a == null || b == null) return false
  return String(a) === String(b)
}

/** Keep org-unit table counts aligned with View Employees modal (same API source). */
export function patchOrgUnitEmployeeCount(rows, unitId, count) {
  if (unitId == null || count == null) return rows
  const normalized = Number(count)
  if (!Number.isFinite(normalized) || normalized < 0) return rows

  return rows.map((row) =>
    sameUserId(row.id, unitId)
      ? {
          ...row,
          assigned_employee_count: normalized,
          total_employees: normalized,
        }
      : row,
  )
}

export function resolveOrgUnitEmployeeCount(data, list) {
  const employees = Array.isArray(list) ? list : Array.isArray(data?.employees) ? data.employees : []
  const fromAssigned = data?.assigned_employee_count
  if (fromAssigned != null && Number.isFinite(Number(fromAssigned))) {
    return Number(fromAssigned)
  }
  const fromApi = data?.employee_count
  if (fromApi != null && Number.isFinite(Number(fromApi))) {
    return Number(fromApi)
  }
  return employees.length
}

export function assignmentSourceLabel(source) {
  const normalized = String(source || 'primary').toLowerCase()
  if (normalized === 'shared') return 'Shared'
  if (normalized === 'temporary') return 'Temporary'
  if (normalized === 'acting') return 'Acting'
  return 'Primary'
}
