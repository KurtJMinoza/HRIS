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
import { CheckCircle2, Home, LogOut } from 'lucide-react'

const SOUND_FEEDBACK_ENABLED = true
const FACE_MATCH_TIMEOUT_MS = 12000
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
  /** Duplicate kiosk clock-in after successful face match → parent opens correction modal */
  onKioskAttendanceCorrection,
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
  const [apiErrorCode, setApiErrorCode] = useState(null)
  const [successSummary, setSuccessSummary] = useState(null)
  const [silentSessionRefresh, setSilentSessionRefresh] = useState(false)
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

  useEffect(() => {
    if (!submitting && !silentSessionRefresh) return
    setVerifyPhase('verify')
    const t = setTimeout(() => setVerifyPhase('match'), 1800)
    return () => clearTimeout(t)
  }, [submitting, silentSessionRefresh])

  const handleAnalysisComplete = useCallback(async () => {
    if (!session?.sessionId || submitting) return
    setSubmitting(true)
    setError(null)
    setApiError(null)
    setApiErrorCode(null)
    try {
      if (onVerified) {
        // No timeout here — registration polls the queue job which can take up to 2 min
        // (InsightFace embedding + duplicate scan + DB write under lock).
        // The parent's handleFaceRegisterVerified owns the timeout/error display.
        await onVerified(session.sessionId)
        playSuccess(SOUND_FEEDBACK_ENABLED)
        onSuccess?.()
        return
      }
      if (kioskMode && !kioskType) {
        const msg = 'Please choose Clock In or Clock Out before scanning your face.'
        toast.error('Select attendance action', { description: msg })
        setApiError(msg)
        setApiErrorCode('kiosk_type_required')
        return
      }
      if (kioskMode && kioskType && onKioskSuccess) {
        const data = await withTimeout(
          recordAttendanceKioskFace(kioskType, {
            liveness_session_id: session.sessionId,
          }),
          FACE_MATCH_TIMEOUT_MS,
          'Face verification took too long. Please try again.'
        )
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
      // Registration mode: parent (EmployeeMyQr / AdminEmployees) owns all error display.
      // The component just resets its own spinner state — no toast, no error UI here.
      if (onVerified) {
        return
      }
      const msg = err?.message || 'Face verification failed'
      playError(SOUND_FEEDBACK_ENABLED)
      const code = err?.errorCode
      if (code === 'spoof_detected') {
        toast.error('Spoof detected', {
          description: 'Liveness check failed. Please use a real face, not a photo or screen.',
        })
        if (kioskMode) {
          setApiError('Face not clear. Please face the camera straight with good lighting and hold still.')
          setApiErrorCode(code)
        }
      } else if (code === 'face_not_recognized') {
        toast.error('Face not recognized', {
          description: kioskMode
            ? msg || 'Face not recognized. Please try again.'
            : msg || 'Try again with good lighting, or sign in with email and password.',
        })
        if (kioskMode) {
          setApiError('Face not recognized. Please try again.')
          setApiErrorCode(code)
        }
      } else if (code === 'face_not_registered') {
        toast.error('Face not registered', {
          description: 'Please register your face in My QR & Face first.',
        })
        if (kioskMode) {
          setApiError('Face not registered. Please register your face in My QR & Face first.')
          setApiErrorCode(code)
        }
      } else if (code === 'login_required_for_face') {
        toast.error('Employee login required', {
          description: 'Enter your username, email, or employee code before face clocking.',
        })
        if (kioskMode) {
          setApiError('Employee login is required before face clocking. Enter your username, email, or employee code.')
          setApiErrorCode(code)
        }
      } else if (code === 'face_account_mismatch') {
        toast.error('Face and account mismatch', {
          description: 'The scanned face does not match the entered account.',
        })
        if (kioskMode) {
          setApiError('Face and account do not match. Please use the correct account or register your own face.')
          setApiErrorCode(code)
        }
      } else if (code === 'face_needs_reregistration') {
        toast.error('Face update required', {
          description: 'Your face data needs to be updated. Please re-register your face in My QR & Face.',
        })
        if (kioskMode) {
          setApiError('Your face data needs to be updated. Please re-register your face in My QR & Face.')
          setApiErrorCode(code)
        }
      } else if (code === 'no_face_detected') {
        toast.error('No face detected', { description: msg })
        if (kioskMode) {
          setApiError('Face not clear. Please face the camera straight with good lighting and hold still.')
          setApiErrorCode(code)
        }
      } else if (code === 'service_unavailable') {
        toast.error('Service unavailable', { description: msg })
        if (kioskMode) {
          setApiError('Face not clear. Please face the camera straight with good lighting and hold still.')
          setApiErrorCode(code)
        }
      } else if (code === 'kiosk_attendance_correction') {
        toast.warning('Attendance correction', { description: msg })
        if (kioskMode && err?.kioskCorrection && onKioskAttendanceCorrection) {
          onKioskAttendanceCorrection(err.kioskCorrection)
        } else if (kioskMode) {
          setApiError(msg || 'Attendance correction may be required.')
          setApiErrorCode(code)
        }
      } else {
        toast.error(kioskMode ? 'Face verification failed' : 'Face login failed', { description: msg })
        if (kioskMode) {
          setApiError(msg)
          setApiErrorCode(code || 'unknown')
        }
      }
      if (!kioskMode) {
        setError(msg)
      }
    } finally {
      setSubmitting(false)
    }
  }, [session, submitting, kioskMode, kioskType, onKioskSuccess, onKioskAttendanceCorrection, onVerified, onSuccess, fetchSession])

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
                ? 'Face the camera straight, align your face in the frame, and hold still in good lighting.'
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
              ) : onVerified ? (
                <>
                  <Loader2 className="size-10 animate-spin text-emerald-400" aria-hidden />
                  <span className="text-sm font-medium text-white">Registering face…</span>
                  <span className="max-w-[18rem] text-center text-[11px] text-white/65">
                    Generating your face template and checking for duplicates. This may take up to 30 seconds.
                  </span>
                </>
              ) : (
                <>
                  <Loader2 className="size-10 animate-spin text-emerald-400" aria-hidden />
                  <span className="text-sm font-medium text-white">
                    {verifyPhase === 'verify' ? 'Verifying liveness…' : 'Matching your face…'}
                  </span>
                  <span className="max-w-[18rem] text-center text-[11px] text-white/65">
                    {verifyPhase === 'verify'
                      ? "Hold still — confirming it's a live face."
                      : 'Comparing your face against enrolled profiles…'}
                  </span>
                </>
              )}
            </div>
          )}
        </div>
      )}
      {apiError && (
        <>
          <div className="rounded-lg border border-rose-300! bg-rose-50! p-3 dark:border-rose-400/35! dark:bg-rose-500/10!">
            <p className="text-center text-sm font-semibold text-black! dark:text-rose-100!">
              {apiError}
            </p>
          </div>
          <div className="mt-4 flex flex-row items-center justify-center gap-3">
            <button
              type="button"
              className="inline-flex h-8 min-w-28 items-center justify-center rounded-md border border-slate-600 bg-slate-200 px-3 text-sm font-semibold text-black opacity-100 hover:bg-slate-300 dark:border-white/30 dark:bg-white/15 dark:text-white dark:hover:bg-white/25"
              onClick={() => {
                setApiError(null)
                setApiErrorCode(null)
                fetchSession()
              }}
            >
              {apiErrorCode === 'face_not_recognized' ? 'Try Again' : 'Try again'}
            </button>
            {(apiErrorCode === 'face_not_registered' || apiErrorCode === 'face_needs_reregistration') && (
              <Button
                size="sm"
                variant="secondary"
                className="min-w-28"
                onClick={() => {
                  window.location.assign('/login')
                }}
              >
                {apiErrorCode === 'face_needs_reregistration' ? 'Re-register Face' : 'Register Face'}
              </Button>
            )}
            {onKioskCancel && (
              <button
                type="button"
                className="inline-flex h-8 min-w-28 items-center justify-center rounded-md border border-slate-600 bg-slate-200 px-3 text-sm font-semibold text-black opacity-100 hover:bg-slate-300 hover:text-black dark:border-white/30 dark:bg-transparent dark:text-white/90 dark:hover:bg-white/10 dark:hover:text-white"
                onClick={() => {
                  setApiError(null)
                  setApiErrorCode(null)
                  onKioskCancel()
                }}
              >
                Cancel
              </button>
            )}
          </div>
        </>
      )}
      {successSummary && (
        <div className="mt-4 mx-auto flex w-full max-w-sm flex-col items-center gap-4 rounded-2xl border border-slate-200/90 bg-white p-6 text-center shadow-xl">
          {successSummary.type === 'clock_out' ? (
            <LogOut className="size-12 text-slate-500" aria-hidden />
          ) : (
            <CheckCircle2 className="size-12 text-[#ff6818]" aria-hidden />
          )}
          {successSummary.name && (
            <div>
              <h3 className="text-balance text-xl font-bold leading-snug text-slate-900">
                {successSummary.type === 'clock_out' ? (
                  <>
                    Goodbye,
                    <br />
                    <span className="text-slate-800">{successSummary.name}</span>
                  </>
                ) : (
                  <>
                    Welcome,
                    <br />
                    <span className="text-slate-800">{successSummary.name}</span>
                  </>
                )}
              </h3>
              <p className="mt-2 text-sm text-slate-500">
                {successSummary.type === 'clock_out'
                  ? 'Your end-of-shift time has been logged.'
                  : 'Your attendance has been recorded.'}
              </p>
            </div>
          )}
          <div className="space-y-1 text-sm text-slate-600">
            {successSummary.typeLabel != null && successSummary.typeLabel !== '' && (
              <span className="block text-xs font-semibold uppercase tracking-wide text-slate-400">
                Clocked {successSummary.typeLabel}
              </span>
            )}
            {successSummary.recordedAt && (
              <span className="block font-mono text-lg font-bold text-slate-900">{formatKioskTime(successSummary.recordedAt)}</span>
            )}
          </div>
          <Button
            onClick={closeSuccessSummary}
            className="w-full rounded-xl border border-[#ff8533]/35 bg-linear-to-br from-[#ff8a44] to-[#ff5410] font-semibold text-white shadow-md hover:from-[#ff7f36] hover:to-[#ea4a0f]"
          >
            <Home className="mr-2 size-4" />
            Go to Dashboard
          </Button>
        </div>
      )}
    </div>
  )
}
