import { useMemo } from 'react'
import { Avatar, AvatarFallback, AvatarImage } from '@/components/ui/avatar'
import { cn } from '@/lib/utils'
import { profileImageUrl } from '@/api'
import { formatDateTime, formatElapsed } from '@/components/approval/ApprovalChainDetailView'
import { normalizeApprovalHeadTitle } from '@/lib/approvalText'

const STATUS_COLORS = {
  completed: 'border-emerald-200/80 bg-emerald-50 text-emerald-800 dark:border-emerald-500/35 dark:bg-emerald-500/15 dark:text-emerald-200',
  current: 'border-amber-300/80 bg-amber-50 text-amber-900 dark:border-amber-500/30 dark:bg-amber-500/12 dark:text-amber-100',
  pending: 'border-border/50 bg-muted/30 text-muted-foreground',
  rejected: 'border-red-200/80 bg-red-50 text-red-900 dark:border-red-500/35 dark:bg-red-500/12 dark:text-red-100',
  skipped: 'border-border/30 bg-muted/20 text-muted-foreground/60',
  cancelled: 'border-border/50 bg-muted/40 text-muted-foreground',
}

function getInitials(name) {
  if (!name || typeof name !== 'string') return '?'
  const parts = name.trim().split(/\s+/).filter(Boolean)
  if (parts.length === 0) return '?'
  if (parts.length === 1) return parts[0].slice(0, 2).toUpperCase()
  return `${parts[0][0] ?? ''}${parts[parts.length - 1][0] ?? ''}`.toUpperCase() || '?'
}

function stepAvatarSrc(step) {
  const raw = step?.profile_image_url
  if (raw == null || raw === '') return undefined
  return profileImageUrl(typeof raw === 'string' ? raw : String(raw))
}

export function ApprovalTimeline({ steps }) {
  const { currentStep, progress, isFullyComplete, isRejected, isCancelled } = useMemo(() => {
    if (!Array.isArray(steps)) return { currentStep: null, progress: 0, isFullyComplete: false, isRejected: false, isCancelled: false }

    const current = steps.find((s) => s.status === 'current') || null
    const done = steps.filter((s) => s.status === 'completed').length
    const total = steps.length
    const pct = total > 0 ? Math.round((done / total) * 100) : 0
    const rejected = steps.some((s) => s.status === 'rejected')
    const cancelled = steps.some((s) => s.status === 'cancelled')

    return {
      currentStep: current,
      progress: pct,
      isFullyComplete: done >= total,
      isRejected: rejected,
      isCancelled: cancelled,
    }
  }, [steps])

  if (!Array.isArray(steps) || steps.length === 0) return null

  return (
    <div className="space-y-6">
      {/* Current Approver Card */}
      {currentStep ? (
        <div className="rounded-xl border border-amber-400/80 bg-amber-50/90 px-5 py-4 shadow-sm ring-2 ring-amber-500/25 dark:border-amber-600/50 dark:bg-amber-950/35 dark:ring-amber-400/20">
          <div className="flex items-start gap-4">
            <div className="flex size-12 shrink-0 items-center justify-center rounded-full border-2 border-amber-400/70 bg-amber-100 text-lg font-black text-amber-800 dark:border-amber-500/50 dark:bg-amber-900/50 dark:text-amber-200">
              !
            </div>
            <div className="min-w-0 flex-1">
              <p className="text-[11px] font-bold uppercase tracking-[0.12em] text-amber-800/80 dark:text-amber-200/80">
                Current Approval
              </p>
              <p className="mt-1 text-base font-bold text-foreground">
                Waiting for {normalizeApprovalHeadTitle(currentStep.label) || currentStep.label}
              </p>
              <p className="mt-0.5 text-sm font-medium text-muted-foreground">
                {currentStep.approver_name || currentStep.approver_role_label || 'Approver'}
              </p>
              {currentStep.received_at ? (() => {
                const elapsed = formatElapsed(currentStep.received_at)
                return elapsed ? (
                  <p className="mt-1.5 text-xs text-amber-700 dark:text-amber-300">
                    Since {formatDateTime(currentStep.received_at)} · <span className="font-semibold">{elapsed}</span>
                  </p>
                ) : null
              })() : null}
            </div>
          </div>
        </div>
      ) : null}

      {/* Progress */}
      {!isRejected && !isCancelled ? (
        <div className="space-y-1.5">
          <div className="flex items-center justify-between text-xs text-muted-foreground">
            <span className="font-semibold">Approval Progress</span>
            <span>{isFullyComplete ? 'Complete' : `${progress}%`}</span>
          </div>
          <div className="h-2 w-full overflow-hidden rounded-full bg-muted/50">
            <div
              className={cn(
                'h-full rounded-full transition-all duration-500',
                isFullyComplete
                  ? 'bg-emerald-500/70'
                  : 'bg-amber-500/60'
              )}
              style={{ width: `${Math.max(4, progress)}%` }}
            />
          </div>
          <p className="text-[11px] text-muted-foreground">
            {steps.filter((s) => s.status === 'completed').length} of {steps.length} approvals completed
          </p>
        </div>
      ) : null}

      {/* Step List */}
      <div className="space-y-3">
        {steps.map((s, idx) => {
          const name = (() => {
            if (!s || typeof s !== 'object') return '—'
            if (s.key === 'submitted') return s.submitter_name || '—'
            return s.approver_name || s.approver_role_label || '—'
          })()
          const role = (() => {
            if (!s || typeof s !== 'object') return ''
            if (s.key === 'submitted') return 'Requester'
            return s.approver_role_label || s.approver_role || ''
          })()
          const avatarSrc = stepAvatarSrc(s)
          const statusLabel = (() => {
            switch (s.status) {
              case 'completed': return 'Completed'
              case 'current': return 'Waiting for Approval'
              case 'pending': return 'Waiting'
              case 'rejected': return 'Rejected'
              case 'skipped': return 'Skipped'
              case 'cancelled': return 'Cancelled'
              default: return s.status ? String(s.status) : '—'
            }
          })()
          const statusDateLine = s.acted_at ? `${statusLabel} · ${formatDateTime(s.acted_at)}` : statusLabel
          const remarks = (() => {
            if (!s?.remarks) return null
            try {
              const txt = typeof s.remarks === 'string' ? s.remarks : String(s.remarks)
              return txt === '-' || txt === '' ? null : txt
            } catch { return null }
          })()

          return (
            <div key={s.key || `step-${idx}`} className="flex gap-3">
              {/* Connector line */}
              <div className="flex flex-col items-center">
                <div
                  className={cn(
                    'flex size-8 shrink-0 items-center justify-center rounded-full border-2 text-[11px] font-bold',
                    s.status === 'completed' ? 'border-emerald-500/60 bg-emerald-100 text-emerald-800 dark:border-emerald-400/50 dark:bg-emerald-900/40 dark:text-emerald-200' : '',
                    s.status === 'current' ? 'border-amber-500/60 bg-amber-100 text-amber-800 dark:border-amber-400/50 dark:bg-amber-900/40 dark:text-amber-200' : '',
                    s.status === 'pending' ? 'border-border/50 bg-muted/30 text-muted-foreground' : '',
                    s.status === 'rejected' ? 'border-red-500/60 bg-red-100 text-red-800 dark:border-red-400/50 dark:bg-red-900/40 dark:text-red-200' : '',
                    s.status === 'skipped' ? 'border-border/30 bg-muted/20 text-muted-foreground/50' : '',
                    s.status === 'cancelled' ? 'border-border/40 bg-muted/40 text-muted-foreground' : '',
                    !s.status || ['completed', 'current', 'pending', 'rejected', 'skipped', 'cancelled'].includes(s.status) === false
                      ? 'border-border/50 bg-muted/30 text-muted-foreground' : ''
                  )}
                >
                  {s.status === 'completed' ? '✓' : s.status === 'current' ? '●' : s.status === 'rejected' ? '✕' : idx + 1}
                </div>
                {idx < steps.length - 1 ? (
                  <div className="mt-1 w-px flex-1 bg-border/50" />
                ) : null}
              </div>

              {/* Step card */}
              <div className="min-w-0 flex-1 pb-5">
                <div
                  className={cn(
                    'rounded-xl border border-border/60 bg-card px-4 py-3.5 shadow-sm dark:border-border/50',
                    s.status === 'current' && 'border-amber-400/80 bg-amber-50/90 ring-2 ring-amber-500/25 dark:border-amber-600/50 dark:bg-amber-950/35 dark:ring-amber-400/20'
                  )}
                >
                  <div className="flex gap-3">
                    <Avatar className="size-10 shrink-0 border-2 border-border/70 bg-muted/40 shadow-sm ring-2 ring-background dark:border-border/60 dark:bg-muted/30">
                      {avatarSrc ? <AvatarImage src={avatarSrc} alt="" className="object-cover" /> : null}
                      <AvatarFallback className="text-xs font-semibold tracking-tight text-foreground">
                        {getInitials(name)}
                      </AvatarFallback>
                    </Avatar>
                    <div className="min-w-0 flex-1 space-y-1">
                      <div className="flex items-start justify-between gap-2">
                        <div className="min-w-0">
                          <p className="text-sm font-bold leading-snug text-foreground">{name}</p>
                          {role ? <p className="text-[11px] font-medium text-muted-foreground">{role}</p> : null}
                        </div>
                        <span
                          className={cn(
                            'inline-flex shrink-0 items-center rounded-full border px-2.5 py-0.5 text-[10px] font-bold uppercase tracking-[0.08em] shadow-sm',
                            STATUS_COLORS[s.status] || STATUS_COLORS.pending
                          )}
                        >
                          {statusLabel}
                        </span>
                      </div>
                      <p className="text-[11px] leading-relaxed text-muted-foreground">{statusDateLine}</p>
                      {s.status === 'current' && s.received_at ? (
                        <p className="text-[11px] font-medium text-amber-700 dark:text-amber-300">
                          Waiting since {formatElapsed(s.received_at)}
                        </p>
                      ) : null}
                      {remarks ? (
                        <p className="pt-1 text-sm leading-relaxed text-foreground/90">
                          <span className="font-semibold text-muted-foreground">Remarks: </span>
                          {remarks}
                        </p>
                      ) : null}
                    </div>
                  </div>
                </div>
              </div>
            </div>
          )
        })}
      </div>
    </div>
  )
}
