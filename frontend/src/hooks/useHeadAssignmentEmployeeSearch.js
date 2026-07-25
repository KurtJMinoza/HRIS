import { useCallback, useEffect, useRef, useState } from 'react'
import { searchEmployeesForHeadAssignment } from '@/api'
import { employeeDisplayName, normalizeLeaderUserId } from '@/lib/employeeSearch'

const DEBOUNCE_MS = 300
const EMPTY_FILTERS = Object.freeze({})

function mergeSelectedEmployee(results, selected) {
  if (!selected) return results
  const selectedId = normalizeLeaderUserId(selected.id ?? selected.employee_id)
  if (!selectedId) return results
  if (results.some((row) => normalizeLeaderUserId(row.id ?? row.employee_id) === selectedId)) {
    return results
  }
  return [selected, ...results]
}

function filtersKey(filters) {
  try {
    return JSON.stringify(filters ?? {})
  } catch {
    return ''
  }
}

export function useHeadAssignmentEmployeeSearch({
  enabled = true,
  searchFilters = EMPTY_FILTERS,
  selectedEmployee = null,
  debounceMs = DEBOUNCE_MS,
}) {
  const [query, setQuery] = useState('')
  const [results, setResults] = useState([])
  const [loading, setLoading] = useState(false)
  const [error, setError] = useState(null)
  const seqRef = useRef(0)
  const abortRef = useRef(null)
  // ponytail: callers often pass inline {} / fresh selected objects; refs avoid abort-loop on every render
  const filtersRef = useRef(searchFilters)
  const selectedRef = useRef(selectedEmployee)
  filtersRef.current = searchFilters
  selectedRef.current = selectedEmployee

  const selectedId = normalizeLeaderUserId(selectedEmployee?.id ?? selectedEmployee?.employee_id) || ''
  const filterSig = filtersKey(searchFilters)

  const runSearch = useCallback(
    async (searchText, { fresh = false } = {}) => {
      if (!enabled) return

      abortRef.current?.abort()
      const controller = new AbortController()
      abortRef.current = controller

      const seq = ++seqRef.current
      setLoading(true)
      setError(null)

      const selected = selectedRef.current
      try {
        const data = await searchEmployeesForHeadAssignment(
          {
            q: searchText,
            ...filtersRef.current,
          },
          { signal: controller.signal, fresh },
        )
        if (seq !== seqRef.current || controller.signal.aborted) return

        const employees = mergeSelectedEmployee(data.employees || [], selected)
        setResults(employees)
      } catch (err) {
        if (controller.signal.aborted || err?.name === 'AbortError') return
        if (seq !== seqRef.current) return
        setResults(selected ? mergeSelectedEmployee([], selected) : [])
        setError(err?.message || 'Could not search employees.')
      } finally {
        if (seq === seqRef.current) {
          setLoading(false)
        }
      }
    },
    [enabled],
  )

  useEffect(() => {
    if (!enabled) {
      setResults([])
      setLoading(false)
      setError(null)
      return undefined
    }

    const timer = window.setTimeout(() => {
      void runSearch(query)
    }, debounceMs)

    return () => {
      window.clearTimeout(timer)
    }
  }, [enabled, query, runSearch, debounceMs, filterSig, selectedId])

  useEffect(() => {
    return () => {
      abortRef.current?.abort()
    }
  }, [])

  const refresh = useCallback(() => runSearch(query, { fresh: true }), [query, runSearch])

  const reset = useCallback(() => {
    seqRef.current += 1
    abortRef.current?.abort()
    setQuery('')
    setResults([])
    setLoading(false)
    setError(null)
  }, [])

  return {
    query,
    setQuery,
    results,
    loading,
    error,
    refresh,
    reset,
    displayName: (employee) => employeeDisplayName(employee),
  }
}
