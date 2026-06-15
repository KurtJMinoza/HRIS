import { useQuery, useQueryClient } from '@tanstack/react-query'
import { getRecruitmentApplicants } from '@/api'
import {
  RECRUITMENT_CACHE_RETAIN_MS,
  RECRUITMENT_STALE_MS,
  TAB_API_NAMES,
  applicantBelongsToTab,
  apiTabToUiTab,
  recruitmentListQueryKey,
} from '@/lib/recruitmentTabCache'

export function fetchRecruitmentTabList(tab, filters, { signal } = {}) {
  const apiTab = TAB_API_NAMES[tab] || tab
  return getRecruitmentApplicants(
    {
      tab: apiTab,
      page: filters.page || 1,
      per_page: filters.per_page || 100,
      q: filters.q || undefined,
      status: filters.status || undefined,
      lite: 1,
    },
    { signal },
  )
}

export function useRecruitmentTabList(tab, filters, { enabled = true } = {}) {
  const queryClient = useQueryClient()
  const key = recruitmentListQueryKey(tab, filters)

  return useQuery({
    queryKey: key,
    queryFn: ({ signal }) => fetchRecruitmentTabList(tab, filters, { signal }),
    enabled,
    staleTime: RECRUITMENT_STALE_MS,
    gcTime: RECRUITMENT_CACHE_RETAIN_MS,
    placeholderData: () => queryClient.getQueryData(key),
    refetchOnWindowFocus: false,
    refetchOnMount: false,
  })
}

export function useRecruitmentListCache() {
  const queryClient = useQueryClient()

  function getTabRows(tab, filters) {
    return queryClient.getQueryData(recruitmentListQueryKey(tab, filters))?.applicants ?? []
  }

  function setTabRows(tab, filters, updater) {
    const key = recruitmentListQueryKey(tab, filters)
    queryClient.setQueryData(key, (current) => {
      const rows = typeof updater === 'function'
        ? updater(current?.applicants ?? [])
        : updater
      return {
        ...(current || {}),
        applicants: rows,
      }
    })
  }

  function invalidateTabs(tabs) {
    const normalized = (tabs || ['applicants']).map((tab) => TAB_API_NAMES[tab] || tab)
    for (const apiTab of normalized) {
      queryClient.invalidateQueries({ queryKey: ['recruitment', apiTab] })
    }
  }

  function patchApplicantAcrossTabs({ listRow, fromTab, affectedTabs, listFiltersForTab }) {
    if (!listRow?.id) return

    const tabsToTouch = new Set([fromTab, ...(affectedTabs || []).map(apiTabToUiTab)])
    for (const tab of tabsToTouch) {
      const filters = listFiltersForTab(tab)
      setTabRows(tab, filters, (rows) => {
        const next = rows.filter((row) => row.id !== listRow.id)
        if (applicantBelongsToTab(listRow, tab)) {
          next.unshift(listRow)
        }
        return next
      })
    }
  }

  function prefetchTab(tab, filters) {
    return queryClient.prefetchQuery({
      queryKey: recruitmentListQueryKey(tab, filters),
      queryFn: ({ signal }) => fetchRecruitmentTabList(tab, filters, { signal }),
      staleTime: RECRUITMENT_STALE_MS,
    })
  }

  return {
    queryClient,
    getTabRows,
    setTabRows,
    invalidateTabs,
    patchApplicantAcrossTabs,
    prefetchTab,
  }
}
