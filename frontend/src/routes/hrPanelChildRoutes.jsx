import { Suspense, lazy } from 'react'
import { Navigate, Route } from 'react-router-dom'
import { EmployeeListRedirect, LegacyTeamPayslipsRedirect, ToEmployeesRedirect } from '@/routes/hrPanelRouteRedirects'
import AttendanceCorrections from '@/pages/AttendanceCorrections'
import AdminSchedules from '@/pages/AdminSchedules'
import AdminPolicySettings from '@/pages/AdminPolicySettings'
import AdminApprovalWorkflowSettings from '@/pages/AdminApprovalWorkflowSettings'
import AdminUsersPermissions from '@/pages/AdminUsersPermissions'
import AdminPayrollLogisticsPlaceholder from '@/pages/AdminPayrollLogisticsPlaceholder'
import AdminEmployeeCompensationPage from '@/pages/AdminEmployeeCompensationPage'
import EmployeeMyQr from '@/pages/EmployeeMyQr'
import { DataTableRouteFallback, MyScheduleRouteFallback, ProfileRouteFallback } from '@/components/skeletons/RoutePageFallbacks.jsx'
import { ErrorBoundary } from '@/components/ErrorBoundary'

const AdminEmployees = lazy(() => import('@/pages/AdminEmployees'))
const AdminEmployeeProfile = lazy(() => import('@/pages/AdminEmployeeProfile'))
const AdminRegularization = lazy(() => import('@/pages/AdminRegularization'))
const AdminDailyComputation = lazy(() => import('@/pages/AdminDailyComputation'))
const GovernmentDeductionPage = lazy(() => import('@/pages/GovernmentDeductionPage'))
const AdminPayCycleManagementPage = lazy(() => import('@/pages/AdminPayCycleManagementPage'))
const AdminPayComponentsPage = lazy(() => import('@/pages/AdminPayComponentsPage'))
const AdminDeductionScheduleSettingsPage = lazy(() => import('@/pages/AdminDeductionScheduleSettingsPage'))
const AdminDeductionsLoansPage = lazy(() => import('@/pages/AdminDeductionsLoansPage'))
const AdminGeneratePayslipsPage = lazy(() => import('@/pages/AdminGeneratePayslipsPage'))
const AdminFinalizePayrollPage = lazy(() => import('@/pages/AdminFinalizePayrollPage'))
const AdminExecomManagementPage = lazy(() => import('@/pages/AdminExecomManagementPage'))
const AdminExecomFinalizePayrollPage = lazy(() => import('@/pages/AdminExecomFinalizePayrollPage'))
const TeamPayslipsPage = lazy(() => import('@/pages/TeamPayslipsPage'))
const AdminPayslipViewPage = lazy(() => import('@/pages/AdminPayslipViewPage'))
const EmployeeLoansDeductionsPage = lazy(() => import('@/pages/EmployeeLoansDeductionsPage'))
const ScheduleRequestsPage = lazy(() => import('@/pages/ScheduleRequestsPage'))
const MySchedule = lazy(() => import('@/pages/MySchedule'))
const AdminDashboard = lazy(() => import('@/pages/AdminDashboard'))
const AdminAttendance = lazy(() => import('@/pages/AdminAttendance'))
const AdminReports = lazy(() => import('@/pages/AdminReports'))
const AdminCompanies = lazy(() => import('@/pages/AdminCompanies'))
const AdminBranches = lazy(() => import('@/pages/AdminBranches'))
const AdminDepartments = lazy(() => import('@/pages/AdminDepartments'))
const AdminDivisions = lazy(() => import('@/pages/AdminDivisions'))
const AdminSectionUnits = lazy(() => import('@/pages/AdminSectionUnits'))
const AdminLeave = lazy(() => import('@/pages/AdminLeave'))
const AdminHoliday = lazy(() => import('@/pages/AdminHoliday'))
const AdminOvertime = lazy(() => import('@/pages/AdminOvertime'))

function withSuspense(node, fallback) {
  return (
    <ErrorBoundary>
      <Suspense fallback={fallback}>{node}</Suspense>
    </ErrorBoundary>
  )
}

export const HR_PANEL_CHILD_ROUTES = [
  <Route key="hr-idx" index element={<Navigate to="dashboard" replace />} />,
  <Route key="hr-dash" path="dashboard" element={withSuspense(<AdminDashboard />, <DataTableRouteFallback titleWidth="w-64" />)} />,
  <Route key="hr-emp" path="employees" element={withSuspense(<AdminEmployees />, <DataTableRouteFallback titleWidth="w-72" />)} />,
  <Route key="hr-emp-list" path="employees/list" element={<EmployeeListRedirect />} />,
  <Route key="hr-emp-add" path="employees/add" element={withSuspense(<AdminEmployees />, <DataTableRouteFallback titleWidth="w-72" />)} />,
  <Route key="hr-users" path="users-permissions" element={<AdminUsersPermissions />} />,
  <Route
    key="hr-emp-id"
    path="employees/:employeeId"
    element={withSuspense(<AdminEmployeeProfile />, <ProfileRouteFallback />)}
  />,
  <Route
    key="hr-reg"
    path="regularization"
    element={withSuspense(<AdminRegularization />, <DataTableRouteFallback titleWidth="w-64" />)}
  />,
  <Route key="hr-ep1" path="employees/employee-profile/personal-info" element={<ToEmployeesRedirect />} />,
  <Route key="hr-ep2" path="employees/employee-profile/employment" element={<ToEmployeesRedirect />} />,
  <Route key="hr-ep3" path="employees/employee-profile/compensation" element={<ToEmployeesRedirect />} />,
  <Route key="hr-ep4" path="employees/employee-profile/benefits" element={<ToEmployeesRedirect />} />,
  <Route key="hr-ep5" path="employees/employee-profile/documents" element={<ToEmployeesRedirect />} />,
  <Route key="hr-ep6" path="employees/employee-profile/emergency-contacts" element={<ToEmployeesRedirect />} />,
  <Route key="hr-ep7" path="employees/employee-profile/skills" element={<ToEmployeesRedirect />} />,
  <Route key="hr-co" path="companies" element={withSuspense(<AdminCompanies />, <DataTableRouteFallback titleWidth="w-64" />)} />,
  <Route key="hr-br" path="branches" element={withSuspense(<AdminBranches />, <DataTableRouteFallback titleWidth="w-64" />)} />,
  <Route key="hr-de" path="departments" element={withSuspense(<AdminDepartments />, <DataTableRouteFallback titleWidth="w-64" />)} />,
  <Route key="hr-di" path="divisions" element={withSuspense(<AdminDivisions />, <DataTableRouteFallback titleWidth="w-64" />)} />,
  <Route key="hr-su" path="sections-units" element={withSuspense(<AdminSectionUnits />, <DataTableRouteFallback titleWidth="w-64" />)} />,
  <Route key="hr-le" path="leave" element={withSuspense(<AdminLeave />, <DataTableRouteFallback titleWidth="w-64" />)} />,
  <Route key="hr-ho" path="holiday" element={withSuspense(<AdminHoliday />, <DataTableRouteFallback titleWidth="w-64" />)} />,
  <Route key="hr-ov" path="overtime" element={withSuspense(<AdminOvertime />, <DataTableRouteFallback titleWidth="w-64" />)} />,
  <Route key="hr-ms" path="my-schedule" element={withSuspense(<MySchedule />, <MyScheduleRouteFallback />)} />,
  <Route key="hr-sr" path="schedule-requests" element={withSuspense(<ScheduleRequestsPage />, <DataTableRouteFallback titleWidth="w-72" />)} />,
  <Route key="hr-dc-rules" path="daily-computation/rules" element={<AdminPayrollLogisticsPlaceholder />} />,
  <Route key="hr-dc-audit" path="daily-computation/audit" element={<AdminPayrollLogisticsPlaceholder />} />,
  <Route key="hr-dc-pol" path="daily-computation/policy-settings" element={<AdminPolicySettings />} />,
  <Route key="hr-approval-workflow" path="approval-workflow-settings" element={<AdminApprovalWorkflowSettings />} />,
  <Route
    key="hr-dc"
    path="daily-computation"
    element={withSuspense(<AdminDailyComputation />, <DataTableRouteFallback titleWidth="w-72" />)}
  />,
  <Route
    key="hr-govded"
    path="compensation/government-deduction"
    element={withSuspense(<GovernmentDeductionPage />, <DataTableRouteFallback titleWidth="w-72" />)}
  />,
  <Route
    key="hr-pay-cycles"
    path="compensation/pay-cycles"
    element={withSuspense(<AdminPayCycleManagementPage />, <DataTableRouteFallback titleWidth="w-60" />)}
  />,
  <Route key="hr-pay-components" path="compensation/pay-components" element={withSuspense(<AdminPayComponentsPage />, <DataTableRouteFallback titleWidth="w-60" />)} />,
  <Route
    key="hr-deduction-schedule-settings"
    path="compensation/deduction-schedule-settings"
    element={withSuspense(<AdminDeductionScheduleSettingsPage />, <DataTableRouteFallback titleWidth="w-72" />)}
  />,
  <Route
    key="hr-deductions-loans"
    path="compensation/deductions-loans"
    element={withSuspense(<AdminDeductionsLoansPage />, <DataTableRouteFallback titleWidth="w-72" />)}
  />,
  <Route
    key="hr-generate-payslips"
    path="compensation/generate-payslips"
    element={withSuspense(<AdminGeneratePayslipsPage />, <DataTableRouteFallback titleWidth="w-64" />)}
  />,
  <Route
    key="hr-finalize-payroll"
    path="compensation/finalize-payroll"
    element={withSuspense(<AdminFinalizePayrollPage />, <DataTableRouteFallback titleWidth="w-64" />)}
  />,
  <Route
    key="hr-execom-management"
    path="execom/employees"
    element={withSuspense(<AdminExecomManagementPage />, <DataTableRouteFallback titleWidth="w-64" />)}
  />,
  <Route
    key="hr-execom-finalize-payroll"
    path="execom/payroll/finalize"
    element={withSuspense(<AdminExecomFinalizePayrollPage />, <DataTableRouteFallback titleWidth="w-64" />)}
  />,
  <Route
    key="hr-payslips-list"
    path="compensation/payslips"
    element={withSuspense(<TeamPayslipsPage />, <DataTableRouteFallback titleWidth="w-56" />)}
  />,
  <Route key="hr-team-payslips-legacy" path="compensation/team-payslips" element={<LegacyTeamPayslipsRedirect />} />,
  <Route
    key="hr-payslip-view"
    path="compensation/payslips/:payslipId/view"
    element={withSuspense(<AdminPayslipViewPage />, <DataTableRouteFallback titleWidth="w-64" />)}
  />,
  <Route
    key="hr-payslip-preview-view"
    path="compensation/payslips/preview/view"
    element={withSuspense(<AdminPayslipViewPage />, <DataTableRouteFallback titleWidth="w-64" />)}
  />,
  <Route key="hr-employee-compensation" path="compensation/employee-compensation" element={<AdminEmployeeCompensationPage />} />,
  <Route
    key="hr-govcontrib-redirect"
    path="government-contributions"
    element={<Navigate to="../compensation/government-deduction" replace />}
  />,
  <Route key="hr-at" path="attendance" element={withSuspense(<AdminAttendance />, <DataTableRouteFallback titleWidth="w-72" />)} />,
  <Route key="hr-att-corr" path="attendance-corrections" element={<AttendanceCorrections />} />,
  <Route key="hr-corr" path="corrections" element={<AttendanceCorrections />} />,
  <Route key="hr-qr" path="qr" element={<EmployeeMyQr />} />,
  <Route key="hr-re" path="reports" element={withSuspense(<AdminReports />, <DataTableRouteFallback titleWidth="w-72" />)} />,
  <Route
    key="hr-my-loans"
    path="loans-deductions"
    element={withSuspense(<EmployeeLoansDeductionsPage />, <DataTableRouteFallback titleWidth="w-72" />)}
  />,
  <Route key="hr-sc" path="schedules" element={<AdminSchedules />} />,
  <Route
    key="hr-pr"
    path="profile"
    element={withSuspense(<AdminEmployeeProfile />, <ProfileRouteFallback />)}
  />,
  <Route
    key="hr-pr-id"
    path="profile/:employeeId"
    element={withSuspense(<AdminEmployeeProfile />, <ProfileRouteFallback />)}
  />,
]
