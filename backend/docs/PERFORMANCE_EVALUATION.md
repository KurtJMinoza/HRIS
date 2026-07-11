# Performance Evaluation Module

Assignment-centric performance evaluation for AGCTEK HRIS.

---

# Architecture

## Tables

- `evaluation_forms` — reusable SurveyJS templates
- `evaluation_assignments` — HR-assigned evaluation cycles (employee + template + deadline)
- `evaluations` — per-evaluator response records linked to an assignment

No evaluation cycles. No evaluation requests. One module: **Performance Evaluation**.

## Module Structure

```
Performance Evaluation
├── Dashboard
├── Templates
├── Assignments
└── Results
```

---

# Workflow

```
HR assigns → Evaluators notified → Each evaluator fills form → Assignment completes → Results consolidated
```

1. **Assign** — HR selects employee(s), template, evaluator roles, and deadline
2. **Evaluate** — Each assigned evaluator opens their pending evaluation and submits
3. **Complete** — When all evaluators submit, the assignment status becomes `completed`
4. **Results** — HR views consolidated scores in the Results tab

Legacy ad-hoc evaluations (without `evaluation_assignment_id`) remain supported for backward compatibility.

---

# Evaluator Roles

When assigning, HR selects which roles evaluate each employee. The system resolves users from org hierarchy:

| Role | Resolution |
|------|------------|
| Immediate Supervisor | `users.supervisor_id` |
| Section Head | Section unit head |
| Department Head | `departments.department_head_id` |
| Division Head | `divisions.division_head_id` |
| Area Head | `areas.area_manager_employee_id` |
| Branch Head | `branches.branch_manager_id` |
| Company Head | `companies.company_head_id` |
| HR | Admin HR user in company |
| Self | Employee being evaluated |
| Custom | Explicitly selected employee(s) |

---

# Permissions

| Role | Templates | Assign | Evaluate | Results |
|------|-----------|--------|----------|---------|
| Admin HR | ✅ | ✅ | ✅ | ✅ |
| Org Heads | ❌ | ❌ | ✅ (assigned in scope) | ✅ (scope) |
| **All Employees** | ❌ | ❌ | ✅ (self / assigned) | Own records |

Permissions: `evaluations.templates.manage`, `evaluations.assign`, `evaluations.create`, `evaluations.view`, `evaluations.review`

---

# API Endpoints

| Method | Path | Purpose |
|--------|------|---------|
| GET | `/admin/evaluations/bootstrap` | Initial module load |
| GET/POST | `/admin/evaluations/forms` | Template CRUD |
| GET | `/admin/evaluations/assignments` | List assignments |
| POST | `/admin/evaluations/assignments` | Create assignment(s) |
| GET | `/admin/evaluations/assignments/{id}` | Assignment detail |
| GET | `/admin/evaluations/my-pending` | Evaluator's pending items |
| GET/POST/PATCH | `/admin/evaluations` | Per-evaluator records |

---

# Integration

- **Employees** — profile, supervisor, org assignment snapshot on evaluation
- **Organizations** — bulk assign by company, area, branch, division, department
- **RBAC** — scope via `DataScopeService`
- **Notifications** — `evaluation_assigned` on assignment create
