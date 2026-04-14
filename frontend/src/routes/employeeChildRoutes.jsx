import { Route } from 'react-router-dom'
import AdminReports from '@/pages/AdminReports'

/** Mount under `/employee/*` (same shell as other employee pages). */
export const EMPLOYEE_PANEL_CHILD_ROUTES = [
  <Route key="employee-reports" path="reports" element={<AdminReports />} />,
]
