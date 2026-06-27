import { useEffect } from 'react'
import { useLoadGeneration } from '@/hooks/useLoadGeneration'

/**
 * Run module fetch when deps change; ignore stale results after route/filter changes.
 * Pass `{ signal, isStale }` to loaders; abort runs on cleanup.
 */
export function useOrgModuleLoad(loadFn, deps) {
  const loadGen = useLoadGeneration()

  useEffect(() => {
    const gen = loadGen.next()
    const ac = new AbortController()
    let cancelled = false

    const isStale = () => cancelled || ac.signal.aborted || !loadGen.isCurrent(gen)

    void Promise.resolve(
      loadFn({
        signal: ac.signal,
        isStale,
      }),
    )

    return () => {
      cancelled = true
      ac.abort()
    }
    // eslint-disable-next-line react-hooks/exhaustive-deps -- caller controls fetch deps
  }, deps)
}
