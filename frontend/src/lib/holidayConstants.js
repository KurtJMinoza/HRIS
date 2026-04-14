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
    hint: '200% daily rate for first 8h if worked (ordinary day); higher if rest day / OT.',
  },
  {
    value: 'special',
    label: 'Special Non-Working Holiday',
    short: 'SNW',
    hint: '130% if worked; typically no pay if unworked unless policy/CBA.',
  },
  {
    value: 'special_working',
    label: 'Special Working Day',
    short: 'SWD',
    hint: 'Declared “no holiday” — pay as ordinary day unless employer policy says otherwise.',
  },
  {
    value: 'company',
    label: 'Company Event',
    short: 'Co',
    hint: 'Internal observance — follow company policy; no default statutory premium.',
  },
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
      return { label: 'Regular Holiday → 200% if worked (1st 8h)', tone: 'teal' }
    case 'special':
      return { label: 'Special Non-Working → 130% if worked (1st 8h)', tone: 'amber' }
    case 'special_working':
      return { label: 'Special Working Day → ordinary rates (no statutory holiday premium)', tone: 'slate' }
    case 'company':
      return { label: 'Company event → policy-based (no default statutory premium)', tone: 'violet' }
    default:
      return { label: 'Select a type', tone: 'muted' }
  }
}
