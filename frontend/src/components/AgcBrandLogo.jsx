import { useTheme } from '@/contexts/useTheme'
import { agcLogoPathForTheme } from '@/lib/agcLogoUrl'
import { cn } from '@/lib/utils'

/**
 * Light UI → `dist/logo/AGC_DARK.png`; dark UI → `dist/dist/logo/AGC_WHITE.png`.
 * `variant`: `auto` follows app theme; `light`/`dark` forces logo for that surface brightness.
 */
export function AgcBrandLogo({
  className,
  alt = 'AGC Technologies & Business Solutions',
  variant = 'auto',
}) {
  const { theme } = useTheme()
  const surface = variant === 'light' || variant === 'dark' ? variant : theme
  const src = agcLogoPathForTheme(surface)
  return (
    <img
      key={src}
      src={src}
      alt={alt}
      className={cn('block max-h-full w-auto shrink-0 object-contain object-left', className)}
      decoding="async"
    />
  )
}
