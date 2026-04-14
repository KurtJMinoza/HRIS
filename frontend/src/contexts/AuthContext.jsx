import { createContext, useCallback, useContext, useEffect, useRef, useState } from 'react'
import { getAuthenticatedUser, getStoredUser, logout as apiLogout, setStoredUser } from '@/api'

const AuthContext = createContext(null)
/** Min interval between GET /user calls (reduces backend cache + auth payload work on navigation). */
const AUTH_USER_REFRESH_COOLDOWN_MS = 15 * 60 * 1000
const AUTH_TOKEN_STORAGE_KEY = 'hris_token'
const AUTH_USER_STORAGE_KEY = 'hris_user'

function isSameAuthUser(a, b) {
  if (a === b) return true
  if (!a || !b) return false
  return String(a.id ?? '') === String(b.id ?? '')
    && String(a.updated_at ?? '') === String(b.updated_at ?? '')
    && String(a.role ?? '') === String(b.role ?? '')
    && String(a.hr_role ?? '') === String(b.hr_role ?? '')
}

function shouldClearAuthFromError(err) {
  const msg = String(err?.message || '').toLowerCase()
  return msg.includes('session expired') || msg.includes('sign in again')
}

export function AuthProvider({ children }) {
  const [user, setUserState] = useState(null)
  const [loading, setLoading] = useState(true)
  const userRef = useRef(null)
  const lastFetchAtRef = useRef(0)
  const inFlightRef = useRef(null)

  useEffect(() => {
    userRef.current = user
  }, [user])

  const applyUserState = useCallback((nextUser) => {
    setUserState((prev) => (isSameAuthUser(prev, nextUser) ? prev : nextUser))
  }, [])

  const setUser = useCallback((u) => {
    const next = typeof u === 'function' ? u(userRef.current) : u
    applyUserState(next ?? null)
    setStoredUser(next ?? null)
  }, [applyUserState])

  const loadAuthenticatedUser = useCallback(async ({ force = false } = {}) => {
    const now = Date.now()
    if (!force && now - lastFetchAtRef.current < AUTH_USER_REFRESH_COOLDOWN_MS) {
      return getStoredUser() ?? null
    }
    if (inFlightRef.current) {
      return inFlightRef.current
    }
    const request = getAuthenticatedUser()
      .then((next) => {
        lastFetchAtRef.current = Date.now()
        return next ?? null
      })
      .finally(() => {
        inFlightRef.current = null
      })
    inFlightRef.current = request
    return request
  }, [])

  useEffect(() => {
    const stored = getStoredUser()
    if (stored) applyUserState(stored)
    loadAuthenticatedUser({ force: !stored })
      .then((u) => {
        applyUserState(u ?? null)
      })
      .catch((err) => {
        if (shouldClearAuthFromError(err)) {
          applyUserState(null)
        } else if (stored) {
          // Preserve cached auth snapshot on transient network/timeout failures.
          applyUserState(stored)
        }
      })
      .finally(() => setLoading(false))
  }, [applyUserState, loadAuthenticatedUser])

  /**
   * Cross-tab auth sync: when one tab logs in/out or refreshes the stored user snapshot,
   * mirror that state immediately in other open tabs.
   */
  useEffect(() => {
    function onStorage(event) {
      if (!event) return
      if (event.key !== AUTH_TOKEN_STORAGE_KEY && event.key !== AUTH_USER_STORAGE_KEY) return
      if (event.newValue === event.oldValue) return

      if (event.key === AUTH_TOKEN_STORAGE_KEY && !event.newValue) {
        applyUserState(null)
        setLoading(false)
        return
      }

      if (event.key === AUTH_USER_STORAGE_KEY) {
        if (!event.newValue) {
          applyUserState(null)
          setLoading(false)
          return
        }
        try {
          const nextUser = JSON.parse(event.newValue)
          if (nextUser && typeof nextUser === 'object') {
            applyUserState(nextUser)
            setLoading(false)
            return
          }
        } catch {
          // ignore malformed storage payloads
        }
      }

      setLoading(true)
      loadAuthenticatedUser({ force: true })
        .then((u) => applyUserState(u ?? null))
        .catch((err) => {
          if (shouldClearAuthFromError(err)) {
            applyUserState(null)
          }
        })
        .finally(() => setLoading(false))
    }

    window.addEventListener('storage', onStorage)
    return () => window.removeEventListener('storage', onStorage)
  }, [applyUserState, loadAuthenticatedUser])

  /**
   * After login/register, `api.js` stores the token and dispatches `hr:auth-changed` so we sync
   * React state (permissions, hr_role) before routed pages mount their data hooks.
   */
  useEffect(() => {
    function onAuthChanged(e) {
      const next = e?.detail?.user
      if (next === null) {
        applyUserState(null)
        setLoading(false)
        return
      }
      if (next && typeof next === 'object') {
        applyUserState(next)
        setLoading(false)
        return
      }
      setLoading(true)
      loadAuthenticatedUser()
        .then((u) => applyUserState(u ?? null))
        .catch((err) => {
          if (shouldClearAuthFromError(err)) {
            applyUserState(null)
          }
        })
        .finally(() => setLoading(false))
    }
    window.addEventListener('hr:auth-changed', onAuthChanged)
    return () => window.removeEventListener('hr:auth-changed', onAuthChanged)
  }, [applyUserState, loadAuthenticatedUser])

  const logout = useCallback(async () => {
    setLoading(true)
    try {
      await apiLogout()
    } finally {
      applyUserState(null)
      setLoading(false)
    }
  }, [applyUserState])

  /** Reload /user so permissions and hr_role match the server (e.g. after RBAC matrix changes). */
  const refreshUser = useCallback(async () => {
    try {
      const u = await loadAuthenticatedUser({ force: true })
      applyUserState(u ?? null)
      setStoredUser(u)
      return u
    } catch (err) {
      if (shouldClearAuthFromError(err)) {
        applyUserState(null)
        setStoredUser(null)
      }
      throw err
    }
  }, [applyUserState, loadAuthenticatedUser])

  const value = { user, loading, setUser, logout, refreshUser }
  return <AuthContext.Provider value={value}>{children}</AuthContext.Provider>
}

export function useAuth() {
  const ctx = useContext(AuthContext)
  if (!ctx) throw new Error('useAuth must be used within AuthProvider')
  return ctx
}
