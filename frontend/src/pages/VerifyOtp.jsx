import { useMemo, useState } from 'react'
import { useLocation, useNavigate } from 'react-router-dom'
import { KeyRound, Loader2, ArrowLeft } from 'lucide-react'
import { Button } from '@/components/ui/button'
import { Card, CardContent, CardDescription, CardHeader, CardTitle } from '@/components/ui/card'
import { Input } from '@/components/ui/input'
import { Label } from '@/components/ui/label'
import { verifyPasswordResetOtp } from '@/api'

export default function VerifyOtp() {
  const navigate = useNavigate()
  const location = useLocation()
  const state = location.state || {}
  const requestId = state.requestId || null
  const login = state.login || ''

  const [otp, setOtp] = useState('')
  const [submitting, setSubmitting] = useState(false)
  const [error, setError] = useState('')

  const maskedLogin = useMemo(() => {
    if (!login || typeof login !== 'string') return ''
    const [u, d] = login.split('@')
    if (!d) return login
    const uMask = u.length <= 2 ? `${u[0] || ''}*` : `${u.slice(0, 2)}***`
    return `${uMask}@${d}`
  }, [login])

  if (!requestId) {
    // No state -> user directly navigated here; send them back.
    return (
      <div className="flex min-h-screen items-center justify-center bg-linear-to-b from-muted/25 via-background to-muted/20 px-4 py-10">
        <div className="w-full max-w-lg">
          <Card className="rounded-2xl border-border/80 shadow-2xl shadow-black/5 ring-1 ring-black/5">
            <CardHeader className="space-y-2">
              <CardTitle className="text-xl font-bold tracking-tight">OTP verification</CardTitle>
              <CardDescription>Start from Forgot Password to request an OTP.</CardDescription>
            </CardHeader>
            <CardContent>
              <Button className="w-full" onClick={() => navigate('/forgot-password', { replace: true })}>
                Go to Forgot Password
              </Button>
            </CardContent>
          </Card>
        </div>
      </div>
    )
  }

  async function handleSubmit(e) {
    e.preventDefault()
    const normalized = otp.replace(/\s+/g, '')
    if (!/^\d{6}$/.test(normalized)) {
      setError('OTP must be a 6-digit code.')
      return
    }
    setSubmitting(true)
    setError('')
    try {
      const data = await verifyPasswordResetOtp(requestId, normalized)
      navigate('/reset-password', {
        replace: true,
        state: { requestId: data.request_id, resetToken: data.reset_token, login },
      })
    } catch (e2) {
      setError(e2.message)
    } finally {
      setSubmitting(false)
    }
  }

  return (
    <div className="flex min-h-screen items-center justify-center bg-linear-to-b from-muted/25 via-background to-muted/20 px-4 py-10">
      <div className="w-full max-w-lg">
        <Card className="rounded-2xl border-border/80 shadow-2xl shadow-black/5 ring-1 ring-black/5">
          <CardHeader className="space-y-2">
            <CardTitle className="flex items-center gap-2 text-xl font-bold tracking-tight">
              <KeyRound className="size-5 text-primary" />
              Verify OTP
            </CardTitle>
            <CardDescription>
              Enter the 6-digit code sent to <span className="font-medium text-foreground">{maskedLogin || 'your email'}</span>.
            </CardDescription>
          </CardHeader>
          <CardContent className="space-y-4">
            {error && (
              <div className="rounded-lg bg-destructive/10 px-3 py-2 text-sm font-medium text-destructive" role="alert">
                {error}
              </div>
            )}
            <form className="space-y-4" onSubmit={handleSubmit}>
              <div className="space-y-2">
                <Label htmlFor="otp" className="text-sm font-semibold">
                  OTP code
                </Label>
                <Input
                  id="otp"
                  inputMode="numeric"
                  autoComplete="one-time-code"
                  value={otp}
                  onChange={(e) => setOtp(e.target.value.replace(/[^\d\s]/g, ''))}
                  placeholder="123456"
                  className="h-11 rounded-lg px-4 tracking-[0.3em]"
                  required
                />
              </div>
              <Button type="submit" disabled={submitting} className="h-11 w-full rounded-xl">
                {submitting ? <Loader2 className="mr-2 size-4 animate-spin" /> : null}
                Verify
              </Button>
            </form>
            <Button
              type="button"
              variant="ghost"
              className="w-full justify-center gap-2 text-muted-foreground"
              onClick={() => navigate('/forgot-password', { replace: true })}
            >
              <ArrowLeft className="size-4" />
              Back
            </Button>
          </CardContent>
        </Card>
      </div>
    </div>
  )
}

