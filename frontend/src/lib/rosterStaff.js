/**
 * Staff included in HR rosters (employee lists, payroll scope, attendance), matching
 * {@see \App\Models\User::ROSTER_ELIGIBLE_ROLES}: Laravel `employee` + `admin` (Admin HR).
 */
export function isRosterStaffRole(role) {
  const r = String(role || '').toLowerCase()
  return r === 'employee' || r === 'admin'
}

/** Prefer API flag from {@see AuthController::userResponse} when present. */
export function isRosterStaffMember(user) {
  if (user?.is_roster_staff === true) return true
  return isRosterStaffRole(user?.role)
}
