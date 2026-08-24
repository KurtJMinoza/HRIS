/**
 * Shared constants for the Refunds & Adjustments module.
 * Mirrors backend App\Models\RefundRequest constants — keep both in sync.
 */

export const REFUND_REASONS = [
  { value: 'missing_time_in', category: 'attendance', label: 'Missing Time In' },
  { value: 'missing_time_out', category: 'attendance', label: 'Missing Time Out' },
  { value: 'missing_attendance', category: 'attendance', label: 'Missing Attendance' },
  { value: 'incorrect_late_deduction', category: 'attendance', label: 'Incorrect Late Deduction' },
  { value: 'incorrect_undertime_deduction', category: 'attendance', label: 'Incorrect Undertime Deduction' },
  { value: 'missing_overtime', category: 'overtime', label: 'Missing Overtime' },
  { value: 'incorrect_overtime_pay', category: 'overtime', label: 'Incorrect Overtime Pay' },
  { value: 'missing_holiday_pay', category: 'holiday', label: 'Missing Holiday Pay' },
  { value: 'incorrect_holiday_pay', category: 'holiday', label: 'Incorrect Holiday Pay' },
  { value: 'missing_rest_day_pay', category: 'attendance', label: 'Missing Rest-Day Pay' },
  { value: 'incorrect_rest_day_premium', category: 'attendance', label: 'Incorrect Rest-Day Premium' },
  { value: 'missing_night_differential', category: 'attendance', label: 'Missing Night Differential' },
  { value: 'incorrect_leave_pay', category: 'leave', label: 'Incorrect Leave Pay' },
  { value: 'incorrect_leave_deduction', category: 'leave', label: 'Incorrect Leave Deduction' },
  { value: 'schedule_error', category: 'schedule', label: 'Schedule Error' },
  { value: 'payroll_computation_error', category: 'payroll_computation', label: 'Payroll Computation Error' },
  { value: 'other', category: 'other', label: 'Other' },
]

/** Reasons that reference an existing approved leave request. */
export const LEAVE_REASONS = new Set(['incorrect_leave_pay', 'incorrect_leave_deduction'])

/** Normalize keyed or list-shaped component maps from the API. */
export function refundComponentRows(components) {
  if (!components) return []
  if (Array.isArray(components)) return components
  return Object.entries(components).map(([key, row]) => ({ key, ...row }))
}

export const REFUND_REASON_LABELS = Object.fromEntries(
  REFUND_REASONS.map((r) => [r.value, r.label]),
)

/** Reasons that need corrected punch inputs. */
export const PUNCH_REASONS = new Set([
  'missing_time_in',
  'missing_time_out',
  'missing_attendance',
  'incorrect_late_deduction',
  'incorrect_undertime_deduction',
])

/** Reasons that reference an existing approved OT request (module never invents OT). */
export const OVERTIME_REASONS = new Set(['missing_overtime', 'incorrect_overtime_pay'])

/** Reasons that allow a manual corrected amount (engine day values shown for reference). */
export const MANUAL_AMOUNT_REASONS = new Set([
  'incorrect_leave_pay',
  'incorrect_leave_deduction',
  'payroll_computation_error',
  'other',
])

export const REFUND_STATUSES = {
  draft: { label: 'Draft', tone: 'secondary' },
  submitted: { label: 'Submitted for Review', tone: 'blue' },
  payroll_review: { label: 'Payroll Review', tone: 'violet' },
  approved: { label: 'Approved', tone: 'green' },
  rejected: { label: 'Rejected', tone: 'red' },
  queued_for_payroll: { label: 'Queued for Payroll', tone: 'amber' },
  processed: { label: 'Processed', tone: 'emerald' },
  cancelled: { label: 'Cancelled', tone: 'gray' },
  voided: { label: 'Voided', tone: 'gray' },
}

export const REFUND_DIRECTIONS = {
  underpayment: { label: 'Refund / Recovery', description: 'Employee was underpaid' },
  overpayment: { label: 'Payroll Recovery', description: 'Employee was overpaid' },
  payroll_adjustment: { label: 'Payroll Adjustment', description: 'Neutral correction' },
}

/** Tab → backend status filter token (see RefundController::statusesForFilter). */
export const REFUND_TAB_STATUSES = {
  requests: 'requests',
  pending_approval: 'pending_approval',
  processed: 'processed',
  history: 'history',
}

export function formatPeso(value) {
  const n = Number(value || 0)
  return `₱${n.toLocaleString('en-PH', { minimumFractionDigits: 2, maximumFractionDigits: 2 })}`
}
