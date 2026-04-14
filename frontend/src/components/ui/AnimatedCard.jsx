import { useEffect, useRef } from 'react'
import gsap from 'gsap'
import { ScrollTrigger } from 'gsap/ScrollTrigger'
import { prefersReducedMotion } from '@/lib/gsapConfig'
import { scaleInFrom, scaleInTo } from '@/lib/gsapPresets'
import { cn } from '@/lib/utils'

gsap.registerPlugin(ScrollTrigger)

/**
 * Card wrapper with scroll-triggered fade + scale reveal.
 * Use for dashboards, widgets. Respects prefers-reduced-motion.
 */
export function AnimatedCard({ children, className = '', as: Component = 'div', ...props }) {
  const ref = useRef(null)

  useEffect(() => {
    if (prefersReducedMotion() || !ref.current) return

    const el = ref.current
    gsap.fromTo(el, scaleInFrom, {
      ...scaleInTo,
      scrollTrigger: { trigger: el, start: 'top 85%', once: true },
    })

    return () => {
      ScrollTrigger.getAll().forEach((st) => {
        if (st.trigger === el) st.kill()
      })
    }
  }, [])

  return (
    <Component ref={ref} className={cn('will-change-transform', className)} {...props}>
      {children}
    </Component>
  )
}
