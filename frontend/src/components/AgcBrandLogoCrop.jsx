import { AgcBrandLogo } from '@/components/AgcBrandLogo'
import { cn } from '@/lib/utils'

const PRESETS = {
  /** Left-aligned crop used in kiosk brand bar */
  kiosk: {
    frame: 'h-[50px] w-[126px]',
    image: 'left-0 top-[-37px] w-[126px] object-left',
  },
  /** Centered crop for splash / preloader header */
  splash: {
    frame: 'h-[50px] w-[126px]',
    image: 'left-1/2 top-[-37px] w-[126px] -translate-x-1/2 object-center',
  },
}

/**
 * AGC logo PNGs include large transparent padding; crop into a fixed viewport.
 */
export function AgcBrandLogoCrop({
  preset = 'kiosk',
  variant = 'auto',
  className,
  imageClassName,
}) {
  const layout = PRESETS[preset] ?? PRESETS.kiosk

  return (
    <div className={cn('relative shrink-0 overflow-hidden', layout.frame, className)}>
      <AgcBrandLogo
        variant={variant}
        className={cn(
          'absolute h-auto max-h-none max-w-none object-contain',
          layout.image,
          imageClassName,
        )}
      />
    </div>
  )
}
