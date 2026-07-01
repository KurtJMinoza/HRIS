import { useEffect, useRef } from 'react'
import { useLocation } from 'react-router-dom'
import { useAuth } from '@/contexts/AuthContext'
import { trackEmployeeActivity } from '@/api'
import { resolveActivityFromPath } from '@/lib/employeeActivityRoutes'

/**
 * Records page/module navigation for the signed-in employee (fire-and-forget).
 */
export function useEmployeeActivityTracking(enabled = true) {
  const { user } = useAuth()
  const location = useLocation()
  const lastPathRef = useRef('')
  const referrerRef = useRef('')
  const timerRef = useRef(null)

  useEffect(() => {
    if (!enabled || !user?.id) return undefined

    const fullPath = `${location.pathname}${location.search}`
    if (fullPath === lastPathRef.current) return undefined

    if (timerRef.current) clearTimeout(timerRef.current)

    timerRef.current = setTimeout(() => {
      const activity = resolveActivityFromPath(location.pathname, location.search)
      trackEmployeeActivity({
        event_type: 'page_view',
        path: activity.path,
        module: activity.module,
        title: activity.title,
        referrer_path: referrerRef.current || undefined,
      })
      referrerRef.current = fullPath
      lastPathRef.current = fullPath
    }, 400)

    return () => {
      if (timerRef.current) clearTimeout(timerRef.current)
    }
  }, [enabled, user?.id, location.pathname, location.search])
}
