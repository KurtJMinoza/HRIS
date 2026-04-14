# HRIS Performance QA Gates

## Target SLA

- Read APIs (paginated lists): p50 <= 1s, p95 <= 2s
- Summary/dashboard APIs: p50 <= 1.5s, p95 <= 3s
- Heavy operations (exports/finalize): async queue-first, HTTP response <= 1s

## Core Scenarios

1. Daily logs small range
- Endpoint: `GET /api/admin/payroll/daily-logs`
- Params: `from_date=today,to_date=today,page=1,per_page=10`
- Expect: 200, valid meta, <1s local

2. Daily logs normal range
- Params: 7-day range, `per_page=10`, pages 1..3
- Expect: no duplicates across pages, stable latency

3. Attendance monitoring bounded range
- Endpoint: `GET /api/admin/attendance`
- Params: 7-day range with and without status filter
- Expect: no timeout, bounded payload, optional `meta` when paginated

4. Detailed report bounded range
- Endpoint: `GET /api/admin/reports/detailed`
- Params: 7-day range, `page=1,per_page=50`
- Expect: valid rows, optional `meta`, no timeout

5. Cross-company data isolation
- Run same requests as different scoped users
- Expect: no leakage of other company data

6. Empty result
- Use future dates with no data
- Expect: fast 200 and empty rows array

7. Concurrency
- Run 10 parallel calls to dashboard + attendance + daily logs
- Expect: no 60s timeout, p95 within target envelope

## N+1 Guard Checks

- Dashboard:
  - `monthlyLateStatistics` should avoid `User::find` in loops
  - `todayAttendanceLogs` should avoid per-row OT query
  - `computeDailyStats` should avoid per-user daily log query
- Reports and Attendance:
  - Date overlap checks should not use `whereDate` on indexed columns

## Log Signals to Verify

- `Payroll dailyLogs request start/completed`
- `Admin daily logs query completed`
- `Attendance monitoring response prepared`
- `Detailed attendance report prepared`
- `Admin dashboard payload prepared`

## Index Validation

After migrations, verify index existence:

- `lr_user_status_overlap_idx`
- `lr_status_overlap_user_idx`
- `al_type_created_user_idx`
- `ps_company_period_range_idx`
- `pbr_company_period_status_idx`
- `ac_date_approved_user_idx`
- `ot_status_date_user_idx`
