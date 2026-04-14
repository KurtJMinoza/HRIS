import { useEffect, useMemo, useState } from 'react'
import Cropper from 'react-easy-crop'
import { Dialog, DialogContent, DialogDescription, DialogFooter, DialogHeader, DialogTitle } from '@/components/ui/dialog'
import { Button } from '@/components/ui/button'

function createImage(url) {
  return new Promise((resolve, reject) => {
    const image = new Image()
    image.addEventListener('load', () => resolve(image))
    image.addEventListener('error', (error) => reject(error))
    image.setAttribute('crossOrigin', 'anonymous')
    image.src = url
  })
}

async function cropToBlob(imageSrc, pixelCrop, outputType = 'image/jpeg', quality = 0.92) {
  const image = await createImage(imageSrc)
  const canvas = document.createElement('canvas')
  const ctx = canvas.getContext('2d')
  if (!ctx) throw new Error('Canvas not supported')

  canvas.width = pixelCrop.width
  canvas.height = pixelCrop.height

  ctx.drawImage(
    image,
    pixelCrop.x,
    pixelCrop.y,
    pixelCrop.width,
    pixelCrop.height,
    0,
    0,
    pixelCrop.width,
    pixelCrop.height
  )

  return new Promise((resolve, reject) => {
    canvas.toBlob(
      (blob) => {
        if (!blob) reject(new Error('Failed to crop image'))
        else resolve(blob)
      },
      outputType,
      quality
    )
  })
}

function extensionForMime(mime) {
  if (mime === 'image/png') return 'png'
  return 'jpg'
}

export function ImageCropDialog({
  open,
  onOpenChange,
  file,
  title = 'Crop image',
  description = 'Adjust position and zoom, then save.',
  maxBytes = 2 * 1024 * 1024,
  onConfirm,
}) {
  const [crop, setCrop] = useState({ x: 0, y: 0 })
  const [zoom, setZoom] = useState(1)
  const [croppedAreaPixels, setCroppedAreaPixels] = useState(null)
  const [saving, setSaving] = useState(false)
  const [error, setError] = useState('')

  const imageUrl = useMemo(() => {
    if (!file) return ''
    return URL.createObjectURL(file)
  }, [file])

  useEffect(() => {
    return () => {
      if (imageUrl) URL.revokeObjectURL(imageUrl)
    }
  }, [imageUrl])

  useEffect(() => {
    if (!open) return
    setCrop({ x: 0, y: 0 })
    setZoom(1)
    setCroppedAreaPixels(null)
    setSaving(false)
    setError('')
  }, [open, file])

  const outputMime = file?.type === 'image/png' ? 'image/png' : 'image/jpeg'

  async function handleConfirm() {
    if (!file || !imageUrl || !croppedAreaPixels || saving) return
    setSaving(true)
    setError('')
    try {
      const blob = await cropToBlob(imageUrl, croppedAreaPixels, outputMime, 0.92)
      if (blob.size > maxBytes) {
        setError('Cropped image exceeds maximum file size.')
        return
      }
      const ext = extensionForMime(outputMime)
      const base = String(file.name || 'profile').replace(/\.[^/.]+$/, '')
      const nextFile = new File([blob], `${base}-cropped.${ext}`, { type: outputMime })
      await onConfirm?.(nextFile)
      onOpenChange?.(false)
    } catch (e) {
      setError(e?.message || 'Failed to crop image')
    } finally {
      setSaving(false)
    }
  }

  return (
    <Dialog open={open} onOpenChange={onOpenChange}>
      <DialogContent className="max-w-2xl">
        <DialogHeader>
          <DialogTitle>{title}</DialogTitle>
          <DialogDescription>{description}</DialogDescription>
        </DialogHeader>

        <div className="space-y-4">
          <div className="relative h-[360px] w-full overflow-hidden rounded-xl border border-border bg-muted">
            {imageUrl ? (
              <Cropper
                image={imageUrl}
                crop={crop}
                zoom={zoom}
                aspect={1}
                cropShape="round"
                showGrid={false}
                onCropChange={setCrop}
                onZoomChange={setZoom}
                onCropComplete={(_, pixels) => setCroppedAreaPixels(pixels)}
              />
            ) : null}
          </div>

          <div className="space-y-2">
            <div className="flex items-center justify-between text-sm">
              <span className="text-muted-foreground">Zoom</span>
              <span className="tabular-nums text-muted-foreground">{zoom.toFixed(2)}x</span>
            </div>
            <input
              type="range"
              min={1}
              max={3}
              step={0.01}
              value={zoom}
              onChange={(e) => setZoom(Number(e.target.value))}
              className="w-full"
            />
          </div>

          {error ? <p className="text-sm text-destructive">{error}</p> : null}
        </div>

        <DialogFooter>
          <Button type="button" variant="outline" onClick={() => onOpenChange?.(false)} disabled={saving}>
            Cancel
          </Button>
          <Button type="button" onClick={handleConfirm} disabled={saving || !croppedAreaPixels}>
            {saving ? 'Saving…' : 'Save photo'}
          </Button>
        </DialogFooter>
      </DialogContent>
    </Dialog>
  )
}

