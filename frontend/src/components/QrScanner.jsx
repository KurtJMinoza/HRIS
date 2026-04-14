/**
 * QR code scanner using ZXing ("Zebra Crossing").
 * Decoding: @zxing/browser → BrowserQRCodeReader → decodeFromConstraints(video) → onResult(employee_code).
 * Flow: Camera → ZXing decode → POST /api/attendance/scan → Backend validation → UI feedback.
 */
import { useEffect, useRef, useState } from 'react'
import { BrowserQRCodeReader } from '@zxing/browser'
import { CheckCircle2, Loader2, Lock } from 'lucide-react'
import { cn } from '@/lib/utils'

export function QrScanner({
  onResult,
  paused = false,
  stopOnResult = true,
  facingMode = 'environment',
  mirror = false,
  /** Real-time validation error (e.g. schedule not assigned) */
  validationError = null,
  /** Brief success state (e.g. parent sets true after API success) */
  success = false,
  className,
}) {
  const videoRef = useRef(null)
  const controlsRef = useRef(null)
  const [status, setStatus] = useState('Initializing camera…')
  const [error, setError] = useState('')
  const hasFiredRef = useRef(false)

  useEffect(() => {
    let cancelled = false

    async function start() {
      setError('')
      setStatus('Starting camera…')
      hasFiredRef.current = false

      try {
        const videoEl = videoRef.current
        if (!videoEl) return

        const reader = new BrowserQRCodeReader()
        const constraints = {
          video: {
            facingMode,
            width: { ideal: 1280, min: 640 },
            height: { ideal: 720, min: 480 },
          },
        }
        const controls = await reader.decodeFromConstraints(
          constraints,
          videoEl,
          (result, err) => {
            if (cancelled) return
            if (result) {
              if (stopOnResult && hasFiredRef.current) return
              hasFiredRef.current = true
              setStatus('Verifying…')
              const text = typeof result.getText === 'function' ? result.getText() : String(result)
              onResult?.(text)
              return
            }
            if (err && !hasFiredRef.current) {
              setStatus('Scanning for QR code…')
            }
          }
        )

        controlsRef.current = controls
        setStatus('Scanning for QR code…')
      } catch {
        if (cancelled) return
        setError('Camera access denied or unavailable.')
        setStatus('Camera error')
      }
    }

    if (!paused) {
      start()
    }

    return () => {
      cancelled = true
      try {
        controlsRef.current?.stop()
      } catch {
        // ignore
      } finally {
        controlsRef.current = null
      }
    }
  }, [paused, facingMode, stopOnResult, onResult])

  if (error) {
    return (
      <div
        className={cn(
          'mx-auto flex max-w-2xl flex-col items-center justify-center rounded-xl border border-zinc-600/50 bg-zinc-900/95 px-6 py-8 text-center backdrop-blur-sm',
          className
        )}
      >
        <div className="rounded-lg border border-red-400/30 bg-red-500/10 px-4 py-3 text-sm font-medium text-red-200 backdrop-blur-sm">
          {error || 'Camera access denied or unavailable.'}
        </div>
      </div>
    )
  }

  const isProcessing = paused
  const showSuccess = success

  return (
    <div
      className={cn(
        'mx-auto flex w-full max-w-2xl flex-col gap-4 rounded-xl border border-zinc-700/50 bg-zinc-900/95 p-4 shadow-xl backdrop-blur-md',
        className
      )}
    >
      {/* Camera + animated frame */}
      <div className="relative aspect-video min-h-[280px] overflow-hidden rounded-lg bg-zinc-950">
        <video
          ref={videoRef}
          className={cn('h-full w-full object-cover', mirror && '-scale-x-100')}
          muted
          playsInline
        />

        {/* Animated scanning frame */}
        <div className="pointer-events-none absolute inset-0 flex items-center justify-center">
          <div className="relative w-[76%] max-w-[380px] aspect-square">
            {/* Outer frame with subtle glow */}
            <div className="absolute inset-0 rounded-lg border-2 border-zinc-500/70 shadow-[0_0_20px_rgba(0,0,0,0.4)]" />
            {/* Animated scan line */}
            <div
              className="absolute left-4 right-4 top-[15%] h-0.5 bg-linear-to-r from-transparent via-zinc-400 to-transparent opacity-90"
              style={{ animation: 'qrScanLine 2.2s ease-in-out infinite' }}
            />
            {/* Corner accents */}
            <div className="absolute -inset-px rounded-lg border border-zinc-500/30" />
          </div>
        </div>

        {/* Lock overlay while processing */}
        {isProcessing && (
          <div className="absolute inset-0 flex flex-col items-center justify-center bg-zinc-900/85 backdrop-blur-sm">
            <div className="flex flex-col items-center gap-3 rounded-lg border border-zinc-600/50 bg-zinc-800/80 px-6 py-5 backdrop-blur-md">
              <Lock className="size-8 text-zinc-400" aria-hidden />
              <p className="text-sm font-medium text-zinc-200">Verifying…</p>
              <p className="text-xs text-zinc-500">Schedule and attendance validated</p>
            </div>
          </div>
        )}

        {/* Success overlay */}
        {showSuccess && !isProcessing && (
          <div className="absolute inset-0 flex flex-col items-center justify-center bg-emerald-950/80 backdrop-blur-sm">
            <div className="flex flex-col items-center gap-2 rounded-lg border border-emerald-500/30 bg-emerald-500/10 px-6 py-5 backdrop-blur-md">
              <CheckCircle2 className="size-10 text-emerald-400" aria-hidden />
              <p className="text-sm font-semibold text-emerald-200">Verified</p>
            </div>
          </div>
        )}
      </div>

      {/* Glassmorphism status card */}
      <div className="flex items-center justify-center gap-2.5 rounded-lg border border-white/10 bg-white/5 px-4 py-3 text-center backdrop-blur-md">
        <Loader2
          className={cn('size-4 shrink-0 text-zinc-400', isProcessing && 'animate-spin')}
          aria-hidden
        />
        <span className="text-sm font-medium text-zinc-300">{status}</span>
      </div>

      {/* Glassmorphism validation error card */}
      {validationError && (
        <div
          className="rounded-lg border border-red-400/30 bg-red-500/10 px-4 py-3 text-center text-sm font-medium text-red-200 backdrop-blur-md"
          role="alert"
        >
          {validationError}
        </div>
      )}

      <style>{`
        @keyframes qrScanLine {
          0%, 100% { top: 15%; opacity: 0.9; }
          50% { top: 85%; opacity: 0.6; }
        }
      `}</style>
    </div>
  )
}
