import { useState, useEffect, useCallback, useRef } from 'react'
import { AlertTriangle, Download, Eye, Loader2, QrCode, RefreshCw, ScanFace, Trash2 } from 'lucide-react'
import { Skeleton } from '@/components/skeletons'
import { QRCodeCanvas } from 'qrcode.react'
import { Card, CardContent, CardDescription, CardHeader, CardTitle } from '@/components/ui/card'
import { Button } from '@/components/ui/button'
import {
  Dialog,
  DialogContent,
  DialogDescription,
  DialogFooter,
  DialogHeader,
  DialogTitle,
} from '@/components/ui/dialog'
import { useAuth } from '@/contexts/AuthContext'
import { getMyQr, regenerateMyQr, registerMyFace, removeMyFace, getMyFace } from '@/api'
import { FaceRekognitionLiveness } from '@/components/FaceRekognitionLiveness'
import { toast } from 'sonner'

export default function EmployeeMyQr() {
  const { user, setUser } = useAuth()
  const [qrToken, setQrToken] = useState('')
  const [generatedAt, setGeneratedAt] = useState(null)
  const [companyLogoUrl, setCompanyLogoUrl] = useState(null)
  const [loading, setLoading] = useState(true)
  const [regenerating, setRegenerating] = useState(false)
  const [error, setError] = useState(null)
  const qrCanvasRef = useRef(null)

  const [faceRegisterOpen, setFaceRegisterOpen] = useState(false)
  const [faceRegisterSubmitting, setFaceRegisterSubmitting] = useState(false)
  const [faceRegisterError, setFaceRegisterError] = useState(null)
  const [faceRegisterErrorCode, setFaceRegisterErrorCode] = useState(null)
  const [faceRegisterRetryKey, setFaceRegisterRetryKey] = useState(0)

  const [removeFaceConfirmOpen, setRemoveFaceConfirmOpen] = useState(false)
  const [removeFaceSubmitting, setRemoveFaceSubmitting] = useState(false)

  const [viewFaceOpen, setViewFaceOpen] = useState(false)
  const [viewFaceImage, setViewFaceImage] = useState(null)
  const [viewFaceLoading, setViewFaceLoading] = useState(false)

  const hasFace = user?.has_face === true

  const fetchQr = useCallback(async () => {
    setError(null)
    setLoading(true)
    try {
      const data = await getMyQr()
      setQrToken(data.qr_token || '')
      setGeneratedAt(data.qr_token_generated_at || null)
      setCompanyLogoUrl(data.company_logo_url || null)
    } catch (e) {
      setError(e.message)
      setQrToken('')
    } finally {
      setLoading(false)
    }
  }, [])

  useEffect(() => {
    fetchQr()
  }, [fetchQr])

  const handleRegenerate = async () => {
    setError(null)
    setRegenerating(true)
    try {
      const data = await regenerateMyQr()
      setQrToken(data.qr_token || '')
      setGeneratedAt(data.qr_token_generated_at || null)
      setCompanyLogoUrl(data.company_logo_url || null)
    } catch (e) {
      setError(e.message)
    } finally {
      setRegenerating(false)
    }
  }

  const downloadQr = useCallback(() => {
    const container = qrCanvasRef.current
    const canvas = container?.querySelector('canvas')
    if (!canvas) return
    const name = (user?.name || 'my-qr').replace(/[^a-z0-9-_]/gi, '-')
    const url = canvas.toDataURL('image/png')
    const a = document.createElement('a')
    a.href = url
    a.download = `${name}-qr.png`
    a.click()
  }, [user?.name])

  const openFaceRegister = () => {
    setFaceRegisterError(null)
    setFaceRegisterErrorCode(null)
    setFaceRegisterRetryKey((k) => k + 1)
    setFaceRegisterOpen(true)
  }

  const openViewFace = async () => {
    if (!hasFace) return
    setViewFaceOpen(true)
    setViewFaceImage(null)
    setViewFaceLoading(true)
    try {
      const data = await getMyFace()
      setViewFaceImage(data.face_image)
    } catch (e) {
      setError(e.message)
      setViewFaceOpen(false)
    } finally {
      setViewFaceLoading(false)
    }
  }

  const closeFaceRegister = (force = false) => {
    if (force || !faceRegisterSubmitting) {
      setFaceRegisterOpen(false)
      setFaceRegisterError(null)
      setFaceRegisterErrorCode(null)
    }
  }

  const handleFaceRegisterVerified = async (sessionId) => {
    setFaceRegisterSubmitting(true)
    setFaceRegisterError(null)
    try {
      const data = await registerMyFace({ liveness_session_id: sessionId })
      if (data.user) {
        setUser((prev) => {
          if (!prev || typeof prev !== 'object') return data.user
          const merged = { ...prev, ...data.user }
          // Keep explicit org-head flag if this response omitted it (avoids falling back to hr_role-only heuristics).
          if (typeof merged.is_assigned_organization_head !== 'boolean' && typeof prev.is_assigned_organization_head === 'boolean') {
            merged.is_assigned_organization_head = prev.is_assigned_organization_head
          }
          return merged
        })
      }
      setViewFaceImage(null)
      closeFaceRegister()
      toast.success('Face registered', {
        description: 'You can now use Facial Recognition for DTR clock-in and clock-out.',
      })
    } catch (e) {
      const msg = e.message || 'Face registration failed'
      const code = e.errorCode
      setFaceRegisterError(msg)
      setFaceRegisterErrorCode(code || null)
      const title =
        code === 'face_already_registered'
          ? 'Duplicate face detected'
          : code === 'registration_timeout'
            ? 'Registration timed out'
            : code === 'no_face_detected'
              ? 'No face detected'
              : code === 'spoof_detected'
                ? 'Liveness check failed'
                : code === 'service_unavailable'
                  ? 'Face service unavailable'
                  : 'Registration failed'
      toast.error(title, { description: msg, duration: code === 'face_already_registered' ? 8000 : 4000 })
    } finally {
      setFaceRegisterSubmitting(false)
    }
  }

  const handleRemoveFace = async () => {
    setRemoveFaceSubmitting(true)
    try {
      const data = await removeMyFace()
      setRemoveFaceConfirmOpen(false)
      if (data.user) setUser(data.user)
      setViewFaceOpen(false)
      setViewFaceImage(null)
      setFaceRegisterError(null)
      setFaceRegisterErrorCode(null)
      toast.success('Face registration reset', {
        description: 'All face data was cleared. You can register again immediately.',
      })
    } catch (e) {
      setError(e.message)
    } finally {
      setRemoveFaceSubmitting(false)
    }
  }

  return (
    <div className="space-y-6">
      <div>
        <h1 className="text-2xl font-semibold tracking-tight">My QR & Facial</h1>
        <p className="mt-1 text-muted-foreground">
          Manage your QR code and facial registration for attendance. Use QR or Face Recognition for DTR clock-in and
          clock-out at the kiosk.
        </p>
      </div>

      {error && (
        <div className="rounded-lg border border-destructive/50 bg-destructive/10 px-4 py-3 text-sm text-destructive">
          {error}
        </div>
      )}

      <div className="flex flex-col @md:flex-row gap-6">
      {/* QR Code Card */}
      <Card className="flex-1 min-w-0 border border-black/10 bg-card/95 shadow-sm">
        <CardHeader>
          <CardTitle className="flex items-center gap-2"> 
            <QrCode className="size-5 text-primary" />
            Attendance QR
          </CardTitle>
          <CardDescription>
            Present this code when scanning for attendance. Do not share it with others.
          </CardDescription>
        </CardHeader>
        <CardContent className="space-y-4">
          {loading ? (
            <div className="flex flex-col items-center gap-3 rounded-xl bg-muted/25 p-4 shadow-inner">
              <Skeleton className="size-[280px] rounded-lg" />
              <Skeleton className="h-4 w-64 rounded-md" />
              <Skeleton className="h-3 w-40 rounded-md" />
              <div className="flex gap-2">
                <Skeleton className="h-9 w-24 rounded-md" />
                <Skeleton className="h-9 w-24 rounded-md" />
              </div>
            </div>
          ) : qrToken ? (
            <>
              <div
                ref={qrCanvasRef}
                className="flex justify-center rounded-xl bg-white p-4 shadow-inner"
              >
                <QRCodeCanvas
                  value={qrToken}
                  size={280}
                  level="H"
                  includeMargin
                  style={{ imageRendering: 'pixelated' }}
                  imageSettings={
                    companyLogoUrl
                      ? {
                          src: companyLogoUrl,
                          height: 56,
                          width: 56,
                          excavate: true,
                        }
                      : undefined
                  }
                />
              </div>
              <p className="break-all rounded-md bg-muted/50 px-3 py-2 font-mono text-xs text-muted-foreground">
                {qrToken}
              </p>
              {generatedAt && (
                <p className="text-xs text-muted-foreground">
                  Generated: {new Date(generatedAt).toLocaleString('en-PH', { dateStyle: 'medium', timeStyle: 'short' })}
                </p>
              )}
              <div className="flex flex-wrap gap-2">
                <Button type="button" variant="outline" onClick={downloadQr} title="Download QR as PNG">
                  <Download className="size-4" />
                  Download
                </Button>
                <Button
                  type="button"
                  variant="outline"
                  onClick={handleRegenerate}
                  disabled={regenerating}
                  title="Generate a new QR code (previous one will stop working)"
                >
                  {regenerating ? <Loader2 className="size-4 animate-spin" /> : <RefreshCw className="size-4" />}
                  Regenerate
                </Button>
              </div>
            </>
          ) : (
            <div className="rounded-lg bg-muted/70 px-4 py-8 text-center text-sm text-muted-foreground shadow-inner">
              No QR code available. If this persists, contact your administrator.
            </div>
          )}
        </CardContent>
      </Card>

      {/* Face Recognition Card */}
      <Card className="flex-1 min-w-0 border border-black/10 bg-card/95 shadow-sm">
        <CardHeader>
          <CardTitle className="flex items-center gap-2">
            <ScanFace className="size-5 text-primary" />
            Facial Recognition
          </CardTitle>
          <CardDescription>
            Register your face to use Facial Recognition for DTR clock-in and clock-out at the kiosk.
          </CardDescription>
        </CardHeader>
        <CardContent className="space-y-4">
          <div className="flex items-center justify-between rounded-lg bg-muted/35 px-4 py-3 shadow-xs">
            <span className="text-sm font-medium">
              Status: {hasFace ? (
                <span className="text-emerald-600 dark:text-emerald-400">Face Registered</span>
              ) : (
                <span className="text-amber-600 dark:text-amber-400">Not registered</span>
              )}
            </span>
          </div>

          {hasFace ? (
            <>
              <p className="text-xs text-muted-foreground">
                You can use Facial Recognition for DTR clock-in and clock-out at the kiosk.
              </p>
              <div className="flex flex-wrap gap-2">
                <Button type="button" variant="outline" onClick={openViewFace}>
                  <Eye className="size-4" />
                  View
                </Button>
                <Button type="button" onClick={openFaceRegister}>
                  <ScanFace className="size-4" />
                  Change Face
                </Button>
                <Button
                  type="button"
                  variant="outline"
                  onClick={() => setRemoveFaceConfirmOpen(true)}
                >
                  <Trash2 className="size-4" />
                  Remove Face
                </Button>
                <Button
                  type="button"
                  variant="destructive"
                  onClick={() => setRemoveFaceConfirmOpen(true)}
                >
                  <Trash2 className="size-4" />
                  Reset Face Registration
                </Button>
              </div>
            </>
          ) : (
            <>
              <p className="text-xs text-amber-600/90 dark:text-amber-400/90">
                Facial Recognition is disabled until you register your face.
              </p>
              <Button type="button" onClick={openFaceRegister}>
                <ScanFace className="size-4" />
                Register Face
              </Button>
            </>
          )}

          <p className="text-xs text-muted-foreground">
            After registering your face, you will be eligible to use Facial Recognition for DTR clock-in and clock-out.
          </p>
        </CardContent>
      </Card>
      </div>

      {/* Register/Change Face Dialog – Amazon Rekognition Face Liveness */}
      <Dialog open={faceRegisterOpen} onOpenChange={(open) => !open && !faceRegisterSubmitting && closeFaceRegister()}>
        <DialogContent className="max-w-lg gap-4">
          <DialogHeader>
            <DialogTitle>{hasFace ? 'Change face' : 'Register face'}</DialogTitle>
            <DialogDescription>
              {hasFace
                ? 'Complete the guided face liveness check. Your existing face data will be replaced.'
                : 'Complete the guided face liveness check to register for Facial Recognition attendance.'}
            </DialogDescription>
          </DialogHeader>
          <FaceRekognitionLiveness
            key={faceRegisterRetryKey}
            onVerified={handleFaceRegisterVerified}
            onSuccess={closeFaceRegister}
            hideInstruction
            instructionText="Complete the face liveness check to register your face."
          />
          {faceRegisterSubmitting && (
            <div
              className="flex items-center gap-2 rounded-md bg-muted/40 px-3 py-2 text-sm text-muted-foreground shadow-xs"
              role="status"
              aria-live="polite"
            >
              <Loader2 className="size-4 shrink-0 animate-spin" />
              Registering face…
            </div>
          )}
          {faceRegisterError && (
            <div className="space-y-2">
              {faceRegisterErrorCode === 'face_already_registered' ? (
                <div className="flex items-start gap-3 rounded-md border border-destructive/30 bg-destructive/10 px-4 py-3" role="alert">
                  <AlertTriangle className="mt-0.5 size-5 shrink-0 text-destructive" />
                  <div className="space-y-1">
                    <p className="text-sm font-semibold text-destructive">Duplicate Face Detected</p>
                    <p className="text-sm text-destructive/90">{faceRegisterError}</p>
                  </div>
                </div>
              ) : (
                <p className="rounded-md bg-destructive/10 px-3 py-2 text-sm text-destructive" role="alert">
                  {faceRegisterError}
                </p>
              )}
              {faceRegisterErrorCode !== 'face_already_registered' && (
                <Button
                  type="button"
                  variant="secondary"
                  className="w-full"
                  disabled={faceRegisterSubmitting}
                  onClick={() => {
                    setFaceRegisterError(null)
                    setFaceRegisterErrorCode(null)
                    setFaceRegisterRetryKey((k) => k + 1)
                  }}
                >
                  Try again
                </Button>
              )}
            </div>
          )}
          <Button variant="outline" onClick={closeFaceRegister} disabled={faceRegisterSubmitting} className="w-full">
            Cancel
          </Button>
        </DialogContent>
      </Dialog>

      {/* View Face Modal */}
      <Dialog open={viewFaceOpen} onOpenChange={(open) => !open && setViewFaceOpen(false)}>
        <DialogContent className="max-w-md gap-3">
          <DialogHeader>
            <DialogTitle className="flex items-center gap-2">
              <ScanFace className="size-5 text-primary" />
              Registered Face
            </DialogTitle>
            <DialogDescription>
              Your face image used for attendance recognition.
            </DialogDescription>
          </DialogHeader>
          {viewFaceLoading ? (
            <div className="flex justify-center py-12">
              <Loader2 className="size-10 animate-spin text-muted-foreground" />
            </div>
          ) : viewFaceImage ? (
            <div className="flex justify-center rounded-lg bg-muted/30 p-4 shadow-inner">
              <img
                src={viewFaceImage}
                alt="Your registered face"
                className="max-h-64 w-auto rounded-lg object-contain"
              />
            </div>
          ) : (
            <p className="py-8 text-center text-sm text-muted-foreground">No face registered.</p>
          )}
          <DialogFooter>
            <Button variant="outline" onClick={() => setViewFaceOpen(false)}>
              Close
            </Button>
          </DialogFooter>
        </DialogContent>
      </Dialog>

      {/* Remove Face Confirmation */}
      <Dialog open={removeFaceConfirmOpen} onOpenChange={(open) => !open && !removeFaceSubmitting && setRemoveFaceConfirmOpen(false)}>
        <DialogContent className="max-w-md">
          <DialogHeader>
            <DialogTitle>Remove face registration?</DialogTitle>
            <DialogDescription>
              Facial Recognition will be disabled until you register again. You can still use your QR code for
              attendance.
            </DialogDescription>
          </DialogHeader>
          <DialogFooter>
            <Button variant="outline" onClick={() => setRemoveFaceConfirmOpen(false)} disabled={removeFaceSubmitting}>
              Cancel
            </Button>
            <Button variant="destructive" onClick={handleRemoveFace} disabled={removeFaceSubmitting}>
              {removeFaceSubmitting ? (
                <Loader2 className="size-4 animate-spin" />
              ) : (
                <>
                  <Trash2 className="size-4 mr-2" />
                  Remove Face
                </>
              )}
            </Button>
          </DialogFooter>
        </DialogContent>
      </Dialog>
    </div>
  )
}
