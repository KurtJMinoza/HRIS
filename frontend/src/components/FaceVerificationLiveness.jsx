import { useCallback, useEffect, useRef, useState } from 'react'
import { CheckCircle2, Home, Loader2, LogOut } from 'lucide-react'
import {
  configuredAttendanceDeviceType,
  createAttendanceAttemptMeta,
  detectedAttendanceDeviceType,
  getAttendanceLocationDiagnostics,
  getToken,
  loginWithFace,
  prepareAttendanceLocation,
  recordAttendanceFace,
  recordAttendanceKioskFace,
  setAttendanceDeviceType,
} from '@/api'
import { playError, playSuccess } from '@/lib/attendanceSounds'
import { toast } from 'sonner'
import { Button } from '@/components/ui/button'
import { cn } from '@/lib/utils'
import { MediaPipeLivenessCamera } from '@/components/MediaPipeLivenessCamera'

const SOUND_FEEDBACK_ENABLED = true
const FACE_MATCH_TIMEOUT_MS = 12000

const ATTENDANCE_FLOW_STATE = {
  LOCATION_REQUESTING: 'LOCATION_REQUESTING',
  LOCATION_RECEIVED: 'LOCATION_RECEIVED',
  GEOFENCE_VALIDATING: 'GEOFENCE_VALIDATING',
  GEOFENCE_PASSED: 'GEOFENCE_PASSED',
  CAMERA_READY: 'CAMERA_READY',
}

const ATTENDANCE_FLOW_LABEL = {
  [ATTENDANCE_FLOW_STATE.LOCATION_REQUESTING]: 'Obtaining precise location...',
  [ATTENDANCE_FLOW_STATE.LOCATION_RECEIVED]: 'Location received...',
  [ATTENDANCE_FLOW_STATE.GEOFENCE_VALIDATING]: 'Validating geofence...',
  [ATTENDANCE_FLOW_STATE.GEOFENCE_PASSED]: 'Geofence passed...',
  [ATTENDANCE_FLOW_STATE.CAMERA_READY]: 'Preparing camera...',
}

function withTimeout(promise, timeoutMs, timeoutMessage) {
  return Promise.race([
    promise,
    new Promise((_, reject) => {
      setTimeout(() => reject(new Error(timeoutMessage || 'Request timed out')), timeoutMs)
    }),
  ])
}

function formatKioskTime(iso) {
  if (!iso) return '-'
  const d = new Date(iso)
  return d.toLocaleString('en-PH', { hour: '2-digit', minute: '2-digit', hour12: true })
}

function buildImagePayload(capture, attendanceDeviceProfile, attemptMeta) {
  return {
    image_base64: capture?.imageBase64,
    client_capture_started_at_ms: capture?.capturedAtMs,
    device_type: attendanceDeviceProfile || undefined,
    camera_info: capture?.cameraInfo,
    ...(attemptMeta || createAttendanceAttemptMeta('face')),
  }
}

export function FaceVerificationLiveness({
  onSuccess,
  onVerified,
  className = '',
  hideInstruction,
  kioskMode = false,
  kioskType = null,
  authenticatedAttendance = false,
  surface = 'dark',
  onKioskSuccess,
  onKioskAttendanceCorrection,
  onKioskCancel,
  onKioskErrorStateChange,
  attemptMeta,
  instructionText,
}) {
  const [ready, setReady] = useState(false)
  const [loading, setLoading] = useState(true)
  const [flowState, setFlowState] = useState(ATTENDANCE_FLOW_STATE.LOCATION_REQUESTING)
  const [attendanceDeviceProfile, setAttendanceDeviceProfile] = useState(
    () => configuredAttendanceDeviceType() || detectedAttendanceDeviceType() || '',
  )
  const [error, setError] = useState(null)
  const [locationDiagnostic, setLocationDiagnostic] = useState(null)
  const [locationBrowserMessage, setLocationBrowserMessage] = useState(null)
  const [geofenceDebug, setGeofenceDebug] = useState(null)
  const [submitting, setSubmitting] = useState(false)
  const [kioskSuccess, setKioskSuccess] = useState(false)
  const [kioskSuccessData, setKioskSuccessData] = useState(null)
  const [kioskSuccessPhase, setKioskSuccessPhase] = useState('verified')
  const [apiError, setApiError] = useState(null)
  const [apiErrorCode, setApiErrorCode] = useState(null)
  const [successSummary, setSuccessSummary] = useState(null)
  const [verifyPhase, setVerifyPhase] = useState('verify')
  const [scannerKey, setScannerKey] = useState(0)
  const attemptMetaRef = useRef(attemptMeta || createAttendanceAttemptMeta('face'))

  useEffect(() => {
    if (attemptMeta) attemptMetaRef.current = attemptMeta
  }, [attemptMeta])

  const prepareVerification = useCallback(async () => {
    if (!onVerified && !kioskMode && !attendanceDeviceProfile) {
      setLoading(false)
      setReady(false)
      return
    }

    setError(null)
    setLocationDiagnostic(null)
    setLocationBrowserMessage(null)
    setGeofenceDebug(null)
    setLoading(true)
    setReady(false)

    try {
      const canPrevalidateAttendanceLocation = !onVerified && Boolean(getToken()) && (!kioskMode || authenticatedAttendance)
      const existingLocation =
        attemptMetaRef.current?.latitude != null && attemptMetaRef.current?.longitude != null
          ? attemptMetaRef.current
          : null

      if (canPrevalidateAttendanceLocation) {
        if (existingLocation?.geofence_validation_id) {
          setFlowState(ATTENDANCE_FLOW_STATE.GEOFENCE_PASSED)
        } else {
          const prepared = await prepareAttendanceLocation({
            location: existingLocation,
            method: attemptMetaRef.current?.method || 'face',
            clock_type: kioskType || undefined,
            device_type: attendanceDeviceProfile,
            onStateChange: setFlowState,
          })
          attemptMetaRef.current = {
            ...(attemptMetaRef.current || createAttendanceAttemptMeta('face')),
            ...(prepared.location || {}),
            geofence_validation_id: prepared.result?.geofence_validation_id,
            geofence_status: prepared.result?.status,
          }
          setGeofenceDebug({
            latitude: prepared.location?.latitude,
            longitude: prepared.location?.longitude,
            accuracy: prepared.location?.accuracy_meters,
            branch: prepared.result?.branch?.name,
            geofence: prepared.result?.matched_geofence?.name,
            distance: prepared.result?.distance_meters ?? prepared.result?.distance,
            radius: prepared.result?.matched_geofence?.radius_meters,
            status: prepared.result?.status,
          })
        }
      } else if (!onVerified) {
        if (!existingLocation) {
          const prepared = await prepareAttendanceLocation({
            method: attemptMetaRef.current?.method || 'face',
            device_type: attendanceDeviceProfile,
            validate: false,
            onStateChange: setFlowState,
          })
          if (prepared.locationError) throw prepared.locationError
          attemptMetaRef.current = {
            ...(attemptMetaRef.current || createAttendanceAttemptMeta('face')),
            ...(prepared.location || {}),
          }
        } else {
          setFlowState(ATTENDANCE_FLOW_STATE.LOCATION_RECEIVED)
        }
      }

      setFlowState(ATTENDANCE_FLOW_STATE.CAMERA_READY)
      setReady(true)
    } catch (e) {
      const maybeLocationError = /location|geolocation|position|permission|denied/i.test(e?.message || '')
      if (maybeLocationError) {
        setLocationBrowserMessage(e?.browserMessage || null)
        setLocationDiagnostic(e?.diagnostics || await getAttendanceLocationDiagnostics().catch(() => null))
      }
      setError(e?.message || 'Could not prepare face verification')
      setReady(false)
    } finally {
      setLoading(false)
    }
  }, [attendanceDeviceProfile, authenticatedAttendance, kioskMode, kioskType, onVerified])

  useEffect(() => {
    prepareVerification()
  }, [prepareVerification])

  useEffect(() => {
    onKioskErrorStateChange?.(!!apiError)
    return () => onKioskErrorStateChange?.(false)
  }, [apiError, onKioskErrorStateChange])

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
    if (!submitting) return
    setVerifyPhase('verify')
    const t = setTimeout(() => setVerifyPhase('match'), 1200)
    return () => clearTimeout(t)
  }, [submitting])

  const handleMediaPipePassed = useCallback(async (capture) => {
    if (submitting) return
    setSubmitting(true)
    setError(null)
    setApiError(null)
    setApiErrorCode(null)

    try {
      const payload = buildImagePayload(capture, attendanceDeviceProfile, attemptMetaRef.current)

      if (onVerified) {
        await onVerified({
          image_base64: payload.image_base64,
          liveness_type: 'mediapipe',
          client_capture_started_at_ms: payload.client_capture_started_at_ms,
          camera_info: payload.camera_info,
        })
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
        const recordFaceAttendance = authenticatedAttendance ? recordAttendanceFace : recordAttendanceKioskFace
        const data = await withTimeout(
          recordFaceAttendance(kioskType, payload),
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
        loginWithFace(payload),
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
      if (onVerified) return

      const msg = err?.message || 'Face verification failed'
      playError(SOUND_FEEDBACK_ENABLED)
      const code = err?.errorCode

      if (code === 'spoof_detected') {
        toast.error('Face verification failed', {
          description: 'Please face the camera directly in good lighting and try again.',
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
      if (!kioskMode) setError(msg)
    } finally {
      setSubmitting(false)
    }
  }, [
    attendanceDeviceProfile,
    authenticatedAttendance,
    kioskMode,
    kioskType,
    onKioskAttendanceCorrection,
    onKioskSuccess,
    onSuccess,
    onVerified,
    submitting,
  ])

  const closeSuccessSummary = useCallback(() => {
    setSuccessSummary(null)
    onSuccess?.()
  }, [onSuccess])

  const selectAttendanceDeviceProfile = useCallback((deviceType) => {
    const selected = setAttendanceDeviceType(deviceType)
    setAttendanceDeviceProfile(selected)
    attemptMetaRef.current = createAttendanceAttemptMeta(attemptMetaRef.current?.method || 'face')
    setReady(false)
    setGeofenceDebug(null)
    setFlowState(ATTENDANCE_FLOW_STATE.LOCATION_REQUESTING)
  }, [])

  const retryBackendAttempt = useCallback(() => {
    setApiError(null)
    setApiErrorCode(null)
    setScannerKey((key) => key + 1)
  }, [])

  const lightSurface = surface === 'light'
  const panelClass = lightSurface
    ? 'rounded-lg border border-slate-200 bg-white p-5 text-slate-900 shadow-sm'
    : 'rounded-lg border border-white/10 bg-black/20 p-5'
  const softPanelClass = lightSurface
    ? 'rounded-lg border border-slate-200 bg-slate-50 p-4 text-center'
    : 'rounded-lg border border-amber-500/30 bg-amber-500/10 p-4 text-center'
  const instructionClass = lightSurface ? 'text-slate-500' : 'text-white/60'
  const mutedTextClass = lightSurface ? 'text-slate-600' : 'text-white/60'
  const strongTextClass = lightSurface ? 'text-slate-900' : 'text-white/80'
  const selectClass = lightSurface
    ? 'mt-4 h-10 w-full rounded-md border border-slate-300 bg-white px-3 text-sm text-slate-900 outline-none focus:border-orange-400'
    : 'mt-4 h-10 w-full rounded-md border border-white/15 bg-slate-950 px-3 text-sm text-white outline-none focus:border-emerald-400'
  const smallSelectClass = lightSurface
    ? 'h-8 rounded-md border border-slate-300 bg-white px-2 text-xs text-slate-900 outline-none focus:border-orange-400 disabled:opacity-60'
    : 'h-8 rounded-md border border-white/15 bg-slate-950 px-2 text-xs text-white outline-none focus:border-emerald-400 disabled:opacity-60'

  if (!onVerified && !kioskMode && !attendanceDeviceProfile) {
    return (
      <div className={className}>
        <div className={panelClass}>
          <p className={cn('text-sm font-semibold', strongTextClass)}>Select this device</p>
          <p className={cn('mt-1 text-xs', mutedTextClass)}>
            Browsers cannot reliably distinguish a desktop from a laptop. Choose the physical device so the correct geofence is enforced.
          </p>
          <select
            value={attendanceDeviceProfile}
            onChange={(event) => selectAttendanceDeviceProfile(event.target.value)}
            className={selectClass}
          >
            <option value="">Select desktop or laptop</option>
            <option value="desktop">Desktop computer</option>
            <option value="laptop">Laptop computer</option>
            <option value="mobile">Mobile phone</option>
            <option value="tablet">Tablet</option>
          </select>
        </div>
      </div>
    )
  }

  if (loading) {
    return (
      <div className={className}>
        <div className={cn(panelClass, 'flex flex-col items-center justify-center gap-4 p-8')}>
          <Loader2 className={cn('size-10 animate-spin', lightSurface ? 'text-orange-500' : 'text-emerald-400')} aria-hidden />
          <span className={cn('text-sm', strongTextClass)}>
            {ATTENDANCE_FLOW_LABEL[flowState] || 'Preparing attendance...'}
          </span>
        </div>
      </div>
    )
  }

  if (error && !ready) {
    const locationError = /location|geolocation|position|permission|denied/i.test(error)
    const permissionGranted = locationDiagnostic?.permission === 'granted'
    const permissionDenied = locationDiagnostic?.permission === 'denied' || (!permissionGranted && /blocked|denied/i.test(error))
    return (
      <div className={className}>
        <div className={cn(softPanelClass, lightSurface ? 'border-amber-200 bg-amber-50' : '')}>
          <p className={cn('text-sm font-semibold', lightSurface ? 'text-amber-800' : 'text-amber-200')}>{error}</p>
          {locationBrowserMessage ? (
            <p className={cn('mt-1 text-xs', lightSurface ? 'text-slate-500' : 'text-white/55')}>Browser message: {locationBrowserMessage}</p>
          ) : null}
          <p className={cn('mt-2 text-xs', mutedTextClass)}>
            {locationError
              ? permissionDenied
                ? 'Location is blocked in this browser for this HRIS site. Change the site permission to Allow, then retry.'
                : permissionGranted
                  ? 'The site permission is allowed, but Chrome or Windows still refused this location request. Confirm Windows Location access for desktop apps/Chrome, then retry.'
                  : 'Allow location access, then try again before starting face verification.'
              : 'Face verification could not be prepared. Please try again.'}
          </p>
          {locationError && locationDiagnostic ? (
            <div className={cn('mx-auto mt-3 grid max-w-md grid-cols-2 gap-2 rounded-md p-3 text-left text-[11px]', lightSurface ? 'border border-slate-200 bg-white text-slate-500' : 'border border-white/10 bg-black/20 text-white/60')}>
              <span>Permission</span>
              <b className={cn('text-right', strongTextClass)}>{locationDiagnostic.permission || 'unknown'}</b>
              <span>Geolocation</span>
              <b className={cn('text-right', strongTextClass)}>{locationDiagnostic.geolocationAvailable ? 'available' : 'unavailable'}</b>
              <span>HTTPS/local</span>
              <b className={cn('text-right', strongTextClass)}>{locationDiagnostic.https ? 'yes' : 'no'}</b>
              <span>Browser</span>
              <b className={cn('text-right', strongTextClass)}>{locationDiagnostic.browser || 'unknown'}</b>
              <span>OS</span>
              <b className={cn('text-right', strongTextClass)}>{locationDiagnostic.operatingSystem || 'unknown'}</b>
            </div>
          ) : null}
          <Button variant="outline" size="sm" className="mt-3" onClick={prepareVerification}>
            Retry
          </Button>
        </div>
      </div>
    )
  }

  return (
    <div className={className || ''}>
      {!onVerified && !kioskMode && (
        <div className={cn('mb-3 flex items-center justify-between gap-3 rounded-md border px-3 py-2', lightSurface ? 'border-slate-200 bg-slate-50' : 'border-white/10 bg-black/20')}>
          <span className={cn('text-xs', mutedTextClass)}>This device</span>
          <select
            value={attendanceDeviceProfile}
            onChange={(event) => selectAttendanceDeviceProfile(event.target.value)}
            disabled={submitting}
            className={smallSelectClass}
          >
            <option value="desktop">Desktop</option>
            <option value="laptop">Laptop</option>
            <option value="mobile">Mobile</option>
            <option value="tablet">Tablet</option>
          </select>
        </div>
      )}

      {!hideInstruction && (
        <p className={cn('text-center text-[11px]', instructionClass)}>
          {instructionText ??
            (onVerified
              ? 'Center your face and hold still to verify the registration photo.'
              : kioskMode
                ? 'Center your face, move closer if needed, then hold still.'
                : 'Center your face, move closer if needed, then hold still.')}
        </p>
      )}

      {!onVerified && geofenceDebug && (
        <div className={cn('mb-3 mt-2 grid grid-cols-2 gap-2 rounded-md border p-3 text-[11px]', lightSurface ? 'border-slate-200 bg-slate-50 text-slate-500' : 'border-white/10 bg-black/25 text-white/60')}>
          <span>Current GPS</span>
          <b className={cn('text-right', strongTextClass)}>
            {geofenceDebug.latitude != null && geofenceDebug.longitude != null
              ? `${Number(geofenceDebug.latitude).toFixed(6)}, ${Number(geofenceDebug.longitude).toFixed(6)}`
              : '-'}
          </b>
          <span>Accuracy</span>
          <b className={cn('text-right', strongTextClass)}>{geofenceDebug.accuracy != null ? `${Math.round(Number(geofenceDebug.accuracy))}m` : '-'}</b>
          <span>Branch</span>
          <b className={cn('text-right', strongTextClass)}>{geofenceDebug.branch || '-'}</b>
          <span>Geofence</span>
          <b className={cn('text-right', strongTextClass)}>{geofenceDebug.geofence || '-'}</b>
          <span>Distance</span>
          <b className={cn('text-right', strongTextClass)}>{geofenceDebug.distance != null ? `${Math.round(Number(geofenceDebug.distance))}m` : '-'}</b>
          <span>Radius</span>
          <b className={cn('text-right', strongTextClass)}>{geofenceDebug.radius != null ? `${Math.round(Number(geofenceDebug.radius))}m` : '-'}</b>
          <span>Result</span>
          <b className={geofenceDebug.status === 'inside' ? 'text-right text-emerald-600 dark:text-emerald-300' : 'text-right text-amber-600 dark:text-amber-300'}>
            {(geofenceDebug.status || 'unknown').toUpperCase()}
          </b>
        </div>
      )}

      {!apiError && (
        <div className="relative">
          <MediaPipeLivenessCamera
            key={scannerKey}
            surface={surface}
            disabled={submitting || kioskSuccess}
            showCancel={Boolean(onKioskCancel)}
            onCancel={onKioskCancel}
            onPassed={handleMediaPipePassed}
          />
          {(submitting || kioskSuccess) && (
            <div className={cn('absolute inset-0 flex flex-col items-center justify-center gap-3 rounded-[20px]', lightSurface ? 'bg-white/85 text-slate-900' : 'bg-black/80')}>
              {kioskSuccess ? (
                <>
                  <CheckCircle2 className="size-10 text-emerald-500" aria-hidden />
                  <span className={cn('text-sm font-medium', strongTextClass)}>
                    {kioskSuccessPhase === 'closing' ? 'Closing...' : 'Verified successfully'}
                  </span>
                </>
              ) : onVerified ? (
                <>
                  <Loader2 className={cn('size-10 animate-spin', lightSurface ? 'text-orange-500' : 'text-emerald-400')} aria-hidden />
                  <span className={cn('text-sm font-medium', strongTextClass)}>Registering face...</span>
                  <span className={cn('max-w-[18rem] text-center text-[11px]', mutedTextClass)}>
                    Generating your face template and checking for duplicates. This may take up to 30 seconds.
                  </span>
                </>
              ) : (
                <>
                  <Loader2 className={cn('size-10 animate-spin', lightSurface ? 'text-orange-500' : 'text-emerald-400')} aria-hidden />
                  <span className={cn('text-sm font-medium', strongTextClass)}>
                    {verifyPhase === 'verify' ? 'Verifying...' : 'Matching your face...'}
                  </span>
                </>
              )}
            </div>
          )}
        </div>
      )}

      {apiError && (
        <>
          <div className="rounded-lg border border-brand/25 bg-brand/8 p-3 dark:border-brand/35 dark:bg-brand/15">
            <p className="text-center text-sm font-semibold text-brand-strong dark:text-brand-foreground">
              {apiError}
            </p>
          </div>
          <div className="mt-4 flex flex-row items-center justify-center gap-3">
            <button
              type="button"
              className="inline-flex h-8 min-w-28 items-center justify-center rounded-md border border-brand bg-brand px-3 text-sm font-semibold text-brand-foreground transition-colors hover:bg-brand-strong dark:border-brand dark:bg-brand dark:hover:bg-brand-strong"
              onClick={retryBackendAttempt}
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
                className="inline-flex h-8 min-w-28 items-center justify-center rounded-md border border-brand/30 bg-transparent px-3 text-sm font-semibold text-brand transition-colors hover:bg-brand/10 hover:text-brand-strong dark:border-brand/35 dark:text-brand-foreground dark:hover:bg-brand/20 dark:hover:text-brand-foreground"
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
