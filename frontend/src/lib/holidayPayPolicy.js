export const DEFAULT_HOLIDAY_POLICY = {
  pay_unworked_regular: true,
  pay_unworked_special: false,
  unworked_special_multiplier: 1,
  attendance: {
    require_previous_workday_presence: true,
    paid_leave_qualifies: true,
    skip_rest_days: true,
    skip_company_non_working_days: true,
    unpaid_absence_disqualifies: true,
  },
  successive_regular_holidays: true,
  coverage: {
    rank_and_file: true,
    probationary: true,
    regular: true,
    managerial: false,
    consultants: false,
    contractual: false,
    fixed_term: false,
    government: false,
    field_personnel: false,
    micro_retail_service: false,
  },
}

export function normalizeHolidayPayPolicy(value) {
  const policy = value && typeof value === 'object' ? value : {}
  return {
    ...DEFAULT_HOLIDAY_POLICY,
    ...policy,
    pay_unworked_regular: true,
    successive_regular_holidays: true,
    unworked_special_multiplier: Math.max(1, Number(policy.unworked_special_multiplier) || 1),
    attendance: {
      ...DEFAULT_HOLIDAY_POLICY.attendance,
      ...(policy.attendance || {}),
      paid_leave_qualifies: true,
      skip_rest_days: true,
      skip_company_non_working_days: true,
    },
    coverage: {
      ...DEFAULT_HOLIDAY_POLICY.coverage,
      ...(policy.coverage || {}),
      rank_and_file: true,
      probationary: true,
      regular: true,
      government: false,
      field_personnel: false,
      micro_retail_service: false,
    },
  }
}
