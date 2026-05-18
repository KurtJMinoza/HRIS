import {
  Dialog,
  DialogContent,
  DialogDescription,
  DialogFooter,
  DialogHeader,
  DialogTitle,
} from '@/components/ui/dialog'
import { Button } from '@/components/ui/button'

/**
 * Shows counts and optional per-request failure rows from bulk approve API responses.
 */
export function BulkApprovalSummaryDialog({ open, onOpenChange, title, summary }) {
  const items = Array.isArray(summary?.failed_items) ? summary.failed_items : []
  const approved = Number(summary?.approved_count ?? 0)
  const skipped = Number(summary?.skipped_count ?? 0)
  const failed = Number(summary?.failed_count ?? 0)
  const trouble = skipped + failed

  return (
    <Dialog open={open} onOpenChange={onOpenChange}>
      <DialogContent className="max-w-lg">
        <DialogHeader>
          <DialogTitle>{title || 'Bulk approval summary'}</DialogTitle>
          <DialogDescription>
            {approved} approved
            {trouble ? ` · ${trouble} skipped or failed` : ''}.
          </DialogDescription>
        </DialogHeader>
        {items.length > 0 ? (
          <div className="max-h-[min(50vh,20rem)] space-y-2 overflow-y-auto rounded-md border border-border/70 bg-muted/20 p-3 text-sm">
            {items.map((row, i) => (
              <div key={`${row.request_id}-${i}`} className="border-b border-border/40 pb-2 last:border-0 last:pb-0">
                <p className="font-mono font-semibold text-foreground">Request #{row.request_id}</p>
                <p className="mt-1 text-muted-foreground">{row.reason || 'No reason returned.'}</p>
              </div>
            ))}
          </div>
        ) : trouble > 0 ? (
          <p className="text-sm text-muted-foreground">Some requests were not approved; details were not returned by the server.</p>
        ) : null}
        <DialogFooter>
          <Button type="button" onClick={() => onOpenChange(false)}>
            Close
          </Button>
        </DialogFooter>
      </DialogContent>
    </Dialog>
  )
}
