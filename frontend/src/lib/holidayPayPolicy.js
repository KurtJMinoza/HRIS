export const REGULAR_UNWORKED_OPTIONS = [
  { value: 'dole_default', label: 'DOLE Default: All Regular Holidays if qualified' },
  { value: 'selected_regular_holidays', label: 'Selected Regular Holidays Only' },
  { value: 'all_regular_holidays', label: 'All Regular Holidays' },
  { value: 'disabled', label: 'Disabled' },
]

export const SPECIAL_UNWORKED_OPTIONS = [
  { value: 'no_work_no_pay_default', label: 'No Work, No Pay — DOLE Default' },
  { value: 'selected_special_holidays', label: 'Selected Special Holidays Only' },
  { value: 'all_special_holidays', label: 'All Special Holidays' },
  { value: 'disabled', label: 'Disabled' },
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
    holiday_selection_mode: 'dole_default',
    holiday_ids: [],
    employment_type_mode: 'all_employment_types',
    eligible_employment_types: [],
    always_pay: false,
    successive_holiday_rule: true,
    coverage_behaviour: 'respect_coverage',
  },
  regular_worked: {
    coverage_behaviour: 'respect_coverage',
    employment_type_rule: 'all_employment_types',
    eligible_employment_types: [],
  },
  special_unworked: {
    unworked_pay_policy: 'no_work_no_pay',
    holiday_selection_mode: 'no_work_no_pay_default',
    holiday_ids: [],
    employment_type_mode: 'all_employment_types',
    eligible_employment_types: [],
    coverage_behaviour: 'respect_coverage',
  },
  special_worked: {
    coverage_behaviour: 'respect_coverage',
    employment_type_rule: 'all_employment_types',
    eligible_employment_types: [],
  },
  attendance: {
    paid_leave_qualifies: true,
    require_previous_workday_presence: true,
    require_following_workday_presence: false,
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

export function normalizeHolidayPayPolicy(value) {
  const policy = value && typeof value === 'object' ? value : {}
  const attendance = { ...DEFAULT_HOLIDAY_POLICY.attendance, ...(policy.attendance || {}) }
  const eligibility = { ...DEFAULT_HOLIDAY_POLICY.eligibility, ...(policy.eligibility || {}) }
  const regularUnworked = {
    ...DEFAULT_HOLIDAY_POLICY.regular_unworked,
    ...(policy.regular_unworked || {}),
    holiday_ids: [...(policy.regular_unworked?.holiday_ids || [])].map(Number).filter(Number.isInteger),
    eligible_employment_types: [...(policy.regular_unworked?.eligible_employment_types || [])],
  }
  const specialUnworked = {
    ...DEFAULT_HOLIDAY_POLICY.special_unworked,
    ...(policy.special_unworked || {}),
    holiday_ids: [...(policy.special_unworked?.holiday_ids || [])].map(Number).filter(Number.isInteger),
    eligible_employment_types: [...(policy.special_unworked?.eligible_employment_types || [])],
  }
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

  if (!policy.regular_unworked?.holiday_selection_mode) {
    regularUnworked.holiday_selection_mode = regularUnworked.unworked_pay_policy === 'disabled'
      ? 'disabled'
      : 'dole_default'
  }
  if (!policy.special_unworked?.holiday_selection_mode) {
    specialUnworked.holiday_selection_mode = specialUnworked.unworked_pay_policy === 'no_work_no_pay'
      ? 'no_work_no_pay_default'
      : 'all_special_holidays'
  }
  if (!policy.regular_unworked?.employment_type_mode) {
    regularUnworked.employment_type_mode = regularUnworked.unworked_pay_policy === 'selected_employment_types'
      ? 'selected_employment_types'
      : 'all_employment_types'
  }
  if (!policy.special_unworked?.employment_type_mode) {
    specialUnworked.employment_type_mode = specialUnworked.unworked_pay_policy === 'selected_employment_types'
      ? 'selected_employment_types'
      : 'all_employment_types'
  }

  regularUnworked.unworked_pay_policy = regularUnworked.holiday_selection_mode === 'disabled'
    ? 'disabled'
    : regularUnworked.employment_type_mode === 'selected_employment_types'
      ? 'selected_employment_types'
      : 'dole_default'
  specialUnworked.unworked_pay_policy = ['disabled', 'no_work_no_pay_default'].includes(specialUnworked.holiday_selection_mode)
    ? 'no_work_no_pay'
    : specialUnworked.employment_type_mode === 'selected_employment_types'
      ? 'selected_employment_types'
      : 'all_employment_types'

  const paysUnworkedSpecial = !['disabled', 'no_work_no_pay_default'].includes(specialUnworked.holiday_selection_mode)

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
      require_previous_workday_presence: attendance.require_previous_workday_presence !== false,
      require_following_workday_presence: attendance.require_following_workday_presence === true,
      paid_leave_qualifies: true,
      skip_rest_days: true,
      skip_company_non_working_days: true,
    },
  }
}

export function serializeHolidayPayPolicyForSave(policy) {
  const normalized = normalizeHolidayPayPolicy(policy)

  return {
    pay_unworked_regular: normalized.pay_unworked_regular,
    pay_unworked_special: normalized.pay_unworked_special,
    eligibility: normalized.eligibility,
    regular_unworked: {
      unworked_pay_policy: normalized.regular_unworked.unworked_pay_policy,
      holiday_selection_mode: normalized.regular_unworked.holiday_selection_mode,
      holiday_ids: normalized.regular_unworked.holiday_ids,
      employment_type_mode: normalized.regular_unworked.employment_type_mode,
      eligible_employment_types: normalized.regular_unworked.eligible_employment_types,
      always_pay: normalized.regular_unworked.always_pay,
      successive_holiday_rule: normalized.regular_unworked.successive_holiday_rule,
      coverage_behaviour: normalized.regular_unworked.coverage_behaviour,
    },
    regular_worked: {
      coverage_behaviour: normalized.regular_worked.coverage_behaviour,
      employment_type_rule: normalized.regular_worked.employment_type_rule,
      eligible_employment_types: normalized.regular_worked.eligible_employment_types,
    },
    special_unworked: {
      unworked_pay_policy: normalized.special_unworked.unworked_pay_policy,
      holiday_selection_mode: normalized.special_unworked.holiday_selection_mode,
      holiday_ids: normalized.special_unworked.holiday_ids,
      employment_type_mode: normalized.special_unworked.employment_type_mode,
      eligible_employment_types: normalized.special_unworked.eligible_employment_types,
      coverage_behaviour: normalized.special_unworked.coverage_behaviour,
    },
    special_worked: {
      coverage_behaviour: normalized.special_worked.coverage_behaviour,
      employment_type_rule: normalized.special_worked.employment_type_rule,
      eligible_employment_types: normalized.special_worked.eligible_employment_types,
    },
    non_statutory: normalized.non_statutory,
    attendance: {
      require_previous_workday_presence: normalized.attendance.require_previous_workday_presence !== false,
      require_following_workday_presence: normalized.attendance.require_following_workday_presence === true,
      paid_leave_qualifies: true,
      skip_rest_days: true,
      skip_company_non_working_days: true,
    },
  }
}
