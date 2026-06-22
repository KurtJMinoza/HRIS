import {
  Building,
  Building2,
  BriefcaseBusiness,
  Calendar,
  CalendarCheck,
  CalendarClock,
  CalendarRange,
  CalendarOff,
  MapPinned,
  Banknote,
  Calculator,
  ClipboardList,
  FileText,
  HandCoins,
  LayoutDashboard,
  Layers,
  Landmark,
  Network,
  QrCode,
  Receipt,
  Mail,
  Settings,
  ShieldCheck,
  Timer,
  User,
  UserCheck,
  UserCog,
  Users,
} from 'lucide-react'

export const adminNavItems = [
  { to: '/admin', end: true, label: 'Dashboard', icon: LayoutDashboard },

  {
    label: 'People & HR',
    icon: Users,
    children: [
      { to: '/admin/employees', end: false, label: 'Employees', icon: Users },
      { to: '/admin/recruitment', end: false, label: 'Recruitment', icon: BriefcaseBusiness },
      { to: '/admin/regularization', end: false, label: 'Regularization', icon: UserCheck },
    ],
  },

  /** HR panel (non–Laravel-admin) + org heads: own delivered payslips */
  { to: '/admin/compensation/payslips', end: false, label: 'My Payslips', icon: FileText },

  {
    label: 'Payroll & Compensation',
    icon: Banknote,
    children: [
      { to: '/admin/compensation/pay-cycles', end: false, label: 'Pay Cycles', icon: CalendarClock },
      { to: '/admin/compensation/pay-components', end: false, label: 'Pay Components', icon: Layers },
      { to: '/admin/compensation/deduction-schedule-settings', end: false, label: 'Deduction Schedules', icon: CalendarRange },
      { to: '/admin/compensation/employee-compensation', end: false, label: 'Employee Pay Setup', icon: Users },
      { to: '/admin/compensation/government-deduction', end: false, label: 'Statutory Deductions', icon: Landmark },
      { to: '/admin/compensation/deductions-loans', end: false, label: 'Loans & Deductions', icon: HandCoins },
      { to: '/admin/compensation/generate-payslips', end: false, label: 'Generate Payslips', icon: Receipt },
      { to: '/admin/compensation/13th-month-pay', end: false, label: '13th Month Pay', icon: Calculator },
      { to: '/admin/execom/employees', end: false, label: 'EXECOM', icon: UserCog },
    ],
  },

  {
    label: 'Organization',
    icon: Building2,
    children: [
      { to: '/admin/companies', end: false, label: 'Companies', icon: Building },
      { to: '/admin/areas', end: false, label: 'Areas', icon: Network },
      { to: '/admin/branches', end: false, label: 'Branches', icon: Network },
      { to: '/admin/divisions', end: false, label: 'Divisions', icon: Layers },
      { to: '/admin/departments', end: false, label: 'Departments', icon: Layers },
      { to: '/admin/sections-units', end: false, label: 'Sections & Units', icon: Users },
    ],
  },

  {
    label: 'Time & Attendance',
    icon: CalendarCheck,
    children: [
      { to: '/admin/holiday', end: false, label: 'Holidays', icon: Calendar },
      { to: '/admin/attendance', end: false, label: 'Attendance', icon: CalendarCheck },
      { to: '/admin/geofencing', end: false, label: 'Geofencing', icon: MapPinned },
      { to: '/admin/corrections', end: false, label: 'Attendance Corrections', icon: ClipboardList },
      { to: '/admin/overtime', end: false, label: 'Overtime', icon: Timer },
      { to: '/admin/leave', end: false, label: 'Leave', icon: CalendarOff },
      { to: '/admin/my-schedule', end: false, label: 'My Schedule', icon: CalendarClock },
      { to: '/admin/schedule-requests', end: false, label: 'Schedule Approvals', icon: ClipboardList },
      { to: '/admin/schedules', end: false, label: 'Work Schedules', icon: CalendarClock },
      { to: '/admin/qr', end: false, label: 'QR & Face ID', icon: QrCode },
      { to: '/admin/daily-computation', end: false, label: 'Daily Payroll', icon: Calculator },
    ],
  },

  { to: '/admin/reports', end: false, label: 'Reports', icon: FileText },

  /** Self-service: own deductions & loan requests */
  { to: '/admin/loans-deductions', end: false, label: 'My Loans & Deductions', icon: HandCoins },

  {
    label: 'Settings',
    icon: Settings,
    children: [
      { to: '/admin/users-permissions', end: false, label: 'Users & Access', icon: UserCog },
      { to: '/admin/profile', end: false, label: 'My Profile', icon: User },
      { to: '/admin/daily-computation/policy-settings', end: false, label: 'Pay Policies', icon: Settings },
      { to: '/admin/approval-workflow-settings', end: false, label: 'Approval Rules', icon: ShieldCheck },
      { to: '/admin/email-notifications', end: false, label: 'Email Alerts', icon: Mail },
    ],
  },
]

export const employeeNavItems = [
  { to: '/employee/dashboard', end: true, label: 'Dashboard', icon: LayoutDashboard },

  {
    label: 'Time & Attendance',
    icon: CalendarCheck,
    children: [
      { to: '/employee/attendance', end: false, label: 'My Attendance', icon: CalendarCheck },
      { to: '/employee/schedule', end: false, label: 'My Schedule', icon: CalendarClock },
      { to: '/employee/correction-requests', end: false, label: 'Corrections', icon: ClipboardList },
      { to: '/employee/qr', end: false, label: 'QR & Face ID', icon: QrCode },
      {
        to: '/employee/holidays',
        end: false,
        label: 'Holidays',
        icon: Calendar,
        requiredPermissions: ['holidays.view', 'holiday.view'],
      },
    ],
  },

  {
    label: 'Requests',
    icon: ClipboardList,
    children: [
      { to: '/employee/requests', end: false, label: 'Leave', icon: CalendarOff },
      { to: '/employee/overtime', end: false, label: 'Overtime', icon: Timer },
    ],
  },

  {
    label: 'Pay & Benefits',
    icon: Banknote,
    children: [
      { to: '/employee/payslips', end: false, label: 'Payslips', icon: Receipt },
      { to: '/employee/loans-deductions', end: false, label: 'Loans & Deductions', icon: HandCoins },
    ],
  },

  {
    to: '/employee/reports',
    end: false,
    label: 'Reports',
    icon: FileText,
    requiredPermissions: [
      'can_access_reports_module',
      'can_view_own_reports',
      'can_view_subordinate_reports',
      'reports.view',
      'reports.view_team',
      'reports.view_division',
    ],
  },

  { to: '/employee/profile', end: false, label: 'My Profile', icon: User },
]
