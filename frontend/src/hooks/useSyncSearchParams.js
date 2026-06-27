import { useEffect, useMemo } from 'react'
import { useSearchParams } from 'react-router-dom'

/** Only call setSearchParams when values actually change — avoids fighting React Router navigation. */
export function useSyncSearchParams(nextParams) {
  const [, setSearchParams] = useSearchParams()
  const serialized = useMemo(() => {
    const next = new URLSearchParams()
    Object.entries(nextParams || {}).forEach(([key, value]) => {
      if (value != null && String(value) !== '') next.set(key, String(value))
    })
    return next.toString()
  }, [nextParams])

  useEffect(() => {
    const current = new URLSearchParams(window.location.search).toString()
    if (current === serialized) return
    setSearchParams(new URLSearchParams(serialized), { replace: true })
  }, [serialized, setSearchParams])
}
