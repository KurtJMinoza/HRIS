import { useCallback, useEffect, useRef, useState } from 'react'
import { searchEmployeesForHeadAssignment } from '@/api'
import { employeeDisplayName, normalizeLeaderUserId } from '@/lib/employeeSearch'

const DEBOUNCE_MS = 300

function mergeSelectedEmployee(results, selected) {
  if (!selected) return results
  const selectedId = normalizeLeaderUserId(selected.id ?? selected.employee_id)
  if (!selectedId) return results
  if (results.some((row) => normalizeLeaderUserId(row.id ?? row.employee_id) === selectedId)) {
    return results
  }
  return [selected, ...results]
}

export function useHeadAssignmentEmployeeSearch({
  enabled = true,
  searchFilters = {},
  selectedEmployee = null,
  debounceMs = DEBOUNCE_MS,
}) {
  const [query, setQuery] = useState('')
  const [results, setResults] = useState([])
  const [loading, setLoading] = useState(false)
  const [error, setError] = useState(null)
  const seqRef = useRef(0)
  const abortRef = useRef(null)

  const runSearch = useCallback(
    async (searchText, { fresh = false } = {}) => {
      if (!enabled) return

      abortRef.current?.abort()
      const controller = new AbortController()
      abortRef.current = controller

      const seq = ++seqRef.current
      setLoading(true)
      setError(null)

      try {
        const data = await searchEmployeesForHeadAssignment(
          {
            q: searchText,
            ...searchFilters,
          },
          { signal: controller.signal, fresh },
        )
        if (seq !== seqRef.current || controller.signal.aborted) return

        const employees = mergeSelectedEmployee(data.employees || [], selectedEmployee)
        setResults(employees)
      } catch (err) {
        if (controller.signal.aborted || err?.name === 'AbortError') return
        if (seq !== seqRef.current) return
        setResults(selectedEmployee ? mergeSelectedEmployee([], selectedEmployee) : [])
        setError(err?.message || 'Could not search employees.')
      } finally {
        if (seq === seqRef.current) {
          setLoading(false)
        }
      }
    },
    [enabled, searchFilters, selectedEmployee],
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
  }, [enabled, query, runSearch, debounceMs])

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
