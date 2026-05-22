import { employeeDisplayName, isEmployeeCompanyUnassigned } from '@/lib/employeeSearch'

function sameUserId(a, b) {
  return String(a ?? '') === String(b ?? '') && String(a ?? '') !== ''
}

export const ASSIGNMENT_MODE_SHARED = 'shared'
export const ASSIGNMENT_MODE_TRANSFER_PRIMARY = 'transfer_primary'

export function employeeCurrentOrgPath(employee) {
  if (employee?.current_org_path) {
    return employee.current_org_path
  }

  return [
    employee?.company_name,
    employee?.branch_name,
    employee?.division_name,
    employee?.department_name || employee?.department,
    employee?.section_unit_name,
  ]
    .filter(Boolean)
    .join(' > ')
}

export function isCrossCompanyForTarget(employee, targetCompanyId) {
  if (targetCompanyId == null || targetCompanyId === '') {
    return false
  }
  if (isEmployeeCompanyUnassigned(employee)) {
    return false
  }
  return String(employee?.company_id ?? '') !== String(targetCompanyId)
}

export function employeeAssignedToUnit(employee, targetUnit, memberIdField = 'department_id') {
  if (!targetUnit?.id || !employee) {
    return false
  }

  if (String(employee[memberIdField] ?? '') === String(targetUnit.id)) {
    return true
  }

  const assignments = Array.isArray(employee.organization_assignments)
    ? employee.organization_assignments
    : []

  return assignments.some((row) => {
    if (memberIdField === 'department_id') {
      return String(row.department_id ?? '') === String(targetUnit.id)
    }
    if (memberIdField === 'division_id') {
      return String(row.division_id ?? '') === String(targetUnit.id)
    }
    if (memberIdField === 'section_unit_id') {
      return String(row.section_unit_id ?? '') === String(targetUnit.id)
    }
    return false
  })
}

export function buildOrgAssignRows({
  assignList,
  targetUnit,
  targetCompanyId,
  memberIdField = 'department_id',
  assignSearchQuery = '',
  assignFilter = 'available',
  assignIds = [],
  isExcludedFromAssignPool = () => false,
  isRosterStaffMember = () => true,
  assignDepartmentFilter = 'all',
}) {
  const rows = (assignList || [])
    .filter((employee) => isRosterStaffMember(employee))
    .filter((employee) => !isExcludedFromAssignPool(employee))
    .filter((employee) => {
      const query = String(assignSearchQuery || '').trim().toLowerCase()
      if (!query) return true
      const haystack = [
        employeeDisplayName(employee),
        employee?.employee_code,
        employee?.email,
        employee?.position,
        employee?.department,
        employee?.current_org_path,
        employee?.company_name,
        employee?.branch_name,
        employee?.management_role,
      ]
        .filter(Boolean)
        .join(' ')
        .toLowerCase()
      return haystack.includes(query)
    })
    .filter((employee) => {
      const departmentLabel = employee.department || 'Unassigned'
      if (assignDepartmentFilter !== 'all' && departmentLabel !== assignDepartmentFilter) {
        return false
      }

      const assignedToCurrent = employeeAssignedToUnit(employee, targetUnit, memberIdField)
      const isInactive = !employee.is_active
      const status = assignedToCurrent ? 'assigned' : (isInactive ? 'unavailable' : 'available')

      if (assignFilter === 'available') return status === 'available'
      if (assignFilter === 'assigned') return status === 'assigned'
      return true
    })
    .map((employee) => {
      const assignedToCurrent = employeeAssignedToUnit(employee, targetUnit, memberIdField)
      const isInactive = !employee.is_active
      const crossCompany = isCrossCompanyForTarget(employee, targetCompanyId)
      const status = assignedToCurrent ? 'assigned' : (isInactive ? 'unavailable' : 'available')
      const checked = assignedToCurrent || assignIds.some((id) => sameUserId(id, employee.id))
      const checkboxDisabled = status !== 'available'

      return {
        emp: employee,
        status,
        checked,
        checkboxDisabled,
        isInactive,
        assignedElsewhere: crossCompany && !assignedToCurrent,
        crossCompany,
        currentOrgPath: employeeCurrentOrgPath(employee),
      }
    })

  return rows
}

export function buildOrgAssignCounts(assignList, targetUnit, memberIdField, isExcludedFromAssignPool, isRosterStaffMember) {
  let available = 0
  let assigned = 0
  let unavailable = 0

  ;(assignList || [])
    .filter((employee) => isRosterStaffMember(employee))
    .filter((employee) => !isExcludedFromAssignPool(employee))
    .forEach((employee) => {
      const assignedToCurrent = employeeAssignedToUnit(employee, targetUnit, memberIdField)
      const isInactive = !employee.is_active
      const status = assignedToCurrent ? 'assigned' : (isInactive ? 'unavailable' : 'available')
      if (status === 'available') available += 1
      else if (status === 'assigned') assigned += 1
      else unavailable += 1
    })

  return {
    available,
    assigned,
    unavailable,
    total: (assignList || []).filter((employee) => isRosterStaffMember(employee)).filter((employee) => !isExcludedFromAssignPool(employee)).length,
  }
}

export function selectedCrossCompanyEmployees(selectedEmployees, targetCompanyId) {
  return (selectedEmployees || []).filter((employee) => isCrossCompanyForTarget(employee, targetCompanyId))
}
