/** Philippine regions / NCR — for regional holiday tagging (PSA-style labels). */
export const PH_REGION_OPTIONS = [
  'NCR (National Capital Region)',
  'CAR (Cordillera Administrative Region)',
  'Region I – Ilocos',
  'Region II – Cagayan Valley',
  'Region III – Central Luzon',
  'Region IV-A – CALABARZON',
  'MIMAROPA',
  'Region V – Bicol',
  'Region VI – Western Visayas',
  'Region VII – Central Visayas',
  'Region VIII – Eastern Visayas',
  'Region IX – Zamboanga Peninsula',
  'Region X – Northern Mindanao',
  'Region XI – Davao',
  'Region XII – SOCCSKSARGEN',
  'Region XIII – Caraga',
  'BARMM',
]

/** Maps UI holiday type to API `type` string. */
export const HOLIDAY_TYPE_API = {
  regular: 'regular',
  special: 'special',
  special_working: 'special_working',
  company: 'company',
}

export const HOLIDAY_TYPE_OPTIONS = [
  {
    value: 'regular',
    label: 'Regular Holiday',
    short: 'RH',
    hint: 'Covered employees receive qualified unworked pay; 200% for the first 8h if worked.',
  },
  {
    value: 'special',
    label: 'Special Non-Working Holiday',
    short: 'SNW',
    hint: 'No Work, No Pay by default; 130% if worked. Enable unworked pay in Policy Settings to pay 100% when qualified.',
  },
]

export const SWAP_HOLIDAY_TYPE_OPTIONS = [
  {
    value: 'regular',
    label: 'Regular Holiday',
    short: 'RH',
    hint: '200% daily rate for first 8h — highest statutory premium. Used for proclaimed swap holidays.',
  },
  {
    value: 'special',
    label: 'Special Non-Working Holiday',
    short: 'SNW',
    hint: '130% if worked. Common for government-proclaimed date swaps.',
  },
]

export const COVERAGE_TYPE_OPTIONS = [
  { value: 'company', label: 'Company-wide', desc: 'All employees in selected companies' },
  { value: 'branches', label: 'Selected Branches', desc: 'Employees in specific branches' },
  { value: 'departments', label: 'Selected Departments', desc: 'Employees in specific departments' },
  { value: 'employees', label: 'Selected Employees', desc: 'Specific individual employees' },
]

export const HOLIDAY_STATUS_OPTIONS = [
  { value: 'active', label: 'Active' },
  { value: 'inactive', label: 'Inactive' },
  { value: 'draft', label: 'Draft' },
]

/** Preview multiplier label for impact badge (first 8h, ordinary day worked — reference only). */
export function holidayImpactPreview(type) {
  switch (type) {
    case 'regular':
      return { label: 'Regular Holiday → 100% qualified unworked pay · 200% if worked', tone: 'teal' }
    case 'special':
      return { label: 'Special Non-Working → No Work, No Pay by default · 130% if worked (enable unworked pay in Policy Settings)', tone: 'amber' }
    case 'special_working':
      return { label: 'Special Working Day → ordinary rates (no statutory holiday premium)', tone: 'slate' }
    case 'company':
      return { label: 'Company event → policy-based (no default statutory premium)', tone: 'violet' }
    default:
      return { label: 'Select a type', tone: 'muted' }
  }
}
