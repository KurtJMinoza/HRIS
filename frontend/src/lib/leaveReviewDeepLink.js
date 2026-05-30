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
  const id = row.id ?? row.request_id ?? row.leave_request_id
  if (id == null) return null
  let currentPendingMarked = false
  const approvalProgress =
    row.approval_progress ??
    (Array.isArray(row.approval_chain)
      ? row.approval_chain.map((step, index) => {
          const rawStatus = String(step?.status || '').toLowerCase()
          const status =
            rawStatus === 'approved'
              ? 'completed'
              : rawStatus === 'rejected'
              ? 'rejected'
              : rawStatus === 'pending' && !currentPendingMarked
              ? 'current'
              : rawStatus || 'pending'
          if (status === 'current') currentPendingMarked = true
          return {
            ...step,
            key: step?.key ?? `approval-${step?.id ?? index}`,
            label: step?.label ?? step?.stage ?? step?.role_label ?? 'Approval step',
            status,
            approver_role_label: step?.approver_role_label ?? step?.role_label,
            acted_at: step?.acted_at ?? step?.approved_at,
          }
        })
      : undefined)
  return {
    ...row,
    id,
    employee_id: row.employee_id ?? row.requester_id,
    type: row.type ?? row.leave_type,
    notes: row.notes ?? row.remarks,
    created_at: row.created_at ?? row.date_filed,
    approval_stage: row.approval_stage ?? row.current_stage,
    approval_progress: approvalProgress,
    actor_can_approve: row.actor_can_approve ?? row.can_approve,
    actor_can_reject: row.actor_can_reject ?? row.can_reject,
  }
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
