/* eslint-disable react-refresh/only-export-components */
import { useState } from 'react'
import { cn } from '@/lib/utils'
import { Button } from '@/components/ui/button'
import { Badge } from '@/components/ui/badge'
import {
  History, Clock, RotateCcw, Copy, Trash2,
  ChevronDown, ChevronRight, Tag, FileText, User,
} from 'lucide-react'
import { formatDistanceToNow, format } from 'date-fns'

// ─── Version Entry Component ───────────────────────────────────────

function VersionEntry({ version, isActive, onRestore, onDuplicate, onDelete }) {
  const [expanded, setExpanded] = useState(false)

  const formattedDate = version.created_at
    ? (() => {
        try {
          const d = new Date(version.created_at)
          return `${format(d, 'MMM d, yyyy h:mm a')} (${formatDistanceToNow(d, { addSuffix: true })})`
        } catch {
          return version.created_at
        }
      })()
    : 'Unknown date'

  return (
    <div
      className={cn(
        'rounded-lg border bg-card transition-all',
        isActive
          ? 'border-brand/50 bg-brand/5 shadow-sm ring-1 ring-brand/20'
          : 'border-border/70 hover:border-border',
      )}
    >
      <div className="flex items-start gap-3 p-3">
        {/* Version icon */}
        <span className={cn(
          'flex size-8 shrink-0 items-center justify-center rounded-lg',
          isActive ? 'bg-brand text-brand-foreground' : 'bg-muted text-muted-foreground',
        )}>
          {isActive ? <Tag className="size-4" /> : <History className="size-4" />}
        </span>

        {/* Version info */}
        <div className="min-w-0 flex-1">
          <div className="flex items-center gap-2">
            <span className={cn(
              'text-sm font-bold',
              isActive ? 'text-foreground' : 'text-muted-foreground',
            )}>
              v{version.version || version.number || 1}
            </span>
            {isActive && (
              <Badge className="rounded-full bg-brand/15 text-brand border-0 text-[10px] px-2 py-0">
                Current
              </Badge>
            )}
            {version.status && (
              <Badge variant="outline" className="rounded-full text-[10px] px-2 py-0">
                {version.status}
              </Badge>
            )}
          </div>
          <p className="mt-0.5 flex items-center gap-1.5 text-[11px] text-muted-foreground">
            <Clock className="size-3" />
            {formattedDate}
          </p>
          {version.created_by && (
            <p className="mt-0.5 flex items-center gap-1.5 text-[11px] text-muted-foreground">
              <User className="size-3" />
              {version.created_by}
            </p>
          )}
          {version.change_summary && (
            <p className="mt-1 text-xs text-muted-foreground line-clamp-2">
              {version.change_summary}
            </p>
          )}

          {/* Changelog (expandable) */}
          {version.changelog && version.changelog.length > 0 && (
            <div className="mt-2">
              <button
                type="button"
                onClick={() => setExpanded(!expanded)}
                className="flex items-center gap-1 text-[10px] font-semibold text-brand hover:text-brand/80"
              >
                {expanded ? <ChevronDown className="size-3" /> : <ChevronRight className="size-3" />}
                {version.changelog.length} change{version.changelog.length !== 1 ? 's' : ''}
              </button>
              {expanded && (
                <ul className="mt-1 space-y-0.5 pl-4">
                  {version.changelog.map((entry, idx) => (
                    <li key={idx} className="list-disc text-[10px] text-muted-foreground">{entry}</li>
                  ))}
                </ul>
              )}
            </div>
          )}
        </div>

        {/* Actions */}
        <div className="flex shrink-0 items-center gap-0.5">
          {!isActive && (
            <Button type="button" variant="ghost" size="icon-sm" className="size-7" onClick={() => onRestore?.(version)} title="Restore this version">
              <RotateCcw className="size-3.5" />
            </Button>
          )}
          <Button type="button" variant="ghost" size="icon-sm" className="size-7" onClick={() => onDuplicate?.(version)} title="Duplicate this version">
            <Copy className="size-3.5" />
          </Button>
          {version.version > 1 && (
            <Button type="button" variant="ghost" size="icon-sm" className="size-7 text-destructive" onClick={() => onDelete?.(version)} title="Delete this version">
              <Trash2 className="size-3.5" />
            </Button>
          )}
        </div>
      </div>
    </div>
  )
}

// ─── Main Version History Component ────────────────────────────────

export default function VersionHistory({
  versions = [],
  currentVersionId,
  onRestore,
  onDuplicate,
  onDelete,
  onCreateVersion,
  saving = false,
  className,
}) {
  const sortedVersions = [...versions].sort((a, b) => {
    const aVer = a.version || a.number || 0
    const bVer = b.version || b.number || 0
    return bVer - aVer
  })

  return (
    <div className={cn('space-y-4', className)}>
      <div className="flex items-center justify-between">
        <div className="flex items-center gap-3">
          <span className="flex size-9 items-center justify-center rounded-lg bg-brand/10 text-brand">
            <History className="size-4" />
          </span>
          <div>
            <h3 className="text-sm font-bold text-foreground">Version History</h3>
            <p className="text-xs text-muted-foreground">
              {versions.length} version{versions.length !== 1 ? 's' : ''} — every save creates a new version
            </p>
          </div>
        </div>
        <Button
          type="button"
          variant="outline"
          size="sm"
          className="h-9 gap-1.5 rounded-lg text-xs"
          onClick={onCreateVersion}
          disabled={saving}
        >
          <FileText className="size-3.5" />
          Save as New Version
        </Button>
      </div>

      {versions.length === 0 ? (
        <div className="flex flex-col items-center justify-center rounded-lg border border-dashed border-border/60 bg-muted/10 px-4 py-8 text-center">
          <History className="mb-2 size-8 text-muted-foreground/40" strokeWidth={1.5} />
          <p className="text-sm font-semibold text-muted-foreground">No version history</p>
          <p className="mt-1 max-w-xs text-[11px] text-muted-foreground/60">
            Versions are created automatically when you save the form. Each save preserves the entire form structure.
          </p>
        </div>
      ) : (
        <div className="space-y-2">
          {sortedVersions.map((version) => {
            const verNum = version.version || version.number || 1
            const isActive = currentVersionId === version.id || currentVersionId === verNum
            return (
              <VersionEntry
                key={version.id || verNum}
                version={version}
                isActive={isActive}
                onRestore={onRestore}
                onDuplicate={onDuplicate}
                onDelete={onDelete}
              />
            )
          })}
        </div>
      )}

      <div className="rounded-lg border border-border/60 bg-muted/15 p-3 text-[11px] leading-relaxed text-muted-foreground">
        <strong className="text-foreground">Note:</strong> Old evaluations continue using the version they were created with.
        Restoring a previous version updates the form template but does not affect already-submitted evaluations.
      </div>
    </div>
  )
}

// ─── Helper to create a version snapshot ───────────────────────────

export function createVersionSnapshot(formState, previousVersions = []) {
  const currentNumber = previousVersions.length > 0
    ? Math.max(...previousVersions.map(v => v.version || v.number || 0))
    : 0

  const nextNumber = currentNumber + 1

  return {
    id: `v${nextNumber}`,
    version: nextNumber,
    created_at: new Date().toISOString(),
    created_by: 'Current User', // Will be populated by the backend in production
    status: formState.status || 'Draft',
    change_summary: '',
    changelog: [],
    // Snapshot of the full form structure at this point
    snapshot: {
      title: formState.title,
      description: formState.description,
      category: formState.category,
      evaluation_type: formState.evaluation_type,
      sections: formState.sections,
      introduction: formState.introduction,
      employee_fields: formState.employee_fields,
      relationships: formState.relationships,
      rating_scale: formState.rating_scale,
      scoring_ranges: formState.scoring_ranges,
      comments: formState.comments,
      header_config: formState.header_config,
    },
  }
}
