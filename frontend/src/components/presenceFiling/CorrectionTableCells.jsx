import { Link } from 'react-router-dom'
import { CheckCircle2, Clock, FileText, XCircle } from 'lucide-react'
import { Popover, PopoverContent, PopoverTrigger } from '@/components/ui/popover'
import { Avatar, AvatarFallback, AvatarImage } from '@/components/ui/avatar'
import { Badge } from '@/components/ui/badge'
import { cn } from '@/lib/utils'
import { profileImageUrl } from '@/api'
import {
  issueLabel,
  reasonLabel,
  remarksUserText,
  reviewStatusBadgeClass,
  reviewStatusKey,
  reviewStatusLabel,
  formatTimeOnly,
} from '@/lib/presenceFilingTable'

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
    <div className={cn('flex min-w-0 max-w-[min(100%,20rem)] items-start gap-3', compact && 'gap-2.5')}>
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
            'truncate font-semibold leading-snug tracking-tight text-foreground',
            compact ? 'text-sm' : 'text-[15px]'
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

export function ReviewStatusTableBadge({ item }) {
  const key = reviewStatusKey(item)
  const ds = item?.display_status && String(item.display_status).trim()
  const label = ds || reviewStatusLabel(item)
  const Icon =
    key === 'rejected' ? XCircle : key === 'hr_approved' ? CheckCircle2 : Clock
  return (
    <span
      className={cn(
        'inline-flex max-w-full items-center gap-1.5 rounded-full border px-2.5 py-1 text-xs font-semibold leading-tight shadow-sm',
        reviewStatusBadgeClass(key)
      )}
    >
      <Icon className="size-3.5 shrink-0 opacity-90" aria-hidden />
      <span className="line-clamp-2">{label}</span>
    </span>
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
          className="group max-w-full text-left text-sm text-foreground outline-none transition hover:text-primary focus-visible:ring-2 focus-visible:ring-ring focus-visible:ring-offset-2"
        >
          <span className="line-clamp-2 font-normal leading-snug text-foreground/90">{clean}</span>
          <span className="mt-1 block text-xs font-semibold text-primary underline-offset-2 group-hover:underline">
            View full
          </span>
        </button>
      </PopoverTrigger>
      <PopoverContent className="max-h-[min(70vh,24rem)] w-[min(100vw-2rem,28rem)] overflow-y-auto text-sm" align="start">
        <p className="whitespace-pre-wrap leading-relaxed text-foreground">{clean}</p>
      </PopoverContent>
    </Popover>
  )
}

/** Time in / time out in tables: monospace time or muted em dash. */
export function TimeCell({ iso }) {
  if (!iso) {
    return <span className="font-mono text-sm text-muted-foreground">—</span>
  }
  return (
    <span className="font-mono text-sm tabular-nums text-foreground">{formatTimeOnly(iso)}</span>
  )
}

export function IssueTypeCell({ issueType, reasonCode }) {
  const sub = reasonLabel(reasonCode)
  return (
    <div className="flex min-w-0 max-w-[14rem] flex-col gap-1">
      <Badge
        variant="outline"
        className="w-fit rounded-lg border-slate-200/90 bg-white px-2.5 py-0.5 text-xs font-medium shadow-sm dark:border-slate-700 dark:bg-slate-900/40"
      >
        {issueLabel(issueType)}
      </Badge>
      {sub ? (
        <span className="block text-[11px] leading-snug text-muted-foreground" title={sub}>
          {sub}
        </span>
      ) : null}
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
