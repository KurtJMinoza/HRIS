import { Loader2 } from 'lucide-react'
import { Button } from '@/components/ui/button'
import { cn } from '@/lib/utils'
import {
  Dialog,
  DialogContent,
  DialogFooter,
  DialogHeader,
  DialogTitle,
} from '@/components/ui/dialog'

const EARLY_PAYOFF_MESSAGE = `Close this loan early?

Remaining balance will be cleared and pending installments skipped.`

const BTN_SECONDARY =
  'border border-border bg-background text-[#0A0A0A] shadow-sm hover:bg-muted/80 [&_svg]:text-[#0A0A0A] dark:border-white/15 dark:bg-card dark:text-slate-100 dark:[&_svg]:text-slate-100 dark:hover:bg-white/10'

/**
 * Confirmation modal for early loan payoff (admin + salary tab).
 */
export function EarlyLoanPayoffConfirmDialog({ open, onOpenChange, onConfirm, loading = false }) {
  return (
    <Dialog open={open} onOpenChange={onOpenChange}>
      <DialogContent className="max-w-lg gap-6 sm:max-w-lg" showCloseButton>
        <DialogHeader className="space-y-3 text-left">
          <DialogTitle className="text-lg font-semibold tracking-tight text-[#0A0A0A] dark:text-slate-50">
            Close loan early
          </DialogTitle>
          <p className="whitespace-pre-line text-[15px] leading-relaxed text-[#0A0A0A]/85 dark:text-slate-300">
            {EARLY_PAYOFF_MESSAGE.trim()}
          </p>
        </DialogHeader>
        <DialogFooter className="flex flex-col-reverse gap-2 sm:flex-row sm:items-center sm:justify-between sm:space-x-0">
          <Button
            type="button"
            variant="outline"
            className={cn(BTN_SECONDARY, 'w-full sm:w-auto')}
            onClick={() => onOpenChange(false)}
            disabled={loading}
          >
            Cancel
          </Button>
          <Button type="button" variant="destructive" className="w-full sm:w-auto" onClick={onConfirm} disabled={loading}>
            {loading ? (
              <>
                <Loader2 className="mr-2 size-4 animate-spin" aria-hidden />
                Closing…
              </>
            ) : (
              'Close loan'
            )}
          </Button>
        </DialogFooter>
      </DialogContent>
    </Dialog>
  )
}
