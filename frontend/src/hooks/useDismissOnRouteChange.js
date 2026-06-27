import { useEffect, useLayoutEffect, useRef } from 'react'
import { useLocation } from 'react-router-dom'
import { clearBlockingOverlays, scheduleRadixModalLockReset } from '@/lib/radixModalLock'

/** Close page overlays when switching HR modules so Radix modal lock cannot trap navigation. */
export function useDismissOnRouteChange(onDismiss) {
  const { pathname } = useLocation()
  const dismissRef = useRef(onDismiss)
  dismissRef.current = onDismiss
  const mountedRef = useRef(false)

  useEffect(() => {
    function onPreNavigate() {
      dismissRef.current?.()
    }
    window.addEventListener('hr:dismiss-overlays', onPreNavigate)
    return () => window.removeEventListener('hr:dismiss-overlays', onPreNavigate)
  }, [])

  useLayoutEffect(() => {
    if (!mountedRef.current) {
      mountedRef.current = true
      clearBlockingOverlays()
      return
    }
    dismissRef.current?.()
    scheduleRadixModalLockReset()
  }, [pathname])
}
