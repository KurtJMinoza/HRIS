import { Navigate } from 'react-router-dom'
import PayslipsListPage from '@/components/payslips/PayslipsListPage'
import { useAuth } from '@/contexts/AuthContext'

/**
 * HR panel: same “My Payslips” UI — own delivered payslips only (`/employee/payslips` API).
 * Laravel `users.role = admin` is redirected (no Payslips module for that role).
 * Route: `{hrBase}/compensation/payslips` (legacy `…/team-payslips` redirects here).
 */
export default function TeamPayslipsPage() {
  const { user } = useAuth()
  const canViewPayslips = new Set(user?.permissions ?? []).has('payslip.view')
  if (!canViewPayslips) {
    return <Navigate to="/admin/dashboard" replace />
  }
  return <PayslipsListPage variant="hr" />
}
