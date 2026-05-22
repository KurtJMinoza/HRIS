import {
  Building,
  Building2,
  Calendar,
  CalendarCheck,
  CalendarClock,
  CalendarRange,
  CalendarOff,
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
  Settings,
  ShieldCheck,
  Timer,
  User,
  UserCheck,
  UserCog,
  Users,
} from 'lucide-react'

export const adminNavItems = [
  // Main
  { to: '/admin', end: true, label: 'Dashboard', icon: LayoutDashboard },
  { to: '/admin/employees', end: false, label: 'Employees', icon: Users },
  { to: '/admin/regularization', end: false, label: 'Regularization', icon: UserCheck },
  /** HR panel (non–Laravel-admin) + org heads: own delivered payslips — hidden in nav for `users.role = admin`. */
  { to: '/admin/compensation/payslips', end: false, label: 'Payslips', icon: FileText },

  // Compensation (collapsible)
  {
    label: 'Compensation',
    icon: Banknote,
    children: [
      {
        to: '/admin/compensation/pay-cycles',
        end: false,
        label: 'Pay Cycles',
        icon: CalendarClock,
      },
      {
        to: '/admin/compensation/pay-components',
        end: false,
        label: 'Pay Components',
        icon: Layers,
      },
      {
        to: '/admin/compensation/deduction-schedule-settings',
        end: false,
        label: 'Deduction Schedule Settings',
        icon: CalendarRange,
      },
      {
        to: '/admin/compensation/employee-compensation',
        end: false,
        label: 'Employee Compensation',
        icon: Users,
      },
      {
        to: '/admin/compensation/government-deduction',
        end: false,
        label: 'Government Deductions',
        icon: Landmark,
      },
      {
        to: '/admin/compensation/deductions-loans',
        end: false,
        label: 'Deductions & Loans',
        icon: HandCoins,
      },
      {
        to: '/admin/compensation/generate-payslips',
        end: false,
        label: 'Generate Payslips',
        icon: Receipt,
      },
    ],
  },

  // Organization (collapsible)
  {
    label: 'Organization',
    icon: Building2,
    children: [
      { to: '/admin/companies', end: false, label: 'Companies', icon: Building },
      { to: '/admin/branches', end: false, label: 'Branches', icon: Network },
      { to: '/admin/divisions', end: false, label: 'Divisions', icon: Layers },
      { to: '/admin/departments', end: false, label: 'Departments', icon: Layers },
      { to: '/admin/sections-units', end: false, label: 'Sections / Units', icon: Users },
    ],
  },

  // Attendance & Time (collapsible)
  {
    label: 'Attendance & Time',
    icon: CalendarCheck,
    children: [
      { to: '/admin/holiday', end: false, label: 'Holiday', icon: Calendar },
      { to: '/admin/attendance', end: false, label: 'Attendance', icon: CalendarCheck },
      { to: '/admin/corrections', end: false, label: 'Correction Requests', icon: ClipboardList },
      { to: '/admin/overtime', end: false, label: 'Overtime', icon: Timer },
      { to: '/admin/leave', end: false, label: 'Leave', icon: CalendarOff },
      { to: '/admin/my-schedule', end: false, label: 'My Schedule', icon: CalendarClock },
      { to: '/admin/schedule-requests', end: false, label: 'Schedule Requests', icon: ClipboardList },
      { to: '/admin/schedules', end: false, label: 'Schedules', icon: CalendarClock },
      { to: '/admin/qr', end: false, label: 'My QR & Facial', icon: QrCode },
      { to: '/admin/daily-computation', end: false, label: 'Daily Computation', icon: Calculator },
    ],
  },

  // Reports
  { to: '/admin/reports', end: false, label: 'Reports', icon: FileText },

  /** Self-service: own deductions & loan requests (`loans.view_own` and/or `request-loan`; legacy `loans.request` honored). */
  { to: '/admin/loans-deductions', end: false, label: 'My Loans & Deductions', icon: HandCoins },

  // Settings (collapsible)
  {
    label: 'Settings',
    icon: Settings,
    children: [
      { to: '/admin/users-permissions', end: false, label: 'Users & Permissions', icon: UserCog },
      { to: '/admin/profile', end: false, label: 'Profile', icon: User },
      { to: '/admin/daily-computation/policy-settings', end: false, label: 'Policy Settings', icon: Settings },
      { to: '/admin/approval-workflow-settings', end: false, label: 'Approval Workflow', icon: ShieldCheck },
    ],
  },
]

export const employeeNavItems = [
  { to: '/employee/dashboard', end: true, label: 'Dashboard', icon: LayoutDashboard },
  { to: '/employee/attendance', end: false, label: 'My Attendance', icon: CalendarCheck },
  { to: '/employee/correction-requests', end: false, label: 'Correction Requests', icon: ClipboardList },
  { to: '/employee/schedule', end: false, label: 'My Schedule', icon: CalendarClock },
  { to: '/employee/qr', end: false, label: 'My QR & Facial', icon: QrCode },
  { to: '/employee/payslips', end: false, label: 'Payslips', icon: Receipt },
  { to: '/employee/loans-deductions', end: false, label: 'My Loans & Deductions', icon: HandCoins },
  { to: '/employee/requests', end: false, label: 'Leave', icon: CalendarOff },
  { to: '/employee/overtime', end: false, label: 'Overtime', icon: Timer },
  { to: '/employee/reports', end: false, label: 'Reports', icon: FileText },
  { to: '/employee/profile', end: false, label: 'Profile', icon: User },
]
