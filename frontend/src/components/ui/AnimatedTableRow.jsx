import { useEffect, useRef } from 'react'
import gsap from 'gsap'
import { prefersReducedMotion } from '@/lib/gsapConfig'
import { fadeUpFrom, fadeUpTo } from '@/lib/gsapPresets'
import { cn } from '@/lib/utils'

/**
 * Table row that fades + slides in on mount.
 * Does NOT block data render — animation runs after paint.
 * Use will-change: transform for performance.
 */
export function AnimatedTableRow({ children, className = '', index = 0, staggerDelay = 0.03, ...props }) {
  const ref = useRef(null)

  useEffect(() => {
    if (prefersReducedMotion() || !ref.current) return

    const el = ref.current
    gsap.fromTo(el, fadeUpFrom, {
      ...fadeUpTo,
      duration: 0.35,
      delay: index * staggerDelay,
      ease: 'power3.out',
    })
  }, [index, staggerDelay])

  return (
    <tr ref={ref} className={cn('will-change-transform', className)} {...props}>
      {children}
    </tr>
  )
}
