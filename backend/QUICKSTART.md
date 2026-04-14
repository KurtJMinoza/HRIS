# Employee Status Automation - Quick Start Guide

## 🚀 Quick Setup (5 minutes)

### 1. Run Migration
```bash
cd backend
php artisan migrate
```

### 2. (Optional) Backfill Existing Employees
```bash
php artisan db:seed --class=BackfillEmploymentStatusSeeder
```

### 3. Test the Automation
```bash
# Manual trigger
php artisan employee:evaluate-status

# Run tests
php artisan test --filter=EmployeeStatus
php artisan test --filter=Regularization
```

### 4. Start Scheduler (Development)
```bash
php artisan schedule:work
```

---

## 📋 Common Tasks

### Submit a Regularization Recommendation (as Department Head)

```bash
POST /api/regularization/recommend
Authorization: Bearer {token}

{
  "user_id": 123,
  "notes": "Employee has shown excellent performance."
}
```

### Approve Recommendation (as HR)

```bash
POST /admin/regularization/recommendations/{id}/approve
Authorization: Bearer {token}

{
  "notes": "Approved based on performance review."
}
```

### View Employee Status & History

```bash
GET /admin/employee-status/{userId}
Authorization: Bearer {token}
```

### Manually Change Status (HR Override)

```bash
PATCH /admin/employee-status/{userId}
Authorization: Bearer {token}

{
  "employment_status": "regular",
  "effective_date": "2024-07-15",
  "remarks": "Early regularization approved by management."
}
```

---

## 🧪 Testing Scenarios

### Test 6-Month Automatic Regularization

```php
$employee = User::factory()->create([
    'role' => User::ROLE_EMPLOYEE,
    'employment_status' => 'probationary',
    'hire_date' => Carbon::now()->subMonths(6),
    'is_active' => true,
]);

ProcessEmployeeStatusTransitionsJob::dispatchSync();

$employee->refresh();
// Should be 'regular' now
```

### Test 3-Month Early Regularization

```php
$employee = User::factory()->create([
    'employment_status' => 'probationary',
    'hire_date' => Carbon::now()->subMonths(3),
    'is_active' => true,
]);

// Create approved recommendation
RegularizationRecommendation::create([
    'user_id' => $employee->id,
    'recommended_by' => $head->id,
    'status' => 'approved',
    'hr_reviewed_by' => $hr->id,
    'hr_reviewed_at' => now(),
    'recommended_at' => now(),
]);

ProcessEmployeeStatusTransitionsJob::dispatchSync();
// Employee should be regularized
```

---

## 🔍 Debugging

### Check Logs
```bash
tail -f storage/logs/laravel.log | grep "status transition"
```

### Verify Scheduler
```bash
php artisan schedule:list
```

### Check Employee Eligibility
```php
use App\Services\EmployeeStatusService;

$service = app(EmployeeStatusService::class);
$employee = User::find(123);

// Check 6-month eligibility
$eligible = $service->isEligibleForSixMonthRegularization($employee);

// Check 3-month eligibility
$eligible = $service->isEligibleForThreeMonthRegularization($employee);

// Get milestone dates
$milestones = $service->getMilestoneDates($employee);
```

---

## 📊 Database Queries

### Find Probationary Employees Approaching 6 Months
```sql
SELECT id, name, hire_date, 
       DATEDIFF(DATE_ADD(hire_date, INTERVAL 6 MONTH), CURDATE()) as days_to_regularization
FROM users
WHERE employment_status = 'probationary'
  AND is_active = 1
  AND hire_date IS NOT NULL
  AND DATEDIFF(DATE_ADD(hire_date, INTERVAL 6 MONTH), CURDATE()) <= 7
ORDER BY days_to_regularization;
```

### View Status History for Employee
```sql
SELECT h.*, u.name as actor_name
FROM employee_status_histories h
LEFT JOIN users u ON h.actor_id = u.id
WHERE h.user_id = 123
ORDER BY h.effective_date DESC, h.created_at DESC;
```

### Pending Recommendations
```sql
SELECT r.*, 
       e.name as employee_name,
       h.name as head_name
FROM regularization_recommendations r
JOIN users e ON r.user_id = e.id
JOIN users h ON r.recommended_by = h.id
WHERE r.status = 'pending'
ORDER BY r.recommended_at;
```

---

## 🎯 Key Classes

### Services
- `EmployeeStatusService` - Core business logic
- `RegularizationService` - Recommendation workflow

### Jobs
- `ProcessEmployeeStatusTransitionsJob` - Daily automation

### Models
- `EmployeeStatusHistory` - Audit trail
- `RegularizationRecommendation` - Workflow tracking

### Enums
- `EmploymentStatus` - Status values

---

## 🔐 Permissions

Uses existing RBAC:
- `employees.view` - View status and recommendations
- `employees.edit` - Approve/reject, change status

Department/Branch/Company Heads can submit recommendations for their employees (checked via `HrApprovalChainResolver`).

---

## ⚙️ Configuration

### Change Scheduler Time
Edit `routes/console.php`:
```php
Schedule::call(function () {
    ProcessEmployeeStatusTransitionsJob::dispatchSync();
})->dailyAt('02:00'); // Change to 2:00 AM
```

### Change Milestone Alert Days
```php
$statusService->isApproachingMilestone($employee, 14); // 14 days instead of 7
```

---

## 📚 Documentation

- **Full Documentation**: `docs/EMPLOYEE_STATUS_AUTOMATION.md`
- **Implementation Summary**: `IMPLEMENTATION_SUMMARY.md`
- **Tests**: `tests/Feature/*` and `tests/Unit/*`

---

## 🆘 Common Issues

### Automation Not Running
1. Check scheduler: `php artisan schedule:list`
2. Verify cron job or `schedule:work` is running
3. Check logs for errors

### Employee Not Regularized
1. Verify `hire_date` is set
2. Check `employment_status` is 'probationary'
3. Verify `is_active` = true
4. Check logs for specific employee

### Recommendation Not Showing
1. Verify user is first-level approver (department/branch/company head)
2. Check employee has completed 3 months
3. Ensure no pending recommendation exists

---

## 🎉 Success Indicators

After deployment, you should see:
- ✓ Probationary employees automatically regularized at 6 months
- ✓ Heads can submit recommendations at 3 months
- ✓ HR can approve/reject recommendations
- ✓ Status history tracked for all changes
- ✓ Daily automation runs without errors
- ✓ All tests passing

---

**Need Help?** Check the full documentation in `docs/EMPLOYEE_STATUS_AUTOMATION.md`
