/** Dispatched after actions that shrink admin overtime / corrections / dashboard pending queues. */
export const HR_PENDING_APPROVALS_CHANGED = 'hr:pending-approvals-changed'

export function notifyPendingApprovalsChanged() {
  if (typeof window === 'undefined') return
  try {
    window.dispatchEvent(new CustomEvent(HR_PENDING_APPROVALS_CHANGED))
  } catch {
    /* ignore */
  }
}
