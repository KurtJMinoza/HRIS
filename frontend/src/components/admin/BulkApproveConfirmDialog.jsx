import { Loader2 } from 'lucide-react'
import { Button } from '@/components/ui/button'
import {
  Dialog,
  DialogContent,
  DialogDescription,
  DialogFooter,
  DialogHeader,
  DialogTitle,
} from '@/components/ui/dialog'

/**
 * Confirmation before bulk-approving selected requests.
 */
export function BulkApproveConfirmDialog({
  open,
  onOpenChange,
  selectedCount = 0,
  selectAllMatching = false,
  remarks = '',
  onConfirm,
  loading = false,
  entityLabel = 'requests',
}) {
  const count = Number(selectedCount) || 0
  const trimmedRemarks = String(remarks || '').trim()
  const label = entityLabel

  return (
    <Dialog open={open} onOpenChange={onOpenChange}>
      <DialogContent className="max-w-md gap-5" showCloseButton>
        <DialogHeader className="text-left">
          <DialogTitle>
            {selectAllMatching ? `Approve all matching ${label}?` : `Approve selected ${label}?`}
          </DialogTitle>
          <DialogDescription className="text-sm leading-relaxed">
            {selectAllMatching ? (
              <>
                You are about to approve{' '}
                <span className="font-semibold text-foreground">
                  {count} {count === 1 ? 'request' : 'requests'}
                </span>{' '}
                matching the current filters across all pages. This action cannot be undone from
                this screen.
              </>
            ) : (
              <>
                You are about to approve{' '}
                <span className="font-semibold text-foreground">
                  {count} {count === 1 ? 'request' : 'requests'}
                </span>
                . This action cannot be undone from this screen.
              </>
            )}
            {trimmedRemarks ? (
              <>
                {' '}
                The same approval remarks will be saved on each approved request.
              </>
            ) : null}
          </DialogDescription>
        </DialogHeader>
        {trimmedRemarks ? (
          <div className="rounded-lg border border-border/60 bg-muted/30 px-3 py-2.5 text-sm">
            <p className="text-xs font-medium text-muted-foreground">Approval remarks</p>
            <p className="mt-1 whitespace-pre-wrap text-foreground">{trimmedRemarks}</p>
          </div>
        ) : null}
        <DialogFooter className="flex flex-col-reverse gap-2 sm:flex-row sm:justify-end">
          <Button type="button" variant="outline" onClick={() => onOpenChange(false)} disabled={loading}>
            Cancel
          </Button>
          <Button type="button" onClick={onConfirm} disabled={loading || count === 0}>
            {loading ? (
              <>
                <Loader2 className="size-4 animate-spin" aria-hidden />
                Approving…
              </>
            ) : (
              'Confirm approval'
            )}
          </Button>
        </DialogFooter>
      </DialogContent>
    </Dialog>
  )
}
