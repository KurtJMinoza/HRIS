export const REGULAR_UNWORKED_OPTIONS = [
  { value: 'dole_default', label: 'DOLE Default: Pay covered employees if qualified' },
  { value: 'selected_employment_types', label: 'Pay Selected Employment Types' },
  { value: 'all_employment_types', label: 'Pay All Employment Types' },
  { value: 'disabled', label: 'Disabled' },
]

export const SPECIAL_UNWORKED_OPTIONS = [
  { value: 'no_work_no_pay', label: 'No Work, No Pay — DOLE Default' },
  { value: 'selected_employment_types', label: 'Pay Selected Employment Types' },
  { value: 'all_employment_types', label: 'Pay All Employment Types' },
  { value: 'paid_leave_only', label: 'Paid Leave Only' },
]

export const UNWORKED_POLICY_LABELS = Object.fromEntries([
  ...REGULAR_UNWORKED_OPTIONS,
  ...SPECIAL_UNWORKED_OPTIONS,
].map((option) => [option.value, option.label]))

export const COVERAGE_BEHAVIOUR_OPTIONS = [
  { value: 'respect_coverage', label: 'Respect Holiday Coverage (DOLE Default)' },
  { value: 'ignore_coverage', label: 'Ignore Holiday Coverage (Payroll Only)' },
]

export const COVERAGE_BEHAVIOUR_LABELS = Object.fromEntries(
  COVERAGE_BEHAVIOUR_OPTIONS.map((option) => [option.value, option.label]),
)

export const WORKED_EMPLOYMENT_TYPE_OPTIONS = [
  { value: 'all_employment_types', label: 'All Employment Types' },
  { value: 'selected_employment_types', label: 'Selected Employment Types' },
]

export const POLICY_MODE_OPTIONS = [
  { value: 'dole_default', label: 'Follow DOLE Default' },
  { value: 'custom', label: 'Custom Company Policy' },
]

export const MINIMUM_CONDITION_OPTIONS = [
  { value: 'previous_working_day_only', label: 'Previous Working Day Only (DOLE Default)' },
  { value: 'previous_and_next', label: 'Previous AND Next Working Day' },
  { value: 'previous_or_next', label: 'Previous OR Next Working Day' },
  { value: 'next_working_day_only', label: 'Next Working Day Only' },
  { value: 'none', label: 'No Attendance Requirement' },
]

export const SUCCESSIVE_QUALIFICATION_OPTIONS = [
  { value: 'previous_working_day', label: 'Previous Working Day' },
  { value: 'previous_and_first_holiday_worked', label: 'Previous AND First Holiday Worked' },
]

export const DEFAULT_ATTENDANCE_RULE = {
  minimum_condition: 'previous_working_day_only',
  qualifying_statuses: ['present', 'late', 'approved_paid_leave'],
  disqualifying_statuses: ['absent', 'leave_without_pay', 'incomplete', 'unpaid_absence'],
  lookup: {
    skip_rest_days: true,
    skip_non_working_days: true,
    skip_holidays: true,
    skip_paid_leave: false,
  },
}

export const DEFAULT_HOLIDAY_POLICY = {
  pay_unworked_regular: true,
  pay_unworked_special: false,
  eligibility: {
    pay_unworked_regular: true,
    special_no_work_no_pay: true,
    company_may_pay_unworked_special: false,
    paid_leave_qualifies: true,
    require_previous_workday: true,
    rest_day_uses_previous_workday: true,
  },
  regular_unworked: {
    unworked_pay_policy: 'dole_default',
    policy_mode: 'dole_default',
    eligible_employment_types: [],
    always_pay: false,
    successive_holiday_rule: true,
    successive_qualification: 'previous_working_day',
    coverage_behaviour: 'respect_coverage',
    attendance_rule: { ...DEFAULT_ATTENDANCE_RULE },
  },
  regular_worked: {
    coverage_behaviour: 'respect_coverage',
    employment_type_rule: 'all_employment_types',
    eligible_employment_types: [],
  },
  special_unworked: {
    unworked_pay_policy: 'no_work_no_pay',
    policy_mode: 'dole_default',
    eligible_employment_types: [],
    coverage_behaviour: 'respect_coverage',
    attendance_rule: { ...DEFAULT_ATTENDANCE_RULE },
  },
  special_worked: {
    coverage_behaviour: 'respect_coverage',
    employment_type_rule: 'all_employment_types',
    eligible_employment_types: [],
  },
  attendance: {
    paid_leave_qualifies: true,
    require_previous_workday_presence: true,
    skip_rest_days: true,
    skip_company_non_working_days: true,
  },
  non_statutory: {
    special_working: { pay_as_ordinary_day: true },
    company: { pay_as_ordinary_day: true },
  },
}

export const NON_STATUTORY_HOLIDAY_TYPES = [
  {
    key: 'special_working',
    label: 'Special Working Day',
    hint: 'Worked time is paid as an ordinary day; approved paid leave remains leave pay.',
  },
  {
    key: 'company',
    label: 'Company Event',
    hint: 'Internal observance governed by company policy.',
  },
]

function normalizeAttendanceRule(raw) {
  const rule = raw && typeof raw === 'object' ? raw : {}
  return {
    minimum_condition: rule.minimum_condition || DEFAULT_ATTENDANCE_RULE.minimum_condition,
    qualifying_statuses: [...(rule.qualifying_statuses?.length ? rule.qualifying_statuses : DEFAULT_ATTENDANCE_RULE.qualifying_statuses)],
    disqualifying_statuses: [...(rule.disqualifying_statuses?.length ? rule.disqualifying_statuses : DEFAULT_ATTENDANCE_RULE.disqualifying_statuses)],
    lookup: {
      ...DEFAULT_ATTENDANCE_RULE.lookup,
      ...(rule.lookup || {}),
    },
  }
}

function normalizeUnworkedBlock(defaults, incoming) {
  const block = { ...defaults, ...(incoming || {}) }
  return {
    ...block,
    policy_mode: block.policy_mode === 'custom' ? 'custom' : 'dole_default',
    eligible_employment_types: [...(block.eligible_employment_types || [])],
    attendance_rule: normalizeAttendanceRule(block.attendance_rule),
    successive_qualification: block.successive_qualification || 'previous_working_day',
  }
}

export function normalizeHolidayPayPolicy(value) {
  const policy = value && typeof value === 'object' ? value : {}
  const attendance = { ...DEFAULT_HOLIDAY_POLICY.attendance, ...(policy.attendance || {}) }
  const eligibility = { ...DEFAULT_HOLIDAY_POLICY.eligibility, ...(policy.eligibility || {}) }
  const regularUnworked = normalizeUnworkedBlock(DEFAULT_HOLIDAY_POLICY.regular_unworked, policy.regular_unworked)
  const specialUnworked = normalizeUnworkedBlock(DEFAULT_HOLIDAY_POLICY.special_unworked, policy.special_unworked)
  const regularWorked = {
    ...DEFAULT_HOLIDAY_POLICY.regular_worked,
    ...(policy.regular_worked || {}),
    eligible_employment_types: [...(policy.regular_worked?.eligible_employment_types || [])],
  }
  const specialWorked = {
    ...DEFAULT_HOLIDAY_POLICY.special_worked,
    ...(policy.special_worked || {}),
    eligible_employment_types: [...(policy.special_worked?.eligible_employment_types || [])],
  }

  if (regularUnworked.unworked_pay_policy === 'covered_employees') regularUnworked.unworked_pay_policy = 'dole_default'
  if (regularUnworked.unworked_pay_policy === 'all_employees') regularUnworked.unworked_pay_policy = 'all_employment_types'
  if (specialUnworked.unworked_pay_policy === 'all_employees') specialUnworked.unworked_pay_policy = 'all_employment_types'
  if (specialUnworked.unworked_pay_policy === 'paid_leave') specialUnworked.unworked_pay_policy = 'paid_leave_only'
  if (!policy.special_unworked && (policy.pay_unworked_special || policy.eligibility?.company_may_pay_unworked_special)) {
    specialUnworked.unworked_pay_policy = 'all_employment_types'
  }

  if (attendance.require_previous_workday_presence === false && regularUnworked.policy_mode !== 'custom') {
    regularUnworked.attendance_rule.minimum_condition = 'none'
  }

  const paysUnworkedSpecial = specialUnworked.unworked_pay_policy !== 'no_work_no_pay'

  return {
    pay_unworked_regular: regularUnworked.unworked_pay_policy !== 'disabled',
    pay_unworked_special: paysUnworkedSpecial,
    eligibility: {
      ...eligibility,
      pay_unworked_regular: regularUnworked.unworked_pay_policy !== 'disabled',
      company_may_pay_unworked_special: paysUnworkedSpecial,
      special_no_work_no_pay: !paysUnworkedSpecial,
    },
    regular_unworked: regularUnworked,
    regular_worked: regularWorked,
    special_unworked: specialUnworked,
    special_worked: specialWorked,
    non_statutory: {
      special_working: { pay_as_ordinary_day: true },
      company: {
        ...DEFAULT_HOLIDAY_POLICY.non_statutory.company,
        ...(policy.non_statutory?.company || {}),
      },
    },
    attendance: {
      ...attendance,
      paid_leave_qualifies: true,
      skip_rest_days: true,
      skip_company_non_working_days: true,
      require_previous_workday_presence:
        regularUnworked.policy_mode === 'custom'
          ? regularUnworked.attendance_rule.minimum_condition !== 'none'
          : attendance.require_previous_workday_presence !== false,
    },
  }
}

export function serializeHolidayPayPolicyForSave(policy) {
  const normalized = normalizeHolidayPayPolicy(policy)

  const serializeBlock = (block) => {
    const payload = {
      unworked_pay_policy: block.unworked_pay_policy,
      eligible_employment_types: block.eligible_employment_types,
      coverage_behaviour: block.coverage_behaviour,
      policy_mode: block.policy_mode,
    }
    if (block.policy_mode === 'custom') {
      payload.attendance_rule = block.attendance_rule
    }
    return payload
  }

  return {
    pay_unworked_regular: normalized.pay_unworked_regular,
    pay_unworked_special: normalized.pay_unworked_special,
    eligibility: normalized.eligibility,
    regular_unworked: {
      ...serializeBlock(normalized.regular_unworked),
      always_pay: normalized.regular_unworked.always_pay,
      successive_holiday_rule: normalized.regular_unworked.successive_holiday_rule,
      successive_qualification: normalized.regular_unworked.successive_qualification,
    },
    regular_worked: {
      coverage_behaviour: normalized.regular_worked.coverage_behaviour,
      employment_type_rule: normalized.regular_worked.employment_type_rule,
      eligible_employment_types: normalized.regular_worked.eligible_employment_types,
    },
    special_unworked: serializeBlock(normalized.special_unworked),
    special_worked: {
      coverage_behaviour: normalized.special_worked.coverage_behaviour,
      employment_type_rule: normalized.special_worked.employment_type_rule,
      eligible_employment_types: normalized.special_worked.eligible_employment_types,
    },
    non_statutory: normalized.non_statutory,
    attendance: {
      require_previous_workday_presence: normalized.attendance.require_previous_workday_presence,
      paid_leave_qualifies: true,
      skip_rest_days: true,
      skip_company_non_working_days: true,
    },
  }
}

export function specialUnworkedUsesCustomRules(unworkedPayPolicy) {
  return !['no_work_no_pay', 'paid_leave_only'].includes(unworkedPayPolicy)
}
