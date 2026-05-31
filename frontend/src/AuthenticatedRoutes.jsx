import { Suspense, lazy } from 'react'
import { Navigate, Route, Routes } from 'react-router-dom'
import { ProtectedRoute } from '@/components/ProtectedRoute'
import { HrPanelLayout } from '@/layouts/HrPanelLayout'
import { EmployeeDashboardLayout } from '@/layouts/EmployeeDashboardLayout'
import { HR_PANEL_CHILD_ROUTES } from '@/routes/hrPanelChildRoutes'
import { MyScheduleRouteFallback } from '@/components/skeletons/RoutePageFallbacks.jsx'

const EmployeeDashboard = lazy(() => import('@/pages/EmployeeDashboard'))
const EmployeeAttendance = lazy(() => import('@/pages/EmployeeAttendance'))
const EmployeeHolidaysPage = lazy(() => import('@/pages/EmployeeHolidaysPage'))
const EmployeeCorrectionRequests = lazy(() => import('@/pages/EmployeeCorrectionRequests'))
const EmployeeProfile = lazy(() => import('@/pages/EmployeeProfile'))
const EmployeeMyPayslipsPage = lazy(() => import('@/pages/EmployeeMyPayslipsPage'))
const EmployeeLeave = lazy(() => import('@/pages/EmployeeLeave'))
const EmployeeOvertime = lazy(() => import('@/pages/EmployeeOvertime'))
const EmployeeMyQr = lazy(() => import('@/pages/EmployeeMyQr'))
const EmployeeReportsPage = lazy(() => import('@/pages/AdminReports'))
const MySchedule = lazy(() => import('@/pages/MySchedule'))
const AdminPayslipViewPage = lazy(() => import('@/pages/AdminPayslipViewPage'))
const EmployeeLoansDeductionsPage = lazy(() => import('@/pages/EmployeeLoansDeductionsPage'))

function routeFallback(label = 'Loading...') {
  return <div className="p-6 text-muted-foreground">{label}</div>
}

function withSuspense(node, fallback = routeFallback()) {
  return <Suspense fallback={fallback}>{node}</Suspense>
}

function authenticatedRoutes() {
  return [
    <Route
      key="admin"
      path="/admin"
      element={(
        <ProtectedRoute variant="adminHr">
          <HrPanelLayout />
        </ProtectedRoute>
      )}
    >
      {HR_PANEL_CHILD_ROUTES}
    </Route>,
    ...['company', 'branch', 'department', 'division', 'section-unit'].map((path) => (
      <Route key={path} path={`/${path}`} element={<HrPanelLayout />}>
        {HR_PANEL_CHILD_ROUTES}
      </Route>
    )),
    <Route
      key="employee"
      path="/employee"
      element={(
        <ProtectedRoute role="employee">
          <EmployeeDashboardLayout />
        </ProtectedRoute>
      )}
    >
      <Route index element={<Navigate to="dashboard" replace />} />
      <Route path="dashboard" element={withSuspense(<EmployeeDashboard />)} />
      <Route
        path="holidays"
        element={(
          <ProtectedRoute role="employee" permissions={['holidays.view', 'holiday.view']}>
            {withSuspense(<EmployeeHolidaysPage />)}
          </ProtectedRoute>
        )}
      />
      <Route path="attendance" element={withSuspense(<EmployeeAttendance />)} />
      <Route path="correction-requests" element={withSuspense(<EmployeeCorrectionRequests />)} />
      <Route path="schedule" element={withSuspense(<MySchedule />, <MyScheduleRouteFallback />)} />
      <Route path="qr" element={withSuspense(<EmployeeMyQr />)} />
      <Route path="reports" element={withSuspense(<EmployeeReportsPage />, routeFallback('Loading reports...'))} />
      <Route path="requests" element={withSuspense(<EmployeeLeave />)} />
      <Route path="loans-deductions" element={withSuspense(<EmployeeLoansDeductionsPage />, <MyScheduleRouteFallback />)} />
      <Route path="overtime" element={withSuspense(<EmployeeOvertime />)} />
      <Route path="profile" element={withSuspense(<EmployeeProfile />)} />
      <Route path="profile/:employeeId" element={withSuspense(<EmployeeProfile />)} />
      <Route path="payslips" element={withSuspense(<EmployeeMyPayslipsPage />)} />
      <Route path="payslips/view/:payslipId" element={withSuspense(<AdminPayslipViewPage />, routeFallback('Loading payslip...'))} />
    </Route>,
  ]
}

export default function AuthenticatedRoutes() {
  return (
    <Routes>
      {authenticatedRoutes()}
      <Route path="*" element={<Navigate to="/" replace />} />
    </Routes>
  )
}
