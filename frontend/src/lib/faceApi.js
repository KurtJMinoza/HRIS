/**
 * face-api.js wrapper for live face detection, 128D descriptor, and liveness/validation helpers.
 * Models must be in public/models (run scripts/download-face-models.js if needed).
 */
import * as faceapi from 'face-api.js'

const MODELS_PATH = '/models'

export const EAR_CLOSED_THRESHOLD = 0.2
export const EAR_OPEN_THRESHOLD = 0.25
export const BRIGHTNESS_THRESHOLD = 40

let modelsLoaded = false

/**
 * Load face-api.js models from MODELS_PATH. Idempotent.
 * @returns {Promise<void>}
 */
export async function loadFaceModels() {
  if (modelsLoaded) return
  await Promise.all([
    faceapi.nets.tinyFaceDetector.loadFromUri(MODELS_PATH),
    faceapi.nets.faceLandmark68Net.loadFromUri(MODELS_PATH),
    faceapi.nets.faceRecognitionNet.loadFromUri(MODELS_PATH),
  ])
  modelsLoaded = true
}

const detectorOptions = new faceapi.TinyFaceDetectorOptions({
  inputSize: 224,
  scoreThreshold: 0.6,
})

/**
 * Detect exactly one face with landmarks and 128D descriptor.
 * @param {HTMLVideoElement} video
 * @returns {Promise<{ detection: object, landmarks: object, descriptor: number[] } | undefined>}
 */
export async function detectStrictSingleFace(video) {
  const result = await faceapi
    .detectSingleFace(video, detectorOptions)
    .withFaceLandmarks(true)
    .withFaceDescriptor()
  if (!result) return undefined
  const descriptor = Array.isArray(result.descriptor)
    ? result.descriptor
    : Array.from(result.descriptor)
  return {
    detection: result.detection,
    landmarks: result.landmarks,
    descriptor,
  }
}

/**
 * Eye aspect ratio from 6 eye points (face-api 68 landmarks: left 36–41, right 42–47).
 * EAR = (||p2-p6|| + ||p3-p5||) / (2*||p1-p4||). Lower = more closed.
 */
function eyeAspectRatio(eyePoints) {
  if (!eyePoints || eyePoints.length < 6) return 1
  const [p1, p2, p3, p4, p5, p6] = eyePoints
  const d1 = dist(p2, p6)
  const d2 = dist(p3, p5)
  const d3 = dist(p1, p4)
  if (d3 < 1e-6) return 1
  return (d1 + d2) / (2 * d3)
}

function dist(a, b) {
  const dx = (a.x ?? a._x) - (b.x ?? b._x)
  const dy = (a.y ?? a._y) - (b.y ?? b._y)
  return Math.hypot(dx, dy)
}

/**
 * @param {{ landmarks: { getLeftEye: () => any, getRightEye: () => any } }} fullResult
 * @returns {number} average EAR (both eyes)
 */
export function getEyeAspectRatio(fullResult) {
  if (!fullResult?.landmarks) return 1
  const left = fullResult.landmarks.getLeftEye?.() ?? []
  const right = fullResult.landmarks.getRightEye?.() ?? []
  const earLeft = eyeAspectRatio(left)
  const earRight = eyeAspectRatio(right)
  return (earLeft + earRight) / 2
}

/**
 * Simple head pose hint from nose/eye positions (no full 3D). Returns 'center' | 'left' | 'right' | 'up' | 'down'.
 */
export function getHeadPoseHint(fullResult) {
  if (!fullResult?.landmarks?.positions?.length) return 'center'
  const positions = fullResult.landmarks.positions
  const nose = positions[30] ?? positions[27]
  const leftEye = fullResult.landmarks.getLeftEye?.()
  const rightEye = fullResult.landmarks.getRightEye?.()
  if (!nose || !leftEye?.length || !rightEye?.length) return 'center'
  const leftC = center(leftEye)
  const rightC = center(rightEye)
  const eyeCenterX = (leftC.x + rightC.x) / 2
  const eyeCenterY = (leftC.y + rightC.y) / 2
  const dx = (nose.x ?? nose._x) - eyeCenterX
  const dy = (nose.y ?? nose._y) - eyeCenterY
  const threshold = 15
  if (Math.abs(dy) > threshold && dy > 0) return 'down'
  if (Math.abs(dy) > threshold && dy < 0) return 'up'
  if (dx > threshold) return 'right'
  if (dx < -threshold) return 'left'
  return 'center'
}

function center(points) {
  let x = 0, y = 0
  for (const p of points) {
    x += p.x ?? p._x ?? 0
    y += p.y ?? p._y ?? 0
  }
  return { x: x / points.length, y: y / points.length }
}

/**
 * Average brightness of the detection box region (0–255). Uses canvas 2d getImageData.
 */
export function getFrameBrightness(ctx, box) {
  if (!ctx || !box) return 128
  const x = Math.max(0, Math.floor(box.x))
  const y = Math.max(0, Math.floor(box.y))
  const w = Math.max(1, Math.min(box.width, ctx.canvas.width - x))
  const h = Math.max(1, Math.min(box.height, ctx.canvas.height - y))
  let data
  try {
    data = ctx.getImageData(x, y, w, h).data
  } catch {
    return 128
  }
  let sum = 0
  for (let i = 0; i < data.length; i += 4) {
    sum += (data[i] + data[i + 1] + data[i + 2]) / 3
  }
  return data.length > 0 ? sum / (data.length / 4) : 128
}

/**
 * Whether the face box is roughly centered in the frame (within ~40% of center).
 */
export function isFaceCentered(detection, videoWidth, videoHeight) {
  if (!detection?.box) return false
  const { x, y, width, height } = detection.box
  const cx = x + width / 2
  const cy = y + height / 2
  const marginX = videoWidth * 0.35
  const marginY = videoHeight * 0.35
  return (
    Math.abs(cx - videoWidth / 2) < marginX &&
    Math.abs(cy - videoHeight / 2) < marginY
  )
}

/**
 * Validate detection for liveness/quality. Options: { requireBlink, requireCentered, minBrightness }.
 * @returns {{ valid: boolean, message?: string }}
 */
export function validateFaceDetection(fullResult, ctx, videoWidth, videoHeight, options = {}) {
  if (!fullResult) return { valid: false, message: 'No face detected' }
  const { requireCentered = true, minBrightness = BRIGHTNESS_THRESHOLD } = options
  if (requireCentered && !isFaceCentered(fullResult.detection, videoWidth, videoHeight)) {
    return { valid: false, message: 'Center your face in the frame' }
  }
  if (ctx && fullResult.detection?.box) {
    const brightness = getFrameBrightness(ctx, fullResult.detection.box)
    if (brightness < minBrightness) {
      return { valid: false, message: 'Face too dark. Improve lighting.' }
    }
  }
  return { valid: true }
}

/**
 * Draw detection box on canvas (green = valid, red = invalid).
 */
export function drawDetectionBox(canvas, fullResult, isValid) {
  if (!canvas || !fullResult?.detection) return
  const ctx = canvas.getContext('2d')
  if (!ctx) return
  const box = fullResult.detection.box
  const color = isValid ? 'rgba(34, 197, 94, 0.9)' : 'rgba(239, 68, 68, 0.9)'
  ctx.strokeStyle = color
  ctx.lineWidth = 3
  ctx.strokeRect(box.x, box.y, box.width, box.height)
}
