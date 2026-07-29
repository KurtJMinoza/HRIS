import { useCallback, useEffect, useMemo, useRef, useState } from 'react'

/**
 * Shared bulk-approval selection for paginated admin tables.
 *
 * @param {object} options
 * @param {object[]} options.pageRows - Approvable rows visible on the current page
 * @param {number} options.totalMatchingCount - Total approvable rows matching current filters (all pages)
 * @param {object} options.bulkFilters - Filters snapshot sent to backend for all_matching mode
 * @param {string} [options.bulkToken] - Server token for the current filters
 * @param {string} [options.filtersKey] - Changes reset selection when filters change
 */
export function useBulkApprovalSelection({
  pageRows = [],
  totalMatchingCount = 0,
  bulkFilters = {},
  bulkToken = '',
  filtersKey = '',
}) {
  const [selectedIds, setSelectedIds] = useState(() => new Set())
  const [selectAllMatching, setSelectAllMatching] = useState(false)
  const [storedFilters, setStoredFilters] = useState(bulkFilters)
  const [storedBulkToken, setStoredBulkToken] = useState('')

  const pageSelectableRows = useMemo(
    () => (Array.isArray(pageRows) ? pageRows.filter((row) => row?.id != null) : []),
    [pageRows],
  )

  const pageCount = pageSelectableRows.length
  const totalCount = Math.max(0, Number(totalMatchingCount) || 0)

  const pageAllSelected =
    pageCount > 0 &&
    (selectAllMatching || pageSelectableRows.every((row) => selectedIds.has(Number(row.id))))

  // Show after the page checkbox is checked (Attendance-style), when more exist across pages.
  const showPageSelectAllBanner =
    !selectAllMatching && pageAllSelected && totalCount > pageCount

  const effectiveSelectedCount = selectAllMatching ? totalCount : selectedIds.size

  const clearSelection = useCallback(() => {
    setSelectedIds(new Set())
    setSelectAllMatching(false)
    setStoredBulkToken('')
  }, [])

  const selectAllMatchingRecords = useCallback(() => {
    setStoredFilters(bulkFilters)
    setStoredBulkToken(bulkToken)
    setSelectAllMatching(true)
    setSelectedIds(new Set())
  }, [bulkFilters, bulkToken])

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
    // Uncheck clears everything (page or cross-page).
    if (selectAllMatching || pageAllSelected) {
      clearSelection()
      return
    }

    // When more approvable rows exist beyond this page, header select = all matching pages.
    if (totalCount > pageCount && totalCount > 0) {
      setStoredFilters(bulkFilters)
      setStoredBulkToken(bulkToken)
      setSelectAllMatching(true)
      setSelectedIds(new Set())
      return
    }

    setSelectAllMatching(false)
    setSelectedIds((prev) => {
      const next = new Set(prev)
      for (const row of pageSelectableRows) next.add(Number(row.id))
      return next
    })
  }, [
    bulkFilters,
    bulkToken,
    clearSelection,
    pageAllSelected,
    pageCount,
    pageSelectableRows,
    selectAllMatching,
    totalCount,
  ])

  const prevFiltersKey = useRef(filtersKey)
  useEffect(() => {
    if (prevFiltersKey.current !== filtersKey) {
      prevFiltersKey.current = filtersKey
      // Selection is intentionally invalidated when the server-side filter snapshot changes.
      // eslint-disable-next-line react-hooks/set-state-in-effect
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
          bulk_token: storedBulkToken || undefined,
          remarks: trimmedRemarks || undefined,
        }
      }
      return {
        mode: 'selected_ids',
        ids: [...selectedIds],
        remarks: trimmedRemarks || undefined,
      }
    },
    [selectAllMatching, selectedIds, storedBulkToken, storedFilters],
  )

  const isRowSelected = useCallback(
    (row) => {
      const id = Number(row?.id)
      if (!id) return false
      if (selectAllMatching) {
        // Cross-page select-all only covers currently-approvable rows.
        return row?.status === 'pending' && Boolean(row?.actor_can_approve)
      }
      return selectedIds.has(id)
    },
    [selectAllMatching, selectedIds],
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
    isRowSelected,
    headerCheckboxChecked: selectAllMatching || pageAllSelected,
    headerCheckboxIndeterminate:
      !selectAllMatching && selectedIds.size > 0 && !pageAllSelected,
  }
}
