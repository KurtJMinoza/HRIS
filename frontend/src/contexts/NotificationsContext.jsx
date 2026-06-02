import { createContext, useCallback, useContext, useEffect, useMemo, useRef, useState } from 'react'
import { toast } from 'sonner'
import { useAuth } from '@/contexts/AuthContext'
import {
  dismissNotification,
  getNotificationModuleCounts,
  getNotifications,
  markAllNotificationsRead,
  markNotificationRead,
} from '@/api'
import { getRealtimeEcho } from '@/lib/realtime'

const NotificationsContext = createContext(null)

const EMPTY_COUNTS = {
  dashboard: 0,
  leave: 0,
  overtime: 0,
  attendance_correction: 0,
  payroll: 0,
  payslip: 0,
  attendance: 0,
  reports: 0,
}

function normalizeCounts(counts) {
  return { ...EMPTY_COUNTS, ...(counts || {}) }
}

export function NotificationsProvider({ children }) {
  const { user } = useAuth()
  const [items, setItems] = useState([])
  const [moduleCounts, setModuleCounts] = useState(EMPTY_COUNTS)
  const [unreadCount, setUnreadCount] = useState(0)
  const [loading, setLoading] = useState(false)
  const activeUserIdRef = useRef(null)

  const refresh = useCallback(async () => {
    if (!user?.id) return
    setLoading(true)
    try {
      const [list, counts] = await Promise.all([
        getNotifications({ per_page: 20 }),
        getNotificationModuleCounts(),
      ])
      const normalizedCounts = normalizeCounts(list.module_counts || counts)
      setItems(Array.isArray(list.notifications) ? list.notifications : [])
      setModuleCounts(normalizedCounts)
      setUnreadCount(Number(list.unread_count ?? Object.values(normalizedCounts).reduce((a, b) => a + Number(b || 0), 0)))
    } finally {
      setLoading(false)
    }
  }, [user?.id])

  const refreshCounts = useCallback(async () => {
    if (!user?.id) return
    const counts = normalizeCounts(await getNotificationModuleCounts())
    setModuleCounts(counts)
    setUnreadCount(Object.values(counts).reduce((a, b) => a + Number(b || 0), 0))
  }, [user?.id])

  useEffect(() => {
    if (!user?.id) {
      activeUserIdRef.current = null
      setItems([])
      setModuleCounts(EMPTY_COUNTS)
      setUnreadCount(0)
      return
    }
    if (activeUserIdRef.current !== user.id) {
      activeUserIdRef.current = user.id
      refresh().catch(() => {})
    }
  }, [refresh, user?.id])

  useEffect(() => {
    if (!user?.id) return undefined
    const timer = window.setInterval(() => {
      refreshCounts().catch(() => {})
    }, 30000)
    return () => window.clearInterval(timer)
  }, [refreshCounts, user?.id])

  useEffect(() => {
    if (!user?.id || typeof window === 'undefined') return undefined
    const echo = getRealtimeEcho()
    if (!echo) return undefined

    const channel = echo.private(`user.${user.id}`)
      .listen('.notification.created', (event) => {
        const notification = event?.notification
        if (!notification?.id) return
        setItems((prev) => [notification, ...prev.filter((item) => item.id !== notification.id)].slice(0, 20))
        setModuleCounts(normalizeCounts(event.module_counts))
        setUnreadCount(Number(event.unread_count || 0))
        toast(notification.title || 'Notification', {
          description: notification.message || undefined,
        })
      })
      .listen('.dashboard.counts_updated', () => {
        window.dispatchEvent(new CustomEvent('hr:dashboard-counts-updated'))
      })

    return () => {
      try {
        channel.stopListening('.notification.created')
        channel.stopListening('.dashboard.counts_updated')
        echo.leave(`private-user.${user.id}`)
      } catch {
        // ignore client disconnect issues
      }
    }
  }, [user?.id])

  const markRead = useCallback(async (id) => {
    const result = await markNotificationRead(id)
    setItems((prev) => prev.map((item) => (item.id === id ? { ...item, read_at: result.notification?.read_at || new Date().toISOString() } : item)))
    setModuleCounts(normalizeCounts(result.module_counts))
    setUnreadCount(Number(result.unread_count || 0))
    return result
  }, [])

  const markAllRead = useCallback(async (module) => {
    const result = await markAllNotificationsRead(module)
    setItems((prev) => prev.map((item) => (module && item.module !== module ? item : { ...item, read_at: item.read_at || new Date().toISOString() })))
    setModuleCounts(normalizeCounts(result.module_counts))
    setUnreadCount(Number(result.unread_count || 0))
    return result
  }, [])

  const dismiss = useCallback(async (id) => {
    const result = await dismissNotification(id)
    setItems((prev) => prev.filter((item) => item.id !== id))
    setModuleCounts(normalizeCounts(result.module_counts))
    setUnreadCount(Number(result.unread_count || 0))
    return result
  }, [])

  const value = useMemo(() => ({
    items,
    loading,
    moduleCounts,
    unreadCount,
    refresh,
    refreshCounts,
    markRead,
    markAllRead,
    dismiss,
  }), [dismiss, items, loading, markAllRead, markRead, moduleCounts, refresh, refreshCounts, unreadCount])

  return <NotificationsContext.Provider value={value}>{children}</NotificationsContext.Provider>
}

// eslint-disable-next-line react-refresh/only-export-components
export function useNotifications() {
  const ctx = useContext(NotificationsContext)
  if (!ctx) throw new Error('useNotifications must be used within NotificationsProvider')
  return ctx
}
