import { useMemo, useRef } from 'react'

/** Ignore stale fetch results after route/filter changes or unmount. */
export function useLoadGeneration() {
  const genRef = useRef(0)
  return useMemo(
    () => ({
      next: () => ++genRef.current,
      isCurrent: (gen) => genRef.current === gen,
    }),
    [],
  )
}
