import { useEffect, useMemo, useState } from 'react'
import { Link } from 'react-router-dom'
import { CheckCircle2, ChevronLeft, ChevronRight, Clock, Eye, FileText, XCircle } from 'lucide-react'
import { Popover, PopoverContent, PopoverTrigger } from '@/components/ui/popover'
import {
  Dialog,
  DialogContent,
  DialogDescription,
  DialogHeader,
  DialogTitle,
} from '@/components/ui/dialog'
import { Button } from '@/components/ui/button'
import SupportingDocumentFilePreview from '@/components/SupportingDocumentFilePreview'
import { Avatar, AvatarFallback, AvatarImage } from '@/components/ui/avatar'
import { Badge } from '@/components/ui/badge'
import { cn } from '@/lib/utils'
import { profileImageUrl } from '@/api'
import { normalizeApprovalHeadTitle, normalizeApprovalStatusLabel } from '@/lib/approvalText'
import {
  issueLabel,
  reasonLabel,
  remarksUserText,
  reviewStatusBadgeClass,
  reviewStatusKey,
  reviewStatusLabel,
  attachmentCount,
  formatTimeOnly,
} from '@/lib/presenceFilingTable'
import { EMPTY_PLACEHOLDER } from '@/lib/formatEmpty'

// eslint-disable-next-line react-refresh/only-export-components
export function getInitials(name) {
  if (!name || typeof name !== 'string') return '?'
  const parts = name.trim().split(/\s+/).filter(Boolean)
  if (parts.length === 0) return '?'
  if (parts.length === 1) return parts[0].slice(0, 2).toUpperCase()
  return `${parts[0][0] ?? ''}${parts[parts.length - 1][0] ?? ''}`.toUpperCase() || '?'
}

function requesterRoleBadgeClass(hrRole) {
  switch (hrRole) {
    case 'admin_hr':
      return 'border-blue-200/80 bg-blue-100 text-blue-950 shadow-sm dark:border-blue-500/35 dark:bg-blue-950/50 dark:text-blue-50'
    case 'department_head':
      return 'border-teal-200/80 bg-teal-100 text-teal-950 shadow-sm dark:border-teal-500/35 dark:bg-teal-950/45 dark:text-teal-50'
    case 'branch_head':
      return 'border-indigo-200/80 bg-indigo-100 text-indigo-950 shadow-sm dark:border-indigo-500/35 dark:bg-indigo-950/45 dark:text-indigo-50'
    case 'company_head':
      return 'border-violet-200/80 bg-violet-100 text-violet-950 shadow-sm dark:border-violet-500/35 dark:bg-violet-950/45 dark:text-violet-50'
    case 'employee':
    default:
      return 'border-border/70 bg-muted text-muted-foreground dark:border-border/60 dark:bg-muted/80'
  }
}

/** Subject employee: avatar + name (attendance being corrected). */
/** Avatar + name + org role badge in a single column (Correction Requests tables). */
export function EmployeeAvatarNameRoleCell({
  name,
  imageUrl,
  profileTo,
  compact,
  roleLabel,
  hrRole,
}) {
  const display = name || '—'
  const role = roleLabel || 'Employee'
  const hr = hrRole || 'employee'
  const avatarInner = (
    <Avatar
      className={cn(
        'shrink-0 border-2 border-white shadow-md ring-1 ring-slate-200/80 ring-offset-2 ring-offset-background dark:border-slate-800 dark:ring-slate-700/60',
        compact ? 'size-9' : 'size-11'
      )}
    >
      {imageUrl ? <AvatarImage src={profileImageUrl(imageUrl)} alt="" className="object-cover" /> : null}
      <AvatarFallback className="bg-gradient-to-br from-slate-200 to-slate-300 text-xs font-bold text-slate-800 dark:from-slate-700 dark:to-slate-600 dark:text-slate-100">
        {getInitials(display)}
      </AvatarFallback>
    </Avatar>
  )

  return (
    <div className={cn('flex min-w-0 w-full max-w-full items-center gap-3', compact && 'gap-2.5')}>
      {profileTo ? (
        <Link
          to={profileTo}
          className="shrink-0 rounded-full outline-none transition hover:opacity-90 focus-visible:ring-2 focus-visible:ring-ring"
          aria-label={`View profile: ${display}`}
        >
          {avatarInner}
        </Link>
      ) : (
        <div className="shrink-0">{avatarInner}</div>
      )}
      <div className="min-w-0 flex-1">
        <p
          className={cn(
            'break-words font-semibold leading-snug tracking-tight text-foreground',
            compact ? 'truncate text-sm' : 'line-clamp-2 text-[15px]'
          )}
          title={display}
        >
          {display}
        </p>
        <Badge
          variant="secondary"
          className={cn(
            'mt-1.5 h-5 w-fit max-w-full truncate rounded-md border px-2 py-0 text-[10px] font-semibold tracking-wide',
            requesterRoleBadgeClass(hr)
          )}
          title={role}
        >
          {role}
        </Badge>
      </div>
    </div>
  )
}

export function EmployeeAvatarNameCell({
  name,
  imageUrl,
  profileTo,
  compact,
  idHint,
}) {
  const display = name || '—'
  const avatarInner = (
    <Avatar
      className={cn(
        'shrink-0 border-2 border-white shadow-md ring-1 ring-slate-200/80 ring-offset-2 ring-offset-background dark:border-slate-800 dark:ring-slate-700/60',
        compact ? 'size-9' : 'size-11'
      )}
    >
      {imageUrl ? <AvatarImage src={profileImageUrl(imageUrl)} alt="" className="object-cover" /> : null}
      <AvatarFallback className="bg-gradient-to-br from-slate-200 to-slate-300 text-xs font-bold text-slate-800 dark:from-slate-700 dark:to-slate-600 dark:text-slate-100">
        {getInitials(display)}
      </AvatarFallback>
    </Avatar>
  )

  return (
    <div className={cn('flex min-w-0 max-w-[min(100%,18rem)] items-center gap-3', compact && 'gap-2.5')}>
      {profileTo ? (
        <Link
          to={profileTo}
          className="shrink-0 rounded-full outline-none transition hover:opacity-90 focus-visible:ring-2 focus-visible:ring-emerald-500/50"
          aria-label={`View profile: ${display}`}
        >
          {avatarInner}
        </Link>
      ) : (
        <div className="shrink-0">{avatarInner}</div>
      )}
      <div className="min-w-0 flex-1">
        <div className="flex min-w-0 flex-wrap items-baseline gap-x-2 gap-y-0">
          <span
            className={cn(
              'truncate font-bold leading-tight tracking-tight text-foreground',
              compact ? 'text-sm' : 'text-[15px]'
            )}
            title={display}
          >
            {display}
          </span>
          {idHint != null ? (
            <span className="shrink-0 font-mono text-[11px] font-medium tabular-nums text-muted-foreground">
              #{idHint}
            </span>
          ) : null}
        </div>
      </div>
    </div>
  )
}

/** Job title + org role badge for the subject employee. */
export function RoleJobTitleCell({ position, roleLabel, hrRole }) {
  const pos = (position && String(position).trim()) || ''
  const role = roleLabel || 'Employee'
  const hr = hrRole || 'employee'

  return (
    <div className="flex min-w-0 max-w-[14rem] flex-col gap-1.5">
      {pos ? (
        <p className="line-clamp-2 text-sm font-medium leading-snug text-foreground" title={pos}>
          {pos}
        </p>
      ) : (
        <p className="text-sm text-muted-foreground">—</p>
      )}
      <Badge
        variant="secondary"
        className={cn(
          'h-5 w-fit max-w-full truncate rounded-md border px-2 py-0 text-[10px] font-semibold tracking-wide',
          requesterRoleBadgeClass(hr)
        )}
        title={role}
      >
        {role}
      </Badge>
    </div>
  )
}

export function ReviewStatusTableBadge({ item, showApprover = true, showApproverLabel = false }) {
  const key = reviewStatusKey(item)
  const ds = item?.display_status && String(item.display_status).trim()
  const currentStage = normalizeApprovalHeadTitle(
    item?.current_stage ||
      String(ds || '')
        .replace(/^Pending\s+/i, '')
        .replace(/\s+Approval$/i, ' approval')
  )
  // Bare "Pending" is a status fallback, not an approval stage title.
  const stageForWaiting =
    currentStage && !/^pending$/i.test(currentStage) ? currentStage : ''
  const label =
    item?.status === 'pending' && stageForWaiting
      ? `Waiting for ${stageForWaiting}`
      : normalizeApprovalStatusLabel(ds || reviewStatusLabel(item))
  const currentApprover = showApprover
    ? String(item?.current_approver_name || item?.current_approver || '').trim()
    : ''
  const approverText = currentApprover
    ? showApproverLabel
      ? `Current approver: ${currentApprover}`
      : currentApprover
    : ''
  const Icon =
    key === 'rejected' ? XCircle : key === 'hr_approved' ? CheckCircle2 : Clock
  return (
    <div className="flex min-w-0 flex-col justify-center gap-1">
      <span
        className={cn(
          'inline-flex w-fit max-w-full items-center gap-1.5 rounded-full border px-2.5 py-1 text-xs font-semibold leading-tight shadow-sm',
          reviewStatusBadgeClass(key)
        )}
      >
        <Icon className="size-3.5 shrink-0 opacity-90" aria-hidden />
        <span className="line-clamp-2">{label}</span>
      </span>
      {approverText ? (
        <p className="line-clamp-2 text-[11px] leading-snug text-muted-foreground" title={approverText}>
          {approverText}
        </p>
      ) : null}
    </div>
  )
}

export function RemarksPreviewCell({ text }) {
  const clean = remarksUserText(text || '')
  if (!clean) {
    return <span className="text-sm text-muted-foreground">—</span>
  }
  return (
    <Popover>
      <PopoverTrigger asChild>
        <button
          type="button"
          className="group flex min-h-9 w-full max-w-full items-center gap-2 text-left text-sm text-foreground outline-none transition hover:text-primary focus-visible:ring-2 focus-visible:ring-ring focus-visible:ring-offset-2"
        >
          <span className="line-clamp-1 min-w-0 flex-1 break-words font-normal leading-snug text-foreground/90">
            {clean}
          </span>
          <span className="shrink-0 text-xs font-semibold text-primary underline-offset-2 group-hover:underline">
            View full
          </span>
        </button>
      </PopoverTrigger>
      <PopoverContent className="max-h-[min(70vh,24rem)] w-[min(100vw-2rem,28rem)] overflow-y-auto text-sm" align="start">
        <p className="break-words whitespace-pre-wrap leading-relaxed text-foreground">{clean}</p>
      </PopoverContent>
    </Popover>
  )
}

/** Time in / time out in tables: monospace time or muted em dash. */
export function TimeCell({ iso }) {
  if (!iso) {
    return <span className="whitespace-nowrap font-mono text-[12px] text-muted-foreground">{EMPTY_PLACEHOLDER}</span>
  }
  return (
    <span className="whitespace-nowrap font-mono text-[12px] tabular-nums text-foreground">{formatTimeOnly(iso)}</span>
  )
}

export function IssueTypeCell({ issueType, reasonCode }) {
  const sub = reasonLabel(reasonCode)
  const label = issueLabel(issueType)
  return (
    <div className="flex min-w-0 max-w-full flex-col justify-center gap-1">
      <Badge
        variant="outline"
        className="w-fit max-w-full justify-start rounded-lg border-slate-200/90 bg-white px-2 py-0.5 text-[11px] font-medium shadow-sm dark:border-slate-700 dark:bg-slate-900/40"
        title={label}
      >
        <span className="min-w-0 truncate">{label}</span>
      </Badge>
      {sub ? (
        <span className="block max-w-full truncate text-[11px] leading-snug text-muted-foreground" title={sub}>
          {sub}
        </span>
      ) : null}
    </div>
  )
}

function correctionDocumentLinks(documents) {
  const docs = Array.isArray(documents) ? documents : []
  return docs
    .map((doc, i) => {
      if (typeof doc === 'string' && doc.trim()) {
        const path = doc.replace(/^\/+/, '')
        const url = profileImageUrl(
          path.startsWith('api/') || path.startsWith('storage/')
            ? `/${path.replace(/^storage\//, 'api/media/public/')}`
            : `/api/media/public/${path}`,
        )
        return { url, filename: doc.split(/[/\\]/).pop() || `Document ${i + 1}` }
      }
      const url = profileImageUrl(doc?.url) || doc?.url
      return {
        url,
        filename: doc?.filename || `Document ${i + 1}`,
      }
    })
    .filter((d) => d.url)
}

const previewDocNavBtnClass =
  'shrink-0 gap-1 border-brand/40 bg-background text-foreground hover:border-brand hover:bg-brand/10 hover:text-brand sm:px-3'
const previewDocTabActiveClass =
  'border-brand bg-brand text-brand-foreground shadow-sm hover:bg-brand-strong hover:text-brand-foreground'
const previewDocTabClass =
  'border-brand/35 bg-background text-foreground hover:border-brand/60 hover:bg-brand/10 hover:text-brand'

/** Approver table preview: remarks + supporting documents only. */
export function CorrectionReasonPreviewCell({ item, loadDocuments }) {
  const [open, setOpen] = useState(false)
  const [activeDocIndex, setActiveDocIndex] = useState(0)
  const [fetchedDocs, setFetchedDocs] = useState(null)
  const [docsLoading, setDocsLoading] = useState(false)
  const remarks = remarksUserText(item?.remarks || '')
  const listDocLinks = useMemo(() => correctionDocumentLinks(item?.documents), [item?.documents])
  const docLinks = useMemo(() => {
    if (fetchedDocs) return correctionDocumentLinks(fetchedDocs)
    return listDocLinks
  }, [fetchedDocs, listDocLinks])
  const declaredCount = attachmentCount(item)
  const hasContent = Boolean(remarks || docLinks.length > 0 || declaredCount > 0)

  const activeDoc = docLinks[activeDocIndex] ?? docLinks[0] ?? null
  const docCount = docLinks.length

  function goToPrevDoc() {
    setActiveDocIndex((i) => (i <= 0 ? docCount - 1 : i - 1))
  }

  function goToNextDoc() {
    setActiveDocIndex((i) => (i >= docCount - 1 ? 0 : i + 1))
  }

  useEffect(() => {
    if (activeDocIndex >= docCount && docCount > 0) {
      setActiveDocIndex(docCount - 1)
    }
  }, [activeDocIndex, docCount])

  useEffect(() => {
    if (!open) {
      setActiveDocIndex(0)
      setFetchedDocs(null)
      setDocsLoading(false)
      return
    }
    setActiveDocIndex(0)
    if (listDocLinks.length > 0 || !loadDocuments || !item?.id) return
    if (declaredCount <= 0) return

    let cancelled = false
    setDocsLoading(true)
    loadDocuments(item.id)
      .then((documents) => {
        if (!cancelled && Array.isArray(documents) && documents.length > 0) {
          setFetchedDocs(documents)
        }
      })
      .catch(() => {})
      .finally(() => {
        if (!cancelled) setDocsLoading(false)
      })

    return () => {
      cancelled = true
    }
  }, [open, item?.id, loadDocuments, listDocLinks.length, declaredCount])

  useEffect(() => {
    if (!open || docCount <= 1) return undefined
    function onKeyDown(e) {
      if (e.key === 'ArrowLeft') {
        e.preventDefault()
        setActiveDocIndex((i) => (i <= 0 ? docCount - 1 : i - 1))
      } else if (e.key === 'ArrowRight') {
        e.preventDefault()
        setActiveDocIndex((i) => (i >= docCount - 1 ? 0 : i + 1))
      }
    }
    window.addEventListener('keydown', onKeyDown)
    return () => window.removeEventListener('keydown', onKeyDown)
  }, [open, docCount])

  if (!hasContent) {
    return <span className="text-sm text-muted-foreground">—</span>
  }

  return (
    <div className="flex w-full min-w-0 items-center justify-start">
      <Button
        type="button"
        variant="outline"
        size="sm"
        className="h-7 max-w-full shrink-0 gap-1 rounded-md border-brand/35 bg-background px-2 text-[11px] font-medium text-foreground shadow-none hover:border-brand/60 hover:bg-brand/10 hover:text-brand dark:bg-transparent"
        onClick={(e) => {
          e.stopPropagation()
          setOpen(true)
        }}
      >
        <Eye className="size-3 shrink-0 opacity-70" aria-hidden />
        Preview
      </Button>
      <Dialog open={open} onOpenChange={setOpen}>
        <DialogContent
          className="z-[60] flex max-h-[min(92dvh,900px)] w-[calc(100vw-1.5rem)] max-w-4xl flex-col overflow-hidden p-0"
          overlayClassName="z-[60]"
          innerClassName="flex min-h-0 flex-1 flex-col overflow-hidden p-4 sm:p-6"
          onClick={(e) => e.stopPropagation()}
        >
          <DialogHeader className="shrink-0 space-y-1 text-left">
            <DialogTitle>Request preview</DialogTitle>
            <DialogDescription>Remarks and supporting document preview.</DialogDescription>
          </DialogHeader>

          {remarks ? (
            <div className="shrink-0 rounded-lg border border-border/60 bg-muted/25 px-3 py-2.5 text-sm">
              <p className="text-xs font-semibold uppercase tracking-wide text-muted-foreground">Remarks</p>
              <p className="mt-1 max-h-24 overflow-y-auto whitespace-pre-wrap leading-relaxed text-foreground">{remarks}</p>
            </div>
          ) : null}

          <div className="flex min-h-0 flex-1 flex-col gap-3 pt-1">
            {docsLoading ? (
              <div className="flex min-h-[240px] flex-1 items-center justify-center text-sm text-muted-foreground">
                Loading attachments…
              </div>
            ) : docLinks.length === 0 ? (
              <div className="flex min-h-[120px] flex-1 items-center justify-center text-sm text-muted-foreground">
                None attached
              </div>
            ) : (
              <>
                {docCount > 1 ? (
                  <div className="flex shrink-0 flex-col gap-2">
                    <div className="flex items-center gap-2 rounded-xl border border-brand/25 bg-brand/5 p-2 sm:gap-3 sm:p-3">
                      <Button
                        type="button"
                        variant="outline"
                        size="sm"
                        className={previewDocNavBtnClass}
                        onClick={(e) => {
                          e.stopPropagation()
                          goToPrevDoc()
                        }}
                      >
                        <ChevronLeft className="size-4 shrink-0" aria-hidden />
                        <span className="hidden sm:inline">Previous</span>
                      </Button>
                      <div className="min-w-0 flex-1 px-1 text-center">
                        <p
                          className="truncate text-sm font-semibold text-foreground"
                          title={activeDoc?.filename}
                        >
                          {activeDoc?.filename}
                        </p>
                        <p className="text-xs tabular-nums text-muted-foreground">
                          File {activeDocIndex + 1} of {docCount}
                        </p>
                      </div>
                      <Button
                        type="button"
                        variant="outline"
                        size="sm"
                        className={previewDocNavBtnClass}
                        onClick={(e) => {
                          e.stopPropagation()
                          goToNextDoc()
                        }}
                      >
                        <span className="hidden sm:inline">Next</span>
                        <ChevronRight className="size-4 shrink-0" aria-hidden />
                      </Button>
                    </div>
                    <div
                      className="flex gap-1.5 overflow-x-auto overscroll-contain pb-0.5"
                      role="tablist"
                      aria-label="Supporting documents"
                    >
                      {docLinks.map((doc, i) => (
                        <Button
                          key={doc.url || i}
                          type="button"
                          variant="outline"
                          size="sm"
                          role="tab"
                          aria-selected={i === activeDocIndex}
                          className={cn(
                            'h-8 min-w-8 shrink-0 px-2.5 tabular-nums',
                            i === activeDocIndex ? previewDocTabActiveClass : previewDocTabClass,
                          )}
                          title={doc.filename}
                          onClick={(e) => {
                            e.stopPropagation()
                            setActiveDocIndex(i)
                          }}
                        >
                          {i + 1}
                        </Button>
                      ))}
                    </div>
                  </div>
                ) : null}
                {activeDoc ? (
                  <SupportingDocumentFilePreview
                    key={activeDoc.url}
                    url={activeDoc.url}
                    filename={activeDoc.filename}
                    className="min-h-0 flex-1"
                  />
                ) : null}
              </>
            )}
          </div>
        </DialogContent>
      </Dialog>
    </div>
  )
}

export function DocumentsIndicator({ count }) {
  const n = typeof count === 'number' ? count : 0
  if (n <= 0) {
    return (
      <span className="inline-flex items-center gap-1.5 text-sm text-muted-foreground">
        <FileText className="size-3.5 opacity-60" aria-hidden />
        No
      </span>
    )
  }
  return (
    <span className="inline-flex items-center gap-1.5 text-sm font-medium tabular-nums text-foreground">
      <FileText className="size-3.5 text-primary" aria-hidden />
      {n === 1 ? '1 file' : `${n} files`}
    </span>
  )
}
