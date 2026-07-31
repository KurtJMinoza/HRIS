import { Avatar, AvatarFallback, AvatarImage } from '@/components/ui/avatar'
import { profileImageUrl } from '@/api'
import { cn } from '@/lib/utils'

function getInitials(name) {
  if (!name || typeof name !== 'string') return '?'
  const parts = name.trim().split(/\s+/).filter(Boolean)
  if (parts.length === 0) return '?'
  if (parts.length === 1) return parts[0].slice(0, 2).toUpperCase()
  return `${parts[0][0] ?? ''}${parts[parts.length - 1][0] ?? ''}`.toUpperCase() || '?'
}

/**
 * Compact approver identity for leave / overtime / correction list tables.
 */
export default function ApproverAvatarNameCell({
  name,
  imageUrl,
  compact = true,
  emptyLabel = '—',
  className,
}) {
  const display = String(name || '').trim()
  if (!display) {
    return <span className="text-xs text-muted-foreground">{emptyLabel}</span>
  }

  return (
    <div className={cn('flex min-w-0 max-w-full items-center gap-2.5', className)}>
      <Avatar
        className={cn(
          'shrink-0 border border-border/70 bg-muted shadow-sm',
          compact ? 'size-8' : 'size-9'
        )}
      >
        {imageUrl ? (
          <AvatarImage src={profileImageUrl(imageUrl)} alt="" className="object-cover" />
        ) : null}
        <AvatarFallback className="bg-gradient-to-br from-slate-200 to-slate-300 text-[10px] font-bold text-slate-800 dark:from-slate-700 dark:to-slate-600 dark:text-slate-100">
          {getInitials(display)}
        </AvatarFallback>
      </Avatar>
      <span
        className={cn(
          'min-w-0 truncate font-semibold leading-snug text-foreground',
          compact ? 'text-[12px]' : 'text-sm'
        )}
        title={display}
      >
        {display}
      </span>
    </div>
  )
}

/** Resolve list-row fields used across leave / OT / correction payloads. */
export function approverFromRequestRow(row) {
  if (!row || typeof row !== 'object') {
    return { name: '', imageUrl: null }
  }
  const name = String(
    row.current_approver_name
      || row.current_approver
      || row.approved_by_name
      || row.rejected_by_name
      || ''
  ).trim()
  const imageUrl =
    row.current_approver_profile_image
    || row.current_approver_profile_image_url
    || row.approved_by_profile_image
    || row.approved_by_profile_image_url
    || null

  return { name, imageUrl }
}
