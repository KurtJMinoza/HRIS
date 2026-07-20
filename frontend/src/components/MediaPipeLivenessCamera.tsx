import { useCallback, useEffect, useRef, useState } from 'react'
import { Camera, CheckCircle2, Loader2, RefreshCw, X } from 'lucide-react'
import { FaceLandmarker, FilesetResolver } from '@mediapipe/tasks-vision'
import { Button } from '@/components/ui/button'
import { cn } from '@/lib/utils'

export const MEDIAPIPE_LIVENESS_STATE = {
  INITIALIZING: 'INITIALIZING',
  NO_FACE: 'NO_FACE',
  MULTIPLE_FACES: 'MULTIPLE_FACES',
  CENTER_FACE: 'CENTER_FACE',
  MOVE_CLOSER: 'MOVE_CLOSER',
  MOVE_BACK: 'MOVE_BACK',
  HOLD_STILL: 'HOLD_STILL',
  VERIFYING: 'VERIFYING',
  PASSED: 'PASSED',
  FAILED: 'FAILED',
}

const RunningMode = { VIDEO: 'VIDEO' }
const FACE_MODEL_URL =
  'https://storage.googleapis.com/mediapipe-models/face_landmarker/face_landmarker/float16/latest/face_landmarker.task'
const WASM_BASE_URL = 'https://cdn.jsdelivr.net/npm/@mediapipe/tasks-vision@0.10.35/wasm'

const HORIZONTAL_TOLERANCE = 0.12
const VERTICAL_TOLERANCE = 0.15
const MIN_FACE_WIDTH = 0.24
const MAX_FACE_WIDTH = 0.58
const MIN_FACE_HEIGHT = 0.30
const MAX_FACE_HEIGHT = 0.72
const ANALYSIS_INTERVAL_MS = 80
const REQUIRED_STABLE_MS = 1100
const REQUIRED_STABLE_FRAMES = 8
const MAX_CENTER_SHIFT = 0.04
const MAX_SIZE_SHIFT = 0.075
const MIN_BRIGHTNESS = 42
const MIN_BLUR_SCORE = 7
const MIN_FRAME_VARIATION = 0.18

const STATE_MESSAGE = {
  [MEDIAPIPE_LIVENESS_STATE.INITIALIZING]: 'Preparing camera...',
  [MEDIAPIPE_LIVENESS_STATE.NO_FACE]: 'No face detected',
  [MEDIAPIPE_LIVENESS_STATE.MULTIPLE_FACES]: 'Multiple faces detected',
  [MEDIAPIPE_LIVENESS_STATE.CENTER_FACE]: 'Center your face',
  [MEDIAPIPE_LIVENESS_STATE.MOVE_CLOSER]: 'Move forward',
  [MEDIAPIPE_LIVENESS_STATE.MOVE_BACK]: 'Move back',
  [MEDIAPIPE_LIVENESS_STATE.HOLD_STILL]: 'Hold still',
  [MEDIAPIPE_LIVENESS_STATE.VERIFYING]: 'Verifying...',
  [MEDIAPIPE_LIVENESS_STATE.PASSED]: 'Face verified',
  [MEDIAPIPE_LIVENESS_STATE.FAILED]: 'Face verification could not be completed.',
}

function clamp01(value) {
  return Math.min(1, Math.max(0, Number(value) || 0))
}

function getFaceBox(landmarks) {
  let minX = 1
  let minY = 1
  let maxX = 0
  let maxY = 0
  for (const point of landmarks || []) {
    const x = clamp01(point?.x)
    const y = clamp01(point?.y)
    minX = Math.min(minX, x)
    minY = Math.min(minY, y)
    maxX = Math.max(maxX, x)
    maxY = Math.max(maxY, y)
  }
  const width = Math.max(0, maxX - minX)
  const height = Math.max(0, maxY - minY)
  return {
    minX,
    minY,
    maxX,
    maxY,
    width,
    height,
    centerX: minX + width / 2,
    centerY: minY + height / 2,
  }
}

function isCentered(box) {
  return (
    Math.abs(box.centerX - 0.5) <= HORIZONTAL_TOLERANCE &&
    Math.abs(box.centerY - 0.5) <= VERTICAL_TOLERANCE
  )
}

function isSizeAcceptable(box) {
  return (
    box.width >= MIN_FACE_WIDTH &&
    box.width <= MAX_FACE_WIDTH &&
    box.height >= MIN_FACE_HEIGHT &&
    box.height <= MAX_FACE_HEIGHT
  )
}

function drawSample(video, canvas, width = 80, height = 60) {
  if (!video || !canvas || !video.videoWidth || !video.videoHeight) return null
  canvas.width = width
  canvas.height = height
  const ctx = canvas.getContext('2d', { willReadFrequently: true })
  if (!ctx) return null
  ctx.drawImage(video, 0, 0, width, height)
  return ctx.getImageData(0, 0, width, height)
}

function analyzeImageQuality(video, canvas, previousFrameRef) {
  const image = drawSample(video, canvas, 96, 72)
  if (!image) return { brightness: 0, blur: 0, variation: 0 }

  const { data, width, height } = image
  const gray = new Float32Array(width * height)
  let brightnessTotal = 0
  for (let i = 0, p = 0; i < data.length; i += 4, p += 1) {
    const y = data[i] * 0.299 + data[i + 1] * 0.587 + data[i + 2] * 0.114
    gray[p] = y
    brightnessTotal += y
  }

  let laplacianTotal = 0
  let laplacianSqTotal = 0
  let laplacianCount = 0
  for (let y = 1; y < height - 1; y += 1) {
    for (let x = 1; x < width - 1; x += 1) {
      const idx = y * width + x
      const laplacian =
        gray[idx - width] + gray[idx + width] + gray[idx - 1] + gray[idx + 1] - gray[idx] * 4
      laplacianTotal += laplacian
      laplacianSqTotal += laplacian * laplacian
      laplacianCount += 1
    }
  }
  const laplacianMean = laplacianCount ? laplacianTotal / laplacianCount : 0
  const blur = laplacianCount ? laplacianSqTotal / laplacianCount - laplacianMean * laplacianMean : 0

  let variation = 0
  const previous = previousFrameRef.current
  if (previous && previous.length === gray.length) {
    let diff = 0
    for (let i = 0; i < gray.length; i += 6) {
      diff += Math.abs(gray[i] - previous[i])
    }
    variation = diff / Math.ceil(gray.length / 6)
  }
  previousFrameRef.current = gray

  return {
    brightness: brightnessTotal / gray.length,
    blur,
    variation,
  }
}

function captureVideoFrame(video, canvas) {
  if (!video || !canvas || !video.videoWidth || !video.videoHeight) return null
  canvas.width = video.videoWidth
  canvas.height = video.videoHeight
  const ctx = canvas.getContext('2d', { alpha: false })
  if (!ctx) return null
  ctx.drawImage(video, 0, 0, canvas.width, canvas.height)
  const dataUrl = canvas.toDataURL('image/jpeg', 0.92)
  const base64 = dataUrl.split(',')[1] || ''
  return base64.length > 100 ? base64 : null
}

function stopStream(stream) {
  stream?.getTracks?.().forEach((track) => track.stop())
}

export function MediaPipeLivenessCamera({
  onPassed,
  onCancel,
  disabled = false,
  showCancel = false,
  className = '',
  surface = 'light',
}) {
  const videoRef = useRef(null)
  const analysisCanvasRef = useRef(null)
  const captureCanvasRef = useRef(null)
  const landmarkerRef = useRef(null)
  const streamRef = useRef(null)
  const rafRef = useRef(0)
  const mountedRef = useRef(false)
  const stoppedRef = useRef(false)
  const verifyingRef = useRef(false)
  const stateRef = useRef(MEDIAPIPE_LIVENESS_STATE.INITIALIZING)
  const stableStartedAtRef = useRef(0)
  const stableSamplesRef = useRef([])
  const previousObservationRef = useRef(null)
  const previousFrameRef = useRef(null)
  const progressFillRef = useRef(null)
  const progressValueRef = useRef(0)
  const lastAnalysisAtRef = useRef(0)
  const lastVideoTimeRef = useRef(-1)
  const [machineState, setMachineState] = useState(MEDIAPIPE_LIVENESS_STATE.INITIALIZING)
  const [failureMessage, setFailureMessage] = useState('')
  const [retryKey, setRetryKey] = useState(0)

  const lightSurface = surface === 'light'
  const ready =
    machineState === MEDIAPIPE_LIVENESS_STATE.HOLD_STILL ||
    machineState === MEDIAPIPE_LIVENESS_STATE.VERIFYING ||
    machineState === MEDIAPIPE_LIVENESS_STATE.PASSED
  const tooFar = machineState === MEDIAPIPE_LIVENESS_STATE.MOVE_CLOSER
  const tooClose = machineState === MEDIAPIPE_LIVENESS_STATE.MOVE_BACK
  const showActivity =
    machineState === MEDIAPIPE_LIVENESS_STATE.HOLD_STILL ||
    machineState === MEDIAPIPE_LIVENESS_STATE.VERIFYING

  const setStateSafely = useCallback((next, message = '') => {
    if (!mountedRef.current || stateRef.current === next) {
      if (message) setFailureMessage(message)
      return
    }
    stateRef.current = next
    setMachineState(next)
    if (message) setFailureMessage(message)
  }, [])

  const setProgressValue = useCallback((value) => {
    const next = Math.min(1, Math.max(0, Number(value) || 0))
    progressValueRef.current = next
    if (progressFillRef.current) {
      progressFillRef.current.style.setProperty('--hold-progress', `${next * 360}deg`)
      progressFillRef.current.style.opacity = next > 0 ? '1' : '0'
    }
  }, [])

  const resetStability = useCallback(() => {
    stableStartedAtRef.current = 0
    stableSamplesRef.current = []
    previousObservationRef.current = null
    setProgressValue(0)
  }, [setProgressValue])

  const cleanup = useCallback(() => {
    stoppedRef.current = true
    if (rafRef.current) cancelAnimationFrame(rafRef.current)
    rafRef.current = 0
    stopStream(streamRef.current)
    streamRef.current = null
    const video = videoRef.current
    if (video) {
      video.pause?.()
      video.srcObject = null
    }
    landmarkerRef.current?.close?.()
    landmarkerRef.current = null
    const analysis = analysisCanvasRef.current
    const capture = captureCanvasRef.current
    analysis?.getContext?.('2d')?.clearRect(0, 0, analysis.width, analysis.height)
    capture?.getContext?.('2d')?.clearRect(0, 0, capture.width, capture.height)
  }, [])

  const fail = useCallback((message) => {
    verifyingRef.current = false
    resetStability()
    setStateSafely(MEDIAPIPE_LIVENESS_STATE.FAILED, message)
  }, [resetStability, setStateSafely])

  const finishVerification = useCallback((observation) => {
    if (verifyingRef.current || stoppedRef.current || disabled) return
    verifyingRef.current = true
    setStateSafely(MEDIAPIPE_LIVENESS_STATE.VERIFYING)
    setProgressValue(1)

    window.setTimeout(() => {
      if (!mountedRef.current || stoppedRef.current) return
      const video = videoRef.current
      const captureCanvas = captureCanvasRef.current
      const samples = stableSamplesRef.current
      const variationAverage =
        samples.length > 1
          ? samples.reduce((total, sample) => total + Number(sample.variation || 0), 0) / samples.length
          : 0

      if (!observation?.singleFace || !observation.centered || !observation.sizeValid) {
        fail('Please try again')
        return
      }
      if (observation.brightness < MIN_BRIGHTNESS) {
        fail('Image is too dark')
        return
      }
      if (observation.blur < MIN_BLUR_SCORE) {
        fail('Image is too blurry')
        return
      }
      if (samples.length < REQUIRED_STABLE_FRAMES || !stableStartedAtRef.current) {
        fail('Please try again')
        return
      }
      if (video?.currentTime === lastVideoTimeRef.current && variationAverage < MIN_FRAME_VARIATION) {
        fail('Please try again')
        return
      }
      if (variationAverage < MIN_FRAME_VARIATION) {
        fail('Please try again')
        return
      }

      const imageBase64 = captureVideoFrame(video, captureCanvas)
      if (!imageBase64) {
        fail('Please try again')
        return
      }

      setStateSafely(MEDIAPIPE_LIVENESS_STATE.PASSED)
      onPassed?.({
        imageBase64,
        capturedAtMs: Date.now(),
        cameraInfo: `${video.videoWidth}x${video.videoHeight}`,
        metadata: {
          provider: 'mediapipe',
          centered: true,
          face_size_valid: true,
          stable_duration_ms: Math.round(performance.now() - stableStartedAtRef.current),
          brightness: Math.round(observation.brightness),
          blur: Math.round(observation.blur),
        },
      })
      cleanup()
    }, 260)
  }, [cleanup, disabled, fail, onPassed, setProgressValue, setStateSafely])

  const processFrame = useCallback((timestamp) => {
    if (stoppedRef.current || disabled) return
    rafRef.current = requestAnimationFrame(processFrame)

    const video = videoRef.current
    const landmarker = landmarkerRef.current
    if (!video || !landmarker || video.readyState < 2 || !video.videoWidth || !video.videoHeight) return
    if (timestamp - lastAnalysisAtRef.current < ANALYSIS_INTERVAL_MS) return
    lastAnalysisAtRef.current = timestamp

    let result
    try {
      result = landmarker.detectForVideo(video, timestamp)
    } catch {
      fail('Please try again')
      return
    }

    const faces = result?.faceLandmarks || []
    if (faces.length === 0) {
      resetStability()
      setStateSafely(MEDIAPIPE_LIVENESS_STATE.NO_FACE)
      return
    }
    if (faces.length > 1) {
      resetStability()
      setStateSafely(MEDIAPIPE_LIVENESS_STATE.MULTIPLE_FACES)
      return
    }

    const box = getFaceBox(faces[0])
    const centered = isCentered(box)
    const sizeValid = isSizeAcceptable(box)
    const quality = analyzeImageQuality(video, analysisCanvasRef.current, previousFrameRef)
    const videoTimeAdvanced = video.currentTime !== lastVideoTimeRef.current
    lastVideoTimeRef.current = video.currentTime

    if (box.width > MAX_FACE_WIDTH || box.height > MAX_FACE_HEIGHT) {
      resetStability()
      setStateSafely(MEDIAPIPE_LIVENESS_STATE.MOVE_BACK)
      return
    }
    if (!centered) {
      resetStability()
      setStateSafely(MEDIAPIPE_LIVENESS_STATE.CENTER_FACE)
      return
    }
    if (box.width < MIN_FACE_WIDTH || box.height < MIN_FACE_HEIGHT) {
      resetStability()
      setStateSafely(MEDIAPIPE_LIVENESS_STATE.MOVE_CLOSER)
      return
    }
    if (!sizeValid) {
      resetStability()
      setStateSafely(MEDIAPIPE_LIVENESS_STATE.CENTER_FACE)
      return
    }

    const previous = previousObservationRef.current
    const abruptShift = previous
      ? Math.abs(box.centerX - previous.centerX) > MAX_CENTER_SHIFT ||
        Math.abs(box.centerY - previous.centerY) > MAX_CENTER_SHIFT ||
        Math.abs(box.width - previous.width) > MAX_SIZE_SHIFT ||
        Math.abs(box.height - previous.height) > MAX_SIZE_SHIFT
      : false

    const observation = {
      singleFace: true,
      centered,
      sizeValid,
      centerX: box.centerX,
      centerY: box.centerY,
      width: box.width,
      height: box.height,
      brightness: quality.brightness,
      blur: quality.blur,
      variation: quality.variation,
      videoTimeAdvanced,
    }

    if (abruptShift) {
      stableStartedAtRef.current = timestamp
      stableSamplesRef.current = [observation]
      previousObservationRef.current = observation
      setProgressValue(0.1)
      setStateSafely(MEDIAPIPE_LIVENESS_STATE.HOLD_STILL)
      return
    }

    if (!stableStartedAtRef.current) stableStartedAtRef.current = timestamp
    previousObservationRef.current = observation
    stableSamplesRef.current = [...stableSamplesRef.current.slice(-18), observation]

    const stableDuration = timestamp - stableStartedAtRef.current
    setProgressValue(Math.min(0.98, stableDuration / REQUIRED_STABLE_MS))
    setStateSafely(MEDIAPIPE_LIVENESS_STATE.HOLD_STILL)

    if (stableDuration >= REQUIRED_STABLE_MS && stableSamplesRef.current.length >= REQUIRED_STABLE_FRAMES) {
      finishVerification(observation)
    }
  }, [disabled, fail, finishVerification, resetStability, setProgressValue, setStateSafely])

  useEffect(() => {
    mountedRef.current = true
    stoppedRef.current = false
    verifyingRef.current = false
    stateRef.current = MEDIAPIPE_LIVENESS_STATE.INITIALIZING
    setMachineState(MEDIAPIPE_LIVENESS_STATE.INITIALIZING)
    setFailureMessage('')
    resetStability()
    setProgressValue(0)
    previousFrameRef.current = null
    lastVideoTimeRef.current = -1

    let cancelled = false

    async function initialize() {
      try {
        if (!navigator.mediaDevices?.getUserMedia) {
          fail('Camera unavailable')
          return
        }

        const stream = await navigator.mediaDevices.getUserMedia({
          audio: false,
          video: {
            facingMode: 'user',
            width: { ideal: 1280 },
            height: { ideal: 720 },
          },
        })
        if (cancelled) {
          stopStream(stream)
          return
        }
        streamRef.current = stream
        const video = videoRef.current
        if (!video) throw new Error('Camera unavailable')
        video.srcObject = stream
        video.muted = true
        video.playsInline = true
        await video.play()

        const vision = await FilesetResolver.forVisionTasks(WASM_BASE_URL)
        if (cancelled) return
        landmarkerRef.current = await FaceLandmarker.createFromOptions(vision, {
          baseOptions: {
            modelAssetPath: FACE_MODEL_URL,
            delegate: 'GPU',
          },
          runningMode: RunningMode.VIDEO,
          numFaces: 1,
          minFaceDetectionConfidence: 0.7,
          minFacePresenceConfidence: 0.7,
          minTrackingConfidence: 0.7,
          outputFaceBlendshapes: true,
          outputFacialTransformationMatrixes: true,
        })
        if (cancelled) return
        setStateSafely(MEDIAPIPE_LIVENESS_STATE.CENTER_FACE)
        rafRef.current = requestAnimationFrame(processFrame)
      } catch (error) {
        const name = String(error?.name || '')
        const message = /notallowed|permission/i.test(name) ? 'Camera permission denied' : 'Camera unavailable'
        fail(message)
      }
    }

    initialize()

    return () => {
      cancelled = true
      mountedRef.current = false
      cleanup()
    }
  }, [cleanup, fail, processFrame, resetStability, retryKey, setProgressValue, setStateSafely])

  const message = failureMessage || STATE_MESSAGE[machineState] || 'Center your face'
  const accentColor = ready ? '#16a34a' : '#2563eb'
  const statusTone =
    machineState === MEDIAPIPE_LIVENESS_STATE.PASSED
      ? 'border-emerald-200 bg-emerald-50 text-emerald-700'
      : machineState === MEDIAPIPE_LIVENESS_STATE.FAILED ||
          machineState === MEDIAPIPE_LIVENESS_STATE.MULTIPLE_FACES ||
          machineState === MEDIAPIPE_LIVENESS_STATE.NO_FACE
        ? 'border-red-200 bg-red-50 text-red-700'
        : 'border-blue-200 bg-blue-50 text-blue-700'

  const handleRetry = () => {
    if (disabled) return
    cleanup()
    setRetryKey((key) => key + 1)
  }

  const handleCancel = () => {
    cleanup()
    onCancel?.()
  }

  return (
    <div className={cn('w-full min-w-0 overflow-hidden', className)}>
      <div
        className={cn(
          'overflow-hidden rounded-[20px] border bg-white shadow-[0_18px_45px_-34px_rgba(15,23,42,0.55)] max-[760px]:rounded-xl',
          lightSurface ? 'border-slate-200 text-slate-950' : 'border-white/10 text-white'
        )}
      >
        <div className="border-b border-slate-200 bg-white px-5 py-4 text-slate-900 max-[760px]:px-3 max-[760px]:py-3 max-[360px]:px-2.5 [@media(max-width:760px)_and_(orientation:landscape)]:py-2">
          <div className="flex min-w-0 items-center gap-3 max-[360px]:gap-2">
            <span className="flex size-10 shrink-0 items-center justify-center rounded-xl bg-blue-50 text-blue-600 ring-1 ring-blue-200 max-[360px]:size-9">
              <Camera className="size-5 max-[360px]:size-4" aria-hidden />
            </span>
            <h3 className="min-w-0 truncate text-lg font-bold tracking-normal text-slate-950 max-[360px]:text-base">Attendance Verification</h3>
          </div>
        </div>

        <div className="space-y-4 bg-slate-50 p-4 sm:p-5 max-[760px]:space-y-2 max-[760px]:p-2 max-[360px]:p-1.5 [@media(max-width:760px)_and_(orientation:landscape)]:space-y-1.5">
          <div className="relative aspect-[4/3] overflow-hidden rounded-[18px] border border-slate-200 bg-slate-950 shadow-inner max-[760px]:aspect-auto max-[760px]:h-[clamp(12rem,min(112vw,58dvh),30rem)] max-[760px]:rounded-xl [@media(max-width:760px)_and_(orientation:landscape)]:h-[clamp(10rem,42dvh,18rem)]">
            <video
              ref={videoRef}
              className="h-full w-full scale-x-[-1] object-cover"
              autoPlay
              muted
              playsInline
            />
            <div
              className="pointer-events-none absolute inset-0 bg-slate-950/35"
              style={{
                WebkitMaskImage: 'radial-gradient(ellipse 34% 43% at 50% 48%, transparent 0 98%, #000 100%)',
                maskImage: 'radial-gradient(ellipse 34% 43% at 50% 48%, transparent 0 98%, #000 100%)',
              }}
              aria-hidden
            />
            <div
              className={cn(
                'pointer-events-none absolute left-1/2 top-[48%] h-[72%] w-[56%] -translate-x-1/2 -translate-y-1/2 rounded-[50%] border-2 transition-colors',
                ready ? 'border-emerald-400/95' : 'border-blue-500/95'
              )}
              style={{ boxShadow: `0 0 0 1px ${accentColor}33, 0 0 34px ${accentColor}22` }}
              aria-hidden
            />
            <div
              ref={progressFillRef}
              className="pointer-events-none absolute left-1/2 top-[48%] h-[76%] w-[60%] -translate-x-1/2 -translate-y-1/2 rounded-[50%] opacity-0 transition-opacity duration-100"
              style={{
                '--hold-progress': `${progressValueRef.current * 360}deg`,
                background:
                  'conic-gradient(from -90deg, rgba(34,197,94,0.95) var(--hold-progress), rgba(34,197,94,0.12) 0deg)',
                WebkitMaskImage: 'radial-gradient(ellipse at center, transparent 0 67%, #000 68% 100%)',
                maskImage: 'radial-gradient(ellipse at center, transparent 0 67%, #000 68% 100%)',
              }}
              aria-hidden
            />
            <div className="pointer-events-none absolute left-1/2 top-[48%] h-[72%] w-[56%] -translate-x-1/2 -translate-y-1/2 rounded-[50%]" aria-hidden>
              <span className="absolute left-1/2 top-0 h-3 w-px -translate-x-1/2 rounded-full bg-white/70" />
              <span className="absolute bottom-0 left-1/2 h-3 w-px -translate-x-1/2 rounded-full bg-white/70" />
              <span className="absolute left-0 top-1/2 h-px w-3 -translate-y-1/2 rounded-full bg-white/70" />
              <span className="absolute right-0 top-1/2 h-px w-3 -translate-y-1/2 rounded-full bg-white/70" />
            </div>
            {(tooFar || tooClose) ? (
              <div
                className={cn(
                  'pointer-events-none absolute left-1/2 top-4 max-w-[calc(100%-1rem)] -translate-x-1/2 whitespace-nowrap rounded-full border px-4 py-1.5 text-sm font-extrabold shadow-[0_10px_30px_rgba(0,0,0,0.28)] backdrop-blur-md max-[760px]:top-3 max-[360px]:top-2 max-[360px]:px-3 max-[360px]:py-1 max-[360px]:text-xs',
                  tooClose
                    ? 'border-orange-200/80 bg-orange-500/90 text-white shadow-orange-500/25'
                    : 'border-blue-200/80 bg-blue-600/90 text-white shadow-blue-500/25'
                )}
              >
                {tooClose ? 'Move back' : 'Move forward'}
              </div>
            ) : null}
            {(machineState === MEDIAPIPE_LIVENESS_STATE.INITIALIZING || disabled) && (
              <div className="absolute inset-0 flex items-center justify-center bg-slate-950/50 text-white">
                <Loader2 className="size-8 animate-spin text-blue-300" aria-hidden />
              </div>
            )}
          </div>

          <div className="flex justify-center">
            <p
              className={cn(
                'inline-flex min-h-10 max-w-full items-center justify-center rounded-full border px-5 text-center text-lg font-extrabold leading-tight tracking-normal shadow-sm max-[760px]:min-h-9 max-[760px]:px-4 max-[760px]:py-1.5 max-[360px]:px-3 max-[360px]:text-base [@media(max-width:760px)_and_(orientation:landscape)]:min-h-8 [@media(max-width:760px)_and_(orientation:landscape)]:py-1 [@media(max-width:760px)_and_(orientation:landscape)]:text-base',
                statusTone
              )}
              aria-live="polite"
            >
              {showActivity ? (
                <Loader2 className={cn('mr-2 size-4 animate-spin', ready ? 'text-emerald-600' : 'text-blue-600')} aria-hidden />
              ) : null}
              {message}
            </p>
          </div>
          <div className="text-center">
            {machineState === MEDIAPIPE_LIVENESS_STATE.FAILED ? (
              <p className="text-sm text-slate-500">Please try again.</p>
            ) : null}
          </div>

          {machineState === MEDIAPIPE_LIVENESS_STATE.PASSED ? (
            <div className="flex items-center justify-center gap-2 rounded-xl border border-emerald-200 bg-emerald-50 px-3 py-2 text-center text-sm font-semibold text-emerald-700">
              <CheckCircle2 className="size-4" aria-hidden />
              Face verified
            </div>
          ) : null}

          {machineState === MEDIAPIPE_LIVENESS_STATE.FAILED ? (
            <div className="grid gap-2 min-[380px]:grid-cols-2">
              <Button type="button" onClick={handleRetry} disabled={disabled} className="bg-blue-600 text-white hover:bg-blue-700">
                <RefreshCw className="size-4" aria-hidden />
                Try Again
              </Button>
              <Button type="button" variant="outline" onClick={handleCancel} disabled={disabled}>
                <X className="size-4" aria-hidden />
                Cancel
              </Button>
            </div>
          ) : showCancel ? (
            <Button type="button" variant="outline" onClick={handleCancel} disabled={disabled} className="w-full">
              Cancel
            </Button>
          ) : null}
        </div>
      </div>

      <canvas ref={analysisCanvasRef} className="hidden" aria-hidden />
      <canvas ref={captureCanvasRef} className="hidden" aria-hidden />
    </div>
  )
}

export default MediaPipeLivenessCamera
