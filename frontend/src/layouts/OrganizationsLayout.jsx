import { useLayoutEffect, useEffect, useRef } from 'react'
import { useLocation } from 'react-router-dom'
import { PanelOutlet } from '@/components/layout/PanelOutlet'
import { dispatchDismissOverlays, scheduleRadixModalLockReset } from '@/lib/radixModalLock'

/** Stays mounted while switching org modules; clears Radix traps before outlet swaps. */
export function OrganizationsLayout() {
  const { pathname } = useLocation()
  const prevPathRef = useRef(pathname)

  useLayoutEffect(() => {
    if (prevPathRef.current === pathname) return
    prevPathRef.current = pathname
    dispatchDismissOverlays()
  }, [pathname])

  useEffect(() => {
    scheduleRadixModalLockReset()
  }, [pathname])

  return <PanelOutlet />
}
