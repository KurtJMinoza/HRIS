import { Suspense, lazy, useState, useEffect, useRef, useCallback } from 'react'
import { motion } from 'framer-motion'
import { BrowserRouter, Navigate, Route, Routes, useNavigate, useLocation } from 'react-router-dom'

/** Matches Vite `base` (e.g. `/HR/` → `/HR`) so routes work when deployed under a subpath */
const routerBasename = import.meta.env.BASE_URL.replace(/\/$/, '') || undefined
import { Toaster, toast } from 'sonner'
import { LogIn, LogOut, Scan, ScanFace, Loader2, ChevronDown, ChevronUp, CheckCircle2, Home, Eye, EyeOff, ClipboardList } from 'lucide-react'
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
        <span className="text-[3.5rem] font-black leading-none tracking-tight text-white md:text-[4.5rem]">
          {timeMain}
        </span>
        {timeSuffix && (
          <span className="mb-1.5 self-end text-lg font-bold tracking-[0.15em] text-teal-300/70 md:text-xl">
            {timeSuffix}
          </span>
        )}
      </div>
      <span className="text-sm font-medium text-white/45">{date}</span>
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
  { value: 'qr_code', label: 'Barcode Scanner', icon: Scan },
  { value: 'face_recognition', label: 'Face Recognition', icon: ScanFace },
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
        toast.error('No match this attempt', {
          description:
            msg ||
            (kioskMode
              ? 'Try again with even lighting, or scan your QR code on this kiosk.'
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
        <DialogContent className="kiosk-glass border-white/20 bg-white/12 p-0 shadow-2xl backdrop-blur-xl sm:max-w-md">
          <div className="flex flex-col items-center gap-4 p-6 text-center">
            <CheckCircle2 className="size-14 text-emerald-400" aria-hidden />
            {faceSuccessSummary?.name && (
              <h3 className="text-xl font-bold text-white">
                Welcome, {faceSuccessSummary.name}
              </h3>
            )}
            <div className="space-y-1 text-sm text-white/80">
              {faceSuccessSummary?.typeLabel && (
                <span className="block font-medium capitalize">
                  Clocked {faceSuccessSummary.typeLabel}
                </span>
              )}
              {faceSuccessSummary?.recordedAt && (
                <span className="block text-white/70">
                  {formatKioskTime(faceSuccessSummary.recordedAt)}
                </span>
              )}
            </div>
            <p className="text-xs text-white/60">
              Attendance recorded. Closing automatically…
            </p>
            <Button onClick={closeFaceSuccessSummary} className="w-full rounded-xl bg-emerald-600 text-white hover:bg-emerald-500">
              <Home className="mr-2 size-4" />
              Go to Dashboard
            </Button>
          </div>
        </DialogContent>
      </Dialog>
    </div>
  )
}

function SmartDTRPreview({ className }) {
  const navigate = useNavigate()
  const [attendanceMode, setAttendanceMode] = useState('qr_code')
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
  const kioskSummaryHeadline = kioskSummaryIsClockOut
    ? `Goodbye, ${kioskSummaryName}`
    : `Welcome, ${kioskSummaryName}`
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
        'relative flex min-h-full w-full flex-col overflow-hidden',
        'bg-linear-to-b from-[#0d1117] via-[#0f1420] to-[#0d1117] text-white',
        className
      )}
    >
      {/* Subtle dot grid */}
      <div
        className="absolute inset-0 -z-10 opacity-[0.04]"
        style={{
          backgroundImage: `linear-gradient(rgba(255,255,255,.08) 1px, transparent 1px), linear-gradient(90deg, rgba(255,255,255,.08) 1px, transparent 1px)`,
          backgroundSize: '48px 48px',
        }}
        aria-hidden
      />
      {/* Subtle teal accent — minimal, no heavy glow */}
      <div
        className="pointer-events-none absolute left-1/2 top-0 -z-10 h-48 w-96 -translate-x-1/2 rounded-full opacity-40"
        style={{ background: 'radial-gradient(ellipse, rgba(20,184,166,0.05) 0%, transparent 70%)' }}
        aria-hidden
      />

      {/* ── Brand bar ── */}
      <div className="flex items-center justify-between px-6 pt-5 pb-0">
        <div className="inline-flex items-center gap-2">
          <div className="flex size-8 items-center justify-center rounded-lg border border-white/10 bg-teal-500/10">
            <Scan className="size-4 text-teal-400" />
          </div>
          <span className="text-sm font-bold tracking-tight text-white/90">SmartDTR</span>
        </div>
        <div className="inline-flex items-center gap-1.5 rounded-full border border-white/10 bg-white/5 px-2.5 py-1 text-[9px] font-bold uppercase tracking-widest text-white/60">
          <span className="relative flex size-1.5">
            <span className="absolute inset-0 animate-ping rounded-full bg-teal-400 opacity-40" />
            <span className="relative inline-flex size-1.5 rounded-full bg-teal-400" />
          </span>
          Live
        </div>
      </div>

      {/* ── Hero Clock ── */}
      <div className="flex flex-col items-center px-6 pt-5 pb-5">
        <RealTimeClock />
      </div>

      <div className="mx-6 h-px bg-white/[0.07]" aria-hidden />

      {/* ── Scrollable body ── */}
      <div className="flex-1 overflow-y-auto">
        <div className="space-y-5 px-6 pt-5 pb-2">

          {/* Mode segmented control — iOS-style filled vs outline */}
          <div className="flex items-center justify-center">
            <div
              className="inline-flex rounded-xl border border-white/10 bg-white/5 p-1"
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
                      'inline-flex items-center gap-2 rounded-lg px-5 py-2.5 text-xs font-semibold transition-all duration-200 focus:outline-none focus:ring-2 focus:ring-teal-400/50 focus:ring-offset-1 focus:ring-offset-transparent',
                      isActive
                        ? 'bg-teal-500/15 text-teal-300 border border-teal-400/20'
                        : 'text-white/50 hover:text-white/70 hover:bg-white/5 border border-transparent'
                    )}
                  >
                    <IconComponent className={cn('size-3.5 shrink-0', isActive ? 'text-teal-400' : '')} />
                    {mode.label}
                  </button>
                )
              })}
            </div>
          </div>

          {/* ── Action area ── */}
          {attendanceMode === 'qr_code' ? (
            /* ── Barcode scanner mode ── */
            <div className="space-y-3">
              {/* Clock In / Clock Out — explicit action required before scanning */}
              <div className="grid grid-cols-2 gap-3">
                <button
                  type="button"
                  onClick={() => setKioskType(kioskType === 'clock_in' ? null : 'clock_in')}
                  className={cn(
                    'group flex flex-col items-center justify-center gap-2 rounded-2xl border px-4 py-4 transition-all duration-200 hover:scale-[1.02] active:scale-[0.98] focus:outline-none focus:ring-2 focus:ring-teal-400/50',
                    kioskType === 'clock_in'
                      ? 'border-teal-400/30 bg-teal-500/15 ring-1 ring-teal-400/20'
                      : 'border-white/10 bg-teal-500/8 hover:bg-teal-500/12 hover:border-teal-400/20'
                  )}
                >
                  <div className={cn(
                    'flex size-10 items-center justify-center rounded-full border transition-colors',
                    kioskType === 'clock_in'
                      ? 'border-teal-400/30 bg-teal-500/20'
                      : 'border-white/10 bg-teal-500/10 group-hover:bg-teal-500/15'
                  )}>
                    <LogIn className="size-4 text-teal-300" />
                  </div>
                  <div className="text-center">
                    <p className="text-sm font-bold text-white">Clock In</p>
                    <p className="text-[10px] text-white/40 mt-0.5">
                      {kioskType === 'clock_in' ? 'Selected' : 'Tap to select'}
                    </p>
                  </div>
                </button>

                <button
                  type="button"
                  onClick={() => setKioskType(kioskType === 'clock_out' ? null : 'clock_out')}
                  className={cn(
                    'group flex flex-col items-center justify-center gap-2 rounded-2xl border px-4 py-4 transition-all duration-200 hover:scale-[1.02] active:scale-[0.98] focus:outline-none focus:ring-2 focus:ring-white/20',
                    kioskType === 'clock_out'
                      ? 'border-white/25 bg-white/10 ring-1 ring-white/15'
                      : 'border-white/10 bg-white/5 hover:bg-white/8'
                  )}
                >
                  <div className={cn(
                    'flex size-10 items-center justify-center rounded-full border transition-colors',
                    kioskType === 'clock_out'
                      ? 'border-white/20 bg-white/15'
                      : 'border-white/10 bg-white/8 group-hover:bg-white/12'
                  )}>
                    <LogOut className="size-4 text-white/55" />
                  </div>
                  <div className="text-center">
                    <p className="text-sm font-bold text-white/85">Clock Out</p>
                    <p className="text-[10px] text-white/35 mt-0.5">
                      {kioskType === 'clock_out' ? 'Selected' : 'Tap to select'}
                    </p>
                  </div>
                </button>
              </div>

              {!kioskType && (
                <p className="text-center text-xs font-medium text-teal-300/90">
                  Select Clock In or Clock Out first, then scan.
                </p>
              )}

              <ScannerInput
                onScan={handleScan}
                submitting={submitting}
                error={error}
                successResult={scanResult}
                onFileAttendanceCorrection={goFileAttendanceCorrectionPortal}
                theme="dark"
              />
            </div>
          ) : !kioskType ? (
            /* ── Face recognition mode: choose action first ── */
            <div className="space-y-3">
              <p className="text-center text-xs text-white/40">
                Use <span className="font-semibold text-teal-300">Face Recognition</span> for instant attendance — no badge required
              </p>
              <div className="grid grid-cols-2 gap-3">
                <button
                  type="button"
                  onClick={() => openCapture('clock_in')}
                  className="group flex flex-col items-center justify-center gap-2.5 rounded-2xl border border-teal-400/20 bg-teal-500/10 px-4 py-6 transition-all duration-200 hover:bg-teal-500/15 hover:border-teal-400/30 hover:scale-[1.02] active:scale-[0.98] focus:outline-none focus:ring-2 focus:ring-teal-400/50"
                >
                  <div className="flex size-12 items-center justify-center rounded-full border border-teal-400/20 bg-teal-500/15 transition-colors group-hover:bg-teal-500/20">
                    <LogIn className="size-5 text-teal-300" />
                  </div>
                  <div className="text-center">
                    <p className="text-base font-bold text-white">Clock In</p>
                    <p className="text-[10px] text-white/40 mt-0.5">Face scan</p>
                  </div>
                </button>
                <button
                  type="button"
                  onClick={() => openCapture('clock_out')}
                  className="group flex flex-col items-center justify-center gap-2.5 rounded-2xl border border-white/10 bg-white/5 px-4 py-6 transition-all duration-200 hover:bg-white/8 hover:border-white/20 hover:scale-[1.02] active:scale-[0.98] focus:outline-none focus:ring-2 focus:ring-white/20"
                >
                  <div className="flex size-12 items-center justify-center rounded-full border border-white/10 bg-white/8 transition-colors group-hover:bg-white/12">
                    <LogOut className="size-5 text-white/55" />
                  </div>
                  <div className="text-center">
                    <p className="text-base font-bold text-white/85">Clock Out</p>
                    <p className="text-[10px] text-white/35 mt-0.5">Face scan</p>
                  </div>
                </button>
              </div>
            </div>
          ) : (
            /* ── Face recognition capture ── */
            <div className="space-y-3">
              <p className="text-center text-[11px] text-white/40">
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
                    className="rounded-xl border border-white/15 px-4 py-1.5 text-xs font-medium text-white/55 transition-all hover:bg-white/8 hover:text-white/80"
                  >
                    ← Back
                  </button>
                </div>
              )}
            </div>
          )}

          <div className="h-px bg-white/[0.07]" aria-hidden />

          {/* ── Recent Activity feed — social proof, higher priority ── */}
          <div className="space-y-3.5 pt-1">
            <div className="flex items-center justify-between">
              <p className="text-xs font-bold uppercase tracking-wider text-white/55">Recent Activity</p>
              {hasMore && !recentExpanded && (
                <button
                  type="button"
                  onClick={() => setRecentExpanded(true)}
                  className="text-[11px] font-semibold text-teal-400/70 transition-colors hover:text-teal-300"
                >
                  View More →
                </button>
              )}
              {recentExpanded && (
                <button
                  type="button"
                  onClick={() => setRecentExpanded(false)}
                  className="text-[11px] font-medium text-white/30 transition-colors hover:text-white/50"
                >
                  Collapse
                </button>
              )}
            </div>

            {recentLogs.length === 0 ? (
              <p className="py-6 text-center text-xs text-white/40">
                No activity yet · use the scanner above to clock in or out
              </p>
            ) : (
              <ul className="space-y-2">
                {visibleLogs.map((log) => {
                  const initials = (log.employee_name || '?').trim().split(/\s+/).map((n) => n[0]).join('').toUpperCase().slice(0, 2)
                  const logMins = Number(log.late_minutes) || 0
                  const isLate = log.status === 'late'
                  const isVeryLate = isLate && logMins >= 120
                  const StatusIcon = log.type === 'clock_in' ? LogIn : LogOut
                  const statusIconCls = log.status === 'on_time' ? 'text-teal-400' : isLate ? (isVeryLate ? 'text-rose-400' : 'text-amber-400') : log.status === 'half_day' ? 'text-sky-400' : 'text-white/50'
                  return (
                    <li
                      key={log.id}
                      className="flex items-center gap-3.5 rounded-xl border border-white/10 bg-white/6 px-4 py-3 transition-colors hover:bg-white/10"
                    >
                      {/* Avatar — same URL resolution as admin / employee profile */}
                      <Avatar className="size-9 shrink-0 rounded-full border border-white/15 shadow-sm ring-2 ring-white/10">
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
                        <p className="truncate text-[13px] font-semibold leading-tight text-white/95">{log.employee_name || '—'}</p>
                        <p className="flex items-center gap-1.5 text-[11px] text-white/50">
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
                                    className="size-4 shrink-0 rounded-sm bg-white/10 object-contain ring-1 ring-white/10"
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
                        <span className="text-[10px] font-medium text-white/40">{formatKioskDateLabel(log.created_at)}</span>
                        <span className="font-mono text-[12px] font-semibold text-white/65">{formatKioskTime(log.created_at)}</span>
                        {log.status === 'on_time' && (
                          <span className="flex items-center gap-1.5 text-[10px] font-bold text-teal-400">
                            <CheckCircle2 className="size-3" aria-hidden />
                            {log.late_label || 'Present'}
                          </span>
                        )}
                        {isLate && (
                          <span className={cn('flex items-center gap-1.5 text-[10px] font-bold', isVeryLate ? 'text-rose-300' : 'text-amber-300')}>
                            <span className={cn('size-1.5 rounded-full', isVeryLate ? 'bg-rose-300' : 'bg-amber-300')} aria-hidden />
                            {log.late_label || 'Late'}
                          </span>
                        )}
                        {log.status === 'half_day' && (
                          <span className="flex items-center gap-1.5 text-[10px] font-bold text-sky-300">
                            <span className="size-1.5 rounded-full bg-sky-300" aria-hidden />
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
          <div className="flex flex-wrap items-center justify-center gap-x-3 gap-y-1 rounded-xl border border-white/8 bg-white/3 py-2 px-4 text-[10px] text-white/25 mb-3">
            {FEATURES.map((f, i) => (
              <span key={f} className="flex items-center gap-2">
                {i > 0 && <span aria-hidden>·</span>}
                <span>{f}</span>
              </span>
            ))}
          </div>

          <p className="pb-4 text-center text-[11px] text-white/45">
            Developed by{' '}
            <a
              href="https://www.facebook.com/kurtjerelle"
              target="_blank"
              rel="noopener noreferrer"
              className="font-medium text-teal-300 transition-colors hover:text-teal-200"
            >
              Kurt Jerelle Minoza
            </a>
          </p>

        </div>
      </div>{/* end scrollable body */}

      {/* Summary modal: glass kiosk success — face / attendance punch + 2s auto-close */}
      <Dialog open={summaryModal.open} onOpenChange={(open) => !open && closeSummaryModal()}>
        <DialogContent
          overlayClassName="bg-black/40 backdrop-blur-md"
          className={cn(
            'max-w-[min(100%,440px)] overflow-hidden rounded-[20px] border border-white/25',
            'bg-white/8 shadow-[inset_0_1px_0_rgba(255,255,255,0.12),0_24px_48px_rgba(0,0,0,0.35)]',
            'backdrop-blur-2xl sm:max-w-[440px]',
          )}
          innerClassName="gap-0 overflow-x-hidden p-0 sm:p-0"
          closeButtonClassName={cn(
            'rounded-lg border border-white/25 bg-white/15 text-white shadow-md backdrop-blur-sm',
            'hover:bg-white/22 hover:text-white focus-visible:ring-2 focus-visible:ring-emerald-400/50 focus-visible:ring-offset-0',
          )}
        >
          <motion.div
            role="document"
            aria-label="Attendance confirmation"
            initial={{ opacity: 0, y: 14 }}
            animate={{ opacity: 1, y: 0 }}
            transition={{ duration: 0.4, ease: [0.23, 1, 0.32, 1] }}
            className="relative px-7 pb-9 pt-14 text-center text-white sm:px-12 sm:pb-10 sm:pt-16"
          >
            {/* Soft glow + particles — green for clock-in, cool neutral for clock-out */}
            <div
              className={cn(
                'pointer-events-none absolute left-1/2 top-28 h-44 w-44 -translate-x-1/2 rounded-full blur-3xl',
                kioskSummaryIsClockOut ? 'bg-slate-400/10' : 'bg-emerald-500/12',
              )}
              aria-hidden
            />
            <div
              className={cn(
                'pointer-events-none absolute left-[18%] top-36 size-2 blur-[0.5px]',
                kioskSummaryIsClockOut ? 'bg-slate-300/40' : 'bg-emerald-400/50',
              )}
              aria-hidden
            />
            <div
              className={cn(
                'pointer-events-none absolute right-[20%] top-32 size-1.5 blur-[0.5px]',
                kioskSummaryIsClockOut ? 'bg-slate-400/35' : 'bg-teal-300/45',
              )}
              aria-hidden
            />
            <div
              className="pointer-events-none absolute left-[22%] top-44 size-1 rounded-full bg-white/25 blur-[0.5px]"
              aria-hidden
            />

            <div className="relative mx-auto flex max-w-sm flex-col items-center">
              <div className="relative mb-8 shrink-0">
                <div
                  className={cn(
                    'absolute -inset-3 rounded-full opacity-90 blur-xl',
                    kioskSummaryIsClockOut
                      ? 'bg-linear-to-br from-slate-400/20 via-slate-500/10 to-transparent'
                      : 'bg-linear-to-br from-emerald-400/25 via-emerald-500/10 to-transparent',
                  )}
                  aria-hidden
                />
                <Avatar
                  className={cn(
                    'size-[120px] rounded-full border-2 border-white/25 shadow-[0_12px_40px_rgba(0,0,0,0.25)]',
                    'ring-[3px] ring-emerald-400/70 ring-offset-4 ring-offset-transparent',
                  )}
                >
                  <AvatarImage
                    key={kioskSummaryPhotoSrc || `fallback-${summaryModal.employeeId}`}
                    src={kioskSummaryPhotoSrc || undefined}
                    alt=""
                    className="object-cover"
                    referrerPolicy="no-referrer"
                  />
                  <AvatarFallback className="rounded-full bg-linear-to-br from-amber-600 via-orange-800 to-amber-950 text-[2rem] font-bold tracking-tight text-white">
                    {kioskSummaryName
                      .split(/\s+/)
                      .map((n) => n[0])
                      .join('')
                      .toUpperCase()
                      .slice(0, 2) || '?'}
                  </AvatarFallback>
                </Avatar>
              </div>

              <h2
                className={cn(
                  'mb-6 max-w-[20ch] text-balance text-[1.75rem] font-bold leading-[1.2] tracking-tight text-white',
                  'sm:text-[2rem]',
                )}
              >
                {kioskSummaryHeadline}
              </h2>

              <div className="flex w-full flex-col items-center">
                <div
                  className={cn(
                    'mb-3 flex items-center justify-center gap-2.5',
                    kioskSummaryIsClockOut ? 'text-slate-200' : 'text-emerald-400',
                  )}
                >
                  {kioskSummaryIsClockOut ? (
                    <LogOut className="size-[1.35rem] shrink-0 opacity-90" strokeWidth={2.25} aria-hidden />
                  ) : (
                    <CheckCircle2 className="size-[1.35rem] shrink-0" strokeWidth={2.25} aria-hidden />
                  )}
                  <span className="text-lg font-bold leading-none">
                    {kioskSummaryIsClockOut ? 'Clocked Out' : 'Clocked In'}
                  </span>
                </div>

                {summaryModal.recordedAt ? (
                  <p
                    className={cn(
                      'font-mono text-[2.25rem] font-bold tabular-nums tracking-tight text-white',
                      'sm:text-[2.5rem] sm:leading-none',
                    )}
                  >
                    {formatKioskTime(summaryModal.recordedAt)}
                  </p>
                ) : (
                  <p className="text-4xl font-bold text-white">—</p>
                )}

                <div className="mt-5 flex min-h-10 w-full flex-wrap items-center justify-center gap-2.5">
                  {!kioskSummaryIsClockOut && summaryModal.status === 'on_time' && (
                    <span className="rounded-2xl bg-emerald-100 px-4 py-2 text-sm font-semibold text-emerald-950 shadow-sm">
                      {summaryModal.lateLabel || 'On time'}
                    </span>
                  )}
                  {!kioskSummaryIsClockOut && summaryModal.status === 'late' && (
                    <span className="rounded-2xl bg-amber-100 px-4 py-2 text-sm font-semibold text-amber-950 shadow-sm">
                      {summaryModal.lateLabel
                        ? summaryModal.lateLabel
                        : summaryModal.lateMinutes != null
                          ? `${summaryModal.lateMinutes} min late`
                          : 'Late'}
                    </span>
                  )}
                  {!kioskSummaryIsClockOut && summaryModal.status === 'half_day' && (
                    <span className="rounded-2xl bg-sky-100 px-4 py-2 text-sm font-semibold text-sky-950 shadow-sm">
                      Half Day
                    </span>
                  )}
                  {kioskSummaryIsClockOut &&
                    summaryModal.status === 'undertime' &&
                    summaryModal.undertimeMinutes != null &&
                    summaryModal.undertimeMinutes > 0 && (
                      <span className="rounded-2xl bg-orange-100 px-4 py-2 text-sm font-semibold text-orange-950 shadow-sm">
                        Undertime ({summaryModal.undertimeMinutes} min)
                      </span>
                    )}
                  {kioskSummaryIsClockOut && summaryModal.status === 'present' && (
                    <span className="rounded-2xl bg-emerald-100 px-4 py-2 text-sm font-semibold text-emerald-950 shadow-sm">
                      On time (no undertime)
                    </span>
                  )}
                </div>
              </div>

              {summaryModal.correctionSuggested && (
                <div className="mt-5 w-full max-w-sm rounded-xl border border-amber-400/30 bg-amber-500/10 px-4 py-3 text-left shadow-inner shadow-black/10">
                  <p className="flex items-start gap-2 text-[13px] font-medium leading-snug text-amber-50">
                    <ClipboardList className="mt-0.5 size-4 shrink-0 text-amber-200" aria-hidden />
                    <span>
                      Clock-out was saved without a recorded clock-in today. File an attendance correction after signing in so
                      your official record matches Daily Computation.
                    </span>
                  </p>
                  <Button
                    type="button"
                    variant="outline"
                    className="mt-3 h-11 w-full rounded-xl border-amber-400/40 bg-amber-500/15 text-[15px] font-semibold text-white hover:bg-amber-500/25"
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
                <p
                  className="mt-6 text-sm font-medium text-white/70"
                  role="status"
                  aria-live="polite"
                >
                  Closing in {kioskAutoCloseSeconds} second{kioskAutoCloseSeconds === 1 ? '' : 's'}…
                </p>
              )}

              <DialogFooter className="mt-6 w-full flex-col gap-3 border-0 p-0 sm:flex-row sm:justify-stretch sm:gap-4">
                <Button
                  type="button"
                  variant="secondary"
                  onClick={closeSummaryModal}
                  className={cn(
                    'h-12 min-h-[48px] w-full flex-1 rounded-xl border border-white/15 bg-white/10 text-[15px] font-semibold text-white',
                    'shadow-inner shadow-black/20 hover:bg-white/14 hover:text-white sm:flex-1',
                  )}
                >
                  Scan again
                </Button>
                <Button
                  type="button"
                  onClick={goToDashboard}
                  className={cn(
                    'h-12 min-h-[48px] w-full flex-1 rounded-xl border border-emerald-500/30 bg-emerald-600 text-[15px] font-semibold text-white',
                    'shadow-[0_8px_24px_-4px_rgba(16,185,129,0.45)] transition-transform hover:bg-emerald-500 hover:shadow-[0_10px_28px_-4px_rgba(16,185,129,0.55)] active:scale-[0.99] sm:flex-1',
                  )}
                >
                  <Home className="mr-2 size-[1.1rem]" aria-hidden />
                  Go to Dashboard
                </Button>
              </DialogFooter>
            </div>
          </motion.div>
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
  ...rest
}) {
  const hasError = Boolean(error)
  const isPassword = type === 'password'
  const [showPassword, setShowPassword] = useState(false)
  const inputType = isPassword && showPassword ? 'text' : type

  return (
    <div className="space-y-2">
      <Label htmlFor={name} className="text-sm font-semibold">
        {label}
        {required && <span className="ml-0.5 text-destructive" aria-hidden>*</span>}
      </Label>
      <div className="relative">
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
          'h-11 rounded-lg px-4 text-base transition-colors duration-200',
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
            className="absolute right-2.5 top-1/2 -translate-y-1/2 rounded-md p-1.5 text-muted-foreground transition-colors hover:bg-muted hover:text-foreground focus:outline-none focus:ring-2 focus:ring-ring"
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
      <Label htmlFor={name} className="cursor-pointer text-sm font-medium text-muted-foreground">
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
          className="text-sm font-medium text-muted-foreground underline-offset-2 transition-colors duration-200 hover:text-foreground hover:underline"
          onClick={() => navigate('/forgot-password')}
        >
          Forgot password?
        </button>
      </div>
      <Button
        type="submit"
        disabled={loading}
        className="h-12 w-full rounded-xl bg-teal-600 py-3 text-base font-semibold text-white shadow-lg shadow-teal-900/20 ring-1 ring-teal-500/30 transition-all duration-200 hover:bg-teal-500 hover:shadow-teal-900/30 active:scale-[0.98] focus-visible:ring-2 focus-visible:ring-teal-400/50"
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
        'flex min-h-screen w-full flex-col items-center justify-center px-6 py-10 md:px-8 lg:px-12',
        'bg-background',
        className
      )}
    >
      <div className="w-full max-w-[min(100%,44rem)] md:max-w-[min(100%,48rem)] xl:max-w-[min(100%,56rem)]">

        {/* Brand header — matches kiosk teal accent */}
        <div className="mb-8 flex flex-col items-center gap-4 text-center">
          <div className="flex size-14 items-center justify-center rounded-2xl border border-teal-500/20 bg-teal-500/10 shadow-lg shadow-teal-900/10 dark:border-teal-400/15 dark:bg-teal-500/10">
            <Scan className="size-7 text-teal-600 dark:text-teal-400" />
          </div>
          <div>
            <h1 className="text-xl font-bold tracking-tight text-foreground">SmartDTR</h1>
            <p className="mt-1 text-xs text-muted-foreground">Secure. Intelligent. Automated.</p>
          </div>
        </div>

        <Card className="rounded-2xl border-border/70 shadow-xl shadow-slate-900/5 dark:shadow-slate-950/30 ring-1 ring-slate-200/50 dark:ring-white/5 transition-all duration-200 dark:border-white/10 dark:bg-white/5">
          <CardHeader className="pb-3 pt-6">
            <CardTitle className="text-lg font-bold tracking-tight">Welcome back</CardTitle>
            <CardDescription className="text-muted-foreground text-sm">
              Sign in to your dashboard to continue.
            </CardDescription>
          </CardHeader>
          <CardContent className="space-y-6 pb-8 pt-3">
            {resetSuccess && (
              <p
                className="rounded-lg bg-emerald-50 px-3 py-2 text-sm font-medium text-emerald-800 dark:bg-emerald-950/50 dark:text-emerald-200"
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

        <p className="mt-5 text-center text-xs text-muted-foreground/60">© 2026 SmartDTR · All rights reserved</p>
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
    <div className="min-h-screen bg-background text-foreground">
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
            'order-3 min-h-0 overflow-auto transition-all duration-300 lg:order-1 lg:flex lg:min-h-screen lg:flex-col lg:bg-linear-to-br lg:from-slate-900 lg:via-slate-800/90 lg:to-slate-900',
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
