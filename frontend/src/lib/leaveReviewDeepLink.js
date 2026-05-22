/**
 * Parse dashboard / leave-module deep-link leave request id (leave_requests.id only).
 * @param {string | null | undefined} raw
 * @returns {string | null}
 */
export function parseLeaveReviewRequestId(raw) {
  if (raw == null || raw === '') return null
  const s = String(raw).trim()
  if (!/^\d+$/.test(s)) return null
  const n = Number(s)
  if (!Number.isFinite(n) || n <= 0) return null
  return String(n)
}

/**
 * @param {unknown} payload
 * @returns {object | null}
 */
export function extractLeaveRequestFromReviewPayload(payload) {
  if (!payload || typeof payload !== 'object') return null
  const row = payload.leave_request ?? payload.data?.leave_request ?? payload.data
  if (!row || typeof row !== 'object') return null
  if (row.id == null && row.leave_request_id == null) return null
  return row
}

/** @param {string} code */
export function leaveReviewErrorMessage(code) {
  switch (code) {
    case 'missing_id':
      return 'Request ID is missing from the link. Open the request again from the dashboard.'
    case 'invalid_id':
      return 'Invalid leave request ID in the URL.'
    case 'not_found':
      return 'Leave request not found. It may be outside your scope or was removed.'
    case 'forbidden':
      return 'You do not have permission to view this leave request.'
    case 'server_error':
      return 'Server error while loading this request. Please try again.'
    default:
      return 'Unable to load request details. Please try again.'
  }
}

/**
 * @param {string} context
 * @param {{ reviewRequestId?: string | null, url?: string, status?: number, body?: unknown, error?: unknown, user?: object | null, search?: string }} detail
 */
export function logLeaveReviewFetchFailure(context, detail) {
  if (!import.meta.env.DEV) return
  console.error(`[leave-review] ${context}`, {
    reviewRequestId: detail.reviewRequestId ?? null,
    endpoint: detail.url ?? null,
    status: detail.status ?? null,
    responseBody: detail.body ?? null,
    error: detail.error ?? null,
    userRole: detail.user?.role ?? null,
    hrRole: detail.user?.hr_role ?? null,
    routeSearch: detail.search ?? null,
  })
}
