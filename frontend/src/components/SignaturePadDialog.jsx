import { useEffect, useRef } from 'react'
import SignatureCanvas from 'react-signature-canvas'
import { Dialog, DialogContent, DialogDescription, DialogFooter, DialogHeader, DialogTitle } from '@/components/ui/dialog'
import { Button } from '@/components/ui/button'
import { Eraser, PenLine, Trash2, X } from 'lucide-react'
import { toast } from 'sonner'

export default function SignaturePadDialog({
  open,
  onOpenChange,
  title = 'Manage E-Signature',
  description = 'Draw your signature inside the box, then save.',
  initialImage = '',
  busy = false,
  onSave,
  onRemove,
}) {
  const sigRef = useRef(null)
  const safeInitialDataUrl =
    typeof initialImage === 'string' && initialImage.startsWith('data:image/')
      ? initialImage
      : ''

  function resizeCanvas() {
    const canvas = sigRef.current?.getCanvas?.()
    if (!canvas) return
    const ratio = Math.max(window.devicePixelRatio || 1, 1)
    const rect = canvas.getBoundingClientRect()
    const width = Math.max(1, Math.floor(rect.width || canvas.offsetWidth || 800))
    const height = Math.max(1, Math.floor(rect.height || canvas.offsetHeight || 240))
    canvas.width = Math.floor(width * ratio)
    canvas.height = Math.floor(height * ratio)
    const ctx = canvas.getContext('2d')
    if (!ctx) return
    ctx.setTransform(1, 0, 0, 1, 0, 0)
    ctx.scale(ratio, ratio)
    ctx.lineCap = 'round'
    ctx.lineJoin = 'round'
  }

  useEffect(() => {
    if (!open || !sigRef.current) return
    let rafId = 0

    const setup = () => {
      if (!sigRef.current) return
      resizeCanvas()
      sigRef.current.clear()

      if (safeInitialDataUrl) {
        try {
          sigRef.current.fromDataURL(safeInitialDataUrl)
        } catch {
          // ignore malformed previews
        }
      }
    }
    // Wait one frame so dialog layout has real dimensions.
    rafId = window.requestAnimationFrame(setup)

    const onResize = () => {
      if (!sigRef.current) return
      const existing = !sigRef.current.isEmpty() ? sigRef.current.toData() : null
      resizeCanvas()
      if (existing && existing.length > 0) {
        sigRef.current.fromData(existing)
      }
    }
    window.addEventListener('resize', onResize)

    return () => {
      window.cancelAnimationFrame(rafId)
      window.removeEventListener('resize', onResize)
    }
  }, [open, safeInitialDataUrl])

  const handleSave = async () => {
    if (!sigRef.current || sigRef.current.isEmpty()) {
      toast.error('Please draw a signature first.')
      return
    }
    try {
      // Avoid getTrimmedCanvas() because trim-canvas can fail under some bundlers.
      const rawCanvas = sigRef.current.getCanvas()
      if (!rawCanvas) {
        toast.error('Signature canvas is not available.')
        return
      }
      const dataUrl = rawCanvas.toDataURL('image/png')
      await onSave?.(dataUrl)
    } catch (e) {
      toast.error(e?.message || 'Failed to save signature.')
    }
  }

  return (
    <Dialog open={open} onOpenChange={onOpenChange}>
      <DialogContent className="max-w-lg">
        <DialogHeader>
          <div className="flex items-start justify-between gap-2">
            <DialogTitle>{title}</DialogTitle>
            <Button
              type="button"
              variant="ghost"
              size="icon"
              className="h-8 w-8 cursor-pointer disabled:cursor-not-allowed"
              onClick={() => onOpenChange?.(false)}
            >
              <X className="size-4" />
              <span className="sr-only">Close</span>
            </Button>
          </div>
          <DialogDescription>{description}</DialogDescription>
        </DialogHeader>

        <div className="rounded-md border border-border/70 bg-card p-3">
          {initialImage && !safeInitialDataUrl ? (
            <div className="mb-2 rounded-md border border-border/60 bg-muted/20 p-2">
              <p className="mb-1 text-xs text-muted-foreground">Current signature on file:</p>
              <img src={initialImage} alt="Current signature" className="h-16 max-w-full object-contain" />
            </div>
          ) : null}
          <div className="overflow-hidden rounded-md border border-border/70 bg-white">
            <SignatureCanvas
              ref={sigRef}
              penColor="#111827"
              minWidth={0.8}
              maxWidth={2.2}
              canvasProps={{
                className: 'h-[180px] w-full touch-none bg-white cursor-crosshair',
                style: { width: '100%', height: 180, display: 'block', touchAction: 'none' },
              }}
            />
          </div>
          <p className="mt-2 text-xs text-muted-foreground">Tip: Sign naturally using mouse, touchpad, or touchscreen.</p>
        </div>

        <DialogFooter className={`grid w-full gap-2 ${onRemove ? 'grid-cols-3' : 'grid-cols-2'}`}>
          {onRemove ? (
            <Button
              type="button"
              variant="outline"
              className="h-9 w-full min-w-0 cursor-pointer px-2 text-xs disabled:cursor-not-allowed"
              disabled={busy}
              onClick={onRemove}
            >
              <Trash2 className="mr-2 size-4" />
              Remove Signature
            </Button>
          ) : null}
          <Button
            type="button"
            variant="outline"
            className="h-9 w-full min-w-0 cursor-pointer px-2 text-xs disabled:cursor-not-allowed"
            disabled={busy}
            onClick={() => {
              sigRef.current?.clear()
            }}
          >
            <Eraser className="mr-2 size-4" />
            Clear Drawing
          </Button>
          <Button
            type="button"
            className="h-9 w-full min-w-0 cursor-pointer px-2 text-xs disabled:cursor-not-allowed"
            disabled={busy}
            onClick={handleSave}
          >
            <PenLine className="mr-2 size-4" />
            Save Signature
          </Button>
        </DialogFooter>
      </DialogContent>
    </Dialog>
  )
}
