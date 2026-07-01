/**
 * Maps app paths to human-readable module/page labels for activity logging.
 * Longest prefix wins.
 */
const ROUTE_ACTIVITY = [
  // Admin — Workforce
  { prefix: '/admin/employees', module: 'Workforce', title: 'Employees' },
  { prefix: '/admin/recruitment', module: 'Workforce', title: 'Recruitment' },
  { prefix: '/admin/regularization', module: 'Workforce', title: 'Regularization' },
  // Admin — Organization
  { prefix: '/admin/organizations/companies', module: 'Organization', title: 'Companies' },
  { prefix: '/admin/organizations/areas', module: 'Organization', title: 'Areas' },
  { prefix: '/admin/organizations/branches', module: 'Organization', title: 'Branches' },
  { prefix: '/admin/organizations/divisions', module: 'Organization', title: 'Divisions' },
  { prefix: '/admin/organizations/departments', module: 'Organization', title: 'Departments' },
  { prefix: '/admin/organizations/sections', module: 'Organization', title: 'Sections & Units' },
  // Admin — Time & Attendance
  { prefix: '/admin/attendance', module: 'Time & Attendance', title: 'Attendance' },
  { prefix: '/admin/schedules', module: 'Time & Attendance', title: 'Work Schedules' },
  { prefix: '/admin/schedule-requests', module: 'Time & Attendance', title: 'Schedule Approvals' },
  { prefix: '/admin/corrections', module: 'Time & Attendance', title: 'Attendance Corrections' },
  { prefix: '/admin/attendance-corrections', module: 'Time & Attendance', title: 'Attendance Corrections' },
  { prefix: '/admin/overtime', module: 'Time & Attendance', title: 'Overtime' },
  { prefix: '/admin/leave', module: 'Time & Attendance', title: 'Leave' },
  { prefix: '/admin/holiday', module: 'Time & Attendance', title: 'Holidays' },
  { prefix: '/admin/geofencing', module: 'Time & Attendance', title: 'Geofencing' },
  // Admin — Payroll
  { prefix: '/admin/compensation/pay-cycles', module: 'Payroll', title: 'Pay Cycles' },
  { prefix: '/admin/compensation/pay-components', module: 'Payroll', title: 'Pay Components' },
  { prefix: '/admin/compensation/employee-compensation', module: 'Payroll', title: 'Employee Pay Setup' },
  { prefix: '/admin/compensation/deduction-schedule-settings', module: 'Payroll', title: 'Deduction Schedules' },
  { prefix: '/admin/compensation/government-deduction', module: 'Payroll', title: 'Government Deductions' },
  { prefix: '/admin/compensation/deductions-loans', module: 'Payroll', title: 'Loans & Deductions' },
  { prefix: '/admin/compensation/generate-payslips', module: 'Payroll', title: 'Generate Payslips' },
  { prefix: '/admin/compensation/13th-month-pay', module: 'Payroll', title: '13th Month Pay' },
  { prefix: '/admin/compensation/payslips', module: 'Payroll', title: 'Payslips' },
  { prefix: '/admin/daily-computation/policy-settings', module: 'Payroll', title: 'Pay Policies' },
  { prefix: '/admin/daily-computation', module: 'Payroll', title: 'Daily Payroll' },
  { prefix: '/admin/execom', module: 'Payroll', title: 'EXECOM Payroll' },
  // Admin — other
  { prefix: '/admin/reports', module: 'Reports', title: 'Reports' },
  { prefix: '/admin/employee-logs', module: 'Administration', title: 'Employee Logs' },
  { prefix: '/admin/users-permissions', module: 'Administration', title: 'Users & Access' },
  { prefix: '/admin/approval-workflow-settings', module: 'Administration', title: 'Approval Rules' },
  { prefix: '/admin/email-notifications', module: 'Administration', title: 'Email Alerts' },
  { prefix: '/admin/my-schedule', module: 'My Workspace', title: 'My Schedule' },
  { prefix: '/admin/qr', module: 'My Workspace', title: 'QR & Face ID' },
  { prefix: '/admin/loans-deductions', module: 'My Workspace', title: 'My Loans & Deductions' },
  { prefix: '/admin/profile', module: 'My Workspace', title: 'My Profile' },
  { prefix: '/admin/notifications', module: 'Notifications', title: 'Notifications' },
  { prefix: '/admin', module: 'Dashboard', title: 'Admin Dashboard' },
  // Scoped manager panels (same modules, different base)
  { prefix: '/company/employees', module: 'Workforce', title: 'Employees' },
  { prefix: '/company/attendance', module: 'Time & Attendance', title: 'Attendance' },
  { prefix: '/company', module: 'Dashboard', title: 'Company Dashboard' },
  { prefix: '/branch', module: 'Dashboard', title: 'Branch Dashboard' },
  { prefix: '/department', module: 'Dashboard', title: 'Department Dashboard' },
  { prefix: '/division', module: 'Dashboard', title: 'Division Dashboard' },
  { prefix: '/section-unit', module: 'Dashboard', title: 'Section Dashboard' },
  // Employee portal
  { prefix: '/employee/dashboard', module: 'Workday', title: 'Dashboard' },
  { prefix: '/employee/attendance', module: 'Workday', title: 'My Attendance' },
  { prefix: '/employee/schedule', module: 'Workday', title: 'My Schedule' },
  { prefix: '/employee/qr', module: 'Workday', title: 'QR & Face ID' },
  { prefix: '/employee/holidays', module: 'Workday', title: 'Holidays' },
  { prefix: '/employee/requests', module: 'Requests', title: 'Leave Requests' },
  { prefix: '/employee/overtime', module: 'Requests', title: 'Overtime Requests' },
  { prefix: '/employee/correction-requests', module: 'Requests', title: 'Attendance Corrections' },
  { prefix: '/employee/payslips', module: 'Pay', title: 'My Payslips' },
  { prefix: '/employee/loans-deductions', module: 'Pay', title: 'Loans & Deductions' },
  { prefix: '/employee/reports', module: 'Reports', title: 'Reports' },
  { prefix: '/employee/profile', module: 'Account', title: 'My Profile' },
  { prefix: '/employee/notifications', module: 'Notifications', title: 'Notifications' },
  { prefix: '/employee', module: 'Workday', title: 'Employee Dashboard' },
]

const SORTED_ROUTES = [...ROUTE_ACTIVITY].sort((a, b) => b.prefix.length - a.prefix.length)

function stripBasename(pathname) {
  const base = (import.meta.env.BASE_URL || '/').replace(/\/$/, '')
  if (base && base !== '/' && pathname.startsWith(base)) {
    return pathname.slice(base.length) || '/'
  }
  return pathname
}

export function resolveActivityFromPath(pathname, search = '') {
  const bare = stripBasename(pathname || '/')
  const path = `${bare}${search || ''}`
  const match = SORTED_ROUTES.find((row) => bare === row.prefix || bare.startsWith(`${row.prefix}/`))
  if (match) {
    return {
      module: match.module,
      title: `Opened ${match.title}`,
      path,
    }
  }

  const segments = bare.split('/').filter(Boolean)
  const portal = segments[0] || 'app'
  const label = segments[segments.length - 1]?.replace(/-/g, ' ') || portal

  return {
    module: portal.charAt(0).toUpperCase() + portal.slice(1),
    title: `Viewed ${label}`,
    path,
  }
}
