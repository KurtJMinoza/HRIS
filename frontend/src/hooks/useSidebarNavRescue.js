import { useEffect } from 'react'
import { useLocation, useNavigate } from 'react-router-dom'
import { clearBlockingOverlays, dispatchDismissOverlays, forceClearBlockingOverlays } from '@/lib/radixModalLock'

/**
 * Clicks on the sidebar can land on a stale full-screen Radix overlay (fixed inset-0 on body)
 * instead of the nav link. If the pointer is inside the sidebar column, clear blockers and navigate.
 */
export function useSidebarNavRescue(sidebarSelector = '[data-hr-sidebar]') {
  const navigate = useNavigate()
  const { pathname } = useLocation()

  useEffect(() => {
    function onPointerDown(e) {
      const sidebar = document.querySelector(sidebarSelector)
      if (!sidebar) return

      const rect = sidebar.getBoundingClientRect()
      const { clientX: x, clientY: y } = e
      if (x < rect.left || x > rect.right || y < rect.top || y > rect.bottom) return

      forceClearBlockingOverlays()
      dispatchDismissOverlays()

      const navEl = document.elementFromPoint(x, y)?.closest('[data-hr-sidebar-nav]')
      if (!navEl) return

      const to = navEl.getAttribute('data-hr-sidebar-href')
      if (!to) return

      e.preventDefault()
      e.stopPropagation()

      const currentPath = pathname.replace(/\/$/, '') || '/'
      const targetPath = to.replace(/\/$/, '') || '/'
      if (currentPath === targetPath || currentPath.startsWith(`${targetPath}/`)) return

      navigate(to)
    }

    document.addEventListener('pointerdown', onPointerDown, true)
    return () => document.removeEventListener('pointerdown', onPointerDown, true)
  }, [navigate, pathname, sidebarSelector])
}
