import { Avatar, AvatarFallback, AvatarImage } from '@/components/ui/avatar'
import { cn } from '@/lib/utils'
import { profileImageUrl } from '@/api'
import { sanitizeApprovalDisplayText } from '@/lib/approvalText'

function formatDateTime(iso) {
  if (!iso) return '—'
  const d = new Date(iso)
  return d.toLocaleString('en-PH', { dateStyle: 'medium', timeStyle: 'short' })
}

function humanStepStatus(status) {
  switch (status) {
    case 'completed':
      return 'Completed'
    case 'current':
      return 'Pending'
    case 'pending':
      return 'Pending'
    case 'rejected':
      return 'Rejected'
    case 'skipped':
      return 'Skipped'
    default:
      return status ? String(status) : '—'
  }
}

function approverDisplayName(step) {
  if (!step || typeof step !== 'object') return '—'
  if (step.key === 'submitted') {
    return step.submitter_name || '—'
  }
  return step.approver_name || step.approver_role_label || '—'
}

function approverDisplayRole(step) {
  if (!step || typeof step !== 'object') return ''
  if (step.key === 'submitted') return 'Requester'
  return step.approver_role_label || step.approver_role || ''
}

function getInitials(name) {
  if (!name || typeof name !== 'string') return '?'
  const parts = name.trim().split(/\s+/).filter(Boolean)
  if (parts.length === 0) return '?'
  if (parts.length === 1) return parts[0].slice(0, 2).toUpperCase()
  return `${parts[0][0] ?? ''}${parts[parts.length - 1][0] ?? ''}`.toUpperCase() || '?'
}

/** Resolve avatar src from API step (`profile_image_url` from backend). */
function stepAvatarSrc(step) {
  const raw = step?.profile_image_url
  if (raw == null || raw === '') return undefined
  return profileImageUrl(typeof raw === 'string' ? raw : String(raw))
}

/**
 * Full stepper for request details modals — Overtime, Leave, Attendance Corrections (shared).
 * @param {{ steps: object[] }} props
 */
export function ApprovalChainDetailView({ steps }) {
  if (!Array.isArray(steps) || steps.length === 0) return null

  return (
    <ol className="list-none space-y-3">
      {steps.map((s, idx) => {
        const stepNum = idx + 1
        const name = approverDisplayName(s)
        const roleLine = approverDisplayRole(s)
        const avatarSrc = stepAvatarSrc(s)
        const statusLabel = humanStepStatus(s.status)
        const statusDateLine =
          s.acted_at != null && s.acted_at !== ''
            ? `${statusLabel} · ${formatDateTime(s.acted_at)}`
            : statusLabel
        const remarks = sanitizeApprovalDisplayText(s?.remarks)

        return (
          <li key={s.key || `step-${idx}`}>
            <div
              className={cn(
                'rounded-xl border border-border/60 bg-card px-4 py-4 shadow-sm dark:border-border/50',
                s.status === 'current' &&
                  'border-amber-400/80 bg-amber-50/90 ring-2 ring-amber-500/25 dark:border-amber-600/50 dark:bg-amber-950/35 dark:ring-amber-400/20'
              )}
            >
              <p className="border-b border-border/40 pb-3 text-[11px] font-bold uppercase tracking-[0.12em] text-muted-foreground">
                <span className="tabular-nums text-muted-foreground/90">{stepNum}. </span>
                {s.label}
              </p>

              <div className="mt-3 flex gap-3">
                <Avatar
                  className={cn(
                    'size-11 shrink-0 border-2 border-border/70 bg-muted/40 shadow-sm ring-2 ring-background',
                    'dark:border-border/60 dark:bg-muted/30'
                  )}
                >
                  {avatarSrc ? <AvatarImage src={avatarSrc} alt="" className="object-cover" /> : null}
                  <AvatarFallback className="text-xs font-semibold tracking-tight text-foreground">
                    {getInitials(name)}
                  </AvatarFallback>
                </Avatar>

                <div className="min-w-0 flex-1 space-y-1">
                  <p className="text-[15px] font-semibold leading-snug text-foreground">{name}</p>
                  {roleLine ? <p className="text-xs font-medium text-muted-foreground">{roleLine}</p> : null}
                  {s?.is_self_approval ? (
                    <span className="inline-flex w-fit rounded-full border border-amber-300/70 bg-amber-50 px-2.5 py-1 text-[10px] font-black uppercase tracking-[0.14em] text-amber-800 dark:border-amber-400/40 dark:bg-amber-950/35 dark:text-amber-200">
                      Self Approval
                    </span>
                  ) : null}
                  <p className="text-xs leading-relaxed text-muted-foreground">{statusDateLine}</p>
                  {remarks ? (
                    <p className="pt-1 text-sm leading-relaxed text-foreground/90">
                      <span className="font-semibold text-muted-foreground">Remarks: </span>
                      {remarks}
                    </p>
                  ) : null}
                </div>
              </div>
            </div>
          </li>
        )
      })}
    </ol>
  )
}
