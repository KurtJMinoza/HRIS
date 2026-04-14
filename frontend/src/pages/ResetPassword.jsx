import { useState } from 'react'
import { useLocation, useNavigate } from 'react-router-dom'
import { Lock, Loader2, ArrowLeft } from 'lucide-react'
import { Button } from '@/components/ui/button'
import { Card, CardContent, CardDescription, CardHeader, CardTitle } from '@/components/ui/card'
import { Input } from '@/components/ui/input'
import { Label } from '@/components/ui/label'
import { resetPasswordWithOtp } from '@/api'
import { validatePassword, validateConfirmPassword, sanitizePassword } from '@/validation'

export default function ResetPassword() {
  const navigate = useNavigate()
  const location = useLocation()
  const state = location.state || {}
  const requestId = state.requestId || null
  const resetToken = state.resetToken || null

  const [password, setPassword] = useState('')
  const [confirm, setConfirm] = useState('')
  const [submitting, setSubmitting] = useState(false)
  const [error, setError] = useState('')
  const [errors, setErrors] = useState({ password: '', confirm: '' })

  if (!requestId || !resetToken) {
    return (
      <div className="flex min-h-screen items-center justify-center bg-linear-to-b from-muted/25 via-background to-muted/20 px-4 py-10">
        <div className="w-full max-w-lg">
          <Card className="rounded-2xl border-border/80 shadow-2xl shadow-black/5 ring-1 ring-black/5">
            <CardHeader className="space-y-2">
              <CardTitle className="text-xl font-bold tracking-tight">Reset password</CardTitle>
              <CardDescription>Start from Forgot Password to reset your password.</CardDescription>
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

  function validateAll() {
    const pErr = validatePassword(password, true)
    const cErr = validateConfirmPassword(password, confirm)
    setErrors({ password: pErr, confirm: cErr })
    return !pErr && !cErr
  }

  async function handleSubmit(e) {
    e.preventDefault()
    if (!validateAll()) return
    setSubmitting(true)
    setError('')
    try {
      await resetPasswordWithOtp(requestId, resetToken, password, confirm)
      navigate('/login', { replace: true, state: { resetSuccess: true } })
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
              <Lock className="size-5 text-primary" />
              Reset password
            </CardTitle>
            <CardDescription>Create a new password for your account.</CardDescription>
          </CardHeader>
          <CardContent className="space-y-4">
            {error && (
              <div className="rounded-lg bg-destructive/10 px-3 py-2 text-sm font-medium text-destructive" role="alert">
                {error}
              </div>
            )}
            <form className="space-y-4" onSubmit={handleSubmit}>
              <div className="space-y-2">
                <Label htmlFor="new-password" className="text-sm font-semibold">
                  New password
                </Label>
                <Input
                  id="new-password"
                  type="password"
                  value={password}
                  onChange={(e) => {
                    const next = sanitizePassword(e.target.value)
                    setPassword(next)
                    setErrors((prev) => ({ ...prev, password: validatePassword(next, true) }))
                  }}
                  onBlur={() => setErrors((prev) => ({ ...prev, password: validatePassword(password, true) }))}
                  className="h-11 rounded-lg px-4"
                  minLength={8}
                  required
                />
                {errors.password && <p className="text-sm text-destructive">{errors.password}</p>}
              </div>
              <div className="space-y-2">
                <Label htmlFor="confirm-password" className="text-sm font-semibold">
                  Confirm password
                </Label>
                <Input
                  id="confirm-password"
                  type="password"
                  value={confirm}
                  onChange={(e) => {
                    const next = sanitizePassword(e.target.value)
                    setConfirm(next)
                    setErrors((prev) => ({ ...prev, confirm: validateConfirmPassword(password, next) }))
                  }}
                  onBlur={() => setErrors((prev) => ({ ...prev, confirm: validateConfirmPassword(password, confirm) }))}
                  className="h-11 rounded-lg px-4"
                  required
                />
                {errors.confirm && <p className="text-sm text-destructive">{errors.confirm}</p>}
              </div>
              <Button type="submit" disabled={submitting} className="h-11 w-full rounded-xl">
                {submitting ? <Loader2 className="mr-2 size-4 animate-spin" /> : null}
                Update password
              </Button>
            </form>
            <Button
              type="button"
              variant="ghost"
              className="w-full justify-center gap-2 text-muted-foreground"
              onClick={() => navigate('/login', { replace: true })}
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

