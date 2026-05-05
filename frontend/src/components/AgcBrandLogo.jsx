import { useMemo, useState } from 'react'
import { useTheme } from '@/contexts/useTheme'
import { agcLogoCandidatePathsForTheme } from '@/lib/agcLogoUrl'
import { cn } from '@/lib/utils'

/**
 * Light UI → `dist/logo/AGC_DARK.png`; dark UI → `dist/dist/dist/logo/AGC_WHITE.png`.
 * `variant`: `auto` follows app theme; `light`/`dark` forces logo for that surface brightness.
 */
export function AgcBrandLogo({
  className,
  alt = 'AGC Technologies & Business Solutions',
  variant = 'auto',
}) {
  const { theme } = useTheme()
  const surface = variant === 'light' || variant === 'dark' ? variant : theme
  const candidates = useMemo(() => agcLogoCandidatePathsForTheme(surface), [surface])
  const [logoState, setLogoState] = useState({ surface, index: 0 })
  const candidateIndex = logoState.surface === surface ? logoState.index : 0
  const src = candidates[candidateIndex] || candidates[0]
  return (
    <img
      key={`${surface}-${src}`}
      src={src}
      alt={alt}
      className={cn('block max-h-full w-auto shrink-0 object-contain object-left', className)}
      decoding="async"
      onError={() => {
        setLogoState((prev) => {
          const currentIndex = prev.surface === surface ? prev.index : 0
          const nextIndex = currentIndex + 1 < candidates.length ? currentIndex + 1 : currentIndex
          return { surface, index: nextIndex }
        })
      }}
    />
  )
}
