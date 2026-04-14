import { useState, useEffect, useLayoutEffect, useRef, useCallback } from 'react'
import {
  loadFaceModels,
  detectStrictSingleFace,
  validateFaceDetection,
  drawDetectionBox,
} from '@/lib/faceApi'

/**
 * Live face capture using face-api.js. Shows camera stream with face box; capture() returns 128D descriptor + optional image.
 *
 * @param {Object} props
 * @param {(descriptor: number[], faceImageDataUrl?: string) => void} [props.onCapture] - Called with 128D descriptor and optional data URL.
 * @param {number} [props.autoCaptureDelay] - If > 0, call onCapture automatically this many ms after becoming "ready". 0 = manual only.
 * @param {boolean} [props.includeFaceImage] - If true, pass data URL as second arg to onCapture.
 * @param {React.MutableRefObject<{ capture: () => Promise<{ descriptor: number[], faceImage?: string } | null> } | null>} [props.captureRef] - Ref to call capture() from parent.
 * @param {(ready: boolean) => void} [props.onReadyChange] - Called when ready-to-capture state changes.
 * @param {{ message: string, type: 'success'|'error'|'matching' }|null} [props.externalStatus] - Override status from parent.
 * @param {{ requireCentered?: boolean, minBrightness?: number }} [props.validationOptions] - Validation options.
 */
const READY_DELAY_MS = 2500
const DETECTION_INTERVAL_MS = 90

export function LiveFaceCapture({
  onCapture,
  autoCaptureDelay = 0,
  includeFaceImage = false,
  captureRef,
  onReadyChange,
  externalStatus = null,
  validationOptions = {},
}) {
  const videoRef = useRef(null)
  const canvasRef = useRef(null)
  const overlayRef = useRef(null)
  const streamRef = useRef(null)
  const autoCaptureTimerRef = useRef(null)
  const onReadyChangeRef = useRef(onReadyChange)
  const lastResultRef = useRef(null)

  const [modelsReady, setModelsReady] = useState(false)
  const [cameraError, setCameraError] = useState('')
  const [status, setStatus] = useState('loading')
  const [statusMessage, setStatusMessage] = useState('Loading camera…')
  const [ready, setReady] = useState(false)
  const statusRef = useRef(status)
  statusRef.current = status
  onReadyChangeRef.current = onReadyChange

  const startCamera = useCallback(async () => {
    setCameraError('')
    try {
      if (streamRef.current) {
        streamRef.current.getTracks().forEach((t) => t.stop())
        streamRef.current = null
      }
      const stream = await navigator.mediaDevices.getUserMedia({
        video: { facingMode: 'user', width: 640, height: 480 },
      })
      streamRef.current = stream
      if (videoRef.current) videoRef.current.srcObject = stream
      setStatus('live')
      setStatusMessage('Position your face inside the frame')
    } catch {
      setCameraError('Camera access denied or unavailable.')
      setStatus('error')
    }
  }, [])

  useEffect(() => {
    let cancelled = false
    loadFaceModels()
      .then(() => { if (!cancelled) setModelsReady(true) })
      .catch(() => { if (!cancelled) setCameraError('Failed to load face models.') })
    return () => { cancelled = true }
  }, [])

  useEffect(() => {
    if (!modelsReady) return
    startCamera()
    return () => {
      streamRef.current?.getTracks().forEach((t) => t.stop())
      streamRef.current = null
    }
  }, [modelsReady, startCamera])

  // Detection loop: run face detection and draw box on overlay
  useEffect(() => {
    if (!modelsReady || status !== 'live' || cameraError) return
    const video = videoRef.current
    const canvas = overlayRef.current
    if (!video || !canvas) return

    let rafId
    let lastDetect = 0

    const tick = async () => {
      const now = Date.now()
      if (now - lastDetect >= DETECTION_INTERVAL_MS) {
        lastDetect = now
        try {
          const result = await detectStrictSingleFace(video)
          lastResultRef.current = result
          const ctx = canvas.getContext('2d')
          if (ctx) {
            ctx.clearRect(0, 0, canvas.width, canvas.height)
            if (result) {
              const vw = video.videoWidth
              const vh = video.videoHeight
              const validation = validateFaceDetection(result, ctx, vw, vh, validationOptions)
              drawDetectionBox(canvas, result, validation.valid)
            }
          }
        } catch {
          lastResultRef.current = null
          if (canvas.getContext('2d')) canvas.getContext('2d').clearRect(0, 0, canvas.width, canvas.height)
        }
      }
      rafId = requestAnimationFrame(tick)
    }
    rafId = requestAnimationFrame(tick)
    return () => cancelAnimationFrame(rafId)
  }, [modelsReady, status, cameraError, validationOptions])

  // Sync overlay canvas size to video
  useEffect(() => {
    const video = videoRef.current
    const canvas = overlayRef.current
    if (!video || !canvas) return
    const sync = () => {
      if (video.videoWidth && video.videoHeight) {
        canvas.width = video.videoWidth
        canvas.height = video.videoHeight
      }
    }
    video.addEventListener('loadedmetadata', sync)
    sync()
    return () => video.removeEventListener('loadedmetadata', sync)
  }, [status])

  // Become "ready" after validation passes for READY_DELAY_MS
  useEffect(() => {
    if (status !== 'live' || cameraError) return
    const t = setInterval(() => {
      const result = lastResultRef.current
      if (!result) return
      const video = videoRef.current
      if (!video?.videoWidth) return
      const validation = validateFaceDetection(result, null, video.videoWidth, video.videoHeight, validationOptions)
      if (validation.valid) {
        setReady(true)
        setStatusMessage('Face detected')
        onReadyChangeRef.current?.(true)
      }
    }, 400)
    return () => clearInterval(t)
  }, [status, cameraError, validationOptions])

  useEffect(() => {
    if (status !== 'live' || cameraError) {
      setReady(false)
      onReadyChangeRef.current?.(false)
    }
  }, [status, cameraError])

  const capture = useCallback(async () => {
    const video = videoRef.current
    const canvas = canvasRef.current
    if (!video || !video.videoWidth || status === 'capturing') return null
    setStatus('capturing')
    setStatusMessage('Capturing…')
    try {
      const result = lastResultRef.current
      if (!result?.descriptor || result.descriptor.length !== 128) {
        setStatus('live')
        setStatusMessage('Position your face inside the frame')
        return null
      }
      const descriptor = result.descriptor.map((n) => Number(n))
      let faceImage
      if (includeFaceImage && canvas) {
        canvas.width = video.videoWidth
        canvas.height = video.videoHeight
        const ctx = canvas.getContext('2d')
        ctx.drawImage(video, 0, 0)
        faceImage = canvas.toDataURL('image/jpeg', 0.85)
      }
      return { descriptor, faceImage }
    } finally {
      setStatus('live')
      setStatusMessage('Position your face inside the frame')
      onReadyChangeRef.current?.(false)
    }
  }, [status, includeFaceImage])

  useLayoutEffect(() => {
    if (captureRef) captureRef.current = { capture }
    return () => { if (captureRef) captureRef.current = null }
  }, [captureRef, capture])

  // Auto-capture when ready
  useEffect(() => {
    if (!ready || autoCaptureDelay <= 0 || !onCapture || externalStatus) return
    autoCaptureTimerRef.current = setTimeout(async () => {
      const fn = captureRef?.current?.capture
      if (fn) {
        const out = await fn()
        if (out?.descriptor?.length === 128) onCapture(out.descriptor, out.faceImage)
      }
    }, autoCaptureDelay)
    return () => {
      if (autoCaptureTimerRef.current) clearTimeout(autoCaptureTimerRef.current)
    }
  }, [ready, autoCaptureDelay, onCapture, externalStatus, captureRef])

  const displayMessage = externalStatus?.message ?? statusMessage
  const displayType = externalStatus?.type ?? (status === 'error' ? 'error' : status === 'capturing' ? 'matching' : null)
  const cardBorderClass = displayType === 'success'
    ? 'border-emerald-500 shadow-emerald-500/20'
    : displayType === 'error'
      ? 'border-red-500 shadow-red-500/20'
      : 'border-white/10'
  const statusTextClass = displayType === 'success'
    ? 'text-emerald-300'
    : displayType === 'error'
      ? 'text-red-300'
      : displayType === 'matching'
        ? 'text-amber-300'
        : 'text-white'

  const isMatching = displayType === 'matching'
  const isSuccess = displayType === 'success'
  const isError = displayType === 'error'

  if (cameraError) {
    return (
      <div className="rounded-2xl border-2 border-red-500/50 bg-muted/50 p-6 text-center text-sm text-destructive shadow-lg">
        {cameraError}
      </div>
    )
  }

  return (
    <div
      className={`camera-card-3d flex flex-col gap-3 rounded-2xl border-2 bg-black/20 p-3 shadow-xl transition-all duration-300 md:p-4 ${cardBorderClass} ${isError ? 'animate-error-shake' : ''}`}
      style={{
        transformStyle: 'preserve-3d',
        perspective: '1000px',
        boxShadow: isSuccess
          ? '0 25px 50px -12px rgba(16, 185, 129, 0.25), 0 0 0 1px rgba(16, 185, 129, 0.1)'
          : isError
            ? '0 25px 50px -12px rgba(239, 68, 68, 0.2), 0 0 0 1px rgba(239, 68, 68, 0.1)'
            : '0 20px 40px -15px rgba(0,0,0,0.35), 0 0 0 1px rgba(255,255,255,0.05)',
      }}
    >
      <div className="flex min-h-9 items-center justify-end gap-2">
        <div className="rounded-lg border border-white/20 bg-black/30 px-3 py-1.5 font-mono text-sm tabular-nums text-white shadow-inner">
          <LiveCameraClock />
        </div>
      </div>

      <div className="relative aspect-video w-full overflow-hidden rounded-xl bg-muted">
        <video
          ref={videoRef}
          autoPlay
          playsInline
          muted
          className="h-full w-full object-cover"
        />
        <canvas
          ref={overlayRef}
          className="absolute left-0 top-0 h-full w-full object-cover pointer-events-none"
          style={{ width: '100%', height: '100%' }}
          aria-hidden
        />
        {!isMatching && !isSuccess && (
          <div
            className="absolute inset-x-0 h-0.5 bg-linear-to-r from-transparent via-cyan-400/80 to-transparent shadow-[0_0_12px_rgba(34,211,238,0.6)]"
            style={{ top: '30%', animation: 'scanLine 2.5s ease-in-out infinite' }}
            aria-hidden
          />
        )}

        {isMatching && (
          <div className="absolute inset-0 flex flex-col items-center justify-center bg-black/50" aria-live="polite">
            <div className="relative size-16">
              <svg className="size-16 -rotate-90" viewBox="0 0 36 36">
                <circle cx="18" cy="18" r="16" fill="none" stroke="rgba(255,255,255,0.1)" strokeWidth="3" />
                <circle cx="18" cy="18" r="16" fill="none" stroke="rgb(251, 191, 36)" strokeWidth="3" strokeLinecap="round" strokeDasharray="100" strokeDashoffset="25" className="animate-matching-progress" />
              </svg>
            </div>
            <p className="mt-3 text-sm font-medium text-amber-200">Matching...</p>
          </div>
        )}

        {isSuccess && (
          <div className="absolute inset-0 flex flex-col items-center justify-center rounded-xl bg-emerald-950/80 backdrop-blur-sm animate-success-fade" aria-live="polite">
            <div className="animate-success-checkmark">
              <svg className="size-20 text-emerald-400" viewBox="0 0 52 52" fill="none">
                <circle cx="26" cy="26" r="24" stroke="currentColor" strokeWidth="2" className="opacity-30" />
                <path d="M14 27l8 8 16-18" stroke="currentColor" strokeWidth="3" strokeLinecap="round" strokeLinejoin="round" pathLength="1" strokeDasharray="1" strokeDashoffset="1" className="animate-success-check-draw" />
              </svg>
            </div>
            <p className="mt-3 text-sm font-semibold text-emerald-200">Attendance Recorded</p>
          </div>
        )}

        <canvas ref={canvasRef} className="hidden" aria-hidden />
      </div>

      <div className={`rounded-lg border border-white/10 bg-black/40 px-3 py-2.5 text-center text-sm font-medium transition-colors ${statusTextClass}`} role="status">
        {displayMessage}
      </div>

      <style>{`
        @keyframes scanLine { 0%, 100% { top: 30%; opacity: 1; } 50% { top: 70%; opacity: 0.7; } }
        @keyframes errorShake { 0%, 100% { transform: translateX(0); } 20% { transform: translateX(-6px); } 40% { transform: translateX(6px); } 60% { transform: translateX(-4px); } 80% { transform: translateX(4px); } }
        .animate-error-shake { animation: errorShake 0.5s ease-in-out; }
        @keyframes matchingProgress { 0% { transform: rotate(0deg); } 100% { transform: rotate(360deg); } }
        .animate-matching-progress { animation: matchingProgress 1.2s linear infinite; }
        @keyframes successFade { from { opacity: 0; } to { opacity: 1; } }
        .animate-success-fade { animation: successFade 0.35s ease-out; }
        @keyframes successCheckDraw { to { stroke-dashoffset: 0; } }
        .animate-success-check-draw { animation: successCheckDraw 0.4s ease-out 0.2s forwards; }
        .camera-card-3d:hover { transform: translateZ(4px); }
      `}</style>
    </div>
  )
}

function LiveCameraClock() {
  const [now, setNow] = useState(() => new Date())
  useEffect(() => {
    const t = setInterval(() => setNow(new Date()), 1000)
    return () => clearInterval(t)
  }, [])
  return <span aria-live="polite">{now.toLocaleTimeString(undefined, { hour: '2-digit', minute: '2-digit', second: '2-digit', hour12: false })}</span>
}
