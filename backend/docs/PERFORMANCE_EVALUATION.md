# Performance Evaluation Module

Design specification for the **Performance Evaluation** module in AGCTEK HRIS.

Reports, audit logs, document management, and analytics remain owned by their existing HRIS modules. This module does **not** reimplement those capabilities.

---

# Architecture

## Tables

- `evaluation_forms` — customizable evaluation templates with sections and questions
- `evaluations` (aliased as `employee_evaluations`) — individual employee evaluation records

No evaluation cycles. Evaluations are created directly against an employee.

## Module Structure

```
Performance Evaluation
├── Evaluation Forms
├── Evaluate (Employee)
└── Results
```

---

# Workflow

```
Draft → Submit → Review (optional) → Completed
```

1. **Draft** — Evaluator fills out the form
2. **Submitted** — Evaluation is submitted for review
3. **Under Review** — Optional review step (submitted → under_review → completed)
4. **Completed** — Final state

No cycle creation is required. HR can evaluate an employee at any time.

---

# Integration

The **Performance Evaluation** module must integrate with the existing HRIS modules to ensure consistency and avoid duplicate data.

### Employees

- Employee Profile
- Employment Type
- Position
- Employment Status
- Hire Date
- Immediate Supervisor

### Organizations

Reference the existing organizational hierarchy:

- Company
- Area
- Branch
- Division
- Department
- Section / Unit

The evaluation scope automatically follows the employee's current organizational assignment.

### Role & Permissions

Use the existing Role-Based Access Control (RBAC).

Permissions should determine who can:

- Create evaluation forms
- Evaluate employees
- Review submitted evaluations
- View evaluation history

No duplicate permission system should be created.

### Notifications

Integrate with the existing notification module.

Automatically notify:

- Employee
- Evaluator
- Department Head
- Branch Manager
- Company Head
- HR Administrator

When:

- Evaluation Assigned
- Evaluation Submitted
- Evaluation Reviewed
- Evaluation Completed

### Employee Profile Integration

Inside the Employee Profile, add a new tab:

```text
Employee Profile
├── General Information
├── Employment
├── Attendance
├── Leave
├── Payroll
└── Performance Evaluation ← NEW
```

This tab displays:

- Evaluation History
- Current Evaluation
- Overall Score
- Overall Rating
- Previous Ratings

### Dashboard Integration

**Employee Dashboard**

Display a widget showing:

```text
Performance Evaluation
Status: In Progress
Latest Score: 94%
Latest Rating: Excellent
Last Evaluated: March 15, 2026
Evaluator: Department Head
```

**Admin Dashboard**

Add summary cards:

- Employees Evaluated — total evaluations with completed/submitted status
- Pending Evaluations — evaluations in draft status
- Average Score — average of completed evaluation scores
- Top Performers — employees with highest evaluation scores

### HRIS Integration Principles

The Performance Evaluation module must:

- Reuse existing Employees data
- Reuse the Organizations hierarchy
- Reuse existing Role & Permissions
- Reuse the existing Notification system
- Reuse existing UI components (tables, filters, tabs, modals, cards, badges, pagination, search, and dialogs)

Do **not** create duplicate implementations of:

- Employee records
- Organization records
- Permissions
- Reports
- Audit Logs
- Document Management / Attachments
- Analytics modules

Instead, reference the existing HRIS architecture to maintain a single source of truth, ensure consistency, and avoid conflicts with other modules. This keeps the module lightweight, maintainable, and fully aligned with the rest of your AGCTEK HRIS.
