# HRIS Complete System Manual

## A Step-by-Step Guide to All Modules

**Version 1.0 | July 2026**

---

# Part 1: Admin Panel (For HR & Administrators)

The **Admin Panel** is the main control center for HR staff and administrators. It is organized into logical groups to help you manage everything about your workforce — from employee records to payroll.

---

## 1. Dashboard

**Where to find it:** Side Menu → Dashboard

**Purpose:** The Dashboard is your at-a-glance summary screen. When you log in, this is the first page you see. It shows you the current state of the company through simple numbers, charts, and quick links.

**What you can see:**
- **Employee count** — total number of active employees in the system
- **Attendance status** — how many employees have clocked in today
- **Pending requests** — how many leave, overtime, or correction requests are waiting for your approval
- **Recent activity** — latest changes and updates made in the system
- **Quick shortcuts** — buttons to jump to common tasks like adding an employee or approving leaves

**What you can do:**
- Review important numbers at the start of your day
- Click on any summary card to go directly to the related module
- Get a quick sense of what needs your attention

---

# WORKFORCE MODULES

## 2. Employees

**Where to find it:** Side Menu → Workforce → Employees

**Purpose:** The Employees module is where you manage all employee records. Think of it as the company's digital employee filing cabinet.

*Refer to the separate **Employee Module Manual** (`HRIS_EMPLOYEE_MANUAL.md`) for the complete guide on this module.*

**Quick Summary of What You Can Do Here:**
- View the full employee list (search, filter, sort)
- Add new employees when they join
- View and edit employee profiles (personal info, employment details, salary, documents, etc.)
- Issue QR codes for attendance scanning
- Register employees for face recognition
- Assign work schedules
- Activate or deactivate accounts
- Import/export employee data using Excel
- Reset passwords
- Delete employee records

---

## 3. Recruitment

**Where to find it:** Side Menu → Workforce → Recruitment

**Purpose:** The Recruitment module helps you manage job applicants and the hiring process — from application to job offer.

**Who uses it:** HR staff handling recruitment and hiring

**What you can see:**
- **Applicants list** — all people who have applied for jobs
- Each applicant's information (name, position applied for, status, date applied)
- Applicant status tracking

**What you can do:**
- **View applicants** — see a list of everyone who applied
- **Track progress** — follow each applicant through the hiring stages (New → Screening → Interview → Job Offer → Hired)
- **Review applications** — click on an applicant to see their full details
- **Update statuses** — move applicants through the hiring pipeline as they progress

---

## 4. Regularization

**Where to find it:** Side Menu → Workforce → Regularization

**Purpose:** The Regularization module helps you manage the process of converting probationary employees to regular (permanent) status. It tracks who is up for regularization and manages recommendations.

**Who uses it:** HR staff and supervisors

**What you can see:**
- Employees currently on probationary status
- Regularization dates (when each employee is due for evaluation)
- Recommendation status

**What you can do:**
- **View upcoming regularizations** — see which employees are approaching their regularization date
- **Track recommendations** — review regularization recommendations from supervisors
- **Process regularization** — update an employee's status from Probationary to Regular when approved
- **View milestones** — see important dates in the probationary period

**When to use this module:**
- When a probationary employee is nearing their evaluation date
- When you need to process a regularization recommendation
- To check which employees are still on probation

---

# ORGANIZATION MODULES

## 5. Companies

**Where to find it:** Side Menu → Organization → Companies

**Purpose:** Manage the companies within your organization. If your business has multiple companies (or entities), each one is set up here.

**Who uses it:** HR administrators setting up the organizational structure

**What you can see:**
- List of all companies with their details

**What you can do:**
- **Add a company** — create a new company record (name, address, contact details)
- **Edit company info** — update company name, address, contact information
- **Assign a Company Head** — designate who is the head/manager of this company
- **Deactivate a company** — mark a company as inactive if needed

**Why this matters:** Employees are assigned to companies, which affects:
- Which employees you can see (data scoping)
- Payroll processing groups
- Attendance monitoring scope
- Reporting structure

---

## 6. Areas

**Where to find it:** Side Menu → Organization → Areas

**Purpose:** Areas are a grouping level between Companies and Branches. Not all organizations use Areas — they are optional and help group branches by region or zone (e.g., "Metro Manila", "Visayas", "Mindanao").

**Who uses it:** HR administrators setting up the organizational hierarchy

**What you can do:**
- **Add an Area** — create a new area under a company
- **Edit area details** — update name and other information
- **Delete an area** — remove it (only if no branches are assigned to it)

---

## 7. Branches

**Where to find it:** Side Menu → Organization → Branches

**Purpose:** Manage your company's physical locations or branches (e.g., "Makati Office", "Cebu Branch", "Laguna Plant").

**Who uses it:** HR administrators and branch managers

**What you can see:**
- List of all branches
- Each branch's company, area, address, contact person
- Branch manager assignment
- Geofence settings (for attendance validation)

**What you can do:**
- **Add a branch** — create a new branch under a company (and optionally an area)
- **Edit branch info** — update name, address, contact details, manager
- **Assign a manager** — set who is the Branch Head/Manager
- **Manage geofences** — set up the virtual boundaries for attendance (explained in Geofencing module)
- **Delete a branch** — remove it (only if no employees are assigned)

**Why this matters:** Branches are a key part of the system because:
- Employees are assigned to branches
- Geofencing uses branch locations
- Attendance can be tracked per branch

---

## 8. Divisions

**Where to find it:** Side Menu → Organization → Divisions

**Purpose:** Divisions are a sub-level within a company or branch. They group related departments together (e.g., "Operations Division", "Finance Division", "Sales Division").

**Who uses it:** HR administrators

**What you can do:**
- **Add a Division** — create a new division under a company or branch
- **Edit division details**
- **Assign a Division Head**
- **Delete a division**

---

## 9. Departments

**Where to find it:** Side Menu → Organization → Departments

**Purpose:** Departments are where work gets organized. They are the primary grouping for employees (e.g., "IT Department", "Human Resources", "Marketing", "Accounting").

**Who uses it:** HR administrators and department heads

**What you can see:**
- List of all departments
- Which branch, division, or company each department belongs to
- Department head assignment
- Team leaders assigned to the department
- Number of employees in each department

**What you can do:**
- **Add a Department** — create a new department
- **Edit department info**
- **Assign a Department Head** — designate who manages this department
- **Assign Team Leaders** — add team leaders who help manage the department
- **View employees** — see a list of employees in this department
- **Delete a department**

---

## 10. Sections & Units

**Where to find it:** Side Menu → Organization → Sections & Units

**Purpose:** Sections (or Units) are the smallest grouping within an organization. They sit under departments or divisions (e.g., "Software Development Team", "Quality Assurance Unit", "Payroll Section").

**Who uses it:** HR administrators

**What you can do:**
- **Add a Section/Unit** — create a new section
- **Assign a Section Head**
- **Assign Team Leaders**
- **Edit or delete** sections

---

# TIME & ATTENDANCE MODULES

## 11. Attendance

**Where to find it:** Side Menu → Time & Attendance → Attendance

**Purpose:** The Attendance module lets you monitor who is clocking in and out, view daily records, and ensure everyone is logging their time properly.

**Who uses it:** HR staff, supervisors, managers

**What you can see:**
- **Today's attendance** — live view of who has clocked in, who hasn't
- **Employee attendance records** — searchable list of daily logs
- **Status indicators** — who is on time, late, or absent
- **Date range selector** — view attendance for any day or date range

**What you can do:**
- **View logs** — see each employee's clock-in and clock-out times for any given day
- **Filter** — narrow down by company, branch, department, or individual employee
- **Export** — download attendance data for reporting
- **Spot issues** — quickly see who is missing or has irregular logs

**Understanding Status Indicators:**
- **Present** — employee clocked in and out (completed attendance)
- **Late** — employee clocked in after their scheduled start time
- **Incomplete** — employee clocked in but forgot to clock out (or vice versa)
- **Absent** — no attendance record for the day
- **On Leave** — employee has an approved leave for this day

---

## 12. Work Schedules

**Where to find it:** Side Menu → Time & Attendance → Work Schedules

**Purpose:** Create and manage work schedule templates that define when employees should start and end their work day.

**Who uses it:** HR administrators and schedule managers

**What you can see:**
- List of all schedule templates
- Each schedule's name, time in/out, break times, and rest days

**What you can do:**
- **Add a schedule** — create a new schedule template (e.g., "Morning Shift 8AM-5PM", "Night Shift 9PM-6AM")
- **Edit a schedule** — change time in, time out, break start/end, or rest days
- **Delete a schedule** — remove unused schedules
- **View all** — see which employees are assigned to which schedule

**Schedule Details:**
- **Time In** — when the work day starts
- **Time Out** — when the work day ends
- **Break Start / Break End** — lunch or rest break period
- **Rest Days** — which days of the week are non-working (e.g., Saturday and Sunday)
- **Grace Period** — how many minutes an employee can be late without penalty

**How schedules are used:** Once created, schedules are assigned to employees (from the Employees module or directly here) so the system knows when they should be working.

---

## 13. Schedule Approvals

**Where to find it:** Side Menu → Time & Attendance → Schedule Approvals

**Purpose:** When employees request a change to their work schedule (temporary or permanent), those requests come here for review and approval.

**Who uses it:** HR staff, supervisors, managers

**What you can see:**
- Pending schedule change requests from employees
- Request details (current schedule vs. requested schedule, reason, dates)

**What you can do:**
- **Review requests** — see why the employee wants to change their schedule
- **Approve** — accept the schedule change
- **Reject** — decline the request (with optional reason)
- **Bulk approve/reject** — process multiple requests at once

---

## 14. Attendance Corrections

**Where to find it:** Side Menu → Time & Attendance → Attendance Corrections

**Purpose:** When employees have incorrect attendance records (e.g., they forgot to clock in, their time was wrong, or they need a manual adjustment), they submit a correction request. This is where you review and approve those requests.

**Who uses it:** HR staff and supervisors

**What you can see:**
- List of pending correction requests from employees
- Each request's details (date, current record, requested change, reason)

**What you can do:**
- **Review corrections** — check the employee's explanation and supporting details
- **Approve** — accept the correction (the attendance record will be updated)
- **Reject** — decline the correction (with reason)
- **Bulk approve** — approve multiple corrections at once

**When to approve:**
- Employee forgot to clock in (but was actually present)
- Biometric/qr scan didn't register
- Employee was on official business and couldn't clock in/out

---

## 15. Overtime

**Where to find it:** Side Menu → Time & Attendance → Overtime

**Purpose:** Manage employee overtime requests — from filing to approval and payroll integration.

**Who uses it:** HR staff, supervisors, payroll team

**What you can see:**
- List of overtime requests (pending, approved, rejected)
- Employee name, date, overtime hours, reason
- Approval status

**What you can do:**
- **Review requests** — see the details of each overtime filing
- **Approve** — authorize overtime (the hours will count toward pay)
- **Reject** — decline the overtime request
- **Bulk approve/reject** — process multiple overtime requests at once
- **Export** — download overtime data for reporting

**Important Note:** Overtime approval affects payroll — approved overtime hours are included in the employee's pay computation.

---

## 16. Leave

**Where to find it:** Side Menu → Time & Attendance → Leave

**Purpose:** Manage employee leave requests — vacation, sick leave, emergency leave, and other types of time off.

**Who uses it:** HR staff and supervisors

**What you can see:**
- List of all leave requests from employees
- Leave type (Vacation, Sick, Emergency, etc.)
- Dates, duration (number of days), reason
- Leave credits remaining for each employee
- Approval status

**What you can do:**
- **Review requests** — check leave details and supporting documents
- **Approve** — accept the leave request
- **Reject** — decline (with reason)
- **Bulk approve/reject** — process multiple leave requests at once
- **View leave balances** — see how many leave days each employee has remaining
- **Filter** — view leaves by status, date range, department, or employee

**Leave Types Commonly Used:**
- **Vacation Leave (VL)** — planned time off
- **Sick Leave (SL)** — for health/medical reasons
- **Emergency Leave** — unexpected personal emergencies
- **Maternity/Paternity Leave** — for new parents
- **Special Leave** — other company-approved time off

---

## 17. Holidays

**Where to find it:** Side Menu → Time & Attendance → Holidays

**Purpose:** Set up and manage company holidays. The system uses this information to calculate holiday pay and adjust attendance expectations.

**Who uses it:** HR administrators

**What you can see:**
- List of all holidays (past and upcoming)
- Holiday name, date, type (regular/ special/ company-specific)

**What you can do:**
- **Add a holiday** — create a new holiday entry
- **Set holiday type** — choose Regular Holiday, Special Non-Working Day, or Company Special Holiday
- **Edit or delete** holidays
- **View applicable companies** — some holidays may apply to specific companies only

**Why this matters:**
- Holiday pay rates are automatically applied during payroll
- Attendance expectations change on holidays
- Employees know which days are non-working or special

---

## 18. Geofencing

**Where to find it:** Side Menu → Time & Attendance → Geofencing

**Purpose:** Geofencing creates virtual boundaries around your office locations. Employees must be inside these boundaries to clock in or out, preventing attendance fraud (e.g., clocking in from home).

**Who uses it:** HR administrators and branch managers

**What you can see:**
- Map view of your branches with geofence boundaries drawn
- List of geofences per branch
- Geofence settings (radius, shape, accuracy requirements)

**What you can do:**
- **Add a geofence** — draw a circular or polygon boundary on the map around your office
- **Set accuracy** — define how precise the GPS location needs to be (e.g., within 50 meters)
- **Set device rules** — choose whether geofencing applies to all devices, mobile only, or desktop only
- **Test geofences** — validate that locations fall inside/outside the boundary
- **View logs** — see geofence validation history (who tried to clock in from where)
- **Edit or delete** geofences when office locations change

**In simple terms:** Think of it like drawing a digital fence around your office. The system checks if the employee's phone/device is inside the fence before allowing them to clock in.

---

# PAYROLL MODULES

## 19. Pay Cycles

**Where to find it:** Side Menu → Payroll → Pay Cycles

**Purpose:** Pay Cycles define how often and when employees get paid. This is the foundation of your payroll schedule.

**Who uses it:** Payroll administrators

**What you can see:**
- List of all pay cycles (e.g., Semi-Monthly, Weekly, Monthly)
- Cut-off dates and pay dates

**What you can do:**
- **Add a pay cycle** — create a new cycle (e.g., "1st-15th and 16th-31st" for semi-monthly)
- **Set cut-off dates** — define the start and end dates of each pay period
- **Set pay date** — when salaries are released
- **Assign to employees** — each employee is assigned to a pay cycle
- **Edit or deactivate** pay cycles

**Examples:**
- **Semi-Monthly:** Pay on the 15th and 30th/31st of each month
- **Weekly:** Pay every Friday
- **Monthly:** Pay once a month (usually the 15th or end of month)

---

## 20. Pay Components

**Where to find it:** Side Menu → Payroll → Pay Components

**Purpose:** Pay Components are the building blocks of an employee's salary. They define the various earnings and deduction items that appear on payslips.

**Who uses it:** Payroll administrators

**What you can see:**
- List of all pay components
- Type (Earnings or Deductions)
- How they are calculated (fixed amount, percentage, or formula-based)
- Applicable companies

**What you can do:**
- **Add a pay component** — create new earning or deduction items
- **Set calculation method** — fixed amount, percentage of basic pay, or custom formula
- **Set taxability** — whether this component is taxable or tax-exempt
- **Assign to employees** — determine which employees receive this component
- **Edit or deactivate** components

**Common Pay Components:**
- **Earnings:** Basic Pay, Rice Allowance, Transportation Allowance, Night Differential, Holiday Pay, Overtime Pay
- **Deductions:** SSS Contribution, PhilHealth, Pag-IBIG, Withholding Tax, Late Deductions, Absences

---

## 21. Employee Pay Setup

**Where to find it:** Side Menu → Payroll → Employee Pay Setup

**Purpose:** A centralized place to view and manage the compensation setup for all employees — what components they receive, at what amounts.

**Who uses it:** Payroll administrators and HR staff

**What you can see:**
- List of employees with their current compensation setup
- Each employee's active pay components and amounts
- Deductions and loans currently active

**What you can do:**
- **View employee compensation** — see all earnings and deductions for an employee
- **Adjust components** — add, modify, or remove pay components for individual employees
- **Check effective dates** — see when each component started or will start
- **Preview changes** — see how changes affect the employee's pay before saving

---

## 22. Deduction Schedules

**Where to find it:** Side Menu → Payroll → Deduction Schedules

**Purpose:** Define how often certain deductions are applied to an employee's pay — some deductions happen every pay period, others once a month, etc.

**Who uses it:** Payroll administrators

**What you can see:**
- List of deduction schedules
- Frequency settings (per pay period, monthly, quarterly, etc.)

**What you can do:**
- Create and manage deduction schedules
- Assign schedules to specific deduction types
- Control when deductions run in the payroll cycle

---

## 23. Government Deductions

**Where to find it:** Side Menu → Payroll → Government Deductions

**Purpose:** Manage government-mandated contributions and deductions — SSS, PhilHealth, Pag-IBIG, and Tax. This module handles the rates, tables, and settings.

**Who uses it:** Payroll administrators

**What you can see:**
- List of government deduction types
- Current contribution tables/rates
- Employer and employee share amounts
- Exemption settings for employees

**What you can do:**
- **Update contribution tables** — update SSS, PhilHealth, and Pag-IBIG rates when the government changes them
- **Set employer/employee shares** — define how much the company and employee each contribute
- **Manage exemptions** — mark employees as exempt from certain deductions (if applicable)
- **View employee settings** — see each employee's government deduction setup

**Important:** Keeping these settings updated is critical for accurate payroll compliance.

---

## 24. Loans & Deductions

**Where to find it:** Side Menu → Payroll → Loans & Deductions

**Purpose:** Manage employee loans (company loans, salary advances, government loans like Pag-IBIG) and other non-government deductions.

**Who uses it:** Payroll administrators and HR staff

**What you can see:**
- List of all loans and deductions
- Employee name, loan type, amount, remaining balance
- Payment schedule and status
- Deduction types available to assign

**What you can do:**
- **View loan details** — see the full breakdown of any loan
- **Track balances** — monitor how much each employee still owes
- **Assign deductions** — set up automatic deductions from payroll
- **Manage payment schedules** — define how much is deducted per pay period
- **Edit deduction types** — create or modify deduction types used for employee-specific deductions

---

## 25. Generate Payslips

**Where to find it:** Side Menu → Payroll → Generate Payslips

**Purpose:** This is where you run payroll for a pay period. The system computes each employee's pay based on attendance, overtime, leave, deductions, and compensation setup — then generates payslips.

**Who uses it:** Payroll administrators

**Step-by-Step Process:**

**Step 1: Select a Pay Period**
- Choose the company/companies
- Select the pay cycle and period (e.g., "July 1-15, 2026")
- Click **"Preview"** to see a summary before finalizing

**Step 2: Review Preview**
- The system shows a summary of gross pay, deductions, and net pay for each employee
- Check that everything looks correct
- You can drill down into individual employee calculations

**Step 3: Generate Payslips**
- Click **"Generate"** to create the payslips
- The system computes everything and creates individual payslip records
- A confirmation message will appear when done

**Step 4: Notify Employees**
- Once generated, employees can view their payslips from their dashboard
- You may also choose to send email notifications

---

## 26. 13th Month Pay

**Where to find it:** Side Menu → Payroll → 13th Month Pay

**Purpose:** Compute and manage 13th Month Pay — a mandatory benefit in the Philippines. This module handles the calculation based on each employee's basic salary for the year.

**Who uses it:** Payroll administrators

**What you can see:**
- Eligible employees list
- Computed 13th month pay amounts
- Payment status

**What you can do:**
- **View 13th month computation** — see how the amount was calculated for each employee
- **Generate 13th month payslips**
- **Track payment status** — whether it has been released

**How it's calculated:** The basic 13th month pay formula is: **Total Basic Salary Earned ÷ 12 months**

---

## 27. Daily Payroll (Daily Computation)

**Where to find it:** Side Menu → Payroll → Daily Payroll

**Purpose:** This module shows the daily computation of employee pay based on attendance, schedules, and time records. It bridges attendance data and payroll processing.

**Who uses it:** Payroll administrators

**What you can see:**
- Daily computation records per employee
- Hours worked, overtime hours, late minutes, undertime
- Daily earnings breakdown
- Policy rules applied

**What you can do:**
- **View daily logs** — see how each day was computed for payroll purposes
- **Check policy settings** — review the rules used for computation
- **Identify issues** — spot days that need manual review

**Why this matters:** This module helps you catch attendance and pay issues before final payroll, ensuring employees are paid correctly for the time they worked.

---

## 28. EXECOM Payroll

**Where to find it:** Side Menu → Payroll → EXECOM Payroll

**Purpose:** A separate payroll module specifically for **Executive Committee (EXECOM)** members — company executives who may have different pay structures, schedules, or processing rules compared to regular employees.

**Who uses it:** Payroll administrators handling executive pay

**What you can see:**
- List of employees marked as EXECOM members
- Their compensation and pay components
- Payroll periods specific to EXECOM

**What you can do:**
- **Manage EXECOM members** — designate which employees are EXECOM
- **Process EXECOM payroll** — run payroll specifically for this group
- **Finalize EXECOM payslips** — approve and lock executive payroll

---

# REPORTS MODULE

## 29. Reports

**Where to find it:** Side Menu → Reports

**Purpose:** Generate and view reports about your workforce data — attendance, payroll, employee lists, and other HR analytics.

**Who uses it:** HR staff, managers, company heads

**What you can see:**
- List of available report types
- Filters and date range selectors
- Report preview and download options

**What you can do:**
- **Generate reports** — choose what type of report you need
- **Filter data** — narrow down by date range, company, branch, department, or employee
- **Preview reports** — see the data on screen before exporting
- **Export** — download reports in various formats (Excel, PDF, CSV)
- **Schedule reports** — some reports can be generated and emailed on a regular schedule

**Common Report Types:**
- **Employee List** — full roster with all employee details
- **Attendance Summary** — who was present, late, absent for a given period
- **Payroll Summary** — gross pay, deductions, net pay per employee
- **Leave Report** — leave usage and balances
- **Overtime Report** — overtime hours and costs
- **Government Remittances** — SSS, PhilHealth, Pag-IBIG contribution summaries
- **Headcount Report** — number of employees per company, department, etc.

---

# MY WORKSPACE (ADMIN SELF-SERVICE)

These are modules available to admin users for their own personal use.

## 30. My Schedule

**Where to find it:** Side Menu → My Workspace → My Schedule

**Purpose:** View your own work schedule, including your daily time in/out, rest days, and any upcoming schedule changes.

**What you can see:**
- Your current schedule (time in/time out)
- Rest days
- Pending schedule change requests
- Calendar view of your schedule

**What you can do:**
- View your schedule for any date
- Check your assigned working hours
- See when your rest days fall

---

## 31. QR & Face ID

**Where to find it:** Side Menu → My Workspace → QR & Face ID

**Purpose:** Manage your own attendance methods — view your personal QR code for scanning at kiosks, and register your face for facial recognition attendance.

**What you can see:**
- Your personal QR code
- Face registration status (registered or not)

**What you can do:**
- **View/download your QR code** — use it to clock in/out via kiosk
- **Register your face** — set up face recognition for attendance
- **Re-register your face** — if needed (e.g., new device, updated look)
- **Check your enrollment status**

---

## 32. My Payslips

**Where to find it:** Side Menu → My Workspace → My Payslips

**Purpose:** View and download your own payslips.

**What you can see:**
- List of your payslips by pay period
- Each payslip's breakdown (earnings, deductions, net pay)

**What you can do:**
- **View payslip details** — see how your pay was computed
- **Download PDF** — save a copy of your payslip
- **Compare periods** — check payslips from different months

---

## 33. My Loans & Deductions

**Where to find it:** Side Menu → My Workspace → My Loans & Deductions

**Purpose:** View your own loans and deductions from payroll.

**What you can see:**
- List of your active loans and deductions
- Amount, remaining balance, payment schedule

**What you can do:**
- View loan details
- Check remaining balance
- See deduction history

---

## 34. My Profile

**Where to find it:** Side Menu → My Workspace → My Profile

**Purpose:** View and update your own personal information.

**What you can see:**
- Your personal details (name, contact info, address, etc.)
- Employment information

**What you can do:**
- Update your contact number and email
- Change your address
- Update your profile photo
- View your employment details

---

# ADMINISTRATION MODULES

## 35. Users & Access

**Where to find it:** Side Menu → Administration → Users & Access

**Purpose:** Manage who has access to the system and what they can do. This is the security control center for the entire HRIS.

**Who uses it:** System administrators

**What you can see:**
- List of system users and their accounts
- User roles (Admin, HR, Manager, etc.)
- Permissions assigned to each user/role

**What you can do:**
- **Create user accounts** — set up login credentials for HR staff and managers
- **Assign roles** — give users appropriate access levels
- **Set permissions** — control exactly what each user can see and do (e.g., some can only view, others can edit)
- **Manage API access** — control system-to-system integrations
- **Deactivate users** — revoke access when someone leaves the HR team

**Permission Examples:**
- `employees.view` — can see employee list
- `employees.create` — can add new employees
- `employees.edit` — can edit employee records
- `payroll.view` — can see payroll data
- `payslip.finalize` — can finalize payroll runs

---

## 36. Employee Logs

**Where to find it:** Side Menu → Administration → Employee Logs

**Purpose:** View an audit trail of all changes made to employee records. This is your system's "paper trail" for accountability.

**Who uses it:** HR administrators and auditors

**What you can see:**
- Chronological list of actions performed in the system
- Who performed each action (which user)
- What was changed (before and after values)
- When the change was made (timestamp)
- IP address of the person who made the change

**What you can do:**
- **Search logs** — find specific changes by employee name, date range, or action type
- **Review changes** — see exactly what was modified in an employee record
- **Audit activities** — track who changed what and when

**Why this matters:** If there's a question about who changed an employee's salary or status, you can find the exact record here.

---

## 37. Pay Policies

**Where to find it:** Side Menu → Administration → Pay Policies

**Purpose:** Configure the rules and settings that control how daily payroll computation works — late penalties, undertime handling, overtime rules, and other computation policies.

**Who uses it:** Payroll administrators

**What you can see:**
- Current policy settings
- Rules for various payroll scenarios

**What you can do:**
- **Configure late rules** — how late minutes affect pay
- **Set undertime policies** — how early departure is handled
- **Adjust rounding rules** — how time is rounded (e.g., nearest 15 minutes)
- **Define grace periods** — how many minutes of lateness are tolerated
- **Save and apply policies** — rules take effect for new computations

---

## 38. Approval Rules

**Where to find it:** Side Menu → Administration → Approval Rules

**Purpose:** Define who can approve what. Set up approval workflows so that leave, overtime, corrections, and schedule requests are routed to the right people.

**Who uses it:** HR administrators

**What you can see:**
- Current approval workflow settings
- Who is assigned to approve for which types of requests
- Approval chain hierarchy

**What you can do:**
- **Set up approval chains** — define the order of approvers (e.g., Team Leader → Department Head → HR)
- **Configure per module** — set different approval rules for leave, overtime, corrections, etc.
- **Assign approvers** — designate who can approve requests for different groups of employees
- **Test workflows** — preview how a request would route through the approval chain

**Example:** You can set it so that:
- Leave requests go first to the Department Head, then to HR
- Overtime requests are approved by the Team Leader only
- Schedule changes need Branch Manager approval

---

## 39. Email Alerts

**Where to find it:** Side Menu → Administration → Email Alerts

**Purpose:** Configure automatic email notifications for system events — so the right people are informed when things happen.

**Who uses it:** HR administrators

**What you can see:**
- List of available email notification types
- Current configuration (who gets notified for what)

**What you can do:**
- **Enable/disable notifications** — turn specific alerts on or off
- **Set recipients** — choose who receives each type of notification
- **Configure triggers** — define what events trigger an email

**Common Notification Types:**
- New employee added
- Leave request submitted (notify the approver)
- Overtime request submitted
- Attendance correction filed
- Payroll ready for review
- Employee deactivated/reactivated

---

# Part 2: Employee Dashboard (For All Employees)

The **Employee Dashboard** is what regular employees see when they log in. It gives employees access to their own information and lets them perform self-service tasks without needing to go to HR.

---

## 40. Employee Dashboard (Home)

**Where to find it:** Side Menu → Dashboard

**Purpose:** Your personal home page in the system. Shows a summary of your work day at a glance.

**What you can see:**
- **Today's attendance status** — have you clocked in? Clocked out?
- **Current date and time**
- **Your work schedule** — what time you should be clocking in/out
- **Pending requests** — any leave or overtime requests waiting for approval
- **Quick actions** — buttons to clock in/out, apply for leave, etc.

**What you can do:**
- Clock in or out from here
- See if you're on time or late
- Quickly access your most-used features

---

## 41. My Attendance

**Where to find it:** Side Menu → Workday → My Attendance

**Purpose:** View your own attendance records — clock-in and clock-out times for any day.

**What you can see:**
- Your daily attendance logs (date, time in, time out)
- Monthly attendance summary
- Status for each day (Present, Late, Absent, On Leave)
- Hours worked per day and per period

**What you can do:**
- **View attendance history** — check any date's record
- **See monthly totals** — how many days you were present, late, absent
- **Spot discrepancies** — check if your recorded times are correct
- **Export your DTR** — download your Daily Time Record if needed

---

## 42. My Schedule

**Where to find it:** Side Menu → Workday → My Schedule

**Purpose:** View your assigned work schedule and request changes if needed.

**What you can see:**
- Your current schedule (time in, time out, break times)
- Rest days
- Calendar view of your schedule for the month
- Any pending schedule change requests

**What you can do:**
- **View your schedule** — know exactly when you're supposed to work
- **Check rest days** — see which days are your days off
- **Request a schedule change** — if you need a temporary or permanent change

---

## 43. QR & Face ID (Employee)

**Where to find it:** Side Menu → Workday → QR & Face ID

**Purpose:** Manage how you clock in and out — using your personal QR code or your face.

**What you can see:**
- Your personal QR code
- Face registration status

**What you can do:**
- **View your QR code** — scan this at the attendance kiosk to clock in/out
- **Download your QR code** — save it to your phone or print it
- **Register your face** — set up facial recognition for faster attendance
- **Re-register your face** — if your appearance changed or if re-registration is needed

---

## 44. Holidays

**Where to find it:** Side Menu → Workday → Holidays

**Purpose:** View the list of upcoming and past holidays.

**What you can see:**
- Holiday name
- Date
- Holiday type (Regular, Special Non-Working, Company Special)

**What you can do:**
- Check upcoming holidays to plan your schedule
- See which holidays are company-specific

---

## 45. Leave Requests

**Where to find it:** Side Menu → Requests → Leave Requests

**Purpose:** Apply for time off — vacation, sick leave, emergency leave, or other leave types.

**What you can see:**
- Your current leave balances (how many days you have left)
- List of your submitted leave requests and their status
- Leave history

**What you can do:**

**To File a Leave:**
1. Click **"File Leave"** or **"New Request"**
2. Select the **leave type** (Vacation, Sick, Emergency, etc.)
3. Choose the **dates** (start and end date)
4. Enter a **reason** for the leave
5. Optionally upload a **supporting document** (e.g., medical certificate for sick leave)
6. Click **"Submit"**

**After Submitting:**
- Your request will be sent to your approver (supervisor, department head, or HR)
- You can check the status anytime — Pending, Approved, or Rejected
- If approved, the system automatically deducts the leave days from your balance

---

## 46. Overtime Requests

**Where to find it:** Side Menu → Requests → Overtime Requests

**Purpose:** File a request for overtime work and get it approved.

**What you can see:**
- List of your overtime requests
- Status of each request

**What you can do:**

**To File an Overtime Request:**
1. Click **"Request Overtime"**
2. Select the **date** you worked overtime
3. Enter the **start and end time** of your overtime
4. Provide a **reason** (why the overtime was needed)
5. Click **"Submit"**

**After Submitting:**
- Your supervisor or HR will review and approve/reject
- Approved overtime hours are included in your payroll computation

---

## 47. Attendance Corrections (Employee)

**Where to find it:** Side Menu → Requests → Attendance Corrections

**Purpose:** If your attendance record is wrong (forgot to clock in, incorrect time, etc.), you can file a correction request here.

**What you can see:**
- List of your correction requests
- Status of each request

**What you can do:**

**To File a Correction:**
1. Click **"Request Correction"**
2. Select the **date** that needs correction
3. Describe what the **correct time should be** (e.g., "I actually clocked in at 8:00 AM but the system shows 8:30 AM")
4. Provide a **reason** for the discrepancy
5. Click **"Submit"**

**After Submitting:**
- HR or your supervisor reviews the request
- If approved, your attendance record is updated
- The corrected time is used for payroll computation

---

## 48. My Payslips (Employee)

**Where to find it:** Side Menu → Pay → My Payslips

**Purpose:** View and download your payslips once payroll has been processed.

**What you can see:**
- List of your payslips by pay period
- For each payslip: gross pay, deductions, net pay

**What you can do:**
- **View payslip** — see the full breakdown of your salary
- **Check deductions** — see how much was deducted (SSS, PhilHealth, Pag-IBIG, Tax, loans, etc.)
- **Download PDF** — save a copy of your payslip for your records
- **Compare** — check your payslips across different periods

**Payslip Details Include:**
- **Basic Pay** — your base salary for the period
- **Earnings** — overtime, holiday pay, allowances, bonuses
- **Deductions** — government contributions, tax, loans, absences/lates
- **Net Pay** — your take-home pay (total earnings minus total deductions)

---

## 49. Loans & Deductions (Employee)

**Where to find it:** Side Menu → Pay → Loans & Deductions

**Purpose:** View any loans you have with the company (salary advances, company loans) and the deductions being taken from your pay.

**What you can see:**
- List of your active loans and deductions
- For each loan: original amount, remaining balance, payment amount per period
- Deduction schedule

**What you can do:**
- Check your loan balance
- See how much is deducted per pay period
- View payment history

---

## 50. Reports (Employee)

**Where to find it:** Side Menu → Reports

**Purpose:** Generate your own personal reports — attendance, schedule, and other data related to your employment.

**Who can see this:** Employees with report access permissions

**What you can do:**
- Generate your own attendance report
- View your work schedule report
- Export data for personal record-keeping

---

## 51. My Profile (Employee)

**Where to find it:** Side Menu → Account → My Profile

**Purpose:** View and update your personal information in the system.

**What you can see:**
- Your personal details (name, date of birth, gender, civil status, nationality)
- Contact information (email, phone number, address)
- Employment information (position, department, hire date)
- Emergency contacts
- Government IDs (SSS, PhilHealth, Pag-IBIG, TIN)

**What you can do:**
- **Update contact info** — change your phone number, email, or address
- **Upload/change profile photo**
- **View employment details**
- **Add/update emergency contacts**
- **Update skills and certifications**
- **Upload documents**
- **View government ID numbers**

---

# Quick Reference: Navigation Map

## Admin Panel Menu Structure

```
Dashboard
├── Workforce
│   ├── Employees
│   ├── Recruitment
│   └── Regularization
├── Organization
│   ├── Companies
│   ├── Areas
│   ├── Branches
│   ├── Divisions
│   ├── Departments
│   └── Sections & Units
├── Time & Attendance
│   ├── Attendance
│   ├── Work Schedules
│   ├── Schedule Approvals
│   ├── Attendance Corrections
│   ├── Overtime
│   ├── Leave
│   ├── Holidays
│   └── Geofencing
├── Payroll
│   ├── Pay Cycles  
│   ├── Pay Components
│   ├── Employee Pay Setup
│   ├── Deduction Schedules
│   ├── Government Deductions
│   ├── Loans & Deductions
│   ├── Generate Payslips
│   ├── 13th Month Pay
│   ├── Daily Payroll
│   └── EXECOM Payroll
├── Reports
├── My Workspace
│   ├── My Schedule
│   ├── QR & Face ID
│   ├── My Payslips
│   ├── My Loans & Deductions
│   └── My Profile
└── Administration
    ├── Users & Access
    ├── Employee Logs
    ├── Pay Policies
    ├── Approval Rules
    └── Email Alerts
```

## Employee Dashboard Menu Structure

```
Dashboard
├── Workday
│   ├── My Attendance
│   ├── My Schedule
│   ├── QR & Face ID
│   └── Holidays
├── Requests
│   ├── Leave Requests
│   ├── Overtime Requests
│   └── Attendance Corrections
├── Pay
│   ├── My Payslips
│   └── Loans & Deductions
├── Reports
└── Account
    └── My Profile
```

---

# Roles and Responsibilities

Different users see different parts of the system based on their role:

| Role | What They Can Access |
|------|---------------------|
| **Employee** | Employee Dashboard only — their own attendance, payslips, leave, schedule, profile |
| **Organization Head** (Company/Branch/Department Head, etc.) | Employee Dashboard + limited Admin Panel (only sees their own scope) |
| **HR Staff** | Full Admin Panel (except system administration) |
| **HR Administrator** | Full Admin Panel including Administration modules |
| **Super Admin** | Everything — full access to all modules and settings |

---

# Frequently Asked Questions

**Q: I can't see a module in my menu. Why?**
A: Your account may not have the necessary permission for that module. Contact your HR administrator.

**Q: Can I undo an approved leave or overtime?**
A: Once approved, you would need to contact HR to cancel or modify it.

**Q: Where do I change my password?**
A: You can change your password from your Profile page. If you forgot your password, use the "Forgot Password" link on the login page.

**Q: How do I know if my attendance correction was approved?**
A: Check the Attendance Corrections page — the status will change from "Pending" to "Approved" or "Rejected".

**Q: The system says I'm late but I was on time. What should I do?**
A: File an Attendance Correction request explaining the situation. HR will review and correct it if valid.

**Q: How do I get my QR code?**
A: Go to QR & Face ID in your Employee Dashboard. Your QR code will be displayed there.

---

*This manual covers version 1.0 of the HRIS system. Some features may vary based on your company's configuration.*

*For additional help, contact your system administrator or HR department.*

*Last updated: July 2026*
