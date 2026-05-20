import { useCallback, useEffect, useMemo, useRef, useState } from 'react'

/**
 * Shared bulk-approval selection for paginated admin tables.
 *
 * @param {object} options
 * @param {object[]} options.pageRows - Approvable rows visible on the current page
 * @param {number} options.totalMatchingCount - Total approvable rows matching current filters (all pages)
 * @param {object} options.bulkFilters - Filters snapshot sent to backend for all_matching mode
 * @param {string} [options.filtersKey] - Changes reset selection when filters change
 */
export function useBulkApprovalSelection({
  pageRows = [],
  totalMatchingCount = 0,
  bulkFilters = {},
  filtersKey = '',
}) {
  const [selectedIds, setSelectedIds] = useState(() => new Set())
  const [selectAllMatching, setSelectAllMatching] = useState(false)
  const [storedFilters, setStoredFilters] = useState(bulkFilters)

  const pageSelectableRows = useMemo(
    () => (Array.isArray(pageRows) ? pageRows.filter((row) => row?.id != null) : []),
    [pageRows],
  )

  const pageCount = pageSelectableRows.length
  const totalCount = Math.max(0, Number(totalMatchingCount) || 0)

  const pageAllSelected =
    pageCount > 0 && pageSelectableRows.every((row) => selectedIds.has(Number(row.id)))

  const showPageSelectAllBanner =
    pageAllSelected && !selectAllMatching && totalCount > pageCount

  const effectiveSelectedCount = selectAllMatching ? totalCount : selectedIds.size

  const clearSelection = useCallback(() => {
    setSelectedIds(new Set())
    setSelectAllMatching(false)
  }, [])

  const selectAllMatchingRecords = useCallback(() => {
    setStoredFilters(bulkFilters)
    setSelectAllMatching(true)
    setSelectedIds(new Set())
  }, [bulkFilters])

  const toggleRow = useCallback((row) => {
    const id = Number(row?.id)
    if (!id) return
    setSelectAllMatching(false)
    setSelectedIds((prev) => {
      const next = new Set(prev)
      if (next.has(id)) next.delete(id)
      else next.add(id)
      return next
    })
  }, [])

  const togglePageSelectAll = useCallback(() => {
    setSelectAllMatching(false)
    setSelectedIds((prev) => {
      if (pageAllSelected) {
        const next = new Set(prev)
        for (const row of pageSelectableRows) next.delete(Number(row.id))
        return next
      }
      const next = new Set(prev)
      for (const row of pageSelectableRows) next.add(Number(row.id))
      return next
    })
  }, [pageAllSelected, pageSelectableRows])

  const prevFiltersKey = useRef(filtersKey)
  useEffect(() => {
    if (prevFiltersKey.current !== filtersKey) {
      prevFiltersKey.current = filtersKey
      clearSelection()
    }
  }, [filtersKey, clearSelection])

  const buildBulkApprovePayload = useCallback(
    (remarks = '') => {
      const trimmedRemarks = String(remarks || '').trim()
      if (selectAllMatching) {
        return {
          mode: 'all_matching',
          filters: storedFilters,
          remarks: trimmedRemarks || undefined,
        }
      }
      return {
        mode: 'selected_ids',
        ids: [...selectedIds],
        remarks: trimmedRemarks || undefined,
      }
    },
    [selectAllMatching, selectedIds, storedFilters],
  )

  return {
    selectedIds,
    selectAllMatching,
    pageSelectableRows,
    pageCount,
    totalCount,
    pageAllSelected,
    showPageSelectAllBanner,
    effectiveSelectedCount,
    clearSelection,
    selectAllMatchingRecords,
    toggleRow,
    togglePageSelectAll,
    buildBulkApprovePayload,
    isRowSelected: (row) => {
      const id = Number(row?.id)
      if (!id) return false
      if (selectAllMatching) return true
      return selectedIds.has(id)
    },
    headerCheckboxChecked: selectAllMatching || pageAllSelected,
    headerCheckboxIndeterminate:
      !selectAllMatching && selectedIds.size > 0 && !pageAllSelected,
  }
}
