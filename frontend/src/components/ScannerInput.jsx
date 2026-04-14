/**
 * ScannerInput — invisible HID barcode/QR scanner capture.
 *
 * The T-D4 desktop 2D barcode scanner (and all HID scanners) work as keyboard-
 * emulation devices: they rapidly type the scanned code followed by Enter.
 * This component captures that pattern via a document-level keydown listener —
 * NO visible input field is shown to the user.
 *
 * Detection heuristic:
 *  - Characters arrive within < 600 ms total (scanner types ~50 chars in < 50 ms)
 *  - Sequence ends with Enter
 *  - Minimum 4 printable characters (shortest plausible QR payload)
 *  - Keys arriving from a focused <input>/<textarea> are ignored (user typing elsewhere)
 *
 * Visual states:
 *  idle       — animated laser-sweep scan zone, "Scanner Ready" indicator
 *  processing — spinner while API call is in-flight
 *  success    — employee name, action (Clock In/Out), time, and status badge
 *  error      — error message with "Scan again" prompt
 */
import { useEffect, useRef } from 'react'
import { CheckCircle2, AlertCircle, Loader2, LogIn, LogOut } from 'lucide-react'
import { cn } from '@/lib/utils'
import { Avatar, AvatarFallback, AvatarImage } from '@/components/ui/avatar'
import { employeeAvatarSrc, getEmployeeAvatarColorClass } from '@/lib/employeeAvatar'

export function ScannerInput({
  onScan,
  submitting = false,
  error = null,
  /** { employeeName?, employeeProfileImageUrl?, employeeId?, type, recordedAt, status, lateMinutes, lateLabel, undertimeMinutes } */
  successResult = null,
  theme = 'light',
  className,
}) {
  const submittingRef = useRef(submitting)
  const onScanRef    = useRef(onScan)
  const isDark = theme === 'dark'

  // Keep mutable refs current without re-registering the listener
  useEffect(() => { submittingRef.current = submitting }, [submitting])
  useEffect(() => { onScanRef.current = onScan },         [onScan])

  // ── Global keydown capture ──────────────────────────────────────────────────
  useEffect(() => {
    let buffer       = ''
    let firstKeyTime = 0
    let clearTimer   = null

    function handleKeyDown(e) {
      if (submittingRef.current) return
      // Don't steal keystrokes from focused text inputs on the page
      const tag = e.target?.tagName?.toLowerCase()
      if (tag === 'input' || tag === 'textarea' || tag === 'select') return
      if (e.ctrlKey || e.altKey || e.metaKey) return

      if (e.key === 'Enter') {
        const elapsed = Date.now() - firstKeyTime
        const text = buffer.trim()
        buffer = ''
        firstKeyTime = 0
        clearTimeout(clearTimer)
        // Process only if it looks like a scanner burst (not manual typing)
        if (text.length >= 4 && elapsed > 0 && elapsed < 600) {
          onScanRef.current(text)
        }
        return
      }

      // Ignore non-printable keys (arrows, F-keys, Backspace, etc.)
      if (e.key.length !== 1) return

      if (!buffer) firstKeyTime = Date.now()
      buffer += e.key

      // Expire stale buffer after 1 s of inactivity
      clearTimeout(clearTimer)
      clearTimer = setTimeout(() => { buffer = ''; firstKeyTime = 0 }, 1000)
    }

    document.addEventListener('keydown', handleKeyDown)
    return () => {
      document.removeEventListener('keydown', handleKeyDown)
      clearTimeout(clearTimer)
    }
  }, []) // Register once; refs keep values fresh

  // ── Visual state helpers — scanner area = single glow accent ─────────────────
  const accentPing    = isDark ? 'bg-teal-400'         : 'bg-primary'
  const accentBracket = isDark ? 'border-teal-400/40' : 'border-primary/70'

  function formatTime(iso) {
    if (!iso) return null
    return new Date(iso).toLocaleTimeString('en-PH', {
      hour: '2-digit', minute: '2-digit', hour12: true,
    })
  }

  // ── PROCESSING state ────────────────────────────────────────────────────────
  if (submitting) {
    return (
      <div className={cn('flex flex-col items-center justify-center gap-4 py-10', className)}>
        <div className={cn(
          'flex size-16 items-center justify-center rounded-full border-2',
          isDark
            ? 'border-amber-400/40 bg-amber-500/10'
            : 'border-amber-300/60 bg-amber-50 dark:bg-amber-900/20'
        )}>
          <Loader2 className={cn(
            'size-7 animate-spin',
            isDark ? 'text-amber-300' : 'text-amber-500 dark:text-amber-400'
          )} />
        </div>
        <div className="space-y-1 text-center">
          <p className={cn('text-sm font-semibold', isDark ? 'text-white/80' : 'text-foreground')}>
            Processing scan…
          </p>
          <p className={cn('text-xs', isDark ? 'text-white/35' : 'text-muted-foreground')}>
            Recording attendance, please wait
          </p>
        </div>
      </div>
    )
  }

  // ── SUCCESS state — ✅ Name Clocked In/Out (clear feedback) ───────────────────
  if (successResult) {
    const isIn = successResult.type === 'clock_in'
    const timeStr = formatTime(successResult.recordedAt)
    const successInitials = (successResult.employeeName || '?')
      .trim()
      .split(/\s+/)
      .map((n) => n[0])
      .join('')
      .toUpperCase()
      .slice(0, 2)

    return (
      <div className={cn('flex flex-col items-center justify-center gap-3 py-6 px-4', className)}>
        <div className="relative">
          <Avatar
            className={cn(
              'size-16 rounded-full border-2 shadow-sm',
              isDark ? 'border-white/20 ring-2 ring-emerald-400/30' : 'border-border ring-2 ring-emerald-500/20',
            )}
          >
            <AvatarImage
              src={employeeAvatarSrc({
                profile_image_url: successResult.employeeProfileImageUrl,
                profile_image: successResult.employeeProfileImage,
              }) || undefined}
              alt=""
              className="object-cover"
            />
            <AvatarFallback
              className={cn(
                'rounded-full text-sm font-bold',
                getEmployeeAvatarColorClass(successResult.employeeId, successResult.employeeName),
              )}
            >
              {successInitials}
            </AvatarFallback>
          </Avatar>
          <span
            className={cn(
              'absolute -bottom-0.5 -right-0.5 flex size-7 items-center justify-center rounded-full shadow-md ring-2',
              isDark ? 'bg-emerald-500/95 text-white ring-white/25' : 'bg-emerald-500 text-white ring-background',
            )}
          >
            <CheckCircle2 className="size-3.5" aria-hidden />
          </span>
        </div>

        {/* Primary confirmation: "Name Clocked In" */}
        {successResult.employeeName && (
          <p className={cn('text-center text-lg font-bold', isDark ? 'text-white' : 'text-[#0A0A0A]')}>
            {successResult.employeeName}
          </p>
        )}

        {/* Action badge */}
        <div className={cn(
          'inline-flex items-center gap-2 rounded-full border px-3 py-1.5 text-xs font-bold',
          isIn
            ? isDark
              ? 'border-teal-400/35 bg-teal-500/15 text-teal-200'
              : 'border-teal-200 bg-teal-50 text-teal-700 dark:bg-teal-900/20 dark:text-teal-300'
            : isDark
              ? 'border-slate-400/35 bg-slate-500/15 text-slate-200'
              : 'border-slate-200 bg-slate-100 text-slate-700 dark:bg-slate-800/40 dark:text-slate-300'
        )}>
          <CheckCircle2 className="size-3.5" />
          {isIn ? 'Clocked In' : 'Clocked Out'}
        </div>

        {/* Time */}
        {timeStr && (
          <p className={cn('font-mono text-sm', isDark ? 'text-white/55' : 'text-muted-foreground')}>
            {timeStr}
          </p>
        )}

        {/* Status badges */}
        {isIn && successResult.status === 'on_time' && (
          <span className={cn(
            'rounded-full border px-2.5 py-0.5 text-xs font-medium',
            isDark ? 'border-emerald-400/25 bg-emerald-500/15 text-emerald-300' : 'border-emerald-200 bg-emerald-50 text-emerald-700'
          )}>Present — On Time</span>
        )}
        {isIn && successResult.status === 'late' && (
          <span className={cn(
            'rounded-full border px-2.5 py-0.5 text-xs font-medium',
            isDark ? 'border-amber-400/25 bg-amber-500/15 text-amber-300' : 'border-amber-200 bg-amber-50 text-amber-700'
          )}>
            Late{successResult.lateMinutes ? ` — ${successResult.lateMinutes} min` : successResult.lateLabel ? ` — ${successResult.lateLabel}` : ''}
          </span>
        )}
        {isIn && successResult.status === 'half_day' && (
          <span className={cn(
            'rounded-full border px-2.5 py-0.5 text-xs font-medium',
            isDark ? 'border-sky-400/25 bg-sky-500/15 text-sky-300' : 'border-sky-200 bg-sky-50 text-sky-700'
          )}>Half Day</span>
        )}
        {!isIn && (successResult.undertimeMinutes ?? 0) > 0 && (
          <span className={cn(
            'rounded-full border px-2.5 py-0.5 text-xs font-medium',
            isDark ? 'border-orange-400/25 bg-orange-500/15 text-orange-300' : 'border-orange-200 bg-orange-50 text-orange-700'
          )}>Undertime — {successResult.undertimeMinutes} min</span>
        )}

        {/* Reset hint */}
        <p className={cn('mt-1 text-[10px]', isDark ? 'text-white/22' : 'text-muted-foreground/50')}>
          Ready for next scan…
        </p>
      </div>
    )
  }

  // ── ERROR state ─────────────────────────────────────────────────────────────
  if (error) {
    return (
      <div className={cn('flex flex-col items-center justify-center gap-4 py-10 px-4', className)}>
        <div className={cn(
          'flex size-16 items-center justify-center rounded-full border-2',
          isDark
            ? 'border-red-400/40 bg-red-500/10'
            : 'border-red-300/60 bg-red-50 dark:bg-red-900/20'
        )}>
          <AlertCircle className={cn('size-7', isDark ? 'text-red-400' : 'text-red-500')} />
        </div>
        <div className="space-y-1.5 text-center">
          <p className={cn('text-sm font-semibold', isDark ? 'text-red-200' : 'text-destructive')}>
            Invalid QR Code
          </p>
          <p className={cn('text-xs leading-relaxed', isDark ? 'text-red-200/70' : 'text-destructive/70')}>
            {error}
          </p>
          <p className={cn('text-[11px] mt-1', isDark ? 'text-white/30' : 'text-muted-foreground')}>
            Please try scanning again
          </p>
        </div>
      </div>
    )
  }

  // ── IDLE state — animated scan terminal (single glow accent per roast) ───────
  return (
    <div className={cn('flex flex-col items-center gap-4', className)}>
      {/* Scan zone — pulsing border, interactive feel */}
      <div className="relative mx-auto w-full max-w-[220px]">
        {/* Zone background — subtle pulse in dark (single glow accent) */}
        <div className={cn(
          'relative aspect-square overflow-hidden rounded-2xl border-2',
          isDark
            ? 'bg-white/4 border-teal-500/30 animate-scanner-pulse-border'
            : 'bg-muted/40 border-border'
        )}>
          {/* Corner brackets — top-left */}
          <div className={cn('absolute top-4 left-4 size-7 border-t-2 border-l-2 rounded-tl', accentBracket)} />
          {/* Corner brackets — top-right */}
          <div className={cn('absolute top-4 right-4 size-7 border-t-2 border-r-2 rounded-tr', accentBracket)} />
          {/* Corner brackets — bottom-left */}
          <div className={cn('absolute bottom-4 left-4 size-7 border-b-2 border-l-2 rounded-bl', accentBracket)} />
          {/* Corner brackets — bottom-right */}
          <div className={cn('absolute bottom-4 right-4 size-7 border-b-2 border-r-2 rounded-br', accentBracket)} />

          {/* Animated laser sweep line — only glow accent in kiosk */}
          <div
            className={cn(
              'absolute left-6 right-6 h-[2px] rounded-full',
              isDark
                ? 'bg-linear-to-r from-transparent via-teal-400/80 to-transparent shadow-[0_0_12px_rgba(20,184,166,0.5)]'
                : 'bg-linear-to-r from-transparent via-primary/70 to-transparent'
            )}
            style={{ animation: 'laserSweep 2.2s ease-in-out infinite' }}
            aria-hidden
          />

          {/* Faint QR grid placeholder in center */}
          <div className="absolute inset-0 flex items-center justify-center">
            <div className={cn('grid grid-cols-3 gap-1.5 opacity-[0.07]', isDark ? '' : '')}>
              {[5, 3, 5, 3, 2, 3, 5, 3, 5].map((size, i) => (
                <div
                  key={i}
                  style={{ width: size * 4, height: size * 4 }}
                  className={cn('rounded-sm', isDark ? 'bg-white' : 'bg-foreground')}
                />
              ))}
            </div>
          </div>
        </div>
      </div>

      {/* Status row */}
      <div className="flex flex-col items-center gap-1.5 text-center">
        <div className="flex items-center gap-2">
          {/* Live indicator dot */}
          <span className="relative flex size-2 shrink-0">
            <span className={cn('absolute inset-0 animate-ping rounded-full opacity-70', accentPing)} />
            <span className={cn('relative inline-flex size-2 rounded-full', accentPing)} />
          </span>
          <p className={cn('text-sm font-semibold', isDark ? 'text-white/80' : 'text-foreground')}>
            Scanner Ready
          </p>
        </div>
        <p className={cn('text-xs', isDark ? 'text-white/35' : 'text-muted-foreground')}>
          Scan your personal QR code badge using the scanner
        </p>
      </div>

      {/* Keyframe for laser sweep */}
      <style>{`
        @keyframes laserSweep {
          0%,100% { top: 16%; opacity:0.95; }
          50%      { top: 84%; opacity:0.65; }
        }
      `}</style>
    </div>
  )
}
