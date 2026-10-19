import { parseReviewRequestId } from '@/lib/leaveReviewDeepLink'

/**
 * Employee self-service path (+ query) to open the matching request review modal.
 * @param {{ kind?: string, id?: number | string }} filing
 * @returns {string | null}
 */
export function employeeFilingReviewHref(filing) {
  const id = parseReviewRequestId(filing?.id)
  if (!id) return null
  const q = `review_id=${encodeURIComponent(id)}`
  switch (String(filing?.kind || '').toLowerCase()) {
    case 'leave':
      return `requests?${q}`
    case 'overtime':
      return `overtime?${q}`
    case 'correction':
      return `correction-requests?${q}`
    default:
      return null
  }
}

/**
 * @param {{ kind?: string, id?: number | string, status?: string, subject_name?: string }} filing
 * @returns {Record<string, unknown> | undefined}
 */
export function employeeFilingReviewNavigationState(filing) {
  const id = parseReviewRequestId(filing?.id)
  if (!id) return undefined
  const status = filing?.status != null ? String(filing.status) : undefined
  switch (String(filing?.kind || '').toLowerCase()) {
    case 'leave':
      return {
        leaveReviewSeed: {
          id: Number(id),
          status,
          employee_name: filing?.subject_name || undefined,
        },
      }
    case 'overtime':
      return {
        overtimeReviewSeed: {
          id: Number(id),
          status,
        },
      }
    case 'correction':
      return {
        correctionReviewSeed: {
          id: Number(id),
          status,
        },
      }
    default:
      return undefined
  }
}
