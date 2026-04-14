import { useMemo } from 'react'
import { buildEmployeeNav } from '@/config/rbacNav'
import { useAuth } from '@/contexts/AuthContext'
import { DashboardLayout } from '@/layouts/DashboardLayout'

/** Employee self-service shell (`/employee/*`). Nav may link heads to HR **Payslips** (own delivered payslips only). */
export function EmployeeDashboardLayout() {
  const { user } = useAuth()
  const navItems = useMemo(() => buildEmployeeNav(user), [user])
  return <DashboardLayout navItems={navItems} role="employee" hrBasePath="/employee" />
}
