export const EMPLOYMENT_TYPES = [
  ['regular', 'Regular'],
  ['probationary', 'Probationary'],
  ['contractual', 'Contractual'],
  ['fixed_term', 'Fixed-term'],
  ['consultant', 'Consultant'],
  ['execom', 'EXECom'],
  ['part_time', 'Part-time'],
  ['project_based', 'Project-based'],
]

export const REGULAR_UNWORKED_OPTIONS = [
  { value: 'covered_employees', label: 'Covered Employees Only — DOLE Default' },
  { value: 'selected_employment_types', label: 'Selected Employment Types' },
  { value: 'all_employees', label: 'All Employees' },
]

export const SPECIAL_UNWORKED_OPTIONS = [
  { value: 'no_work_no_pay', label: 'No Work, No Pay — DOLE Default' },
  { value: 'selected_employment_types', label: 'Pay Selected Employment Types' },
  { value: 'all_employees', label: 'Pay All Covered Employees' },
]

export const UNWORKED_POLICY_LABELS = Object.fromEntries([
  ...REGULAR_UNWORKED_OPTIONS,
  ...SPECIAL_UNWORKED_OPTIONS,
].map((o) => [o.value, o.label]))

export const DEFAULT_HOLIDAY_POLICY = {
  pay_unworked_regular: true,
  pay_unworked_special: false,
  unworked_special_multiplier: 1,
  eligibility: {
    pay_unworked_regular: true,
    special_no_work_no_pay: true,
    company_may_pay_unworked_special: false,
    paid_leave_qualifies: true,
    require_previous_workday: true,
    rest_day_uses_previous_workday: true,
  },
  regular_unworked: {
    unworked_pay_policy: 'covered_employees',
    eligible_employment_types: [],
  },
  special_unworked: {
    unworked_pay_policy: 'no_work_no_pay',
    eligible_employment_types: [],
  },
  attendance: {
    paid_leave_qualifies: true,
    require_previous_workday_presence: true,
    skip_rest_days: true,
    skip_company_non_working_days: true,
  },
  non_statutory: {
    special_working: {
      pay_as_ordinary_day: true,
    },
    company: {
      pay_as_ordinary_day: true,
    },
  },
}

export const NON_STATUTORY_HOLIDAY_TYPES = [
  {
    key: 'special_working',
    label: 'Special Working Day',
    hint: 'Declared "no holiday" — pay as ordinary day unless employer policy says otherwise.',
  },
  {
    key: 'company',
    label: 'Company Event',
    hint: 'Internal observance — follow company policy; no default statutory premium.',
  },
]

export function isSpecialHolidayType(type) {
  const value = String(type || '').toLowerCase()
  return value.includes('special') && !value.includes('working')
}

export function isRegularHolidayType(type) {
  const value = String(type || '').toLowerCase()
  return value.includes('regular') || value === 'double'
}

export function normalizeHolidayPayPolicy(value) {
  const policy = value && typeof value === 'object' ? value : {}
  const attendance = { ...DEFAULT_HOLIDAY_POLICY.attendance, ...(policy.attendance || {}) }
  const eligibility = { ...DEFAULT_HOLIDAY_POLICY.eligibility, ...(policy.eligibility || {}) }

  const regularUnworked = {
    ...DEFAULT_HOLIDAY_POLICY.regular_unworked,
    ...(policy.regular_unworked || {}),
    eligible_employment_types: [...(policy.regular_unworked?.eligible_employment_types || [])],
  }

  const specialUnworked = {
    ...DEFAULT_HOLIDAY_POLICY.special_unworked,
    ...(policy.special_unworked || {}),
    eligible_employment_types: [...(policy.special_unworked?.eligible_employment_types || [])],
  }
  // ponytail: legacy policies only had pay_unworked_special — infer once when special_unworked was never stored
  if (policy.special_unworked?.unworked_pay_policy == null && policy.special_unworked == null) {
    if (policy.pay_unworked_special || policy.eligibility?.company_may_pay_unworked_special) {
      specialUnworked.unworked_pay_policy = 'all_employees'
    }
  }
  const paysUnworkedSpecial = specialUnworked.unworked_pay_policy !== 'no_work_no_pay'

  return {
    pay_unworked_regular: true,
    pay_unworked_special: paysUnworkedSpecial,
    unworked_special_multiplier: Math.max(1, Number(policy.unworked_special_multiplier) || 1),
    eligibility: {
      ...eligibility,
      pay_unworked_regular: true,
      company_may_pay_unworked_special: paysUnworkedSpecial,
      special_no_work_no_pay: !paysUnworkedSpecial,
    },
    regular_unworked: regularUnworked,
    special_unworked: specialUnworked,
    non_statutory: {
      special_working: {
        ...DEFAULT_HOLIDAY_POLICY.non_statutory.special_working,
        ...(policy.non_statutory?.special_working || {}),
        pay_as_ordinary_day: (policy.non_statutory?.special_working?.pay_as_ordinary_day ?? true) !== false,
      },
      company: {
        ...DEFAULT_HOLIDAY_POLICY.non_statutory.company,
        ...(policy.non_statutory?.company || {}),
        pay_as_ordinary_day: (policy.non_statutory?.company?.pay_as_ordinary_day ?? true) !== false,
      },
    },
    attendance: {
      ...attendance,
      paid_leave_qualifies: true,
      skip_rest_days: true,
      skip_company_non_working_days: true,
    },
  }
}

/** Whitelisted payload for PUT /admin/payroll/policies — avoids legacy keys that fail validation. */
export function serializeHolidayPayPolicyForSave(policy) {
  const normalized = normalizeHolidayPayPolicy(policy)

  return {
    pay_unworked_regular: normalized.pay_unworked_regular,
    pay_unworked_special: normalized.pay_unworked_special,
    unworked_special_multiplier: normalized.unworked_special_multiplier,
    eligibility: normalized.eligibility,
    regular_unworked: {
      unworked_pay_policy: normalized.regular_unworked.unworked_pay_policy,
      eligible_employment_types: [...normalized.regular_unworked.eligible_employment_types],
    },
    special_unworked: {
      unworked_pay_policy: normalized.special_unworked.unworked_pay_policy,
      eligible_employment_types: [...normalized.special_unworked.eligible_employment_types],
    },
    non_statutory: normalized.non_statutory,
    attendance: normalized.attendance,
  }
}

export function holidayTypeLabel(type) {
  if (isRegularHolidayType(type)) return 'Regular Holiday'
  if (isSpecialHolidayType(type)) return 'Special Holiday'
  return 'Holiday'
}

export function employmentTypeLabels(types = []) {
  const map = Object.fromEntries(EMPLOYMENT_TYPES)
  return types.map((t) => map[t] || t).join(', ')
}
