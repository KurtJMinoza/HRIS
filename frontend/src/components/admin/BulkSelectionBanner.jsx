import { Button } from '@/components/ui/button'
import { cn } from '@/lib/utils'

/**
 * Banner for page-level vs global bulk selection.
 */
export function BulkSelectionBanner({
  className,
  pageCount = 0,
  totalCount = 0,
  selectAllMatching = false,
  showPageSelectAllBanner = false,
  onSelectAllMatching,
  onClearSelection,
  entityLabel = 'requests',
}) {
  const page = Number(pageCount) || 0
  const total = Number(totalCount) || 0
  const label = entityLabel

  if (selectAllMatching && total > 0) {
    return (
      <div
        className={cn(
          'flex flex-col gap-2 rounded-lg border border-brand/30 bg-brand/10 px-3 py-2.5 text-sm sm:flex-row sm:items-center sm:justify-between',
          className,
        )}
        role="status"
      >
        <p className="text-foreground">
          All <span className="font-semibold tabular-nums">{total}</span> matching {label} are
          selected.
        </p>
        <Button type="button" variant="ghost" size="sm" className="h-8 shrink-0" onClick={onClearSelection}>
          Clear selection
        </Button>
      </div>
    )
  }

  if (!showPageSelectAllBanner || page === 0 || total <= page) {
    return null
  }

  return (
    <div
      className={cn(
        'flex flex-col gap-2 rounded-lg border border-border/70 bg-muted/40 px-3 py-2.5 text-sm sm:flex-row sm:items-center sm:justify-between',
        className,
      )}
      role="status"
    >
      <p className="text-muted-foreground">
        All <span className="font-semibold tabular-nums text-foreground">{page}</span> {label} on
        this page are selected.
      </p>
      <Button type="button" variant="link" size="sm" className="h-8 shrink-0 px-0" onClick={onSelectAllMatching}>
        Select all {total} matching {label}
      </Button>
    </div>
  )
}
