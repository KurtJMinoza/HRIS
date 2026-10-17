import { Dialog, DialogContent, DialogFooter } from '@/components/ui/dialog'
import { Button } from '@/components/ui/button'
import { CooWelcomeLetterContent } from '@/components/notifications/CooWelcomeLetterContent'
import { cn } from '@/lib/utils'

const LETTER_CONTENT = {
  coo_rocelyn_cortez: CooWelcomeLetterContent,
}

export default function WelcomeLetterModal({ letterKey, open, onClose }) {
  const Content = letterKey ? LETTER_CONTENT[letterKey] : null

  return (
    <Dialog
      open={open && Boolean(Content)}
      onOpenChange={(nextOpen) => {
        if (nextOpen) return
      }}
    >
      <DialogContent
        pinFooter
        showCloseButton={false}
        className={cn(
          'max-h-[min(92dvh,calc(100dvh-1rem))] max-w-[min(100vw-1.5rem,42rem)] border-brand/15 p-0',
          'data-[state=open]:duration-500 data-[state=open]:zoom-in-95 data-[state=open]:slide-in-from-bottom-4',
        )}
        innerClassName="overflow-y-auto overscroll-contain px-6 pb-4 pt-8 sm:px-8 sm:pt-10"
        overlayClassName="bg-slate-900/50 backdrop-blur-sm data-[state=open]:duration-500"
        onPointerDownOutside={(e) => e.preventDefault()}
        onInteractOutside={(e) => e.preventDefault()}
        onEscapeKeyDown={(e) => e.preventDefault()}
      >
        {Content ? <Content /> : null}
        <DialogFooter className="shrink-0 border-t border-border/60 bg-card px-6 py-4 sm:px-8">
          <Button
            type="button"
            className="w-full rounded-xl bg-brand font-semibold text-brand-foreground hover:bg-brand-strong sm:w-auto sm:min-w-40"
            onClick={() => onClose?.()}
          >
            Close
          </Button>
        </DialogFooter>
      </DialogContent>
      <style>{`
        .welcome-letter-line {
          animation: welcome-letter-fade 0.65s ease-out both;
        }
        @keyframes welcome-letter-fade {
          from {
            opacity: 0;
            transform: translateY(10px);
          }
          to {
            opacity: 1;
            transform: translateY(0);
          }
        }
      `}</style>
    </Dialog>
  )
}
