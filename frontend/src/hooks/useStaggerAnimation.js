/**
 * useStaggerAnimation — Stagger children on scroll reveal
 * Use for card grids, table rows, list items.
 */
import { useEffect, useRef } from 'react'
import { gsap } from 'gsap'
import { ScrollTrigger } from 'gsap/ScrollTrigger'
import { prefersReducedMotion } from '@/lib/gsapConfig'
import { fadeUpFrom, fadeUpTo, STAGGER } from '@/lib/gsapPresets'

const DEFAULT_START = 'top 85%'
const DEFAULT_ONCE = true

/**
 * @param {Object} options
 * @param {string} [options.start='top 85%']
 * @param {boolean} [options.once=true]
 * @param {number} [options.stagger=0.1]
 * @param {string} [options.childSelector='.animate-child'] — CSS selector for children
 */
export function useStaggerAnimation(options = {}) {
  const ref = useRef(null)
  const {
    start = DEFAULT_START,
    once = DEFAULT_ONCE,
    stagger = STAGGER,
    childSelector = '.animate-child',
  } = options

  useEffect(() => {
    if (prefersReducedMotion() || !ref.current) return

    const el = ref.current
    const children = el.querySelectorAll(childSelector)

    if (!children.length) return

    gsap.fromTo(
      children,
      fadeUpFrom,
      {
        ...fadeUpTo,
        stagger,
        scrollTrigger: {
          trigger: el,
          start,
          once,
        },
      }
    )

    return () => {
      ScrollTrigger.getAll().forEach((st) => {
        if (st.trigger === el) st.kill()
      })
    }
  }, [start, once, stagger, childSelector])

  return ref
}
