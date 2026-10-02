import { isAdminHrUser } from '@/lib/hrRoutes'

/** Admin approval routes → panel path for the signed-in user. */
const APPROVAL_ROUTE_TARGETS = {
  '/admin/leave': { admin: '/admin/leave', employee: '/employee/requests' },
  '/admin/overtime': { admin: '/admin/overtime', employee: '/employee/overtime' },
  '/admin/corrections': { admin: '/admin/corrections', employee: '/employee/correction-requests' },
  '/admin/attendance-corrections': { admin: '/admin/corrections', employee: '/employee/correction-requests' },
  '/admin/attendance/corrections': { admin: '/admin/corrections', employee: '/employee/correction-requests' },
}

/**
 * Normalize stored notification action_url for the current user (fixes legacy /admin/* links for org heads).
 * @param {string | null | undefined} actionUrl
 * @param {object | null | undefined} user
 * @returns {string | null}
 */
export function resolveNotificationActionPath(actionUrl, user) {
  if (!actionUrl || typeof actionUrl !== 'string') return null

  let pathAndQuery = actionUrl.trim()
  if (!pathAndQuery) return null

  if (/^https?:\/\//i.test(pathAndQuery) || pathAndQuery.startsWith('//')) {
    try {
      const parsed = new URL(pathAndQuery, window.location.origin)
      pathAndQuery = `${parsed.pathname}${parsed.search}${parsed.hash}`
    } catch {
      return actionUrl
    }
  }

  if (!pathAndQuery.startsWith('/')) {
    pathAndQuery = `/${pathAndQuery}`
  }

  const qIndex = pathAndQuery.indexOf('?')
  const hashIndex = pathAndQuery.indexOf('#')
  const pathEnd = qIndex >= 0 ? qIndex : hashIndex >= 0 ? hashIndex : pathAndQuery.length
  const pathOnly = pathAndQuery.slice(0, pathEnd).replace(/\/$/, '') || '/'
  const suffix = pathAndQuery.slice(pathEnd)

  const shell = isAdminHrUser(user) ? 'admin' : 'employee'
  const mapped = APPROVAL_ROUTE_TARGETS[pathOnly]
  if (mapped) {
    return `${mapped[shell]}${suffix}`
  }

  if (pathOnly.startsWith('/admin/') && shell === 'employee') {
    return null
  }

  return `${pathOnly}${suffix}`
}
