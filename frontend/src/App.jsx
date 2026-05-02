import { Suspense, lazy, useCallback, useEffect, useId, useRef, useState } from 'react'
import { BrowserRouter, Navigate, Route, Routes, useNavigate, useLocation } from 'react-router-dom'

/** Matches Vite `base` (e.g. `/HR/` → `/HR`) so routes work when deployed under a subpath */
const routerBasename = import.meta.env.BASE_URL.replace(/\/$/, '') || undefined
import { Toaster, toast } from 'sonner'
import { LogIn, LogOut, Scan, ScanFace, Loader2, ChevronDown, ChevronUp, CheckCircle2, Home, Eye, EyeOff, ClipboardList, User, LockKeyhole } from 'lucide-react'
import {
  login,
  loginWithFace,
  verifyFaceOnly,
  getStoredUser,
  recordAttendanceKiosk,
  recordAttendanceKioskFace,
  getKioskRecentAttendance,
} from './api'
import { FaceRekognitionLiveness } from '@/components/FaceRekognitionLiveness'
import { playSuccess, playError } from '@/lib/attendanceSounds'
import {
  validateLoginIdentifier,
  validatePassword,
  sanitizeLogin,
  sanitizePassword,
} from './validation'
import { Button } from '@/components/ui/button'
import { Card, CardContent, CardDescription, CardHeader, CardTitle } from '@/components/ui/card'
import { Input } from '@/components/ui/input'
import { Label } from '@/components/ui/label'
import { Checkbox } from '@/components/ui/checkbox'
import { cn } from '@/lib/utils'
import { useBlinkLiveness } from '@/hooks/useBlinkLiveness'
import { FaceLivenessOverlay } from '@/components/FaceLivenessOverlay'
import { AuthProvider, useAuth } from '@/contexts/AuthContext'
import { ThemeProvider } from '@/contexts/ThemeContext'
import { ProtectedRoute } from '@/components/ProtectedRoute'
import { HrPanelLayout } from '@/layouts/HrPanelLayout'
import { EmployeeDashboardLayout } from '@/layouts/EmployeeDashboardLayout'
import { HR_PANEL_CHILD_ROUTES } from '@/routes/hrPanelChildRoutes'
import { resolvePostLoginPath } from '@/lib/hrRoutes'
import EmployeeDashboard from '@/pages/EmployeeDashboard'
import EmployeeAttendance from '@/pages/EmployeeAttendance'
import EmployeeCorrectionRequests from '@/pages/EmployeeCorrectionRequests'
import EmployeeProfile from '@/pages/EmployeeProfile'
import EmployeeMyPayslipsPage from '@/pages/EmployeeMyPayslipsPage'
import EmployeeLeave from '@/pages/EmployeeLeave'
import { ErrorBoundary } from '@/components/ErrorBoundary'
import { MyScheduleRouteFallback } from '@/components/skeletons/RoutePageFallbacks.jsx'

import EmployeeOvertime from '@/pages/EmployeeOvertime'
import EmployeeMyQr from '@/pages/EmployeeMyQr'
const EmployeeReportsPage = lazy(() => import('@/pages/AdminReports'))
import ForgotPassword from '@/pages/ForgotPassword'
import VerifyOtp from '@/pages/VerifyOtp'
import ResetPassword from '@/pages/ResetPassword'
import { ScannerInput } from '@/components/ScannerInput'
import { Avatar, AvatarFallback, AvatarImage } from '@/components/ui/avatar'
import { getEmployeeAvatarColorClass, kioskAttendanceAvatarSrc } from '@/lib/employeeAvatar'
import Webcam from 'react-webcam'
import { Dialog, DialogContent, DialogFooter } from '@/components/ui/dialog'
import { AgcBrandLogo } from '@/components/AgcBrandLogo'

/** Login duo people mark — SVG only (one white tile; avoids nested/cropped raster glitches). */
function LoginHrDualFigureMark({ className }) {
  const gid = useId().replace(/:/g, '')
  const gradId = `hrisLoginDuoGrad-${gid}`
  return (
    <div
      className={cn(
        'flex size-28 shrink-0 items-center justify-center rounded-[1.125rem] border border-orange-400/45 bg-white',
        'shadow-[0_12px_30px_-14px_rgba(15,23,42,0.18)] md:size-31',
        className,
      )}
      role="img"
      aria-label="HRIS icon"
    >
      <svg
        viewBox="0 0 88 76"
        className="h-15.5 w-18 md:h-16.5 md:w-20"
        xmlns="http://www.w3.org/2000/svg"
        aria-hidden
      >
        <defs>
          <linearGradient id={gradId} x1={44} y1={8} x2={44} y2={74} gradientUnits="userSpaceOnUse">
            <stop stopColor="#ffb020" />
            <stop offset="1" stopColor="#ea4a12" />
          </linearGradient>
        </defs>
        <g fill={`url(#${gradId})`} opacity="0.92">
          <ellipse cx={28} cy={27} rx={12} ry={13} />
          <path d="M16 71V55Q28 44 41 52V71H16z" />
        </g>
        <g fill={`url(#${gradId})`}>
          <ellipse cx={53} cy={25} rx={13} ry={14} />
          <path d="M35 71V53Q53 46 68 53V71H35z" />
        </g>
      </svg>
    </div>
  )
}

const MySchedule = lazy(() => import('@/pages/MySchedule'))
const AdminPayslipViewPage = lazy(() => import('@/pages/AdminPayslipViewPage'))
const EmployeeLoansDeductionsPage = lazy(() => import('@/pages/EmployeeLoansDeductionsPage'))

// —— Real-time clock for DTR ——
function RealTimeClock() {
  const [now, setNow] = useState(() => new Date())
  useEffect(() => {
    const t = setInterval(() => setNow(new Date()), 1000)
    return () => clearInterval(t)
  }, [])
  const time = now.toLocaleTimeString('en-PH', { hour: '2-digit', minute: '2-digit', second: '2-digit', hour12: true })
  const [timeMain, timeSuffix] = time.split(' ')
  const date = now.toLocaleDateString('en-PH', { weekday: 'long', month: 'long', day: 'numeric', year: 'numeric' })
  return (
    <div className="flex flex-col items-center gap-1">
      <div className="flex items-baseline gap-2.5 font-mono tabular-nums" aria-live="polite">
        <span className="text-[3.4rem] font-black leading-none text-[#050918] md:text-[4.35rem] xl:text-[4.55rem]">
          {timeMain}
        </span>
        {timeSuffix && (
          <span className="mb-1.5 self-end text-lg font-bold text-[#ff5a14] md:text-xl">
            {timeSuffix}
          </span>
        )}
      </div>
      <span className="text-sm font-medium text-[#4f5665]">{date}</span>
    </div>
  )
}

const FEATURES = [
  'Barcode Scanner',
  'Face Recognition',
  'Real-Time Reports',
  'Role-Based Dashboard',
]

function formatKioskTime(iso) {
  if (!iso) return '—'
  const d = new Date(iso)
  return d.toLocaleString('en-PH', { hour: '2-digit', minute: '2-digit', hour12: true })
}

/** Kiosk attendance confirmation dialog header (light surface → dark logo asset). */
function KioskAttendanceModalBrandBar({ variant }) {
  const isOut = variant === 'clock_out'
  return (
    <div className="flex flex-wrap items-center gap-3 border-b border-slate-200/90 bg-linear-to-r from-slate-50 to-white px-5 py-3.5 sm:px-6">
      <AgcBrandLogo variant="light" className="h-8 w-[104px] shrink-0 object-contain object-left" />
      <p className="min-w-0 flex-1 truncate text-[11px] text-slate-500">
        {isOut ? 'Shift end confirmation' : 'Shift start confirmation'}
      </p>
    </div>
  )
}

/** Relative day label for kiosk feed: Today, Yesterday, or a short date (en-PH). */
function formatKioskDateLabel(iso) {
  if (!iso) return ''
  const d = new Date(iso)
  if (Number.isNaN(d.getTime())) return ''
  const now = new Date()
  const startToday = new Date(now.getFullYear(), now.getMonth(), now.getDate()).getTime()
  const startLog = new Date(d.getFullYear(), d.getMonth(), d.getDate()).getTime()
  const dayDiff = Math.round((startToday - startLog) / 86400000)
  if (dayDiff === 0) return 'Today'
  if (dayDiff === 1) return 'Yesterday'
  const sameYear = d.getFullYear() === now.getFullYear()
  return d.toLocaleDateString('en-PH', {
    month: 'short',
    day: 'numeric',
    ...(sameYear ? {} : { year: 'numeric' }),
  })
}

const SOUND_FEEDBACK_ENABLED = true
const FACE_CAPTURE_WIDTH = 640
const FACE_CAPTURE_HEIGHT = 480
const FACE_CAPTURE_QUALITY = 0.82

function captureResizedFrameBase64(webcamRef, {
  width = FACE_CAPTURE_WIDTH,
  height = FACE_CAPTURE_HEIGHT,
  quality = FACE_CAPTURE_QUALITY,
} = {}) {
  const webcam = webcamRef?.current
  const video = webcam?.video
  if (!video || !video.videoWidth || !video.videoHeight) return null
  const canvas = document.createElement('canvas')
  canvas.width = width
  canvas.height = height
  const ctx = canvas.getContext('2d', { alpha: false })
  if (!ctx) return null
  ctx.drawImage(video, 0, 0, width, height)
  const dataUrl = canvas.toDataURL('image/jpeg', quality)
  return dataUrl.split(',')[1] || null
}

const ATTENDANCE_MODES = [
  { value: 'face_recognition', label: 'Face Recognition', icon: ScanFace },
  { value: 'qr_code', label: 'Barcode Scanner', icon: Scan },
]

// —— Face Recognition: kiosk (attendance only) or login (auth + attendance) ——
function FaceLoginCapture({ onSuccess, className, hideInstruction, kioskMode, kioskType, onKioskSuccess }) {
  const [faceSubmitting, setFaceSubmitting] = useState(false)
  const [faceVideoReady, setFaceVideoReady] = useState(false)
  const [faceErrorCode, setFaceErrorCode] = useState(null)
  const [faceSuccessSummary, setFaceSuccessSummary] = useState(null)
  const [liveConfidence, setLiveConfidence] = useState(null)
  const faceCaptureTriggeredRef = useRef(false)
  const webcamRef = useRef(null)
  const {
    livenessPassed,
    faceAligned,
    faceDetected,
    liveFeedback,
    progress: faceProgress,
    stepIndex: faceStepIndex,
    steps: faceSteps,
    error: blinkError,
    antiSpoofWarnings,
    tooBright: faceTooBright,
    blinkCount: faceBlinkCount,
  } = useBlinkLiveness(webcamRef, true, faceVideoReady, true)

  useEffect(() => {
    if (!livenessPassed) faceCaptureTriggeredRef.current = false
  }, [livenessPassed])

  useEffect(() => {
    if (livenessPassed || faceSubmitting || faceSuccessSummary) {
      setLiveConfidence(null)
      return
    }
    if (!faceVideoReady || !webcamRef.current) return
    const interval = setInterval(async () => {
      if (!webcamRef.current || faceSubmitting) return
      const base64 = captureResizedFrameBase64(webcamRef)
      if (!base64) return
      try {
        const result = await verifyFaceOnly(base64)
        if (result && result.spoof_confidence != null) setLiveConfidence(result.spoof_confidence)
      } catch {
        // ignore network errors during polling
      }
    }, 1500)
    return () => clearInterval(interval)
  }, [faceVideoReady, livenessPassed, faceSubmitting, faceSuccessSummary])

  useEffect(() => {
    if (!faceVideoReady) return
    const t = setTimeout(async () => {
      const warmupBase64 = captureResizedFrameBase64(webcamRef, { width: 480, height: 360, quality: 0.72 })
      if (!warmupBase64) return
      try {
        await verifyFaceOnly(warmupBase64)
      } catch {
        // Warmup is best-effort only.
      }
    }, 350)
    return () => clearTimeout(t)
  }, [faceVideoReady])

  useEffect(() => {
    if (!livenessPassed || faceSubmitting || faceSuccessSummary) return
    if (faceCaptureTriggeredRef.current) return
    faceCaptureTriggeredRef.current = true
    handleFaceCapture()
  }, [livenessPassed, faceSubmitting, faceSuccessSummary])

  async function handleFaceCapture() {
    if (faceSubmitting || !webcamRef.current) return
    const capturedAtMs = Date.now()
    const base64 = captureResizedFrameBase64(webcamRef)
    if (!base64) {
      toast.error('Camera capture failed', { description: 'Please allow camera access and try again.' })
      return
    }
    setFaceSubmitting(true)
    setFaceErrorCode(null)
    try {
      if (kioskMode && !kioskType) {
        toast.error('Select attendance action', {
          description: 'Please choose Clock In or Clock Out before scanning your face.',
        })
        return
      }
      if (kioskMode && kioskType && onKioskSuccess) {
        const data = await recordAttendanceKioskFace(kioskType, {
          image_base64: base64,
          client_capture_started_at_ms: capturedAtMs,
        })
        playSuccess(SOUND_FEEDBACK_ENABLED)
        onKioskSuccess(data)
        return
      }
      const data = await loginWithFace({
        image_base64: base64,
        client_capture_started_at_ms: capturedAtMs,
      })
      playSuccess(SOUND_FEEDBACK_ENABLED)
      const att = data?.attendance?.attendance
      const typeLabel = att?.type === 'clock_out' ? 'Out' : 'In'
      setFaceSuccessSummary({
        name: data?.user?.name ?? 'Employee',
        type: att?.type ?? 'clock_in',
        recordedAt: att?.created_at ?? new Date().toISOString(),
        typeLabel,
      })
    } catch (err) {
      // Do NOT reset faceCaptureTriggeredRef — prevents spam retries when face fails
      const msg = err?.message || 'Face verification failed'
      playError(SOUND_FEEDBACK_ENABLED)
      const code = err?.errorCode
      if (code === 'spoof_detected') {
        setFaceErrorCode('spoof_detected')
        toast.error('Spoof detected', { description: 'Liveness check failed. Please use a real face, not a photo or screen.' })
      } else if (code === 'face_not_recognized') {
        toast.error('Face not recognized', {
          description:
            msg ||
            (kioskMode
              ? 'Face not recognized. Please try again.'
              : 'Try again with good lighting, or sign in with username/email and password.'),
        })
      } else if (code === 'no_face_detected') {
        toast.error('No face detected', { description: msg })
      } else if (code === 'service_unavailable') {
        toast.error('Service unavailable', { description: msg })
      } else {
        toast.error(kioskMode ? 'Face verification failed' : 'Face login failed', { description: msg })
      }
    } finally {
      setFaceSubmitting(false)
    }
  }

  function closeFaceSuccessSummary() {
    setFaceSuccessSummary(null)
    onSuccess?.()
  }

  useEffect(() => {
    if (!faceSuccessSummary) return
    const t = setTimeout(closeFaceSuccessSummary, 2500)
    return () => clearTimeout(t)
  }, [faceSuccessSummary])

  return (
    <div className={cn('space-y-3', className)}>
      {!hideInstruction && (
        <p className="text-center text-[11px] text-white/60">
          Position your face in the frame to sign in. No login required.
        </p>
      )}
      <div className="relative aspect-video overflow-hidden rounded-lg border border-white/10 bg-black/20">
        <Webcam
          ref={webcamRef}
          audio={false}
          screenshotFormat="image/jpeg"
          screenshotQuality={FACE_CAPTURE_QUALITY}
          forceScreenshotSourceSize={false}
          videoConstraints={{ facingMode: 'user', width: FACE_CAPTURE_WIDTH, height: FACE_CAPTURE_HEIGHT }}
          className="h-full w-full object-cover"
          mirrored
          onUserMedia={() => setFaceVideoReady(true)}
        />
        {faceVideoReady && (
          <FaceLivenessOverlay
            faceAligned={faceAligned}
            faceDetected={faceDetected}
            livenessPassed={livenessPassed}
            instructionAbove="Position your face in the frame"
            instructionBelow={liveFeedback}
            status={faceErrorCode === 'spoof_detected' ? 'Spoof detected' : liveFeedback}
            statusError={faceErrorCode === 'spoof_detected'}
            antiSpoofWarnings={antiSpoofWarnings}
            progress={faceProgress}
            stepIndex={faceStepIndex}
            steps={faceSteps}
            tooBright={faceTooBright}
            blinkCount={faceBlinkCount}
            spoofReasons={faceErrorCode === 'spoof_detected' ? ['static_image'] : []}
            antiSpoofConfidence={liveConfidence}
          />
        )}
        {faceSubmitting && (
          <div className="absolute inset-0 flex flex-col items-center justify-center gap-3 bg-black/80">
            <Loader2 className="size-10 animate-spin text-emerald-400" aria-hidden />
            <span className="text-sm font-medium text-white">Verifying face…</span>
            <span className="text-xs text-white/60">AI processing</span>
          </div>
        )}
      </div>
      {blinkError && (
        <p className="text-center text-xs text-amber-400">{blinkError}</p>
      )}
      <Dialog open={!!faceSuccessSummary} onOpenChange={(open) => !open && closeFaceSuccessSummary()}>
        <DialogContent
          overlayClassName="bg-slate-900/45 backdrop-blur-sm"
          className="overflow-hidden rounded-2xl border border-slate-200 bg-white p-0 shadow-[0_25px_50px_-12px_rgba(15,23,42,0.25)] sm:max-w-[400px]"
          innerClassName="gap-0 p-0 sm:p-0 overflow-x-hidden"
          closeButtonClassName="right-4 top-[14px] z-30 border border-slate-200 bg-white text-slate-600 shadow-sm hover:bg-slate-50"
        >
          <KioskAttendanceModalBrandBar variant={faceSuccessSummary?.type === 'clock_out' ? 'clock_out' : 'clock_in'} />
          <div className="px-6 pb-7 pt-6 text-center sm:px-8">
            <div className="mb-4 inline-flex items-center gap-2 text-sm font-bold">
              {faceSuccessSummary?.type === 'clock_out' ? (
                <>
                  <LogOut className="size-5 text-slate-500" aria-hidden />
                  <span className="text-slate-700">Clocked out</span>
                </>
              ) : (
                <>
                  <CheckCircle2 className="size-5 text-[#ff6818]" aria-hidden />
                  <span className="text-[#b45309]">Clocked in</span>
                </>
              )}
            </div>
            {faceSuccessSummary?.name && (
              <>
                <h3 className="text-balance text-2xl font-bold leading-tight text-slate-900">
                  {faceSuccessSummary?.type === 'clock_out' ? (
                    <>
                      Goodbye,<br />
                      <span className="text-slate-800">{faceSuccessSummary.name}</span>
                    </>
                  ) : (
                    <>
                      Welcome,<br />
                      <span className="text-slate-800">{faceSuccessSummary.name}</span>
                    </>
                  )}
                </h3>
                <p className="mt-2 text-sm text-slate-500">
                  {faceSuccessSummary?.type === 'clock_out'
                    ? 'Your end-of-shift time has been logged.'
                    : 'Your attendance has been recorded for this shift.'}
                </p>
              </>
            )}
            <div className="mt-4 space-y-1 text-slate-600">
              {faceSuccessSummary?.typeLabel != null && faceSuccessSummary.typeLabel !== '' && (
                <p className="text-xs font-semibold uppercase tracking-wide text-slate-400">
                  Confirmation · Clock {faceSuccessSummary.typeLabel}
                </p>
              )}
              {faceSuccessSummary?.recordedAt && (
                <p className="font-mono text-2xl font-bold tabular-nums text-slate-900">{formatKioskTime(faceSuccessSummary.recordedAt)}</p>
              )}
            </div>
            <p className="mt-4 text-xs text-slate-400">
              Closing automatically…
            </p>
            <Button
              onClick={closeFaceSuccessSummary}
              className="mt-6 w-full rounded-xl border border-[#ff8533]/35 bg-linear-to-br from-[#ff8a44] to-[#ff5410] text-[15px] font-semibold text-white shadow-md hover:from-[#ff7f36] hover:to-[#ea4a0f]"
            >
              <Home className="mr-2 size-4" />
              Continue
            </Button>
          </div>
        </DialogContent>
      </Dialog>
    </div>
  )
}

function SmartDTRPreview({ className }) {
  const navigate = useNavigate()
  const [attendanceMode, setAttendanceMode] = useState('face_recognition')
  // null = auto-detect (scanner reads clock_in vs clock_out from backend context)
  // 'clock_in' | 'clock_out' = explicit override (optional for scanner, required for face)
  const [kioskType, setKioskType] = useState(null)
  const [submitting, setSubmitting] = useState(false)
  const [error, setError] = useState(null)
  const [scanResult, setScanResult] = useState(null) // inline success feedback
  const [recentLogs, setRecentLogs] = useState([])
  const [recentExpanded, setRecentExpanded] = useState(false)
  const [summaryModal, setSummaryModal] = useState({
    open: false,
    employeeId: null,
    employeeName: null,
    employeeProfileImageUrl: null,
    employeeProfileImage: null,
    type: null,
    recordedAt: null,
    status: null,
    lateMinutes: null,
    lateLabel: null,
    undertimeMinutes: null,
    correctionSuggested: false,
    correctionReason: null,
  })
  /** Duplicate clock-in at kiosk → offer Presence / Attendance Correction filing (employee portal after login). */
  const [kioskCorrectionModal, setKioskCorrectionModal] = useState({
    open: false,
    reason: null,
    employeeId: null,
    employeeName: null,
    employeeProfileImageUrl: null,
    employeeProfileImage: null,
  })
  const [kioskFaceInError, setKioskFaceInError] = useState(false)
  const lastScanRef = useRef({ text: null, at: 0 })

  const RECENT_VISIBLE_COUNT = 5
  const visibleLogs = recentExpanded ? recentLogs : recentLogs.slice(0, RECENT_VISIBLE_COUNT)
  const hasMore = recentLogs.length > RECENT_VISIBLE_COUNT


  const fetchRecent = useCallback(async () => {
    try {
      const data = await getKioskRecentAttendance()
      setRecentLogs(data.attendance || [])
    } catch {
      setRecentLogs([])
    }
  }, [])

  const goFileAttendanceCorrectionPortal = useCallback(() => {
    navigate('/login', {
      state: { from: { pathname: '/employee/correction-requests' } },
    })
  }, [navigate])

  useEffect(() => {
    fetchRecent()
    const t = setInterval(fetchRecent, 30000)
    return () => clearInterval(t)
  }, [fetchRecent])

  // Auto-clear inline scan result (longer when orphan clock-out hint is shown so user can tap File correction)
  useEffect(() => {
    if (!scanResult) return
    const ms = scanResult.correctionSuggested ? 14000 : 6000
    const t = setTimeout(() => setScanResult(null), ms)
    return () => clearTimeout(t)
  }, [scanResult])

  // Auto-clear scan error after 5 s so the scanner is ready again
  useEffect(() => {
    if (!error) return
    const t = setTimeout(() => setError(null), 5000)
    return () => clearTimeout(t)
  }, [error])

  async function handleScan(qrText) {
    if (!qrText || submitting) return
    const now = Date.now()
    const last = lastScanRef.current
    if (last.text === qrText && now - last.at < 2500) return
    lastScanRef.current = { text: qrText, at: now }

    setError(null)
    setScanResult(null)
    setSubmitting(true)

    let data = null
    let usedType = kioskType

    try {
      if (!kioskType) {
        throw new Error('Please select Clock In or Clock Out before scanning.')
      }
      // Always submit the explicitly selected action to avoid unintended dual punches.
      data = await recordAttendanceKiosk(kioskType, qrText)
      usedType = kioskType

      playSuccess(SOUND_FEEDBACK_ENABLED)
      const kc = data.kiosk_correction
      setScanResult({
        employeeId: data.employee_id ?? null,
        employeeName: data.employee_name ?? null,
        employeeProfileImageUrl: data.employee_profile_image_url ?? null,
        employeeProfileImage: data.employee_profile_image ?? null,
        type: usedType,
        recordedAt: data.attendance?.created_at ?? new Date().toISOString(),
        status: data.attendance?.status ?? null,
        lateMinutes: data.attendance?.late_minutes ?? null,
        lateLabel: data.attendance?.late_label ?? null,
        undertimeMinutes: data.attendance?.undertime_minutes ?? null,
        correctionSuggested: Boolean(kc?.suggested),
        correctionReason: kc?.reason ?? null,
      })
      setKioskType(null)
      fetchRecent()
    } catch (e) {
      if (e?.errorCode === 'kiosk_attendance_correction' && e?.kioskCorrection) {
        const k = e.kioskCorrection
        setKioskCorrectionModal({
          open: true,
          reason: k.reason ?? 'already_timed_in',
          employeeId: k.employee_id ?? null,
          employeeName: k.employee_name ?? null,
          employeeProfileImageUrl: k.employee_profile_image_url ?? null,
          employeeProfileImage: k.employee_profile_image ?? null,
        })
        playError(SOUND_FEEDBACK_ENABLED)
      } else {
        setError(e.message)
        playError(SOUND_FEEDBACK_ENABLED)
      }
    } finally {
      setSubmitting(false)
    }
  }

  function openCapture(type) {
    setKioskType(type)
    setError(null)
    setScanResult(null)
  }

  function handleModeChange(mode) {
    setAttendanceMode(mode)
    setKioskType(null)
    setError(null)
    setScanResult(null)
  }

  const closeSummaryModal = useCallback(() => {
    setSummaryModal((s) => ({
      ...s,
      open: false,
      correctionSuggested: false,
      correctionReason: null,
    }))
  }, [])

  const closeKioskCorrectionModal = useCallback(() => {
    setKioskCorrectionModal((s) => ({ ...s, open: false }))
  }, [])

  function goToDashboard() {
    closeSummaryModal()
    navigate('/')
  }

  /** Auto-close face summary unless orphan clock-out hint is shown (employee may need time to read / tap File correction). */
  const [kioskAutoCloseSeconds, setKioskAutoCloseSeconds] = useState(2)
  useEffect(() => {
    if (!summaryModal.open) return
    if (summaryModal.correctionSuggested) {
      setKioskAutoCloseSeconds(0)
      return undefined
    }
    setKioskAutoCloseSeconds(2)
    const after1s = setTimeout(() => setKioskAutoCloseSeconds(1), 1000)
    const after2s = setTimeout(() => closeSummaryModal(), 2000)
    return () => {
      clearTimeout(after1s)
      clearTimeout(after2s)
    }
  }, [summaryModal.open, summaryModal.correctionSuggested, closeSummaryModal])

  const kioskSummaryName = (summaryModal.employeeName || '').trim() || 'Employee'
  const kioskSummaryIsClockOut = summaryModal.type === 'clock_out'
  const kioskSummaryPhotoSrc = kioskAttendanceAvatarSrc(summaryModal)

  const kioskCorrectionConflictTitle =
    kioskCorrectionModal.reason === 'already_timed_in'
      ? 'Already clocked in'
      : 'File attendance correction'

  const kioskCorrectionConflictBody =
    kioskCorrectionModal.reason === 'already_timed_in'
      ? 'You already have a clock-in today. Use Attendance Correction after signing in if your punches are wrong or incomplete.'
      : 'Sign in to the employee portal to submit an attendance correction (Presence filing / approvals).'

  return (
    <div
      className={cn(
        'relative flex min-h-full w-full flex-col overflow-hidden bg-[#fbfbfc] text-[#090d18] lg:h-screen',
        className
      )}
    >
      <div className="pointer-events-none absolute inset-0 bg-[radial-gradient(circle_at_50%_0%,rgba(255,255,255,0.96),rgba(247,248,250,0.72)_58%,rgba(255,255,255,0.95)_100%)]" aria-hidden />

      {/* Brand bar — AGC lockup framing (narrow viewport + lift) */}
      <div className="relative z-10 flex items-start justify-between px-8 pt-6 pb-0 sm:px-10 xl:px-12">
        <div className="relative h-[50px] w-[126px] shrink-0 overflow-hidden">
          <AgcBrandLogo
            variant="light"
            className="absolute left-0 top-[-37px] h-auto max-h-none w-[126px] max-w-none object-contain object-left"
          />
        </div>
        <div className="inline-flex items-center gap-2 rounded-xl border border-[#dfe2e8] bg-white/80 px-3 py-2 text-xs font-bold uppercase text-[#5b6473] shadow-[0_2px_8px_rgba(15,23,42,0.08)]">
          <span className="relative flex size-2">
            <span className="absolute inset-0 animate-ping rounded-full bg-[#ff5a14] opacity-35" />
            <span className="relative inline-flex size-2 rounded-full bg-[#ff5a14]" />
          </span>
          Live
        </div>
      </div>

      {/* Hero Clock */}
      <div className="relative z-10 flex flex-col items-center px-6 pt-1 pb-4">
        <RealTimeClock />
      </div>

      <div className="relative z-10 mx-11 h-px bg-[#e3e5ea]" aria-hidden />

      {/* Scrollable body */}
      <div className="relative z-10 flex-1 overflow-y-auto lg:overflow-hidden">
        <div className="space-y-3 px-8 pt-4 pb-2 sm:px-10 xl:px-12">

          {/* Mode segmented control — iOS-style filled vs outline */}
          <div className="flex items-center justify-center">
            <div
              className="inline-flex rounded-[14px] border border-[#e1e4ea] bg-white/85 p-1 shadow-[0_4px_18px_rgba(15,23,42,0.07)]"
              role="tablist"
              aria-label="Attendance capture mode"
            >
              {ATTENDANCE_MODES.map((mode) => {
                const IconComponent = mode.icon
                const isActive = attendanceMode === mode.value
                return (
                  <button
                    key={mode.value}
                    type="button"
                    role="tab"
                    aria-selected={isActive}
                    onClick={() => handleModeChange(mode.value)}
                    className={cn(
                      'inline-flex items-center gap-2 rounded-[10px] border px-5 py-2.5 text-sm font-semibold transition-all duration-200 focus:outline-none focus:ring-2 focus:ring-[#ff6818]/25',
                      isActive
                        ? 'border-[#ff8a45] bg-[#fff6f0] text-[#ff4f0b]'
                        : 'border-transparent text-[#111827] hover:bg-[#f7f8fa]'
                    )}
                  >
                    <IconComponent className={cn('size-4 shrink-0', isActive ? 'text-[#ff5a14]' : 'text-[#ff5a14]')} />
                    {mode.label}
                  </button>
                )
              })}
            </div>
          </div>

          {/* ── Action area ── */}
          {attendanceMode === 'qr_code' ? (
            /* ── QR code scanner mode ── */
            <div className="space-y-3">
              {/* Clock In / Clock Out — explicit action required before scanning */}
              <div className="grid grid-cols-2 gap-3">
                <button
                  type="button"
                  onClick={() => setKioskType(kioskType === 'clock_in' ? null : 'clock_in')}
                  className={cn(
                    'group flex min-h-[154px] flex-col items-center justify-center gap-3 rounded-xl border px-4 py-5 transition-all duration-200 hover:scale-[1.01] active:scale-[0.99] focus:outline-none focus:ring-2 focus:ring-[#ff6818]/30',
                    kioskType === 'clock_in'
                      ? 'border-[#ff8a45]/40 bg-linear-to-br from-[#ffb400] to-[#ff4b0c] text-white shadow-[0_14px_32px_rgba(255,91,20,0.23)]'
                      : 'border-[#ff8a45]/40 bg-linear-to-br from-[#ffb400] to-[#ff4b0c] text-white shadow-[0_14px_32px_rgba(255,91,20,0.23)]'
                  )}
                >
                  <div className={cn(
                    'flex size-16 items-center justify-center rounded-full bg-white text-[#ff5a14] shadow-[0_10px_22px_rgba(17,24,39,0.10)] transition-transform group-hover:scale-105',
                    kioskType === 'clock_in'
                      ? ''
                      : ''
                  )}>
                    <LogIn className="size-8" />
                  </div>
                  <div className="text-center">
                    <p className="text-xl font-bold text-white">Clock In</p>
                    <p className="mt-1 text-sm font-semibold text-white">Barcode scan</p>
                  </div>
                </button>

                <button
                  type="button"
                  onClick={() => setKioskType(kioskType === 'clock_out' ? null : 'clock_out')}
                  className={cn(
                    'group flex min-h-[154px] flex-col items-center justify-center gap-3 rounded-xl border px-4 py-5 transition-all duration-200 hover:scale-[1.01] active:scale-[0.99] focus:outline-none focus:ring-2 focus:ring-[#ff6818]/20',
                    kioskType === 'clock_out'
                      ? 'border-[#ff8a45] bg-[#fff4ed] ring-1 ring-[#ff8a45]/20 shadow-[0_8px_24px_rgba(15,23,42,0.07)]'
                      : 'border-[#e1e4ea] bg-white text-[#111827] shadow-[0_8px_24px_rgba(15,23,42,0.07)] hover:border-[#ffb28a]'
                  )}
                >
                  <div className={cn(
                    'flex size-16 items-center justify-center rounded-full transition-transform group-hover:scale-105',
                    kioskType === 'clock_out'
                      ? 'bg-white text-[#111827]'
                      : 'bg-[#f0f1f3] text-[#111827]'
                  )}>
                    <LogOut className="size-8" />
                  </div>
                  <div className="text-center">
                    <p className="text-xl font-bold text-[#111827]">Clock Out</p>
                    <p className="mt-1 text-sm font-semibold text-[#6b7280]">Barcode scan</p>
                  </div>
                </button>
              </div>

              {!kioskType && (
                <p className="text-center text-xs font-medium text-[#ff5a14]">
                  Select Clock In or Clock Out first, then scan.
                </p>
              )}

              <ScannerInput
                onScan={handleScan}
                submitting={submitting}
                error={error}
                successResult={scanResult}
                onFileAttendanceCorrection={goFileAttendanceCorrectionPortal}
                theme="light"
              />
            </div>
          ) : !kioskType ? (
            /* ── Face recognition mode: choose action first ── */
            <div className="space-y-3">
              <p className="text-center text-sm text-[#2f3542]">
                Use <span className="font-semibold text-[#ff4f0b]">Face Recognition</span> for instant attendance - no badge required
              </p>
              <div className="grid grid-cols-2 gap-3">
                <button
                  type="button"
                  onClick={() => openCapture('clock_in')}
                  className="group flex min-h-[154px] flex-col items-center justify-center gap-3 rounded-xl border border-[#ff8a45]/40 bg-linear-to-br from-[#ffb400] to-[#ff4b0c] px-4 py-5 text-white shadow-[0_14px_32px_rgba(255,91,20,0.23)] transition-all duration-200 hover:scale-[1.01] active:scale-[0.99] focus:outline-none focus:ring-2 focus:ring-[#ff6818]/30"
                >
                  <div className="flex size-16 items-center justify-center rounded-full bg-white text-[#ff5a14] shadow-[0_10px_22px_rgba(17,24,39,0.10)] transition-transform group-hover:scale-105">
                    <LogIn className="size-8" />
                  </div>
                  <div className="text-center">
                    <p className="text-xl font-bold text-white">Clock In</p>
                    <p className="mt-1 text-sm font-semibold text-white">Face scan</p>
                  </div>
                </button>
                <button
                  type="button"
                  onClick={() => openCapture('clock_out')}
                  className="group flex min-h-[154px] flex-col items-center justify-center gap-3 rounded-xl border border-[#e1e4ea] bg-white px-4 py-5 text-[#111827] shadow-[0_8px_24px_rgba(15,23,42,0.07)] transition-all duration-200 hover:scale-[1.01] hover:border-[#ffb28a] active:scale-[0.99] focus:outline-none focus:ring-2 focus:ring-[#ff6818]/20"
                >
                  <div className="flex size-16 items-center justify-center rounded-full bg-[#f0f1f3] text-[#111827] transition-transform group-hover:scale-105">
                    <LogOut className="size-8" />
                  </div>
                  <div className="text-center">
                    <p className="text-xl font-bold text-[#111827]">Clock Out</p>
                    <p className="mt-1 text-sm font-semibold text-[#6b7280]">Face scan</p>
                  </div>
                </button>
              </div>
            </div>
          ) : (
            /* ── Face recognition capture ── */
            <div className="space-y-3">
              <p className="text-center text-xs text-[#6b7280]">
                Look into the camera and hold still during the guided liveness check.
              </p>
              <FaceRekognitionLiveness
                kioskMode
                kioskType={kioskType}
                onKioskAttendanceCorrection={(kc) => {
                  setKioskCorrectionModal({
                    open: true,
                    reason: kc?.reason ?? 'already_timed_in',
                    employeeId: kc?.employee_id ?? null,
                    employeeName: kc?.employee_name ?? null,
                    employeeProfileImageUrl: kc?.employee_profile_image_url ?? null,
                    employeeProfileImage: kc?.employee_profile_image ?? null,
                  })
                }}
                onKioskSuccess={(data) => {
                  const kc = data?.kiosk_correction
                  setSummaryModal({
                    open: true,
                    employeeId: data.employee_id ?? null,
                    employeeName: data.employee_name ?? null,
                    employeeProfileImageUrl: data.employee_profile_image_url ?? null,
                    employeeProfileImage: data.employee_profile_image ?? null,
                    type: kioskType,
                    recordedAt: data.attendance?.created_at ?? new Date().toISOString(),
                    status: data.attendance?.status ?? null,
                    lateMinutes: data.attendance?.late_minutes ?? null,
                    lateLabel: data.attendance?.late_label ?? null,
                    undertimeMinutes: data.attendance?.undertime_minutes ?? null,
                    correctionSuggested: Boolean(kc?.suggested),
                    correctionReason: kc?.reason ?? null,
                  })
                  setKioskType(null)
                  fetchRecent()
                }}
                onKioskCancel={() => { setKioskType(null); setError(null); setScanResult(null) }}
                onKioskErrorStateChange={setKioskFaceInError}
                hideInstruction
              />
              {!kioskFaceInError && (
                <div className="flex justify-center">
                  <button
                    type="button"
                    onClick={() => { setKioskType(null); setError(null); setScanResult(null) }}
                    className="rounded-xl border border-[#e1e4ea] bg-white px-4 py-1.5 text-xs font-medium text-[#6b7280] shadow-sm transition-all hover:border-[#ffb28a] hover:text-[#111827]"
                  >
                    ← Back
                  </button>
                </div>
              )}
            </div>
          )}

          <div className="h-px bg-[#e3e5ea]" aria-hidden />

          {/* ── Recent Activity feed — social proof, higher priority ── */}
          <div className="space-y-3 pt-0">
            <div className="flex items-center justify-between">
              <p className="text-xs font-bold uppercase tracking-wider text-[#5d6472]">Recent Activity</p>
              {hasMore && !recentExpanded && (
                <button
                  type="button"
                  onClick={() => setRecentExpanded(true)}
                  className="text-sm font-semibold text-[#ff4f0b] transition-colors hover:text-[#db3f04]"
                >
                  View More →
                </button>
              )}
              {recentExpanded && (
                <button
                  type="button"
                  onClick={() => setRecentExpanded(false)}
                  className="text-[11px] font-medium text-[#6b7280] transition-colors hover:text-[#111827]"
                >
                  Collapse
                </button>
              )}
            </div>

            {recentLogs.length === 0 ? (
              <p className="rounded-xl border border-[#e1e4ea] bg-white py-6 text-center text-xs text-[#6b7280] shadow-sm">
                No activity yet · use the scanner above to clock in or out
              </p>
            ) : (
              <ul className="space-y-1.5">
                {visibleLogs.map((log) => {
                  const initials = (log.employee_name || '?').trim().split(/\s+/).map((n) => n[0]).join('').toUpperCase().slice(0, 2)
                  const isLate = log.status === 'late'
                  const StatusIcon = log.type === 'clock_in' ? LogIn : LogOut
                  const statusIconCls = 'text-[#ff5a14]'
                  return (
                    <li
                      key={log.id}
                      className="flex items-center gap-3 rounded-xl border border-[#e1e4ea] bg-white px-4 py-2.5 shadow-[0_4px_15px_rgba(15,23,42,0.05)] transition-colors hover:border-[#ffdccb]"
                    >
                      {/* Avatar — same URL resolution as admin / employee profile */}
                      <Avatar className="size-10 shrink-0 rounded-full border border-[#fff0e7] shadow-sm ring-2 ring-[#fff4ed]">
                        <AvatarImage
                          src={
                            kioskAttendanceAvatarSrc({
                              employeeProfileImageUrl: log.employee_profile_image_url,
                              employeeProfileImage: log.employee_profile_image,
                            }) || undefined
                          }
                          alt=""
                          className="object-cover"
                          referrerPolicy="no-referrer"
                        />
                        <AvatarFallback
                          className={cn(
                            'rounded-full text-[11px] font-bold',
                            getEmployeeAvatarColorClass(log.employee_id, log.employee_name),
                          )}
                        >
                          {initials}
                        </AvatarFallback>
                      </Avatar>
                      {/* Name + action with icon */}
                      <div className="min-w-0 flex-1">
                        <p className="truncate text-sm font-bold leading-tight text-[#111827]">{log.employee_name || '—'}</p>
                        <p className="flex items-center gap-1.5 text-xs text-[#4b5563]">
                          <StatusIcon className={cn('size-3 shrink-0', statusIconCls)} />
                          <span>{log.type === 'clock_in' ? 'Clocked in' : 'Clocked out'}</span>
                          {log.company?.name && (
                            <>
                              <span className="opacity-50">·</span>
                              <span className="inline-flex min-w-0 max-w-[min(140px,45vw)] items-center gap-1.5">
                                {log.company?.logo_url ? (
                                  <img
                                    src={log.company.logo_url}
                                    alt=""
                                    className="size-4 shrink-0 rounded-sm bg-white object-contain ring-1 ring-[#e1e4ea]"
                                    loading="lazy"
                                    referrerPolicy="no-referrer"
                                    onError={(e) => { e.currentTarget.style.display = 'none' }}
                                  />
                                ) : null}
                                <span className="truncate">{log.company.name}</span>
                              </span>
                            </>
                          )}
                        </p>
                      </div>
                      {/* Date + time + status badge */}
                      <div className="flex shrink-0 flex-col items-end gap-1">
                        <span className="text-[10px] font-medium text-[#6b7280]">{formatKioskDateLabel(log.created_at)}</span>
                        <span className="font-mono text-[12px] font-bold text-[#111827]">{formatKioskTime(log.created_at)}</span>
                        {log.status === 'on_time' && (
                          <span className="flex items-center gap-1.5 text-[10px] font-bold text-[#ff5a14]">
                            <CheckCircle2 className="size-3" aria-hidden />
                            {log.late_label || 'Present'}
                          </span>
                        )}
                        {isLate && (
                          <span className="flex items-center gap-1.5 text-[10px] font-bold text-[#ff5a14]">
                            <span className="size-1.5 rounded-full bg-[#ff5a14]" aria-hidden />
                            {log.late_label || 'Late'}
                          </span>
                        )}
                        {log.status === 'half_day' && (
                          <span className="flex items-center gap-1.5 text-[10px] font-bold text-[#ff5a14]">
                            <span className="size-1.5 rounded-full bg-[#ff5a14]" aria-hidden />
                            {log.late_label || 'Half Day'}
                          </span>
                        )}
                      </div>
                    </li>
                  )
                })}
              </ul>
            )}
          </div>

          {/* Features footer */}
          <div className="mb-2 flex flex-wrap items-center justify-center gap-x-3 gap-y-1 rounded-xl border border-[#e1e4ea] bg-white/90 py-1.5 px-4 text-[11px] font-medium text-[#5f6673] shadow-sm">
            {FEATURES.map((f, i) => (
              <span key={f} className="flex items-center gap-2">
                {i > 0 && <span aria-hidden>·</span>}
                <span>{f}</span>
              </span>
            ))}
          </div>

          <p className="pb-3 text-center text-xs text-[#5f6673]">
            Developed by{' '}
            <a
              href="https://www.facebook.com/kurtjerelle"
              target="_blank"
              rel="noopener noreferrer"
              className="font-medium text-[#ff4f0b] transition-colors hover:text-[#db3f04]"
            >
              Kurt Jerelle Minoza
            </a>
          </p>

        </div>
      </div>{/* end scrollable body */}

      {/* Summary modal: branded professional confirmation — same layout for Clock In / Clock Out (Welcome vs Goodbye) */}
      <Dialog open={summaryModal.open} onOpenChange={(open) => !open && closeSummaryModal()}>
        <DialogContent
          overlayClassName="bg-slate-900/45 backdrop-blur-sm"
          className="max-w-[min(100%,440px)] overflow-hidden rounded-2xl border border-slate-200 bg-white p-0 shadow-[0_25px_50px_-12px_rgba(15,23,42,0.28)] sm:max-w-[440px]"
          innerClassName="gap-0 overflow-x-hidden p-0 sm:p-0"
          closeButtonClassName={cn(
            'right-4 top-[14px] z-30 rounded-lg border border-slate-200 bg-white text-slate-600 shadow-sm',
            'hover:bg-slate-50 focus-visible:ring-2 focus-visible:ring-[#ff6818]/35 focus-visible:ring-offset-0',
          )}
        >
          <KioskAttendanceModalBrandBar variant={kioskSummaryIsClockOut ? 'clock_out' : 'clock_in'} />
          <div
            role="document"
            aria-label="Attendance confirmation"
            className="relative px-6 pb-9 pt-6 text-center text-slate-900 sm:px-10 sm:pb-10 sm:pt-7"
          >
            <div className="relative mx-auto flex max-w-sm flex-col items-center">
              <div className="relative mb-6 shrink-0">
                <div
                  className={cn(
                    'pointer-events-none absolute -inset-2 rounded-full blur-2xl',
                    kioskSummaryIsClockOut ? 'bg-slate-200/60' : 'bg-orange-100/85',
                  )}
                  aria-hidden
                />
                <Avatar
                  className={cn(
                    'relative size-[112px] rounded-full border-2 border-white shadow-lg',
                    kioskSummaryIsClockOut
                      ? 'ring-[3px] ring-slate-400/45 ring-offset-2 ring-offset-white'
                      : 'ring-[3px] ring-[#ff9248]/65 ring-offset-2 ring-offset-white',
                  )}
                >
                  <AvatarImage
                    key={kioskSummaryPhotoSrc || `fallback-${summaryModal.employeeId}`}
                    src={kioskSummaryPhotoSrc || undefined}
                    alt=""
                    className="object-cover"
                    referrerPolicy="no-referrer"
                  />
                  <AvatarFallback className="rounded-full bg-linear-to-br from-amber-600 via-orange-700 to-orange-950 text-[1.85rem] font-bold tracking-tight text-white">
                    {kioskSummaryName
                      .split(/\s+/)
                      .map((n) => n[0])
                      .join('')
                      .toUpperCase()
                      .slice(0, 2) || '?'}
                  </AvatarFallback>
                </Avatar>
              </div>

              <h2 className="mb-1 max-w-[22ch] text-balance text-[1.65rem] font-bold leading-snug tracking-tight text-slate-900 sm:text-[1.85rem]">
                {kioskSummaryIsClockOut ? (
                  <>
                    Goodbye,
                    <br />
                    <span className="text-slate-800">{kioskSummaryName}</span>
                  </>
                ) : (
                  <>
                    Welcome,
                    <br />
                    <span className="text-slate-800">{kioskSummaryName}</span>
                  </>
                )}
              </h2>
              <p className="mb-6 text-sm text-slate-500">
                {kioskSummaryIsClockOut
                  ? 'Your clock-out time has been recorded securely.'
                  : 'Your clock-in time has been recorded securely.'}
              </p>

              <div className="flex w-full flex-col items-center">
                <div
                  className={cn(
                    'mb-2 flex items-center justify-center gap-2',
                    kioskSummaryIsClockOut ? 'text-slate-600' : 'text-[#c2410c]',
                  )}
                >
                  {kioskSummaryIsClockOut ? (
                    <LogOut className="size-5 shrink-0" strokeWidth={2.25} aria-hidden />
                  ) : (
                    <CheckCircle2 className="size-5 shrink-0 text-[#ff6818]" strokeWidth={2.25} aria-hidden />
                  )}
                  <span className="text-base font-bold">
                    {kioskSummaryIsClockOut ? 'Clocked out' : 'Clocked in'}
                  </span>
                </div>

                {summaryModal.recordedAt ? (
                  <p className="font-mono text-[2.05rem] font-bold tabular-nums tracking-tight text-slate-900 sm:text-[2.25rem]">
                    {formatKioskTime(summaryModal.recordedAt)}
                  </p>
                ) : (
                  <p className="text-4xl font-bold text-slate-400">—</p>
                )}

                <div className="mt-4 flex min-h-10 w-full flex-wrap items-center justify-center gap-2">
                  {!kioskSummaryIsClockOut && summaryModal.status === 'on_time' && (
                    <span className="rounded-full bg-emerald-50 px-3.5 py-1.5 text-xs font-semibold text-emerald-900 ring-1 ring-emerald-100">
                      {summaryModal.lateLabel || 'On time'}
                    </span>
                  )}
                  {!kioskSummaryIsClockOut && summaryModal.status === 'late' && (
                    <span className="rounded-full bg-amber-50 px-3.5 py-1.5 text-xs font-semibold text-amber-950 ring-1 ring-amber-100">
                      {summaryModal.lateLabel
                        ? summaryModal.lateLabel
                        : summaryModal.lateMinutes != null
                          ? `${summaryModal.lateMinutes} min late`
                          : 'Late'}
                    </span>
                  )}
                  {!kioskSummaryIsClockOut && summaryModal.status === 'half_day' && (
                    <span className="rounded-full bg-sky-50 px-3.5 py-1.5 text-xs font-semibold text-sky-950 ring-1 ring-sky-100">
                      Half Day
                    </span>
                  )}
                  {kioskSummaryIsClockOut &&
                    summaryModal.status === 'undertime' &&
                    summaryModal.undertimeMinutes != null &&
                    summaryModal.undertimeMinutes > 0 && (
                      <span className="rounded-full bg-orange-50 px-3.5 py-1.5 text-xs font-semibold text-orange-950 ring-1 ring-orange-100">
                        Undertime ({summaryModal.undertimeMinutes} min)
                      </span>
                    )}
                  {kioskSummaryIsClockOut && summaryModal.status === 'present' && (
                    <span className="rounded-full bg-emerald-50 px-3.5 py-1.5 text-xs font-semibold text-emerald-900 ring-1 ring-emerald-100">
                      On time (no undertime)
                    </span>
                  )}
                </div>
              </div>

              {summaryModal.correctionSuggested && (
                <div className="mt-6 w-full max-w-sm rounded-xl border border-amber-200 bg-amber-50/90 px-4 py-3 text-left shadow-sm">
                  <p className="flex items-start gap-2 text-[13px] font-medium leading-snug text-amber-950">
                    <ClipboardList className="mt-0.5 size-4 shrink-0 text-amber-700" aria-hidden />
                    <span>
                      Clock-out was saved without a recorded clock-in today. File an attendance correction after signing in so
                      your official record matches Daily Computation.
                    </span>
                  </p>
                  <Button
                    type="button"
                    variant="outline"
                    className="mt-3 h-11 w-full rounded-xl border-amber-300 bg-white text-[15px] font-semibold text-amber-950 hover:bg-amber-100"
                    onClick={() => {
                      closeSummaryModal()
                      goFileAttendanceCorrectionPortal()
                    }}
                  >
                    File correction
                  </Button>
                </div>
              )}

              {kioskAutoCloseSeconds > 0 && summaryModal.open && !summaryModal.correctionSuggested && (
                <p className="mt-6 text-sm font-medium text-slate-400" role="status" aria-live="polite">
                  Closing in {kioskAutoCloseSeconds} second{kioskAutoCloseSeconds === 1 ? '' : 's'}…
                </p>
              )}

              <DialogFooter className="mt-6 w-full flex-col gap-3 border-t border-slate-100 p-0 pt-6 sm:flex-row sm:justify-stretch sm:gap-4">
                <Button
                  type="button"
                  variant="secondary"
                  onClick={closeSummaryModal}
                  className="h-12 min-h-[48px] w-full flex-1 rounded-xl border border-slate-200 bg-white text-[15px] font-semibold text-slate-700 shadow-sm hover:bg-slate-50 sm:flex-1"
                >
                  Scan again
                </Button>
                <Button
                  type="button"
                  onClick={goToDashboard}
                  className="h-12 min-h-[48px] w-full flex-1 rounded-xl border border-[#ff8533]/40 bg-linear-to-br from-[#ff8a44] to-[#ff5410] text-[15px] font-semibold text-white shadow-md hover:from-[#ff7f36] hover:to-[#ea4a0f] active:scale-[0.99] sm:flex-1"
                >
                  <Home className="mr-2 size-[1.1rem]" aria-hidden />
                  Go to Dashboard
                </Button>
              </DialogFooter>
            </div>
          </div>
        </DialogContent>
      </Dialog>

      {/* Kiosk: duplicate clock-in (face / QR) → correction filing instead of forcing another punch */}
      <Dialog open={kioskCorrectionModal.open} onOpenChange={(open) => !open && closeKioskCorrectionModal()}>
        <DialogContent
          overlayClassName="bg-black/40 backdrop-blur-md"
          className={cn(
            'max-w-[min(100%,440px)] overflow-hidden rounded-[20px] border border-amber-400/25',
            'bg-white/8 shadow-[inset_0_1px_0_rgba(255,255,255,0.12),0_24px_48px_rgba(0,0,0,0.35)]',
            'backdrop-blur-2xl sm:max-w-[440px]',
          )}
          innerClassName="gap-0 overflow-x-hidden p-0 sm:p-0"
          closeButtonClassName={cn(
            'rounded-lg border border-white/25 bg-white/15 text-white shadow-md backdrop-blur-sm',
            'hover:bg-white/22 hover:text-white focus-visible:ring-2 focus-visible:ring-amber-400/50 focus-visible:ring-offset-0',
          )}
        >
          <div className="px-7 pb-8 pt-12 text-center text-white sm:px-10">
            <div className="mx-auto mb-5 flex size-14 items-center justify-center rounded-full border border-amber-400/35 bg-amber-500/15">
              <ClipboardList className="size-7 text-amber-200" aria-hidden />
            </div>
            {(kioskCorrectionModal.employeeName || '').trim() && (
              <p className="mb-1 text-sm font-medium text-white/70">{kioskCorrectionModal.employeeName.trim()}</p>
            )}
            <h2 className="mb-3 text-xl font-bold tracking-tight text-white">{kioskCorrectionConflictTitle}</h2>
            <p className="text-sm leading-relaxed text-white/75">{kioskCorrectionConflictBody}</p>
            <DialogFooter className="mt-8 flex-col gap-3 border-0 p-0 sm:flex-row sm:justify-stretch">
              <Button
                type="button"
                variant="secondary"
                onClick={closeKioskCorrectionModal}
                className="h-12 w-full rounded-xl border border-white/15 bg-white/10 text-[15px] font-semibold text-white hover:bg-white/14"
              >
                Scan again
              </Button>
              <Button
                type="button"
                onClick={() => {
                  closeKioskCorrectionModal()
                  goFileAttendanceCorrectionPortal()
                }}
                className="h-12 w-full rounded-xl border border-amber-400/35 bg-amber-600 text-[15px] font-semibold text-white hover:bg-amber-500"
              >
                File correction
              </Button>
            </DialogFooter>
          </div>
        </DialogContent>
      </Dialog>
    </div>
  )
}

// —— Right panel: shared form primitives ——
function AuthInput({
  label,
  type = 'text',
  name,
  placeholder,
  value = '',
  onChange,
  onBlur,
  error,
  required,
  icon: Icon,
  ...rest
}) {
  const hasError = Boolean(error)
  const isPassword = type === 'password'
  const [showPassword, setShowPassword] = useState(false)
  const inputType = isPassword && showPassword ? 'text' : type

  return (
    <div className="space-y-2">
      <Label htmlFor={name} className="text-sm font-bold text-[#0b0f19]">
        {label}
        {required && <span className="ml-2 text-[#ff5a14]" aria-hidden>*</span>}
      </Label>
      <div className="relative">
        {Icon && (
          <Icon className="pointer-events-none absolute left-4 top-1/2 size-5 -translate-y-1/2 text-[#ff5a14]" aria-hidden />
        )}
        <Input
          id={name}
          type={inputType}
          name={name}
          placeholder={placeholder}
          value={value}
          onChange={onChange}
          onBlur={onBlur}
          required={required}
          aria-invalid={hasError}
          aria-describedby={hasError ? `${name}-error` : undefined}
        className={cn(
          'h-[54px] rounded-xl border-[#dfe2e8] bg-white px-4 text-base text-[#111827] shadow-[0_2px_8px_rgba(15,23,42,0.04)] transition-colors duration-200 placeholder:text-[#7b8190] focus-visible:border-[#ff9b67] focus-visible:ring-[#ff6818]/15',
          Icon && 'pl-14',
          isPassword && 'pr-11',
          hasError && 'border-destructive focus-visible:ring-destructive/20'
        )}
          {...rest}
        />
        {isPassword && (
          <button
            type="button"
            tabIndex={-1}
            onClick={() => setShowPassword((v) => !v)}
            className="absolute right-3 top-1/2 -translate-y-1/2 rounded-md p-1.5 text-[#ff5a14] transition-colors hover:bg-[#fff4ed] hover:text-[#db3f04] focus:outline-none focus:ring-2 focus:ring-[#ff6818]/20"
            aria-label={showPassword ? 'Hide password' : 'Show password'}
          >
            {showPassword ? (
              <EyeOff className="size-4.5" aria-hidden />
            ) : (
              <Eye className="size-4.5" aria-hidden />
            )}
          </button>
        )}
      </div>
      {hasError && (
        <p id={`${name}-error`} className="flex items-center gap-1.5 text-sm text-destructive" role="alert">
          <span className="size-1.5 shrink-0 rounded-full bg-destructive" aria-hidden />
          {error}
        </p>
      )}
    </div>
  )
}

function AuthCheckbox({ label, name, checked, onCheckedChange }) {
  return (
    <div className="flex items-center gap-3">
      <Checkbox
        id={name}
        name={name}
        checked={checked}
        onCheckedChange={onCheckedChange}
        aria-label={label}
      />
      <Label htmlFor={name} className="cursor-pointer text-sm font-medium text-[#6b7280]">
        {label}
      </Label>
    </div>
  )
}

// —— Login: credentials only (QR scanner tab removed) ——
function LoginFormWithTabs({ onSuccess, onError }) {
  return (
    <div className="space-y-5">
      <LoginForm onSuccess={onSuccess} onError={onError} />
    </div>
  )
}

// —— Auth forms ——
function LoginForm({ onSuccess, onError }) {
  const navigate = useNavigate()
  const [loading, setLoading] = useState(false)
  const [loginValue, setLoginValue] = useState('')
  const [password, setPassword] = useState('')
  const [remember, setRemember] = useState(false)
  const [errors, setErrors] = useState({ login: '', password: '' })

  function validateAll() {
    const loginErr = validateLoginIdentifier(loginValue)
    const passwordErr = validatePassword(password, false)
    setErrors({ login: loginErr, password: passwordErr })
    return !loginErr && !passwordErr
  }

  function handleLoginChange(e) {
    const next = sanitizeLogin(e.target.value)
    setLoginValue(next)
    setErrors((prev) => ({ ...prev, login: validateLoginIdentifier(next) }))
  }

  function handlePasswordChange(e) {
    const next = sanitizePassword(e.target.value)
    setPassword(next)
    setErrors((prev) => ({ ...prev, password: validatePassword(next, false) }))
  }

  async function handleSubmit(e) {
    e.preventDefault()
    if (!validateAll()) return
    setLoading(true)
    onError?.('')
    try {
      const data = await login(loginValue.trim(), password, undefined, { remember })
      onSuccess?.(data?.user ?? null)
    } catch (err) {
      const message = String(err?.message || 'Login failed')
      const isTimeout = /timed out/i.test(message)
      onError?.(
        isTimeout
          ? 'Login request timed out. Please try again. If this keeps happening, check backend API health and Laravel logs.'
          : message
      )
    } finally {
      setLoading(false)
    }
  }

  return (
    <form className="space-y-5" onSubmit={handleSubmit}>
      <AuthInput
        label="Username or Email"
        type="text"
        name="login"
        placeholder="Enter username or work email"
        icon={User}
        value={loginValue}
        onChange={handleLoginChange}
        onBlur={() => setErrors((prev) => ({ ...prev, login: validateLoginIdentifier(loginValue) }))}
        error={errors.login}
        required
      />
      <AuthInput
        label="Password"
        type="password"
        name="password"
        placeholder="Enter your password"
        icon={LockKeyhole}
        value={password}
        onChange={handlePasswordChange}
        onBlur={() => setErrors((prev) => ({ ...prev, password: validatePassword(password, false) }))}
        error={errors.password}
        required
      />
      <div className="flex flex-wrap items-center justify-between gap-3">
        <AuthCheckbox
          label="Remember me"
          name="remember"
          checked={remember}
          onCheckedChange={(checked) => setRemember(checked === true)}
        />
        <button
          type="button"
          className="text-sm font-medium text-[#ff4f0b] underline-offset-2 transition-colors duration-200 hover:text-[#db3f04] hover:underline"
          onClick={() => navigate('/forgot-password')}
        >
          Forgot password?
        </button>
      </div>
      <Button
        type="submit"
        disabled={loading}
        className="h-[54px] w-full rounded-xl bg-linear-to-r from-[#ffb300] to-[#ff4b0c] py-3 text-base font-bold text-white shadow-[0_12px_24px_rgba(255,91,20,0.22)] ring-1 ring-[#ff7a2e]/20 transition-all duration-200 hover:brightness-105 active:scale-[0.99] focus-visible:ring-2 focus-visible:ring-[#ff6818]/35"
      >
        {loading ? 'Signing in…' : 'Sign in to Dashboard'}
      </Button>
    </form>
  )
}

// —— Right auth panel (shadcn Card + Tabs, desktop-friendly width) ——
function AuthPanel({ className, onSuccess, resetSuccess }) {
  const [authError, setAuthError] = useState('')

  function handleAuthSuccess(authUser = null) {
    setAuthError('')
    if (typeof onSuccess === 'function') {
      onSuccess(authUser)
    } else {
      window.location.reload()
    }
  }

  return (
    <div
      className={cn(
        'relative flex min-h-screen w-full flex-col items-center justify-center bg-[#fbfbfc] px-6 py-10 md:px-8 lg:px-10',
        className
      )}
    >
      <div className="relative z-10 w-full max-w-[min(100%,44rem)] lg:translate-y-6">

        {/* Duo-figure logo tile + separate headings (PNG omits cropped “HRIS” under art) */}
        <div className="mb-6 flex flex-col items-center gap-3 px-2 text-center">
          <LoginHrDualFigureMark />
          <h1 className="text-[2.85rem] font-black leading-none tracking-tight text-[#111318] md:text-[3.35rem]">HRIS</h1>
          <p className="text-base font-medium text-[#2d3138]">Human Resource Information System</p>
          <p className="text-[0.9375rem] font-semibold tracking-wide text-[#ff6818]">Secure. Intelligent. Automated.</p>
        </div>

        <Card className="w-full rounded-2xl border border-[#eceff3] bg-white/92 shadow-[0_24px_55px_rgba(15,23,42,0.12)] ring-1 ring-white/80 backdrop-blur-sm">
          <CardHeader className="px-9 pb-5 pt-9 sm:px-12">
            <CardTitle className="text-2xl font-black tracking-tight text-[#0b0f19]">Welcome back</CardTitle>
            <CardDescription className="pt-1 text-base text-[#6b7280]">
              Sign in to your dashboard to continue.
            </CardDescription>
          </CardHeader>
          <CardContent className="space-y-6 px-9 pb-10 pt-3 sm:px-12">
            {resetSuccess && (
              <p
                className="rounded-lg bg-emerald-50 px-3 py-2 text-sm font-medium text-emerald-800"
                role="status"
              >
                Password updated successfully. You can sign in now.
              </p>
            )}
            {authError && (
              <p className="rounded-lg bg-destructive/10 px-3 py-2 text-sm font-medium text-destructive" role="alert">
                {authError}
              </p>
            )}
            <LoginFormWithTabs onSuccess={handleAuthSuccess} onError={setAuthError} />
          </CardContent>
        </Card>

        <p className="mt-6 text-center text-sm text-[#6b7280]">© 2026 HRIS. All rights reserved.</p>
      </div>
    </div>
  )
}

// —— Login page: split screen with redirect on success ——
function LoginPageWrapper() {
  const navigate = useNavigate()
  const location = useLocation()
  const { user, loading, setUser } = useAuth()
  const [previewOpen, setPreviewOpen] = useState(false)

  const resetSuccess = Boolean(location?.state?.resetSuccess)
  const fromLocation = location?.state?.from
  const targetPath = (() => {
    const pathname = typeof fromLocation?.pathname === 'string' ? fromLocation.pathname : ''
    const search = typeof fromLocation?.search === 'string' ? fromLocation.search : ''
    const hash = typeof fromLocation?.hash === 'string' ? fromLocation.hash : ''
    const full = `${pathname}${search}${hash}`
    if (!full || full === '/' || full.startsWith('/login')) return null
    return full
  })()

  // Already logged in -> redirect to role dashboard
  if (!loading && user) {
    const path = targetPath || resolvePostLoginPath(user)
    return <Navigate to={path} replace />
  }

  function handleAuthSuccess(authUser = null) {
    const user = authUser || getStoredUser()
    if (!user) {
      navigate('/', { replace: true })
      return
    }
    setUser(user)
    const path = targetPath || resolvePostLoginPath(user)
    navigate(path, { replace: true })
    setTimeout(() => {
      if (window.location.pathname === '/login') {
        window.location.replace(path)
      }
    }, 150)
  }

  return (
    <div className="min-h-screen bg-[#fbfbfc] text-[#090d18]">
      <div className="flex flex-col lg:grid lg:grid-cols-2 lg:min-h-screen lg:items-stretch">
        <main className="order-1 min-h-screen shrink-0 lg:order-2">
          <AuthPanel onSuccess={handleAuthSuccess} resetSuccess={resetSuccess} />
        </main>
        <div className="order-2 flex justify-center border-t border-border bg-background px-4 py-3 lg:order-2 lg:hidden">
          <Button
            type="button"
            variant="ghost"
            onClick={() => setPreviewOpen((o) => !o)}
            className="gap-2 text-muted-foreground hover:text-foreground"
          >
            {previewOpen ? 'Hide system preview' : 'See how it works'}
            <svg
              className={cn('size-4 transition-transform duration-200', previewOpen && 'rotate-180')}
              fill="none"
              viewBox="0 0 24 24"
              stroke="currentColor"
            >
              <path strokeLinecap="round" strokeLinejoin="round" strokeWidth={2} d="M19 9l-7 7-7-7" />
            </svg>
          </Button>
        </div>
        <aside
          className={cn(
            'order-3 min-h-0 overflow-auto transition-all duration-300 lg:order-1 lg:flex lg:min-h-screen lg:flex-col lg:border-r lg:border-[#dcdee3] lg:bg-[#fbfbfc]',
            previewOpen ? 'block' : 'hidden'
          )}
        >
          <SmartDTRPreview className="lg:min-h-full lg:flex-1" />
        </aside>
      </div>
    </div>
  )
}

// —— Redirect / to dashboard or login by auth state ——
function HomeRedirect() {
  const { user, loading } = useAuth()
  if (loading) {
    return (
      <div className="flex min-h-screen items-center justify-center bg-background">
        <Loader2 className="size-8 animate-spin text-primary" aria-hidden />
      </div>
    )
  }
  if (user) {
    return <Navigate to={resolvePostLoginPath(user)} replace />
  }
  return <Navigate to="/login" replace />
}

function AccessDeniedToast() {
  useEffect(() => {
    try {
      if (sessionStorage.getItem('hr_access_denied') === '1') {
        sessionStorage.removeItem('hr_access_denied')
        toast.error('Access denied', {
          description:
            'You do not have access to the Admin area. You were redirected to your dashboard.',
        })
      }
      if (sessionStorage.getItem('employee_route_denied') === '1') {
        sessionStorage.removeItem('employee_route_denied')
        toast.error('Wrong workspace', {
          description:
            'Manager accounts use the Branch / Company / Department panel, not the employee app. You were redirected to your dashboard.',
        })
      }
    } catch {
      // ignore
    }
  }, [])
  return null
}

// —— App: router + auth + role-based routes ——
export default function App() {
  return (
    <BrowserRouter basename={routerBasename}>
      <AuthProvider>
        <ThemeProvider>
          <Toaster richColors position="top-right" closeButton />
          <AccessDeniedToast />
          <ErrorBoundary fullScreen>
            <Routes>
              <Route path="/" element={<HomeRedirect />} />
              <Route path="/login" element={<LoginPageWrapper />} />
              <Route path="/forgot-password" element={<ForgotPassword />} />
              <Route path="/verify-otp" element={<VerifyOtp />} />
              <Route path="/reset-password" element={<ResetPassword />} />
              <Route
                path="/admin"
                element={(
                  <ProtectedRoute variant="adminHr">
                    <HrPanelLayout />
                  </ProtectedRoute>
                )}
              >
                {HR_PANEL_CHILD_ROUTES}
              </Route>
              <Route path="/company" element={<HrPanelLayout />}>
                {HR_PANEL_CHILD_ROUTES}
              </Route>
              <Route path="/branch" element={<HrPanelLayout />}>
                {HR_PANEL_CHILD_ROUTES}
              </Route>
              <Route path="/department" element={<HrPanelLayout />}>
                {HR_PANEL_CHILD_ROUTES}
              </Route>
              <Route
                path="/employee"
                element={
                  <ProtectedRoute role="employee">
                    <EmployeeDashboardLayout />
                  </ProtectedRoute>
                }
              >
                <Route index element={<Navigate to="dashboard" replace />} />
                <Route path="dashboard" element={<EmployeeDashboard />} />
                <Route path="attendance" element={<EmployeeAttendance />} />
                <Route path="correction-requests" element={<EmployeeCorrectionRequests />} />
                <Route
                  path="schedule"
                  element={
                    <Suspense fallback={<MyScheduleRouteFallback />}>
                      <MySchedule />
                    </Suspense>
                  }
                />
                <Route path="qr" element={<EmployeeMyQr />} />
                <Route
                  path="reports"
                  element={
                    <Suspense fallback={<div className="p-6 text-muted-foreground">Loading reports…</div>}>
                      <EmployeeReportsPage />
                    </Suspense>
                  }
                />
                <Route path="requests" element={<EmployeeLeave />} />
                <Route
                  path="loans-deductions"
                  element={
                    <Suspense fallback={<MyScheduleRouteFallback />}>
                      <EmployeeLoansDeductionsPage />
                    </Suspense>
                  }
                />
                <Route path="overtime" element={<EmployeeOvertime />} />
                <Route path="profile" element={<EmployeeProfile />} />
                <Route path="profile/:employeeId" element={<EmployeeProfile />} />
                <Route path="payslips" element={<EmployeeMyPayslipsPage />} />
                <Route
                  path="payslips/view/:payslipId"
                  element={
                    <Suspense fallback={<div className="p-6 text-muted-foreground">Loading payslip…</div>}>
                      <AdminPayslipViewPage />
                    </Suspense>
                  }
                />
              </Route>
              <Route path="*" element={<Navigate to="/" replace />} />
            </Routes>
          </ErrorBoundary>
        </ThemeProvider>
      </AuthProvider>
    </BrowserRouter>
  )
}
