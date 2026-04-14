import { useEffect, useRef, useState } from 'react'
import { Link } from 'react-router-dom'
import { Clock, Send, CheckCircle2, XCircle, Shield, CheckCheck, CircleAlert, Loader2 } from 'lucide-react'
import { Button } from '@/components/ui/button'
import { Avatar, AvatarFallback, AvatarImage } from '@/components/ui/avatar'
import { Checkbox } from '@/components/ui/checkbox'
import { useToast } from '@/components/ui/use-toast'
import {
  Dialog,
  DialogContent,
  DialogDescription,
  DialogFooter,
  DialogHeader,
  DialogTitle,
} from '@/components/ui/dialog'
import { cn } from '@/lib/utils'
import { getInitials } from '@/components/presenceFiling/CorrectionTableCells'
import { profileImageUrl } from '@/api'
import { RegularizationStatusBadge } from '@/components/regularization/RegularizationStatusBadge'
import {
  ADMIN_FORM_DIALOG_BODY_CLASS,
  ADMIN_FORM_DIALOG_DESC_CLASS,
  ADMIN_FORM_DIALOG_FOOTER_CLASS,
  ADMIN_FORM_DIALOG_HEADER_INNER_CLASS,
  ADMIN_FORM_DIALOG_HEADER_WRAP_CLASS,
  ADMIN_FORM_DIALOG_PRIMARY_BUTTON_CLASS,
  ADMIN_FORM_DIALOG_TITLE_CLASS,
  adminFormDialogContentClass,
} from '@/lib/adminFormDialogStyles'

const RECOMMENDATION_TYPES = [
  { value: 'probation_to_regular', label: 'Probation to Regular' },
  { value: 'contract_renewal', label: 'Contract Renewal' },
  { value: 'contract_extension', label: 'Contract Extension' },
  { value: 'end_contract', label: 'End Contract' },
  { value: 'project_extension', label: 'Project Extension' },
  { value: 'project_completion', label: 'Project Completion' },
  { value: 'performance_based', label: 'Performance-Based' },
]

function recommendationTypeLabel(value) {
  return RECOMMENDATION_TYPES.find((t) => t.value === value)?.label || value || '—'
}

function formatShortDate(iso) {
  if (!iso) return '—'
  try {
    return new Date(`${iso}T12:00:00`).toLocaleDateString('en-PH', {
      month: 'short',
      day: 'numeric',
      year: 'numeric',
    })
  } catch {
    return iso
  }
}

function formatDateTimeSubmitted(iso) {
  if (!iso) return '—'
  try {
    const d = new Date(iso)
    if (Number.isNaN(d.getTime())) return '—' 
    return d.toLocaleString('en-PH', { dateStyle: 'medium', timeStyle: 'short' })
  } catch {
    return '—'
  }
}

function employmentTypeLabel(v) {
  if (!v) return '—'
  return String(v).replace(/_/g, ' ')
}

/**
 * @typedef {'performance_review_completed' | 'training_completed' | 'documents_submitted' | 'manager_recommendation_received' | 'checklist_completed'} ChecklistItemKey
 * @typedef {{ completed: boolean, completedAt: string | null }} ChecklistItemState
 * @typedef {Record<ChecklistItemKey, ChecklistItemState>} ChecklistState
 */

const CHECKLIST_ITEM_DEFS = [
  /** @type {{ key: ChecklistItemKey, label: string }} */ ({
    key: 'performance_review_completed',
    label: 'Performance Review Completed',
  }),
  /** @type {{ key: ChecklistItemKey, label: string }} */ ({
    key: 'training_completed',
    label: 'Training / Orientation Checklist Completed',
  }),
  /** @type {{ key: ChecklistItemKey, label: string }} */ ({
    key: 'documents_submitted',
    label: 'Documents Submitted (ID, clearances, etc.)',
  }),
  /** @type {{ key: ChecklistItemKey, label: string }} */ ({
    key: 'manager_recommendation_received',
    label: 'Manager Recommendation Received',
  }),
  /** @type {{ key: ChecklistItemKey, label: string }} */ ({
    key: 'checklist_completed',
    label: 'Checklist Completion',
  }),
]

/** @returns {ChecklistState} */
function buildChecklistSeed() {
  return {
    // Pre-populated demo state requested by product:
    // 2 done, 3 pending (the last 3 must remain independently checkable).
    performance_review_completed: { completed: true, completedAt: '2026-03-25T10:00:00+08:00' },
    training_completed: { completed: true, completedAt: '2026-03-26T10:00:00+08:00' },
    documents_submitted: { completed: false, completedAt: null },
    manager_recommendation_received: { completed: false, completedAt: null },
    checklist_completed: { completed: false, completedAt: null },
  }
}

function formatServiceDurationDetailed(hireDateIso) {
  if (!hireDateIso) return '—'
  const hire = new Date(`${hireDateIso}T12:00:00`)
  const now = new Date()
  if (Number.isNaN(hire.getTime()) || hire > now) return '—'
  let anchor = new Date(hire)
  let wholeMonths = 0
  for (let i = 0; i < 600; i += 1) {
    const next = new Date(anchor)
    next.setMonth(next.getMonth() + 1)
    if (next > now) break
    anchor = next
    wholeMonths += 1
  }
  const days = Math.max(0, Math.floor((now.getTime() - anchor.getTime()) / 86400000))
  if (wholeMonths === 0) return `${days} day${days !== 1 ? 's' : ''}`
  const mPart = `${wholeMonths} month${wholeMonths !== 1 ? 's' : ''}`
  return days === 0 ? mPart : `${mPart} ${days} day${days !== 1 ? 's' : ''}`
}

/** Colored action chip (Submitted / Approved / Rejected / Awaiting review). */
function TrailActionChip({ variant, children }) {
  const styles = {
    submitted:
      'border-violet-200/90 bg-gradient-to-br from-violet-100 to-indigo-50 text-violet-950 shadow-violet-500/15 ring-1 ring-violet-200/70 dark:from-violet-950/45 dark:to-violet-950/25 dark:text-violet-100 dark:ring-violet-500/30',
    approved:
      'border-emerald-200/90 bg-gradient-to-br from-emerald-100 to-teal-50 text-emerald-950 shadow-emerald-500/20 ring-1 ring-emerald-200/80 dark:from-emerald-950/50 dark:to-emerald-950/30 dark:text-emerald-50 dark:ring-emerald-500/15',
    rejected:
      'border-rose-200/90 bg-gradient-to-br from-rose-100 to-red-50 text-rose-950 shadow-rose-500/15 ring-1 ring-rose-200/80 dark:from-rose-950/45 dark:to-rose-950/25 dark:text-rose-100 dark:ring-rose-500/30',
    pending:
      'border-amber-200/90 bg-gradient-to-br from-amber-100 to-amber-50 text-amber-950 shadow-amber-500/15 ring-1 ring-amber-200/80 dark:from-amber-950/45 dark:to-amber-950/25 dark:text-amber-100',
  }
  const Icon =
    variant === 'approved' ? CheckCircle2 : variant === 'rejected' ? XCircle : variant === 'pending' ? Clock : Send
  return (
    <span
      className={cn(
        'inline-flex items-center gap-1.5 rounded-full border px-2.5 py-0.5 text-[11px] font-bold uppercase tracking-wide shadow-sm',
        styles[variant] || styles.pending,
      )}
    >
      <Icon className="size-3.5 shrink-0 opacity-90" aria-hidden />
      {children}
    </span>
  )
}

function TrailActorAvatar({ imageUrl, name, fallbackIcon: FallbackIcon, fallbackClassName }) {
  const src = imageUrl ? profileImageUrl(imageUrl) : null
  const display = name || '—'
  if (FallbackIcon && !src) {
    return (
      <div
        className={cn(
          'flex size-11 shrink-0 items-center justify-center rounded-full border-2 border-border/70 bg-muted/50 shadow-sm ring-2 ring-background dark:border-border/60',
          fallbackClassName,
        )}
        aria-hidden
      >
        <FallbackIcon className="size-5 text-muted-foreground" />
      </div>
    )
  }
  return (
    <Avatar
      className={cn(
        'size-11 shrink-0 border-2 border-border/70 bg-muted/40 shadow-sm ring-2 ring-background',
        'dark:border-border/60 dark:bg-muted/30',
      )}
    >
      {src ? <AvatarImage src={src} alt="" className="object-cover" /> : null}
      <AvatarFallback className="text-xs font-semibold tracking-tight text-foreground">
        {getInitials(display)}
      </AvatarFallback>
    </Avatar>
  )
}

/**
 * Read-only recommendation details (HR panel + employee self-service).
 * @param {{ open: boolean, onOpenChange: (o: boolean) => void, rec: object | null, employeeProfileHref?: string | null }} props
 */
export function RegularizationRecommendationViewDialog({ open, onOpenChange, rec, employeeProfileHref = null }) {
  const { toast } = useToast()
  const viewImg = rec ? profileImageUrl(rec.employee_profile_image) : null
  const status = (rec?.status || '').toLowerCase()
  const workflowStatus = String(rec?.workflow_status || '').toLowerCase()
  const isFinalized = status === 'approved' || status === 'rejected' || workflowStatus === 'completed'
  const hrDecisionLabel =
    status === 'rejected' ? 'Rejection reason / HR notes' : status === 'approved' ? 'HR approval notes' : 'HR decision'

  const submitterName = rec?.recommended_by_name || '—'
  const submitterRole = rec?.recommended_by_role_label || '—'
  const hrName = rec?.hr_reviewed_by_name || 'HR'
  const hrRoleLabel = rec?.hr_reviewed_by_role_label || 'Admin (HR)'

  /** @type {React.MutableRefObject<Record<string, ChecklistState>>} */
  const checklistStoreRef = useRef({})
  /** @type {[ChecklistState, React.Dispatch<React.SetStateAction<ChecklistState>>]} */
  const [checklistState, setChecklistState] = useState(() => buildChecklistSeed())
  const [lastSavedAt, setLastSavedAt] = useState(null)
  const [confirmBusy, setConfirmBusy] = useState(false)
  /** @type {[Record<string, string>, React.Dispatch<React.SetStateAction<Record<string, string>>>]} */
  const [confirmedAtByRecId, setConfirmedAtByRecId] = useState({})

  useEffect(() => {
    if (!open || !rec?.id) return
    const key = String(rec.id)
    const fromStore = checklistStoreRef.current[key]
    if (fromStore) {
      setChecklistState(fromStore)
      return
    }
    const seed = buildChecklistSeed()
    checklistStoreRef.current[key] = seed
    setChecklistState(seed)
  }, [open, rec?.id])

  const totalChecklistItems = CHECKLIST_ITEM_DEFS.length
  /**
   * Single source of truth fix:
   * Count strictly from checklistState for all 5 keys so every row updates totals immediately.
   */
  const completedChecklistCount = CHECKLIST_ITEM_DEFS.reduce(
    (acc, item) => acc + (checklistState[item.key]?.completed ? 1 : 0),
    0,
  )
  const pendingChecklistCount = Math.max(0, totalChecklistItems - completedChecklistCount)
  const progressPct = Math.round((completedChecklistCount / totalChecklistItems) * 100)
  const canConfirmRegularization = pendingChecklistCount === 0 && !isFinalized
  const recIdKey = rec?.id != null ? String(rec.id) : null
  const confirmedAt = recIdKey ? confirmedAtByRecId[recIdKey] || null : null
  const isConfirmed = Boolean(confirmedAt)

  /** @param {ChecklistItemKey} itemKey @param {boolean} nextChecked */
  function toggleChecklistItem(itemKey, nextChecked) {
    setChecklistState((prev) => {
      const next = {
        ...prev,
        [itemKey]: {
          completed: !!nextChecked,
          completedAt: nextChecked ? new Date().toISOString() : null,
        },
      }
      if (rec?.id) {
        checklistStoreRef.current[String(rec.id)] = next
      }
      return next
    })
    // "Instant save" UX indicator (replace with API mutation when backend endpoint is wired).
    setLastSavedAt(new Date().toISOString())
  }

  async function handleConfirmRegularization() {
    if (!rec || !canConfirmRegularization || confirmBusy || isConfirmed) return
    setConfirmBusy(true)
    await new Promise((resolve) => setTimeout(resolve, 550))
    const nowIso = new Date().toISOString()
    setConfirmedAtByRecId((prev) => ({
      ...prev,
      [String(rec.id)]: nowIso,
    }))
    setLastSavedAt(nowIso)
    // Simulated audit trail write hook for this modal-level demo.
    console.info('[AuditTrail] regularization_confirmed', {
      recommendationId: rec.id,
      employeeId: rec.employee_id,
      employeeName: rec.employee_name,
      confirmedAt: nowIso,
    })
    toast({
      title: 'Regularization confirmed',
      description: `Regularization confirmed successfully for ${rec.employee_name || 'employee'}`,
      variant: 'success',
    })
    setConfirmBusy(false)
  }

  return (
    <Dialog open={open} onOpenChange={onOpenChange}>
      <DialogContent showCloseButton className={adminFormDialogContentClass('max-w-2xl')}>
        <DialogHeader className={ADMIN_FORM_DIALOG_HEADER_WRAP_CLASS}>
          <div className={ADMIN_FORM_DIALOG_HEADER_INNER_CLASS}>
            <DialogTitle className={ADMIN_FORM_DIALOG_TITLE_CLASS}>Regularization request details</DialogTitle>
            <DialogDescription className={ADMIN_FORM_DIALOG_DESC_CLASS}>
              Full request record, submission trail, and HR decision for audit and transparency.
            </DialogDescription>
          </div>
        </DialogHeader>
        {rec ? (
          <div className={ADMIN_FORM_DIALOG_BODY_CLASS}>
            <div className="flex items-start gap-3 border-b border-border/60 pb-4">
              <Avatar className="size-12 border border-border">
                {viewImg ? <AvatarImage src={viewImg} alt="" className="object-cover" /> : null}
                <AvatarFallback className="text-sm font-semibold">{getInitials(rec.employee_name)}</AvatarFallback>
              </Avatar>
              <div className="min-w-0 flex-1">
                <p className="font-semibold text-foreground">{rec.employee_name || '—'}</p>
                <p className="text-sm text-muted-foreground">{rec.employee_position || '—'}</p>
                <p className="font-mono text-xs text-muted-foreground">{rec.employee_code || '—'}</p>
              </div>
            </div>

            <dl className="mt-4 grid gap-3 text-sm">
              <div className="flex justify-between gap-4">
                <dt className="text-muted-foreground">Request ID</dt>
                <dd className="text-right font-mono text-xs font-medium tabular-nums text-foreground">#{rec.id}</dd>
              </div>
              <div className="flex justify-between gap-4">
                <dt className="text-muted-foreground">Employment type</dt>
                <dd className="text-right font-medium capitalize">{employmentTypeLabel(rec.employee_employment_type)}</dd>
              </div>
              <div className="flex justify-between gap-4">
                <dt className="text-muted-foreground">Hire date</dt>
                <dd className="text-right tabular-nums">
                  {rec.employee_hire_date ? formatShortDate(rec.employee_hire_date) : '—'}
                </dd>
              </div>
              <div className="flex justify-between gap-4">
                <dt className="text-muted-foreground">Service duration</dt>
                <dd className="text-right tabular-nums">{formatServiceDurationDetailed(rec.employee_hire_date)}</dd>
              </div>
              <div className="flex justify-between gap-4">
                <dt className="text-muted-foreground">Recommended effective date</dt>
                <dd className="text-right tabular-nums font-medium">
                  {rec.effective_date ? formatShortDate(rec.effective_date) : '—'}
                </dd>
              </div>
              {'expiration_date' in rec ? (
                <div className="flex justify-between gap-4">
                  <dt className="text-muted-foreground">Expiration date</dt>
                  <dd className="text-right tabular-nums font-medium">
                    {rec.expiration_date ? formatShortDate(rec.expiration_date) : '—'}
                  </dd>
                </div>
              ) : null}
              <div className="flex justify-between gap-4">
                <dt className="text-muted-foreground">Recommendation type</dt>
                <dd className="text-right">{recommendationTypeLabel(rec.recommendation_type)}</dd>
              </div>
              <div className="flex justify-between gap-4">
                <dt className="shrink-0 text-muted-foreground">Status</dt>
                <dd className="flex min-w-0 justify-end text-right">
                  <RegularizationStatusBadge status={rec.status} processed={rec.processed} />
                </dd>
              </div>
            </dl>

            {status === 'pending' ? (
            <section className="mt-6 rounded-2xl border border-emerald-500/20 bg-linear-to-br from-emerald-500/10 via-card to-card p-4 shadow-[0_1px_0_rgba(0,0,0,0.03),0_10px_24px_rgba(16,185,129,0.08)] dark:from-emerald-500/8">
              <div className="flex flex-wrap items-start justify-between gap-3">
                <div>
                  <p className="text-base font-semibold tracking-tight text-foreground">
                    Required Actions Before Confirmation
                  </p>
                  <p className="mt-1 text-sm text-muted-foreground">
                    {pendingChecklistCount > 0 ? `${pendingChecklistCount} items still pending.` : 'All actions completed.'}
                  </p>
                </div>
                <div className="rounded-full border border-border/60 bg-background/70 px-2.5 py-1 text-xs font-medium text-muted-foreground">
                  {completedChecklistCount} of {totalChecklistItems} completed
                </div>
              </div>

              <div className="mt-3">
                <div className="h-2 overflow-hidden rounded-full bg-muted/40">
                  <div
                    className="h-full rounded-full bg-emerald-500 transition-all duration-300"
                    style={{ width: `${progressPct}%` }}
                    role="progressbar"
                    aria-valuemin={0}
                    aria-valuemax={100}
                    aria-valuenow={progressPct}
                    aria-label="Checklist completion progress"
                  />
                </div>
              </div>

              <div className="mt-4 space-y-2.5">
                {CHECKLIST_ITEM_DEFS.map((item) => {
                  const done = !!checklistState[item.key]?.completed
                  const doneAt = checklistState[item.key]?.completedAt
                  return (
                    <div
                      key={item.key}
                      className={cn(
                        'flex items-center justify-between gap-3 rounded-xl border px-3 py-2.5 transition-colors',
                        done
                          ? 'border-emerald-500/25 bg-emerald-500/8'
                          : 'border-amber-500/25 bg-amber-500/8',
                      )}
                    >
                      <label className="flex min-w-0 cursor-pointer items-start gap-3" htmlFor={`req-${item.key}`}>
                        <Checkbox
                          id={`req-${item.key}`}
                          checked={done}
                          onCheckedChange={(val) => toggleChecklistItem(item.key, !!val)}
                          className="mt-0.5 size-5 rounded-md border-border/70 data-[state=checked]:border-emerald-500 data-[state=checked]:bg-emerald-500"
                          aria-label={item.label}
                        />
                        <div className="min-w-0">
                          <p className={cn('text-sm', done ? 'text-muted-foreground line-through' : 'text-foreground')}>
                            {item.label}
                          </p>
                          {doneAt ? (
                            <p className="mt-0.5 text-xs text-emerald-700 dark:text-emerald-300">
                              Completed on {formatDateTimeSubmitted(doneAt)}
                            </p>
                          ) : null}
                        </div>
                      </label>
                      <span
                        className={cn(
                          'inline-flex shrink-0 items-center gap-1 rounded-full px-2.5 py-1 text-[11px] font-semibold',
                          done
                            ? 'bg-emerald-500/15 text-emerald-700 dark:text-emerald-300'
                            : 'bg-amber-500/15 text-amber-700 dark:text-amber-300',
                        )}
                      >
                        {done ? <CheckCircle2 className="size-3.5" aria-hidden /> : null}
                        {done ? 'Completed' : 'Pending'}
                      </span>
                    </div>
                  )
                })}
              </div>

              <div className="mt-3 flex flex-wrap items-center justify-between gap-2 rounded-lg border border-border/60 bg-muted/20 px-3 py-2 text-xs text-muted-foreground">
                <span className="inline-flex items-center gap-1.5">
                  <CircleAlert className="size-3.5" />
                  All items must be completed before final confirmation can be submitted.
                </span>
                {lastSavedAt ? <span>Saved {formatDateTimeSubmitted(lastSavedAt)}</span> : null}
              </div>
            </section>
            ) : (
            <section className="mt-6 rounded-2xl border border-border/60 bg-muted/20 p-4">
              <div className="flex flex-wrap items-start justify-between gap-2">
                <div>
                  <p className="text-base font-semibold tracking-tight text-foreground">
                    Required Actions Before Confirmation
                  </p>
                  <p className="mt-1 text-sm text-muted-foreground">
                    This request is already finalized ({workflowStatus || status}). Checklist items are no longer blocking for this record.
                  </p>
                </div>
                <RegularizationStatusBadge status={status} processed={rec?.processed} />
              </div>
            </section>
            )}

            <div className="mt-6 border-t border-border/60 pt-5">
              <p className="text-[11px] font-bold uppercase tracking-[0.12em] text-muted-foreground">Approval trail</p>
              <p className="mt-1 text-xs text-muted-foreground">
                Submitter and HR decision with profile, role, timestamps, and remarks — aligned with correction request
                details.
              </p>

              <ol className="mt-4 list-none space-y-3">
                <li>
                  <div className="rounded-xl border border-border/60 bg-card px-4 py-4 shadow-sm dark:border-border/50">
                    <div className="flex flex-col gap-3 border-b border-border/40 pb-3 @sm:flex-row @sm:items-center @sm:justify-between">
                      <p className="text-[11px] font-bold uppercase tracking-[0.12em] text-muted-foreground">
                        <span className="tabular-nums text-muted-foreground/90">1. </span>
                        Submission
                      </p>
                      <TrailActionChip variant="submitted">Submitted</TrailActionChip>
                    </div>
                    <div className="mt-3 flex gap-3">
                      <TrailActorAvatar imageUrl={rec.recommended_by_profile_image} name={submitterName} />
                      <div className="min-w-0 flex-1 space-y-1">
                        <p className="text-[15px] font-semibold leading-snug tracking-tight text-foreground">
                          {submitterName}
                        </p>
                        <p className="text-xs font-medium text-muted-foreground">{submitterRole}</p>
                        <p className="text-xs tabular-nums leading-relaxed text-muted-foreground">
                          {formatDateTimeSubmitted(rec.recommended_at)}
                        </p>
                        <div className="mt-3 rounded-lg border border-border/50 bg-muted/25 px-3 py-2.5 dark:bg-muted/15">
                          <p className="text-[11px] font-bold uppercase tracking-[0.08em] text-muted-foreground">
                            Basis & remarks (submitter)
                          </p>
                          <p className="mt-1.5 whitespace-pre-wrap text-sm leading-relaxed text-foreground/95">
                            {rec.recommendation_notes?.trim() ? rec.recommendation_notes : '—'}
                          </p>
                        </div>
                      </div>
                    </div>
                  </div>
                </li>

                {status === 'pending' ? (
                  <li>
                    <div
                      className={cn(
                        'rounded-xl border px-4 py-4 shadow-sm',
                        'border-amber-400/80 bg-amber-50/90 ring-2 ring-amber-500/20',
                        'dark:border-amber-600/50 dark:bg-amber-950/35 dark:ring-amber-400/15',
                      )}
                    >
                      <div className="flex flex-col gap-3 border-b border-amber-200/60 pb-3 @sm:flex-row @sm:items-center @sm:justify-between dark:border-amber-800/40">
                        <p className="text-[11px] font-bold uppercase tracking-[0.12em] text-amber-900/80 dark:text-amber-200/90">
                          <span className="tabular-nums">2. </span>
                          HR review
                        </p>
                        <TrailActionChip variant="pending">Awaiting decision</TrailActionChip>
                      </div>
                      <div className="mt-3 flex gap-3">
                        <TrailActorAvatar
                          name={null}
                          fallbackIcon={Shield}
                          fallbackClassName="border-amber-200/80 bg-amber-100/80 dark:border-amber-800/50 dark:bg-amber-950/50"
                        />
                        <div className="min-w-0 flex-1 space-y-1">
                          <p className="text-sm font-medium text-amber-950 dark:text-amber-100">
                            Pending HR administrator
                          </p>
                          <p className="text-xs leading-relaxed text-amber-900/85 dark:text-amber-200/85">
                            An HR admin with approval rights will approve or reject this recommendation. You will see their
                            name, role, and notes here once decided.
                          </p>
                        </div>
                      </div>
                    </div>
                  </li>
                ) : (
                  <li>
                    <div
                      className={cn(
                        'rounded-xl border border-border/60 bg-card px-4 py-4 shadow-sm dark:border-border/50',
                        status === 'approved' &&
                          'border-emerald-200/90 ring-1 ring-emerald-500/15 dark:border-emerald-800/40',
                        status === 'rejected' && 'border-rose-200/90 ring-1 ring-rose-500/15 dark:border-rose-900/40',
                      )}
                    >
                      <div className="flex flex-col gap-3 border-b border-border/40 pb-3 @sm:flex-row @sm:items-center @sm:justify-between">
                        <p className="text-[11px] font-bold uppercase tracking-[0.12em] text-muted-foreground">
                          <span className="tabular-nums text-muted-foreground/90">2. </span>
                          HR decision
                        </p>
                        <TrailActionChip variant={status === 'rejected' ? 'rejected' : 'approved'}>
                          {status === 'rejected' ? 'Rejected' : 'Approved'}
                        </TrailActionChip>
                      </div>
                      <div className="mt-3 flex gap-3">
                        <TrailActorAvatar imageUrl={rec.hr_reviewed_by_profile_image} name={hrName} />
                        <div className="min-w-0 flex-1 space-y-1">
                          <p className="text-[15px] font-semibold leading-snug tracking-tight text-foreground">{hrName}</p>
                          <p className="text-xs font-medium text-muted-foreground">{hrRoleLabel}</p>
                          <p className="text-xs tabular-nums leading-relaxed text-muted-foreground">
                            {formatDateTimeSubmitted(rec.hr_reviewed_at)}
                          </p>
                          <div className="mt-3 rounded-lg border border-border/50 bg-muted/25 px-3 py-2.5 dark:bg-muted/15">
                            <p className="text-[11px] font-bold uppercase tracking-[0.08em] text-muted-foreground">
                              {hrDecisionLabel}
                            </p>
                            <p className="mt-1.5 whitespace-pre-wrap text-sm leading-relaxed text-foreground/95">
                              {rec.hr_notes?.trim() ? rec.hr_notes : '—'}
                            </p>
                          </div>
                        </div>
                      </div>
                    </div>
                  </li>
                )}
              </ol>
            </div>
          </div>
        ) : null}
        <DialogFooter className={cn(ADMIN_FORM_DIALOG_FOOTER_CLASS, 'border-t border-border/60 pt-4')}>
          <div className="flex w-full flex-col gap-3 @sm:flex-row @sm:items-center @sm:justify-between">
            <Button
              type="button"
              variant="ghost"
              className="h-10 rounded-xl px-4 font-medium"
              onClick={() => onOpenChange(false)}
            >
              Close
            </Button>

            <div className="flex w-full flex-col gap-3 @sm:w-auto @sm:flex-row @sm:items-center">
              {status === 'pending' ? (
              <Button
                type="button"
                className={cn(
                  'h-10 rounded-xl px-5 font-semibold',
                  'shadow-[0_10px_24px_rgba(0,0,0,0.15)] transition-all hover:shadow-[0_14px_30px_rgba(0,0,0,0.2)]',
                  'disabled:cursor-not-allowed disabled:opacity-55 disabled:shadow-none',
                )}
                disabled={!canConfirmRegularization || confirmBusy || isConfirmed}
                onClick={handleConfirmRegularization}
              >
                {confirmBusy ? (
                  <>
                    <Loader2 className="mr-2 size-4 animate-spin" />
                    Confirming...
                  </>
                ) : isConfirmed ? (
                  <>
                    <CheckCircle2 className="mr-2 size-4" />
                    Confirmed
                  </>
                ) : (
                  <>
                    <CheckCheck className="mr-2 size-4" />
                    Confirm Regularization
                  </>
                )}
              </Button>
              ) : null}

              {employeeProfileHref ? (
                <Button
                  type="button"
                  variant="outline"
                  className="h-10 rounded-xl px-4 font-medium"
                  asChild
                >
                  <Link to={employeeProfileHref}>Open employee profile</Link>
                </Button>
              ) : null}
            </div>
          </div>
        </DialogFooter>
      </DialogContent>
    </Dialog>
  )
}
