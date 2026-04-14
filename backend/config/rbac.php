<?php

/**
 * RBAC registry: permission definitions and default role → permission grants.
 * Seeded into `permissions` and `role_permissions`; super admins may adjust via API (future).
 *
 * Slug convention: {module}.{action} e.g. employees.view, payroll.compute
 */
return [
    'permissions' => [
        // 1. Dashboard & overview
        ['slug' => 'dashboard.view', 'module' => 'dashboard', 'label' => 'View dashboard', 'description' => 'KPIs, attendance snapshot, company-wide widgets'],

        // 2. Employee management
        ['slug' => 'employees.view', 'module' => 'employees', 'label' => 'View employees', 'description' => 'List and open employee records in scope'],
        ['slug' => 'employees.create', 'module' => 'employees', 'label' => 'Create employees', 'description' => 'Add new employee accounts'],
        ['slug' => 'employees.edit', 'module' => 'employees', 'label' => 'Edit employees', 'description' => 'Update profiles, schedules, QR/face, activation'],
        ['slug' => 'employees.delete', 'module' => 'employees', 'label' => 'Delete employees', 'description' => 'Remove employee records'],
        ['slug' => 'employees.import', 'module' => 'employees', 'label' => 'Bulk import', 'description' => 'Import employees in bulk (when implemented)'],
        ['slug' => 'employees.export', 'module' => 'employees', 'label' => 'Export roster', 'description' => 'Export employee data'],
        ['slug' => 'employees.sensitive', 'module' => 'employees', 'label' => 'Sensitive HR data', 'description' => 'Salary, statutory IDs, full PII (DPA-aligned)'],
        ['slug' => 'employees.transfer', 'module' => 'employees', 'label' => 'Transfer employees', 'description' => 'Org transfers between branches/departments'],
        ['slug' => 'employees.password_reset', 'module' => 'employees', 'label' => 'Reset passwords', 'description' => 'Trigger password reset for employees'],

        // 3. Attendance & daily computation (monitoring + corrections)
        ['slug' => 'attendance.view', 'module' => 'attendance', 'label' => 'View attendance', 'description' => 'Monitor logs and daily status in scope'],
        ['slug' => 'attendance.corrections.create', 'module' => 'attendance', 'label' => 'Create corrections', 'description' => 'Submit DTR corrections'],
        ['slug' => 'attendance.corrections.approve', 'module' => 'attendance', 'label' => 'Approve attendance corrections', 'description' => 'Multi-level approval of manual attendance filings (remarks at each step)'],
        ['slug' => 'attendance.corrections.delete', 'module' => 'attendance', 'label' => 'Delete corrections', 'description' => 'Remove pending corrections'],

        // 4. Overtime
        ['slug' => 'overtime.view', 'module' => 'overtime', 'label' => 'View OT', 'description' => 'List overtime requests in scope'],
        ['slug' => 'overtime.approve', 'module' => 'overtime', 'label' => 'Approve OT', 'description' => 'Approve or reject overtime'],
        ['slug' => 'overtime.export', 'module' => 'overtime', 'label' => 'Export OT', 'description' => 'Export overtime reports'],
        ['slug' => 'overtime.edit_hours', 'module' => 'overtime', 'label' => 'Edit OT hours', 'description' => 'Adjust approved hours'],

        // 5. Leave
        ['slug' => 'leave.view', 'module' => 'leave', 'label' => 'View leave', 'description' => 'See leave requests in scope'],
        ['slug' => 'leave.approve', 'module' => 'leave', 'label' => 'Approve leave', 'description' => 'Approve or reject leave'],
        ['slug' => 'leave.notes', 'module' => 'leave', 'label' => 'Leave notes', 'description' => 'Edit admin notes on leave records'],

        // 6. Holidays
        ['slug' => 'holiday.view', 'module' => 'holiday', 'label' => 'View holidays', 'description' => 'View holiday calendar'],
        ['slug' => 'holiday.manage', 'module' => 'holiday', 'label' => 'Manage holidays', 'description' => 'Create/update/delete holidays'],

        // 7. Schedules & work calendar
        ['slug' => 'schedule.view', 'module' => 'schedule', 'label' => 'View schedules', 'description' => 'View work schedules and assignments'],
        ['slug' => 'schedule.manage', 'module' => 'schedule', 'label' => 'Manage schedules', 'description' => 'CRUD schedule templates'],
        ['slug' => 'schedule.assign', 'module' => 'schedule', 'label' => 'Assign schedules', 'description' => 'Assign schedules to employees'],
        ['slug' => 'view-my-schedule', 'module' => 'schedule', 'label' => 'View my schedule', 'description' => 'View your current assigned schedule and request history'],
        ['slug' => 'request-schedule', 'module' => 'schedule', 'label' => 'Request schedule', 'description' => 'Request a new working schedule template'],
        ['slug' => 'approve-schedule', 'module' => 'schedule', 'label' => 'Approve schedule requests', 'description' => 'Approve or reject schedule change requests in scope'],
        ['slug' => 'manage-schedules', 'module' => 'schedule', 'label' => 'Manage schedules (admin)', 'description' => 'Full administrative control over schedule templates and assignments'],

        // 8. Reports
        ['slug' => 'reports.view', 'module' => 'reports', 'label' => 'View reports', 'description' => 'Attendance, detailed, premium summaries'],
        ['slug' => 'reports.export', 'module' => 'reports', 'label' => 'Export reports', 'description' => 'Download report extracts'],
        ['slug' => 'reports.payroll', 'module' => 'reports', 'label' => 'Payroll-related reports', 'description' => 'Reports tied to compensation'],

        // 9. Payroll core
        ['slug' => 'payroll.view', 'module' => 'payroll', 'label' => 'View payroll', 'description' => 'Preview/classify/daily logs/periods'],
        ['slug' => 'payroll.compute', 'module' => 'payroll', 'label' => 'Run payroll compute', 'description' => 'Execute payroll computation jobs'],
        ['slug' => 'payroll.policies', 'module' => 'payroll', 'label' => 'Pay policies', 'description' => 'Manage pay rules and policies'],
        ['slug' => 'payroll.export', 'module' => 'payroll', 'label' => 'Export payroll', 'description' => 'Export payroll outputs'],
        ['slug' => 'payroll.generate_payslips', 'module' => 'payroll', 'label' => 'Generate payslips', 'description' => 'Bulk payslip generation and previews'],
        ['slug' => 'payroll.finalize', 'module' => 'payroll', 'label' => 'Finalize payroll', 'description' => 'Finalize payroll and lock periods'],

        // 10. Compensation
        ['slug' => 'compensation.view', 'module' => 'compensation', 'label' => 'View compensation', 'description' => 'View pay components, pay cycles, and compensation settings'],
        ['slug' => 'compensation.pay_cycles.manage', 'module' => 'compensation', 'label' => 'Manage pay cycles', 'description' => 'Create, update, and delete pay cycles'],
        ['slug' => 'compensation.pay_components.manage', 'module' => 'compensation', 'label' => 'Manage pay components', 'description' => 'Create, update, and delete pay components'],
        ['slug' => 'compensation.employee_compensation.view', 'module' => 'compensation', 'label' => 'View employee compensation', 'description' => 'View employee compensation details and previews'],
        ['slug' => 'compensation.employee_compensation.assign', 'module' => 'compensation', 'label' => 'Assign employee compensation', 'description' => 'Assign and modify employee compensation details'],
        ['slug' => 'compensation.payroll.prorate', 'module' => 'compensation', 'label' => 'Pro-rate payroll', 'description' => 'Handle pro-ration logic across compensation and payroll cycles'],
        ['slug' => 'compensation.deductions_loans.admin', 'module' => 'compensation', 'label' => 'Admin deductions & loans', 'description' => 'Admin module for deductions and internal loan management'],

        ['slug' => 'loans.view_own', 'module' => 'loans', 'label' => 'View own loans & deductions', 'description' => 'Self-service: assigned deductions and loan request history'],
        /** Canonical submit permission (self or scoped employee for org heads). Legacy: {@see loans.request}. */
        ['slug' => 'request-loan', 'module' => 'loans', 'label' => 'Request loan', 'description' => 'Submit a loan request for your own account (Admin HR approves)'],
        ['slug' => 'loans.request', 'module' => 'loans', 'label' => 'Request loan (legacy)', 'description' => 'Submit a loan request for approval'],
        ['slug' => 'loans.view', 'module' => 'loans', 'label' => 'View loan requests', 'description' => 'List loan requests in organizational scope'],
        ['slug' => 'loans.approve', 'module' => 'loans', 'label' => 'Approve loans', 'description' => 'Approve or reject loan requests (Admin HR only)'],
        ['slug' => 'loans.types.manage', 'module' => 'loans', 'label' => 'Manage deduction types', 'description' => 'CRUD master deduction types (loans/benefits)'],
        ['slug' => 'loans.assign', 'module' => 'loans', 'label' => 'Assign employee deductions', 'description' => 'Assign manual deductions to employees'],

        // 11. Government deductions
        ['slug' => 'government_deductions.view', 'module' => 'government_deductions', 'label' => 'View government deductions', 'description' => 'View SSS, PhilHealth, Pag-IBIG, and related statutory settings'],
        ['slug' => 'government_deductions.manage', 'module' => 'government_deductions', 'label' => 'Manage government deductions', 'description' => 'Edit statutory rates, brackets, contribution tables, and settings'],
        ['slug' => 'government_deductions.audit', 'module' => 'government_deductions', 'label' => 'Run compliance audit', 'description' => 'Run audit previews and compliance checks for statutory contributions'],
        ['slug' => 'government_deductions.rates.view', 'module' => 'government_deductions', 'label' => 'View statutory rates', 'description' => 'View rate history, compliance metadata, and statutory references'],
        ['slug' => 'government_deductions.remittances.manage', 'module' => 'government_deductions', 'label' => 'Manage statutory remittances', 'description' => 'Handle remittance reports, exports, and future filing workflows'],

        // 12. User & role management
        ['slug' => 'users.view', 'module' => 'users', 'label' => 'View users', 'description' => 'List and search user accounts'],
        ['slug' => 'users.manage', 'module' => 'users', 'label' => 'Manage users', 'description' => 'Create, edit, assign roles, activate/deactivate, reset passwords'],
        ['slug' => 'rbac.manage', 'module' => 'rbac', 'label' => 'Manage RBAC', 'description' => 'Change role-permission mappings'],
        ['slug' => 'rbac.audit', 'module' => 'rbac', 'label' => 'View RBAC audit', 'description' => 'Read permission change history'],

        // 13. Company & branch settings
        ['slug' => 'org.company.view', 'module' => 'organization', 'label' => 'View companies', 'description' => 'List companies'],
        ['slug' => 'org.company.manage', 'module' => 'organization', 'label' => 'Manage companies', 'description' => 'Create/update/delete companies'],
        ['slug' => 'org.branch.view', 'module' => 'organization', 'label' => 'View branches', 'description' => 'List branches in scope'],
        ['slug' => 'org.branch.manage', 'module' => 'organization', 'label' => 'Manage branches', 'description' => 'Create/update/delete branches'],
        ['slug' => 'org.department.view', 'module' => 'organization', 'label' => 'View departments', 'description' => 'List departments in scope'],
        ['slug' => 'org.department.manage', 'module' => 'organization', 'label' => 'Manage departments', 'description' => 'Create/update/delete departments'],

        // 14. Benefits (catalog + assignment)
        ['slug' => 'benefits.catalog', 'module' => 'benefits', 'label' => 'Benefit catalog', 'description' => 'Manage benefit catalog entries'],
        ['slug' => 'benefits.assign', 'module' => 'benefits', 'label' => 'Assign benefits', 'description' => 'Assign benefits to employees'],

        // 15. Documents & files
        ['slug' => 'documents.view', 'module' => 'documents', 'label' => 'View documents', 'description' => 'View employee documents in scope'],
        ['slug' => 'documents.review', 'module' => 'documents', 'label' => 'Review documents', 'description' => 'Verify/review uploaded documents'],

        // 16. Notifications & alerts
        ['slug' => 'notifications.view', 'module' => 'notifications', 'label' => 'View notifications', 'description' => 'See system notifications'],
        ['slug' => 'notifications.manage', 'module' => 'notifications', 'label' => 'Manage notifications', 'description' => 'Configure alerts (when implemented)'],

        // 17. Profile
        ['slug' => 'profile.view', 'module' => 'profile', 'label' => 'View profile', 'description' => 'View own profile and scoped employee profile pages'],
        ['slug' => 'profile.edit', 'module' => 'profile', 'label' => 'Edit profile details', 'description' => 'Edit personal/contact/employment profile details'],
        ['slug' => 'profile.picture.edit', 'module' => 'profile', 'label' => 'Edit profile picture', 'description' => 'Upload/remove profile picture only'],
        ['slug' => 'profile.salary.edit', 'module' => 'profile', 'label' => 'Edit salary details', 'description' => 'Edit compensation and salary fields on employee profile (Admin/HR)'],
    ],

    /**
     * Default grants per HrRole value. Use '*' for all registered permissions (ADMIN HR).
     */
    'default_role_permissions' => [
        'admin_hr' => ['*'],
        /**
         * Org heads use the same Employee app UI as staff. Scoped data via DataScopeService.
         * No admin-only modules (org CRUD, payroll, users, holiday manage, etc.).
         */
        /** Scoped manager tools via `/employee/manager/*` (same shell as employees). No global admin modules. */
        'company_head' => [
            'profile.view',
            'profile.picture.edit',
            'view-my-schedule',
            'request-schedule',
            'approve-schedule',
            'employees.view',
            'attendance.view',
            'reports.view',
            'reports.export',
            'attendance.corrections.create',
            'attendance.corrections.approve',
            'leave.view',
            'leave.approve',
            'leave.notes',
            'loans.view_own',
            'request-loan',
            'loans.request',
            'loans.view',
            'loans.types.manage',
            'loans.assign',
            'overtime.view',
            'overtime.approve',
            'compensation.view',
            'compensation.pay_cycles.manage',
            'compensation.pay_components.manage',
            'compensation.employee_compensation.view',
            'compensation.employee_compensation.assign',
            'compensation.payroll.prorate',
            'government_deductions.view',
            'government_deductions.manage',
            'government_deductions.audit',
            'government_deductions.rates.view',
            'government_deductions.remittances.manage',
        ],
        'branch_head' => [
            'profile.view',
            'profile.picture.edit',
            'view-my-schedule',
            'request-schedule',
            'approve-schedule',
            'employees.view',
            'attendance.view',
            'reports.view',
            'reports.export',
            'attendance.corrections.create',
            'attendance.corrections.approve',
            'leave.view',
            'leave.approve',
            'leave.notes',
            'loans.view_own',
            'request-loan',
            'loans.request',
            'loans.view',
            'loans.assign',
            'overtime.view',
            'overtime.approve',
            'compensation.view',
            'compensation.employee_compensation.view',
            'government_deductions.view',
            'government_deductions.audit',
            'government_deductions.rates.view',
        ],
        'department_head' => [
            'profile.view',
            'profile.picture.edit',
            'view-my-schedule',
            'request-schedule',
            'approve-schedule',
            'employees.view',
            'attendance.view',
            'reports.view',
            'reports.export',
            'attendance.corrections.create',
            'attendance.corrections.approve',
            'leave.view',
            'leave.approve',
            'leave.notes',
            'loans.view_own',
            'request-loan',
            'loans.request',
            'loans.view',
            'loans.assign',
            'overtime.view',
            'overtime.approve',
            'compensation.view',
            'compensation.employee_compensation.view',
            'government_deductions.view',
            'government_deductions.rates.view',
        ],
        /** Standard employee: personal app + filing attendance corrections (approval chain applies). */
        'employee' => [
            'profile.view',
            'profile.picture.edit',
            'view-my-schedule',
            'request-schedule',
            'attendance.corrections.create',
            'loans.view_own',
            'request-loan',
            'loans.request',
        ],
    ],
];
