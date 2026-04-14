import { useState, useEffect, useRef } from 'react'

// MediaPipe Face Mesh landmark indices (468 points)
const LEFT_EYE = [33, 160, 158, 133, 153, 144]
const RIGHT_EYE = [362, 385, 387, 263, 373, 380]
const NOSE_TIP = 1

/** Eye Aspect Ratio (EAR) — same logic as face-api.js blink detection. EAR < threshold → blink. */
function eyeAspectRatio(landmarks, indices) {
  const p = (i) => landmarks[indices[i]] || { x: 0, y: 0 }
  const dist = (a, b) => Math.hypot(a.x - b.x, a.y - b.y)
  const v1 = dist(p(1), p(5))
  const v2 = dist(p(2), p(4))
  const h = dist(p(0), p(3))
  if (h < 1e-6) return 0
  return (v1 + v2) / (2 * h)
}

function faceCenterFromLandmarks(landmarks) {
  const left = landmarks[33] || landmarks[LEFT_EYE[0]]
  const right = landmarks[263] || landmarks[RIGHT_EYE[0]]
  const nose = landmarks[NOSE_TIP]
  if (!left || !right || !nose) return { x: 0.5, y: 0.5 }
  return {
    x: (left.x + right.x + nose.x) / 3,
    y: (left.y + right.y + nose.y) / 3,
  }
}

function eyeDistance(landmarks) {
  const left = landmarks[33] || { x: 0, y: 0 }
  const right = landmarks[263] || { x: 0, y: 0 }
  return Math.hypot(right.x - left.x, right.y - left.y)
}

// Face alignment — wider window so face stays detectable during subtle turns
const CENTER_X_MIN = 0.35
const CENTER_X_MAX = 0.65
const CENTER_Y_MIN = 0.35
const CENTER_Y_MAX = 0.65

// Yaw: nose vs face center. Smaller threshold = subtle turn registers (face stays readable).
const YAW_ANGLE_THRESHOLD = 0.028

// Yaw: 2 frames = ~400ms hold (less time with face at angle = better tracking)
const YAW_CONFIRM_FRAMES = 2

// Blink: EAR below threshold = eyes closed. 1 frame = one blink (catches fast ~200ms blinks).
const BLINK_EAR_THRESHOLD = 0.2
const BLINK_CONFIRM_FRAMES = 1

// Head turn: smaller offset = subtle turn (nose.x 0.44–0.56) keeps face in frame for recognition
const FRAME_CENTER_X = 0.5
const HEAD_TURN_OFFSET = 0.06

// Face size: if eye distance < this, "Move closer" (slightly relaxed for varied distances)
const MIN_EYE_DISTANCE = 0.1

// Brightness: sample every N frames. Below = too dark, above = backlight
const BRIGHTNESS_CHECK_INTERVAL = 15
const BRIGHTNESS_THRESHOLD = 40
const BRIGHTNESS_MAX = 200

// Detection: requestAnimationFrame loop, throttled to ~12 FPS for smoother UX and stable CPU usage.
const DETECTION_INTERVAL_MS = 85
const PROCESS_EVERY_NTH_FRAME = 2

// State stabilization: 3 frames ≈ 600ms (faster feedback, face stays detectable)
const STABILITY_FRAMES = 3

function sampleBrightness(video) {
  if (!video?.videoWidth || !video?.videoHeight) return null
  try {
    const canvas = document.createElement('canvas')
    canvas.width = 32
    canvas.height = 32
    const ctx = canvas.getContext('2d')
    if (!ctx) return null
    ctx.drawImage(
      video,
      Math.max(0, video.videoWidth / 2 - 16),
      Math.max(0, video.videoHeight / 2 - 16),
      32,
      32,
      0,
      0,
      32,
      32
    )
    const data = ctx.getImageData(0, 0, 32, 32).data
    let sum = 0
    for (let i = 0; i < data.length; i += 4) {
      sum += (data[i] + data[i + 1] + data[i + 2]) / 3
    }
    return sum / (data.length / 4)
  } catch {
    return null
  }
}

/** Fixed step order — Align → Blink → Left → Right → Anti-spoof → Success */
export const STEPS = [
  { id: 'center', label: 'Align face' },
  { id: 'blink', label: 'Blink twice' },
  { id: 'left', label: 'Turn head left' },
  { id: 'right', label: 'Turn head right' },
  { id: 'antispoof', label: 'Anti-spoof verification' },
]

export function useBlinkLiveness(webcamRef, enabled, videoReady = true, skipLivenessSteps = false) {
  const [steps] = useState(STEPS)
  const [stepIndex, setStepIndex] = useState(0)
  const [faceAligned, setFaceAligned] = useState(false)
  const [faceDetected, setFaceDetected] = useState(false)
  const [liveFeedback, setLiveFeedback] = useState('Detecting face...')
  const [livenessPassed, setLivenessPassed] = useState(false)
  const [error, setError] = useState(null)
  const [antiSpoofWarnings, setAntiSpoofWarnings] = useState([])
  const [tooBright, setTooBright] = useState(false)
  const [blinkCount, setBlinkCount] = useState(0)

  const faceMeshRef = useRef(null)
  const rafRef = useRef(null)
  const earHistoryRef = useRef([])
  const consecutiveLowEarRef = useRef(0)
  const blinkCountRef = useRef(0)
  const lastBlinkFrameRef = useRef(0)
  const yawLeftDoneRef = useRef(false)
  const yawRightDoneRef = useRef(false)
  const yawLeftConsecutiveRef = useRef(0)
  const yawRightConsecutiveRef = useRef(0)
  const leftTurnSignRef = useRef(null)
  const stepIndexRef = useRef(0)
  const frameCountRef = useRef(0)
  const tooDarkRef = useRef(false)
  const tooBrightRef = useRef(false)
  const lastDetectionTimeRef = useRef(0)
  const consecutiveFaceDetectedRef = useRef(0)
  const consecutiveNoFaceRef = useRef(0)
  const consecutiveCenterAlignedRef = useRef(0)
  const antispoofConsecutiveRef = useRef(0)
  const stepsRef = useRef(steps)
  const rafFrameCounterRef = useRef(0)

  const currentStep = steps[stepIndex]
  const progress = livenessPassed ? 1 : steps.length > 0 ? stepIndex / steps.length : 0
  stepsRef.current = steps

  useEffect(() => {
    if (!enabled) {
      setStepIndex(0)
      setFaceAligned(false)
      setFaceDetected(false)
      setLiveFeedback('No face detected')
      setLivenessPassed(false)
      setAntiSpoofWarnings([])
      setTooBright(false)
      setBlinkCount(0)
      stepIndexRef.current = 0
      blinkCountRef.current = 0
      consecutiveLowEarRef.current = 0
      yawLeftDoneRef.current = false
      yawRightDoneRef.current = false
      yawLeftConsecutiveRef.current = 0
      yawRightConsecutiveRef.current = 0
      leftTurnSignRef.current = null
      earHistoryRef.current = []
      frameCountRef.current = 0
      consecutiveFaceDetectedRef.current = 0
      consecutiveNoFaceRef.current = 0
      consecutiveCenterAlignedRef.current = 0
      antispoofConsecutiveRef.current = 0
      return
    }
    stepsRef.current = STEPS

    const video = webcamRef?.current?.video ?? webcamRef?.current
    if (!video || typeof video.addEventListener !== 'function') return

    let cancelled = false

    async function init() {
      if (cancelled) return
      try {
        const mod = await import('@mediapipe/face_mesh')
        const FaceMesh = mod.FaceMesh ?? mod.default
        const faceMesh = new FaceMesh({
          locateFile: (file) =>
            `https://cdn.jsdelivr.net/npm/@mediapipe/face_mesh/${file}`,
        })
        faceMesh.setOptions({
          maxNumFaces: 1,
          refineLandmarks: true,
          minDetectionConfidence: 0.5,
          minTrackingConfidence: 0.5,
        })

        faceMeshRef.current = faceMesh

        faceMesh.onResults((results) => {
          if (cancelled) return
          frameCountRef.current += 1

          if (frameCountRef.current % BRIGHTNESS_CHECK_INTERVAL === 0) {
            const brightness = sampleBrightness(video)
            tooDarkRef.current = brightness !== null && brightness < BRIGHTNESS_THRESHOLD
            const bright = brightness !== null && brightness > BRIGHTNESS_MAX
            if (tooBrightRef.current !== bright) {
              tooBrightRef.current = bright
              setTooBright(bright)
            }
          }

          if (!results.multiFaceLandmarks?.length) {
            consecutiveNoFaceRef.current += 1
            consecutiveFaceDetectedRef.current = 0
            consecutiveCenterAlignedRef.current = 0
            if (consecutiveNoFaceRef.current >= STABILITY_FRAMES) {
              setFaceAligned(false)
              setFaceDetected(false)
              setLiveFeedback(tooBrightRef.current ? 'Reduce backlight' : 'No face detected')
              setAntiSpoofWarnings([])
            }
            earHistoryRef.current = []
            consecutiveLowEarRef.current = 0
            return
          }

          consecutiveFaceDetectedRef.current += 1
          consecutiveNoFaceRef.current = 0
          const faceDetectedStable = consecutiveFaceDetectedRef.current >= STABILITY_FRAMES
          if (faceDetectedStable) setFaceDetected(true)

          // Anti-spoof: multiple faces in frame
          const faceCount = results.multiFaceLandmarks.length
          if (faceCount > 1) {
            setAntiSpoofWarnings((prev) => (prev.includes('multiple_faces') ? prev : [...prev, 'multiple_faces']))
            setLiveFeedback('Possible spoof attempt')
            // Skip other feedback when spoof suspected
            earHistoryRef.current = []
            consecutiveLowEarRef.current = 0
            return
          } else {
            setAntiSpoofWarnings((prev) => prev.filter((w) => w !== 'multiple_faces'))
          }

          // DTR login: skip active liveness — only require face detected (passive anti-spoof on backend)
          if (skipLivenessSteps) {
            const ready = faceDetectedStable && faceCount === 1
            setFaceAligned(ready)
            setLivenessPassed(ready)
            setLiveFeedback(ready ? 'Face detected' : faceCount > 1 ? 'Possible spoof attempt' : 'No face detected')
            return
          }
          const landmarks = results.multiFaceLandmarks[0]
          const noseX = landmarks[NOSE_TIP]?.x ?? 0.5
          const noseY = landmarks[NOSE_TIP]?.y ?? 0.5

          const stepsList = stepsRef.current
          const stepIdx = stepIndexRef.current
          const currentStepId = stepsList[stepIdx]?.id ?? 'center'

          // Face center for yaw and head-turn feedback
          const center = faceCenterFromLandmarks(landmarks)
          const yawOffset = noseX - center.x
          const absYaw = Math.abs(yawOffset)
          const yawSign = yawOffset > 0 ? 1 : -1

          // Head turn: nose.x vs frame center — "Turn head right" when nose.x < center - offset
          const turnHeadRight = noseX < FRAME_CENTER_X - HEAD_TURN_OFFSET
          const turnHeadLeft = noseX > FRAME_CENTER_X + HEAD_TURN_OFFSET

          // Head yaw detection (left/right turn)
          if (absYaw > YAW_ANGLE_THRESHOLD) {
            if (currentStepId === 'left' && !yawLeftDoneRef.current) {
              yawLeftConsecutiveRef.current += 1
              yawRightConsecutiveRef.current = 0
              if (yawLeftConsecutiveRef.current >= YAW_CONFIRM_FRAMES) {
                yawLeftDoneRef.current = true
                leftTurnSignRef.current = yawSign
              }
            } else if (
              currentStepId === 'right' &&
              leftTurnSignRef.current !== null &&
              yawSign !== leftTurnSignRef.current
            ) {
              yawRightConsecutiveRef.current += 1
              yawLeftConsecutiveRef.current = 0
              if (yawRightConsecutiveRef.current >= YAW_CONFIRM_FRAMES) {
                yawRightDoneRef.current = true
              }
            } else if (currentStepId === 'right') {
              yawRightConsecutiveRef.current = 0
            }
          } else {
            yawLeftConsecutiveRef.current = 0
            yawRightConsecutiveRef.current = 0
          }

          // Face size (eye distance) for "Move closer"
          const eyeDist = eyeDistance(landmarks)
          const tooFar = eyeDist < MIN_EYE_DISTANCE

          const centerAligned =
            noseX >= CENTER_X_MIN &&
            noseX <= CENTER_X_MAX &&
            noseY >= CENTER_Y_MIN &&
            noseY <= CENTER_Y_MAX

          if (centerAligned) {
            consecutiveCenterAlignedRef.current += 1
          } else {
            consecutiveCenterAlignedRef.current = 0
          }

          const centerAlignedStable = consecutiveCenterAlignedRef.current >= STABILITY_FRAMES

          // Antispoof: require face centered + single face for N frames
          if (currentStepId === 'antispoof') {
            const singleFace = faceCount === 1
            if (centerAlignedStable && singleFace) {
              antispoofConsecutiveRef.current += 1
            } else {
              antispoofConsecutiveRef.current = 0
            }
          } else {
            antispoofConsecutiveRef.current = 0
          }
          const antispoofDone = currentStepId === 'antispoof' && antispoofConsecutiveRef.current >= STABILITY_FRAMES * 2

          let effectiveAligned = centerAlignedStable
          if (currentStepId === 'left') effectiveAligned = yawLeftDoneRef.current
          else if (currentStepId === 'right') effectiveAligned = yawRightDoneRef.current
          else if (currentStepId === 'antispoof') effectiveAligned = antispoofDone
          setFaceAligned(effectiveAligned)

          if (livenessPassed) {
            setLiveFeedback('Liveness verified')
            return
          }

          const isLastStep = stepIdx >= stepsList.length - 1
          if (isLastStep && effectiveAligned) {
            setLivenessPassed(true)
            setLiveFeedback('Liveness verified')
            return
          }

          // Live feedback: head-turn — nose.x vs frame center (Turn head right when nose.x < center)
          if (currentStepId === 'left') {
            setLiveFeedback(yawLeftDoneRef.current ? stepsList[stepIdx + 1]?.label : 'Turn head left')
          } else if (currentStepId === 'right') {
            setLiveFeedback(yawRightDoneRef.current ? stepsList[stepIdx + 1]?.label : 'Turn head right')
          } else if (currentStepId === 'antispoof') {
            setLiveFeedback(antispoofDone ? stepsList[stepIdx + 1]?.label : 'Hold still, verifying...')
          } else if (currentStepId === 'blink') {
            const count = blinkCountRef.current
            if (count >= 2) setLiveFeedback(stepsList[stepIdx + 1]?.label || '')
            else if (count === 1) setLiveFeedback('Blink detected')
            else setLiveFeedback('Blink twice')
          } else {
            if (tooBrightRef.current) setLiveFeedback('Reduce backlight')
            else if (tooDarkRef.current) setLiveFeedback('Increase lighting')
            else if (tooFar) setLiveFeedback('Move closer')
            else if (!centerAlignedStable && faceDetectedStable) {
              setLiveFeedback(
                turnHeadRight ? 'Turn head right' : turnHeadLeft ? 'Turn head left' : stepsList[stepIdx]?.label ?? 'Align face'
              )
            } else setLiveFeedback('Face detected')
          }

          if (centerAlignedStable && currentStepId === 'center') {
            stepIndexRef.current = 1
            setStepIndex(1)
          }

          const leftEAR = eyeAspectRatio(landmarks, LEFT_EYE)
          const rightEAR = eyeAspectRatio(landmarks, RIGHT_EYE)
          const ear = (leftEAR + rightEAR) / 2
          const history = earHistoryRef.current
          history.push(ear)
          if (history.length > 20) history.shift()

          if (currentStepId === 'blink' && centerAlignedStable) {
            if (ear < BLINK_EAR_THRESHOLD) {
              consecutiveLowEarRef.current += 1
            } else {
              if (consecutiveLowEarRef.current >= BLINK_CONFIRM_FRAMES) {
                const framesSinceLastBlink = frameCountRef.current - lastBlinkFrameRef.current
                const minGap = 1
                if (framesSinceLastBlink >= minGap || blinkCountRef.current === 0) {
                  blinkCountRef.current += 1
                  setBlinkCount(blinkCountRef.current)
                  lastBlinkFrameRef.current = frameCountRef.current
                  setLiveFeedback('Blink detected')
                  if (blinkCountRef.current >= 2) {
                    const next = stepIdx + 1
                    stepIndexRef.current = next
                    setStepIndex(next)
                    blinkCountRef.current = 0
                  }
                }
              }
              consecutiveLowEarRef.current = 0
            }
          }

          if (currentStepId === 'left' && yawLeftDoneRef.current) {
            advanceToNextStep(stepIdx, stepsList)
          }
          if (currentStepId === 'right' && yawRightDoneRef.current) {
            advanceToNextStep(stepIdx, stepsList)
          }
          if (currentStepId === 'antispoof' && antispoofDone) {
            advanceToNextStep(stepIdx, stepsList)
          }

          function advanceToNextStep(idx, list) {
            const next = idx + 1
            if (next < list.length) {
              stepIndexRef.current = next
              setStepIndex(next)
            }
          }
        })

        function tick() {
          if (cancelled) return
          if (!faceMeshRef.current || !video.videoWidth) {
            rafRef.current = requestAnimationFrame(tick)
            return
          }
          rafFrameCounterRef.current += 1
          if (rafFrameCounterRef.current % PROCESS_EVERY_NTH_FRAME !== 0) {
            rafRef.current = requestAnimationFrame(tick)
            return
          }
          const now = Date.now()
          const elapsed = now - lastDetectionTimeRef.current
          if (elapsed >= DETECTION_INTERVAL_MS || lastDetectionTimeRef.current === 0) {
            lastDetectionTimeRef.current = now
            faceMeshRef.current
              .send({ image: video })
              .then(() => { rafRef.current = requestAnimationFrame(tick) })
              .catch(() => { rafRef.current = requestAnimationFrame(tick) })
          } else {
            rafRef.current = requestAnimationFrame(tick)
          }
        }
        tick()
      } catch {
        setError('Face Mesh failed to load')
      }
    }

    init()
    return () => {
      cancelled = true
      if (rafRef.current) cancelAnimationFrame(rafRef.current)
      faceMeshRef.current = null
    }
  }, [enabled, videoReady])

  return {
    livenessPassed,
    stepIndex,
    currentStep,
    steps,
    faceAligned,
    faceDetected,
    liveFeedback,
    progress,
    error,
    antiSpoofWarnings,
    tooBright,
    blinkCount,
    skipLivenessSteps,
  }
}
