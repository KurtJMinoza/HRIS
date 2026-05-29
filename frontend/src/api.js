/**
 * API root. In Vite dev, default to same-origin `/api` so requests go through vite.config.js proxy
 * (avoids CORS). Override with VITE_API_URL when the API is on another host/port.
 */
const API_BASE =
  import.meta.env.VITE_API_URL ||
  (import.meta.env.DEV ? '/api' : 'http://localhost:8000/api')
// Some HR endpoints (preview/finalize payroll, large list loads) can legitimately run longer.
// Keep a conservative default of 60s for all authenticated API requests.
const REQUEST_TIMEOUT_MS = 60000
const GET_RETRY_ATTEMPTS = 2
const GET_RETRY_BASE_DELAY_MS = 350

const TOKEN_KEY = 'hris_token'
const USE_SANCTUM_SESSION = String(import.meta.env.VITE_USE_SANCTUM_SESSION ?? 'true') !== 'false'
let csrfCookieBootPromise = null

function firstValidationMessage(data) {
  if (!data || typeof data !== 'object') return null
  const errs = data.errors
  if (errs && typeof errs === 'object') {
    for (const key of Object.keys(errs)) {
      const v = errs[key]
      if (Array.isArray(v) && v.length) return String(v[0])
    }
  }
  return null
}

/** Base URL of the backend (no /api). Used for storage assets (logos, photos). */
export function apiOrigin() {
  const base = String(API_BASE || '').replace(/\/api\/?$/, '')
  if (base === '' || (base.startsWith('/') && typeof window !== 'undefined')) {
    return typeof window !== 'undefined' && window.location?.origin ? window.location.origin : ''
  }
  return base
}

function sanctumCsrfUrl() {
  return `${apiOrigin()}/sanctum/csrf-cookie`
}

function getCookieValue(name) {
  if (typeof document === 'undefined') return null
  const match = document.cookie
    .split('; ')
    .find((row) => row.startsWith(`${name}=`))
  return match ? match.split('=').slice(1).join('=') : null
}

function getXsrfTokenFromCookie() {
  const raw = getCookieValue('XSRF-TOKEN')
  if (!raw) return null
  try {
    return decodeURIComponent(raw)
  } catch {
    return raw
  }
}

/** Resolve profile image URL from API (handles full URL or relative path from storage). */
export function profileImageUrl(pathOrUrl) {
  if (!pathOrUrl) return undefined
  if (typeof pathOrUrl !== 'string') return undefined
  if (pathOrUrl.startsWith('http://') || pathOrUrl.startsWith('https://')) {
    // Hosted safeguard: backend may emit localhost APP_URL media links; remap to the real API origin.
    try {
      const parsed = new URL(pathOrUrl)
      const host = String(parsed.hostname || '').toLowerCase()
      const isLocalHost =
        host === 'localhost'
        || host === '127.0.0.1'
        || host === '0.0.0.0'
        || host === '::1'
      if (!isLocalHost) return pathOrUrl
      const origin = apiOrigin()
      if (!origin) return pathOrUrl
      return `${origin}${parsed.pathname}${parsed.search || ''}${parsed.hash || ''}`
    } catch {
      return pathOrUrl
    }
  }
  const origin = apiOrigin()
  return pathOrUrl.startsWith('/') ? `${origin}${pathOrUrl}` : `${origin}/${pathOrUrl}`
}

function firstNonEmptyString(values) {
  for (const value of values) {
    if (typeof value === 'string' && value.trim() !== '') return value.trim()
  }
  return undefined
}

/**
 * Best URL for a user/employee avatar. Keep this as the single frontend resolver:
 * employee image aliases first, linked user image aliases second, then UI fallback.
 */
export function userProfileImageSrc(user) {
  if (!user || typeof user !== 'object') return undefined
  const direct = firstNonEmptyString([
    user.profile_picture_url,
    user.profile_image_url,
    user.avatar_url,
    user.photo_url,
    user.profile_photo_url,
    user.image_url,
  ])
  if (direct) return profileImageUrl(direct)

  const raw = firstNonEmptyString([
    user.profile_picture,
    user.profile_image,
    user.avatar,
    user.photo,
    user.profile_photo,
  ])
  if (raw) return profileImageUrl(raw)

  const linkedUser = user.user
  if (linkedUser && typeof linkedUser === 'object') {
    const linkedDirect = firstNonEmptyString([
      linkedUser.profile_picture_url,
      linkedUser.profile_image_url,
      linkedUser.avatar_url,
      linkedUser.photo_url,
      linkedUser.profile_photo_url,
      linkedUser.image_url,
    ])
    if (linkedDirect) return profileImageUrl(linkedDirect)

    const linkedRaw = firstNonEmptyString([
      linkedUser.profile_picture,
      linkedUser.profile_image,
      linkedUser.avatar,
      linkedUser.photo,
      linkedUser.profile_photo,
    ])
    if (linkedRaw) return profileImageUrl(linkedRaw)
  }

  return undefined
}

/**
 * Company (department) logo URL. Always built from API origin + storage path so the logo
 * is fetched from the same host as the API. Pass department object with optional `logo` (storage path).
 * @param {{ logo?: string | null }} department - department from API (has logo path, e.g. "department-logos/xxx.jpg")
 * @returns {string | undefined} full URL or undefined if no logo
 */
export function departmentLogoUrl(department) {
  if (!department) return undefined
  // If backend explicitly sends logo_url (including null), trust it.
  // null means file is missing and UI should use fallback avatar, not /storage path.
  if (Object.prototype.hasOwnProperty.call(department, 'logo_url')) {
    if (typeof department.logo_url !== 'string' || department.logo_url.trim() === '') return undefined
    const logoUrl = department.logo_url.trim()
    if (logoUrl.startsWith('http://') || logoUrl.startsWith('https://')) return logoUrl
    const origin = apiOrigin()
    return logoUrl.startsWith('/') ? `${origin}${logoUrl}` : `${origin}/${logoUrl}`
  }
  if (!department.logo || typeof department.logo !== 'string') return undefined
  const path = department.logo.startsWith('storage/') ? department.logo : `storage/${department.logo}`
  return `${apiOrigin()}/${path}`
}

/** Turn network errors (e.g. backend not running) into a clear message. */
function wrapNetworkError(promise) {
  return promise.catch((err) => {
    if (err?.name === 'AbortError') {
      throw err
    }
    const msg = err?.message ?? ''
    if (/timed out/i.test(msg)) {
      throw new Error(
        'Server request timed out. Check if the backend API is running and reachable.'
      )
    }
    if (msg === 'Failed to fetch' || err?.name === 'TypeError') {
      throw new Error(
        'Cannot connect to the server. Make sure the backend is running (e.g. run "php artisan serve" in the backend folder).'
      )
    }
    throw err
  })
}

function normalizeApiClientError(err, fallbackMessage) {
  const msg = String(err?.message || '').trim()
  if (/timed out/i.test(msg)) return TIMEOUT_ERROR_MSG
  if (msg === 'Failed to fetch' || err?.name === 'TypeError') {
    return 'Cannot connect to the server. Make sure the backend is running (e.g. run "php artisan serve" in the backend folder).'
  }
  return msg || fallbackMessage
}

function isTransientNetworkError(err) {
  const msg = String(err?.message || '').toLowerCase()
  return (
    msg.includes('timed out')
    || msg.includes('failed to fetch')
    || msg.includes('cannot connect to the server')
    || err?.name === 'TypeError'
  )
}

function delayMs(ms) {
  return new Promise((resolve) => setTimeout(resolve, ms))
}

/** First validation or message string from Laravel JSON error bodies. */
function laravelValidationMessage(data, fallback) {
  if (!data || typeof data !== 'object') return fallback
  if (typeof data.message === 'string' && data.message.trim() !== '') return data.message
  const errs = data.errors
  if (errs && typeof errs === 'object') {
    const flat = Object.values(errs)
      .flat()
      .filter((v) => v != null && String(v).trim() !== '')
    if (flat.length) return String(flat[0])
  }
  return fallback
}

function normalizePerPage(perPage, fallback = undefined, max = 100) {
  if (perPage == null || perPage === '') return fallback
  if (typeof perPage === 'string' && perPage.trim().toLowerCase() === 'all') return max
  const n = Number(perPage)
  if (!Number.isFinite(n) || n <= 0) return fallback
  return Math.min(max, Math.trunc(n))
}

const TIMEOUT_ERROR_MSG =
  'Server request timed out. Check if the backend API is running and reachable.'

/** Default page size for Admin / Employee detailed reports (server-validated: 25, 50, 100). */
export const REPORTS_AND_ATTENDANCE_PAGE_SIZE = 50

/** @deprecated alias */
export const REPORTS_PAGE_SIZE = REPORTS_AND_ATTENDANCE_PAGE_SIZE

/** Allowed page sizes for Admin / Employee attendance tables (server-validated). */
export const ATTENDANCE_PAGE_SIZE_OPTIONS = [25, 50, 100]

/** Default attendance table page size. */
export const ADMIN_ATTENDANCE_PAGE_SIZE = 50

/** @deprecated Use ADMIN_ATTENDANCE_PAGE_SIZE */
export const ADMIN_ATTENDANCE_DEFAULT_PAGE_SIZE = ADMIN_ATTENDANCE_PAGE_SIZE

/** Matches backend AttendanceController legacy full-range cap when per_page is omitted. */
export const EMPLOYEE_ATTENDANCE_SUMMARY_MAX_PER_PAGE = 124

/** Employee → Attendance: default server page size. */
export const EMPLOYEE_ATTENDANCE_PAGE_SIZE = 50

/**
 * Normalize attendance list page size to 25, 50, or 100.
 * @param {unknown} perPage
 * @param {number} [fallback=50]
 */
export function normalizeAttendancePerPage(perPage, fallback = ADMIN_ATTENDANCE_PAGE_SIZE) {
  const n = Number(perPage)
  if (ATTENDANCE_PAGE_SIZE_OPTIONS.includes(n)) return n
  return ATTENDANCE_PAGE_SIZE_OPTIONS.includes(fallback) ? fallback : 50
}

/** @deprecated Use EMPLOYEE_ATTENDANCE_PAGE_SIZE */
export const EMPLOYEE_ATTENDANCE_INITIAL_PER_PAGE = EMPLOYEE_ATTENDANCE_PAGE_SIZE

/** Inclusive calendar days between two YYYY-MM-DD strings (employee attendance summary sizing). */
export function inclusiveAttendanceDaySpan(fromDateStr, toDateStr) {
  if (!fromDateStr || !toDateStr) return 31
  const a = Date.parse(String(fromDateStr).slice(0, 10) + 'T12:00:00')
  const b = Date.parse(String(toDateStr).slice(0, 10) + 'T12:00:00')
  if (!Number.isFinite(a) || !Number.isFinite(b)) return 31
  return Math.floor(Math.abs(b - a) / 86400000) + 1
}

// Lightweight in-memory cache + in-flight dedupe for repeated GET endpoints.
// This reduces duplicate network calls across tabs/modules during rapid navigation.
const GET_CACHE = new Map()
const GET_IN_FLIGHT = new Map()

function clearGetCacheByPrefix(prefix) {
  if (!prefix) return
  for (const key of GET_CACHE.keys()) {
    if (key.startsWith(prefix)) GET_CACHE.delete(key)
  }
}

/**
 * After HR changes an employee from the admin panel, clear both admin snapshot GET cache and the
 * employee self-service `/employee/profile` cache in this browser (in-memory). Also notify listeners
 * so open Employee Profile tabs can invalidate React Query when the edited user is the viewer.
 * @param {number|string|null|undefined} employeeId - omit for list-wide actions (e.g. add employee) where no per-user profile key applies
 */
function clearCachesAfterAdminEmployeeDataChange(employeeId) {
  clearGetCacheByPrefix('/admin/employees')
  if (employeeId != null && employeeId !== '') {
    clearGetCacheByPrefix(`/admin/employees/${employeeId}/profile`)
  }
  clearGetCacheByPrefix('/employee/profile')
  if (typeof window !== 'undefined') {
    const n = employeeId != null && employeeId !== '' ? Number(employeeId) : NaN
    if (Number.isFinite(n)) {
      window.dispatchEvent(new CustomEvent('hr:admin-updated-employee', { detail: { employeeId: n } }))
    }
  }
}

async function cachedAuthenticatedGetJson(path, { ttlMs = 0, timeoutMs } = {}) {
  const cacheKey = path
  const now = Date.now()
  const cached = GET_CACHE.get(cacheKey)
  if (cached && cached.expiresAt > now) {
    return cached.data
  }

  const inFlight = GET_IN_FLIGHT.get(cacheKey)
  if (inFlight) return inFlight

  const requestPromise = (async () => {
    const res = await authenticatedFetch(path, {
      ...(timeoutMs != null ? { timeoutMs } : {}),
    })
    const data = await res.json().catch(() => ({}))
    if (!res.ok) {
      throw new Error(data.message || 'Failed to load data')
    }
    if (ttlMs > 0) {
      GET_CACHE.set(cacheKey, {
        data,
        expiresAt: Date.now() + ttlMs,
      })
    }
    return data
  })()

  GET_IN_FLIGHT.set(cacheKey, requestPromise)
  try {
    return await requestPromise
  } finally {
    GET_IN_FLIGHT.delete(cacheKey)
  }
}

/**
 * fetch with deadline; optional `signal` aborts the request (caller abort, not timeout).
 * On deadline, throws Error(TIMEOUT_ERROR_MSG) so it is distinct from AbortError.
 */
async function fetchWithTimeout(url, options = {}, timeoutMs = REQUEST_TIMEOUT_MS) {
  const { signal: userSignal, ...rest } = options
  const controller = new AbortController()
  let deadlineFired = false
  const timeoutId = setTimeout(() => {
    deadlineFired = true
    controller.abort()
  }, timeoutMs)

  const onUserAbort = () => {
    clearTimeout(timeoutId)
    controller.abort()
  }
  if (userSignal) {
    if (userSignal.aborted) {
      clearTimeout(timeoutId)
      throw new DOMException('Aborted', 'AbortError')
    }
    userSignal.addEventListener('abort', onUserAbort, { once: true })
  }

  try {
    return await fetch(url, { ...rest, signal: controller.signal })
  } catch (e) {
    if (e?.name === 'AbortError') {
      if (deadlineFired) {
        throw new Error(TIMEOUT_ERROR_MSG)
      }
      throw e
    }
    throw e
  } finally {
    clearTimeout(timeoutId)
    if (userSignal) {
      userSignal.removeEventListener('abort', onUserAbort)
    }
  }
}

const USER_KEY = 'hris_user'

/** Notify AuthContext after login/register so React state matches storage without a full reload. */
function notifyAuthChanged(user) {
  if (typeof window === 'undefined') return
  try {
    window.dispatchEvent(new CustomEvent('hr:auth-changed', { detail: { user: user ?? null } }))
  } catch {
    // ignore
  }
}

function logAuthDebug(event, detail = {}) {
  if (typeof console === 'undefined') return
  try {
    console.info(`[auth] ${event}`, detail)
  } catch {
    // ignore console serialization errors
  }
}

export function getToken() {
  try {
    return localStorage.getItem(TOKEN_KEY) || sessionStorage.getItem(TOKEN_KEY)
  } catch {
    return null
  }
}

function clearToken() {
  try {
    sessionStorage.removeItem(TOKEN_KEY)
    sessionStorage.removeItem(USER_KEY)
    sessionStorage.removeItem('user')
    sessionStorage.removeItem('auth_user')
  } catch {
    // ignore when sessionStorage is unavailable
  }
  try {
    localStorage.removeItem(TOKEN_KEY)
    localStorage.removeItem(USER_KEY)
    localStorage.removeItem('user')
    localStorage.removeItem('auth_user')
  } catch {
    // ignore when localStorage is unavailable (e.g. private mode)
  }
}

function getAuthStoragePreference() {
  try {
    if (localStorage.getItem(TOKEN_KEY)) return { remember: true }
  } catch {
    // ignore
  }
  try {
    if (sessionStorage.getItem(TOKEN_KEY)) return { remember: true }
  } catch {
    // ignore
  }
  return {}
}

function setToken(token) {
  if (!token) return

  // Ensure we don't keep stale auth in the other storage.
  clearToken()

  try {
    // Keep auth token in localStorage so multiple tabs share one login session.
    localStorage.setItem(TOKEN_KEY, token)
  } catch {
    try {
      // Fallback only when localStorage is unavailable.
      sessionStorage.setItem(TOKEN_KEY, token)
    } catch {
      // If storage fails, there's nothing more we can do.
    }
  }
}

/** Persist user (id, name, email, role) for role-based UI after reload. */
export function setStoredUser(user) {
  try {
    // Clear existing copies to avoid mismatched token/user storage.
    try {
      localStorage.removeItem(USER_KEY)
    } catch {
      // ignore
    }
    try {
      sessionStorage.removeItem(USER_KEY)
    } catch {
      // ignore
    }

    // Keep auth snapshot in localStorage so all tabs receive the same current user.
    if (user) localStorage.setItem(USER_KEY, JSON.stringify(user))
    else localStorage.removeItem(USER_KEY)
  } catch {
    try {
      if (user) sessionStorage.setItem(USER_KEY, JSON.stringify(user))
      else sessionStorage.removeItem(USER_KEY)
    } catch {
      // ignore when browser storage is unavailable
    }
  }
}

/** Get last stored user (e.g. from login/register). Includes role. */
export function getStoredUser() {
  try {
    const raw =
      localStorage.getItem(USER_KEY) ||
      sessionStorage.getItem(USER_KEY)
    return raw ? JSON.parse(raw) : null
  } catch {
    return null
  }
}

export async function ensureSanctumCsrfCookie() {
  if (!USE_SANCTUM_SESSION) return
  if (csrfCookieBootPromise) return csrfCookieBootPromise
  csrfCookieBootPromise = wrapNetworkError(
    fetchWithTimeout(sanctumCsrfUrl(), {
      method: 'GET',
      credentials: 'include',
      headers: { Accept: 'application/json' },
    })
  )
    .then(() => undefined)
    .catch((e) => {
      csrfCookieBootPromise = null
      throw e
    })
  return csrfCookieBootPromise
}

/**
 * Public (unauthenticated) POST/PATCH/DELETE to `/api/*` with Sanctum CSRF.
 * Required when Laravel uses `statefulApi()` — browser requests from SANCTUM_STATEFUL_DOMAINS
 * must send `credentials: 'include'` and `X-XSRF-TOKEN` after `/sanctum/csrf-cookie`.
 * Retries once on HTTP 419 (session/token mismatch) with a fresh CSRF cookie.
 *
 * @param {string} path - API path starting with `/` (e.g. `/face/liveness/session`)
 * @param {RequestInit & { timeoutMs?: number }} [options]
 * @returns {Promise<Response>}
 */
export async function fetchWithSanctumCsrf(path, options = {}) {
  const method = String(options.method || 'POST').toUpperCase()
  const { timeoutMs, ...rest } = options
  const url = path.startsWith('http') ? path : `${API_BASE}${path.startsWith('/') ? path : `/${path}`}`

  const attempt = async (isRetry) => {
    if (USE_SANCTUM_SESSION && !['GET', 'HEAD', 'OPTIONS'].includes(method)) {
      if (isRetry) {
        csrfCookieBootPromise = null
      }
      await ensureSanctumCsrfCookie()
    }
    const xsrf = USE_SANCTUM_SESSION ? getXsrfTokenFromCookie() : null
    const headers = {
      Accept: 'application/json',
      ...rest.headers,
    }
    if (xsrf && !headers['X-XSRF-TOKEN']) {
      headers['X-XSRF-TOKEN'] = xsrf
    }
    const isFormDataBody = typeof FormData !== 'undefined' && rest.body instanceof FormData
    const res = await wrapNetworkError(
      fetchWithTimeout(
        url,
        {
          ...rest,
          method,
          credentials: USE_SANCTUM_SESSION ? 'include' : rest.credentials ?? 'same-origin',
          headers: {
            ...(rest.body && !isFormDataBody && !headers['Content-Type'] && { 'Content-Type': 'application/json' }),
            ...headers,
          },
        },
        timeoutMs ?? REQUEST_TIMEOUT_MS
      )
    )
    if (res.status === 419 && USE_SANCTUM_SESSION && !isRetry && !['GET', 'HEAD', 'OPTIONS'].includes(method)) {
      return attempt(true)
    }
    return res
  }

  return attempt(false)
}

/**
 * Authenticated request: sends Bearer token and clears it on 401.
 * @param {string} path - API path (e.g. '/user') or full URL
 * @param {RequestInit & { timeoutMs?: number }} [options] - fetch options; `timeoutMs` overrides default deadline
 * @returns {Promise<Response>}
 */
export async function authenticatedFetch(path, options = {}) {
  const { timeoutMs, ...fetchOptions } = options
  const url = path.startsWith('http') ? path : `${API_BASE}${path.startsWith('/') ? path : `/${path}`}`
  const method = String(fetchOptions.method || 'GET').toUpperCase()
  const token = getToken()
  const attempt = async (isRetry) => {
    if (USE_SANCTUM_SESSION && !['GET', 'HEAD', 'OPTIONS'].includes(method)) {
      if (isRetry) csrfCookieBootPromise = null
      await ensureSanctumCsrfCookie()
    }
    const headers = {
      Accept: 'application/json',
      ...fetchOptions.headers,
    }
    if (USE_SANCTUM_SESSION && !['GET', 'HEAD', 'OPTIONS'].includes(method)) {
      const xsrf = getXsrfTokenFromCookie()
      if (xsrf && !headers['X-XSRF-TOKEN']) {
        headers['X-XSRF-TOKEN'] = xsrf
      }
    }
    if (token) {
      headers.Authorization = `Bearer ${token}`
    }
    const isFormDataBody = typeof FormData !== 'undefined' && fetchOptions.body instanceof FormData
    const requestOnce = () => wrapNetworkError(
      fetchWithTimeout(url, {
        ...fetchOptions,
        // Sanctum SPA cookie auth requires credentials on cross-origin/subdomain API calls.
        credentials: USE_SANCTUM_SESSION ? 'include' : (fetchOptions.credentials ?? 'same-origin'),
        headers: {
          // Only force JSON Content-Type when we are not sending FormData and caller
          // has not explicitly set a Content-Type header.
          ...(fetchOptions.body && !isFormDataBody && !headers['Content-Type'] && { 'Content-Type': 'application/json' }),
          ...headers,
        },
      }, timeoutMs ?? REQUEST_TIMEOUT_MS)
    )
    let res
    let lastError = null
    const shouldRetryTransientGet = method === 'GET' || method === 'HEAD'
    const maxAttempts = shouldRetryTransientGet ? GET_RETRY_ATTEMPTS : 1
    for (let attemptNo = 1; attemptNo <= maxAttempts; attemptNo += 1) {
      try {
        res = await requestOnce()
        lastError = null
        break
      } catch (err) {
        lastError = err
        if (!shouldRetryTransientGet || !isTransientNetworkError(err) || attemptNo >= maxAttempts) {
          throw err
        }
        await delayMs(GET_RETRY_BASE_DELAY_MS * attemptNo)
      }
    }
    if (!res && lastError) throw lastError
    if (res.status === 419 && USE_SANCTUM_SESSION && !isRetry && !['GET', 'HEAD', 'OPTIONS'].includes(method)) {
      return attempt(true)
    }
    if (res.status === 401) {
      clearToken()
      throw new Error('Session expired. Please sign in again.')
    }
    return res
  }
  return attempt(false)
}

export async function login(loginValue, password, role, options = {}) {
  return loginWithRole(loginValue, password, role, options)
}

/** Login via QR code scan (employee badge). */
export async function loginWithQr(qrToken, options = {}) {
  const res = await fetchWithSanctumCsrf('/login/qr', {
    method: 'POST',
    body: JSON.stringify({ qr_token: qrToken }),
  })
  const data = await res.json().catch(() => ({}))
  if (!res.ok) {
    const msg = data.errors?.qr_token?.[0] || data.message || 'QR login failed'
    throw new Error(msg)
  }
  if (data.token) setToken(data.token, options)
  if (data.user) setStoredUser(data.user, options)
  logAuthDebug('login:qr:success', {
    userId: data?.user?.id ?? null,
    role: data?.user?.role ?? null,
    hrRole: data?.user?.hr_role ?? null,
  })
  notifyAuthChanged(data.user)
  return data
}

/**
 * Create Amazon Rekognition Face Liveness session for Amplify FaceLivenessDetector.
 * @returns {{ sessionId: string, region: string }}
 */
export async function createLivenessSession() {
  const res = await fetchWithSanctumCsrf('/face/liveness/session', { method: 'POST' })
  const data = await res.json().catch(() => ({}))
  if (!res.ok) throw new Error(data.message || 'Could not create liveness session')
  if (!data.sessionId) throw new Error('Invalid liveness session response')
  return {
    sessionId: data.sessionId,
    region: data.region || import.meta.env.VITE_AWS_REGION || 'us-east-1',
    cognitoIdentityPoolId: data.cognitoIdentityPoolId ?? data.cognitoId,
    cognitoRegion: data.cognitoRegion,
  }
}

/**
 * Fetch Face Liveness session results (backend calls GetFaceLivenessSessionResults). Prefer over calling AWS from the browser.
 * Uses POST with CSRF for stateful SPA; same payload as GET /face/liveness/session/{id}.
 */
export async function fetchLivenessSessionResults(sessionId) {
  const res = await fetchWithSanctumCsrf('/face/liveness/results', {
    method: 'POST',
    body: JSON.stringify({ session_id: sessionId }),
  })
  const data = await res.json().catch(() => ({}))
  if (!res.ok) throw new Error(data.message || 'Could not retrieve liveness result')
  return data
}

/**
 * Login via face recognition. Uses Amazon Rekognition Face Liveness session (recommended) or legacy image.
 * @param {{ liveness_session_id?: string, image_base64?: string }} payload - pass liveness_session_id after Amplify liveness, or image_base64 for legacy
 * On 422, throws an error with message and errorCode: 'spoof_detected' | 'no_face_detected' | 'face_not_recognized' | 'service_unavailable'.
 */
export async function loginWithFace(payload, options = {}) {
  const body =
    typeof payload === 'string'
      ? { image_base64: payload }
      : {
          liveness_session_id: payload?.liveness_session_id,
          image_base64: payload?.image_base64,
          client_capture_started_at_ms: payload?.client_capture_started_at_ms,
        }
  const res = await fetchWithSanctumCsrf('/login/face', {
    method: 'POST',
    body: JSON.stringify(body),
  })
  const data = await res.json().catch(() => ({}))
  if (!res.ok) {
    const msg = data.errors?.face?.[0] || data.message || 'Face login failed'
    const err = new Error(msg)
    err.errorCode = data.error_code || null
    throw err
  }
  if (data.token) setToken(data.token, options)
  if (data.user) setStoredUser(data.user, options)
  logAuthDebug('login:face:success', {
    userId: data?.user?.id ?? null,
    role: data?.user?.role ?? null,
    hrRole: data?.user?.hr_role ?? null,
  })
  notifyAuthChanged(data.user)
  return data
}

/**
 * Verify-only: run anti-spoof on a frame and return is_live + spoof_confidence (0–1).
 * Used for real-time Live % display. Does not perform face matching or clock-in.
 */
export async function verifyFaceOnly(imageBase64) {
  const res = await fetchWithSanctumCsrf('/face/verify-only', {
    method: 'POST',
    body: JSON.stringify({ image_base64: imageBase64 }),
  })
  const data = await res.json().catch(() => ({}))
  if (!res.ok) return null
  return {
    is_live: data.is_live === true,
    spoof_confidence: data.spoof_confidence != null ? Number(data.spoof_confidence) : null,
    message: data.message || '',
  }
}

export async function loginWithRole(loginValue, password, role, options = {}) {
  const LOGIN_TIMEOUT_MS = 60 * 1000
  if (USE_SANCTUM_SESSION) {
    await ensureSanctumCsrfCookie()
  }
  const xsrf = USE_SANCTUM_SESSION ? getXsrfTokenFromCookie() : null
  const res = await wrapNetworkError(
    fetchWithTimeout(`${API_BASE}/login`, {
      method: 'POST',
      credentials: USE_SANCTUM_SESSION ? 'include' : 'same-origin',
      headers: {
        'Content-Type': 'application/json',
        Accept: 'application/json',
        ...(xsrf ? { 'X-XSRF-TOKEN': xsrf } : {}),
      },
      body: JSON.stringify({ login: loginValue, password, role }),
    }, LOGIN_TIMEOUT_MS)
  )
  let data
  try {
    data = await res.json()
  } catch {
    throw new Error('Invalid response from server. Is the backend running?')
  }
  if (!res.ok) {
    const msg = data.errors?.login?.[0] || data.errors?.role?.[0] || data.message || 'Login failed'
    throw new Error(msg)
  }
  if (data.token) setToken(data.token, options)
  if (data.user) setStoredUser(data.user, options)
  logAuthDebug('login:password:success', {
    userId: data?.user?.id ?? null,
    role: data?.user?.role ?? null,
    hrRole: data?.user?.hr_role ?? null,
  })
  notifyAuthChanged(data.user)
  return data
}

// ---- Password reset (OTP via email) ----

export async function requestPasswordResetOtp(login) {
  const res = await fetchWithSanctumCsrf('/password/forgot', {
    method: 'POST',
    body: JSON.stringify({ login }),
  })
  const data = await res.json().catch(() => ({}))
  if (!res.ok) {
    const msg = data.errors?.login?.[0] || data.message || 'Could not send OTP.'
    throw new Error(msg)
  }
  return data
}

export async function verifyPasswordResetOtp(requestId, otp) {
  const res = await fetchWithSanctumCsrf('/password/verify-otp', {
    method: 'POST',
    body: JSON.stringify({ request_id: requestId, otp }),
  })
  const data = await res.json().catch(() => ({}))
  if (!res.ok) {
    const msg = data.errors?.otp?.[0] || data.message || 'OTP verification failed.'
    throw new Error(msg)
  }
  return data
}

export async function resetPasswordWithOtp(requestId, resetToken, password, passwordConfirmation) {
  const res = await fetchWithSanctumCsrf('/password/reset', {
    method: 'POST',
    body: JSON.stringify({
      request_id: requestId,
      reset_token: resetToken,
      password,
      password_confirmation: passwordConfirmation,
    }),
  })
  const data = await res.json().catch(() => ({}))
  if (!res.ok) {
    const msg =
      data.errors?.password?.[0] ||
      data.errors?.reset_token?.[0] ||
      data.errors?.request_id?.[0] ||
      data.message ||
      'Password reset failed.'
    throw new Error(msg)
  }
  return data
}

export async function register(firstName, lastName, email, password, phoneNumber, role = 'employee', adminInviteCode) {
  const res = await fetchWithSanctumCsrf('/register', {
    method: 'POST',
    body: JSON.stringify({
      first_name: firstName,
      last_name: lastName,
      email,
      password,
      phone_number: phoneNumber?.trim() || undefined,
      role,
      ...(role === 'admin' && adminInviteCode ? { admin_invite_code: adminInviteCode } : {}),
    }),
  })
  let data
  try {
    data = await res.json()
  } catch {
    throw new Error('Invalid response from server. Is the backend running?')
  }
  if (!res.ok) {
    const msg = data.errors
      ? Object.values(data.errors).flat().join(' ')
      : data.message || 'Registration failed'
    throw new Error(msg)
  }
  if (data.token) setToken(data.token, { remember: true })
  if (data.user) setStoredUser(data.user, { remember: true })
  notifyAuthChanged(data.user)
  return data
}

export async function logout() {
  const token = getToken()
  try {
    if (USE_SANCTUM_SESSION) {
      await ensureSanctumCsrfCookie()
      const xsrf = getXsrfTokenFromCookie()
      await wrapNetworkError(
        fetchWithTimeout(`${API_BASE}/logout`, {
          method: 'POST',
          credentials: 'include',
          headers: {
            Accept: 'application/json',
            ...(xsrf ? { 'X-XSRF-TOKEN': xsrf } : {}),
          },
        })
      )
    } else if (token) {
      await authenticatedFetch('/logout', { method: 'POST' })
    }
  } finally {
    logAuthDebug('logout', { hadToken: Boolean(token) })
    clearToken()
    setStoredUser(null)
    notifyAuthChanged(null)
  }
}

/**
 * Get current user from API. Returns null if not authenticated or token invalid.
 */
export async function getAuthenticatedUser() {
  const token = getToken()

  if (!token) {
    setStoredUser(null, getAuthStoragePreference())
    logAuthDebug('hydrate:none', { hasToken: false, reason: 'missing_token' })
    return null
  }

  try {
    const res = await authenticatedFetch('/user')
    if (res.ok) {
      const data = await res.json().catch(() => ({}))
      const user = data.user ?? null
      if (user) setStoredUser(user, getAuthStoragePreference())
      logAuthDebug('hydrate:bearer', {
        hasToken: true,
        userId: user?.id ?? null,
        role: user?.role ?? null,
        hrRole: user?.hr_role ?? null,
      })
      return user
    }
  } catch (err) {
    // Transient timeout/network failures should not be treated as invalid token.
    if (isTransientNetworkError(err)) {
      throw err
    }
    // ignore and fall through to null for true auth failures
  }

  setStoredUser(null, getAuthStoragePreference())
  logAuthDebug('hydrate:none', { hasToken: true, reason: 'token_invalid' })
  return null
}

export function isAuthenticated() {
  return !!getToken()
}

export async function initializeAuthSession() {
  if (!USE_SANCTUM_SESSION) return
  try {
    await ensureSanctumCsrfCookie()
  } catch {
    // non-fatal during app bootstrap
  }
}

// —— Profile (authenticated user) ——

/**
 * Update profile (email, phone, and/or password).
 * @param {{ email?: string, phone_number?: string | null, current_password?: string, password?: string, password_confirmation?: string, name?: string, username?: string }} payload
 * @returns {Promise<{ user: object, message?: string }>}
 */
export async function updateProfile(payload) {
  const res = await authenticatedFetch('/profile', {
    method: 'PATCH',
    headers: { 'Content-Type': 'application/json' },
    body: JSON.stringify(payload),
  })
  const data = await res.json().catch(() => ({}))
  if (!res.ok) {
    const msg = data.errors
      ? Object.values(data.errors).flat().filter(Boolean)[0] || data.message || 'Failed to update profile'
      : data.message || 'Failed to update profile'
    throw new Error(msg)
  }
  if (data.user) setStoredUser(data.user)
  return data
}

/**
 * Upload profile photo.
 * @param {File} file
 * @returns {Promise<{ user: object, message?: string }>}
 */
export async function uploadProfilePhoto(file) {
  const formData = new FormData()
  formData.append('photo', file)
  const res = await authenticatedFetch('/profile/photo', {
    method: 'POST',
    headers: { Accept: 'application/json' },
    body: formData,
  })
  const data = await res.json().catch(() => ({}))
  if (!res.ok) {
    const msg = data.errors?.photo?.[0] || data.message || 'Failed to upload photo'
    throw new Error(msg)
  }
  if (data.user) setStoredUser(data.user)
  return data
}

/**
 * Remove profile photo.
 * @returns {Promise<{ user: object, message?: string }>}
 */
export async function removeProfilePhoto() {
  const res = await authenticatedFetch('/profile/photo', { method: 'DELETE' })
  const data = await res.json().catch(() => ({}))
  if (!res.ok) throw new Error(data.message || 'Failed to remove photo')
  if (data.user) setStoredUser(data.user)
  return data
}

/**
 * Get the authenticated employee's QR token (for My QR page). Same data as Admin employee QR.
 * @returns {Promise<{ employee_id: number, employee_name: string, qr_token: string, qr_token_generated_at?: string, has_qr: boolean }>}
 */
export async function getMyQr() {
  const res = await authenticatedFetch('/profile/qr')
  const data = await res.json().catch(() => ({}))
  if (!res.ok) throw new Error(data.message || 'Failed to load QR code')
  return data
}

/**
 * Regenerate the authenticated employee's QR token (invalidates previous QR).
 * @returns {Promise<{ message: string, qr_token: string, qr_token_generated_at?: string, has_qr: boolean }>}
 */
export async function regenerateMyQr() {
  const res = await authenticatedFetch('/profile/qr/regenerate', { method: 'POST' })
  const data = await res.json().catch(() => ({}))
  if (!res.ok) throw new Error(data.message || 'Failed to regenerate QR code')
  return data
}

function delay(ms) {
  return new Promise((resolve) => setTimeout(resolve, ms))
}

/**
 * Poll async face registration (queue) until completed or failed.
 * @param {string} pollUrl - GET status URL from the same origin as other API calls
 */
async function pollFaceRegistrationUntilDone(pollUrl, options = {}) {
  // InsightFace registration budget: job timeout 120s × up to 3 tries + 15s backoff between retries
  // = ~390s worst case. Poll every 2.5s for up to 180 attempts = ~7.5 min ceiling.
  // In practice a healthy worker finishes in 3–10 seconds.
  const maxAttempts = options.maxAttempts ?? 180
  const intervalMs = options.intervalMs ?? 2500
  for (let i = 0; i < maxAttempts; i++) {
    await delay(intervalMs)
    const res = await authenticatedFetch(pollUrl)
    const data = await res.json().catch(() => ({}))
    if (res.status === 404 || res.status === 403) {
      const err = new Error(data.message || 'Registration request expired or access denied.')
      err.errorCode = data.error_code || 'registration_poll_denied'
      throw err
    }
    if (data.status === 'completed') return data
    if (data.status === 'failed' || res.status === 422) {
      const msg = data.message || data.errors?.face?.[0] || 'Face registration failed'
      const err = new Error(msg)
      err.errorCode = data.error_code
      throw err
    }
  }
  const err = new Error(
    'Registration timed out. Please try again with better lighting and your face clearly in frame. If this keeps happening, ask your administrator to confirm the face queue worker is running.'
  )
  err.errorCode = 'registration_timeout'
  throw err
}

/**
 * Register the authenticated employee's face. Use Rekognition liveness (recommended) or legacy image.
 * Backend may return 202 + track_id (queued); this function polls until the face is stored or an error is returned.
 * @param {{ liveness_session_id?: string, image_base64?: string, liveness_type?: string }} payload - liveness_session_id after Amplify liveness, or image_base64 (legacy)
 * @returns {Promise<{ message: string, user: object, status?: string }>}
 */
export async function registerMyFace(payload) {
  const body =
    typeof payload === 'string'
      ? { image_base64: payload, liveness_type: 'rekognition' }
      : {
          liveness_session_id: payload?.liveness_session_id,
          image_base64: payload?.image_base64,
          liveness_type: payload?.liveness_type ?? 'rekognition',
        }
  const res = await authenticatedFetch('/profile/face/register', {
    method: 'POST',
    headers: { 'Content-Type': 'application/json' },
    body: JSON.stringify(body),
  })
  const data = await res.json().catch(() => ({}))
  if (res.status === 202 && data.track_id) {
    return pollFaceRegistrationUntilDone(`/profile/face/register/status/${data.track_id}`)
  }
  if (!res.ok) {
    const msg = data.errors?.face?.[0] || data.message || 'Face registration failed'
    const err = new Error(msg)
    err.errorCode = data.error_code
    throw err
  }
  return data
}

/**
 * Remove the authenticated employee's registered face.
 * @returns {Promise<{ message: string, user: object }>}
 */
export async function removeMyFace() {
  const res = await authenticatedFetch('/profile/face', { method: 'DELETE' })
  const data = await res.json().catch(() => ({}))
  if (!res.ok) throw new Error(data.message || 'Failed to remove face')
  return data
}

// —— Employee Dashboard: Profile (tabbed) ——

/**
 * Get the authenticated employee's full profile (personal, employment, benefits, government IDs, emergency contacts, account).
 * @returns {Promise<{ user: object, government_ids: object, emergency_contacts: any[], benefits: any[] }>}
 */
export async function getMyEmployeeProfile(options = {}) {
  const qs = new URLSearchParams()
  if (options.include_benefits != null) qs.set('include_benefits', options.include_benefits ? '1' : '0')
  if (options.include_leave_credits != null) qs.set('include_leave_credits', options.include_leave_credits ? '1' : '0')
  if (options.include_leave_credits_history != null) qs.set('include_leave_credits_history', options.include_leave_credits_history ? '1' : '0')
  if (options.include_compensation_summary != null) qs.set('include_compensation_summary', options.include_compensation_summary ? '1' : '0')
  if (options.include_pay_cycle_preview != null) qs.set('include_pay_cycle_preview', options.include_pay_cycle_preview ? '1' : '0')
  const suffix = qs.toString() ? `?${qs.toString()}` : ''
  // No client TTL: profile must reflect HR edits and server invalidation immediately; duplicate GETs still dedupe via GET_IN_FLIGHT.
  return cachedAuthenticatedGetJson(`/employee/profile${suffix}`, { ttlMs: 0 })
}

/**
 * HR panel: full employee profile snapshot (same shape as getMyEmployeeProfile) for a scoped employee.
 * @param {number|string} employeeId
 */
export async function getEmployeeProfileSnapshot(employeeId, options = {}) {
  const qs = new URLSearchParams()
  if (options.lite != null) qs.set('lite', options.lite ? '1' : '0')
  if (options.include_government_ids != null) qs.set('include_government_ids', options.include_government_ids ? '1' : '0')
  if (options.include_emergency_contacts != null) qs.set('include_emergency_contacts', options.include_emergency_contacts ? '1' : '0')
  if (options.include_benefits != null) qs.set('include_benefits', options.include_benefits ? '1' : '0')
  if (options.include_leave_credits != null) qs.set('include_leave_credits', options.include_leave_credits ? '1' : '0')
  if (options.include_leave_credits_history != null) qs.set('include_leave_credits_history', options.include_leave_credits_history ? '1' : '0')
  if (options.include_compensation_summary != null) qs.set('include_compensation_summary', options.include_compensation_summary ? '1' : '0')
  if (options.include_pay_cycle_preview != null) qs.set('include_pay_cycle_preview', options.include_pay_cycle_preview ? '1' : '0')
  const suffix = qs.toString() ? `?${qs.toString()}` : ''
  const ttlMs = options.include_compensation_summary ? 0 : 90 * 1000
  return cachedAuthenticatedGetJson(`/admin/employees/${employeeId}/profile${suffix}`, { ttlMs })
}

/**
 * Admin: preview schedule-based divisors/rates for an employee as if assigned a given working schedule.
 * @param {number|string} employeeId
 * @param {number|string} workingScheduleId
 */
export async function getAdminEmployeeScheduleRatePreview(employeeId, workingScheduleId) {
  const qs = new URLSearchParams({ working_schedule_id: String(workingScheduleId) })
  const res = await authenticatedFetch(`/admin/employees/${employeeId}/schedule-rate-preview?${qs.toString()}`)
  const data = await res.json().catch(() => ({}))
  if (!res.ok) throw new Error(data.message || 'Failed to load schedule rate preview')
  return data
}

/**
 * Update authenticated employee personal info (Profile tab).
 * @param {{ first_name?: string, middle_name?: string|null, last_name?: string, suffix?: string|null, date_of_birth?: string|null, gender?: string|null, civil_status?: string|null, nationality?: string|null, home_address?: string|null }} payload
 */
export async function updateMyPersonalInfo(payload, options = {}) {
  const { timeoutMs } = options
  const res = await authenticatedFetch('/employee/profile/personal', {
    method: 'PATCH',
    body: JSON.stringify(payload),
    ...(timeoutMs != null ? { timeoutMs } : {}),
  })
  const data = await res.json().catch(() => ({}))
  if (!res.ok) {
    throw new Error(laravelValidationMessage(data, 'Failed to update personal info'))
  }
  if (!data.user || typeof data.user !== 'object') {
    throw new Error('Invalid server response: profile was not updated.')
  }
  setStoredUser(data.user)
  clearGetCacheByPrefix('/employee/profile')
  return data
}

/**
 * Save authenticated employee signature image.
 * @param {string} signatureDataUrl
 */
export async function saveMySignature(signatureDataUrl) {
  const res = await authenticatedFetch('/employee/profile/signature', {
    method: 'POST',
    body: JSON.stringify({ signature_data_url: signatureDataUrl }),
  })
  const data = await res.json().catch(() => ({}))
  if (!res.ok) throw new Error(data.message || 'Failed to save signature')
  if (data.user) setStoredUser(data.user)
  clearGetCacheByPrefix('/employee/profile')
  return data
}

/**
 * Clear authenticated employee signature image.
 */
export async function clearMySignature() {
  const res = await authenticatedFetch('/employee/profile/signature', { method: 'DELETE' })
  const data = await res.json().catch(() => ({}))
  if (!res.ok) throw new Error(data.message || 'Failed to remove signature')
  if (data.user) setStoredUser(data.user)
  clearGetCacheByPrefix('/employee/profile')
  return data
}

/**
 * Update authenticated employee government IDs (Government IDs tab).
 * @param {{ sss_number?: string|null, philhealth_number?: string|null, pagibig_number?: string|null, tin_number?: string|null }} payload
 */
export async function updateMyGovernmentIds(payload) {
  const res = await authenticatedFetch('/employee/profile/government-ids', {
    method: 'PATCH',
    body: JSON.stringify(payload),
  })
  const data = await res.json().catch(() => ({}))
  if (!res.ok) {
    const msg = data.errors ? Object.values(data.errors).flat().filter(Boolean)[0] || data.message : data.message
    throw new Error(msg || 'Failed to update government IDs')
  }
  return data
}

/**
 * Replace authenticated employee emergency contacts (Emergency Contacts tab).
 * @param {Array<{ full_name: string, relationship: string, phone_number: string, address?: string|null, is_primary?: boolean }>} contacts
 */
export async function replaceMyEmergencyContacts(contacts) {
  const res = await authenticatedFetch('/employee/profile/emergency-contacts', {
    method: 'PUT',
    body: JSON.stringify({ contacts }),
  })
  const data = await res.json().catch(() => ({}))
  if (!res.ok) {
    const msg = data.errors ? Object.values(data.errors).flat().filter(Boolean)[0] || data.message : data.message
    throw new Error(msg || 'Failed to update emergency contacts')
  }
  return data
}

// —— Admin: Dashboard ——

/**
 * Fetches admin dashboard data. Returns normalized shape so Overview cards always have stats/today_logs etc.
 * @returns {Promise<{ stats: object, stats_prev: object, weekly_overview: array, upcoming_holidays: array, department_distribution: array, today_logs: array }>}
 */
export async function getDashboardData() {
  const res = await authenticatedFetch('/admin/dashboard')
  const body = await res.json().catch(() => ({}))
  if (!res.ok) {
    const msg = res.status === 403
      ? 'You do not have permission to view the dashboard.'
      : (body.message || 'Failed to load dashboard')
    throw new Error(msg)
  }
  // Normalize: support direct response or Laravel API resource wrapper (data key).
  const raw = body.data != null ? body.data : body
  return {
    stats: raw.stats ?? {},
    stats_prev: raw.stats_prev ?? {},
    weekly_overview: Array.isArray(raw.weekly_overview) ? raw.weekly_overview : [],
    upcoming_holidays: Array.isArray(raw.upcoming_holidays) ? raw.upcoming_holidays : [],
    department_distribution: Array.isArray(raw.department_distribution) ? raw.department_distribution : [],
    company_distribution: Array.isArray(raw.company_distribution) ? raw.company_distribution : [],
    today_logs: Array.isArray(raw.today_logs) ? raw.today_logs : [],
    half_day_summary: raw.half_day_summary ?? { am_today: 0, pm_today: 0, total_today: 0, total_workforce: 0 },
    today_leaves: Array.isArray(raw.today_leaves) ? raw.today_leaves : [],
    today_birthdays: Array.isArray(raw.today_birthdays) ? raw.today_birthdays : [],
    current_month_birthdays: Array.isArray(raw.current_month_birthdays) ? raw.current_month_birthdays : [],
    upcoming_30_days: Array.isArray(raw.upcoming_30_days) ? raw.upcoming_30_days : (Array.isArray(raw.upcoming_birthdays) ? raw.upcoming_birthdays : []),
    upcoming_90_days: Array.isArray(raw.upcoming_90_days) ? raw.upcoming_90_days : (Array.isArray(raw.upcoming_birthdays_90) ? raw.upcoming_birthdays_90 : []),
    upcoming_birthdays: Array.isArray(raw.upcoming_birthdays) ? raw.upcoming_birthdays : (Array.isArray(raw.upcoming_30_days) ? raw.upcoming_30_days : []),
    upcoming_birthdays_90: Array.isArray(raw.upcoming_birthdays_90) ? raw.upcoming_birthdays_90 : (Array.isArray(raw.upcoming_90_days) ? raw.upcoming_90_days : []),
    birthday_month_label: raw.birthday_month_label ?? '',
    birthday_month_range_label: raw.birthday_month_range_label ?? '',
    // Widget payloads (required by dashboard cards).
    upcoming_regularizations: Array.isArray(raw.upcoming_regularizations) ? raw.upcoming_regularizations : [],
    expiring_contracts: Array.isArray(raw.expiring_contracts) ? raw.expiring_contracts : [],
    employment_settings: raw.employment_settings ?? null,
    pending_attendance_corrections: Number(raw.pending_attendance_corrections ?? 0) || 0,
    pending_attendance_correction_preview: raw.pending_attendance_correction_preview ?? null,
  }
}

/**
 * Admin dashboard: birthdays for a specific calendar month (current or past).
 * @param {{ year: number, month: number }} params — 1-based month
 */
export async function getAdminDashboardBirthdays({ year, month }) {
  const query = new URLSearchParams({
    year: String(year),
    month: String(month),
  })
  const res = await authenticatedFetch(`/admin/dashboard/birthdays?${query.toString()}`)
  const raw = await res.json().catch(() => ({}))
  if (!res.ok) {
    throw new Error(raw.message || 'Failed to load birthdays for this month')
  }
  return {
    birthdays: Array.isArray(raw.birthdays) ? raw.birthdays : [],
    birthday_month_label: raw.birthday_month_label ?? '',
    birthday_month_range_label: raw.birthday_month_range_label ?? '',
    year: Number(raw.year ?? year),
    month: Number(raw.month ?? month),
    is_current_month: Boolean(raw.is_current_month),
    is_past_month: Boolean(raw.is_past_month),
    is_future_month: Boolean(raw.is_future_month),
    can_go_previous: raw.can_go_previous !== false,
    can_go_next: Boolean(raw.can_go_next),
  }
}

/**
 * Fetch company attendance comparison data for the chart.
 * @param {{ from_date?: string, to_date?: string, company_ids?: number[] }} params
 */
export async function getDashboardCompanyAttendance(params = {}) {
  const query = new URLSearchParams()
  if (params.from_date) query.set('from_date', params.from_date)
  if (params.to_date) query.set('to_date', params.to_date)
  if (params.company_ids && Array.isArray(params.company_ids) && params.company_ids.length > 0) {
    params.company_ids.forEach((id) => query.append('company_ids[]', String(id)))
  }
  const path = `/admin/dashboard/company-attendance${query.toString() ? `?${query.toString()}` : ''}`
  const res = await authenticatedFetch(path)
  const body = await res.json().catch(() => ({}))
  if (!res.ok) throw new Error(body.message || 'Failed to load company attendance')
  return body.data != null ? body.data : body
}

/**
 * Fetch half-day leave list for a given date (employees on AM/PM half-day).
 * @param {{ date?: string }} params - date in YYYY-MM-DD (default: today)
 */
export async function getHalfDayList(params = {}) {
  const query = new URLSearchParams()
  if (params.date) query.set('date', params.date)
  const path = `/admin/dashboard/half-day-list${query.toString() ? `?${query.toString()}` : ''}`
  const res = await authenticatedFetch(path)
  const body = await res.json().catch(() => ({}))
  if (!res.ok) throw new Error(body.message || 'Failed to load half-day list')
  return body.data != null ? body.data : body
}

// —— Admin: Attendance Monitoring ——

export async function getAdminAttendance(params = {}) {
  const query = new URLSearchParams()
  if (params.date) query.set('date', params.date)
  if (params.from_date) query.set('from_date', params.from_date)
  if (params.to_date) query.set('to_date', params.to_date)
  if (params.department) query.set('department', params.department)
  if (params.employee_id) query.set('employee_id', String(params.employee_id))
  if (params.status) query.set('status', params.status)

  const p = Number(params.page)
  query.set('page', String(Number.isFinite(p) && p >= 1 ? Math.floor(p) : 1))
  query.set('per_page', String(normalizeAttendancePerPage(params.per_page, ADMIN_ATTENDANCE_PAGE_SIZE)))

  if (params.search) query.set('search', String(params.search).trim())
  if (params.company) query.set('company', String(params.company))
  if (params.pending_attention === true) query.set('pending_attention', '1')

  if (params.premium_type) query.set('premium_type', params.premium_type)
  const path = `/admin/attendance${query.toString() ? `?${query.toString()}` : ''}`
  const fetchOpts = params.signal ? { signal: params.signal } : {}
  const res = await authenticatedFetch(path, fetchOpts)
  const data = await res.json().catch(() => ({}))
  if (!res.ok) throw new Error(data.message || 'Failed to load attendance')
  return data
}

/** All rows for the given filters by paging ({@link ADMIN_ATTENDANCE_PAGE_SIZE} per request). Prefer export endpoint for CSV. */
export async function fetchAllAdminAttendanceRows(params = {}) {
  const first = await getAdminAttendance({ ...params, page: 1 })
  const meta = first.meta || {}
  const lastPage = Math.max(1, Number(meta.last_page) || 1)
  const out = [...(first.rows || [])]
  let page = 2
  while (page <= lastPage) {
    const chunk = await getAdminAttendance({ ...params, page })
    out.push(...(chunk.rows || []))
    page++
  }
  return out
}

/**
 * Export admin attendance monitoring report.
 * - format=csv returns a Blob (downloadable)
 * - format=json returns { from_date, to_date, rows } for building Excel in the SPA
 * @param {Record<string, unknown>} params
 */
export async function exportAdminAttendance(params = {}) {
  const query = new URLSearchParams()
  if (params.date) query.set('date', params.date)
  if (params.from_date) query.set('from_date', params.from_date)
  if (params.to_date) query.set('to_date', params.to_date)
  if (params.department) query.set('department', params.department)
  if (params.employee_id) query.set('employee_id', String(params.employee_id))
  if (params.status) query.set('status', params.status)
  if (params.premium_type) query.set('premium_type', params.premium_type)
  if (params.search) query.set('search', String(params.search).trim())
  if (params.company) query.set('company', String(params.company))
  if (params.pending_attention === true) query.set('pending_attention', '1')

  query.set('format', params.format === 'json' ? 'json' : 'csv')
  // Always bypass intermediary/browser cache so exports include the latest columns/data.
  query.set('_ts', String(Date.now()))

  const path = `/admin/attendance/export?${query.toString()}`
  const res = await authenticatedFetch(path)
  if (!res.ok) {
    const data = await res.json().catch(() => ({}))
    throw new Error(data.message || 'Failed to export attendance')
  }
  if (params.format === 'json') {
    return res.json()
  }
  return res.blob()
}

// —— Admin: Attendance Corrections ——

/**
 * Create or update a manual attendance correction for an employee and date.
 * @param {{ employee_id: number, date: string, time_in?: string, time_out?: string, remarks?: string, approved?: boolean }} payload
 */
export async function saveAttendanceCorrection(payload) {
  const body = {
    employee_id: Number(payload.employee_id),
    date: String(payload.date),
    approved: Boolean(payload.approved),
  }
  if (payload.preset_schedule_regular != null) body.preset_schedule_regular = Boolean(payload.preset_schedule_regular)
  if (!body.preset_schedule_regular) {
    if (payload.time_in != null && String(payload.time_in).trim() !== '') body.time_in = String(payload.time_in).trim()
    if (payload.time_out != null && String(payload.time_out).trim() !== '') body.time_out = String(payload.time_out).trim()
  }
  if (payload.remarks != null && String(payload.remarks).trim() !== '') body.remarks = String(payload.remarks).trim()
  if (payload.manual_presence_reason != null && String(payload.manual_presence_reason).trim() !== '') {
    body.manual_presence_reason = String(payload.manual_presence_reason).trim()
  }
  if (payload.override_leave != null) body.override_leave = Boolean(payload.override_leave)

  const res = await authenticatedFetch('/admin/attendance/corrections', {
    method: 'POST',
    headers: { 'Content-Type': 'application/json' },
    body: JSON.stringify(body),
  })
  const data = await res.json().catch(() => ({}))
  if (!res.ok) {
    const msg =
      data.errors?.employee_id?.[0] ||
      data.errors?.date?.[0] ||
      data.errors?.time_in?.[0] ||
      data.errors?.time_out?.[0] ||
      data.message ||
      'Failed to save correction'
    throw new Error(msg)
  }
  return data
}

/**
 * Delete an attendance correction by id.
 * @param {number} id Correction id
 */
export async function deleteAttendanceCorrection(id) {
  const res = await authenticatedFetch(`/admin/attendance/corrections/${id}`, {
    method: 'DELETE',
  })
  const data = await res.json().catch(() => ({}))
  if (!res.ok) throw new Error(data.message || 'Failed to remove correction')
  return data
}

// —— Admin & employee: Reports (detailed per-day only) ——

/**
 * Get detailed per-day attendance rows over a date range.
 * Each row includes date, schedule, time in/out, total hours, late/undertime/overtime minutes,
 * status, leave type/status, and overtime status.
 *
 * @param {{ from_date: string, to_date?: string, department?: string, employee_id?: number, status?: string, leave_type?: string, overtime_status?: string }} params
 */
export async function getAdminReportsDetailed(params = {}, fetchOpts = {}) {
  const query = new URLSearchParams()
  if (params.from_date) query.set('from_date', params.from_date)
  if (params.to_date) query.set('to_date', params.to_date)
  if (params.department) query.set('department', params.department)
  if (params.company_id != null && params.company_id !== '') query.set('company_id', String(params.company_id))
  if (params.branch_id != null && params.branch_id !== '') query.set('branch_id', String(params.branch_id))
  if (params.employee_id != null && params.employee_id !== '') query.set('employee_id', String(params.employee_id))
  if (params.status) query.set('status', params.status)
  if (params.leave_type) query.set('leave_type', params.leave_type)
  if (params.overtime_status) query.set('overtime_status', params.overtime_status)
  if (params.include_deactivated) query.set('include_deactivated', '1')
  const p = Number(params.page)
  query.set('page', String(Number.isFinite(p) && p >= 1 ? Math.floor(p) : 1))
  query.set('per_page', String(normalizeAttendancePerPage(params.per_page, REPORTS_AND_ATTENDANCE_PAGE_SIZE)))
  if (params.search) query.set('search', String(params.search).trim())

  const path = `/admin/reports/detailed${query.toString() ? `?${query.toString()}` : ''}`
  const res = await authenticatedFetch(path, fetchOpts)
  const data = await res.json().catch(() => ({}))
  if (!res.ok) throw new Error(data.message || 'Failed to load detailed attendance report')
  return data
}

/** All detailed rows for exports by paging fixed-size pages server-side. */
export async function fetchAllAdminReportsDetailedRows(params = {}) {
  const first = await getAdminReportsDetailed({ ...params, page: 1, per_page: 100 })
  const meta = first.meta || {}
  const lastPage = Math.max(1, Number(meta.last_page) || 1)
  const rows = [...(first.rows || [])]
  let page = 2
  while (page <= lastPage) {
    const chunk = await getAdminReportsDetailed({ ...params, page, per_page: 100 })
    rows.push(...(chunk.rows || []))
    page++
  }
  return rows
}

export async function fetchAllEmployeeReportsDetailedRows(params = {}) {
  const first = await getEmployeeReportsDetailed({ ...params, page: 1, per_page: 100 })
  const meta = first.meta || {}
  const lastPage = Math.max(1, Number(meta.last_page) || 1)
  const rows = [...(first.rows || [])]
  let page = 2
  while (page <= lastPage) {
    const chunk = await getEmployeeReportsDetailed({ ...params, page, per_page: 100 })
    rows.push(...(chunk.rows || []))
    page++
  }
  return rows
}

/**
 * Self-service detailed rows (plain employees only).
 * @param {{ from_date: string, to_date?: string }} params
 */
export async function getEmployeeReportsDetailed(params = {}, fetchOpts = {}) {
  const query = new URLSearchParams()
  if (params.from_date) query.set('from_date', params.from_date)
  if (params.to_date) query.set('to_date', params.to_date)
  const p = Number(params.page)
  query.set('page', String(Number.isFinite(p) && p >= 1 ? Math.floor(p) : 1))
  query.set('per_page', String(normalizeAttendancePerPage(params.per_page, REPORTS_AND_ATTENDANCE_PAGE_SIZE)))
  if (params.search) query.set('search', String(params.search).trim())

  const path = `/employee/reports/detailed${query.toString() ? `?${query.toString()}` : ''}`
  const res = await authenticatedFetch(path, fetchOpts)
  const data = await res.json().catch(() => ({}))
  if (!res.ok) throw new Error(data.message || 'Failed to load detailed attendance report')
  return data
}

/**
 * Admin daily computation logs (PH rules engine: holiday, rest day, OT, ND).
 * @param {{ from_date: string, to_date: string, search?: string, status?: string, company_id?: number, page?: number, per_page?: number }} params
 */
export async function getAdminDailyComputationLogs(params = {}) {
  const query = new URLSearchParams()
  if (params.from_date) query.set('from_date', params.from_date)
  if (params.to_date) query.set('to_date', params.to_date)
  if (params.start_date) query.set('start_date', params.start_date)
  if (params.end_date) query.set('end_date', params.end_date)
  if (!params.start_date && params.from_date) query.set('start_date', params.from_date)
  if (!params.end_date && params.to_date) query.set('end_date', params.to_date)
  if (params.search) query.set('search', String(params.search).trim())
  if (params.status && params.status !== 'all') query.set('status', params.status)
  if (params.company_id != null && params.company_id !== '') query.set('company_id', String(params.company_id))
  if (params.page != null) query.set('page', String(params.page))
  const logsPerPage = normalizePerPage(params.per_page)
  if (logsPerPage != null) query.set('per_page', String(logsPerPage))
  const path = `/admin/payroll/daily-logs${query.toString() ? `?${query.toString()}` : ''}`
  const res = await authenticatedFetch(path)
  const data = await res.json().catch(() => ({}))
  if (!res.ok) throw new Error(data.message || 'Failed to load daily computation logs')
  return data
}

/**
 * Paginated payroll periods for an employee (requires `payroll.view`).
 * @param {number|string} employeeId
 * @param {{ per_page?: number, page?: number, finalized_only?: boolean }} [opts]
 * @returns {Promise<{ data: any[], current_page?: number, last_page?: number, per_page?: number, total?: number }>}
 */
export async function getPayrollPeriodsForEmployee(employeeId, opts = {}) {
  const q = new URLSearchParams()
  q.set('employee_id', String(employeeId))
  const payrollPerPage = normalizePerPage(opts.per_page)
  if (payrollPerPage != null) q.set('per_page', String(payrollPerPage))
  if (opts.page != null) q.set('page', String(opts.page))
  if (opts.finalized_only === true) q.set('finalized_only', '1')
  const res = await authenticatedFetch(`/admin/payroll/periods?${q.toString()}`)
  const data = await res.json().catch(() => ({}))
  if (!res.ok) throw new Error(data.message || 'Failed to load payroll history')
  return data
}

// —— Payslips (PayrollComputationService + PayCycle integration) ——

/**
 * Salary-tab Payroll History for the logged-in employee.
 * Returns all finalized/published payslips regardless of send/delivery status.
 * @param {{ per_page?: number }} [opts]
 */
export async function getMySalaryHistory(opts = {}) {
  const q = new URLSearchParams()
  const perPage = normalizePerPage(opts.per_page)
  if (perPage != null) q.set('per_page', String(perPage))
  q.set('salary_history', '1')
  const res = await authenticatedFetch(`/employee/payslips${q.toString() ? `?${q}` : ''}`)
  const data = await res.json().catch(() => ({}))
  if (!res.ok) throw new Error(data.message || 'Failed to load payroll history')
  return data
}

/**
 * @param {{ per_page?: number, page?: number, from_date?: string, to_date?: string, status?: 'all'|'finalized'|'generated'|'emailed'|'sent_finalized'|'viewed' }} [opts]
 */
export async function getMyPayslips(opts = {}) {
  const q = new URLSearchParams()
  const myPayslipsPerPage = normalizePerPage(opts.per_page)
  if (myPayslipsPerPage != null) q.set('per_page', String(myPayslipsPerPage))
  if (opts.page != null) q.set('page', String(opts.page))
  if (opts.from_date) q.set('from_date', String(opts.from_date))
  if (opts.to_date) q.set('to_date', String(opts.to_date))
  if (opts.status && String(opts.status) !== 'all') q.set('status', String(opts.status))
  const res = await authenticatedFetch(`/employee/payslips${q.toString() ? `?${q}` : ''}`)
  const data = await res.json().catch(() => ({}))
  if (!res.ok) throw new Error(data.message || 'Failed to load payslips')
  return data
}

/** @param {number|string} id */
export async function getMyPayslip(id) {
  const res = await authenticatedFetch(`/employee/payslips/${encodeURIComponent(String(id))}`)
  const data = await res.json().catch(() => ({}))
  if (!res.ok) throw new Error(data.message || 'Failed to load payslip')
  return data
}

/**
 * PDF blob for preview/download (employee self-service).
 * @param {number|string} id
 * @returns {Promise<Blob>}
 */
export async function getMyPayslipPdfBlob(id) {
  const res = await authenticatedFetch(`/employee/payslips/${encodeURIComponent(String(id))}/pdf`)
  if (!res.ok) {
    const data = await res.json().catch(() => ({}))
    throw new Error(data.message || 'Failed to load payslip PDF')
  }
  return res.blob()
}

/**
 * Unified payslip download endpoint (self-service + HR/admin scope-aware checks in backend).
 * @param {number|string} id
 * @returns {Promise<Blob>}
 */
export async function getPayslipDownloadBlob(id) {
  const res = await authenticatedFetch(`/payslips/${encodeURIComponent(String(id))}/download`)
  if (!res.ok) {
    const data = await res.json().catch(() => ({}))
    throw new Error(data.message || 'Failed to download payslip PDF')
  }
  return res.blob()
}

/** Employee: full payslip preview JSON (stored snapshot). */
export async function getEmployeePayslipViewData(id) {
  const res = await authenticatedFetch(`/employee/payslips/${encodeURIComponent(String(id))}/data`, { timeoutMs: 60000 })
  const data = await res.json().catch(() => ({}))
  if (!res.ok) throw new Error(data.message || 'Failed to load payslip')
  return data
}

/**
 * HR finalize flow: mark payslips delivered (`delivered_at`) and status sent_finalized for My Payslips.
 * @param {{ payslip_ids: number[], company_id?: number, branch_id?: number, department_id?: number }} payload
 */
export async function adminDeliverFinalizePayslips(payload) {
  const res = await authenticatedFetch('/admin/payroll/finalize/deliver-payslips', {
    method: 'POST',
    headers: { 'Content-Type': 'application/json' },
    body: JSON.stringify(payload),
    timeoutMs: 120000,
  })
  const data = await res.json().catch(() => ({}))
  if (!res.ok) throw new Error(data.message || 'Failed to release payslips')
  return data
}

/**
 * Finalized batch: send payslips for all employees in the batch (not page-limited).
 * @param {number|string} batchRunId
 */
export async function adminBulkSendFinalizedBatchPayslips(batchRunId) {
  const res = await authenticatedFetch(`/admin/payroll-batches/${encodeURIComponent(String(batchRunId))}/bulk-send-payslips`, {
    method: 'POST',
    headers: { 'Content-Type': 'application/json' },
    timeoutMs: 180000,
  })
  const data = await res.json().catch(() => ({}))
  if (!res.ok) throw new Error(data.message || 'Failed to send batch payslips')
  return data
}

/**
 * Admin payslip list (Laravel admin role only).
 * @param {{ company_id?: number, branch_id?: number, department_id?: number, from_date?: string, to_date?: string, per_page?: number }} params
 */
export async function getAdminPayslips(params = {}) {
  const q = new URLSearchParams()
  if (params.company_id != null) q.set('company_id', String(params.company_id))
  if (params.branch_id != null) q.set('branch_id', String(params.branch_id))
  if (params.department_id != null) q.set('department_id', String(params.department_id))
  if (params.from_date) q.set('from_date', params.from_date)
  if (params.to_date) q.set('to_date', params.to_date)
  if (params.per_page != null) q.set('per_page', String(params.per_page))
  if (params.ids != null && params.ids !== '') q.set('ids', String(params.ids))
  const res = await authenticatedFetch(`/admin/payslips${q.toString() ? `?${q}` : ''}`)
  const data = await res.json().catch(() => ({}))
  if (!res.ok) throw new Error(data.message || 'Failed to load payslips')
  return data
}

/**
 * Admin: payslips aggregated by company + pay period (recent batch summary). Admin only.
 * @param {{ company_id?: number, branch_id?: number, department_id?: number, from_date?: string, to_date?: string, per_page?: number }} params
 */
export async function getAdminPayslipsRecentByCompany(params = {}) {
  const q = new URLSearchParams()
  if (params.company_id != null) q.set('company_id', String(params.company_id))
  if (params.branch_id != null) q.set('branch_id', String(params.branch_id))
  if (params.department_id != null) q.set('department_id', String(params.department_id))
  if (params.from_date) q.set('from_date', params.from_date)
  if (params.to_date) q.set('to_date', params.to_date)
  if (params.per_page != null) q.set('per_page', String(params.per_page))
  const res = await authenticatedFetch(`/admin/payslips/recent-by-company${q.toString() ? `?${q}` : ''}`)
  const data = await res.json().catch(() => ({}))
  if (!res.ok) throw new Error(data.message || 'Failed to load company payslip summary')
  return data
}

/** Admin: delete draft payslip batch (PayrollBatchRun + draft payslips). Finalized batches return 422. */
export async function adminDeletePayslipBatch(batchRunId) {
  const res = await authenticatedFetch(`/admin/payslips/batch/${encodeURIComponent(String(batchRunId))}`, {
    method: 'DELETE',
  })
  const data = await res.json().catch(() => ({}))
  if (!res.ok) throw new Error(data.message || 'Failed to delete draft batch')
  return data
}

/**
 * Bulk scope preview (same filters as bulk generate). Admin only.
 * @param {{ company_id?: number, branch_id?: number, department_id?: number }} params
 */
export async function getAdminPayslipPreviewScope(params = {}) {
  const q = new URLSearchParams()
  if (params.company_id != null && params.company_id !== '') q.set('company_id', String(params.company_id))
  if (params.branch_id != null && params.branch_id !== '') q.set('branch_id', String(params.branch_id))
  if (params.department_id != null && params.department_id !== '') q.set('department_id', String(params.department_id))
  const res = await authenticatedFetch(`/admin/payslips/preview-scope${q.toString() ? `?${q}` : ''}`)
  const data = await res.json().catch(() => ({}))
  if (!res.ok) throw new Error(data.message || 'Failed to load scope preview')
  return data
}

/**
 * Company default cut-off + pay-date calculator (Advanced section).
 * @param {{ company_id?: number, anchor_date?: string, pay_date?: string }} params
 * @returns {Promise<{ from_date: string|null, to_date: string|null, pay_date: string|null, reference_date: string|null, weekend_adjusted: boolean, weekend_adjustment_note?: string|null, cycle_label?: string|null }>}
 */
export async function getAdminCompanyDefaultPayslipDates(params = {}) {
  const q = new URLSearchParams()
  if (params.company_id != null && params.company_id !== '') q.set('company_id', String(params.company_id))
  if (params.anchor_date) q.set('anchor_date', params.anchor_date)
  if (params.pay_date) q.set('pay_date', params.pay_date)
  const res = await authenticatedFetch(`/admin/payslips/company-default-dates${q.toString() ? `?${q}` : ''}`)
  const data = await res.json().catch(() => ({}))
  if (!res.ok) throw new Error(data.message || 'Failed to calculate company default dates')
  return data
}

/**
 * @param {Record<string, unknown>} payload — employee_id or scope + period fields; see backend validation.
 */
/**
 * Admin: DRAFT finalize payroll preview (computed totals + rows; no DB writes).
 * @param {Record<string, unknown>} payload — scope + period fields (same family as bulk payslip generate).
 */
export async function adminPreviewFinalizePayroll(payload) {
  try {
    const res = await authenticatedFetch('/admin/payroll/finalize/preview', {
      method: 'POST',
      headers: { 'Content-Type': 'application/json' },
      body: JSON.stringify(payload),
      // Large scopes can exceed 20s; cap to avoid infinite spinner without feedback.
      timeoutMs: 120000,
    })
    const data = await res.json().catch(() => ({}))
    if (!res.ok) throw new Error(data.message || 'Failed to load finalize preview')
    return data
  } catch (err) {
    throw new Error(normalizeApiClientError(err, 'Failed to load finalize preview'))
  }
}

/**
 * Admin: finalize payroll (persist periods, payslip PDFs, lock, batch audit). Requires `review_confirmed: true`.
 * @param {Record<string, unknown>} payload
 */
export async function adminExecuteFinalizePayroll(payload) {
  try {
    const res = await authenticatedFetch('/admin/payroll/finalize/execute', {
      method: 'POST',
      headers: { 'Content-Type': 'application/json' },
      body: JSON.stringify(payload),
      timeoutMs: 90000,
    })
    const data = await res.json().catch(() => ({}))
    if (!res.ok) throw new Error(data.message || 'Failed to finalize payroll')
    return data
  } catch (err) {
    throw new Error(normalizeApiClientError(err, 'Failed to finalize payroll'))
  }
}

/**
 * Admin: finalize one employee in the current scope + pay window (sync). Requires `confirm: true`.
 * @param {Record<string, unknown>} payload
 */
export async function adminFinalizeEmployeePayslip(payload) {
  try {
    const res = await authenticatedFetch('/admin/payroll/finalize/employee', {
      method: 'POST',
      headers: { 'Content-Type': 'application/json' },
      body: JSON.stringify(payload),
      timeoutMs: 90000,
    })
    const data = await res.json().catch(() => ({}))
    if (!res.ok) throw new Error(data.message || 'Failed to finalize employee payslip')
    return data
  } catch (err) {
    throw new Error(normalizeApiClientError(err, 'Failed to finalize employee payslip'))
  }
}

export async function adminFinalizePayrollStatus(batchRunId) {
  const res = await authenticatedFetch(`/admin/payroll/finalize/status/${encodeURIComponent(String(batchRunId))}`, {
    timeoutMs: 20000,
  })
  const data = await res.json().catch(() => ({}))
  if (!res.ok) throw new Error(data.message || 'Failed to load finalize status')
  return data
}

/**
 * Admin/HR: void a finalized payroll batch (preserves snapshots; does not revert to draft).
 */
export async function adminDeleteFinalizedPayrollBatch(batchRunId) {
  const res = await authenticatedFetch(`/admin/payroll/finalize/batch/${encodeURIComponent(String(batchRunId))}`, {
    method: 'DELETE',
    timeoutMs: 90000,
  })
  const data = await res.json().catch(() => ({}))
  if (!res.ok) throw new Error(data.message || 'Failed to delete finalized payroll batch')
  return data
}

export async function adminGeneratePayslips(payload) {
  const res = await authenticatedFetch('/admin/payslips/generate', {
    method: 'POST',
    headers: { 'Content-Type': 'application/json' },
    body: JSON.stringify(payload),
    // Bulk generation returns immediately after the Redis payroll job is queued.
    timeoutMs: 30000,
  })
  const data = await res.json().catch(() => ({}))
  if (!res.ok) throw new Error(data.message || 'Failed to generate payslips')
  return data
}

/**
 * Admin: lightweight sample preview (JSON only; no PDF). Cached briefly server-side.
 * @param {Record<string, unknown>} payload
 */
export async function adminPreviewPayslipSampleData(payload = {}) {
  const res = await authenticatedFetch('/admin/payslips/preview-sample-data', {
    method: 'POST',
    headers: { 'Content-Type': 'application/json' },
    body: JSON.stringify(payload),
    timeoutMs: 20000,
  })
  const data = await res.json().catch(() => ({}))
  if (!res.ok) throw new Error(data.message || 'Failed to load sample preview')
  return data
}

/** Admin: stored payslip preview payload (no recomputation). */
export async function getAdminPayslipData(id) {
  const res = await authenticatedFetch(`/admin/payslips/${encodeURIComponent(String(id))}/data`)
  const data = await res.json().catch(() => ({}))
  if (!res.ok) throw new Error(data.message || 'Failed to load payslip preview')
  return data
}

/** Admin: full payslip view payload for dedicated module page. */
export async function getAdminPayslipViewData(id) {
  const res = await authenticatedFetch(`/admin/payslips/${encodeURIComponent(String(id))}/view`)
  const data = await res.json().catch(() => ({}))
  if (!res.ok) throw new Error(data.message || 'Failed to load payslip view')
  return data
}

/** Admin: full payslip preview data when stored payslip is not yet available. */
export async function getAdminPayslipViewPreviewData(payload = {}) {
  const res = await authenticatedFetch('/admin/payslips/view-preview-data', {
    method: 'POST',
    headers: { 'Content-Type': 'application/json' },
    body: JSON.stringify(payload),
  })
  const data = await res.json().catch(() => ({}))
  if (!res.ok) throw new Error(data.message || 'Failed to load computed payslip preview')
  return data
}

/**
 * Admin: one sample PDF for the selected scope + period (same engine as bulk; no DB row). Password optional via header.
 * @param {Record<string, unknown>} payload — same scope/period keys as {@link adminGeneratePayslips} (no employee_id).
 * @returns {Promise<{ blob: Blob, pdfPassword: string | null }>}
 */
export async function adminPreviewPayslipSampleBlob(payload = {}) {
  const res = await authenticatedFetch('/admin/payslips/preview-sample', {
    method: 'POST',
    headers: { 'Content-Type': 'application/json' },
    body: JSON.stringify(payload),
  })
  if (!res.ok) {
    const data = await res.json().catch(() => ({}))
    throw new Error(data.message || 'Failed to generate sample preview')
  }
  const blob = await res.blob()
  const raw = res.headers.get('X-Payslip-Pdf-Password')
  const pdfPassword = raw != null && String(raw).trim() !== '' ? String(raw).trim() : null
  return { blob, pdfPassword }
}

/**
 * Admin: payslip PDF preview for a specific employee — no DB write. Password optional via header.
 * @param {Record<string, unknown>} payload — must include employee_id + period fields
 * @returns {Promise<{ blob: Blob, pdfPassword: string | null }>}
 */
export async function adminPreviewPayslipEmployeeBlob(payload = {}) {
  const res = await authenticatedFetch('/admin/payslips/preview-employee', {
    method: 'POST',
    headers: { 'Content-Type': 'application/json' },
    body: JSON.stringify(payload),
  })
  if (!res.ok) {
    const data = await res.json().catch(() => ({}))
    throw new Error(data.message || 'Failed to generate payslip preview')
  }
  const blob = await res.blob()
  const raw = res.headers.get('X-Payslip-Pdf-Password')
  const pdfPassword = raw != null && String(raw).trim() !== '' ? String(raw).trim() : null
  return { blob, pdfPassword }
}

/**
 * Admin: structured payslip preview data for a specific employee (same computation as PDF preview).
 * @param {Record<string, unknown>} payload
 * @returns {Promise<Record<string, unknown>>}
 */
export async function adminPreviewPayslipEmployeeData(payload = {}) {
  const res = await authenticatedFetch('/admin/payslips/preview-employee-data', {
    method: 'POST',
    headers: { 'Content-Type': 'application/json' },
    body: JSON.stringify(payload),
  })
  const data = await res.json().catch(() => ({}))
  if (!res.ok) throw new Error(data.message || 'Failed to load payslip preview data')
  return data
}

/** @param {number[]} payslipIds */
export async function adminDownloadPayslipsZip(payslipIds) {
  const res = await authenticatedFetch('/admin/payslips/zip', {
    method: 'POST',
    headers: { 'Content-Type': 'application/json' },
    body: JSON.stringify({ payslip_ids: payslipIds }),
  })
  if (!res.ok) {
    const data = await res.json().catch(() => ({}))
    throw new Error(data.message || 'Failed to build ZIP')
  }
  return res.blob()
}

/**
 * Queue bulk payslip ZIP on payslip-pdf Redis queue (instant HTTP response).
 * @param {number|string} batchRunId
 * @param {{ forceRegenerate?: boolean, employeeIds?: number[] }} [options]
 * @returns {Promise<{ request_id: number, message: string, bulk_download: Record<string, unknown> }>}
 */
export async function adminQueueBulkPayslipDownload(batchRunId, options = {}) {
  const res = await authenticatedFetch(
    `/admin/payroll-batches/${encodeURIComponent(String(batchRunId))}/bulk-download-pdf`,
    {
      method: 'POST',
      headers: { 'Content-Type': 'application/json', Accept: 'application/json' },
      body: JSON.stringify({
        force_regenerate: Boolean(options.forceRegenerate),
        employee_ids: Array.isArray(options.employeeIds) ? options.employeeIds : undefined,
      }),
      timeoutMs: 15000,
    }
  )
  const data = await res.json().catch(() => ({}))
  if (!res.ok) {
    throw new Error(data.message || 'Failed to queue bulk payslip download')
  }
  return data
}

/** @param {number|string} requestId */
export async function adminPayslipBulkDownloadStatus(requestId) {
  const res = await authenticatedFetch(
    `/admin/payslip-bulk-downloads/${encodeURIComponent(String(requestId))}/status`,
    { timeoutMs: 15000 }
  )
  const data = await res.json().catch(() => ({}))
  if (!res.ok) {
    throw new Error(data.message || 'Failed to load bulk download status')
  }
  return data
}

/**
 * Download completed bulk ZIP file.
 * @param {number|string} requestId
 * @returns {Promise<Blob>}
 */
export async function adminDownloadPayslipBulkZipFile(requestId) {
  const res = await authenticatedFetch(
    `/admin/payslip-bulk-downloads/${encodeURIComponent(String(requestId))}/download`,
    { timeoutMs: 120000 }
  )
  if (!res.ok) {
    const data = await res.json().catch(() => ({}))
    throw new Error(data.message || 'Failed to download bulk ZIP')
  }
  return res.blob()
}

export async function getPayrollRunCompanyPayrollReportPdfBlob(batchRunId, companyId) {
  const res = await authenticatedFetch(
    `/payroll-runs/${encodeURIComponent(String(batchRunId))}/company/${encodeURIComponent(String(companyId))}/payroll-report/pdf`,
    { timeoutMs: 120000 }
  )
  if (!res.ok) {
    const data = await res.json().catch(() => ({}))
    throw new Error(data.message || 'Failed to download Payroll Report PDF')
  }
  return res.blob()
}

// ——— EXECOM payroll & employee management ———

function execomQueryString(params = {}) {
  const q = new URLSearchParams()
  Object.entries(params).forEach(([key, value]) => {
    if (value !== undefined && value !== null && value !== '') q.set(key, String(value))
  })
  const s = q.toString()
  return s ? `?${s}` : ''
}

export async function getExecomEmployees(params = {}) {
  const res = await authenticatedFetch(`/admin/execom/employees${execomQueryString(params)}`)
  const data = await res.json().catch(() => ({}))
  if (!res.ok) throw new Error(data.message || 'Failed to load EXECOM employees')
  return data
}

export async function createExecomEmployee(payload) {
  const res = await authenticatedFetch('/admin/execom/employees', {
    method: 'POST',
    headers: { 'Content-Type': 'application/json' },
    body: JSON.stringify(payload),
  })
  const data = await res.json().catch(() => ({}))
  if (!res.ok) throw new Error(data.message || 'Failed to add EXECOM employee')
  return data
}

export async function updateExecomEmployee(id, payload) {
  const res = await authenticatedFetch(`/admin/execom/employees/${encodeURIComponent(String(id))}`, {
    method: 'PATCH',
    headers: { 'Content-Type': 'application/json' },
    body: JSON.stringify(payload),
  })
  const data = await res.json().catch(() => ({}))
  if (!res.ok) throw new Error(data.message || 'Failed to update EXECOM employee')
  return data
}

export async function deleteExecomEmployee(id) {
  const res = await authenticatedFetch(`/admin/execom/employees/${encodeURIComponent(String(id))}`, {
    method: 'DELETE',
  })
  const data = await res.json().catch(() => ({}))
  if (!res.ok) throw new Error(data.message || 'Failed to remove EXECOM employee')
  return data
}

export async function getExecomPayrollSettings() {
  const res = await authenticatedFetch('/admin/execom/payroll-settings')
  const data = await res.json().catch(() => ({}))
  if (!res.ok) throw new Error(data.message || 'Failed to load EXECOM payroll settings')
  return data
}

export async function updateExecomPayrollSettings(payload) {
  const res = await authenticatedFetch('/admin/execom/payroll-settings', {
    method: 'PUT',
    headers: { 'Content-Type': 'application/json' },
    body: JSON.stringify(payload),
  })
  const data = await res.json().catch(() => ({}))
  if (!res.ok) throw new Error(data.message || 'Failed to update EXECOM payroll settings')
  return data
}

export async function adminGenerateExecomPayroll(payload) {
  const res = await authenticatedFetch('/admin/execom/payroll/generate', {
    method: 'POST',
    headers: { 'Content-Type': 'application/json' },
    body: JSON.stringify(payload),
    timeoutMs: 30000,
  })
  const data = await res.json().catch(() => ({}))
  if (!res.ok) throw new Error(data.message || 'Failed to generate EXECOM payroll draft')
  return data
}

export async function adminRecomputeExecomPayroll(batchRunId) {
  const res = await authenticatedFetch(
    `/admin/execom/payroll/batches/${encodeURIComponent(String(batchRunId))}/recompute`,
    { method: 'POST', timeoutMs: 30000 }
  )
  const data = await res.json().catch(() => ({}))
  if (!res.ok) throw new Error(data.message || 'Failed to recompute EXECOM payroll draft')
  return data
}

export async function adminFinalizeExecomPayroll(batchRunId) {
  const res = await authenticatedFetch(
    `/admin/execom/payroll/batches/${encodeURIComponent(String(batchRunId))}/finalize`,
    { method: 'POST', timeoutMs: 30000 }
  )
  const data = await res.json().catch(() => ({}))
  if (!res.ok) throw new Error(data.message || 'Failed to finalize EXECOM payroll')
  return data
}

export async function getExecomPayrollBatchStatus(batchRunId) {
  const res = await authenticatedFetch(
    `/admin/execom/payroll/batches/${encodeURIComponent(String(batchRunId))}/status`
  )
  const data = await res.json().catch(() => ({}))
  if (!res.ok) throw new Error(data.message || 'Failed to load EXECOM batch status')
  return data
}

export async function getExecomPayrollBatches(params = {}) {
  const res = await authenticatedFetch(`/admin/execom/payroll/batches${execomQueryString(params)}`)
  const data = await res.json().catch(() => ({}))
  if (!res.ok) throw new Error(data.message || 'Failed to load EXECOM payroll batches')
  return data
}

export async function getExecomPayrollPayslips(params = {}) {
  const status = String(params.status || 'draft').toLowerCase() === 'finalized' ? 'finalized' : 'draft'
  const path = status === 'finalized' ? '/admin/execom/payroll/finalized' : '/admin/execom/payroll/draft'
  const q = { ...params }
  delete q.status
  const res = await authenticatedFetch(`${path}${execomQueryString(q)}`)
  const data = await res.json().catch(() => ({}))
  if (!res.ok) throw new Error(data.message || 'Failed to load EXECOM payslips')
  return data
}

export async function getExecomPayrollReportPdfBlob(batchRunId, companyId = null) {
  const params = new URLSearchParams()
  if (companyId != null && companyId !== '') {
    params.set('company_id', String(companyId))
  }
  const query = params.toString()
  const res = await authenticatedFetch(
    `/admin/execom/payroll/batches/${encodeURIComponent(String(batchRunId))}/report/pdf${query ? `?${query}` : ''}`,
    { timeoutMs: 120000 }
  )
  if (!res.ok) {
    const data = await res.json().catch(() => ({}))
    throw new Error(data.message || 'Failed to download EXECOM payroll report PDF')
  }
  return res.blob()
}

export async function getReportsPayrollReportPdfBlob(params = {}) {
  const q = new URLSearchParams()
  if (params.company_id != null) q.set('company_id', String(params.company_id))
  if (params.payroll_run_id != null) q.set('payroll_run_id', String(params.payroll_run_id))
  if (params.pay_period_id != null) q.set('pay_period_id', String(params.pay_period_id))
  const res = await authenticatedFetch(`/reports/payroll-report${q.toString() ? `?${q}` : ''}`, {
    timeoutMs: 120000,
  })
  if (!res.ok) {
    const data = await res.json().catch(() => ({}))
    throw new Error(data.message || 'Failed to download Payroll Report PDF')
  }
  return res.blob()
}

const BULK_PAYSLIP_POLL_MS = 2500

/**
 * Poll bulk download until ready, then return ZIP blob.
 * @param {number|string} requestId
 * @param {{ onProgress?: (status: Record<string, unknown>) => void, signal?: AbortSignal }} [options]
 * @returns {Promise<{ blob: Blob, bulk_download: Record<string, unknown> }>}
 */
export async function adminPollAndDownloadBulkPayslipZip(requestId, options = {}) {
  const { onProgress, signal } = options
  const sleep = (ms) =>
    new Promise((resolve, reject) => {
      const t = setTimeout(resolve, ms)
      signal?.addEventListener(
        'abort',
        () => {
          clearTimeout(t)
          reject(new DOMException('Aborted', 'AbortError'))
        },
        { once: true }
      )
    })

  while (true) {
    if (signal?.aborted) {
      throw new DOMException('Aborted', 'AbortError')
    }
    const data = await adminPayslipBulkDownloadStatus(requestId)
    const bulk = data?.bulk_download ?? data
    onProgress?.(bulk)
    const status = String(bulk?.status || '').toLowerCase()
    if (status === 'completed' && bulk?.ready) {
      const blob = await adminDownloadPayslipBulkZipFile(requestId)
      return { blob, bulk_download: bulk }
    }
    if (status === 'failed') {
      throw new Error(bulk?.error_message || 'Bulk payslip download failed. Please try again.')
    }
    await sleep(BULK_PAYSLIP_POLL_MS)
  }
}

/** @deprecated Use adminQueueBulkPayslipDownload + adminPollAndDownloadBulkPayslipZip */
export async function adminBulkDownloadPayrollBatchPdfZip(batchRunId, options = {}) {
  const queued = await adminQueueBulkPayslipDownload(batchRunId, options)
  const requestId = Number(queued?.request_id ?? queued?.bulk_download?.id ?? 0)
  if (!requestId) {
    throw new Error('Server did not return a bulk download request id.')
  }
  const { blob } = await adminPollAndDownloadBulkPayslipZip(requestId)
  return blob
}

/** @param {number|string} id */
export async function getAdminPayslipPdfBlob(id) {
  const res = await authenticatedFetch(`/admin/payslips/${encodeURIComponent(String(id))}/pdf`)
  if (!res.ok) {
    const data = await res.json().catch(() => ({}))
    throw new Error(data.message || 'Failed to load payslip PDF')
  }
  return res.blob()
}

/**
 * PH payroll policy snapshot: multipliers, first-8h / OT matrices, ND window, module map (Labor Code Arts. 87, 93, 94).
 * @returns {Promise<Record<string, unknown>>}
 */
export async function getAdminPayrollPolicyReference() {
  const res = await authenticatedFetch('/admin/payroll/policy-reference')
  const data = await res.json().catch(() => ({}))
  if (!res.ok) throw new Error(data.message || 'Failed to load payroll policy reference')
  return data
}

// —— Admin: Pay Policies ——

export async function getPayPolicies(params = {}) {
  const query = new URLSearchParams()
  if (params.company_id != null) query.set('company_id', String(params.company_id))
  if (params.status && params.status !== 'all') query.set('status', params.status)
  if (params.per_page != null) query.set('per_page', String(params.per_page))
  const path = `/admin/payroll/policies${query.toString() ? `?${query.toString()}` : ''}`
  const res = await authenticatedFetch(path)
  const data = await res.json().catch(() => ({}))
  if (!res.ok) throw new Error(data.message || 'Failed to load policies')
  return data
}

export async function getPayPolicy(id) {
  const res = await authenticatedFetch(`/admin/payroll/policies/${id}`)
  const data = await res.json().catch(() => ({}))
  if (!res.ok) throw new Error(data.message || 'Failed to load policy')
  return data
}

export async function createPayPolicy(payload) {
  const res = await authenticatedFetch('/admin/payroll/policies', {
    method: 'POST',
    headers: { 'Content-Type': 'application/json' },
    body: JSON.stringify(payload),
  })
  const data = await res.json().catch(() => ({}))
  if (!res.ok) throw new Error(data.message || 'Failed to create policy')
  return data
}

export async function updatePayPolicy(id, payload) {
  const res = await authenticatedFetch(`/admin/payroll/policies/${id}`, {
    method: 'PUT',
    headers: { 'Content-Type': 'application/json' },
    body: JSON.stringify(payload),
  })
  const data = await res.json().catch(() => ({}))
  if (!res.ok) throw new Error(data.message || 'Failed to update policy')
  return data
}

export async function duplicatePayPolicy(id, payload) {
  const res = await authenticatedFetch(`/admin/payroll/policies/${id}/duplicate`, {
    method: 'POST',
    headers: { 'Content-Type': 'application/json' },
    body: JSON.stringify(payload),
  })
  const data = await res.json().catch(() => ({}))
  if (!res.ok) throw new Error(data.message || 'Failed to duplicate policy')
  return data
}

export async function deletePayPolicy(id) {
  const res = await authenticatedFetch(`/admin/payroll/policies/${id}`, {
    method: 'DELETE',
  })
  const data = await res.json().catch(() => ({}))
  if (!res.ok) throw new Error(data.message || 'Failed to delete policy')
  return data
}

/** Policy formula preview is cheap server-side; allow longer deadline when DB is under load. */
const PAY_POLICY_PREVIEW_TIMEOUT_MS = 90000

/**
 * @param {Record<string, unknown>} params
 * @param {RequestInit & { timeoutMs?: number }} [fetchOpts] - optional `signal` to cancel; `timeoutMs` default 90s
 */
export async function getPayPolicyPreview(params, fetchOpts = {}) {
  const query = new URLSearchParams()
  if (params.rule_code) query.set('rule_code', params.rule_code)
  if (params.hourly_rate != null) query.set('hourly_rate', String(params.hourly_rate))
  if (params.policy_id != null) query.set('policy_id', String(params.policy_id))
  if (params.company_id != null) query.set('company_id', String(params.company_id))
  if (params.date) query.set('date', params.date)
  const path = `/admin/payroll/policies/preview${query.toString() ? `?${query.toString()}` : ''}`
  const { timeoutMs = PAY_POLICY_PREVIEW_TIMEOUT_MS, ...rest } = fetchOpts
  const res = await authenticatedFetch(path, { ...rest, timeoutMs })
  const data = await res.json().catch(() => ({}))
  if (!res.ok) throw new Error(data.message || 'Failed to load preview')
  return data
}

export async function getPayPolicyCompanies() {
  const res = await authenticatedFetch('/admin/payroll/policies/companies')
  const data = await res.json().catch(() => ({}))
  if (!res.ok) throw new Error(data.message || 'Failed to load companies')
  return data
}

export async function getPayPolicyConditionKeys() {
  const res = await authenticatedFetch('/admin/payroll/policies/condition-keys')
  const data = await res.json().catch(() => ({}))
  if (!res.ok) throw new Error(data.message || 'Failed to load condition keys')
  return data
}

// —— Admin: Government Contributions (SSS/PhilHealth/Pag-IBIG/EC) ——

export async function getStatutoryRates(params = {}) {
  const query = new URLSearchParams()
  if (params.company_id != null && params.company_id !== '') query.set('company_id', String(params.company_id))
  const path = `/admin/payroll/statutory-rates${query.toString() ? `?${query.toString()}` : ''}`
  const res = await authenticatedFetch(path)
  const data = await res.json().catch(() => ({}))
  if (!res.ok) throw new Error(data.message || 'Failed to load statutory rates')
  return data
}

export async function getStatutoryRateHistory(params = {}) {
  const query = new URLSearchParams()
  if (params.code) query.set('code', String(params.code).toUpperCase())
  if (params.company_id != null && params.company_id !== '') query.set('company_id', String(params.company_id))
  if (params.page != null) query.set('page', String(params.page))
  if (params.per_page != null) query.set('per_page', String(params.per_page))
  const path = `/admin/payroll/statutory-rates/history${query.toString() ? `?${query.toString()}` : ''}`
  const res = await authenticatedFetch(path)
  const data = await res.json().catch(() => ({}))
  if (!res.ok) throw new Error(data.message || 'Failed to load statutory rates history')
  return data
}

export async function upsertStatutoryRate(code, payload) {
  const res = await authenticatedFetch(`/admin/payroll/statutory-rates/${encodeURIComponent(code)}`, {
    method: 'PUT',
    body: JSON.stringify(payload),
  })
  const data = await res.json().catch(() => ({}))
  if (!res.ok) throw new Error(data.message || 'Failed to save statutory rate')
  return data
}

export async function calculateStatutoryContributions(payload) {
  const res = await authenticatedFetch('/admin/payroll/statutory/calculate', {
    method: 'POST',
    body: JSON.stringify(payload),
  })
  const data = await res.json().catch(() => ({}))
  if (!res.ok) throw new Error(data.message || 'Failed to calculate statutory contributions')
  return data
}

/** BIR withholding preview (TRAIN annual table, annualized monthly spread). */
export async function previewWithholdingTax(payload) {
  const res = await authenticatedFetch('/admin/payroll/withholding-tax/preview', {
    method: 'POST',
    body: JSON.stringify(payload),
  })
  const data = await res.json().catch(() => ({}))
  if (!res.ok) throw new Error(data.message || 'Failed to compute withholding tax preview')
  return data
}

/** Classify earnings lines into taxable vs non-taxable totals. */
export async function classifyTaxEarnings(payload) {
  const res = await authenticatedFetch('/admin/payroll/withholding-tax/classify-earnings', {
    method: 'POST',
    body: JSON.stringify(payload),
  })
  const data = await res.json().catch(() => ({}))
  if (!res.ok) throw new Error(data.message || 'Failed to classify earnings')
  return data
}

/** Year-end tax balancing: annual tax due vs withholding YTD. */
export async function previewYearEndTaxAdjustment(payload) {
  const res = await authenticatedFetch('/admin/payroll/withholding-tax/year-end-adjustment', {
    method: 'POST',
    body: JSON.stringify(payload),
  })
  const data = await res.json().catch(() => ({}))
  if (!res.ok) throw new Error(data.message || 'Failed to compute year-end adjustment')
  return data
}

/** Retroactive recalculation guidance (requires payroll ledger for exact deltas). */
export async function previewRetroactiveTax(payload) {
  const res = await authenticatedFetch('/admin/payroll/withholding-tax/retroactive-preview', {
    method: 'POST',
    body: JSON.stringify(payload),
  })
  const data = await res.json().catch(() => ({}))
  if (!res.ok) throw new Error(data.message || 'Failed to load retroactive tax preview')
  return data
}

/** Stored BIR/TRAIN tax table rows (audit). */
export async function getTaxTables(params = {}) {
  const q = new URLSearchParams()
  if (params.year != null) q.set('year', String(params.year))
  const path = `/admin/payroll/tax-tables${q.toString() ? `?${q}` : ''}`
  const res = await authenticatedFetch(path)
  const data = await res.json().catch(() => ({}))
  if (!res.ok) throw new Error(data.message || 'Failed to load tax tables')
  return data
}

export async function getEmployeeTaxProfile(employeeId) {
  const res = await authenticatedFetch(`/admin/employees/${employeeId}/tax-profile`)
  const data = await res.json().catch(() => ({}))
  if (!res.ok) throw new Error(data.message || 'Failed to load employee tax profile')
  return data
}

export async function upsertEmployeeTaxProfile(employeeId, payload) {
  const res = await authenticatedFetch(`/admin/employees/${employeeId}/tax-profile`, {
    method: 'PUT',
    body: JSON.stringify(payload),
  })
  const data = await res.json().catch(() => ({}))
  if (!res.ok) throw new Error(data.message || 'Failed to save employee tax profile')
  return data
}

/** Estimated org-wide statutory + WHT; pending remittance batch counts (Government Deductions hub). */
export async function getStatutoryDashboardSummary(params = {}) {
  const query = new URLSearchParams()
  if (params.company_id != null && params.company_id !== '') query.set('company_id', String(params.company_id))
  const path = `/admin/payroll/statutory/dashboard-summary${query.toString() ? `?${query.toString()}` : ''}`
  const res = await authenticatedFetch(path)
  const data = await res.json().catch(() => ({}))
  if (!res.ok) throw new Error(data.message || 'Failed to load statutory dashboard summary')
  return data
}

export async function listStatutoryRemittances(params = {}) {
  const query = new URLSearchParams()
  if (params.agency) query.set('agency', String(params.agency))
  if (params.status) query.set('status', String(params.status))
  if (params.year != null) query.set('year', String(params.year))
  if (params.month != null) query.set('month', String(params.month))
  if (params.company_id != null) query.set('company_id', String(params.company_id))
  if (params.page != null) query.set('page', String(params.page))
  if (params.per_page != null) query.set('per_page', String(params.per_page))
  const path = `/admin/payroll/remittances${query.toString() ? `?${query.toString()}` : ''}`
  const res = await authenticatedFetch(path)
  const data = await res.json().catch(() => ({}))
  if (!res.ok) throw new Error(data.message || 'Failed to load remittances')
  return data
}

export async function generateStatutoryRemittance(payload) {
  const res = await authenticatedFetch('/admin/payroll/remittances/generate', {
    method: 'POST',
    body: JSON.stringify(payload),
  })
  const data = await res.json().catch(() => ({}))
  if (!res.ok) throw new Error(data.message || 'Failed to generate remittance batch')
  return data
}

export async function getEmployeeStatutoryContributions(employeeId) {
  const res = await authenticatedFetch(`/admin/employees/${employeeId}/statutory-contributions`)
  const data = await res.json().catch(() => ({}))
  if (!res.ok) throw new Error(data.message || 'Failed to load contribution history')
  return data
}

export async function getMyContributions() {
  const res = await authenticatedFetch('/employee/contributions')
  const data = await res.json().catch(() => ({}))
  if (!res.ok) throw new Error(data.message || 'Failed to load your contributions')
  return data
}

// —— Admin: Working Schedules ——

/**
 * @returns {Promise<{ schedules: Array<{ id: number, name: string, time_in: string, break_start?: string|null, break_end?: string|null, time_out: string, grace_period_minutes: number, rest_days: string[] }> }>}
 */
export async function getWorkingSchedules() {
  return cachedAuthenticatedGetJson('/admin/schedules', { ttlMs: 5 * 60 * 1000 })
}

/**
 * @param {{ name: string, time_in: string, break_start?: string|null, break_end?: string|null, time_out: string, grace_period_minutes?: number, rest_days?: string[] }} payload
 */
export async function createWorkingSchedule(payload) {
  const res = await authenticatedFetch('/admin/schedules', {
    method: 'POST',
    body: JSON.stringify(payload),
  })
  const data = await res.json().catch(() => ({}))
  if (!res.ok) throw new Error(data.message || 'Failed to create schedule')
  return data
}

/**
 * @param {number} id
 * @param {Partial<{ name: string, time_in: string, break_start?: string|null, break_end?: string|null, time_out: string, grace_period_minutes?: number, rest_days?: string[] }>} payload
 */
export async function updateWorkingSchedule(id, payload) {
  const res = await authenticatedFetch(`/admin/schedules/${id}`, {
    method: 'PATCH',
    body: JSON.stringify(payload),
  })
  const data = await res.json().catch(() => ({}))
  if (!res.ok) throw new Error(data.message || 'Failed to update schedule')
  return data
}

/**
 * @param {number} id
 */
export async function deleteWorkingSchedule(id) {
  const res = await authenticatedFetch(`/admin/schedules/${id}`, {
    method: 'DELETE',
  })
  const data = await res.json().catch(() => ({}))
  if (!res.ok) throw new Error(data.message || 'Failed to delete schedule')
  return data
}

/**
 * Assign a schedule to a set of employees.
 * @param {number} id
 * @param {{ employee_ids: number[] }} payload
 * @returns {Promise<{ message: string, assigned_count?: number, conflicts?: Array<{ employee_id: number, employee_name: string, current_schedule: string, current_time: string }> }>}
 */
export async function assignWorkingSchedule(id, payload) {
  const res = await authenticatedFetch(`/admin/schedules/${id}/assign`, {
    method: 'POST',
    body: JSON.stringify(payload),
  })
  const data = await res.json().catch(() => ({}))
  if (!res.ok) {
    const err = new Error(data.message || 'Failed to assign schedule')
    err.conflicts = data.conflicts
    err.status = res.status
    throw err
  }
  return data
}

export async function getMySchedule() {
  const res = await authenticatedFetch('/my-schedule')
  const data = await res.json().catch(() => ({}))
  if (!res.ok) throw new Error(data.message || 'Failed to load your schedule')
  return data
}

export async function getMyScheduleRequestContext() {
  const res = await authenticatedFetch('/my-schedule/request-context')
  const data = await res.json().catch(() => ({}))
  if (!res.ok) throw new Error(data.message || 'Failed to load schedule request context')
  return data
}

export async function createMyScheduleRequest(payload) {
  const res = await authenticatedFetch('/my-schedule/requests', {
    method: 'POST',
    body: JSON.stringify(payload),
  })
  const data = await res.json().catch(() => ({}))
  if (!res.ok) throw new Error(firstValidationMessage(data) || data.message || 'Failed to submit schedule request')
  return data
}

export async function deleteMyScheduleRequest(id) {
  const res = await authenticatedFetch(`/my-schedule/requests/${id}`, { method: 'DELETE' })
  const data = await res.json().catch(() => ({}))
  if (!res.ok) throw new Error(firstValidationMessage(data) || data.message || 'Failed to delete schedule request')
  return data
}

export async function getAdminScheduleRequests(params = {}) {
  const query = new URLSearchParams()
  if (params.status) query.set('status', String(params.status))
  const res = await authenticatedFetch(`/admin/schedule-requests${query.toString() ? `?${query.toString()}` : ''}`)
  const data = await res.json().catch(() => ({}))
  if (!res.ok) throw new Error(data.message || 'Failed to load schedule requests')
  return data
}

export async function getAdminScheduleRequestDetail(id) {
  const res = await authenticatedFetch(`/admin/schedule-requests/${id}`)
  const data = await res.json().catch(() => ({}))
  if (!res.ok) throw new Error(data.message || 'Failed to load schedule request')
  return data
}

export async function approveScheduleRequest(id, payload = {}) {
  const res = await authenticatedFetch(`/admin/schedule-requests/${id}/approve`, {
    method: 'POST',
    body: JSON.stringify(payload),
  })
  const data = await res.json().catch(() => ({}))
  if (!res.ok) throw new Error(firstValidationMessage(data) || data.message || 'Failed to approve schedule request')
  return data
}

export async function rejectScheduleRequest(id, payload) {
  const res = await authenticatedFetch(`/admin/schedule-requests/${id}/reject`, {
    method: 'POST',
    body: JSON.stringify(payload),
  })
  const data = await res.json().catch(() => ({}))
  if (!res.ok) throw new Error(firstValidationMessage(data) || data.message || 'Failed to reject schedule request')
  return data
}

export async function deleteAdminScheduleRequest(id) {
  const res = await authenticatedFetch(`/admin/schedule-requests/${id}`, { method: 'DELETE' })
  const data = await res.json().catch(() => ({}))
  if (!res.ok) throw new Error(firstValidationMessage(data) || data.message || 'Failed to delete schedule request')
  return data
}

// —— Admin: Employees ——

/**
 * Get employees list (admin) with optional simple pagination.
 * @param {{ page?: number, per_page?: number, for_schedule_assignment?: boolean, active_filter?: 'active'|'deactivated'|'all' }} [params]
 *   - for_schedule_assignment: true → returns all employees (no pagination, for Assign Schedule modal).
 * @returns {Promise<{ employees: Array<{ id: number, name: string, email: string, role: string, department?: string|null, schedule: object|null, working_schedule_id?: number|null, is_active: boolean, created_at: string }>, meta?: { current_page: number, per_page: number, total: number, last_page: number } }>}
 */
export async function getEmployees(params = {}) {
  const query = new URLSearchParams()
  // Keep employee list payloads small by default; callers can pass lite:false when they truly need full rows.
  if (params.lite !== false) query.set('lite', '1')
  if (params.page) query.set('page', String(params.page))
  const employeesPerPage = normalizePerPage(params.per_page)
  if (employeesPerPage != null) query.set('per_page', String(employeesPerPage))
  if (params.for_schedule_assignment) query.set('for_schedule_assignment', '1')
  if (params.active_filter) query.set('active_filter', String(params.active_filter))
  if (params.q) query.set('q', String(params.q))
  if (params.company_id != null && params.company_id !== '') query.set('company_id', String(params.company_id))
  if (params.branch_id != null && params.branch_id !== '') query.set('branch_id', String(params.branch_id))
  if (params.department_id != null && params.department_id !== '') query.set('department_id', String(params.department_id))
  if (params.division_id != null && params.division_id !== '') query.set('division_id', String(params.division_id))
  if (params.section_unit_id != null && params.section_unit_id !== '') query.set('section_unit_id', String(params.section_unit_id))
  if (params.assignable_to_company_id != null && params.assignable_to_company_id !== '') query.set('assignable_to_company_id', String(params.assignable_to_company_id))
  if (params.for_department_assignment) query.set('for_department_assignment', '1')
  if (params.for_organization_assignment) query.set('for_organization_assignment', '1')
  if (params.assignment_branch_id != null && params.assignment_branch_id !== '') query.set('assignment_branch_id', String(params.assignment_branch_id))
  if (params.fresh) query.set('_ts', String(Date.now()))
  const path = `/admin/employees${query.toString() ? `?${query.toString()}` : ''}`
  return cachedAuthenticatedGetJson(path, { ttlMs: params.fresh ? 0 : 6000 })
}

export async function getEmployeeOrganizationAssignments(employeeId, params = {}) {
  const query = new URLSearchParams()
  if (params.fresh) query.set('_ts', String(Date.now()))
  const suffix = query.toString()
  const path = `/admin/employees/${employeeId}/organization-assignments${suffix ? `?${suffix}` : ''}`
  return cachedAuthenticatedGetJson(path, { ttlMs: params.fresh ? 0 : 15000 })
}

export async function getMyOrganizationAssignments(params = {}) {
  const query = new URLSearchParams()
  if (params.fresh) query.set('_ts', String(Date.now()))
  if (params.date) query.set('date', params.date)
  if (params.start_date) query.set('start_date', params.start_date)
  const suffix = query.toString()
  const path = `/me/organization-assignments${suffix ? `?${suffix}` : ''}`
  return cachedAuthenticatedGetJson(path, { ttlMs: params.fresh ? 0 : 15000 })
}

/**
 * Download full employee roster workbook (one sheet per company).
 * @returns {Promise<{ blob: Blob, filename: string }>}
 */
export async function exportAllEmployeesCsv() {
  const res = await authenticatedFetch('/admin/employees/export/csv', {
    timeoutMs: 120000,
  })
  if (!res.ok) {
    const data = await res.json().catch(() => ({}))
    throw new Error(data.message || 'Failed to export employees CSV')
  }
  const blob = await res.blob()
  const header = res.headers.get('content-disposition') || ''
  const filenameMatch = /filename\*?=(?:UTF-8''|")?([^";]+)/i.exec(header)
  const filename = filenameMatch?.[1] ? decodeURIComponent(filenameMatch[1].replace(/"/g, '')) : 'employees_by_company.xlsx'

  return { blob, filename }
}

/**
 * Bulk import employees via CSV/XLSX.
 * @param {File} file
 * @returns {Promise<{ message: string, imported: number, failed: number, total_rows: number, errors: Array<{row:number,email:string,name:string,message:string}> }>}
 */
export async function importEmployees(file) {
  const formData = new FormData()
  formData.append('file', file)

  const res = await authenticatedFetch('/admin/employees/import', {
    method: 'POST',
    body: formData,
    timeoutMs: 180000,
  })
  const data = await res.json().catch(() => ({}))
  if (!res.ok) {
    throw new Error(firstValidationMessage(data) || data.message || 'Failed to import employees')
  }
  clearCachesAfterAdminEmployeeDataChange()
  return data
}

/**
 * Server-side full-sheet preview (same PhpSpreadsheet bounds as import).
 * @param {File} file
 * @returns {Promise<{ headers: string[], rows: Record<string, unknown>[], row_count: number, column_count: number }>}
 */
export async function previewEmployeeImport(file) {
  const formData = new FormData()
  formData.append('file', file)

  const res = await authenticatedFetch('/admin/employees/import/preview', {
    method: 'POST',
    body: formData,
    timeoutMs: 120000,
  })
  const data = await res.json().catch(() => ({}))
  if (!res.ok) {
    throw new Error(firstValidationMessage(data) || data.message || 'Failed to preview import file')
  }
  return data
}

/**
 * Remove all employees from a single bulk import (requires `employees.delete`).
 * @param {string} importBatchId UUID returned from {@link importEmployees}
 * @returns {Promise<{ message: string, deleted_count: number, deleted_user_ids: number[] }>}
 */
export async function rollbackEmployeeImport(importBatchId) {
  const res = await authenticatedFetch('/admin/employees/import/rollback', {
    method: 'POST',
    headers: { 'Content-Type': 'application/json', Accept: 'application/json' },
    body: JSON.stringify({ import_batch_id: importBatchId }),
    timeoutMs: 120000,
  })
  const data = await res.json().catch(() => ({}))
  if (!res.ok) {
    throw new Error(firstValidationMessage(data) || data.message || 'Failed to remove imported employees')
  }
  clearCachesAfterAdminEmployeeDataChange()
  return data
}

/**
 * Download self-service employee profile CSV.
 * @returns {Promise<{ blob: Blob, filename: string }>}
 */
export async function exportMyProfileCsv() {
  const res = await authenticatedFetch('/employee/profile/export/csv', {
    timeoutMs: 120000,
  })
  if (!res.ok) {
    const data = await res.json().catch(() => ({}))
    throw new Error(data.message || 'Failed to export profile CSV')
  }
  const blob = await res.blob()
  const header = res.headers.get('content-disposition') || ''
  const filenameMatch = /filename\*?=(?:UTF-8''|")?([^";]+)/i.exec(header)
  const filename = filenameMatch?.[1] ? decodeURIComponent(filenameMatch[1].replace(/"/g, '')) : 'my_profile_export.csv'

  return { blob, filename }
}

// NOTE: getWorkingSchedules is defined above in the Working Schedules section and returns
// the full schedules payload ({ schedules: [...] }). Do not redeclare it here.

/**
 * @param {{ first_name: string, middle_name?: string, last_name: string, suffix?: string, date_of_birth?: string, gender?: string, civil_status?: string, nationality?: string, home_address?: string, email: string, password: string, phone_number?: string, schedule?: object, department?: string|null, position?: string|null, profile_photo?: File|null }} payload
 */
export async function addEmployee(payload) {
  const hasFile = payload?.profile_photo instanceof File
  let body
  let headers = undefined
  if (hasFile) {
    const formData = new FormData()
    Object.entries(payload || {}).forEach(([key, value]) => {
      if (value === undefined || value === null || value === '') return
      if (key === 'profile_photo' && value instanceof File) {
        formData.append('profile_photo', value)
        return
      }
      formData.append(key, typeof value === 'object' ? JSON.stringify(value) : String(value))
    })
    body = formData
  } else {
    body = JSON.stringify(payload)
    headers = { 'Content-Type': 'application/json' }
  }
  const res = await authenticatedFetch('/admin/employees', {
    method: 'POST',
    ...(headers ? { headers } : {}),
    body,
  })
  const data = await res.json().catch(() => ({}))
  if (!res.ok) {
    const msg = data.errors
      ? Object.values(data.errors).flat().join(' ')
      : data.message || 'Failed to add employee'
    throw new Error(msg)
  }
  clearCachesAfterAdminEmployeeDataChange()
  return data
}

/**
 * Permanently delete an employee (and cascade related data such as attendance logs).
 * @param {number} id
 */
export async function deleteEmployee(id) {
  const res = await authenticatedFetch(`/admin/employees/${id}`, {
    method: 'DELETE',
  })
  const data = await res.json().catch(() => ({}))
  if (!res.ok) throw new Error(data.message || 'Failed to delete employee')
  clearCachesAfterAdminEmployeeDataChange(id)
  return data
}

/**
 * Update employee profile (e.g. phone_number).
 * @param {number} id
 * @param {{ phone_number?: string | null }} payload
 */
/**
 * PATCH admin employee. Heavy cache/compensation work runs asynchronously (queue); response is fast.
 */
export async function updateEmployee(id, payload, options = {}) {
  const { timeoutMs } = options
  const res = await authenticatedFetch(`/admin/employees/${id}`, {
    method: 'PATCH',
    headers: { 'Content-Type': 'application/json' },
    body: JSON.stringify(payload),
    ...(timeoutMs != null ? { timeoutMs } : {}),
  })
  const data = await res.json().catch(() => ({}))
  if (!res.ok) {
    throw new Error(laravelValidationMessage(data, 'Failed to update employee'))
  }
  clearCachesAfterAdminEmployeeDataChange(id)
  return data
}

/**
 * Transfer employee to a new branch.
 * @param {number|string} id - Employee ID
 * @param {{ target_branch_id: number, transfer_date?: string, department_id?: number, reason?: string }} payload
 */
export async function transferEmployee(id, payload) {
  const res = await authenticatedFetch(`/admin/employees/${id}/transfer`, {
    method: 'POST',
    headers: { 'Content-Type': 'application/json' },
    body: JSON.stringify(payload),
  })
  const data = await res.json().catch(() => ({}))
  if (!res.ok) {
    const msg = data.errors
      ? Object.values(data.errors).flat().join(' ')
      : data.message || 'Failed to transfer employee'
    throw new Error(msg)
  }
  clearCachesAfterAdminEmployeeDataChange(id)
  return data
}

/**
 * Save employee signature image (admin).
 * @param {number|string} id
 * @param {string} signatureDataUrl
 */
export async function saveEmployeeSignature(id, signatureDataUrl) {
  const res = await authenticatedFetch(`/admin/employees/${id}/signature`, {
    method: 'POST',
    body: JSON.stringify({ signature_data_url: signatureDataUrl }),
  })
  const data = await res.json().catch(() => ({}))
  if (!res.ok) throw new Error(data.message || 'Failed to save signature')
  clearCachesAfterAdminEmployeeDataChange(id)
  return data
}

/**
 * Clear employee signature image (admin).
 * @param {number|string} id
 */
export async function clearEmployeeSignature(id) {
  const res = await authenticatedFetch(`/admin/employees/${id}/signature`, { method: 'DELETE' })
  const data = await res.json().catch(() => ({}))
  if (!res.ok) throw new Error(data.message || 'Failed to remove signature')
  clearCachesAfterAdminEmployeeDataChange(id)
  return data
}

/**
 * Upload or replace an employee profile photo (admin).
 * @param {number} id
 * @param {File} file
 */
export async function uploadEmployeePhoto(id, file) {
  const formData = new FormData()
  formData.append('photo', file)
  const res = await authenticatedFetch(`/admin/employees/${id}/photo`, {
    method: 'POST',
    body: formData,
  })
  const data = await res.json().catch(() => ({}))
  if (!res.ok) {
    const msg = data.errors?.photo?.[0] || data.message || 'Failed to upload employee photo'
    throw new Error(msg)
  }
  clearCachesAfterAdminEmployeeDataChange(id)
  return data
}

/**
 * Remove employee profile photo (admin).
 * @param {number} id
 */
export async function removeEmployeePhoto(id) {
  const res = await authenticatedFetch(`/admin/employees/${id}/photo`, {
    method: 'DELETE',
  })
  const data = await res.json().catch(() => ({}))
  if (!res.ok) throw new Error(data.message || 'Failed to remove employee photo')
  clearCachesAfterAdminEmployeeDataChange(id)
  return data
}

// —— Admin: Departments ——

export async function getDepartments(params = {}) {
  const query = new URLSearchParams()
  if (params.branch_id != null && params.branch_id !== '') query.set('branch_id', String(params.branch_id))
  if (params.company_id != null && params.company_id !== '') query.set('company_id', String(params.company_id))
  if (params.fresh) query.set('_ts', String(Date.now()))
  const path = `/admin/departments${query.toString() ? `?${query.toString()}` : ''}`
  return cachedAuthenticatedGetJson(path, { ttlMs: params.fresh ? 0 : 5 * 60 * 1000 })
}

/**
 * Create department. Pass { name, division_id, branch_id?, logo? } where logo is a File (JPG/PNG/WebP, max 2MB).
 * Sends multipart/form-data when logo is present.
 */
export async function createDepartment(payload) {
  const hasFile = payload.logo instanceof File
  const headers = { Accept: 'application/json' }

  let body
  if (hasFile) {
    const form = new FormData()
    form.append('name', payload.name)
    if (payload.division_id != null && payload.division_id !== '') {
      form.append('division_id', String(payload.division_id))
    }
    if (payload.branch_id != null && payload.branch_id !== '') {
      form.append('branch_id', String(payload.branch_id))
    }
    if (payload.company_id != null && payload.company_id !== '') {
      form.append('company_id', String(payload.company_id))
    }
    if (payload.office_location != null && String(payload.office_location).trim() !== '') {
      form.append('office_location', String(payload.office_location).trim())
    }
    if (payload.description != null && String(payload.description).trim() !== '') {
      form.append('description', String(payload.description).trim())
    }
    form.append('logo', payload.logo)
    body = form
    delete headers['Content-Type']
  } else {
    headers['Content-Type'] = 'application/json'
    body = JSON.stringify({
      name: payload.name,
      division_id: Number(payload.division_id),
      ...(payload.branch_id != null && payload.branch_id !== '' ? { branch_id: Number(payload.branch_id) } : {}),
      ...(payload.company_id != null && payload.company_id !== '' ? { company_id: Number(payload.company_id) } : {}),
      ...(payload.office_location != null && String(payload.office_location).trim() !== ''
        ? { office_location: String(payload.office_location).trim() }
        : {}),
      ...(payload.description != null && String(payload.description).trim() !== ''
        ? { description: String(payload.description).trim() }
        : {}),
    })
  }

  const res = await authenticatedFetch('/admin/departments', { method: 'POST', headers, body })
  const data = await res.json().catch(() => ({}))
  if (!res.ok) {
    const msg = data.message || data.errors?.division_id?.[0] || data.errors?.logo?.[0] || data.errors?.name?.[0] || 'Failed to create department'
    throw new Error(msg)
  }
  clearGetCacheByPrefix('/admin/departments')
  return data
}

/** Get employees assigned to a department (for View Employees). */
export async function getDepartmentEmployees(departmentId) {
  const res = await authenticatedFetch(`/admin/departments/${departmentId}/employees`)
  const data = await res.json().catch(() => ({}))
  if (!res.ok) throw new Error(data.message || 'Failed to load department employees')
  return data
}

/** Unassign employees from a department. */
export async function unassignEmployeesFromDepartment(departmentId, employeeIds) {
  const res = await authenticatedFetch(`/admin/departments/${departmentId}/unassign-employees`, {
    method: 'POST',
    body: JSON.stringify({ employee_ids: employeeIds }),
  })
  const data = await res.json().catch(() => ({}))
  if (!res.ok) throw new Error(data.errors?.employee_ids?.[0] || data.message || 'Failed to unassign employees')
  clearGetCacheByPrefix('/admin/departments')
  clearGetCacheByPrefix('/admin/divisions')
  clearGetCacheByPrefix('/admin/sections-or-units')
  return data
}

// —— Admin: Benefit Catalogs (Company Benefits Configuration) ——

/**
 * List benefit catalog options. Optional: department_id, type.
 * @param {{ department_id?: number|string, type?: string, all?: boolean }} params - all: if true, return inactive catalogs too (for admin manage)
 */
export async function getBenefitCatalogs(params = {}) {
  const query = new URLSearchParams()
  if (params.department_id != null && params.department_id !== '') query.set('department_id', String(params.department_id))
  if (params.type) query.set('type', String(params.type))
  if (params.all) query.set('all', '1')
  const path = `/admin/benefit-catalogs${query.toString() ? `?${query.toString()}` : ''}`
  const res = await authenticatedFetch(path)
  const data = await res.json().catch(() => ({}))
  if (!res.ok) throw new Error(data.message || 'Failed to load benefit catalogs')
  return data
}

export async function createBenefitCatalog(payload) {
  const res = await authenticatedFetch('/admin/benefit-catalogs', {
    method: 'POST',
    headers: { 'Content-Type': 'application/json' },
    body: JSON.stringify(payload),
  })
  const data = await res.json().catch(() => ({}))
  if (!res.ok) {
    const msg = data.errors ? Object.values(data.errors).flat().join(' ') : data.message || 'Failed to create benefit catalog'
    throw new Error(msg)
  }
  return data
}

export async function updateBenefitCatalog(id, payload) {
  const res = await authenticatedFetch(`/admin/benefit-catalogs/${id}`, {
    method: 'PATCH',
    headers: { 'Content-Type': 'application/json' },
    body: JSON.stringify(payload),
  })
  const data = await res.json().catch(() => ({}))
  if (!res.ok) throw new Error(data.message || 'Failed to update benefit catalog')
  return data
}

export async function deleteBenefitCatalog(id) {
  const res = await authenticatedFetch(`/admin/benefit-catalogs/${id}`, { method: 'DELETE' })
  const data = await res.json().catch(() => ({}))
  if (!res.ok) throw new Error(data.message || 'Failed to delete benefit catalog')
  return data
}

// —— Admin: Pay Components & Employee Compensation ——

export async function getPayComponents(params = {}) {
  const query = new URLSearchParams()
  if (params.search) query.set('search', String(params.search))
  if (params.type) query.set('type', String(params.type))
  if (params.all) query.set('all', '1')
  const res = await authenticatedFetch(`/admin/pay-components${query.toString() ? `?${query.toString()}` : ''}`)
  const data = await res.json().catch(() => ({}))
  if (!res.ok) throw new Error(data.message || 'Failed to load pay components')
  return data
}

export async function createPayComponent(payload) {
  const res = await authenticatedFetch('/admin/pay-components', {
    method: 'POST',
    headers: { 'Content-Type': 'application/json' },
    body: JSON.stringify(payload),
  })
  const data = await res.json().catch(() => ({}))
  if (!res.ok) {
    const msg = data.errors ? Object.values(data.errors).flat().join(' ') : data.message || 'Failed to create pay component'
    throw new Error(msg)
  }
  clearCachesAfterAdminEmployeeDataChange(null)
  return data
}

export async function updatePayComponent(id, payload) {
  const res = await authenticatedFetch(`/admin/pay-components/${id}`, {
    method: 'PATCH',
    headers: { 'Content-Type': 'application/json' },
    body: JSON.stringify(payload),
  })
  const data = await res.json().catch(() => ({}))
  if (!res.ok) {
    const msg = data.errors ? Object.values(data.errors).flat().join(' ') : data.message || 'Failed to update pay component'
    throw new Error(msg)
  }
  clearCachesAfterAdminEmployeeDataChange(null)
  return data
}

export async function deletePayComponent(id, opts = {}) {
  const q = new URLSearchParams()
  if (opts.forceUnassign) q.set('force_unassign', '1')
  const suffix = q.toString() ? `?${q.toString()}` : ''
  const res = await authenticatedFetch(`/admin/pay-components/${id}${suffix}`, { method: 'DELETE' })
  const data = await res.json().catch(() => ({}))
  if (!res.ok) {
    const err = new Error(data.message || 'Failed to delete pay component')
    err.status = res.status
    err.active_assignment_count = data.active_assignment_count
    err.requires_confirmation = data.requires_confirmation
    throw err
  }
  clearCachesAfterAdminEmployeeDataChange(null)
  return data
}

export async function getDeductionScheduleSettings(params = {}) {
  const query = new URLSearchParams()
  if (params.company_id != null && params.company_id !== '') query.set('company_id', String(params.company_id))
  const res = await authenticatedFetch(`/admin/deduction-schedule-settings${query.toString() ? `?${query.toString()}` : ''}`)
  const data = await res.json().catch(() => ({}))
  if (!res.ok) throw new Error(data.message || 'Failed to load deduction schedule settings')
  return data
}

export async function updateDeductionScheduleSetting(payload) {
  const res = await authenticatedFetch('/admin/deduction-schedule-settings', {
    method: 'PATCH',
    headers: { 'Content-Type': 'application/json' },
    body: JSON.stringify(payload),
  })
  const data = await res.json().catch(() => ({}))
  if (!res.ok) {
    const msg = data.errors ? Object.values(data.errors).flat().join(' ') : data.message || 'Failed to update deduction schedule'
    throw new Error(msg)
  }
  return data
}

/** Save multiple schedules in one request (applies to all employees). */
export async function updateDeductionScheduleSettingsBatch(payload) {
  const res = await authenticatedFetch('/admin/deduction-schedule-settings/batch', {
    method: 'POST',
    headers: { 'Content-Type': 'application/json' },
    body: JSON.stringify(payload),
  })
  const data = await res.json().catch(() => ({}))
  if (!res.ok) {
    const msg = data.errors ? Object.values(data.errors).flat().join(' ') : data.message || 'Failed to save deduction schedules'
    throw new Error(msg)
  }
  return data
}

export async function getAdminNextDeductionDates(params = {}) {
  const query = new URLSearchParams()
  if (params.schedule_type) query.set('schedule_type', String(params.schedule_type))
  if (params.user_id != null && params.user_id !== '') query.set('user_id', String(params.user_id))
  if (params.as_of_date) query.set('as_of_date', String(params.as_of_date))
  const res = await authenticatedFetch(`/admin/deduction-schedule-settings/next-deduction-dates?${query.toString()}`)
  const data = await res.json().catch(() => ({}))
  if (!res.ok) throw new Error(data.message || 'Failed to load next deduction dates')
  return data
}

export async function getPayCycles(params = {}) {
  const query = new URLSearchParams()
  if (params.company_id != null && params.company_id !== '') query.set('company_id', String(params.company_id))
  const res = await authenticatedFetch(`/admin/pay-cycles${query.toString() ? `?${query.toString()}` : ''}`)
  const data = await res.json().catch(() => ({}))
  if (!res.ok) throw new Error(data.message || 'Failed to load pay cycles')
  return data
}

export async function previewPayCycle(payload) {
  const res = await authenticatedFetch('/admin/pay-cycles/preview', {
    method: 'POST',
    headers: { 'Content-Type': 'application/json' },
    body: JSON.stringify(payload),
  })
  const data = await res.json().catch(() => ({}))
  if (!res.ok) throw new Error(data.message || 'Failed to preview pay cycle')
  return data
}

export async function createPayCycle(payload) {
  const res = await authenticatedFetch('/admin/pay-cycles', {
    method: 'POST',
    headers: { 'Content-Type': 'application/json' },
    body: JSON.stringify(payload),
  })
  const data = await res.json().catch(() => ({}))
  if (!res.ok) {
    const msg = data.errors ? Object.values(data.errors).flat().join(' ') : data.message || 'Failed to create pay cycle'
    throw new Error(msg)
  }
  return data
}

export async function updatePayCycle(id, payload) {
  const res = await authenticatedFetch(`/admin/pay-cycles/${id}`, {
    method: 'PATCH',
    headers: { 'Content-Type': 'application/json' },
    body: JSON.stringify(payload),
  })
  const data = await res.json().catch(() => ({}))
  if (!res.ok) {
    const msg = data.errors ? Object.values(data.errors).flat().join(' ') : data.message || 'Failed to update pay cycle'
    throw new Error(msg)
  }
  return data
}

export async function deletePayCycle(id) {
  const res = await authenticatedFetch(`/admin/pay-cycles/${id}`, { method: 'DELETE' })
  const data = await res.json().catch(() => ({}))
  if (!res.ok) throw new Error(data.message || 'Failed to delete pay cycle')
  return data
}

export async function getEmployeeCompensation(params = {}) {
  const { signal, employee_ids: employeeIdsParam, employee_id: employeeIdParam, as_of_date: asOfDateParam } = params
  const query = new URLSearchParams()
  ;(employeeIdsParam || []).forEach((id) => query.append('employee_ids[]', String(id)))
  if (employeeIdParam != null && employeeIdParam !== '') query.set('employee_id', String(employeeIdParam))
  if (asOfDateParam) query.set('as_of_date', String(asOfDateParam))
  const url = `/admin/employee-compensation${query.toString() ? `?${query.toString()}` : ''}`
  const response = await authenticatedFetch(url, signal ? { signal } : {})
  const data = await response.json().catch(() => ({}))
  if (!response.ok) throw new Error(data.message || 'Failed to load employee compensation')
  return data
}

export async function assignEmployeeCompensation(payload) {
  const res = await authenticatedFetch('/admin/employee-compensation/assign', {
    method: 'POST',
    headers: { 'Content-Type': 'application/json' },
    body: JSON.stringify(payload),
  })
  const data = await res.json().catch(() => ({}))
  if (!res.ok) {
    const msg = data.errors ? Object.values(data.errors).flat().join(' ') : data.message || 'Failed to assign employee compensation'
    throw new Error(msg)
  }
  const ids = Array.isArray(payload?.employee_ids) ? payload.employee_ids : []
  if (ids.length === 1) {
    clearCachesAfterAdminEmployeeDataChange(ids[0])
  } else {
    clearGetCacheByPrefix('/admin/employees')
    clearGetCacheByPrefix('/employee/profile')
    for (const raw of ids) {
      const n = Number(raw)
      if (Number.isFinite(n)) clearGetCacheByPrefix(`/admin/employees/${n}/profile`)
    }
  }
  return data
}

export async function updateEmployeeCompensation(employeeId, assignmentId, payload) {
  const res = await authenticatedFetch(`/admin/employees/${employeeId}/compensation/${assignmentId}`, {
    method: 'PATCH',
    headers: { 'Content-Type': 'application/json' },
    body: JSON.stringify(payload),
  })
  const data = await res.json().catch(() => ({}))
  if (!res.ok) {
    const msg = data.errors ? Object.values(data.errors).flat().join(' ') : data.message || 'Failed to update employee compensation'
    throw new Error(msg)
  }
  clearCachesAfterAdminEmployeeDataChange(employeeId)
  return data
}

export async function deleteEmployeeCompensation(employeeId, assignmentId) {
  const res = await authenticatedFetch(`/admin/employees/${employeeId}/compensation/${assignmentId}`, {
    method: 'DELETE',
  })
  const data = await res.json().catch(() => ({}))
  if (!res.ok) throw new Error(data.message || 'Failed to delete employee compensation')
  clearCachesAfterAdminEmployeeDataChange(employeeId)
  return data
}

// —— Admin: Employee Benefits (assignments) ——

export async function getEmployeeBenefits(employeeId) {
  const res = await authenticatedFetch(`/admin/employees/${employeeId}/benefits`)
  const data = await res.json().catch(() => ({}))
  if (!res.ok) throw new Error(data.message || 'Failed to load employee benefits')
  return data
}

// —— Admin: Employee Skills ——

export async function getEmployeeSkills(employeeId) {
  const res = await authenticatedFetch(`/admin/employees/${employeeId}/skills`)
  const data = await res.json().catch(() => ({}))
  if (!res.ok) throw new Error(data.message || 'Failed to load employee skills')
  return data
}

export async function addEmployeeSkill(employeeId, name) {
  const res = await authenticatedFetch(`/admin/employees/${employeeId}/skills`, {
    method: 'POST',
    headers: { 'Content-Type': 'application/json' },
    body: JSON.stringify({ name }),
  })
  const data = await res.json().catch(() => ({}))
  if (!res.ok) {
    const msg = data.errors ? Object.values(data.errors).flat().filter(Boolean)[0] || data.message : data.message
    throw new Error(msg || 'Failed to add skill')
  }
  return data
}

export async function updateEmployeeSkill(employeeId, skillId, name) {
  const res = await authenticatedFetch(`/admin/employees/${employeeId}/skills/${skillId}`, {
    method: 'PATCH',
    headers: { 'Content-Type': 'application/json' },
    body: JSON.stringify({ name }),
  })
  const data = await res.json().catch(() => ({}))
  if (!res.ok) {
    const msg = data.errors ? Object.values(data.errors).flat().filter(Boolean)[0] || data.message : data.message
    throw new Error(msg || 'Failed to update skill')
  }
  return data
}

export async function removeEmployeeSkill(employeeId, skillId) {
  const res = await authenticatedFetch(`/admin/employees/${employeeId}/skills/${skillId}`, {
    method: 'DELETE',
  })
  const data = await res.json().catch(() => ({}))
  if (!res.ok) throw new Error(data.message || 'Failed to remove skill')
  return data
}

// —— Shared: Skill suggestions (autocomplete) ——

export async function getSkillSuggestions(q, limit = 8) {
  const query = new URLSearchParams()
  if (q != null && String(q).trim() !== '') query.set('q', String(q))
  if (limit != null) query.set('limit', String(limit))
  const res = await authenticatedFetch(`/skills/suggestions${query.toString() ? `?${query.toString()}` : ''}`)
  const data = await res.json().catch(() => ({}))
  if (!res.ok) throw new Error(data.message || 'Failed to load skill suggestions')
  return data
}

// —— Employee: Skills ——

export async function getMySkills() {
  const res = await authenticatedFetch('/employee/profile/skills')
  const data = await res.json().catch(() => ({}))
  if (!res.ok) throw new Error(data.message || 'Failed to load skills')
  return data
}

export async function addMySkill(name) {
  const res = await authenticatedFetch('/employee/profile/skills', {
    method: 'POST',
    headers: { 'Content-Type': 'application/json' },
    body: JSON.stringify({ name }),
  })
  const data = await res.json().catch(() => ({}))
  if (!res.ok) {
    const msg = data.errors ? Object.values(data.errors).flat().filter(Boolean)[0] || data.message : data.message
    throw new Error(msg || 'Failed to add skill')
  }
  return data
}

export async function updateMySkill(skillId, name) {
  const res = await authenticatedFetch(`/employee/profile/skills/${skillId}`, {
    method: 'PATCH',
    headers: { 'Content-Type': 'application/json' },
    body: JSON.stringify({ name }),
  })
  const data = await res.json().catch(() => ({}))
  if (!res.ok) {
    const msg = data.errors ? Object.values(data.errors).flat().filter(Boolean)[0] || data.message : data.message
    throw new Error(msg || 'Failed to update skill')
  }
  return data
}

export async function removeMySkill(skillId) {
  const res = await authenticatedFetch(`/employee/profile/skills/${skillId}`, { method: 'DELETE' })
  const data = await res.json().catch(() => ({}))
  if (!res.ok) throw new Error(data.message || 'Failed to remove skill')
  return data
}

// —— Employee: Certifications ——

export async function getMyCertifications() {
  const res = await authenticatedFetch('/employee/profile/certifications')
  const data = await res.json().catch(() => ({}))
  if (!res.ok) throw new Error(data.message || 'Failed to load certifications')
  return data
}

export async function createMyCertification(payload) {
  const form = new FormData()
  form.append('certification_name', payload.certification_name || '')
  form.append('issuing_organization', payload.issuing_organization || '')
  form.append('issue_date', payload.issue_date || '')
  if (payload.expiration_date) form.append('expiration_date', payload.expiration_date)
  if (payload.credential_id) form.append('credential_id', payload.credential_id)
  if (payload.credential_url) form.append('credential_url', payload.credential_url)
  if (payload.certificate_file instanceof File) form.append('certificate_file', payload.certificate_file)

  const res = await authenticatedFetch('/employee/profile/certifications', { method: 'POST', body: form })
  const data = await res.json().catch(() => ({}))
  if (!res.ok) {
    const msg = data.errors ? Object.values(data.errors).flat().filter(Boolean)[0] || data.message : data.message
    throw new Error(msg || 'Failed to submit certification')
  }
  return data
}

export async function updateMyCertification(id, payload) {
  const form = new FormData()
  form.append('certification_name', payload.certification_name || '')
  form.append('issuing_organization', payload.issuing_organization || '')
  form.append('issue_date', payload.issue_date || '')
  if (payload.expiration_date) form.append('expiration_date', payload.expiration_date)
  if (payload.credential_id) form.append('credential_id', payload.credential_id)
  if (payload.credential_url) form.append('credential_url', payload.credential_url)
  if (payload.certificate_file instanceof File) form.append('certificate_file', payload.certificate_file)

  const res = await authenticatedFetch(`/employee/profile/certifications/${id}`, { method: 'POST', body: form })
  const data = await res.json().catch(() => ({}))
  if (!res.ok) {
    const msg = data.errors ? Object.values(data.errors).flat().filter(Boolean)[0] || data.message : data.message
    throw new Error(msg || 'Failed to update certification')
  }
  return data
}

export async function deleteMyCertification(id) {
  const res = await authenticatedFetch(`/employee/profile/certifications/${id}`, { method: 'DELETE' })
  const data = await res.json().catch(() => ({}))
  if (!res.ok) throw new Error(data.message || 'Failed to delete certification')
  return data
}

// —— Admin: Employee Certifications ——

export async function getEmployeeCertifications(employeeId) {
  const res = await authenticatedFetch(`/admin/employees/${employeeId}/certifications`)
  const data = await res.json().catch(() => ({}))
  if (!res.ok) throw new Error(data.message || 'Failed to load employee certifications')
  return data
}

export async function createEmployeeCertification(employeeId, payload) {
  const form = new FormData()
  form.append('certification_name', payload.certification_name || '')
  form.append('issuing_organization', payload.issuing_organization || '')
  form.append('issue_date', payload.issue_date || '')
  if (payload.expiration_date) form.append('expiration_date', payload.expiration_date)
  if (payload.credential_id) form.append('credential_id', payload.credential_id)
  if (payload.credential_url) form.append('credential_url', payload.credential_url)
  if (payload.certificate_file instanceof File) form.append('certificate_file', payload.certificate_file)

  const res = await authenticatedFetch(`/admin/employees/${employeeId}/certifications`, { method: 'POST', body: form })
  const data = await res.json().catch(() => ({}))
  if (!res.ok) {
    const msg = data.errors ? Object.values(data.errors).flat().filter(Boolean)[0] || data.message : data.message
    throw new Error(msg || 'Failed to add certification')
  }
  return data
}

export async function updateEmployeeCertification(employeeId, id, payload) {
  const form = new FormData()
  form.append('certification_name', payload.certification_name || '')
  form.append('issuing_organization', payload.issuing_organization || '')
  form.append('issue_date', payload.issue_date || '')
  if (payload.expiration_date) form.append('expiration_date', payload.expiration_date)
  if (payload.credential_id) form.append('credential_id', payload.credential_id)
  if (payload.credential_url) form.append('credential_url', payload.credential_url)
  if (payload.certificate_file instanceof File) form.append('certificate_file', payload.certificate_file)
  if (payload.verification_status) form.append('verification_status', payload.verification_status)

  const res = await authenticatedFetch(`/admin/employees/${employeeId}/certifications/${id}`, { method: 'POST', body: form })
  const data = await res.json().catch(() => ({}))
  if (!res.ok) {
    const msg = data.errors ? Object.values(data.errors).flat().filter(Boolean)[0] || data.message : data.message
    throw new Error(msg || 'Failed to update certification')
  }
  return data
}

export async function deleteEmployeeCertification(employeeId, id) {
  const res = await authenticatedFetch(`/admin/employees/${employeeId}/certifications/${id}`, { method: 'DELETE' })
  const data = await res.json().catch(() => ({}))
  if (!res.ok) throw new Error(data.message || 'Failed to delete certification')
  return data
}

export async function verifyEmployeeCertification(employeeId, id, status, rejection_reason) {
  const res = await authenticatedFetch(`/admin/employees/${employeeId}/certifications/${id}/verify`, {
    method: 'POST',
    headers: { 'Content-Type': 'application/json' },
    body: JSON.stringify({ status, rejection_reason }),
  })
  const data = await res.json().catch(() => ({}))
  if (!res.ok) {
    const msg = data.errors ? Object.values(data.errors).flat().filter(Boolean)[0] || data.message : data.message
    throw new Error(msg || 'Failed to verify certification')
  }
  return data
}

// —— Employee: Government ID Documents ——

export async function getMyGovernmentIdDocuments() {
  const res = await authenticatedFetch('/employee/profile/government-id-documents')
  const data = await res.json().catch(() => ({}))
  if (!res.ok) throw new Error(data.message || 'Failed to load government IDs')
  return data
}

export async function createMyGovernmentIdDocument(payload) {
  const form = new FormData()
  form.append('id_type', payload.id_type || '')
  form.append('id_number', payload.id_number || '')
  form.append('issuing_agency', payload.issuing_agency || '')
  if (payload.expiry_date) form.append('expiry_date', payload.expiry_date)
  if (payload.document_file instanceof File) form.append('document_file', payload.document_file)

  const res = await authenticatedFetch('/employee/profile/government-id-documents', { method: 'POST', body: form })
  const data = await res.json().catch(() => ({}))
  if (!res.ok) {
    const msg = data.errors ? Object.values(data.errors).flat().filter(Boolean)[0] || data.message : data.message
    throw new Error(msg || 'Failed to upload government ID')
  }
  return data
}

export async function updateMyGovernmentIdDocument(id, payload) {
  const form = new FormData()
  form.append('id_type', payload.id_type || '')
  form.append('id_number', payload.id_number || '')
  form.append('issuing_agency', payload.issuing_agency || '')
  if (payload.expiry_date) form.append('expiry_date', payload.expiry_date)
  if (payload.document_file instanceof File) form.append('document_file', payload.document_file)

  const res = await authenticatedFetch(`/employee/profile/government-id-documents/${id}`, { method: 'POST', body: form })
  const data = await res.json().catch(() => ({}))
  if (!res.ok) {
    const msg = data.errors ? Object.values(data.errors).flat().filter(Boolean)[0] || data.message : data.message
    throw new Error(msg || 'Failed to update government ID')
  }
  return data
}

export async function deleteMyGovernmentIdDocument(id) {
  const res = await authenticatedFetch(`/employee/profile/government-id-documents/${id}`, { method: 'DELETE' })
  const data = await res.json().catch(() => ({}))
  if (!res.ok) {
    const msg = data.errors ? Object.values(data.errors).flat().filter(Boolean)[0] || data.message : data.message
    throw new Error(msg || 'Failed to delete government ID')
  }
  return data
}

// —— Admin: Employee Government ID Documents ——

export async function getEmployeeGovernmentIdDocuments(employeeId) {
  const res = await authenticatedFetch(`/admin/employees/${employeeId}/government-id-documents`)
  const data = await res.json().catch(() => ({}))
  if (!res.ok) throw new Error(data.message || 'Failed to load employee government IDs')
  return data
}

export async function createEmployeeGovernmentIdDocument(employeeId, payload) {
  const form = new FormData()
  form.append('id_type', payload.id_type || '')
  form.append('id_number', payload.id_number || '')
  form.append('issuing_agency', payload.issuing_agency || '')
  if (payload.expiry_date) form.append('expiry_date', payload.expiry_date)
  if (payload.document_file instanceof File) form.append('document_file', payload.document_file)

  const res = await authenticatedFetch(`/admin/employees/${employeeId}/government-id-documents`, { method: 'POST', body: form })
  const data = await res.json().catch(() => ({}))
  if (!res.ok) {
    const msg = data.errors ? Object.values(data.errors).flat().filter(Boolean)[0] || data.message : data.message
    throw new Error(msg || 'Failed to upload government ID')
  }
  return data
}

export async function updateEmployeeGovernmentIdDocument(employeeId, id, payload) {
  const form = new FormData()
  form.append('id_type', payload.id_type || '')
  form.append('id_number', payload.id_number || '')
  form.append('issuing_agency', payload.issuing_agency || '')
  if (payload.expiry_date) form.append('expiry_date', payload.expiry_date)
  if (payload.document_file instanceof File) form.append('document_file', payload.document_file)

  const res = await authenticatedFetch(`/admin/employees/${employeeId}/government-id-documents/${id}`, { method: 'POST', body: form })
  const data = await res.json().catch(() => ({}))
  if (!res.ok) {
    const msg = data.errors ? Object.values(data.errors).flat().filter(Boolean)[0] || data.message : data.message
    throw new Error(msg || 'Failed to update government ID')
  }
  return data
}

export async function deleteEmployeeGovernmentIdDocument(employeeId, id) {
  const res = await authenticatedFetch(`/admin/employees/${employeeId}/government-id-documents/${id}`, { method: 'DELETE' })
  const data = await res.json().catch(() => ({}))
  if (!res.ok) {
    const msg = data.errors ? Object.values(data.errors).flat().filter(Boolean)[0] || data.message : data.message
    throw new Error(msg || 'Failed to delete government ID')
  }
  return data
}

export async function verifyEmployeeGovernmentIdDocument(employeeId, id, status, rejection_reason) {
  const res = await authenticatedFetch(`/admin/employees/${employeeId}/government-id-documents/${id}/verify`, {
    method: 'POST',
    headers: { 'Content-Type': 'application/json' },
    body: JSON.stringify({ status, rejection_reason }),
  })
  const data = await res.json().catch(() => ({}))
  if (!res.ok) {
    const msg = data.errors ? Object.values(data.errors).flat().filter(Boolean)[0] || data.message : data.message
    throw new Error(msg || 'Failed to verify government ID')
  }
  return data
}

// —— Employee: Documents ——

export async function getMyDocuments(category) {
  const qs = category ? `?category=${encodeURIComponent(category)}` : ''
  const res = await authenticatedFetch(`/employee/profile/documents${qs}`)
  const data = await res.json().catch(() => ({}))
  if (!res.ok) throw new Error(data.message || 'Failed to load documents')
  return data
}

export async function createMyDocument(payload) {
  const form = new FormData()
  form.append('category', payload.category || '')
  form.append('document_name', payload.document_name || '')
  if (payload.version) form.append('version', payload.version)
  if (payload.expiry_date) form.append('expiry_date', payload.expiry_date)
  if (payload.file instanceof File) form.append('file', payload.file)

  const res = await authenticatedFetch('/employee/profile/documents', { method: 'POST', body: form })
  const data = await res.json().catch(() => ({}))
  if (!res.ok) {
    const msg = data.errors ? Object.values(data.errors).flat().filter(Boolean)[0] || data.message : data.message
    throw new Error(msg || 'Failed to upload document')
  }
  return data
}

export async function updateMyDocument(id, payload) {
  const form = new FormData()
  form.append('category', payload.category || '')
  form.append('document_name', payload.document_name || '')
  if (payload.version) form.append('version', payload.version)
  if (payload.expiry_date) form.append('expiry_date', payload.expiry_date)
  if (payload.file instanceof File) form.append('file', payload.file)

  const res = await authenticatedFetch(`/employee/profile/documents/${id}`, { method: 'POST', body: form })
  const data = await res.json().catch(() => ({}))
  if (!res.ok) {
    const msg = data.errors ? Object.values(data.errors).flat().filter(Boolean)[0] || data.message : data.message
    throw new Error(msg || 'Failed to update document')
  }
  return data
}

export async function deleteMyDocument(id) {
  const res = await authenticatedFetch(`/employee/profile/documents/${id}`, { method: 'DELETE' })
  const data = await res.json().catch(() => ({}))
  if (!res.ok) {
    const msg = data.errors ? Object.values(data.errors).flat().filter(Boolean)[0] || data.message : data.message
    throw new Error(msg || 'Failed to delete document')
  }
  return data
}

// —— Admin: Employee Documents ——

export async function getEmployeeDocuments(employeeId, category) {
  const qs = category ? `?category=${encodeURIComponent(category)}` : ''
  const res = await authenticatedFetch(`/admin/employees/${employeeId}/documents${qs}`)
  const data = await res.json().catch(() => ({}))
  if (!res.ok) throw new Error(data.message || 'Failed to load employee documents')
  return data
}

export async function createEmployeeDocument(employeeId, payload) {
  const form = new FormData()
  form.append('category', payload.category || '')
  form.append('document_name', payload.document_name || '')
  if (payload.version) form.append('version', payload.version)
  if (payload.expiry_date) form.append('expiry_date', payload.expiry_date)
  if (payload.file instanceof File) form.append('file', payload.file)

  const res = await authenticatedFetch(`/admin/employees/${employeeId}/documents`, { method: 'POST', body: form })
  const data = await res.json().catch(() => ({}))
  if (!res.ok) {
    const msg = data.errors ? Object.values(data.errors).flat().filter(Boolean)[0] || data.message : data.message
    throw new Error(msg || 'Failed to upload document')
  }
  return data
}

export async function updateEmployeeDocument(employeeId, id, payload) {
  const form = new FormData()
  form.append('category', payload.category || '')
  form.append('document_name', payload.document_name || '')
  if (payload.version) form.append('version', payload.version)
  if (payload.expiry_date) form.append('expiry_date', payload.expiry_date)
  if (payload.file instanceof File) form.append('file', payload.file)

  const res = await authenticatedFetch(`/admin/employees/${employeeId}/documents/${id}`, { method: 'POST', body: form })
  const data = await res.json().catch(() => ({}))
  if (!res.ok) {
    const msg = data.errors ? Object.values(data.errors).flat().filter(Boolean)[0] || data.message : data.message
    throw new Error(msg || 'Failed to update document')
  }
  return data
}

export async function deleteEmployeeDocument(employeeId, id) {
  const res = await authenticatedFetch(`/admin/employees/${employeeId}/documents/${id}`, { method: 'DELETE' })
  const data = await res.json().catch(() => ({}))
  if (!res.ok) {
    const msg = data.errors ? Object.values(data.errors).flat().filter(Boolean)[0] || data.message : data.message
    throw new Error(msg || 'Failed to delete document')
  }
  return data
}

export async function reviewEmployeeDocument(employeeId, id, status, review_note) {
  const res = await authenticatedFetch(`/admin/employees/${employeeId}/documents/${id}/review`, {
    method: 'POST',
    headers: { 'Content-Type': 'application/json' },
    body: JSON.stringify({ status, review_note }),
  })
  const data = await res.json().catch(() => ({}))
  if (!res.ok) {
    const msg = data.errors ? Object.values(data.errors).flat().filter(Boolean)[0] || data.message : data.message
    throw new Error(msg || 'Failed to review document')
  }
  return data
}

export async function assignEmployeeBenefit(employeeId, payload) {
  const res = await authenticatedFetch(`/admin/employees/${employeeId}/benefits`, {
    method: 'POST',
    headers: { 'Content-Type': 'application/json' },
    body: JSON.stringify(payload),
  })
  const data = await res.json().catch(() => ({}))
  if (!res.ok) {
    const msg = data.errors ? Object.values(data.errors).flat().join(' ') : data.message || 'Failed to assign benefit'
    throw new Error(msg)
  }
  return data
}

export async function updateEmployeeBenefit(employeeId, assignmentId, payload) {
  const res = await authenticatedFetch(`/admin/employees/${employeeId}/benefits/${assignmentId}`, {
    method: 'PATCH',
    headers: { 'Content-Type': 'application/json' },
    body: JSON.stringify(payload),
  })
  const data = await res.json().catch(() => ({}))
  if (!res.ok) throw new Error(data.message || 'Failed to update benefit')
  return data
}

export async function removeEmployeeBenefit(employeeId, assignmentId) {
  const res = await authenticatedFetch(`/admin/employees/${employeeId}/benefits/${assignmentId}`, {
    method: 'DELETE',
  })
  const data = await res.json().catch(() => ({}))
  if (!res.ok) throw new Error(data.message || 'Failed to remove benefit')
  return data
}

// —— Admin: Leave Management ——

/**
 * Fetch holidays (API + custom) for a given year.
 * @param {{ year?: number }} params - year (default: current)
 * @returns {{
 *   holidays: Array<{
 *     id?: number, date: string, name: string, type: string, scope?: string, description?: string|null,
 *     payroll_hints?: string[],
 *   }>,
 *   year: number,
 *   payroll_matrix?: {
 *     first_8_hour_by_condition: Array<{ holiday_type: string, rest_day: boolean|null, worked: boolean|null, first_8_multiplier: number|string, note: string|null }>,
 *     ot_multiplier_by_day_type: Array<{ day_type: string, ot_multiplier: number, rule_code: string }>,
 *   },
 * }}
 */
export async function getAdminHolidays(params = {}) {
  const year = params.year ?? new Date().getFullYear()
  const res = await authenticatedFetch(`/admin/holidays?year=${year}`)
  const data = await res.json().catch(() => ({}))
  if (!res.ok) throw new Error(data.message || 'Failed to load holidays')
  return data
}

export async function getMyHolidays(params = {}) {
  const year = params.year ?? new Date().getFullYear()
  const res = await authenticatedFetch(`/employee/holidays?year=${year}`)
  const data = await res.json().catch(() => ({}))
  if (!res.ok) throw new Error(data.message || 'Failed to load holidays')
  return data
}

/**
 * Create a custom holiday/event.
 * @param {{ name: string, date: string, type: string, scope: string }} payload
 * @returns {{ holiday: { id: number, date: string, name: string, type: string, scope: string } }}
 */
export async function createAdminHoliday(payload) {
  const res = await authenticatedFetch('/admin/holidays', {
    method: 'POST',
    body: JSON.stringify(payload),
  })
  const data = await res.json().catch(() => ({}))
  if (!res.ok) throw new Error(data.message || data.errors?.date?.[0] || data.errors?.name?.[0] || 'Failed to create holiday')
  return data
}

/**
 * Update a custom holiday/event.
 * @param {number} id - holiday id
 * @param {{ name?: string, date?: string, type?: string, scope?: string }} payload
 */
export async function updateAdminHoliday(id, payload) {
  const res = await authenticatedFetch(`/admin/holidays/${id}`, {
    method: 'PATCH',
    body: JSON.stringify(payload),
  })
  const data = await res.json().catch(() => ({}))
  if (!res.ok) throw new Error(data.message || data.errors?.date?.[0] || data.errors?.name?.[0] || 'Failed to update holiday')
  return data
}

/**
 * Move a custom holiday to another date while preserving scope and pay settings.
 * @param {number} id - holiday id
 * @param {{ date: string }} payload
 */
export async function swapAdminHoliday(id, payload) {
  const res = await authenticatedFetch(`/admin/holidays/${id}/swap`, {
    method: 'POST',
    body: JSON.stringify(payload),
  })
  const data = await res.json().catch(() => ({}))
  if (!res.ok) throw new Error(data.message || data.errors?.date?.[0] || 'Failed to swap holiday')
  return data
}

/**
 * Swap a seeded fallback holiday by creating an inactive override on the old date
 * and a custom active holiday on the new date.
 * @param {{ name: string, date: string, new_date: string, type: string, scope: string }} payload
 */
export async function swapSeededAdminHoliday(payload) {
  const res = await authenticatedFetch('/admin/holidays/seeded/swap', {
    method: 'POST',
    body: JSON.stringify(payload),
  })
  const data = await res.json().catch(() => ({}))
  if (!res.ok) throw new Error(data.message || data.errors?.new_date?.[0] || data.errors?.date?.[0] || 'Failed to swap holiday')
  return data
}

/**
 * Create a Swap Holiday with coverage targeting.
 * @param {{ name: string, date: string, type: string, coverage_type: string, coverage_ids: number[], original_date?: string, description?: string }} payload
 */
export async function createSwapHoliday(payload) {
  const res = await authenticatedFetch('/admin/holidays/swap', {
    method: 'POST',
    body: JSON.stringify(payload),
  })
  const data = await res.json().catch(() => ({}))
  if (!res.ok) throw new Error(data.message || 'Failed to create swap holiday')
  return data
}

/**
 * Update a Swap Holiday's coverage.
 * @param {number} id
 * @param {object} payload
 */
export async function updateSwapHoliday(id, payload) {
  const res = await authenticatedFetch(`/admin/holidays/${id}/swap`, {
    method: 'PATCH',
    body: JSON.stringify(payload),
  })
  const data = await res.json().catch(() => ({}))
  if (!res.ok) throw new Error(data.message || 'Failed to update swap holiday')
  return data
}

/**
 * Delete a custom holiday/event.
 * @param {number} id - holiday id
 */
export async function deleteAdminHoliday(id) {
  const res = await authenticatedFetch(`/admin/holidays/${id}`, { method: 'DELETE' })
  const data = await res.json().catch(() => ({}))
  if (!res.ok) throw new Error(data.message || 'Failed to delete holiday')
  return data
}

export async function getLeaveRequests(filters) {
  const params = new URLSearchParams()
  if (typeof filters === 'string') {
    if (filters) params.set('status', filters)
  } else if (filters && typeof filters === 'object') {
    if (filters.status) params.set('status', String(filters.status))
    if (filters.from_date) params.set('from_date', String(filters.from_date))
    if (filters.to_date) params.set('to_date', String(filters.to_date))
    if (filters.page) params.set('page', String(filters.page))
    if (filters.per_page) params.set('per_page', String(filters.per_page))
  }
  const qs = params.toString()
  const url = qs ? `/admin/leave?${qs}` : '/admin/leave'
  const res = await authenticatedFetch(url)
  const data = await res.json().catch(() => ({}))
  if (!res.ok) throw new Error(data.message || 'Failed to load leave requests')
  return data
}

/** Admin: fetch a single leave request by primary id (for dashboard deep-links). */
export async function getAdminLeaveByRequestId(requestId) {
  const res = await authenticatedFetch(`/admin/leave/${encodeURIComponent(String(requestId))}`)
  const data = await res.json().catch(() => ({}))
  if (!res.ok) throw new Error(data.message || 'Failed to load leave request')
  return data
}

/** Admin: fast review payload for modal (approval chain, history, attachments only). */
export async function fetchLeaveRequestReview(requestId, options = {}) {
  const raw = String(requestId ?? '').trim()
  if (!/^\d+$/.test(raw) || Number(raw) <= 0) {
    const err = new Error('Invalid leave request ID')
    err.code = 'invalid_id'
    throw err
  }
  const fetchOpts = options.signal ? { signal: options.signal } : {}
  const id = encodeURIComponent(raw)
  const paths = [
    `/leave-requests/${id}/review`,
    `/admin/leave/${id}/review`,
    `/admin/leave/${id}`,
    `/leave-requests/${id}`,
  ]
  let lastErr = null
  let lastStatus = 0
  let lastBody = null
  let lastUrl = null
  for (const path of paths) {
    lastUrl = path
    const res = await authenticatedFetch(path, fetchOpts)
    const data = await res.json().catch(() => ({}))
    lastBody = data
    if (res.ok) {
      return data
    }
    lastStatus = res.status
    lastErr = new Error(data.message || 'Failed to load leave request details')
    lastErr.status = res.status
    lastErr.url = path
    lastErr.body = data
    if (res.status === 403) {
      lastErr.code = 'forbidden'
      break
    }
    if (res.status >= 500) {
      lastErr.code = 'server_error'
      break
    }
    if (res.status !== 404) {
      break
    }
  }
  if (lastErr) {
    if (!lastErr.code && lastStatus === 404) lastErr.code = 'not_found'
    throw lastErr
  }
  const fallback = new Error('Failed to load leave request details')
  fallback.status = lastStatus
  fallback.url = lastUrl
  fallback.body = lastBody
  throw fallback
}

/**
 * @param {object} payload
 * @param {boolean} [payload.bypass_leave_credit_check] Super-admin only: file leave despite insufficient credits.
 */
/**
 * Employee: check whether a date range includes scheduled rest days.
 * @param {{ start_date: string, end_date: string }} params
 */
export async function validateMyLeaveDateRange(params) {
  const q = new URLSearchParams()
  q.set('start_date', params.start_date)
  q.set('end_date', params.end_date)
  const res = await authenticatedFetch(`/leave/validate-range?${q}`)
  const data = await res.json().catch(() => ({}))
  if (!res.ok) throw new Error(data.message || 'Failed to validate leave dates')
  return data
}

/**
 * HR panel: same check for a specific employee.
 * @param {{ user_id: number|string, start_date: string, end_date: string }} params
 */
export async function validateAdminLeaveDateRange(params) {
  const q = new URLSearchParams()
  q.set('user_id', String(params.user_id))
  q.set('start_date', params.start_date)
  q.set('end_date', params.end_date)
  const res = await authenticatedFetch(`/admin/leave/validate-range?${q}`)
  const data = await res.json().catch(() => ({}))
  if (!res.ok) throw new Error(data.message || 'Failed to validate leave dates')
  return data
}

export async function createLeaveRequest(payload) {
  const res = await authenticatedFetch('/admin/leave', {
    method: 'POST',
    body: JSON.stringify(payload),
  })
  const data = await res.json().catch(() => ({}))
  if (!res.ok) {
    const msg =
      firstValidationMessage(data) ||
      data.message ||
      data.errors?.user_id?.[0] ||
      'Failed to create leave request'
    throw new Error(msg)
  }
  return data
}

export async function approveLeaveRequest(id, notes, opts = {}) {
  const body = { notes: notes ?? '' }
  if (opts.forceInsufficientCredits) body.force_insufficient_credits = true
  if (opts.bypassRestDays) body.bypass_rest_days = true
  if (opts.restDayBypassReason) body.rest_day_bypass_reason = opts.restDayBypassReason
  const res = await authenticatedFetch(`/admin/leave/${id}/approve`, {
    method: 'POST',
    body: JSON.stringify(body),
  })
  const data = await res.json().catch(() => ({}))
  if (!res.ok) throw new Error(data.message || 'Failed to approve')
  return data
}

export async function bulkApproveLeavePreview(filters = {}) {
  const res = await authenticatedFetch('/admin/leave/bulk-approve-preview', {
    method: 'POST',
    headers: { 'Content-Type': 'application/json' },
    body: JSON.stringify({ filters }),
  })
  const data = await res.json().catch(() => ({}))
  if (!res.ok) throw new Error(data.message || 'Failed to count approvable leave requests')
  return data
}

/** @param {object|number[]} payloadOrIds - bulk payload or legacy id array */
export async function bulkApproveLeaveRequests(payloadOrIds, remarks = '') {
  const body =
    Array.isArray(payloadOrIds) || typeof payloadOrIds === 'number'
      ? {
          mode: 'selected_ids',
          ids: Array.isArray(payloadOrIds) ? payloadOrIds.map(Number) : [Number(payloadOrIds)],
          remarks: String(remarks || '').trim() || undefined,
        }
      : payloadOrIds
  const res = await authenticatedFetch('/admin/leave/bulk-approve', {
    method: 'POST',
    headers: { 'Content-Type': 'application/json' },
    body: JSON.stringify(body),
  })
  const data = await res.json().catch(() => ({}))
  if (!res.ok) throw new Error(data.message || 'Failed to approve selected leave requests')
  return data
}

export async function rejectLeaveRequest(id, reason) {
  const res = await authenticatedFetch(`/admin/leave/${id}/reject`, {
    method: 'POST',
    body: JSON.stringify({ reason: reason ?? '' }),
  })
  const data = await res.json().catch(() => ({}))
  if (!res.ok) throw new Error(data.message || 'Failed to reject')
  return data
}

export async function updateLeaveNotes(id, notes) {
  const res = await authenticatedFetch(`/admin/leave/${id}/notes`, {
    method: 'PATCH',
    body: JSON.stringify({ notes: notes ?? '' }),
  })
  const data = await res.json().catch(() => ({}))
  if (!res.ok) throw new Error(data.message || 'Failed to update notes')
  return data
}

export async function deleteAdminLeaveRequest(id) {
  const res = await authenticatedFetch(`/admin/leave/${id}`, { method: 'DELETE' })
  const data = await res.json().catch(() => ({}))
  if (!res.ok) throw new Error(firstValidationMessage(data) || data.message || 'Failed to delete leave request')
  return data
}

/** Probation employees approaching 3- or 6-month regularization milestones (scoped by HR data access). */
export async function getAdminUpcomingRegularizations(daysAhead = 30) {
  const q = new URLSearchParams()
  if (daysAhead != null && daysAhead !== '') q.set('days_ahead', String(daysAhead))
  const path = `/admin/regularization/upcoming${q.toString() ? `?${q.toString()}` : ''}`
  const res = await authenticatedFetch(path)
  const data = await res.json().catch(() => ({}))
  if (!res.ok) throw new Error(data.message || 'Failed to load upcoming regularizations')
  return data
}

/** Employee: read-only recommendations about self (probation regularization). */
export async function getMyRegularizationStatus() {
  const res = await authenticatedFetch('/regularization/my-status')
  const data = await res.json().catch(() => ({}))
  if (!res.ok) throw new Error(data.message || 'Failed to load regularization status')
  return data
}

/** Alias: full regularization history for the signed-in employee (subject only). */
export async function getMyRegularizationHistory() {
  return getMyRegularizationStatus()
}

/**
 * Admin / org heads: regularization recommendations (scoped by DataScope).
 * @param {'pending'|'approved'|'rejected'|'history'|undefined|null} status - Omit or use `history` for all statuses (audit log).
 */
export async function getAdminRegularizationRecommendations(status) {
  const path =
    status && ['pending', 'approved', 'rejected'].includes(status)
      ? `/admin/regularization/recommendations?status=${encodeURIComponent(status)}`
      : '/admin/regularization/recommendations'
  const res = await authenticatedFetch(path)
  const data = await res.json().catch(() => ({}))
  if (!res.ok) throw new Error(data.message || 'Failed to load recommendations')
  return data
}

/** HR Admin: probationary employees eligible for a regularization recommendation (scoped). */
export async function getRegularizationEligibleEmployees() {
  const res = await authenticatedFetch('/regularization/eligible-employees')
  const data = await res.json().catch(() => ({}))
  if (!res.ok) throw new Error(data.message || 'Failed to load eligible employees')
  return data
}

/**
 * HR Admin: submit regularization recommendation. Default auto_complete=true approves in one step.
 * @param {{ user_id: number, recommendation_type: string, recommendation_notes: string, effective_date?: string|null, expiration_date?: string|null, auto_complete?: boolean }} payload
 */
export async function submitRegularizationRecommendation(payload) {
  const body = {
    user_id: payload.user_id,
    recommendation_type: payload.recommendation_type,
    recommendation_notes: payload.recommendation_notes,
  }
  if (payload.auto_complete === true) {
    body.auto_complete = true
  }
  if (payload.effective_date != null && String(payload.effective_date).trim() !== '') {
    body.effective_date = String(payload.effective_date).trim()
  }
  if (payload.expiration_date != null && String(payload.expiration_date).trim() !== '') {
    body.expiration_date = String(payload.expiration_date).trim()
  }
  const res = await authenticatedFetch('/regularization/recommend', {
    method: 'POST',
    body: JSON.stringify(body),
  })
  const data = await res.json().catch(() => ({}))
  if (!res.ok) {
    const msg = firstValidationMessage(data) || data.message || 'Failed to submit recommendation'
    throw new Error(msg)
  }
  return data
}

export async function approveRegularizationRecommendation(id, notes) {
  const res = await authenticatedFetch(`/admin/regularization/recommendations/${id}/approve`, {
    method: 'POST',
    body: JSON.stringify({ notes: notes ?? '' }),
  })
  const data = await res.json().catch(() => ({}))
  if (!res.ok) {
    const msg = firstValidationMessage(data) || data.message || 'Failed to approve recommendation'
    throw new Error(msg)
  }
  return data
}

export async function rejectRegularizationRecommendation(id, reason) {
  const res = await authenticatedFetch(`/admin/regularization/recommendations/${id}/reject`, {
    method: 'POST',
    body: JSON.stringify({ reason: reason ?? '' }),
  })
  const data = await res.json().catch(() => ({}))
  if (!res.ok) {
    const msg = firstValidationMessage(data) || data.message || 'Failed to reject recommendation'
    throw new Error(msg)
  }
  return data
}

/** HR Admin: get configurable employment status automation settings. */
export async function getEmploymentStatusSettings() {
  const res = await authenticatedFetch('/admin/employee-status/settings')
  const data = await res.json().catch(() => ({}))
  if (!res.ok) throw new Error(data.message || 'Failed to load employment status settings')
  return data
}

/** HR Admin: update configurable employment status automation settings. */
export async function updateEmploymentStatusSettings(payload) {
  const res = await authenticatedFetch('/admin/employee-status/settings', {
    method: 'PATCH',
    body: JSON.stringify(payload || {}),
  })
  const data = await res.json().catch(() => ({}))
  if (!res.ok) throw new Error(firstValidationMessage(data) || data.message || 'Failed to update employment status settings')
  return data
}

/** Org heads + HR: get required-action checklist state for scoped employees. */
export async function getRegularizationRequiredActions(params = {}) {
  const q = new URLSearchParams()
  const userIds = Array.isArray(params.user_ids) ? params.user_ids : []
  userIds.forEach((id) => q.append('user_ids[]', String(id)))
  const res = await authenticatedFetch(`/regularization/required-actions${q.toString() ? `?${q.toString()}` : ''}`)
  const data = await res.json().catch(() => ({}))
  if (!res.ok) throw new Error(data.message || 'Failed to load required actions')
  return data
}

/** Org heads + HR: update required-action checklist for one employee. */
export async function updateRegularizationRequiredActions(userId, payload) {
  const res = await authenticatedFetch(`/regularization/required-actions/${userId}`, {
    method: 'PATCH',
    body: JSON.stringify(payload || {}),
  })
  const data = await res.json().catch(() => ({}))
  if (!res.ok) throw new Error(firstValidationMessage(data) || data.message || 'Failed to update required actions')
  return data
}

/** Employee status detail + history (admin). */
export async function getAdminEmployeeStatus(userId) {
  const res = await authenticatedFetch(`/admin/employee-status/${userId}`)
  const data = await res.json().catch(() => ({}))
  if (!res.ok) throw new Error(data.message || 'Failed to load employee status')
  return data
}

/**
 * Manual employment status override (admin).
 * @param {number} userId
 * @param {{ employment_status: string, effective_date?: string|null, remarks?: string|null }} payload
 */
export async function updateAdminEmployeeStatus(userId, payload) {
  const res = await authenticatedFetch(`/admin/employee-status/${userId}`, {
    method: 'PATCH',
    body: JSON.stringify(payload),
  })
  const data = await res.json().catch(() => ({}))
  if (!res.ok) {
    const msg = firstValidationMessage(data) || data.message || 'Failed to update employee status'
    throw new Error(msg)
  }
  return data
}

/**
 * Employee: create a leave request for the authenticated user.
 * @param {{ type: 'vacation'|'sick'|'emergency'|'other'|'undertime'|'half_day', start_date: string, end_date: string, undertime_time?: string, reason?: string }} payload
 */
export async function createMyLeaveRequest(payload) {
  const res = await authenticatedFetch('/leave', {
    method: 'POST',
    body: JSON.stringify(payload),
  })
  const data = await res.json().catch(() => ({}))
  if (!res.ok) {
    const msg = firstValidationMessage(data) || data.message || 'Failed to submit leave request'
    throw new Error(msg)
  }
  return data
}

/**
 * Employee: live undertime preview for a given date and early-out time.
 * @param {{ date: string, undertime_time: string }} params
 */
export async function getUndertimePreview(params) {
  const query = new URLSearchParams()
  if (params?.date) query.set('date', params.date)
  if (params?.undertime_time) query.set('undertime_time', params.undertime_time)
  const path = `/leave/undertime-preview${query.toString() ? `?${query.toString()}` : ''}`
  const res = await authenticatedFetch(path)
  const data = await res.json().catch(() => ({}))
  if (!res.ok) throw new Error(data.message || 'Failed to compute undertime preview')
  return data
}

/**
 * Employee: upload or replace supporting document for a leave request.
 * @param {number} id
 * @param {File} file
 */
export async function uploadMyLeaveDocument(id, file) {
  const formData = new FormData()
  formData.append('document', file)
  const res = await authenticatedFetch(`/leave/${id}/document`, {
    method: 'POST',
    body: formData,
  })
  const data = await res.json().catch(() => ({}))
  if (!res.ok) throw new Error(data.message || 'Failed to upload document')
  return data
}

export async function deleteMyLeaveRequest(id) {
  const res = await authenticatedFetch(`/leave/${id}`, { method: 'DELETE' })
  const data = await res.json().catch(() => ({}))
  if (!res.ok) throw new Error(firstValidationMessage(data) || data.message || 'Failed to delete leave request')
  return data
}

/**
 * Admin / HR panel: append a supporting document to a leave request (same rules as employee upload).
 * @param {number} id
 * @param {File} file
 */
export async function uploadAdminLeaveDocument(id, file) {
  const formData = new FormData()
  formData.append('document', file)
  const res = await authenticatedFetch(`/admin/leave/${id}/document`, {
    method: 'POST',
    body: formData,
  })
  const data = await res.json().catch(() => ({}))
  if (!res.ok) throw new Error(data.message || data.errors?.document?.[0] || 'Failed to upload document')
  return data
}

/**
 * Employee: overtime form context for a date (schedule end, clock status, pre-OT vs post-OT).
 * @param {string} dateYmd - YYYY-MM-DD
 * @returns {Promise<{
 *   date: string,
 *   has_assigned_schedule: boolean,
 *   is_workday: boolean,
 *   schedule_start: string | null,
 *   schedule_end: string | null,
 *   overnight_shift: boolean,
 *   has_clock_in: boolean,
 *   has_clock_out: boolean,
 *   last_clock_out_at: string | null,
 *   mode: string,
 *   mode_label: string,
 *   help: string,
 *   ph_ot_rule_options?: Array<{ code: string, day_type_label: string, ot_multiplier: number, first_8_multiplier: number }>,
 *   default_ph_ot_rule?: string,
 *   ph_ot_rule_help?: string,
 * }>}
 */
export async function getMyOvertimeRequestContext(dateYmd) {
  const q = new URLSearchParams({ date: String(dateYmd || '') })
  const res = await authenticatedFetch(`/overtime/request-context?${q.toString()}`)
  const data = await res.json().catch(() => ({}))
  if (!res.ok) {
    throw new Error(data.errors?.date?.[0] || data.message || 'Failed to load overtime context')
  }
  return data
}

/**
 * Employee: submit an overtime request for the authenticated user.
 * Uses FormData to support optional attachment upload.
 * @param {{ date: string, start_time: string, end_time: string, category: string, selected_segments?: string[], ph_ot_rule?: string, reason: string, attachment?: File|null }} payload
 */
export async function createMyOvertimeRequest(payload) {
  const formData = new FormData()
  formData.append('date', String(payload.date || ''))
  formData.append('start_time', String(payload.start_time || ''))
  formData.append('end_time', String(payload.end_time || ''))
  formData.append('category', String(payload.category || ''))
  if (Array.isArray(payload.selected_segments)) {
    payload.selected_segments
      .filter((s) => typeof s === 'string' && s.trim() !== '')
      .forEach((seg) => formData.append('selected_segments[]', String(seg)))
  }
  if (payload.ph_ot_rule != null && payload.ph_ot_rule !== '') {
    formData.append('ph_ot_rule', String(payload.ph_ot_rule))
  }
  if (payload.assignment_id != null && payload.assignment_id !== '') {
    formData.append('assignment_id', String(payload.assignment_id))
  }
  formData.append('reason', String(payload.reason || ''))
  if (payload.attachment instanceof File) {
    formData.append('attachment', payload.attachment)
  }

  const res = await wrapNetworkError(
    authenticatedFetch('/overtime/request', {
      method: 'POST',
      headers: { Accept: 'application/json' },
      body: formData,
    })
  )

  const data = await res.json().catch(() => ({}))
  if (!res.ok) {
    const msg =
      data.errors?.date?.[0] ||
      data.errors?.start_time?.[0] ||
      data.errors?.end_time?.[0] ||
      data.errors?.category?.[0] ||
      data.errors?.selected_segments?.[0] ||
      data.errors?.ph_ot_rule?.[0] ||
      data.errors?.reason?.[0] ||
      data.errors?.attachment?.[0] ||
      data.message ||
      'Failed to submit overtime request'
    throw new Error(msg)
  }

  return data
}

/**
 * Employee: list my overtime requests (paginated).
 * @param {{ per_page?: number, from_date?: string, to_date?: string, page?: number }} [params]
 */
export async function getMyOvertimeRequests(params = {}) {
  const q = new URLSearchParams()
  if (params.per_page != null) q.set('per_page', String(params.per_page))
  if (params.from_date) q.set('from_date', String(params.from_date))
  if (params.to_date) q.set('to_date', String(params.to_date))
  if (params.page != null) q.set('page', String(params.page))
  if (params.dashboard_lite) q.set('dashboard_lite', '1')
  const suffix = q.toString() ? `?${q.toString()}` : ''
  const res = await authenticatedFetch(`/overtime/my${suffix}`, params.signal ? { signal: params.signal } : {})
  const data = await res.json().catch(() => ({}))
  if (!res.ok) throw new Error(data.message || 'Failed to load overtime requests')
  return data
}

/**
 * Employee: all overtime requests in a date range (follows pagination until empty).
 * @param {string} from_date YYYY-MM-DD
 * @param {string} to_date YYYY-MM-DD
 */
export async function getAllMyOvertimeRequestsInRange(from_date, to_date, options = {}) {
  const perPage = 50
  let page = 1
  let lastPage = 1
  const all = []
  do {
    const data = await getMyOvertimeRequests({
      from_date,
      to_date,
      per_page: perPage,
      page,
      dashboard_lite: options.dashboard_lite,
      signal: options.signal,
    })
    const items = Array.isArray(data.overtimes) ? data.overtimes : []
    all.push(...items)
    lastPage = typeof data.pagination?.last_page === 'number' ? data.pagination.last_page : 1
    page += 1
  } while (page <= lastPage)
  return all
}

/**
 * Employee: single overtime request (full detail: approval chain, history).
 * @param {number} id
 */
export async function getMyOvertimeDetail(id) {
  const res = await authenticatedFetch(`/overtime/my/${id}`)
  const data = await res.json().catch(() => ({}))
  if (!res.ok) throw new Error(data.message || 'Failed to load overtime details')
  return data
}

/**
 * Employee: update a pending overtime request (multipart if attachment).
 * @param {number} id
 * @param {{ start_time: string, end_time: string, category: string, ph_ot_rule?: string, reason: string, attachment?: File|null }} payload
 */
export async function updateMyOvertimeRequest(id, payload) {
  const formData = new FormData()
  formData.append('start_time', String(payload.start_time || ''))
  formData.append('end_time', String(payload.end_time || ''))
  formData.append('category', String(payload.category || ''))
  if (payload.ph_ot_rule != null && payload.ph_ot_rule !== '') {
    formData.append('ph_ot_rule', String(payload.ph_ot_rule))
  }
  formData.append('reason', String(payload.reason || ''))
  if (payload.attachment instanceof File) {
    formData.append('attachment', payload.attachment)
  }
  const res = await authenticatedFetch(`/overtime/my/${id}`, { method: 'PATCH', body: formData })
  const data = await res.json().catch(() => ({}))
  if (!res.ok) {
    const msg =
      data.errors?.start_time?.[0] ||
      data.errors?.end_time?.[0] ||
      data.errors?.category?.[0] ||
      data.errors?.ph_ot_rule?.[0] ||
      data.errors?.reason?.[0] ||
      data.errors?.attachment?.[0] ||
      data.errors?.id?.[0] ||
      data.message ||
      'Failed to update overtime request'
    throw new Error(msg)
  }
  return data
}

/**
 * Employee: cancel (delete) a pending overtime request.
 */
export async function cancelMyOvertimeRequest(id) {
  const res = await authenticatedFetch(`/overtime/my/${id}`, { method: 'DELETE' })
  const data = await res.json().catch(() => ({}))
  if (!res.ok) {
    throw new Error(data.errors?.id?.[0] || data.message || 'Failed to cancel overtime request')
  }
  return data
}

/**
 * Update department. Pass { name?, department_head_id?, logo? } where logo is a File (JPG/PNG/WebP, max 2MB).
 * Sends multipart/form-data when logo is present.
 */
export async function updateDepartment(id, payload) {
  const hasFile = payload.logo instanceof File
  const headers = { Accept: 'application/json' }

  let body
  let method = 'PATCH'
  if (hasFile) {
    const form = new FormData()
    // Use method spoofing for multipart updates; some PHP stacks ignore files on true PATCH multipart.
    form.append('_method', 'PATCH')
    if (payload.name != null) form.append('name', payload.name)
    if (payload.office_location != null) form.append('office_location', String(payload.office_location))
    if (payload.description != null) form.append('description', String(payload.description))
    if (payload.branch_id != null && payload.branch_id !== '') form.append('branch_id', String(payload.branch_id))
    if (payload.division_id != null && payload.division_id !== '') form.append('division_id', String(payload.division_id))
    if (payload.company_id != null && payload.company_id !== '') form.append('company_id', String(payload.company_id))
    if (payload.department_head_id != null) form.append('department_head_id', payload.department_head_id === '' || payload.department_head_id === null ? '' : String(payload.department_head_id))
    form.append('logo', payload.logo)
    body = form
    method = 'POST'
    delete headers['Content-Type']
  } else {
    headers['Content-Type'] = 'application/json'
    body = JSON.stringify(payload)
  }

  const res = await authenticatedFetch(`/admin/departments/${id}`, { method, headers, body })
  const data = await res.json().catch(() => ({}))
  if (!res.ok) {
    const msg =
      data.errors?.department_head_id?.[0] ||
      data.errors?.team_leader_ids?.[0] ||
      data.errors?.logo?.[0] ||
      data.errors?.name?.[0] ||
      data.message ||
      'Failed to update department'
    throw new Error(msg)
  }
  clearGetCacheByPrefix('/admin/departments')
  return data
}

export async function deleteDepartment(id) {
  const res = await authenticatedFetch(`/admin/departments/${id}`, { method: 'DELETE' })
  const data = await res.json().catch(() => ({}))
  if (!res.ok) throw new Error(data.message || 'Failed to delete department')
  clearGetCacheByPrefix('/admin/departments')
  return data
}

export async function assignEmployeesToDepartment(departmentId, employeeIds, options = {}) {
  const res = await authenticatedFetch(`/admin/departments/${departmentId}/assign-employees`, {
    method: 'POST',
    body: JSON.stringify({
      employee_ids: employeeIds,
      assignment_mode: options.assignmentMode || 'transfer_primary',
      remarks: options.remarks || undefined,
    }),
  })
  const data = await res.json().catch(() => ({}))
  if (!res.ok) throw new Error(data.errors?.employee_ids?.[0] || data.message || 'Failed to assign employees')
  clearGetCacheByPrefix('/admin/departments')
  clearGetCacheByPrefix('/admin/divisions')
  clearGetCacheByPrefix('/admin/sections-or-units')
  return data
}

// —— Admin: Companies ——

// Admin: Divisions / Sections or Units

function orgQuery(params = {}, keys = ['company_id', 'branch_id', 'department_id', 'division_id', 'status', 'search']) {
  const query = new URLSearchParams()
  for (const key of keys) {
    if (params[key] != null && params[key] !== '') query.set(key, String(params[key]))
  }
  if (params.fresh) query.set('_ts', String(Date.now()))
  return query.toString()
}

async function jsonMutation(path, method, payload, fallbackMessage) {
  const res = await authenticatedFetch(path, {
    method,
    headers: { 'Content-Type': 'application/json' },
    body: JSON.stringify(payload ?? {}),
  })
  const data = await res.json().catch(() => ({}))
  if (!res.ok) {
    const msg = data.errors ? Object.values(data.errors).flat().join(' ') : data.message || fallbackMessage
    throw new Error(msg)
  }
  return data
}

export async function getDivisions(params = {}) {
  const suffix = orgQuery(params, ['company_id', 'branch_id', 'department_id', 'status', 'search'])
  const path = `/admin/divisions${suffix ? `?${suffix}` : ''}`
  return cachedAuthenticatedGetJson(path, { ttlMs: params.fresh ? 0 : 5 * 60 * 1000 })
}

export async function createDivision(payload) {
  const data = await jsonMutation('/admin/divisions', 'POST', payload, 'Failed to create division')
  clearGetCacheByPrefix('/admin/divisions')
  return data
}

export async function updateDivision(id, payload) {
  const data = await jsonMutation(`/admin/divisions/${id}`, 'PATCH', payload, 'Failed to update division')
  clearGetCacheByPrefix('/admin/divisions')
  return data
}

export async function deleteDivision(id) {
  const res = await authenticatedFetch(`/admin/divisions/${id}`, { method: 'DELETE' })
  const data = await res.json().catch(() => ({}))
  if (!res.ok) throw new Error(data.message || 'Failed to delete division')
  clearGetCacheByPrefix('/admin/divisions')
  return data
}

export async function getDivisionEmployees(divisionId) {
  const res = await authenticatedFetch(`/admin/divisions/${divisionId}/employees`)
  const data = await res.json().catch(() => ({}))
  if (!res.ok) throw new Error(data.message || 'Failed to load division employees')
  return data
}

export async function assignEmployeesToDivision(divisionId, employeeIds, options = {}) {
  const data = await jsonMutation(
    `/admin/divisions/${divisionId}/assign-employees`,
    'POST',
    {
      employee_ids: employeeIds,
      assignment_mode: options.assignmentMode || 'transfer_primary',
      remarks: options.remarks || undefined,
    },
    'Failed to assign employees',
  )
  clearGetCacheByPrefix('/admin/divisions')
  clearGetCacheByPrefix('/admin/employees')
  return data
}

export async function unassignEmployeesFromDivision(divisionId, employeeIds) {
  const data = await jsonMutation(`/admin/divisions/${divisionId}/unassign-employees`, 'POST', { employee_ids: employeeIds }, 'Failed to unassign employees')
  clearGetCacheByPrefix('/admin/divisions')
  clearGetCacheByPrefix('/admin/employees')
  return data
}

export async function getSectionsOrUnits(params = {}) {
  const suffix = orgQuery(params, ['company_id', 'branch_id', 'department_id', 'division_id', 'status', 'search'])
  const path = `/admin/sections-or-units${suffix ? `?${suffix}` : ''}`
  return cachedAuthenticatedGetJson(path, { ttlMs: params.fresh ? 0 : 5 * 60 * 1000 })
}

export async function createSectionOrUnit(payload) {
  const data = await jsonMutation('/admin/sections-or-units', 'POST', payload, 'Failed to create section/unit')
  clearGetCacheByPrefix('/admin/sections-or-units')
  return data
}

export async function updateSectionOrUnit(id, payload) {
  const data = await jsonMutation(`/admin/sections-or-units/${id}`, 'PATCH', payload, 'Failed to update section/unit')
  clearGetCacheByPrefix('/admin/sections-or-units')
  return data
}

export async function deleteSectionOrUnit(id) {
  const res = await authenticatedFetch(`/admin/sections-or-units/${id}`, { method: 'DELETE' })
  const data = await res.json().catch(() => ({}))
  if (!res.ok) throw new Error(data.message || 'Failed to delete section/unit')
  clearGetCacheByPrefix('/admin/sections-or-units')
  return data
}

export async function getSectionOrUnitEmployees(sectionUnitId) {
  const res = await authenticatedFetch(`/admin/sections-or-units/${sectionUnitId}/employees`)
  const data = await res.json().catch(() => ({}))
  if (!res.ok) throw new Error(data.message || 'Failed to load section/unit employees')
  return data
}

export async function assignEmployeesToSectionOrUnit(sectionUnitId, employeeIds, options = {}) {
  const data = await jsonMutation(
    `/admin/sections-or-units/${sectionUnitId}/assign-employees`,
    'POST',
    {
      employee_ids: employeeIds,
      assignment_mode: options.assignmentMode || 'transfer_primary',
      remarks: options.remarks || undefined,
    },
    'Failed to assign employees',
  )
  clearGetCacheByPrefix('/admin/sections-or-units')
  clearGetCacheByPrefix('/admin/employees')
  return data
}

export async function unassignEmployeesFromSectionOrUnit(sectionUnitId, employeeIds) {
  const data = await jsonMutation(`/admin/sections-or-units/${sectionUnitId}/unassign-employees`, 'POST', { employee_ids: employeeIds }, 'Failed to unassign employees')
  clearGetCacheByPrefix('/admin/sections-or-units')
  clearGetCacheByPrefix('/admin/employees')
  return data
}

export function companyLogoUrl(company) {
  if (!company) return undefined
  if (Object.prototype.hasOwnProperty.call(company, 'logo_url')) {
    if (typeof company.logo_url !== 'string' || company.logo_url.trim() === '') return undefined
    const logoUrl = company.logo_url.trim()
    if (logoUrl.startsWith('http://') || logoUrl.startsWith('https://')) return logoUrl
    const origin = apiOrigin()
    return logoUrl.startsWith('/') ? `${origin}${logoUrl}` : `${origin}/${logoUrl}`
  }
  if (!company.logo || typeof company.logo !== 'string') return undefined
  const path = company.logo.startsWith('storage/') ? company.logo : `storage/${company.logo}`
  return `${apiOrigin()}/${path}`
}

export async function getCompanies(params = {}) {
  const path = params.fresh ? `/admin/companies?_ts=${Date.now()}` : '/admin/companies'
  return cachedAuthenticatedGetJson(path, { ttlMs: params.fresh ? 0 : 5 * 60 * 1000 })
}

export async function createCompany(payload) {
  const hasFile = payload.logo instanceof File
  const headers = { Accept: 'application/json' }

  let body
  if (hasFile) {
    const form = new FormData()
    form.append('name', payload.name)
    if (payload.company_head_id != null && payload.company_head_id !== '') form.append('company_head_id', String(payload.company_head_id))
    if (payload.tin != null && payload.tin !== '') form.append('tin', String(payload.tin))
    if (payload.address != null && payload.address !== '') form.append('address', String(payload.address))
    form.append('logo', payload.logo)
    body = form
    delete headers['Content-Type']
  } else {
    headers['Content-Type'] = 'application/json'
    body = JSON.stringify({
      name: payload.name,
      ...(payload.company_head_id != null && payload.company_head_id !== '' ? { company_head_id: Number(payload.company_head_id) } : {}),
      ...(payload.tin != null && payload.tin !== '' ? { tin: String(payload.tin) } : {}),
      ...(payload.address != null && payload.address !== '' ? { address: String(payload.address) } : {}),
    })
  }

  const res = await authenticatedFetch('/admin/companies', { method: 'POST', headers, body })
  const data = await res.json().catch(() => ({}))
  if (!res.ok) throw new Error(data.errors ? Object.values(data.errors).flat().filter(Boolean)[0] || data.message : data.message || 'Failed to create company')
  return data
}

export async function updateCompany(id, payload) {
  const hasFile = payload.logo instanceof File
  const headers = { Accept: 'application/json' }

  let body
  let method = 'PATCH'
  if (hasFile) {
    const form = new FormData()
    form.append('_method', 'PATCH')
    if (payload.name != null) form.append('name', payload.name)
    if (payload.company_head_id != null) form.append('company_head_id', payload.company_head_id === '' || payload.company_head_id === null ? '' : String(payload.company_head_id))
    form.append('logo', payload.logo)
    body = form
    method = 'POST'
    delete headers['Content-Type']
  } else {
    headers['Content-Type'] = 'application/json'
    body = JSON.stringify(payload)
  }

  const res = await authenticatedFetch(`/admin/companies/${id}`, { method, headers, body })
  const data = await res.json().catch(() => ({}))
  if (!res.ok) throw new Error(data.errors ? Object.values(data.errors).flat().filter(Boolean)[0] || data.message : data.message || 'Failed to update company')
  return data
}

/** Company head only: profile fields + optional logo (PATCH /admin/companies/{id}/profile). */
export async function updateCompanyProfile(id, payload) {
  const hasFile = payload.logo instanceof File
  const headers = { Accept: 'application/json' }

  let body
  let method = 'PATCH'
  if (hasFile) {
    const form = new FormData()
    form.append('_method', 'PATCH')
    if (payload.phone != null) form.append('phone', payload.phone === '' ? '' : String(payload.phone))
    if (payload.email != null) form.append('email', payload.email === '' ? '' : String(payload.email))
    if (payload.tin != null) form.append('tin', payload.tin === '' ? '' : String(payload.tin))
    if (payload.address != null) form.append('address', payload.address === '' ? '' : String(payload.address))
    if (payload.founded_at != null) form.append('founded_at', payload.founded_at === '' ? '' : String(payload.founded_at))
    form.append('logo', payload.logo)
    body = form
    method = 'POST'
    delete headers['Content-Type']
  } else {
    headers['Content-Type'] = 'application/json'
    body = JSON.stringify({
      phone: payload.phone ?? null,
      email: payload.email ?? null,
      tin: payload.tin ?? null,
      address: payload.address ?? null,
      founded_at: payload.founded_at ?? null,
    })
  }

  const res = await authenticatedFetch(`/admin/companies/${id}/profile`, { method, headers, body })
  const data = await res.json().catch(() => ({}))
  if (!res.ok) throw new Error(data.errors ? Object.values(data.errors).flat().filter(Boolean)[0] || data.message : data.message || 'Failed to update company profile')
  return data
}

export async function deleteCompany(id) {
  const res = await authenticatedFetch(`/admin/companies/${id}`, { method: 'DELETE' })
  const data = await res.json().catch(() => ({}))
  if (!res.ok) throw new Error(data.message || 'Failed to delete company')
  return data
}

export async function getCompanyBranches(companyId) {
  const res = await authenticatedFetch(`/admin/companies/${companyId}/branches`)
  const data = await res.json().catch(() => ({}))
  if (!res.ok) throw new Error(data.message || 'Failed to load branches')
  return data
}

// —— Admin: Branches ——

export async function getBranches(params = {}) {
  const query = new URLSearchParams()
  if (params.company_id != null && params.company_id !== '') query.set('company_id', String(params.company_id))
  const path = `/admin/branches${query.toString() ? `?${query.toString()}` : ''}`
  return cachedAuthenticatedGetJson(path, { ttlMs: 5 * 60 * 1000 })
}

export async function createBranch(payload) {
  const res = await authenticatedFetch('/admin/branches', {
    method: 'POST',
    headers: { 'Content-Type': 'application/json' },
    body: JSON.stringify(payload),
  })
  const data = await res.json().catch(() => ({}))
  if (!res.ok) throw new Error(data.message || 'Failed to create branch')
  return data
}

export async function updateBranch(id, payload) {
  const res = await authenticatedFetch(`/admin/branches/${id}`, {
    method: 'PATCH',
    headers: { 'Content-Type': 'application/json' },
    body: JSON.stringify(payload),
  })
  const data = await res.json().catch(() => ({}))
  if (!res.ok) throw new Error(data.message || 'Failed to update branch')
  return data
}

export async function deleteBranch(id) {
  const res = await authenticatedFetch(`/admin/branches/${id}`, { method: 'DELETE' })
  const data = await res.json().catch(() => ({}))
  if (!res.ok) throw new Error(data.message || 'Failed to delete branch')
  return data
}

export async function getBranchDepartments(branchId) {
  const res = await authenticatedFetch(`/admin/branches/${branchId}/departments`)
  const data = await res.json().catch(() => ({}))
  if (!res.ok) throw new Error(data.message || 'Failed to load departments')
  return data
}

export async function resetEmployeePassword(id, password) {
  const res = await authenticatedFetch(`/admin/employees/${id}/reset-password`, {
    method: 'POST',
    headers: { 'Content-Type': 'application/json' },
    body: JSON.stringify({ password }),
  })
  const data = await res.json().catch(() => ({}))
  if (!res.ok) {
    const msg = data.errors?.password?.[0] || data.message || 'Failed to reset password'
    throw new Error(msg)
  }
  return data
}

/**
 * Get employee's registered face image (Admin). Returns { has_face, face_image }.
 * @param {number} id - Employee ID
 */
export async function getEmployeeFace(id) {
  const res = await authenticatedFetch(`/admin/employees/${id}/face`)
  const data = await res.json().catch(() => ({}))
  if (!res.ok) throw new Error(data.message || 'Failed to load face image')
  return data
}

/**
 * Get authenticated user's registered face image (Employee profile).
 * @returns {Promise<{ has_face: boolean, face_image: string|null }>}
 */
export async function getMyFace() {
  const res = await authenticatedFetch('/profile/face')
  const data = await res.json().catch(() => ({}))
  if (!res.ok) throw new Error(data.message || 'Failed to load face image')
  return data
}

/**
 * Register employee face. Use Rekognition liveness (recommended) or legacy image.
 * @param {number} id - Employee ID
 * @param {{ liveness_session_id?: string, image_base64?: string } | string} payload - liveness_session_id after Amplify liveness, or base64 string (legacy)
 * @param {string} [livenessType] - 'rekognition' (default), 'hybrid', etc.
 */
export async function registerEmployeeFace(id, payload, livenessType = 'rekognition') {
  const body =
    typeof payload === 'string'
      ? { image_base64: payload, liveness_type: livenessType }
      : {
          liveness_session_id: payload?.liveness_session_id,
          image_base64: payload?.image_base64,
          liveness_type: livenessType,
        }
  const res = await authenticatedFetch(`/admin/employees/${id}/face/register`, {
    method: 'POST',
    headers: { 'Content-Type': 'application/json' },
    body: JSON.stringify(body),
  })
  const data = await res.json().catch(() => ({}))
  if (res.status === 202 && data.track_id) {
    const polled = await pollFaceRegistrationUntilDone(
      `/admin/employees/${id}/face/register/status/${data.track_id}`
    )
    clearCachesAfterAdminEmployeeDataChange(id)
    return polled
  }
  if (!res.ok) {
    const msg = data.errors?.face?.[0] || data.message || 'Face registration failed'
    const err = new Error(msg)
    err.errorCode = data.error_code
    throw err
  }
  clearCachesAfterAdminEmployeeDataChange(id)
  return data
}

/**
 * @param {number} id
 * @param {{ face_descriptor: number[]|null, face_image?: string|null }} payload - 128D descriptor; null to remove face.
 */
export async function updateEmployeeFace(id, payload) {
  const res = await authenticatedFetch(`/admin/employees/${id}/face`, {
    method: 'PATCH',
    body: JSON.stringify(payload),
  })
  const data = await res.json().catch(() => ({}))
  if (!res.ok) {
    const msg = data.errors?.face?.[0] || data.message || 'Failed to save face'
    const err = new Error(msg)
    err.errorCode = data.error_code
    throw err
  }
  clearCachesAfterAdminEmployeeDataChange(id)
  return data
}

/**
 * @param {number} id
 * @param {{ schedule: object }} payload
 */
export async function updateEmployeeSchedule(id, payload) {
  const res = await authenticatedFetch(`/admin/employees/${id}/schedule`, {
    method: 'PATCH',
    body: JSON.stringify(payload),
  })
  const data = await res.json().catch(() => ({}))
  if (!res.ok) throw new Error(data.message || 'Failed to update schedule')
  clearCachesAfterAdminEmployeeDataChange(id)
  return data
}

/**
 * @param {number} id
 */
export async function toggleEmployeeActive(id) {
  const res = await authenticatedFetch(`/admin/employees/${id}/toggle-active`, {
    method: 'PATCH',
  })
  const data = await res.json().catch(() => ({}))
  if (!res.ok) throw new Error(data.message || 'Failed to update status')
  clearCachesAfterAdminEmployeeDataChange(id)
  return data
}

// —— Attendance (QR-verified clock-in/out) ——

/** Message shown when employee has no schedule assigned (must match backend). Attendance is never recorded without a schedule. */
export const NO_SCHEDULE_MESSAGE = 'No schedule assigned. Please contact the administrator.'

/**
 * Unified scan: Camera → Decode QR (employee_code) → POST /api/attendance/scan → Backend validation → JSON → UI feedback.
 * Backend validates schedule for today before allowing Clock In/Out; if none assigned, returns 422 with errors.schedule.
 * Use authenticated=true when called from employee page (sends Bearer), false for kiosk (no auth).
 *
 * @param {'clock_in'|'clock_out'} type
 * @param {string} qrToken - scanned QR token (employee_code)
 * @param {{ authenticated?: boolean }} [options] - pass { authenticated: true } for employee, false/omit for kiosk
 */
export async function recordAttendanceScan(type, qrToken, options = {}) {
  const { authenticated = false } = options
  const body = JSON.stringify({ type, qr_token: qrToken })
  const kioskHeaders = authenticated ? {} : { 'X-Kiosk-Attendance': '1' }
  const res = await wrapNetworkError(
    authenticated
      ? authenticatedFetch('/attendance/scan', { method: 'POST', body })
      : fetchWithSanctumCsrf('/attendance/scan', {
          method: 'POST',
          body,
          headers: kioskHeaders,
        })
  )
  const data = await res.json().catch(() => ({}))
  if (!res.ok) {
    // Real-time validation: no schedule → block attendance and show required message
    const scheduleErr = data.errors?.schedule?.[0]
    const msg =
      (scheduleErr && String(scheduleErr).trim()) ||
      data.errors?.qr_token?.[0] ||
      data.errors?.type?.[0] ||
      data.message ||
      'Failed to record attendance'
    const err = new Error(msg)
    err.errorCode = data.error_code || null
    err.kioskCorrection = data.kiosk_correction || null
    throw err
  }
  return data
}

/**
 * Record clock in/out for logged-in employee using QR token (uses unified /attendance/scan with auth).
 * @param {'clock_in'|'clock_out'} type
 * @param {string} qrToken - scanned QR token
 */
export async function recordAttendance(type, qrToken) {
  return recordAttendanceScan(type, qrToken, { authenticated: true })
}

export async function getAttendance() {
  const res = await authenticatedFetch('/attendance')
  if (!res.ok) throw new Error('Failed to load attendance')
  return res.json()
}

/**
 * Get monthly attendance summary for the authenticated employee.
 * Sends pagination hints so the API only runs payslip-parity payroll impact on the returned slice (merged automatically when the range spans multiple pages).
 * @param {{ from_date?: string, to_date?: string, page?: number, per_page?: number, full_summary?: boolean, merge_all_pages?: boolean, dashboard_lite?: boolean, signal?: AbortSignal }} params
 */
export async function getMyAttendanceSummary(params = {}) {
  const maxPerPage = EMPLOYEE_ATTENDANCE_SUMMARY_MAX_PER_PAGE
  const mergeAllPages = params.merge_all_pages !== false
  const pageParam = Number(params.page)
  const requestedPage =
    Number.isFinite(pageParam) && pageParam >= 1 ? Math.floor(pageParam) : 1

  function buildQuery(page) {
    const query = new URLSearchParams()
    if (params.from_date) query.set('from_date', params.from_date)
    if (params.to_date) query.set('to_date', params.to_date)
    if (params.dashboard_lite) query.set('dashboard_lite', '1')
    query.set('page', String(page))

    if (params.full_summary === true) {
      // omit per_page → legacy path (hydrates payroll for every day in range; slower)
    } else if (params.per_page != null && params.per_page !== '') {
      const perPage = normalizeAttendancePerPage(params.per_page, EMPLOYEE_ATTENDANCE_PAGE_SIZE)
      query.set('per_page', String(perPage))
    } else if (params.merge_all_pages) {
      const span = inclusiveAttendanceDaySpan(params.from_date, params.to_date)
      const perPage = Math.min(maxPerPage, Math.max(1, span))
      query.set('per_page', String(perPage))
    } else {
      query.set('per_page', String(normalizeAttendancePerPage(params.per_page, EMPLOYEE_ATTENDANCE_PAGE_SIZE)))
    }

    return query
  }

  const pathBase = `/attendance/summary`
  const firstQuery = buildQuery(requestedPage)
  const path = `${pathBase}?${firstQuery.toString()}`
  const res = await authenticatedFetch(path, params.signal ? { signal: params.signal } : {})
  const data = await res.json().catch(() => ({}))
  if (!res.ok) throw new Error(data.message || 'Failed to load attendance summary')

  const dm = data.meta?.days
  if (!mergeAllPages || !dm?.paginated || dm.last_page <= 1) return data

  const fetchPage = async (page) => {
    const q = buildQuery(page)
    const r = await authenticatedFetch(`${pathBase}?${q.toString()}`, params.signal ? { signal: params.signal } : {})
    const chunk = await r.json().catch(() => ({}))
    if (!r.ok) throw new Error(chunk.message || 'Failed to load attendance summary')
    return chunk
  }

  const pageNums = []
  for (let p = 2; p <= dm.last_page; p++) pageNums.push(p)
  const chunks = await Promise.all(pageNums.map((p) => fetchPage(p)))

  const days = [...(data.days || [])]
  for (const chunk of chunks) {
    days.push(...(chunk.days || []))
  }

  return {
    ...data,
    days,
    meta: {
      ...(data.meta || {}),
      days: { ...dm, paginated: false, merged_pages: dm.last_page },
    },
  }
}

/** Employee: submit presence filing (pending approval). */
export async function submitPresenceFiling(payload) {
  const ti = payload.time_in != null ? String(payload.time_in).trim() : ''
  const to = payload.time_out != null ? String(payload.time_out).trim() : ''
  const body = {
    date: payload.date != null ? String(payload.date) : undefined,
    issue_kind: String(payload.issue_kind),
    remarks: String(payload.remarks ?? '').trim(),
    time_in: ti === '' ? undefined : ti,
    time_out: to === '' ? undefined : to,
  }
  const res = await authenticatedFetch('/employee/presence-filing', {
    method: 'POST',
    headers: { 'Content-Type': 'application/json' },
    body: JSON.stringify(body),
  })
  const data = await res.json().catch(() => ({}))
  if (!res.ok) {
    const msg =
      data.errors?.date?.[0] ||
      data.errors?.issue_kind?.[0] ||
      data.errors?.time_in?.[0] ||
      data.errors?.time_out?.[0] ||
      data.errors?.remarks?.[0] ||
      data.message ||
      'Failed to submit presence filing'
    throw new Error(msg)
  }
  return data
}

export async function submitAdminPresenceFiling(payload) {
  const body = {
    employee_id: Number(payload.employee_id),
    date: String(payload.date),
    issue_kind: String(payload.issue_kind || payload.issue_type || ''),
    remarks: String(payload.remarks || '').trim(),
  }
  if (payload.time_in != null && String(payload.time_in).trim() !== '') body.time_in = String(payload.time_in).trim()
  if (payload.time_out != null && String(payload.time_out).trim() !== '') body.time_out = String(payload.time_out).trim()

  const res = await authenticatedFetch('/admin/presence-filings', {
    method: 'POST',
    headers: { 'Content-Type': 'application/json' },
    body: JSON.stringify(body),
  })
  const data = await res.json().catch(() => ({}))
  if (!res.ok) {
    const msg =
      firstValidationMessage(data) ||
      data.errors?.employee_id?.[0] ||
      data.errors?.date?.[0] ||
      data.errors?.time_in?.[0] ||
      data.errors?.time_out?.[0] ||
      data.errors?.remarks?.[0] ||
      data.message ||
      'Failed to submit presence filing'
    throw new Error(msg)
  }
  return data
}

/** Employee: list own correction / presence filings (history). */
export async function getMyPresenceFilings(params = {}) {
  const q = new URLSearchParams()
  if (params.from_date) q.set('from_date', params.from_date)
  if (params.to_date) q.set('to_date', params.to_date)
  if (params.page) q.set('page', String(params.page))
  if (params.per_page) q.set('per_page', String(params.per_page))
  const path = `/employee/presence-filings${q.toString() ? `?${q}` : ''}`
  const res = await authenticatedFetch(path)
  const data = await res.json().catch(() => ({}))
  if (!res.ok) throw new Error(data.message || 'Failed to load correction requests')
  return data
}

export async function getMyPresenceFilingDetail(id) {
  const res = await authenticatedFetch(`/employee/presence-filings/${encodeURIComponent(String(id))}`)
  const data = await res.json().catch(() => ({}))
  if (!res.ok) throw new Error(data.message || 'Failed to load correction request')
  return data
}

export async function getMyPresenceFiling() {
  const res = await authenticatedFetch('/employee/presence-filing')
  const data = await res.json().catch(() => ({}))
  if (!res.ok) throw new Error(data.message || 'Failed to load presence filing')
  return data
}

export async function getMyPresenceFilingAttendanceDetail(params = {}) {
  const q = new URLSearchParams()
  if (params.date) q.set('date', String(params.date))
  if (params.issue_type) q.set('issue_type', String(params.issue_type))
  const res = await authenticatedFetch(`/employee/presence-filing/attendance-detail?${q}`)
  const data = await res.json().catch(() => ({}))
  if (!res.ok) throw new Error(firstValidationMessage(data) || data.message || 'Failed to load attendance detail')
  return data
}

/**
 * Approvers: presence filings in scope (pending, approved, rejected, or all).
 * @param {{ status?: 'all' | 'pending' | 'approved' | 'rejected', from_date?: string, to_date?: string, issue_type?: string, q?: string }} params
 */
export async function getAdminPresenceFilings(params = {}) {
  const q = new URLSearchParams()
  if (params.status && params.status !== 'all') q.set('status', params.status)
  if (params.from_date) q.set('from_date', params.from_date)
  if (params.to_date) q.set('to_date', params.to_date)
  if (params.issue_type && params.issue_type !== 'all') q.set('issue_type', params.issue_type)
  if (params.q && String(params.q).trim()) q.set('q', String(params.q).trim())
  if (params.request_id != null && params.request_id !== '') q.set('request_id', String(params.request_id))
  if (params.page) q.set('page', String(params.page))
  if (params.per_page) q.set('per_page', String(params.per_page))
  const path = `/admin/presence-filings${q.toString() ? `?${q}` : ''}`
  const res = await authenticatedFetch(path)
  const data = await res.json().catch(() => ({}))
  if (!res.ok) throw new Error(data.message || 'Failed to load presence filings')
  return data
}

export async function getAdminPresenceFilingDetail(id) {
  const res = await authenticatedFetch(`/attendance-corrections/${encodeURIComponent(String(id))}/review`)
  const data = await res.json().catch(() => ({}))
  if (!res.ok) throw new Error(data.message || 'Failed to load correction request')
  return data
}

export async function getAdminPresenceFilingAttendanceDetail(params = {}) {
  const q = new URLSearchParams()
  if (params.employee_id != null && params.employee_id !== '') q.set('employee_id', String(params.employee_id))
  if (params.date) q.set('date', String(params.date))
  if (params.issue_type) q.set('issue_type', String(params.issue_type))
  const res = await authenticatedFetch(`/admin/presence-filings/attendance-detail?${q}`)
  const data = await res.json().catch(() => ({}))
  if (!res.ok) throw new Error(firstValidationMessage(data) || data.message || 'Failed to load attendance detail')
  return data
}

/**
 * @param {number|string} id
 * @param {{ notes?: string }} payload
 */
export async function approvePresenceFiling(id, payload = {}) {
  const res = await authenticatedFetch(`/admin/presence-filings/${id}/approve`, {
    method: 'POST',
    headers: { 'Content-Type': 'application/json' },
    body: JSON.stringify({
      notes: payload.notes != null ? String(payload.notes) : undefined,
    }),
  })
  const data = await res.json().catch(() => ({}))
  if (!res.ok) throw new Error(data.message || 'Failed to approve')
  return data
}

export async function bulkApprovePresenceFilingsPreview(filters = {}) {
  const res = await authenticatedFetch('/admin/presence-filings/bulk-approve-preview', {
    method: 'POST',
    headers: { 'Content-Type': 'application/json' },
    body: JSON.stringify({ filters }),
  })
  const data = await res.json().catch(() => ({}))
  if (!res.ok) throw new Error(data.message || 'Failed to count approvable attendance corrections')
  return data
}

/** @param {object|number[]} payloadOrIds - bulk payload or legacy id array */
export async function bulkApprovePresenceFilings(payloadOrIds, remarks = '') {
  const body =
    Array.isArray(payloadOrIds) || typeof payloadOrIds === 'number'
      ? {
          mode: 'selected_ids',
          ids: Array.isArray(payloadOrIds) ? payloadOrIds.map(Number) : [Number(payloadOrIds)],
          remarks: String(remarks || '').trim() || undefined,
        }
      : payloadOrIds
  const res = await authenticatedFetch('/admin/presence-filings/bulk-approve', {
    method: 'POST',
    headers: { 'Content-Type': 'application/json' },
    body: JSON.stringify(body),
  })
  const data = await res.json().catch(() => ({}))
  if (!res.ok) throw new Error(data.message || 'Failed to approve selected attendance corrections')
  return data
}

export async function rejectPresenceFiling(id, rejectionNote) {
  const res = await authenticatedFetch(`/admin/presence-filings/${id}/reject`, {
    method: 'POST',
    headers: { 'Content-Type': 'application/json' },
    body: JSON.stringify({ rejection_note: String(rejectionNote || '') }),
  })
  const data = await res.json().catch(() => ({}))
  if (!res.ok) throw new Error(data.message || 'Failed to reject')
  return data
}

/** Admin (HR): internal remark on a pending filing (audit only; does not advance workflow). */
export async function addPresenceFilingHrNote(id, notes) {
  const res = await authenticatedFetch(`/admin/presence-filings/${id}/note`, {
    method: 'POST',
    headers: { 'Content-Type': 'application/json' },
    body: JSON.stringify({ notes: String(notes || '').trim() }),
  })
  const data = await res.json().catch(() => ({}))
  if (!res.ok) throw new Error(data.message || 'Failed to save remark')
  return data
}

export async function deleteMyPresenceFiling(id) {
  const res = await authenticatedFetch(`/employee/presence-filings/${id}`, { method: 'DELETE' })
  const data = await res.json().catch(() => ({}))
  if (!res.ok) throw new Error(firstValidationMessage(data) || data.message || 'Failed to delete correction request')
  return data
}

export async function deleteAdminPresenceFiling(id) {
  const res = await authenticatedFetch(`/admin/presence-filings/${id}`, { method: 'DELETE' })
  const data = await res.json().catch(() => ({}))
  if (!res.ok) throw new Error(firstValidationMessage(data) || data.message || 'Failed to delete correction request')
  return data
}

/**
 * Get leave summary and list for the authenticated employee.
 * @param {{ from_date?: string, to_date?: string, status?: 'pending'|'approved'|'rejected' }} params
 */
export async function getMyLeaveSummary(params = {}) {
  const query = new URLSearchParams()
  if (params.from_date) query.set('from_date', params.from_date)
  if (params.to_date) query.set('to_date', params.to_date)
  if (params.status) query.set('status', params.status)
  if (params.dashboard_lite) query.set('dashboard_lite', '1')
  if (params.page) query.set('page', String(params.page))
  if (params.per_page) query.set('per_page', String(params.per_page))
  const path = `/leave/my${query.toString() ? `?${query.toString()}` : ''}`
  const res = await authenticatedFetch(path, params.signal ? { signal: params.signal } : {})
  const data = await res.json().catch(() => ({}))
  if (!res.ok) throw new Error(data.message || 'Failed to load leave summary')
  return data
}

/**
 * Employee: server-side paid vs unpaid leave preview (schedule-based billable days).
 * @param {{ type: string, start_date: string, end_date?: string, except_leave_request_id?: number }} params
 */
export async function getPaidLeavePreview(params) {
  const q = new URLSearchParams()
  q.set('type', String(params.type || ''))
  q.set('start_date', String(params.start_date || ''))
  if (params.end_date) q.set('end_date', String(params.end_date))
  if (params.except_leave_request_id != null) q.set('except_leave_request_id', String(params.except_leave_request_id))
  const res = await authenticatedFetch(`/leave/paid-leave-preview?${q}`)
  const data = await res.json().catch(() => ({}))
  if (!res.ok) throw new Error(data.message || 'Failed to preview leave credits')
  return data
}

/**
 * HR: adjust employee leave credits (audited). Requires employees.edit.
 * @param {number} employeeId
 * @param {{ delta: number, reason: string }} body
 */
export async function adjustEmployeeLeaveCredits(employeeId, body) {
  const res = await authenticatedFetch(`/admin/employees/${employeeId}/leave-credits/adjust`, {
    method: 'POST',
    headers: { 'Content-Type': 'application/json' },
    body: JSON.stringify({
      delta: body.delta,
      reason: String(body.reason || '').trim(),
    }),
  })
  const data = await res.json().catch(() => ({}))
  if (!res.ok) {
    const msg = firstValidationMessage(data) || data.message || 'Failed to adjust leave credits'
    throw new Error(msg)
  }
  return data
}

/** HR reports: leave credit balances per scoped employee. */
export async function getLeaveCreditsReport() {
  const res = await authenticatedFetch('/admin/reports/leave-credits')
  const data = await res.json().catch(() => ({}))
  if (!res.ok) throw new Error(data.message || 'Failed to load leave credits report')
  return data
}

/**
 * Get half-day availability flags (morning/afternoon clock-ins) for a specific date.
 * Used to enable/disable AM/PM half-day options in the employee leave form.
 * @param {string} date - YYYY-MM-DD
 */
export async function getHalfDayAvailability(date) {
  const query = new URLSearchParams()
  if (date) query.set('date', date)
  const path = `/leave/halfday-availability${query.toString() ? `?${query.toString()}` : ''}`
  const res = await authenticatedFetch(path)
  const data = await res.json().catch(() => ({}))
  if (!res.ok) throw new Error(data.message || 'Failed to load half-day availability')
  return data
}

// —— Kiosk (no login): QR-only clock in/out on login page ——

/**
 * Record clock in/out by QR token on kiosk (no auth). Uses unified POST /api/attendance/scan.
 * @param {'clock_in'|'clock_out'} type
 * @param {string} qrToken - scanned QR token (employee_code)
 */
export async function recordAttendanceKiosk(type, qrTokenOrLogin, options = {}) {
  const { useLoginFallback = false } = options
  if (useLoginFallback) {
    const res = await fetchWithSanctumCsrf('/attendance/kiosk', {
      method: 'POST',
      body: JSON.stringify({ type, login: qrTokenOrLogin }),
      headers: { 'X-Kiosk-Attendance': '1' },
    })
    const data = await res.json().catch(() => ({}))
    if (!res.ok) {
      const msg = data.errors?.login?.[0] || data.errors?.type?.[0] || data.message || 'Attendance failed'
      const err = new Error(msg)
      err.errorCode = data.error_code || null
      err.kioskCorrection = data.kiosk_correction || null
      throw err
    }
    return data
  }
  return recordAttendanceScan(type, qrTokenOrLogin, { authenticated: false })
}

/**
 * Record clock in/out by face on kiosk (no auth, face/liveness only).
 * @param {'clock_in'|'clock_out'} type
 * @param {{ liveness_session_id?: string, image_base64?: string } | string} payload - liveness_session_id (after Amplify liveness) or image_base64 (legacy), or raw base64 string
 */
export async function recordAttendanceKioskFace(type, payload) {
  const body =
    typeof payload === 'string'
      ? { type, image_base64: payload }
      : {
          type,
          login: payload?.login,
          liveness_session_id: payload?.liveness_session_id,
          image_base64: payload?.image_base64,
          company_id: payload?.company_id,
          device_id: payload?.device_id,
          camera_info: payload?.camera_info,
          client_capture_started_at_ms: payload?.client_capture_started_at_ms,
        }
  const res = await fetchWithSanctumCsrf('/attendance/kiosk/face', {
    method: 'POST',
    body: JSON.stringify(body),
    headers: { 'X-Kiosk-Attendance': '1' },
  })
  const data = await res.json().catch(() => ({}))
  if (!res.ok) {
    const msg = data.errors?.face?.[0] || data.errors?.type?.[0] || data.message || 'Face verification failed'
    const err = new Error(msg)
    err.errorCode = data.error_code || null
    err.kioskCorrection = data.kiosk_correction || null
    throw err
  }
  return data
}

/**
 * Get recent attendance logs for kiosk display (no auth).
 */
export async function getKioskRecentAttendance() {
  const base = String(API_BASE || '').replace(/\/$/, '')
  const path = `${base}/attendance/kiosk/recent`
  const res = await wrapNetworkError(
    fetchWithTimeout(path, { headers: { Accept: 'application/json' } })
  )
  const data = await res.json().catch(() => ({}))
  if (!res.ok) {
    const msg = data.message || `Failed to load recent attendance (${res.status})`
    throw new Error(msg)
  }
  return data
}

// —— Admin: Employee QR token management ——

export async function getEmployeeQr(id) {
  const res = await authenticatedFetch(`/admin/employees/${id}/qr`)
  const data = await res.json().catch(() => ({}))
  if (!res.ok) throw new Error(data.message || 'Failed to load employee QR')
  return data
}

export async function regenerateEmployeeQr(id) {
  const res = await authenticatedFetch(`/admin/employees/${id}/qr/regenerate`, { method: 'POST' })
  const data = await res.json().catch(() => ({}))
  if (!res.ok) throw new Error(data.message || 'Failed to regenerate QR')
  return data
}

export async function clearEmployeeQr(id) {
  const res = await authenticatedFetch(`/admin/employees/${id}/qr`, { method: 'DELETE' })
  const data = await res.json().catch(() => ({}))
  if (!res.ok) throw new Error(data.message || 'Failed to clear QR')
  return data
}

// —— Admin: Overtime Management ——

/**
 * Get overtime records for admin with filters and summary.
 * @param {{ from_date?: string, to_date?: string, department?: string, employee_id?: number, status?: 'pending'|'approved'|'rejected', ot_type?: string, page?: number, per_page?: number }} params
 */
export async function getAdminOvertime(params = {}) {
  const query = new URLSearchParams()
  if (params.from_date) query.set('from_date', params.from_date)
  if (params.to_date) query.set('to_date', params.to_date)
  if (params.department) query.set('department', params.department)
  if (params.employee_id) query.set('employee_id', String(params.employee_id))
  if (params.status) query.set('status', params.status)
  if (params.ot_type) query.set('ot_type', params.ot_type)
  if (params.page) query.set('page', String(params.page))
  if (params.per_page) query.set('per_page', String(params.per_page))

  const path = `/admin/overtime${query.toString() ? `?${query.toString()}` : ''}`
  const res = await authenticatedFetch(path)
  const data = await res.json().catch(() => ({}))
  if (!res.ok) throw new Error(data.message || 'Failed to load overtime records')
  return data
}

/**
 * Get a single overtime record with adjustment history.
 * @param {number} id
 */
export async function getAdminOvertimeDetail(id) {
  const res = await authenticatedFetch(`/overtime-requests/${encodeURIComponent(String(id))}/review`)
  const data = await res.json().catch(() => ({}))
  if (!res.ok) throw new Error(data.message || 'Failed to load overtime details')
  return data
}

/**
 * Update overtime status (approve/reject).
 * @param {number} id
 * @param {'approved'|'rejected'} status
 * @param {string} [remarks]
 */
export async function updateAdminOvertimeStatus(id, status, remarks) {
  const body = { status }
  if (remarks != null && String(remarks).trim() !== '') body.remarks = String(remarks).trim()

  const res = await authenticatedFetch(`/admin/overtime/${id}/status`, {
    method: 'PATCH',
    body: JSON.stringify(body),
  })
  const data = await res.json().catch(() => ({}))
  if (!res.ok) throw new Error(data.message || 'Failed to update overtime status')
  return data
}

export async function bulkApproveAdminOvertimePreview(filters = {}) {
  const res = await authenticatedFetch('/admin/overtime/bulk-approve-preview', {
    method: 'POST',
    headers: { 'Content-Type': 'application/json' },
    body: JSON.stringify({ filters }),
  })
  const data = await res.json().catch(() => ({}))
  if (!res.ok) throw new Error(data.message || 'Failed to count approvable overtime requests')
  return data
}

/** @param {object|number[]} payloadOrIds - bulk payload or legacy id array */
export async function bulkApproveAdminOvertime(payloadOrIds, remarks = '') {
  const body =
    Array.isArray(payloadOrIds) || typeof payloadOrIds === 'number'
      ? {
          mode: 'selected_ids',
          ids: Array.isArray(payloadOrIds) ? payloadOrIds.map(Number) : [Number(payloadOrIds)],
          remarks: String(remarks || '').trim() || undefined,
        }
      : payloadOrIds
  const res = await authenticatedFetch('/admin/overtime/bulk-approve', {
    method: 'POST',
    headers: { 'Content-Type': 'application/json' },
    body: JSON.stringify(body),
  })
  const data = await res.json().catch(() => ({}))
  if (!res.ok) throw new Error(data.message || 'Failed to approve selected overtime requests')
  return data
}

/**
 * Manually adjust overtime hours for a pending record.
 * @param {number} id
 * @param {number} hours - new overtime hours (e.g. 1.5)
 * @param {string} reason - required reason for audit log
 * @param {string} [notes] - optional notes
 */
export async function updateAdminOvertimeHours(id, hours, reason, notes) {
  const body = {
    hours: Number(hours),
    reason: String(reason ?? '').trim(),
  }
  if (!body.reason) {
    throw new Error('Reason is required for overtime adjustments.')
  }
  if (notes != null && String(notes).trim() !== '') body.notes = String(notes).trim()

  const res = await authenticatedFetch(`/admin/overtime/${id}/hours`, {
    method: 'PATCH',
    body: JSON.stringify(body),
  })
  const data = await res.json().catch(() => ({}))
  if (!res.ok) throw new Error(data.message || 'Failed to update overtime hours')
  return data
}

export async function deleteAdminOvertimeRequest(id) {
  const res = await authenticatedFetch(`/admin/overtime/${id}`, { method: 'DELETE' })
  const data = await res.json().catch(() => ({}))
  if (!res.ok) throw new Error(firstValidationMessage(data) || data.message || 'Failed to delete overtime request')
  return data
}

/**
 * Export overtime records as CSV. Returns a Blob for download.
 * @param {{ from_date?: string, to_date?: string, department?: string, employee_id?: number, status?: 'pending'|'approved'|'rejected', ot_type?: string }} params
 * @returns {Promise<Blob>}
 */
export async function exportAdminOvertimeCsv(params = {}) {
  const query = new URLSearchParams()
  if (params.from_date) query.set('from_date', params.from_date)
  if (params.to_date) query.set('to_date', params.to_date)
  if (params.department) query.set('department', params.department)
  if (params.employee_id) query.set('employee_id', String(params.employee_id))
  if (params.status) query.set('status', params.status)
  if (params.ot_type) query.set('ot_type', params.ot_type)
  query.set('format', 'csv')

  const token = getToken()
  const url = `${API_BASE}/admin/overtime/export${query.toString() ? `?${query.toString()}` : ''}`
  const res = await fetch(url, {
    method: 'GET',
    headers: {
      Accept: 'text/csv',
      ...(token ? { Authorization: `Bearer ${token}` } : {}),
    },
  })
  if (res.status === 401) {
    clearToken()
    throw new Error('Session expired. Please sign in again.')
  }
  if (!res.ok) {
    const data = await res.json().catch(() => ({}))
    throw new Error(data.message || 'Failed to export overtime records')
  }
  return res.blob()
}

// —— Admin: Users & RBAC ——

/**
 * @param {{ q?: string, hr_role?: string, is_active?: boolean|string, per_page?: number, page?: number }} params
 */
export async function getAdminUserAccounts(params = {}) {
  const query = new URLSearchParams()
  if (params.q) query.set('q', params.q)
  if (params.hr_role) query.set('hr_role', params.hr_role)
  if (params.department_id) query.set('department_id', String(params.department_id))
  if (params.is_active !== undefined && params.is_active !== '') query.set('is_active', String(params.is_active))
  if (params.per_page) query.set('per_page', String(params.per_page))
  if (params.page) query.set('page', String(params.page))
  const path = `/admin/user-accounts${query.toString() ? `?${query.toString()}` : ''}`
  const res = await authenticatedFetch(path)
  const data = await res.json().catch(() => ({}))
  if (!res.ok) throw new Error(data.message || 'Failed to load users')
  return data
}

/**
 * @param {{ action: 'activate' | 'deactivate', user_ids: number[] }} payload
 */
export async function bulkUpdateAdminUserAccounts(payload) {
  const res = await authenticatedFetch('/admin/user-accounts/bulk', {
    method: 'POST',
    headers: { 'Content-Type': 'application/json' },
    body: JSON.stringify(payload),
  })
  const data = await res.json().catch(() => ({}))
  if (!res.ok) {
    const msg = data.errors ? Object.values(data.errors).flat().filter(Boolean)[0] || data.message : data.message
    throw new Error(msg || 'Bulk update failed')
  }
  return data
}

export async function getAdminUserAccount(id) {
  const res = await authenticatedFetch(`/admin/user-accounts/${id}`)
  const data = await res.json().catch(() => ({}))
  if (!res.ok) throw new Error(data.message || 'Failed to load user')
  return data.user
}

/**
 * @param {{ name: string, email: string, password: string, hr_role: string, company_id?: number|null, branch_id?: number|null, department_id?: number|null, is_active?: boolean }} payload
 */
export async function createAdminUserAccount(payload) {
  const res = await authenticatedFetch('/admin/user-accounts', {
    method: 'POST',
    headers: { 'Content-Type': 'application/json' },
    body: JSON.stringify(payload),
  })
  const data = await res.json().catch(() => ({}))
  if (!res.ok) {
    const msg = data.errors ? Object.values(data.errors).flat().filter(Boolean)[0] || data.message : data.message
    throw new Error(msg || 'Failed to create user')
  }
  return data
}

/**
 * @param {number} id
 * @param {Record<string, unknown>} payload
 */
export async function updateAdminUserAccount(id, payload) {
  const res = await authenticatedFetch(`/admin/user-accounts/${id}`, {
    method: 'PATCH',
    headers: { 'Content-Type': 'application/json' },
    body: JSON.stringify(payload),
  })
  const data = await res.json().catch(() => ({}))
  if (!res.ok) {
    const msg = data.errors ? Object.values(data.errors).flat().filter(Boolean)[0] || data.message : data.message
    throw new Error(msg || 'Failed to update user')
  }
  return data
}

export async function resetAdminUserAccountPassword(id, password) {
  const res = await authenticatedFetch(`/admin/user-accounts/${id}/reset-password`, {
    method: 'POST',
    headers: { 'Content-Type': 'application/json' },
    body: JSON.stringify({ password }),
  })
  const data = await res.json().catch(() => ({}))
  if (!res.ok) throw new Error(data.message || 'Failed to reset password')
  return data
}

export async function getAdminUserAccountActivity(id) {
  const res = await authenticatedFetch(`/admin/user-accounts/${id}/activity`)
  const data = await res.json().catch(() => ({}))
  if (!res.ok) throw new Error(data.message || 'Failed to load activity')
  return data.logs ?? []
}

export async function getRbacMatrix() {
  const res = await authenticatedFetch('/admin/rbac/matrix')
  const data = await res.json().catch(() => ({}))
  if (!res.ok) throw new Error(data.message || 'Failed to load permission matrix')
  return data
}

export async function getRbacAuditLog() {
  const res = await authenticatedFetch('/admin/rbac/audit')
  const data = await res.json().catch(() => ({}))
  if (!res.ok) throw new Error(data.message || 'Failed to load audit log')
  return data.logs ?? []
}

/**
 * @param {string} roleKey - e.g. company_head
 * @param {string[]} permissionSlugs
 */
export async function syncRbacRolePermissions(roleKey, permissionSlugs) {
  const res = await authenticatedFetch(`/admin/rbac/roles/${encodeURIComponent(roleKey)}`, {
    method: 'PUT',
    headers: { 'Content-Type': 'application/json' },
    body: JSON.stringify({ permission_slugs: permissionSlugs }),
  })
  const data = await res.json().catch(() => ({}))
  if (!res.ok) throw new Error(data.message || 'Failed to update role permissions')
  return data
}

/**
 * Restore role grants from config/rbac.php defaults (server-side).
 * @param {string} roleKey
 */
export async function resetRbacRoleToDefaults(roleKey) {
  const res = await authenticatedFetch(
    `/admin/rbac/roles/${encodeURIComponent(roleKey)}/reset-defaults`,
    {
      method: 'POST',
      headers: { 'Content-Type': 'application/json' },
    }
  )
  const data = await res.json().catch(() => ({}))
  if (!res.ok) throw new Error(data.message || 'Failed to restore default permissions')
  return data
}

// —— Employee: loans & deductions (self-service) ——

export async function getEmployeeLoanRequestContext() {
  const res = await authenticatedFetch('/employee/loan-requests/context')
  const data = await res.json().catch(() => ({}))
  if (!res.ok) throw new Error(data.message || 'Failed to load loan context')
  return data
}

/** @returns {Promise<{ employee_deductions: unknown[], loan_requests: unknown[] }>} */
export async function getEmployeeMyDeductions() {
  const res = await authenticatedFetch('/employee/my-deductions')
  const data = await res.json().catch(() => ({}))
  if (!res.ok) throw new Error(data.message || 'Failed to load deductions')
  return {
    employee_deductions: data.employee_deductions ?? [],
    loan_requests: data.loan_requests ?? [],
  }
}

export async function createEmployeeLoanRequest(payload) {
  const res = await authenticatedFetch('/employee/loan-requests', {
    method: 'POST',
    headers: { 'Content-Type': 'application/json' },
    body: JSON.stringify(payload),
  })
  const data = await res.json().catch(() => ({}))
  if (!res.ok) {
    const details = data.errors ? Object.values(data.errors).flat().join(' ') : ''
    throw new Error(details || data.message || 'Failed to submit loan request')
  }
  return data.loan_request ?? data
}

export async function getEmployeeNextDeductionDates(params = {}) {
  const query = new URLSearchParams()
  if (params.schedule_type) query.set('schedule_type', String(params.schedule_type))
  if (params.as_of_date) query.set('as_of_date', String(params.as_of_date))
  const res = await authenticatedFetch(`/employee/loan-requests/next-deduction-dates?${query.toString()}`)
  const data = await res.json().catch(() => ({}))
  if (!res.ok) throw new Error(data.message || 'Failed to load next deduction dates')
  return data
}

/**
 * Employee: fetch full loan request detail (matching admin shape).
 * Returns { loan_request, approval_progress, pay_cycle_preview, next_deduction_dates }.
 */
export async function getEmployeeLoanRequestDetail(id) {
  const res = await authenticatedFetch(`/employee/loan-requests/${id}`)
  const data = await res.json().catch(() => ({}))
  if (!res.ok) throw new Error(data.message || 'Failed to load loan request detail')
  return data
}

// —— Admin: deductions & loans ——

export async function getAdminDeductionTypes() {
  const res = await authenticatedFetch('/admin/deduction-types')
  const data = await res.json().catch(() => ({}))
  if (!res.ok) throw new Error(data.message || 'Failed to load deduction types')
  return data
}

export async function createAdminDeductionType(payload) {
  const res = await authenticatedFetch('/admin/deduction-types', {
    method: 'POST',
    headers: { 'Content-Type': 'application/json' },
    body: JSON.stringify(payload),
  })
  const data = await res.json().catch(() => ({}))
  if (!res.ok) throw new Error(data.message || 'Failed to create deduction type')
  return data
}

export async function updateAdminDeductionType(id, payload) {
  const res = await authenticatedFetch(`/admin/deduction-types/${id}`, {
    method: 'PATCH',
    headers: { 'Content-Type': 'application/json' },
    body: JSON.stringify(payload),
  })
  const data = await res.json().catch(() => ({}))
  if (!res.ok) throw new Error(data.message || 'Failed to update deduction type')
  return data
}

export async function deleteAdminDeductionType(id) {
  const res = await authenticatedFetch(`/admin/deduction-types/${id}`, {
    method: 'PATCH',
    headers: { 'Content-Type': 'application/json' },
    body: JSON.stringify({ is_active: false }),
  })
  const data = await res.json().catch(() => ({}))
  if (!res.ok) throw new Error(data.message || 'Failed to delete deduction type')
  return data
}

export async function getAdminActiveEmployeeDeductionsInScope() {
  const res = await authenticatedFetch('/admin/employee-deductions/active')
  const data = await res.json().catch(() => ({}))
  if (!res.ok) throw new Error(data.message || 'Failed to load active deductions')
  return data.employee_deductions ?? []
}

export async function getAdminLoanRequests() {
  const res = await authenticatedFetch('/admin/loan-requests')
  const data = await res.json().catch(() => ({}))
  if (!res.ok) throw new Error(data.message || 'Failed to load loan requests')
  return data.loan_requests ?? []
}

export async function getAdminLoanRequestDetail(id) {
  const res = await authenticatedFetch(`/admin/loan-requests/${id}`)
  const data = await res.json().catch(() => ({}))
  if (!res.ok) throw new Error(data.message || 'Failed to load loan request')
  return data
}

export async function approveAdminLoanRequest(id, payload = {}) {
  const res = await authenticatedFetch(`/admin/loan-requests/${id}/approve`, {
    method: 'POST',
    headers: { 'Content-Type': 'application/json' },
    body: JSON.stringify(payload),
  })
  const data = await res.json().catch(() => ({}))
  if (!res.ok) throw new Error(data.message || 'Failed to approve request')
  return data
}

export async function rejectAdminLoanRequest(id, payload = {}) {
  const res = await authenticatedFetch(`/admin/loan-requests/${id}/reject`, {
    method: 'POST',
    headers: { 'Content-Type': 'application/json' },
    body: JSON.stringify(payload),
  })
  const data = await res.json().catch(() => ({}))
  if (!res.ok) throw new Error(data.message || 'Failed to reject request')
  return data
}

export async function createAdminEmployeePayDeduction(userId, payload) {
  const res = await authenticatedFetch(`/admin/employees/${userId}/pay-deductions`, {
    method: 'POST',
    headers: { 'Content-Type': 'application/json' },
    body: JSON.stringify(payload),
  })
  const data = await res.json().catch(() => ({}))
  if (!res.ok) throw new Error(data.message || 'Failed to assign deduction')
  return data.employee_deduction ?? data
}

export async function postAdminEmployeeDeductionEarlyPayoff(userId, deductionId) {
  const res = await authenticatedFetch(`/admin/employees/${userId}/pay-deductions/${deductionId}/early-payoff`, {
    method: 'POST',
    headers: { 'Content-Type': 'application/json' },
    body: JSON.stringify({}),
  })
  const data = await res.json().catch(() => ({}))
  if (!res.ok) throw new Error(data.message || 'Failed to process early payoff')
  return data.employee_deduction ?? data
}

export async function patchAdminEmployeeDeductionBalance(userId, deductionId, remainingBalance) {
  const res = await authenticatedFetch(`/admin/employees/${userId}/pay-deductions/${deductionId}/balance`, {
    method: 'PATCH',
    headers: { 'Content-Type': 'application/json' },
    body: JSON.stringify({ remaining_balance: remainingBalance }),
  })
  const data = await res.json().catch(() => ({}))
  if (!res.ok) throw new Error(data.message || 'Failed to adjust loan balance')
  return data.employee_deduction ?? data
}

export async function getAdminEmployeeDeductionAuditLogs(userId, deductionId) {
  const res = await authenticatedFetch(`/admin/employees/${userId}/pay-deductions/${deductionId}/audit-logs`)
  const data = await res.json().catch(() => ({}))
  if (!res.ok) throw new Error(data.message || 'Failed to load deduction audit logs')
  return data
}

export async function getOrganizationLeadership(legacyType, legacyId) {
  const res = await authenticatedFetch(`/admin/organization-leadership/${legacyType}/${legacyId}`)
  const data = await res.json().catch(() => ({}))
  if (!res.ok) throw new Error(data.message || 'Failed to load leadership positions')
  return data
}

export async function updateOrganizationLeadership(legacyType, legacyId, payload) {
  const res = await authenticatedFetch(`/admin/organization-leadership/${legacyType}/${legacyId}`, {
    method: 'PUT',
    headers: { 'Content-Type': 'application/json', Accept: 'application/json' },
    body: JSON.stringify(payload),
  })
  const data = await res.json().catch(() => ({}))
  if (!res.ok) {
    const firstFieldError = data?.errors
      ? Object.values(data.errors).flat().find(Boolean)
      : null
    throw new Error(firstFieldError || data.message || 'Failed to update leadership positions')
  }
  return data
}

export async function getEmployeeApprovalRoutePreview(employeeId, params = {}) {
  const query = new URLSearchParams()
  if (params.request_type) query.set('request_type', params.request_type)
  const suffix = query.toString() ? `?${query.toString()}` : ''
  const res = await authenticatedFetch(`/admin/employees/${employeeId}/approval-route-preview${suffix}`)
  const data = await res.json().catch(() => ({}))
  if (!res.ok) throw new Error(data.message || 'Failed to load approval route preview')
  return data
}

export async function getApprovalWorkflowSettings() {
  const res = await authenticatedFetch('/admin/approval-workflow-settings')
  const data = await res.json().catch(() => ({}))
  if (!res.ok) throw new Error(data.message || 'Failed to load approval workflow settings')
  return data
}

export async function updateApprovalWorkflowSettings(payload) {
  const res = await authenticatedFetch('/admin/approval-workflow-settings', {
    method: 'PUT',
    headers: { 'Content-Type': 'application/json', Accept: 'application/json' },
    body: JSON.stringify(payload),
  })
  const data = await res.json().catch(() => ({}))
  if (!res.ok) throw new Error(data.message || 'Failed to update approval workflow settings')
  return data
}
