import { useState } from 'react'
import { useNavigate } from 'react-router-dom'
import { Mail, Loader2, ArrowLeft } from 'lucide-react'
import { Button } from '@/components/ui/button'
import { Card, CardContent, CardDescription, CardHeader, CardTitle } from '@/components/ui/card'
import { Input } from '@/components/ui/input'
import { Label } from '@/components/ui/label'
import { requestPasswordResetOtp } from '@/api'
import { validateEmail, sanitizeEmail } from '@/validation'

export default function ForgotPassword() {
  const navigate = useNavigate()
  const [email, setEmail] = useState('')
  const [submitting, setSubmitting] = useState(false)
  const [error, setError] = useState('')
  const [emailErr, setEmailErr] = useState('')

  function handleEmailChange(e) {
    const next = sanitizeEmail(e.target.value)
    setEmail(next)
    setEmailErr(validateEmail(next))
  }

  async function handleSubmit(e) {
    e.preventDefault()
    const err = validateEmail(email)
    setEmailErr(err)
    if (err) return
    setSubmitting(true)
    setError('')
    try {
      const data = await requestPasswordResetOtp(email.trim())
      navigate('/verify-otp', {
        replace: true,
        state: { requestId: data.request_id, email: email.trim(), expiresInSeconds: data.expires_in_seconds },
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
              <Mail className="size-5 text-primary" />
              Forgot password
            </CardTitle>
            <CardDescription>
              Enter your registered email. We’ll send a one-time code (OTP) to reset your password.
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
                <Label htmlFor="fp-email" className="text-sm font-semibold">
                  Email
                </Label>
                <Input
                  id="fp-email"
                  type="email"
                  value={email}
                  onChange={handleEmailChange}
                  onBlur={() => setEmailErr(validateEmail(email))}
                  placeholder="you@company.com"
                  required
                  className="h-11 rounded-lg px-4"
                />
                {emailErr && (
                  <p className="text-sm text-destructive" role="alert">
                    {emailErr}
                  </p>
                )}
              </div>
              <Button type="submit" disabled={submitting} className="h-11 w-full rounded-xl">
                {submitting ? <Loader2 className="mr-2 size-4 animate-spin" /> : null}
                Send OTP
              </Button>
            </form>
            <Button
              type="button"
              variant="ghost"
              className="w-full justify-center gap-2 text-muted-foreground"
              onClick={() => navigate('/login')}
            >
              <ArrowLeft className="size-4" />
              Back to login
            </Button>
          </CardContent>
        </Card>
      </div>
    </div>
  )
}

