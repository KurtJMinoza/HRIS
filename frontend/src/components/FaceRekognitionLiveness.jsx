/**
 * Amazon Rekognition Face Liveness via Amplify UI FaceLivenessDetector.
 * Creates session via Laravel, runs guided liveness, then submits sessionId to login or kiosk.
 */
import { useState, useEffect, useRef, useCallback } from 'react'
import { FaceLivenessDetector } from '@aws-amplify/ui-react-liveness'
import { ThemeProvider } from '@aws-amplify/ui-react'
import '@aws-amplify/ui-react/styles.css'
import { Loader2 } from 'lucide-react'
import { Amplify } from 'aws-amplify'
import { createLivenessSession, loginWithFace, recordAttendanceKioskFace } from '@/api'
import { playSuccess, playError } from '@/lib/attendanceSounds'
import { toast } from 'sonner'
import { Button } from '@/components/ui/button'
import { CheckCircle2, Home } from 'lucide-react'

const SOUND_FEEDBACK_ENABLED = true
const FACE_MATCH_TIMEOUT_MS = 6500
const VERIFY_SLOW_NOTICE_MS = 8000

function withTimeout(promise, timeoutMs, timeoutMessage) {
  return Promise.race([
    promise,
    new Promise((_, reject) => {
      setTimeout(() => reject(new Error(timeoutMessage || 'Request timed out')), timeoutMs)
    }),
  ])
}

function formatKioskTime(iso) {
  if (!iso) return '—'
  const d = new Date(iso)
  return d.toLocaleString('en-PH', { hour: '2-digit', minute: '2-digit', hour12: true })
}

/**
 * @param {((sessionId: string) => void | Promise<void>)} [onVerified] - When set, liveness is used for identity verification only (e.g. profile update, face registration). Called with sessionId on PASS; no login/kiosk.
 */
export function FaceRekognitionLiveness({
  onSuccess,
  onVerified,
  className = '',
  hideInstruction,
  kioskMode = false,
  kioskType = null,
  onKioskSuccess,
  onKioskCancel,
  onKioskErrorStateChange,
  instructionText,
}) {
  const [session, setSession] = useState(null)
  const [loading, setLoading] = useState(true)
  const [error, setError] = useState(null)
  const [submitting, setSubmitting] = useState(false)
  const [kioskSuccess, setKioskSuccess] = useState(false)
  const [kioskSuccessData, setKioskSuccessData] = useState(null)
  const [kioskSuccessPhase, setKioskSuccessPhase] = useState('verified') // 'verified' | 'closing'
  const [apiError, setApiError] = useState(null)
  const [successSummary, setSuccessSummary] = useState(null)
  const [silentSessionRefresh, setSilentSessionRefresh] = useState(false)
  /** 0 = first attempt; after face_not_recognized we auto-retry up to 2 more times (3 total). */
  const faceMatchAttemptRef = useRef(0)
  const [verifyPhase, setVerifyPhase] = useState('verify') // 'verify' | 'match' — shown during backend round-trip
  const handlingErrorRef = useRef(false)
  const amplifyConfiguredRef = useRef(false)

  const ensureAmplifyConfig = useCallback((sessionData) => {
    if (amplifyConfiguredRef.current) return
    const identityPoolId =
      sessionData?.cognitoIdentityPoolId || sessionData?.cognitoId || import.meta.env.VITE_COGNITO_IDENTITY_POOL_ID
    const cognitoRegion =
      sessionData?.cognitoRegion || import.meta.env.VITE_AWS_REGION || sessionData?.region || 'us-east-1'
    if (identityPoolId) {
      try {
        Amplify.configure({
          Auth: {
            Cognito: {
              identityPoolId,
              identityPoolRegion: cognitoRegion,
              allowGuestAccess: true,
            },
          },
        })
        amplifyConfiguredRef.current = true
      } catch (e) {
        console.warn('Amplify config (identity pool):', e?.message)
      }
    }
  }, [])

  const fetchSession = useCallback(
    async ({ silent = false } = {}) => {
      if (!silent) {
        setError(null)
        faceMatchAttemptRef.current = 0
        setLoading(true)
      } else {
        setSilentSessionRefresh(true)
      }
      try {
        const data = await createLivenessSession()
        setSession(data)
        ensureAmplifyConfig(data)
      } catch (e) {
        setError(e?.message || 'Could not create liveness session')
        if (!silent) setSession(null)
      } finally {
        if (!silent) setLoading(false)
        else setSilentSessionRefresh(false)
      }
    },
    [ensureAmplifyConfig]
  )

  useEffect(() => {
    fetchSession()
  }, [fetchSession])

  // Notify parent when kiosk error state changes (so parent can hide its Cancel when we show ours)
  useEffect(() => {
    onKioskErrorStateChange?.(!!apiError)
    return () => onKioskErrorStateChange?.(false)
  }, [apiError, onKioskErrorStateChange])

  // Kiosk success: brief celebration then hand off (~1.2–1.5s total — keeps flow snappy)
  useEffect(() => {
    if (!kioskSuccess || !kioskSuccessData || !onKioskSuccess) return
    const t1 = setTimeout(() => setKioskSuccessPhase('closing'), 400)
    const t2 = setTimeout(() => {
      onKioskSuccess(kioskSuccessData)
    }, 1200)
    return () => {
      clearTimeout(t1)
      clearTimeout(t2)
    }
  }, [kioskSuccess, kioskSuccessData, onKioskSuccess])

  // After Amplify reports analysis complete, the overlay stays until the API returns — split copy so it feels faster.
  useEffect(() => {
    if (!submitting && !silentSessionRefresh) return
    setVerifyPhase('verify')
    const t = setTimeout(() => setVerifyPhase('match'), 700)
    return () => clearTimeout(t)
  }, [submitting, silentSessionRefresh])

  const handleAnalysisComplete = useCallback(async () => {
    if (!session?.sessionId || submitting) return
    setSubmitting(true)
    setError(null)
    setApiError(null)
    try {
      if (onVerified) {
        const slowNoticeTimer = setTimeout(() => {
          toast.info('This is taking longer than usual…', {
            description: 'Face registration is still processing. Please keep this open for a bit longer.',
          })
        }, VERIFY_SLOW_NOTICE_MS)
        try {
          await onVerified(session.sessionId)
        } finally {
          clearTimeout(slowNoticeTimer)
        }
        faceMatchAttemptRef.current = 0
        playSuccess(SOUND_FEEDBACK_ENABLED)
        onSuccess?.()
        return
      }
      if (kioskMode && kioskType && onKioskSuccess) {
        const data = await withTimeout(
          recordAttendanceKioskFace(kioskType, {
            liveness_session_id: session.sessionId,
          }),
          FACE_MATCH_TIMEOUT_MS,
          'Face verification took too long. Please use QR code for faster fallback.'
        )
        faceMatchAttemptRef.current = 0
        playSuccess(SOUND_FEEDBACK_ENABLED)
        setKioskSuccess(true)
        setKioskSuccessData(data)
        setKioskSuccessPhase('verified')
        setSubmitting(false)
        return
      }
      const data = await withTimeout(
        loginWithFace({ liveness_session_id: session.sessionId }),
        FACE_MATCH_TIMEOUT_MS,
        'Face login timed out. Please try again.'
      )
      faceMatchAttemptRef.current = 0
      playSuccess(SOUND_FEEDBACK_ENABLED)
      const att = data?.attendance?.attendance
      const typeLabel = att?.type === 'clock_out' ? 'Out' : 'In'
      setSuccessSummary({
        name: data?.user?.name ?? 'Employee',
        type: att?.type ?? 'clock_in',
        recordedAt: att?.created_at ?? new Date().toISOString(),
        typeLabel,
      })
    } catch (err) {
      const msg = err?.message || 'Face verification failed'
      playError(SOUND_FEEDBACK_ENABLED)
      const code = err?.errorCode
      // Auto-retry up to 2 more times on face-not-match (no match ≠ spoof): new liveness session, user scans again.
      if (code === 'face_not_recognized' && faceMatchAttemptRef.current < 2) {
        faceMatchAttemptRef.current += 1
        toast.info(`Trying again (${faceMatchAttemptRef.current + 1} of 3)`, {
          description:
            'Face the camera straight-on, use even lighting (avoid backlight), then hold still when prompted.',
        })
        setSubmitting(false)
        await fetchSession({ silent: true })
        return
      }
      if (code === 'spoof_detected') {
        toast.error('Spoof detected', {
          description: 'Liveness check failed. Please use a real face, not a photo or screen.',
        })
      } else if (code === 'face_not_recognized') {
        toast.error('No match this attempt', {
          description: kioskMode
            ? msg || 'Try again with even lighting, or scan your QR code on this kiosk.'
            : msg || 'Try again with good lighting, or sign in with email and password.',
        })
      } else if (code === 'no_face_detected') {
        toast.error('No face detected', { description: msg })
      } else if (code === 'service_unavailable') {
        toast.error('Service unavailable', { description: msg })
      } else {
        toast.error(kioskMode ? 'Face verification failed' : 'Face login failed', { description: msg })
      }
      if (kioskMode) {
        setApiError(msg)
      } else {
        setError(msg)
      }
    } finally {
      setSubmitting(false)
    }
  }, [session, submitting, kioskMode, kioskType, onKioskSuccess, onVerified, onSuccess, fetchSession])

  const handleError = useCallback(async (err) => {
    console.error('Liveness error:', err)
    if (handlingErrorRef.current) return
    handlingErrorRef.current = true
    setLoading(true)
    try {
      await fetchSession()
    } finally {
      handlingErrorRef.current = false
    }
  }, [fetchSession])

  const closeSuccessSummary = useCallback(() => {
    setSuccessSummary(null)
    onSuccess?.()
  }, [onSuccess])

  if (loading) {
    return (
      <div className={className}>
        <div className="flex flex-col items-center justify-center gap-4 rounded-lg border border-white/10 bg-black/20 p-8">
          <Loader2 className="size-10 animate-spin text-emerald-400" aria-hidden />
          <span className="text-sm text-white/80">Creating liveness session…</span>
        </div>
      </div>
    )
  }

  if (error && !session) {
    return (
      <div className={className}>
        <div className="rounded-lg border border-amber-500/30 bg-amber-500/10 p-4 text-center">
          <p className="text-sm text-amber-200">{error}</p>
          <p className="mt-2 text-xs text-white/60">
            Ensure AWS Rekognition is configured and the backend can create liveness sessions.
          </p>
          <Button variant="outline" size="sm" className="mt-3" onClick={fetchSession}>
            Retry
          </Button>
        </div>
      </div>
    )
  }

  if (!session?.sessionId) return null

  const hasCognitoId =
    !!session?.cognitoIdentityPoolId ||
    !!session?.cognitoId ||
    !!import.meta.env.VITE_COGNITO_IDENTITY_POOL_ID
  if (!hasCognitoId) {
    return (
      <div className={className}>
        <div className="rounded-lg border border-amber-500/30 bg-amber-500/10 p-4 text-center">
          <p className="text-sm font-medium text-amber-200">Face Liveness requires Cognito Identity Pool</p>
          <p className="mt-2 text-xs text-white/60">
            Set <code className="rounded bg-white/10 px-1">VITE_COGNITO_IDENTITY_POOL_ID</code> and{' '}
            <code className="rounded bg-white/10 px-1">VITE_AWS_REGION</code> in the frontend <code className="rounded bg-white/10 px-1">.env</code>.
            The Identity Pool must allow unauthenticated access and its IAM role must have{' '}
            <code className="rounded bg-white/10 px-1">rekognition:StartFaceLivenessSession</code>.
          </p>
          <p className="mt-2 text-xs text-white/50">
            See backend <code>.env.example</code> or AWS Rekognition Face Liveness docs.
          </p>
          <Button variant="outline" size="sm" className="mt-3" onClick={fetchSession}>
            Retry
          </Button>
        </div>
      </div>
    )
  }

  return (
    <div className={className || ''}>
      {!hideInstruction && (
        <p className="text-center text-[11px] text-white/60">
          {instructionText ??
            (onVerified
              ? 'Complete the face liveness check to verify your identity.'
              : kioskMode
                ? 'Position your face in the frame. Use even lighting and avoid backlight from windows.'
                : 'Complete the face liveness check to sign in. Use even lighting in front of your face.')}
        </p>
      )}
      {!apiError && (
        <div className="relative w-full overflow-hidden rounded-lg border border-white/10 bg-black/20">
          <ThemeProvider colorMode="dark">
            <FaceLivenessDetector
              key={session.sessionId}
              sessionId={session.sessionId}
              region={session.region}
              onAnalysisComplete={handleAnalysisComplete}
              onError={handleError}
            />
          </ThemeProvider>
          {(submitting || kioskSuccess || silentSessionRefresh) && (
            <div className="absolute inset-0 flex flex-col items-center justify-center gap-3 bg-black/80">
              {kioskSuccess ? (
                <>
                  <CheckCircle2 className="size-10 text-emerald-400" aria-hidden />
                  <span className="text-sm font-medium text-white">
                    {kioskSuccessPhase === 'closing' ? 'Closing…' : 'Verified successfully'}
                  </span>
                </>
              ) : silentSessionRefresh ? (
                <>
                  <Loader2 className="size-10 animate-spin text-emerald-400" aria-hidden />
                  <span className="text-sm font-medium text-white">Preparing another try…</span>
                </>
              ) : (
                <>
                  <Loader2 className="size-10 animate-spin text-emerald-400" aria-hidden />
                  <span className="text-sm font-medium text-white">
                    {verifyPhase === 'verify' ? 'Verifying liveness…' : 'Matching your face…'}
                  </span>
                  <span className="max-w-[18rem] text-center text-[11px] text-white/65">
                    Hold still until the prompt finishes — this usually takes a few seconds.
                  </span>
                </>
              )}
            </div>
          )}
        </div>
      )}
      {apiError && (
        <>
          <p className="text-center text-sm text-white/90">{apiError}</p>
          <div className="mt-4 flex flex-row items-center justify-center gap-3">
            <Button
              size="sm"
              className="min-w-28 border border-white/30 bg-white/15 text-white hover:bg-white/25"
              onClick={() => {
                setApiError(null)
                fetchSession()
              }}
            >
              Try again
            </Button>
            {onKioskCancel && (
              <Button
                variant="ghost"
                size="sm"
                className="min-w-28 text-white/90 hover:bg-white/10 hover:text-white"
                onClick={() => {
                  setApiError(null)
                  onKioskCancel()
                }}
              >
                Cancel
              </Button>
            )}
          </div>
        </>
      )}
      {successSummary && (
        <div className="mt-4 flex flex-col items-center gap-4 rounded-xl border border-white/20 bg-white/10 p-6 text-center">
          <CheckCircle2 className="size-14 text-emerald-400" aria-hidden />
          {successSummary.name && (
            <h3 className="text-xl font-bold text-white">Welcome, {successSummary.name}</h3>
          )}
          <div className="space-y-1 text-sm text-white/80">
            {successSummary.typeLabel && (
              <span className="block font-medium capitalize">Clocked {successSummary.typeLabel}</span>
            )}
            {successSummary.recordedAt && (
              <span className="block text-white/70">{formatKioskTime(successSummary.recordedAt)}</span>
            )}
          </div>
          <Button
            onClick={closeSuccessSummary}
            className="rounded-xl bg-emerald-600 text-white hover:bg-emerald-500"
          >
            <Home className="mr-2 size-4" />
            Go to Dashboard
          </Button>
        </div>
      )}
    </div>
  )
}
