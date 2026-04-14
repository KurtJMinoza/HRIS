import { useEffect, useRef, useState } from 'react'
import { AnimatePresence, motion } from 'framer-motion'

const MotionDiv = motion.div

/** Soft camera shutter sound via Web Audio API (no external file) */
function playShutterSound() {
  try {
    const ctx = new (window.AudioContext || window.webkitAudioContext)()
    const osc = ctx.createOscillator()
    const gain = ctx.createGain()
    osc.connect(gain)
    gain.connect(ctx.destination)
    osc.frequency.setValueAtTime(1200, ctx.currentTime)
    osc.frequency.exponentialRampToValueAtTime(800, ctx.currentTime + 0.05)
    gain.gain.setValueAtTime(0.15, ctx.currentTime)
    gain.gain.exponentialRampToValueAtTime(0.01, ctx.currentTime + 0.12)
    osc.start(ctx.currentTime)
    osc.stop(ctx.currentTime + 0.12)
  } catch {
    return
  }
}

/** Subtle vibration on error (mobile) */
function vibrateOnError() {
  if (typeof navigator !== 'undefined' && navigator.vibrate) {
    navigator.vibrate([50, 30, 50])
  }
}

/** Premium color palette — enterprise, controlled, subtle emerald accent */
const COLORS = {
  primary: '#34D399',
  danger: '#FF4D4F',
  bg: '#0F172A',
  glow: 'rgba(52, 211, 153, 0.35)',
  glowSoft: 'rgba(52, 211, 153, 0.2)',
}

/** Minimum anti-spoof confidence (0–1) to show green / allow. Must match backend face_min_liveness_score. */
const LIVE_CONFIDENCE_THRESHOLD = 0.8

/** Anti-spoof warning keys → display labels */
const ANTI_SPOOF_LABELS = {
  multiple_faces: 'Multiple faces detected',
  no_depth_variation: 'No depth variation',
  screen_reflection: 'Screen reflection detected',
  static_image: 'Static image detected',
}

/**
 * Pro Face Liveness UI — Layer Architecture
 *
 * [ Camera Video ] → [ Dark Blur Overlay ] → [ Glowing Oval Frame ] → [ Animated Scan Line ]
 *   → [ Challenge Instruction ] → [ Security Indicator Badge ] → [ Progress Bar ]
 */
export function FaceLivenessOverlay({
  faceAligned,
  faceDetected,
  livenessPassed = false,
  instructionAbove = 'Center your face',
  instructionBelow,
  status,
  statusError = false,
  antiSpoofWarnings = [],
  spoofReasons = [],
  progress = 0,
  stepIndex = 0,
  steps = [],
  tooBright = false,
  blinkCount = 0,
  /** Real-time anti-spoof confidence 0–1 from backend (verify-only). When set, drives border color and Security %. */
  antiSpoofConfidence = null,
  className = '',
}) {
  const hasFace = faceDetected || faceAligned || livenessPassed
  const isFaceCentered = faceAligned || livenessPassed

  const warningKeys = [...new Set([...antiSpoofWarnings, ...spoofReasons])]
  const warningLabels = warningKeys
    .map((key) => ANTI_SPOOF_LABELS[key] || key)
    .filter(Boolean)
  const isSpoofState = statusError || warningLabels.length > 0

  const prevSpoofRef = useRef(false)
  const prevPassedRef = useRef(false)
  const prevBlinkCountRef = useRef(0)
  const [blinkBounce, setBlinkBounce] = useState(false)

  useEffect(() => {
    if (blinkCount > prevBlinkCountRef.current) {
      prevBlinkCountRef.current = blinkCount
      const id = requestAnimationFrame(() => {
        setBlinkBounce(true)
        setTimeout(() => setBlinkBounce(false), 450)
      })
      return () => cancelAnimationFrame(id)
    }
    prevBlinkCountRef.current = blinkCount
  }, [blinkCount])

  useEffect(() => {
    if (isSpoofState && !prevSpoofRef.current) {
      vibrateOnError()
    }
    prevSpoofRef.current = isSpoofState
  }, [isSpoofState])

  useEffect(() => {
    if (livenessPassed && !prevPassedRef.current) {
      playShutterSound()
    }
    prevPassedRef.current = livenessPassed
  }, [livenessPassed])
  const isValid = hasFace && !isSpoofState

  const displayStatus = status ?? instructionBelow
  const isSpoof = statusError || (displayStatus && displayStatus.toLowerCase().includes('spoof'))

  const statusMessage =
    isSpoof || warningLabels.length > 0
      ? 'Possible spoof attempt'
      : displayStatus || instructionAbove

  const progressPercent = Math.min(100, (progress ?? 0) * 100)

  // Anti-spoof confidence: use real-time from backend when provided, else synthetic from progress
  const spoofScore =
    antiSpoofConfidence !== null && antiSpoofConfidence !== undefined
      ? antiSpoofConfidence
      : livenessPassed
        ? 1
        : isSpoofState
          ? 0.2
          : 0.5 + (stepIndex / Math.max(1, steps.length)) * 0.45

  const isSpoofByConfidence =
    antiSpoofConfidence !== null &&
    antiSpoofConfidence !== undefined &&
    antiSpoofConfidence < LIVE_CONFIDENCE_THRESHOLD

  const getSecurityColor = (score) => {
    if (isSpoofState || isSpoofByConfidence) return COLORS.danger
    if (score >= LIVE_CONFIDENCE_THRESHOLD) return '#16A34A'
    if (score > 0.5) return '#14B8A6'
    return '#F59E0B'
  }

  const frameValid = isValid && !isSpoofByConfidence

  return (
    <div
      className={`pointer-events-none absolute inset-0 flex flex-col items-center justify-center overflow-hidden ${className}`}
      aria-hidden
    >
      {/* Layer 1: Cinematic focus — clean vignette, no heavy blur */}
      <div
        className="absolute inset-0"
        style={{
          background:
            'radial-gradient(circle at center, rgba(15,23,42,0) 40%, rgba(15,23,42,0.9) 70%)',
        }}
      />

      {/* Layer 3: Sharp Glowing Oval Border — green = live, red = spoof/low confidence; Framer Motion transitions */}
      <MotionDiv
        className="face-frame relative flex items-center justify-center overflow-hidden rounded-[50%] border-2"
        style={{
          width: 'min(78%, 300px)',
          aspectRatio: '3/4',
        }}
        animate={{
          borderColor: frameValid ? COLORS.primary : COLORS.danger,
          boxShadow: frameValid
            ? '0 0 20px rgba(52,211,153,0.35)'
            : '0 0 15px rgba(255,77,79,0.4)',
          scale: blinkBounce
            ? [1, 1.05, 1]
            : livenessPassed
              ? [1, 1.03, 1]
              : isFaceCentered
                ? 1
                : 0.96,
        }}
        transition={{
          borderColor: { duration: 0.35, ease: 'easeOut' },
          boxShadow: { duration: 0.35, ease: 'easeOut' },
          scale: {
            duration: blinkBounce || livenessPassed ? 0.4 : 0.3,
            ease: 'easeOut',
          },
        }}
      >
        {/* Layer 3: Animated Scan Line — premium sweep */}
        {!livenessPassed && hasFace && (
          <MotionDiv
            className="absolute left-0 right-0 h-0.5 rounded-full"
            style={{
              top: '50%',
              background: COLORS.primary,
              boxShadow: `0 0 12px ${COLORS.glow}`,
            }}
            animate={{ y: ['-150px', '150px'] }}
            transition={{
              repeat: Infinity,
              duration: 2,
              ease: 'linear',
            }}
          />
        )}
        {hasFace && (
          <div
            className="absolute inset-2 rounded-[50%] border border-white/10"
            style={{ pointerEvents: 'none' }}
          />
        )}
      </MotionDiv>

      {/* Backlight warning — subtle, top */}
      {!livenessPassed && tooBright && (
        <motion.div
          initial={{ opacity: 0, y: -8 }}
          animate={{ opacity: 1, y: 0 }}
          exit={{ opacity: 0, y: -8 }}
          className="absolute top-3 left-0 right-0 z-20 flex justify-center"
        >
          <span className="rounded-lg bg-amber-500/20 px-3 py-2 text-sm text-amber-400">
            Backlight detected. Move away from bright source.
          </span>
        </motion.div>
      )}

      {/* Layer 4: Structured Top Status — [ LIVE ● ] | [ Security % ] + instruction */}
      {!livenessPassed && (
        <div className={`absolute left-3 right-3 z-10 space-y-2 ${tooBright ? 'top-14' : 'top-4'}`}>
          <div className="flex justify-between items-center">
            <div className="flex items-center gap-2 text-xs" style={{ color: hasFace ? COLORS.primary : 'rgba(255,255,255,0.6)' }}>
              <span
                className={`size-2 rounded-full ${hasFace ? 'animate-pulse' : ''}`}
                style={{ background: hasFace ? COLORS.primary : 'rgba(255,255,255,0.4)' }}
              />
              {hasFace ? 'LIVE' : 'Detecting'}
            </div>
            <motion.div
              className="rounded-full px-3 py-1 text-xs font-medium tabular-nums"
              animate={{
                color: getSecurityColor(spoofScore),
              }}
              transition={{ duration: 0.3, ease: 'easeOut' }}
              style={{ background: 'rgba(0,0,0,0.5)' }}
            >
              {antiSpoofConfidence !== null && antiSpoofConfidence !== undefined
                ? `Live ${Math.round(spoofScore * 100)}%`
                : `Security ${Math.round(spoofScore * 100)}%`}
            </motion.div>
          </div>
          <AnimatePresence mode="wait">
            <motion.h2
              key={statusMessage}
              initial={{ opacity: 0 }}
              animate={{ opacity: 1 }}
              exit={{ opacity: 0 }}
              transition={{ duration: 0.2 }}
              className="text-lg font-semibold text-center"
              style={{
                color: isSpoof || warningLabels.length > 0 ? COLORS.danger : 'rgba(255,255,255,0.95)',
                textShadow: '0 2px 8px rgba(0,0,0,0.5)',
              }}
            >
              {isSpoof || warningLabels.length > 0 ? `${statusMessage} ❌` : statusMessage}
            </motion.h2>
          </AnimatePresence>
        </div>
      )}

      {/* Anti-spoof warnings */}
      {!livenessPassed && warningLabels.length > 0 && (
        <div className="absolute bottom-20 left-3 right-3 z-10 flex flex-col items-center gap-1.5">
          {warningLabels.map((label) => (
            <p
              key={label}
              className="flex items-center gap-2 rounded-lg px-3 py-1.5 text-xs font-medium"
              style={{
                background: 'rgba(255,59,59,0.2)',
                color: COLORS.danger,
                border: '1px solid rgba(255,59,59,0.4)',
              }}
            >
              <span aria-hidden>⚠</span>
              {label}
            </p>
          ))}
        </div>
      )}

      {/* Layer 6: Glass-style progress section */}
      {!livenessPassed && steps.length > 0 && (
        <div className="absolute bottom-6 left-1/2 z-10 w-[80%] -translate-x-1/2 rounded-xl bg-black/40 p-3 backdrop-blur-md">
          <div className="flex justify-between text-xs text-gray-300">
            <span>Step {stepIndex + 1} of {steps.length}</span>
            <span>{steps[stepIndex]?.label}</span>
          </div>
          <div className="mt-2 h-2 overflow-hidden rounded-full bg-gray-700">
            <motion.div
              className="h-2 rounded-full bg-emerald-400"
              initial={{ width: 0 }}
              animate={{ width: `${progressPercent}%` }}
              transition={{ duration: 0.3 }}
            />
          </div>
        </div>
      )}

      {/* Liveness verified overlay — smooth success transition */}
      {livenessPassed && (
        <MotionDiv
          initial={{ opacity: 0 }}
          animate={{ opacity: 1 }}
          transition={{ duration: 0.5 }}
          className="absolute inset-0 flex flex-col items-center justify-center backdrop-blur-lg"
        style={{
          background: 'rgba(52,211,153,0.15)',
        }}
        >
          <MotionDiv
            initial={{ opacity: 0, scale: 0.9 }}
            animate={{ opacity: 1, scale: 1 }}
            transition={{ duration: 0.4, delay: 0.2 }}
            className="flex flex-col items-center gap-4 rounded-2xl px-8 py-6"
            style={{
              background: 'rgba(15,23,42,0.9)',
              border: `2px solid ${COLORS.primary}50`,
              boxShadow: `0 0 24px ${COLORS.glowSoft}`,
            }}
          >
            <div
              className="flex size-16 items-center justify-center rounded-full"
            style={{
              background: 'rgba(52,211,153,0.2)',
              border: `2px solid ${COLORS.primary}50`,
            }}
            >
              <svg
                className="size-9"
                fill="none"
                viewBox="0 0 24 24"
                stroke={COLORS.primary}
                strokeWidth={2.5}
              >
                <path strokeLinecap="round" strokeLinejoin="round" d="M5 13l4 4L19 7" />
              </svg>
            </div>
            <div className="flex flex-col gap-2 text-left">
              <p className="flex items-center gap-2 text-sm font-semibold" style={{ color: COLORS.primary }}>
                <span aria-hidden>✔</span> Identity Verified
              </p>
              <p className="flex items-center gap-2 text-sm font-semibold" style={{ color: COLORS.primary }}>
                <span aria-hidden>✔</span> Liveness Confirmed
              </p>
            </div>
          </MotionDiv>
        </MotionDiv>
      )}
    </div>
  )
}
