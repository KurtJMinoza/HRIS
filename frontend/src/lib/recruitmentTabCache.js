export const RECRUITMENT_STALE_MS = 180_000

export const RECRUITMENT_CACHE_RETAIN_MS = 1_800_000

export const TAB_API_NAMES = {
  applicants: 'applicants',
  initial: 'initial_interview',
  exams: 'exams',
  final: 'final_interview',
  requirements: 'requirements',
  hiring: 'hiring_approval',
  hired: 'hired_applicants',
  rejected: 'rejected',
}

export const STAGE_STATUS_FILTERS = {
  initial: ['New', 'For Initial Interview'],
  exams: ['For Exam', 'Exam Passed'],
  final: ['For Final Interview', 'Final Interview Passed'],
  requirements: ['For Requirements'],
  hiring: ['For Hiring Approval'],
  hired: ['Hired'],
  rejected: ['Rejected'],
}

export function recruitmentListQueryKey(tab, filters) {
  const apiTab = TAB_API_NAMES[tab] || tab
  return [
    'recruitment',
    apiTab,
    filters.page || 1,
    stableFiltersHash({
      q: filters.q,
      status: filters.status,
      per_page: filters.per_page,
    }),
  ]
}

export function stableFiltersHash(filters) {
  return Object.entries(filters)
    .filter(([, value]) => value != null && value !== '')
    .sort(([a], [b]) => a.localeCompare(b))
    .map(([key, value]) => `${key}:${String(value)}`)
    .join('|') || 'base'
}

export function recruitmentTabCacheKey(tab, filters) {
  const apiTab = TAB_API_NAMES[tab] || tab
  return `recruitment:tab:${apiTab}:${stableFiltersHash(filters)}:${filters.page || 1}`
}

export function applicantBelongsToTab(applicant, tab) {
  const allowed = STAGE_STATUS_FILTERS[tab]
  if (!allowed) return true
  return allowed.includes(applicant?.status)
}

export function createEmptyTabCacheEntry() {
  return {
    data: [],
    filters: null,
    page: 1,
    scrollPosition: 0,
    loadedAt: 0,
    isStale: true,
  }
}

export function pruneTabCache(map, retainMs = RECRUITMENT_CACHE_RETAIN_MS) {
  const cutoff = Date.now() - retainMs
  for (const [key, entry] of map.entries()) {
    if ((entry.loadedAt || 0) < cutoff) {
      map.delete(key)
    }
  }
}

export function patchTabCacheEntry(map, key, applicants, loadedAt = Date.now()) {
  const existing = map.get(key) || createEmptyTabCacheEntry()
  map.set(key, {
    ...existing,
    data: applicants,
    loadedAt,
    isStale: false,
  })
}

export function removeApplicantFromTabCache(map, tab, filters, applicantId) {
  const key = recruitmentTabCacheKey(tab, filters)
  const entry = map.get(key)
  if (!entry?.data?.length) return false
  const next = entry.data.filter((row) => row.id !== applicantId)
  if (next.length === entry.data.length) return false
  patchTabCacheEntry(map, key, next, entry.loadedAt)
  return true
}

export function upsertApplicantInTabCache(map, tab, filters, applicant) {
  const key = recruitmentTabCacheKey(tab, filters)
  const entry = map.get(key)
  const rows = entry?.data ? [...entry.data] : []
  const index = rows.findIndex((row) => row.id === applicant.id)
  if (index === -1) {
    rows.unshift(applicant)
  } else {
    rows[index] = { ...rows[index], ...applicant }
  }
  patchTabCacheEntry(map, key, rows, Date.now())
}

export function moveApplicantBetweenTabCaches(map, fromTab, toTab, filters, applicantId, listRow) {
  removeApplicantFromTabCache(map, fromTab, filters, applicantId)
  if (listRow && applicantBelongsToTab(listRow, toTab)) {
    upsertApplicantInTabCache(map, toTab, filters, listRow)
  }
}

export function apiTabToUiTab(apiTab) {
  return ({
    initial_interview: 'initial',
    final_interview: 'final',
    hiring_approval: 'hiring',
    hired_applicants: 'hired',
  })[apiTab] || apiTab
}
