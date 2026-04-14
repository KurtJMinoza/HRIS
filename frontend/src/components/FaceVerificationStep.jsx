import { useState, useRef } from 'react'
import { Scan, Loader2 } from 'lucide-react'
import { Button } from '@/components/ui/button'
import { LiveFaceCapture } from '@/components/LiveFaceCapture'
import { verifyFace } from '@/api'

/**
 * Face verification step: live detection, auto-detect, validation, then verify.
 * Used after login (when user has_face) and before attendance clock-in/out.
 */
export function FaceVerificationStep({ onSuccess, onCancel, title = 'Face verification', description = 'Position your face in the frame. When the box turns green and valid, click Verify.' }) {
  const [loading, setLoading] = useState(false)
  const [ready, setReady] = useState(false)
  const [error, setError] = useState('')
  const captureRef = useRef(null)

  async function handleVerify() {
    if (!captureRef.current || loading) return
    setError('')
    setLoading(true)
    try {
      const result = await captureRef.current.capture()
      if (!result?.descriptor?.length || result.descriptor.length !== 128) {
        setError('No face detected. Please face the camera and try again.')
        setLoading(false)
        return
      }
      await verifyFace(result.descriptor)
      onSuccess?.()
    } catch (e) {
      setError(e.message || 'Verification failed.')
    } finally {
      setLoading(false)
    }
  }

  return (
    <div className="flex flex-col gap-4">
      <div className="space-y-1">
        <h3 className="flex items-center gap-2 font-semibold">
          <Scan className="size-5 text-primary" />
          {title}
        </h3>
        <p className="text-sm text-muted-foreground">{description}</p>
      </div>
      {error && (
        <p className="rounded-md bg-destructive/10 px-3 py-2 text-sm text-destructive" role="alert">
          {error}
        </p>
      )}
      <LiveFaceCapture
        captureRef={captureRef}
        onReadyChange={setReady}
      />
      <div className="flex flex-wrap gap-2">
        {onCancel && (
          <Button type="button" variant="outline" onClick={onCancel}>
            Cancel
          </Button>
        )}
        <Button onClick={handleVerify} disabled={!ready || loading}>
          {loading ? <Loader2 className="size-4 animate-spin" /> : <Scan className="size-4" />}
          Verify
        </Button>
      </div>
    </div>
  )
}
