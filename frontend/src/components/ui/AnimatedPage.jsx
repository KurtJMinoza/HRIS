import { useEffect, useRef } from 'react'
import gsap from 'gsap'
import { prefersReducedMotion } from '@/lib/gsapConfig'
import { fadeUpFrom, fadeUpTo, DURATION } from '@/lib/gsapPresets'
import { cn } from '@/lib/utils'

/**
 * Page entry wrapper: fade-in + slight upward motion (0.65s, power3.out).
 * Use as the direct parent of Outlet. Respects prefers-reduced-motion.
 */
export function AnimatedPage({ children, className = '', ...props }) {
  const ref = useRef(null)

  useEffect(() => {
    if (!ref.current) return

    const el = ref.current
    if (prefersReducedMotion()) {
      el.style.opacity = '1'
      return
    }

    const duration = DURATION.pageEntry || 0.65
    const fallback = setTimeout(() => {
      el.style.opacity = '1'
      el.style.transform = 'none'
    }, (duration + 0.2) * 1000)

    try {
      gsap.fromTo(el, fadeUpFrom, {
        ...fadeUpTo,
        duration,
        ease: 'power3.out',
        onComplete: () => clearTimeout(fallback),
      })
    } catch {
      clearTimeout(fallback)
      el.style.opacity = '1'
      el.style.transform = 'none'
    }
    return () => clearTimeout(fallback)
  }, [])

  return (
    <div ref={ref} className={cn('w-full', className)} {...props}>
      {children}
    </div>
  )
}
