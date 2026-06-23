import { useId } from 'react'
import { AgcBrandLogoCrop } from '@/components/AgcBrandLogoCrop'
import { cn } from '@/lib/utils'

function SplashHrMark() {
  const gid = useId().replace(/:/g, '')
  const gradId = `hrisSplashGrad-${gid}`

  return (
    <span className="inline-flex shrink-0 items-center justify-center py-1" aria-hidden>
      <svg viewBox="0 0 88 76" xmlns="http://www.w3.org/2000/svg" className="h-[4.65rem] w-22">
        <defs>
          <linearGradient id={gradId} x1={44} y1={8} x2={44} y2={74} gradientUnits="userSpaceOnUse">
            <stop stopColor="#ffc02e" />
            <stop offset="0.52" stopColor="#ff9420" />
            <stop offset="1" stopColor="#ea4a12" />
          </linearGradient>
        </defs>
        <g fill={`url(#${gradId})`}>
          <circle cx={44} cy={22} r={14} />
          <path d="M17 66.5c2.4-17.1 13.1-26 27-26s24.6 8.9 27 26c.4 2.8-1.8 5.5-4.7 5.5H21.7c-2.9 0-5.1-2.7-4.7-5.5z" />
        </g>
      </svg>
    </span>
  )
}

function SplashProgress() {
  return (
    <div
      className="h-0.5 w-24 overflow-hidden rounded-full bg-border"
      role="progressbar"
      aria-label="Loading application"
      aria-busy="true"
    >
      <div className="h-full w-2/5 rounded-full bg-[#ff5a14] animate-[hris-splash-progress_1.6s_ease-in-out_infinite]" />
    </div>
  )
}

/**
 * Minimal splash while the login page initializes.
 * Hierarchy: product identity → loading state → company attribution.
 */
export function LoginPagePreloader({ exiting = false }) {
  return (
    <div
      className={cn(
        'fixed inset-0 z-200 flex min-h-screen flex-col bg-background text-foreground transition-opacity duration-700 ease-in-out',
        exiting && 'pointer-events-none opacity-0',
      )}
      role="status"
      aria-live="polite"
      aria-busy={!exiting}
      aria-label="Loading HRIS"
    >
      <div className="flex flex-1 flex-col items-center justify-center px-6">
        <div className="flex max-w-sm flex-col items-center gap-8 text-center">
          <div className="flex flex-col items-center gap-2">
            <div className="flex flex-wrap items-center justify-center gap-2 md:gap-3">
              <SplashHrMark />
              <h1 className="text-[clamp(2.6rem,8vw,3.875rem)] font-black uppercase leading-none tracking-[-0.02em] text-foreground">
                HRIS
              </h1>
            </div>
            <p className="text-[1.0625rem] font-medium leading-snug text-muted-foreground md:text-[1.125rem]">
              Human Resource Information System
            </p>
          </div>

          <SplashProgress />
        </div>
      </div>

      <footer className="flex flex-col items-center gap-5 px-6 pb-10">
        <AgcBrandLogoCrop preset="splash" variant="auto" className="opacity-90" />
        <p className="text-sm text-muted-foreground">© 2026 HRIS. All rights reserved.</p>
      </footer>
    </div>
  )
}
