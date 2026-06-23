import { useId } from 'react'
import { AgcBrandLogoCrop } from '@/components/AgcBrandLogoCrop'
import { useTheme } from '@/contexts/useTheme'
import { cn } from '@/lib/utils'

function PreloaderHrMark({ className }) {
  const gid = useId().replace(/:/g, '')
  const gradId = `hrisPreloaderGrad-${gid}`

  return (
    <svg
      viewBox="0 0 88 76"
      xmlns="http://www.w3.org/2000/svg"
      className={cn('relative z-10 h-17 w-19', className)}
      aria-hidden
    >
      <defs>
        <linearGradient id={gradId} x1={44} y1={8} x2={44} y2={74} gradientUnits="userSpaceOnUse">
          <stop stopColor="#ffc02e" />
          <stop offset="0.52" stopColor="#ff9420" />
          <stop offset="1" stopColor="#ea4a12" />
        </linearGradient>
        <filter id={`${gradId}-glow`} x="-40%" y="-40%" width="180%" height="180%">
          <feGaussianBlur stdDeviation="3.5" result="blur" />
          <feMerge>
            <feMergeNode in="blur" />
            <feMergeNode in="SourceGraphic" />
          </feMerge>
        </filter>
      </defs>
      <g fill={`url(#${gradId})`} filter={`url(#${gradId}-glow)`}>
        <circle cx={44} cy={22} r={14} />
        <path d="M17 66.5c2.4-17.1 13.1-26 27-26s24.6 8.9 27 26c.4 2.8-1.8 5.5-4.7 5.5H21.7c-2.9 0-5.1-2.7-4.7-5.5z" />
      </g>
    </svg>
  )
}

function PreloaderDots() {
  return (
    <div className="flex items-center gap-2" aria-hidden>
      {[0, 1, 2].map((i) => (
        <span
          key={i}
          className="size-1.5 rounded-full bg-[#ff6a1a]"
          style={{
            animation: `hris-preloader-dot 1.15s ease-in-out ${i * 0.16}s infinite`,
          }}
        />
      ))}
    </div>
  )
}

/**
 * Full-screen splash shown while the login page initializes (auth check, fonts, assets).
 */
export function LoginPagePreloader({ exiting = false }) {
  const { theme } = useTheme()
  const isDark = theme === 'dark'

  return (
    <div
      className={cn(
        'fixed inset-0 z-200 overflow-hidden bg-[#f8f9fb] text-[#090d18] transition-opacity duration-700 ease-in-out',
        'dark:bg-[#0c0e14] dark:text-foreground',
        exiting && 'pointer-events-none opacity-0',
      )}
      role="status"
      aria-live="polite"
      aria-busy={!exiting}
      aria-label="Loading HRIS"
    >
      {/* Ambient canvas */}
      <div
        className={cn(
          'pointer-events-none absolute inset-0',
          isDark
            ? 'bg-[radial-gradient(ellipse_90%_70%_at_50%_-8%,rgba(255,106,26,0.22),transparent_55%),radial-gradient(ellipse_60%_50%_at_100%_100%,rgba(255,176,48,0.08),transparent_50%)]'
            : 'bg-[radial-gradient(ellipse_90%_70%_at_50%_-8%,rgba(255,176,48,0.28),transparent_55%),radial-gradient(ellipse_60%_50%_at_100%_100%,rgba(255,120,40,0.1),transparent_50%)]',
        )}
        aria-hidden
      />
      <div
        className="pointer-events-none absolute -left-24 top-[18%] size-80 rounded-full bg-[#ff8a2a]/25 blur-[88px] animate-[hris-preloader-drift_11s_ease-in-out_infinite] dark:bg-[#ff6a1a]/20"
        aria-hidden
      />
      <div
        className="pointer-events-none absolute -right-20 bottom-[12%] size-72 rounded-full bg-[#ffb300]/20 blur-[80px] animate-[hris-preloader-drift-alt_13s_ease-in-out_infinite] dark:bg-[#ff9420]/15"
        aria-hidden
      />
      <div
        className={cn(
          'pointer-events-none absolute inset-0 opacity-[0.35] dark:opacity-[0.18]',
          'bg-[linear-gradient(rgba(9,13,24,0.04)_1px,transparent_1px),linear-gradient(90deg,rgba(9,13,24,0.04)_1px,transparent_1px)]',
          'dark:bg-[linear-gradient(rgba(255,255,255,0.05)_1px,transparent_1px),linear-gradient(90deg,rgba(255,255,255,0.05)_1px,transparent_1px)]',
          'bg-size-[48px_48px]',
        )}
        aria-hidden
      />
      <div
        className="pointer-events-none absolute inset-0 bg-[radial-gradient(circle_at_center,transparent_42%,rgba(9,13,24,0.06)_100%)] dark:bg-[radial-gradient(circle_at_center,transparent_38%,rgba(0,0,0,0.45)_100%)]"
        aria-hidden
      />

      <div className="relative flex h-full flex-col px-6">
        <header
          className="flex shrink-0 justify-center pt-8 pb-2 sm:pt-10 animate-[hris-preloader-fade-down_0.85s_cubic-bezier(0.22,1,0.36,1)_both]"
          style={{ animationDelay: '0.05s' }}
        >
          <AgcBrandLogoCrop preset="splash" variant="auto" />
        </header>

        <div
          className="flex flex-1 flex-col items-center justify-center pb-16 text-center animate-[hris-preloader-rise_1s_cubic-bezier(0.22,1,0.36,1)_both]"
          style={{ animationDelay: '0.12s' }}
        >
          {/* Orbital hero */}
          <div className="relative mb-8 flex size-44 items-center justify-center sm:size-48">
            <div
              className="absolute inset-0 rounded-full border border-[#ff8a45]/25 animate-[hris-preloader-orbit_14s_linear_infinite] dark:border-[#ff8a45]/20"
              aria-hidden
            />
            <div
              className="absolute inset-5 rounded-full border border-dashed border-[#ffb300]/30 animate-[hris-preloader-orbit-reverse_18s_linear_infinite] dark:border-[#ff9420]/25"
              aria-hidden
            />
            <div
              className="absolute inset-10 rounded-full border border-[#ff5a14]/15 animate-[hris-preloader-orbit_22s_linear_infinite]"
              style={{ animationDirection: 'reverse' }}
              aria-hidden
            />
            <div
              className="absolute inset-3 rounded-full bg-[radial-gradient(circle,rgba(255,120,40,0.22),transparent_70%)] animate-[hris-preloader-glow_2.8s_ease-in-out_infinite] dark:bg-[radial-gradient(circle,rgba(255,106,26,0.28),transparent_70%)]"
              aria-hidden
            />
            <div
              className="absolute inset-0 rounded-full shadow-[0_0_60px_rgba(255,106,26,0.18)] animate-[hris-preloader-glow_2.8s_ease-in-out_infinite]"
              aria-hidden
            />
            <PreloaderHrMark />
          </div>

          <div className="flex flex-col items-center gap-2.5">
            <h1
              className={cn(
                'bg-linear-to-br from-[#ffb300] via-[#ff7a18] to-[#ea4a12] bg-clip-text text-[clamp(2.85rem,9vw,3.5rem)] font-black uppercase leading-none tracking-[-0.03em] text-transparent',
                'drop-shadow-[0_8px_28px_rgba(255,106,26,0.22)]',
              )}
            >
              HRIS
            </h1>
            <p className="max-w-xs text-[0.9375rem] font-medium leading-snug text-[#4f5665] dark:text-muted-foreground">
              Human Resource Information System
            </p>
          </div>

          <div
            className="mt-10 flex flex-col items-center gap-3 animate-[hris-preloader-fade-up_0.9s_cubic-bezier(0.22,1,0.36,1)_both]"
            style={{ animationDelay: '0.35s' }}
          >
            <PreloaderDots />
            <p className="text-[13px] font-medium text-[#6b7280] dark:text-muted-foreground/90">
              Preparing your workspace
            </p>
          </div>
        </div>

        <p
          className="absolute inset-x-0 bottom-8 text-center text-xs text-[#9ca3af] dark:text-muted-foreground/70 animate-[hris-preloader-fade-up_1s_ease-out_both]"
          style={{ animationDelay: '0.5s' }}
        >
          © 2026 AGC Technologies
        </p>
      </div>
    </div>
  )
}
