import { Navigate, useLocation } from 'react-router-dom'
import { useHrBasePath } from '@/contexts/HrAppPathContext'
import { hrPanelPath } from '@/lib/hrRoutes'

export function ToEmployeesRedirect() {
  const base = useHrBasePath()
  return <Navigate to={hrPanelPath(base, 'employees')} replace />
}

export function EmployeeListRedirect() {
  const { pathname, search } = useLocation()
  const target = pathname.replace(/\/employees\/list\/?$/, '/employees')
  return <Navigate to={`${target}${search}`} replace />
}

/** Old URL `…/compensation/team-payslips` → canonical `…/compensation/payslips`. */
export function LegacyTeamPayslipsRedirect() {
  const base = useHrBasePath()
  return <Navigate to={`${base}/compensation/payslips`} replace />
}
