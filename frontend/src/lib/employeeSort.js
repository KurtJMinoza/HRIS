/**
 * Employee roster ordering aligned with backend {@see User::scopeOrderByLastName}:
 * last_name → first_name → middle_name → id.
 */
export function compareEmployeesByLastName(a, b) {
  const ln = String(a?.last_name ?? '').localeCompare(String(b?.last_name ?? ''), undefined, {
    sensitivity: 'base',
  })
  if (ln !== 0) return ln
  const fn = String(a?.first_name ?? '').localeCompare(String(b?.first_name ?? ''), undefined, {
    sensitivity: 'base',
  })
  if (fn !== 0) return fn
  const mn = String(a?.middle_name ?? '').localeCompare(String(b?.middle_name ?? ''), undefined, {
    sensitivity: 'base',
  })
  if (mn !== 0) return mn
  return (Number(a?.id) || 0) - (Number(b?.id) || 0)
}

export function formatEmployeeName(employee, fallback = '') {
  const preferred =
    employee?.formatted_name ||
    employee?.display_name ||
    employee?.full_name_last_first
  if (preferred && String(preferred).trim() !== '') return String(preferred).trim()

  const first = String(employee?.first_name ?? '').trim()
  const middle = String(employee?.middle_name ?? '').trim()
  const last = String(employee?.last_name ?? '').trim()
  const suffix = String(employee?.suffix ?? '').trim()
  const given = [first, middle].filter(Boolean).join(' ')
  const base = last && given ? `${last}, ${given}` : last || given
  const formatted = [base, suffix].filter(Boolean).join(' ').trim()
  if (formatted) return formatted

  const legacy = employee?.employee_name || employee?.name
  return legacy && String(legacy).trim() !== '' ? String(legacy).trim() : fallback
}

/** For TanStack Table row objects (`rowA.original`, `rowB.original`) with optional `employee_sort_key`. */
export function compareEmployeeRowsBySortKey(rowA, rowB) {
  const ra = rowA?.original
  const rb = rowB?.original
  const ka = ra?.employee_sort_key
  const kb = rb?.employee_sort_key
  if (ka != null && kb != null && ka !== '' && kb !== '') {
    return String(ka).localeCompare(String(kb), undefined, { sensitivity: 'base' })
  }
  return compareEmployeesByLastName(ra, rb)
}
