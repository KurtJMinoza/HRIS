/**
 * useFadeInOnScroll — ScrollTrigger-based section reveal
 * Triggers when element enters viewport. Respects prefers-reduced-motion.
 */
import { useEffect, useRef } from 'react'
import { gsap } from 'gsap'
import { ScrollTrigger } from 'gsap/ScrollTrigger'
import { prefersReducedMotion } from '@/lib/gsapConfig'
import { fadeUpFrom, fadeUpTo } from '@/lib/gsapPresets'

const DEFAULT_START = 'top 85%'
const DEFAULT_ONCE = true

/**
 * @param {Object} options
 * @param {string} [options.start='top 85%'] — ScrollTrigger start
 * @param {boolean} [options.once=true] — trigger once
 * @param {Object} [options.vars] — extra gsap vars (e.g. delay)
 */
export function useFadeInOnScroll(options = {}) {
  const ref = useRef(null)
  const { start = DEFAULT_START, once = DEFAULT_ONCE, vars = {} } = options

  useEffect(() => {
    if (prefersReducedMotion() || !ref.current) return

    const el = ref.current
    gsap.fromTo(
      el,
      { ...fadeUpFrom, ...vars.from },
      {
        ...fadeUpTo,
        ...vars,
        scrollTrigger: {
          trigger: el,
          start,
          once,
          toggleActions: once ? 'play none none none' : 'play none reverse none',
        },
      }
    )

    return () => {
      ScrollTrigger.getAll().forEach((st) => {
        if (st.trigger === el) st.kill()
      })
    }
  }, [start, once, vars])

  return ref
}
